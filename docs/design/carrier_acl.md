# Carrier ACL Sync (`app/x_carrier_acl`)

Status: design, 2026-11-11. Upstream refs are against `fusionpbx/fusionpbx` master (5.6.x) and `fusionpbx-install.sh` `debian/`, cloned read-only at `/tmp/claude-501/fpbx/core` and `/tmp/claude-501/fpbx/inst`. Paths below are relative to those roots unless absolute.

## 1. Problem and stance

Carriers publish the IP ranges their SIP signaling and RTP media come from. Those ranges change. Upstream FusionPBX only offers manual entry or CSV import into `app/access_controls` (global, superadmin). Nothing fetches, diffs, or keeps the host firewall in step.

How a carrier's traffic gets in today:

- FreeSWITCH only accepts an unauthenticated INVITE if the source IP passes a list named by `apply-inbound-acl`. The stock profiles set it to `providers` (`app/switch/resources/conf/sip_profiles/internal.xml.noload:128`, `external.xml.noload:42`, and the ipv6 variants). `app/sip_profiles/app_defaults.php:36-98` copies those params into `v_sip_profile_settings` on first install, so the live value is a DB row, not the file.
- `acl.conf` is generated from `v_access_controls` / `v_access_control_nodes` by the Lua XML handler (`app/switch/resources/scripts/app/xml_handler/resources/scripts/configuration/acl.conf.lua:78-121`) and cached under key `configuration:acl.conf` (`:40-41`, `:137`). The `providers` and `domains` lists additionally get every enabled domain name appended as `domain=` nodes (`:103-116`).
- After a DB change the UI clears the cache and reloads: `cache->delete("configuration:acl.conf")` then `event_socket::async("reloadacl")` (`app/access_controls/resources/classes/access_controls.php:123-128`, repeated at `:184-189`). `async` is `bgapi` (`resources/classes/event_socket.php:536-538`); the file cache delete unlinks `/var/cache/fusionpbx/configuration.acl.conf` and also sends a `fusion::file` event (`resources/classes/cache.php:180-205`).
- The host firewall is iptables-legacy with iptables-persistent (`inst/debian/resources/iptables.sh:30-34`, `:104-106`), SIP `5060:5091` tcp/udp and RTP `16384:32768` udp open to the world (`:87-89`). `nftables.sh` exists but is commented out of `install.sh:43`. Fail2ban decides which backend it configures by whether `/etc/iptables/rules.v4` exists (`inst/debian/resources/fail2ban.sh:11`, `:31-38`).

Stance (decided):

1. **We own one ACL list, `x_carrier_auto`.** We never write to `providers`, `domains`, `rfc1918`, or any list we did not create. Admins keep hand-managing `providers` as before; ours is additive via a second `apply-inbound-acl` param (section 6).
2. **Two processes, one direction of trust.** An unprivileged worker (runs as `www-data`, reasons in section 7.1) fetches, parses, validates, diffs and writes the DB. A root applier with no network and no parser reads one strictly-formatted snapshot file and programs ipset/nft. Nothing the root script does depends on remote content.
3. **Never empty, never silent.** A failed, empty, or implausible fetch leaves the last-known-good set in place and raises an alert. Big changes wait for a superadmin.
4. **Settings and `app/*` only.** No core file is touched; upstream upgrades (`git pull` + `upgrade.php --schema --defaults --menu --permissions`) leave the app alone (`resources/classes/schema.php:61`, `core/upgrade/upgrade.php:221-226`).

## 2. Feeds and parsers

### 2.1 What Telnyx actually publishes (fetched 2026-11-11)

`https://sip.telnyx.com/` is `text/html`: a marketing-style page with a "Telnyx SIP Regions" table (Region / FQDN / IP Address 1 / IP Address 2), a UAC "Public Anycast IP" table, a "Subnets (containing all media IPs)" code block, and a TURN subnet block. IPv4 only. It links `https://sip.telnyx.com/voice.json` (`application/json`, ~1.7 KB):

```json
{
  "version": "2026-05-25T00:00:00Z",
  "signaling": { "US": { "description": "United States", "fqdn": "sip.telnyx.com", "primary": "192.76.120.10", "secondary": "64.16.250.10" }, "EU": {...}, "AU": {...}, "CA": {...}, "AP": {...}, "ME": {...} },
  "uac":       { "US": { "description": "United States", "ip": "192.76.120.14" }, ... },
  "transports": { "UDP": 5060, "TCP": 5060, "TLS": 5061 },
  "media":     { "globalCidrs": [ "36.255.198.128/25", "50.114.136.128/25", "50.114.144.0/21", "64.16.226.0/24", ... 16 entries ] }
}
```

Bare IPs (no prefix) for signaling and UAC, `a.b.c.d/nn` strings for media. `voice.json` is the authoritative Telnyx feed; the HTML page is a fallback only. Different roles matter: signaling IPs are what INVITEs arrive from (FreeSWITCH ACL + firewall); media CIDRs only need the firewall (sofia ACLs do not see RTP).

### 2.2 Feed record

One row per feed in `v_x_carrier_acl_feeds` (section 4). The parser is chosen by `feed_parser`; `feed_parser_options` is JSON. Every parser returns the same thing: a list of `{cidr, role, label}` where `role` is `signaling` or `media`. The worker never trusts a parser's output; section 3 validates every entry regardless of parser.

### 2.3 Parsers (`resources/classes/parsers/*.php`, one class each, registered by name)

| name | input | options | notes |
|---|---|---|---|
| `cidr_list` | text, one entry per line | `role` (default `signaling`), `comment_chars` (default `#;`) | bare IP becomes `/32` or `/128`. Trailing whitespace, blank lines, inline comments stripped. |
| `csv` | CSV | `column` (header name or 0-based index), `delimiter` (default `,`), `header` (bool), `role`, optional `role_column` + `role_map` | uses `str_getcsv`, max 100 000 rows. |
| `json_path` | JSON | `paths`: list of `{path, role}`; path grammar is dotted keys with `*` for any key and `[*]` for any array element, nothing else (`media.globalCidrs[*]`, `signaling.*.primary`). Optional `version_path` (e.g. `version`) stored on the run for display | tiny recursive walker, no JSONPath library, no new dependency. Max depth 16, max document 2 MiB. |
| `html_ip_scrape` | HTML | `role` (default `signaling`), optional `section_regex` (only scan text after a heading matching this until the next heading), `exclude_regex` | `DOMDocument` with `LIBXML_NONET`, scripts/styles removed, text extracted, tokens matched against an IPv4/IPv6/CIDR regex. Useful for pages like sip.telnyx.com; brittle by nature, so the UI labels it "best effort". |

Seeded presets (`app_defaults.php`, insert-if-missing by `feed_uuid`, disabled until an admin enables them):

- **Telnyx (voice.json)**: `json_path`, paths `signaling.*.primary` → signaling, `signaling.*.secondary` → signaling, `uac.*.ip` → signaling, `media.globalCidrs[*]` → media, `version_path` = `version`.
- **Telnyx (HTML fallback)**: `html_ip_scrape` on `https://sip.telnyx.com/`, `section_regex` = `^(SIP Signaling Addresses|UAC|Media|TURN)`, disabled.

Adding a carrier is a feed row, not code, unless its format needs a new parser class.

### 2.4 Fetcher (`resources/classes/x_carrier_acl_fetch.php`)

PHP curl (`php8.4-curl` is already installed, `inst/debian/resources/php.sh:163`). Hard rules, not settings:

- URL scheme must be `https`; anything else is rejected before any network call. `CURLOPT_PROTOCOLS` and `CURLOPT_REDIR_PROTOCOLS` = `CURLPROTO_HTTPS`, `CURLOPT_MAXREDIRS` = 3.
- `CURLOPT_SSL_VERIFYPEER` = true, `CURLOPT_SSL_VERIFYHOST` = 2, system CA bundle (`ca-certificates`). Optional per-feed `feed_tls_pin_sha256` (SPKI pin via `CURLOPT_PINNEDPUBLICKEY`); off by default because carriers rotate certs.
- Timeouts: connect 10 s, total 30 s. Body cap 2 MiB enforced with `CURLOPT_PROGRESSFUNCTION` abort (a `Content-Length` header is not trusted).
- Conditional requests: stored `ETag` / `Last-Modified` sent as `If-None-Match` / `If-Modified-Since`; a 304 is "unchanged", not a failure.
- Any non-200 (other than 304), curl error, or empty body = fetch failure. Response `Content-Type` is recorded but not enforced (Telnyx serves correct types; others may not).
- SSRF: the hardened unit (section 7.2) uses `IPAddressDeny` for RFC1918, link-local, multicast and loopback except the DB/ESL hosts, so a feed URL pointing at an internal service cannot connect. The web preview path (section 8) runs in php-fpm without that protection, so the fetcher additionally resolves the host first and refuses private/loopback/link-local destinations.

## 3. Validation (`resources/classes/x_carrier_acl_validate.php`)

Applied to every parsed entry; a rejected entry is recorded on the run with its reason and does not count as a fetch failure unless nothing survives.

1. Syntax: `inet_pton()` on the address part, prefix is an integer, 0..32 for v4, 0..128 for v6. Host bits set (e.g. `10.1.2.3/24`) are normalised to the network address and recorded as a warning, not rejected (carriers do this).
2. Prefix width thresholds (settings, defaults shown):

   | | reject | needs approval | normal |
   |---|---|---|---|
   | IPv4 | shorter than `/16` | `/16` to `/19` | `/20` and longer |
   | IPv6 | shorter than `/32` | `/32` to `/47` | `/48` and longer |

   "Needs approval" entries force the whole run into pending approval (section 5.4) the first time they appear; once applied they are remembered on the entry and do not re-trigger.
3. Reserved/special ranges rejected unless the feed has `feed_allow_private = true`: `0.0.0.0/8`, `10/8`, `100.64/10`, `127/8`, `169.254/16`, `172.16/12`, `192.0.0/24`, `192.0.2/24`, `192.168/16`, `198.18/15`, `198.51.100/24`, `203.0.113/24`, `224/4`, `240/4`, `255.255.255.255/32`; `::/128`, `::1/128`, `::ffff:0:0/96`, `64:ff9b::/96`, `100::/64`, `2001:db8::/32`, `fc00::/7`, `fe80::/10`, `ff00::/8`. A bare `0.0.0.0/0` or `::/0` is always rejected.
4. The host's own addresses (from `switch.event_socket.host` and the sip profile `sip-ip`/`ext-sip-ip` values in `v_sip_profile_settings`) are rejected: a carrier list must never allow-list ourselves.
5. Exact duplicates collapse; overlapping ranges are kept as published (no aggregation) so the UI can show provenance.
6. Plausibility: `feed_min_entries` (default 1) must survive; `feed_max_entries` (default 5000) must not be exceeded. Otherwise the run fails and last-known-good stays.

## 4. Data model

All tables come from `app/x_carrier_acl/app_config.php` `['db']` entries and are created by `upgrade.php --schema`. Naming follows upstream (`v_` prefix, `<singular>_uuid` pk) so `database::save()` permission checks resolve to `x_carrier_acl_feed_add` etc. (`resources/classes/database.php:2493`, `:2622`, `:1654`).

### `v_x_carrier_acl_feeds`

| column | notes |
|---|---|
| `x_carrier_acl_feed_uuid` | pk |
| `feed_name` | "Telnyx" |
| `feed_url` | https only |
| `feed_parser` | `cidr_list`, `csv`, `json_path`, `html_ip_scrape` |
| `feed_parser_options` | JSON text |
| `feed_interval_minutes` | default 360 |
| `feed_enabled` | bool |
| `feed_allow_private` | bool, default false |
| `feed_min_entries`, `feed_max_entries` | plausibility bounds |
| `feed_max_remove_pct`, `feed_max_remove_abs`, `feed_max_add_pct`, `feed_max_add_abs` | null = use global setting |
| `feed_tls_pin_sha256` | optional SPKI pin |
| `feed_etag`, `feed_last_modified` | conditional-request state |
| `feed_last_checked`, `feed_last_success`, `feed_last_applied` | timestamps |
| `feed_consecutive_failures` | int |
| `feed_run_requested` | bool; set by UI "Run now" |
| `feed_description`, `insert_*`, `update_*` | upstream audit columns |

### `v_x_carrier_acl_entries` (last-known-good, one row per live CIDR per feed)

| column | notes |
|---|---|
| `x_carrier_acl_entry_uuid` | pk |
| `x_carrier_acl_feed_uuid` | fk |
| `entry_cidr` | normalised text, e.g. `192.76.120.10/32` |
| `entry_family` | `4` or `6` |
| `entry_role` | `signaling` or `media` |
| `entry_label` | from parser (region/key path) |
| `entry_broad_approved` | bool; set when a "needs approval" width was approved |
| `entry_first_seen`, `entry_last_seen` | |
| `access_control_node_uuid` | the upstream node we created for it (null for `media`) |

This table, not `v_access_control_nodes`, is the source of truth for both outputs. The upstream node rows are a projection (section 6) and the firewall snapshot (section 7.3) is another.

### `v_x_carrier_acl_runs` (audit and approval queue)

| column | notes |
|---|---|
| `x_carrier_acl_run_uuid` | pk |
| `x_carrier_acl_feed_uuid` | fk, nullable for "apply-all" runs |
| `run_started`, `run_finished` | |
| `run_trigger` | `timer`, `manual`, `approval`, `boot` |
| `run_status` | `unchanged`, `applied`, `pending_approval`, `approved`, `rejected`, `superseded`, `failed` |
| `run_http_status`, `run_fetched_sha256`, `run_feed_version`, `run_bytes` | fetch facts |
| `run_added`, `run_removed`, `run_rejected_count` | counts |
| `run_proposal` | JSON: full proposed entry set plus the diff and per-entry reject reasons. Only this is applied on approval. |
| `run_error` | text |
| `run_approved_by` (user uuid), `run_approved_at` | |
| `run_firewall_generation`, `run_firewall_result` | generation number handed to the root applier and its reported outcome |

### Settings (`app_config.php` `['default_settings']`, category `x_carrier_acl`)

| subcategory | default | purpose |
|---|---|---|
| `acl_name` | `x_carrier_auto` | the one list we own |
| `max_remove_pct` / `max_remove_abs` | `20` / `25` | approval threshold on removals (either trips) |
| `max_add_pct` / `max_add_abs` | `100` / `50` | approval threshold on additions |
| `v4_reject_shorter_than` / `v4_approve_shorter_than` | `16` / `20` | section 3 |
| `v6_reject_shorter_than` / `v6_approve_shorter_than` | `32` / `48` | |
| `alert_email` | empty = use `email.smtp_from` | recipients, comma separated |
| `alert_on` | `change,failure,approval` | which events send mail |
| `failure_alert_after` | `3` | consecutive failures before a failure alert |
| `stale_after_days` | `30` | UI warning only; never auto-removes |
| `firewall_enabled` | `true` | write the snapshot at all |
| `firewall_mode` | `allow` | `allow` or `restrict` (section 7.4) |
| `firewall_sip_ports` / `firewall_rtp_ports` | `5060:5091` / `16384:32768` | mirrors `iptables.sh:87-89` |
| `fail2ban_ignoreip` | `true` | generate the fail2ban drop-in |

## 5. Worker (`resources/cli/x_carrier_acl_worker.php`)

Bootstraps like upstream CLI jobs: refuse unless `defined('STDIN')`, then `require_once dirname(__DIR__, 4) . '/resources/require.php'` (`app/email_queue/resources/jobs/email_queue.php:4-9`, `.../service/email_queue.php:3`). Uses upstream `database`, `settings`, `cache`, `event_socket`, `email`, `permissions` classes. It is a oneshot, not a daemon: systemd runs it on a timer (section 7.2).

### 5.1 Locking

`flock(LOCK_EX | LOCK_NB)` on `/run/x_carrier_acl/worker.lock`; if the lock is held, exit 0 with a journal line. The timer unit is also `Type=oneshot`, so systemd itself never overlaps two instances of the same unit; the flock covers a hand-run copy. The web UI never executes the worker (section 8).

### 5.2 Per-feed cycle

For each enabled feed where `now >= feed_last_checked + interval`, or `feed_run_requested`, or an `approved` run exists:

1. **Fetch** (section 2.4). 304 → set `feed_last_checked`, status `unchanged`, next feed. Failure → `feed_consecutive_failures++`, run `failed`, alert if the count reached `failure_alert_after`, next feed. Entries are untouched.
2. **Parse, validate** (sections 2.3, 3). Nothing surviving, or outside min/max → `failed`, same handling.
3. **Diff** against `v_x_carrier_acl_entries` for the feed: `added`, `removed`, `unchanged`, by `(cidr, role)`.
4. **Gate**:
   - First successful run for a feed (no entries yet) → always `pending_approval`. Admin sees the whole list before anything goes live.
   - `removed` count > `max_remove_abs` or > `max_remove_pct` of current → `pending_approval`.
   - `added` count over the add thresholds → `pending_approval`.
   - Any new "needs approval" prefix width → `pending_approval`.
   - An `approved` run exists for this feed: apply its `run_proposal` only if the fresh `run_fetched_sha256` equals the approved run's. If the feed changed since approval, mark the old run `superseded`, create a fresh `pending_approval`, alert.
   - Otherwise apply.
   Pending runs supersede older pending runs for the same feed (only one open question per feed). Pending runs send one approval alert.
5. **Apply** (section 6) in one DB transaction, then cache clear + `reloadacl`, then verify, then snapshot (section 7.3).
6. Record the run; on `applied` with a non-empty diff send a change alert listing added/removed CIDRs with labels.

After all feeds: if any feed applied, or the snapshot on disk does not match the DB (e.g. after a restore), regenerate the firewall snapshot once (union of all feeds, both roles).

### 5.3 Last-known-good guarantees

- `v_x_carrier_acl_entries` rows are only deleted inside a successful apply transaction for that feed.
- Disabling a feed keeps its entries and nodes; a separate explicit "Purge entries" action (superadmin, CSRF token, confirm) removes them and triggers reloadacl + snapshot.
- Deleting a feed purges its entries the same way, because orphan nodes in the ACL would be invisible to the UI.
- The worker never truncates the ACL list or the ipset. The root applier refuses an empty snapshot unless the snapshot explicitly says `count 0` and `mode allow` (an operator who purges everything gets an empty allow set, which is harmless; an empty set in `restrict` mode is refused).

### 5.4 Alerts

Upstream `email` class, which inserts into `v_email_queue` when `email_queue.enabled` is true and sends directly otherwise (`resources/classes/email.php:264-301`); the queue is drained by the stock `email_queue.service` (`app/email_queue/resources/service/debian/email_queue.service`). The worker sets `recipients` from `x_carrier_acl.alert_email`, falling back to the global `email.smtp_from`, and `domain_uuid` to the first domain so the email settings resolve. Subjects: `[x_carrier_acl] <feed> approval required (+N/-M)`, `... applied (+N/-M)`, `... FAILED (3 consecutive)`, `... firewall apply FAILED`. Bodies are plain text with the diff; CIDRs are what they are, no HTML. Every alert is also a `syslog(LOG_NOTICE|LOG_ERR)` line with ident `x_carrier_acl` and a run row.

## 6. FreeSWITCH ACL projection

### 6.1 Writing our list

On first apply the worker ensures a `v_access_controls` row `access_control_name = x_carrier_auto`, `access_control_default = deny`, description "Managed by x_carrier_acl. Do not edit." (insert via `database::save` with temporary permissions `access_control_add`, the same trick upstream uses in `app/access_controls/app_defaults.php:77-83`). The uuid is cached in a setting so a rename by an admin is detected (the UI warns; the worker refuses to write to a renamed list and creates a new one).

Per feed apply, inside the transaction: delete every `v_access_control_nodes` row whose `access_control_node_uuid` is referenced by a removed entry, insert one `node_type = allow`, `node_cidr`, `node_description = x_carrier_acl:<feed_name>:<label>` row per added `signaling` entry, store the node uuid on the entry. `media` entries produce no node. Temporary permissions `access_control_node_add` / `access_control_node_delete` (`access_controls.php:112-121`).

Reconciliation at the start of every apply: any node in our list not referenced by an entry (admin added by hand, or crash mid-way) is deleted; any entry whose node vanished is re-created. Our list is ours.

### 6.2 Reload, exactly like upstream

```php
$cache = new cache;
$cache->delete("configuration:acl.conf");   // access_controls.php:124-125; unlinks /var/cache/fusionpbx/configuration.acl.conf (cache.php:180-205)
event_socket::async("reloadacl");           // access_controls.php:128; bgapi (event_socket.php:536)
```

Then verify: `event_socket::command("api acl <one added ip> x_carrier_auto")` must return `true` within 5 s (poll up to 5 times, 1 s apart, because `bgapi` is asynchronous). If verification fails the run is marked `applied` with `run_error = "reloadacl not confirmed"` and an alert is sent; the DB stays as applied because the next `reloadacl` (ours or an admin's) will pick it up. The ESL connection details come from `config.conf` (`event_socket.php:487-489`), readable by `www-data` (`finish.sh:115`).

### 6.3 Attaching the list to SIP profiles

FreeSWITCH evaluates each `apply-inbound-acl` param as a separate list and passes the INVITE if any one list allows the IP. Verified in `src/mod/endpoints/mod_sofia/sofia.c` (v1.10.12, read via GitHub): the profile parser appends every `apply-inbound-acl` value to `profile->acl[profile->acl_count++]` up to `SOFIA_MAX_ACL` (value `none` resets the count), and `sofia_handle_sip_i_invite` loops `for (x = 0; x < profile->acl_count; x++)` calling `switch_check_network_list_ip_port_token(network_ip, acl_port, profile->acl[x], &token)` and `break`s on the first pass, so OR semantics. The `list:pass_context:fail_context` syntax is also parsed there; we use the plain list name. (v1.11 not diffed; the code is old and stable, flagged in section 11.) We therefore do not touch the existing `apply-inbound-acl = providers` rows. The installer (and a UI button "Attach to profiles") inserts, for each profile in `v_sip_profiles` named `internal`, `external`, `internal-ipv6`, `external-ipv6` that exists and is enabled, a `v_sip_profile_settings` row:

```
sip_profile_setting_name    = apply-inbound-acl
sip_profile_setting_value   = x_carrier_auto
sip_profile_setting_enabled = true
sip_profile_setting_description = Managed by x_carrier_acl
```

insert-if-missing keyed on `(sip_profile_uuid, name, value)`. A new `apply-inbound-acl` param needs the profile restarted (`sofia profile <name> restart`), which the installer does once at install time; after that, node changes only need `reloadacl`. The admin UI shows per profile whether the attachment row exists and is enabled, and if the admin disables or deletes it the UI shows a red "not attached" state rather than silently re-adding it. `apply-register-acl` stays untouched (upstream ships it disabled, `internal.xml.noload:134`).

What this does not do: carriers that authenticate by registration do not need an ACL at all; carriers whose INVITEs come from IPs outside the feed still get the normal 401 challenge, as today.

## 7. Firewall sync

### 7.1 Why the worker runs as `www-data`

The worker must unlink `/var/cache/fusionpbx/configuration.acl.conf` (`cache.php:200-205`), and that directory is `www-data:www-data` (`inst/debian/resources/fusionpbx.sh:27-28`); it must read `/etc/fusionpbx/config.conf` for DB and ESL credentials (`finish.sh:115`, mode 664 root-owned, so group `www-data` reads); and `/var/run/fusionpbx` is `www-data` (`finish.sh:105-106`). A dedicated user would need group `www-data` plus write access to the cache dir, which gains nothing. Upstream's own queue service runs as `www-data` (`email_queue.service:18-19`). The systemd unit, not the uid, provides the containment.

What `www-data` cannot do: start a root unit, run `ipset`/`nft`/`iptables`, write to `/etc/fail2ban`, write our units. There is no sudoers entry. The only root-facing surface is one snapshot file.

### 7.2 Units (all installed by our install.sh into `/etc/systemd/system`, owner root, mode 0644)

Do not put any `.service` under `app/x_carrier_acl/resources/service/`: upstream's services manager globs `app/*/resources/service/debian/*.service`, copies them to `/etc/systemd/system` and enables them as root (`core/services/resources/classes/services.php:331-345`, `:625-628`). A unit file living in a `www-data`-writable tree that root installs is a privilege-escalation path, and it would also not install our `.timer`/`.path` units.

`x-carrier-acl-worker.service`

```ini
[Unit]
Description=Carrier ACL feed worker (fetch, validate, write DB)
After=network-online.target postgresql.service freeswitch.service
Wants=network-online.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/fusionpbx
ExecStart=/usr/bin/php /var/www/fusionpbx/app/x_carrier_acl/resources/cli/x_carrier_acl_worker.php
RuntimeDirectory=x_carrier_acl
RuntimeDirectoryMode=0750
RuntimeDirectoryPreserve=yes
StateDirectory=x_carrier_acl/export
UMask=0027
TimeoutStartSec=300
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ReadWritePaths=/var/cache/fusionpbx /var/run/fusionpbx
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
ProtectProc=invisible
PrivateDevices=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=yes
LockPersonality=yes
CapabilityBoundingSet=
SystemCallFilter=@system-service
SystemCallFilter=~@privileged @resources
SystemCallArchitectures=native
IPAddressDeny=10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 169.254.0.0/16 100.64.0.0/10 fc00::/7 fe80::/10 multicast
IPAddressAllow=127.0.0.0/8 ::1
```

`StateDirectory=x_carrier_acl/export` makes systemd create `/var/lib/x_carrier_acl/export` owned by `www-data` and allow writes there despite `ProtectSystem=strict`. If `database.0.host` in `config.conf` is a private address other than loopback, install.sh appends it to `IPAddressAllow` via a drop-in (section 10). `MemoryDenyWriteExecute` is deliberately omitted: PHP opcache JIT may be enabled and would break.

`x-carrier-acl-worker.timer`

```ini
[Unit]
Description=Run the carrier ACL worker periodically
[Timer]
OnBootSec=5min
OnUnitActiveSec=15min
RandomizedDelaySec=2min
Persistent=true
Unit=x-carrier-acl-worker.service
[Install]
WantedBy=timers.target
```

The 15-minute tick is the scheduler; `feed_interval_minutes` decides whether a feed is actually fetched. "Run now" from the UI writes `/var/lib/x_carrier_acl/export/run.request`, watched by:

`x-carrier-acl-worker.path`

```ini
[Unit]
Description=Start the carrier ACL worker on request
[Path]
PathExists=/var/lib/x_carrier_acl/export/run.request
Unit=x-carrier-acl-worker.service
[Install]
WantedBy=multi-user.target
```

The worker deletes the request file first thing.

`x-carrier-acl-apply.service` (root)

```ini
[Unit]
Description=Apply carrier ACL firewall set from validated snapshot
After=netfilter-persistent.service nftables.service
Before=fail2ban.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/x-carrier-acl-apply
StateDirectory=x_carrier_acl/state
UMask=0077
NoNewPrivileges=yes
PrivateTmp=yes
PrivateNetwork=no
ProtectSystem=strict
ReadWritePaths=/etc/fail2ban/jail.d /etc/iptables
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
PrivateDevices=yes
RestrictAddressFamilies=AF_NETLINK AF_UNIX
IPAddressDeny=any
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes
CapabilityBoundingSet=CAP_NET_ADMIN CAP_NET_RAW
SystemCallFilter=@system-service @network-io
SystemCallArchitectures=native

[Install]
WantedBy=multi-user.target
```

`IPAddressDeny=any` plus `RestrictAddressFamilies=AF_NETLINK AF_UNIX` means the applier cannot open an IP socket at all: ipset/nft/iptables talk netlink, `fail2ban-client` talks a Unix socket. `ProtectKernelModules` is not set because iptables-legacy may autoload `xt_set`/`ip_set_hash_net`; install.sh also preloads them via `/etc/modules-load.d/x_carrier_acl.conf` so the applier never needs to. **Unverified:** whether `MemoryDenyWriteExecute` and the `@system-service` filter are compatible with `python3` and `iptables-legacy` on trixie; the installer's smoke test (section 10) runs the applier once and fails the install if it exits non-zero, so a mismatch is caught at install, not at 3 a.m.

`x-carrier-acl-apply.path`

```ini
[Unit]
Description=Apply carrier ACL firewall set when the snapshot generation changes
[Path]
PathChanged=/var/lib/x_carrier_acl/export/firewall.gen
Unit=x-carrier-acl-apply.service
[Install]
WantedBy=multi-user.target
```

This is the privilege bridge: `www-data` can only change a file; systemd (root) notices and runs the applier. No sudo, no setuid, no D-Bus from the web user.

Boot/restart ordering drop-ins (so a firewall reload never leaves us absent): `/etc/systemd/system/netfilter-persistent.service.d/x_carrier_acl.conf` and `/etc/systemd/system/nftables.service.d/x_carrier_acl.conf`, each:

```ini
[Unit]
Wants=x-carrier-acl-apply.service
Before=x-carrier-acl-apply.service
```

Upstream's `nftables.conf` begins with `flush ruleset` (`nftables.sh:28`), so every nftables restart wipes our table; this drop-in re-applies it immediately after.

### 7.3 Snapshot file (worker → applier)

`/var/lib/x_carrier_acl/export/firewall.snapshot`, written atomically (`tmp` + `rename`), owner `www-data`, mode 0640; then `firewall.gen` is rewritten with the generation number, which fires the path unit. Format, line-oriented, nothing else allowed:

```
x_carrier_acl snapshot 1
generation 42
mode allow
ports sip 5060:5091
ports rtp 16384:32768
count 31
v4 192.76.120.10/32 signaling
v4 36.255.198.128/25 media
v6 2001:db8::/48 media
end
```

The applier (`/usr/local/sbin/x-carrier-acl-apply`, python3 standard library only: `ipaddress`, `subprocess`, `re`, `os`; root-owned 0755; ~200 lines) does, in order, and exits non-zero at the first failure without touching the kernel:

1. Refuse unless the file is owned by `www-data`, is a regular file, is smaller than 1 MiB, and has fewer than 10 001 lines.
2. Parse with a strict regex per line: header exactly as above, `generation` integer, `mode` in `{allow, restrict}`, `ports` as `^\d{1,5}:\d{1,5}$` with both ends 1..65535 and low ≤ high, `count` integer equal to the number of `v4`/`v6` lines, every `v4`/`v6` line `^v[46] <cidr> (signaling|media)$`, `end` present as the last line. Unknown line → refuse.
3. Re-validate each CIDR with `ipaddress.ip_network(strict=True)` (so host bits are refused here even though the worker normalised them: belt and braces), re-apply the reject thresholds (`/16`, `/32`) and the reserved-range list from section 3 (hard-coded in the applier; the `feed_allow_private` option therefore cannot reach the firewall, which is intended: private carrier ranges, if any, go in the FreeSWITCH ACL only).
4. `count 0` with `mode restrict` → refuse.
5. Generation must be greater than the last applied generation in `/var/lib/x_carrier_acl/state/applied.gen` (replay protection: an older snapshot cannot be re-applied by rewriting `firewall.gen`), unless invoked with `--boot` (from the `multi-user.target` start), which re-applies the current snapshot.
6. Program the backend (7.4) atomically, write the fail2ban drop-in if it changed, then write `state/applied.gen` and `state/status.json` (`{generation, backend, mode, v4, v6, result, ts}`, mode 0644 so the worker and UI can read it).
7. Log one journal line per run. On failure, `status.json` carries the error; the worker's next tick sees `applied.gen` lagging `firewall.gen`, records `run_firewall_result = failed` on the run and sends an alert.

### 7.4 Backends and set names

Backend detection mirrors `fail2ban.sh:31`: if `/etc/iptables/rules.v4` exists → iptables+ipset; else if `/etc/nftables.conf` exists → nft; else refuse (no firewall to sync; the UI shows "firewall sync inactive"). Detection is cached in `status.json` so the UI can display it.

**iptables + ipset** (upstream default; iptables is forced to legacy, `iptables.sh:30-34`):

| object | name |
|---|---|
| ipset v4 | `x_carrier_v4` (`hash:net family inet`) |
| ipset v6 | `x_carrier_v6` (`hash:net family inet6`) |
| chain | `X_CARRIER_ACL` in `filter` table, both `iptables` and `ip6tables` |
| jump | `INPUT` rule 1: `-j X_CARRIER_ACL` |

Apply: `ipset create x_carrier_v4_new hash:net family inet -exist; ipset flush x_carrier_v4_new; ipset add ...` fed through `ipset restore` on stdin (one process, not one exec per CIDR), then `ipset swap x_carrier_v4_new x_carrier_v4` (atomic) and `ipset destroy x_carrier_v4_new`; same for v6. Chain contents are rebuilt with `iptables-restore --noflush` of just our chain (flush+rules in one transaction):

```
*filter
:X_CARRIER_ACL - [0:0]
-F X_CARRIER_ACL
-A X_CARRIER_ACL -p udp -m multiport --dports <sip>,<rtp> -m set --match-set x_carrier_v4 src -j ACCEPT
-A X_CARRIER_ACL -p tcp -m multiport --dports <sip> -m set --match-set x_carrier_v4 src -j ACCEPT
COMMIT
```

(restrict mode appends `-A X_CARRIER_ACL -p udp --dport <sip> -m set ! --match-set x_carrier_v4 src -j DROP` and the tcp twin; see below). Then ensure the jump is at position 1: `iptables -C INPUT -j X_CARRIER_ACL` and, if present but `iptables -S INPUT 1` is not it, `-D` then `-I INPUT 1`. Position 1 puts carriers ahead of the `friendly-scanner` string drops (`iptables.sh:69-82`) and ahead of fail2ban's `f2b-*` chains, which fail2ban also inserts at position 1 when a jail starts; the ignoreip drop-in below makes the outcome deterministic regardless of who inserted last. Finally write `/etc/iptables/ipsets` with `ipset save` so that `netfilter-persistent` restores the sets before `rules.v4` at boot; otherwise an `iptables-restore` of a saved rules file that references a missing set fails as a whole and can leave the box with default-ACCEPT policies. **Unverified:** Debian trixie's `ipset-persistent` package and its `10-ipset` plugin path; install.sh asserts the plugin exists after `apt-get install ipset-persistent` and otherwise strips our chain from any `rules.v4` save (post-save hook) and relies on `x-carrier-acl-apply.service` at boot alone.

Note that upstream configures no `ip6tables` rules at all (`iptables.sh` is v4-only), so the v6 default policy is ACCEPT; our v6 chain is still installed for consistency and for restrict mode, but the wide-open v6 INPUT is an upstream issue to track separately.

**nftables** (if an operator switched to `nftables.sh`): everything lives in our own table so we never edit upstream's `inet filter`:

```
table inet x_carrier_acl {
  set carrier_v4 { type ipv4_addr; flags interval; elements = { ... } }
  set carrier_v6 { type ipv6_addr; flags interval; elements = { ... } }
  chain input {
    type filter hook input priority -10; policy accept;
    ip  saddr @carrier_v4 udp dport { <sip>, <rtp> } counter accept
    ip  saddr @carrier_v4 tcp dport <sip> counter accept
    ip6 saddr @carrier_v6 udp dport { <sip>, <rtp> } counter accept
    ip6 saddr @carrier_v6 tcp dport <sip> counter accept
  }
}
```

Applied with `nft -f` of a generated file that starts with `add table inet x_carrier_acl` and `flush table inet x_carrier_acl` so the whole replacement is one atomic transaction. Caveat the UI states plainly: in nftables an `accept` in one base chain does not stop a `drop` in another base chain (upstream's `inet filter input` at priority 0, fail2ban's `f2b-table`). In `allow` mode on nft our rule is therefore only a counter and a guarantee for restrict mode; carrier protection from bans comes from the fail2ban drop-in.

**Modes.** `allow` (default): carriers are accepted early on SIP+RTP ports and exempted from fail2ban. Nothing is blocked that was not blocked before. `restrict` (opt-in, red warning in UI): SIP ports also drop from any source not in the carrier set or the local networks (section 12). Phones on networks not listed there can no longer register, so the UI requires typing the word `restrict` to enable it and the applier refuses an empty carrier set in that mode.

**Fail2ban.** When `fail2ban_ignoreip` is on, the applier writes `/etc/fail2ban/jail.d/x_carrier_acl.local`:

```
[DEFAULT]
ignoreip = 127.0.0.1/8 ::1 192.76.120.10/32 36.255.198.128/25 ...
```

and runs `fail2ban-client reload` only if the file content changed and fail2ban is active. Upstream ships `ignoreip` commented out (`inst/debian/resources/fail2ban/jail.local.nft:6`) and only the `ssh` jail enabled (`jail.local:1-10`), but operators routinely enable `freeswitch-ip`/`auth-challenge-ip` with `maxretry = 1` (`jail.local:37-59`); a single malformed carrier packet would otherwise ban a carrier for a day. The drop-in is the deterministic fix for both backends. Entry cap 2 000 (fail2ban parses this into a flat list); above that the applier skips the drop-in and reports `ignoreip skipped: too many entries`.

## 8. Admin UI (`app/x_carrier_acl`, global, superadmin only)

Menu item "Carrier ACL" under Advanced (parent `594d99c5-6128-9c88-ca35-4b33392cec0f`, as `app/access_controls/app_menu.php:29`), `groups[] = superadmin` (`:34`). Permissions in `app_config.php`, all assigned to `superadmin` only, mirroring `app/access_controls/app_config.php:35-57`:

`x_carrier_acl_feed_view/add/edit/delete`, `x_carrier_acl_entry_view`, `x_carrier_acl_run_view`, `x_carrier_acl_approve`, `x_carrier_acl_run_now`, `x_carrier_acl_purge`, `x_carrier_acl_attach` (sip profile rows), `x_carrier_acl_settings`. Every page checks `permission_exists()`; every POST validates the upstream `token` class (`access_controls.php:90-96`). There is no domain scoping: the ACL and firewall are host-global, which is why only superadmin sees it (DESIGN.md: "ACL sync stays global").

Pages:

- `x_carrier_acl_feeds.php` list: name, URL, parser, enabled, last success, entry count (signaling/media), consecutive failures, stale badge, pending-approval badge, firewall status strip at the top (`status.json`: backend, generation applied vs exported, last error).
- `x_carrier_acl_feed_edit.php`: fields from section 4; parser options as a JSON textarea with server-side validation against the chosen parser's option schema; "Preview" button.
- `x_carrier_acl_preview.php` (POST, token): fetches the feed in the web request with the same fetcher/validator and renders the would-be diff (added / removed / rejected with reasons / needs-approval widths) and the raw first 2 KB of the body. Writes nothing. Shows the fetch's TLS subject and `version_path` value.
- `x_carrier_acl_runs.php`: history with filters; a `pending_approval` run shows the full proposal diff and **Approve** / **Reject** (POST, token, `x_carrier_acl_approve`). Approve sets `run_status = approved`, `run_approved_by`, writes `run.request`; the worker applies on its next tick if the content hash still matches (section 5.2).
- `x_carrier_acl_entries.php`: live entries per feed with first/last seen and the upstream node uuid (link to the stock access control node editor, read-only intent).
- Settings live in the stock Default Settings page under category `x_carrier_acl`; our page links there.
- "Run now" writes `run.request` (section 7.2) and sets `feed_run_requested`; "Attach to profiles" inserts the `apply-inbound-acl` rows (section 6.3) and tells the admin which profiles need a restart, with the exact `fs_cli -x 'sofia profile external restart'` command rather than restarting from the web process.

App layout:

```
/var/www/fusionpbx/app/x_carrier_acl/
  app_config.php, app_defaults.php, app_menu.php, app_languages.php
  x_carrier_acl_feeds.php, x_carrier_acl_feed_edit.php, x_carrier_acl_preview.php
  x_carrier_acl_runs.php, x_carrier_acl_run_approve.php (POST only)
  x_carrier_acl_entries.php
  resources/classes/x_carrier_acl_fetch.php
  resources/classes/x_carrier_acl_validate.php
  resources/classes/x_carrier_acl_diff.php
  resources/classes/x_carrier_acl_apply.php        (DB + ACL projection + reloadacl + snapshot writer)
  resources/classes/x_carrier_acl_alert.php
  resources/classes/parsers/{cidr_list,csv,json_path,html_ip_scrape}.php
  resources/cli/x_carrier_acl_worker.php
  resources/cli/x_carrier_acl_attach.php           (sip profile rows; installer calls it)
```

Nothing under `resources/service/`. The root applier and all units ship from this repo's `files/` tree, not from the app directory.

## 9. Audit

- Every run is a `v_x_carrier_acl_runs` row with counts, hashes, proposal, approver.
- Node writes also land in upstream's `v_database_transactions` when that app is installed (`resources/classes/database.php:2364`), so the stock Database Transactions page shows them too.
- Worker and applier both log to the journal/syslog with ident `x_carrier_acl`.
- Alerts (section 5.4) on change, failure, approval needed, firewall apply failure.

## 10. Installer contract

### Users

- `www-data` (exists): worker, web UI. No new system user.
- `root`: applier, units. No sudoers entry anywhere; the `.path` units are the privilege bridge.

### Packages

| package | why | status |
|---|---|---|
| `ipset` | sets for iptables backend | add |
| `ipset-persistent` | restore sets before `rules.v4` at boot | add; **unverified** name/plugin on trixie, install.sh asserts `/usr/share/netfilter-persistent/plugins.d/10-ipset` exists |
| `python3` | applier | present on Debian standard installs; assert `command -v python3` |
| `php8.4-curl`, `php8.4-xml` | fetcher, html parser | already installed (`php.sh:163`) |
| `ca-certificates` | TLS verification | already present; assert |
| `nftables` | only if that backend is active | not installed by us |

### Files, owners, modes

| path | owner | mode | notes |
|---|---|---|---|
| `/var/www/fusionpbx/app/x_carrier_acl/` | `www-data:www-data` | as web root | rsync from this repo |
| `/usr/local/sbin/x-carrier-acl-apply` | `root:root` | 0755 | python3, stdlib only |
| `/etc/systemd/system/x-carrier-acl-worker.{service,timer,path}` | `root:root` | 0644 | |
| `/etc/systemd/system/x-carrier-acl-apply.{service,path}` | `root:root` | 0644 | |
| `/etc/systemd/system/x-carrier-acl-worker.service.d/10-db-host.conf` | `root:root` | 0644 | only when DB host is private and not loopback: `IPAddressAllow=<host>` |
| `/etc/systemd/system/netfilter-persistent.service.d/x_carrier_acl.conf` | `root:root` | 0644 | |
| `/etc/systemd/system/nftables.service.d/x_carrier_acl.conf` | `root:root` | 0644 | harmless if nftables is absent |
| `/etc/modules-load.d/x_carrier_acl.conf` | `root:root` | 0644 | `ip_set`, `ip_set_hash_net`, `xt_set`, `xt_multiport` |
| `/var/lib/x_carrier_acl/` | `root:root` | 0755 | |
| `/var/lib/x_carrier_acl/export/` | `www-data:www-data` | 0750 | snapshot, `firewall.gen`, `run.request` (systemd `StateDirectory` creates it; install.sh pre-creates for the smoke test) |
| `/var/lib/x_carrier_acl/state/` | `root:root` | 0755 | `applied.gen` 0600, `status.json` 0644 |
| `/run/x_carrier_acl/` | `www-data:www-data` | 0750 | lock; `RuntimeDirectory` |
| `/etc/fail2ban/jail.d/x_carrier_acl.local` | `root:root` | 0644 | generated by applier |
| `/etc/iptables/ipsets` | `root:root` | 0640 | generated by applier (iptables backend) |

### Firewall object names (reserved; nothing else may use them)

ipset `x_carrier_v4`, `x_carrier_v6`, `x_carrier_lan_v4`, `x_carrier_lan_v6` (+ transient `_new`); iptables/ip6tables chain `X_CARRIER_ACL` jumped from `INPUT` rule 1; nft table `inet x_carrier_acl` with sets `carrier_v4`, `carrier_v6`, `local_v4`, `local_v6` and chain `input`. ACL list name `x_carrier_auto`. Settings category `x_carrier_acl`. Syslog ident `x_carrier_acl`.

### Commands install.sh must run (after upstream `finish.sh` equivalent, as root)

```sh
# 1. packages and modules
apt-get install -y --no-install-recommends ipset ipset-persistent
command -v python3 >/dev/null || { echo "python3 missing"; exit 1; }
[ -f /etc/iptables/rules.v4 ] && [ ! -f /usr/share/netfilter-persistent/plugins.d/10-ipset ] && echo "WARN: ipset-persistent plugin missing; boot restore relies on x-carrier-acl-apply.service only"
install -o root -g root -m 0644 files/x_carrier_acl/modules-load.conf /etc/modules-load.d/x_carrier_acl.conf
systemctl restart systemd-modules-load.service

# 2. app
rsync -a --delete app/x_carrier_acl/ /var/www/fusionpbx/app/x_carrier_acl/
chown -R www-data:www-data /var/www/fusionpbx/app/x_carrier_acl
test ! -e /var/www/fusionpbx/app/x_carrier_acl/resources/service   # guard against upstream auto-install of units

# 3. root applier, dirs, units
install -o root -g root -m 0755 files/x_carrier_acl/x-carrier-acl-apply /usr/local/sbin/x-carrier-acl-apply
install -d -o root -g root -m 0755 /var/lib/x_carrier_acl /var/lib/x_carrier_acl/state
install -d -o www-data -g www-data -m 0750 /var/lib/x_carrier_acl/export
install -o root -g root -m 0644 files/x_carrier_acl/units/x-carrier-acl-*.{service,timer,path} /etc/systemd/system/
install -d /etc/systemd/system/netfilter-persistent.service.d /etc/systemd/system/nftables.service.d
install -o root -g root -m 0644 files/x_carrier_acl/units/firewall-hook.conf /etc/systemd/system/netfilter-persistent.service.d/x_carrier_acl.conf
install -o root -g root -m 0644 files/x_carrier_acl/units/firewall-hook.conf /etc/systemd/system/nftables.service.d/x_carrier_acl.conf
db_host=$(sed -n 's/^database\.0\.host *= *//p' /etc/fusionpbx/config.conf)
# if $db_host is a private non-loopback address: write 10-db-host.conf with IPAddressAllow=$db_host
systemctl daemon-reload

# 4. register with FusionPBX
cd /var/www/fusionpbx
sudo -u www-data php core/upgrade/upgrade.php --schema
sudo -u www-data php core/upgrade/upgrade.php --defaults      # seeds disabled Telnyx presets, default settings
sudo -u www-data php core/upgrade/upgrade.php --menu
sudo -u www-data php core/upgrade/upgrade.php --permissions

# 5. ACL list and profile attachment (idempotent), then one-time profile restart
sudo -u www-data php app/x_carrier_acl/resources/cli/x_carrier_acl_attach.php --ensure-list --attach internal,external,internal-ipv6,external-ipv6
for p in internal external; do fs_cli -x "sofia profile $p restart" >/dev/null; done   # only profiles that exist

# 6. smoke test the privilege split before enabling timers
sudo -u www-data php app/x_carrier_acl/resources/cli/x_carrier_acl_worker.php --write-empty-snapshot   # count 0, mode allow
/usr/local/sbin/x-carrier-acl-apply --boot || { echo "firewall applier failed under hardening"; exit 1; }
ipset list x_carrier_v4 >/dev/null 2>&1 || nft list table inet x_carrier_acl >/dev/null

# 7. enable
systemctl enable --now x-carrier-acl-apply.service x-carrier-acl-apply.path
systemctl enable --now x-carrier-acl-worker.timer x-carrier-acl-worker.path
```

Step 6 runs `x-carrier-acl-apply` directly (not via the unit) and then via `systemctl start x-carrier-acl-apply.service` to catch sandbox incompatibilities; both must exit 0.

### Commands upgrade.sh must run

```sh
rsync -a --delete app/x_carrier_acl/ /var/www/fusionpbx/app/x_carrier_acl/
chown -R www-data:www-data /var/www/fusionpbx/app/x_carrier_acl
test ! -e /var/www/fusionpbx/app/x_carrier_acl/resources/service
install -o root -g root -m 0755 files/x_carrier_acl/x-carrier-acl-apply /usr/local/sbin/x-carrier-acl-apply
install -o root -g root -m 0644 files/x_carrier_acl/units/x-carrier-acl-*.{service,timer,path} /etc/systemd/system/
systemctl daemon-reload
cd /var/www/fusionpbx
sudo -u www-data php core/upgrade/upgrade.php --schema
sudo -u www-data php core/upgrade/upgrade.php --defaults
sudo -u www-data php core/upgrade/upgrade.php --menu
sudo -u www-data php core/upgrade/upgrade.php --permissions
sudo -u www-data php app/x_carrier_acl/resources/cli/x_carrier_acl_attach.php --ensure-list --check   # non-zero if list renamed or profile rows missing; print, do not auto-fix
systemctl start x-carrier-acl-apply.service   # re-apply current snapshot with the new applier
systemctl restart x-carrier-acl-worker.timer x-carrier-acl-worker.path x-carrier-acl-apply.path
```

After an upstream `git pull`, nothing of ours is overwritten: the app dir is outside upstream's tree, the units and applier are outside the web root, and our ACL list and sip profile rows are ordinary DB rows upstream never rewrites (`app_defaults.php` for access controls only seeds when the table is empty, `app/access_controls/app_defaults.php:31-33`; sip profile defaults likewise, `app/sip_profiles/app_defaults.php:27-36`).

## 11. Unverified / open

- **FreeSWITCH multi-ACL semantics** verified on v1.10.12 source (section 6.3), not re-checked on the 1.11 tag; `SOFIA_MAX_ACL` lives in a header not inspected (believed 100). Also unverified: whether FusionPBX's `sofia.conf.lua` emits two `apply-inbound-acl` params when two enabled rows share the name (it iterates `v_sip_profile_settings` rows, so it should; confirm on a dev box with `sofia status profile external` showing both lists in `ACL`).
- `ipset-persistent` package and plugin path on trixie (section 7.4, 10).
- systemd hardening (`MemoryDenyWriteExecute`, `SystemCallFilter`) versus `python3` and `iptables-legacy`; caught by the install smoke test.
- The upstream `config.conf` is mode 664 (`finish.sh:115`): the DB password is readable by group `www-data` and world-readable. Out of scope, same note as `carrier_credentials.md` section 11.
- IPv6 host firewall is wide open upstream (`iptables.sh` has no `ip6tables` rules).
- `fail2ban-client reload` cost on large `ignoreip` lists; the 2 000 cap is a guess.
- Telnyx `voice.json` lists the TURN subnets under `media.globalCidrs` while the HTML page lists them separately; we take the JSON as authoritative.
- Local networks (section 12): a restrict-mode host whose phones sit behind NAT on the far side of a VPN or office router needs the router's public address listed, not the LAN behind it; the UI text says "networks your phones register from" but cannot detect this.

## 12. Local networks (LAN allowlist)

Added 2026-11-11. Feeds always reject private and reserved ranges (section 3), so in `restrict` mode SIP from LAN phones was dropped. Local networks are a separate, admin-entered allowlist that the firewall always accepts.

- **Storage.** Table `v_x_carrier_acl_local_networks` (`x_carrier_acl_local_network_uuid`, `local_network_cidr` normalised, `local_network_family`, `local_network_enabled`, `local_network_description`, upstream audit columns), from `app_config.php`. Global, superadmin only: permissions `x_carrier_acl_local_network_view/add/edit/delete` (the names `database::save()`/`delete()` derive from the table). At most 500 rows.
- **Validation** (`x_carrier_acl_validate::local_network`, mirrored in the applier's `LOCAL_RESERVED`): private ranges allowed (RFC1918, CGNAT `100.64/10`, ULA `fc00::/7`), as are public office/VPN ranges. Always refused: `/0`, IPv4 shorter than `/8` inside the private ranges (`10/8`, `172.16/12`, `192.168/16`, `100.64/10`) and shorter than `/16` elsewhere, IPv6 shorter than `/32`, host bits set (the admin is shown the network address instead of it being silently cleared), loopback, unspecified/`0/8`, link-local (`169.254/16`, `fe80::/10`: on-link only, never needed for phones that reach the PBX by its address), multicast, `240/4`, broadcast, documentation, benchmarking, `192.0.0/24`, `::ffff:0:0/96`, `64:ff9b::/96`, `100::/64`. The host's own addresses are not refused: a LAN normally contains the PBX.
- **No approval, still audited.** UI pages `x_carrier_acl_local_networks.php` (list, toggle, delete; POST + token) and `x_carrier_acl_local_network_edit.php` (add/edit), linked from the feeds page. Each change writes a `v_x_carrier_acl_runs` row with no feed, `run_trigger = local_network`, `run_status = applied`, the added/removed CIDRs in `run_proposal` and the user in `run_approved_by`, then writes `run.request`. The worker links these runs to the generation it exports (or records why none was exported) so the Runs page shows them with the firewall result.
- **FreeSWITCH ACL untouched.** Local networks never become nodes of `x_carrier_auto`: that list bypasses digest authentication, and phones must keep authenticating.
- **Snapshot version 2.** The worker writes `x_carrier_acl snapshot 2`: version 1 plus a section after the carrier lines, local networks written once per role (allowed for signaling and RTP, like carriers):

  ```
  count 31
  v4 192.76.120.10/32 signaling
  ...
  local 2
  local v4 192.168.10.0/24 signaling
  local v4 192.168.10.0/24 media
  end
  ```

  The applier accepts versions 1 and 2 (v1 has no `local` line; v2 requires it), re-validates local lines under the local rules above and carrier lines under the unchanged carrier rules, and still refuses an empty carrier set in restrict mode. A failed read of the table is an error (no snapshot), never an export without the LAN; a missing table (upgrade, before `--schema`) exports an empty local section.
- **Firewall.** iptables: ipsets `x_carrier_lan_v4`/`x_carrier_lan_v6`, always created (empty for a v1 snapshot), with the same udp SIP+RTP and tcp SIP `ACCEPT` rules as carriers, placed before the restrict-mode drops; persisted to `/etc/iptables/ipsets` with ours. nft: sets `local_v4`/`local_v6` in our table with the same accept rules ahead of the drops. Both modes. `status.json` gains `local_v4`, `local_v6`, `snapshot_version`.
- **Fail2ban.** Local networks are added to the `ignoreip` drop-in alongside carriers (same 2 000 total cap).
- **Upgrade.** The hook installs the new applier before its smoke test, which re-applies whatever snapshot is on disk; a version 1 snapshot is accepted. The new worker never treats a v1 body as current, so its next run rewrites the snapshot as v2. If the worker timer fires between the app copy and the hook (new worker, old applier), the old applier refuses the v2 snapshot and keeps the previous rules; the hook's `--boot` run then applies it.
