# Carrier Credentials (`app/x_carriers`)

Status: design, 2026-11-11. Upstream refs are against `fusionpbx/fusionpbx` master (5.6.x) and `fusionpbx-install.sh` `debian/`, cloned read-only at `/tmp/claude-501/fpbx/core` and `/tmp/claude-501/fpbx/inst`. Paths below are relative to those roots.

## 1. Problem and stance

Upstream gateways are domain-scoped rows in `v_gateways` with a plaintext `password` column (`app/gateways/app_config.php:103-109`, `:127-129`). The password reaches FreeSWITCH two ways, both plaintext:

- The Lua XML handler selects it straight from `v_gateways` and emits it into the `sofia.conf` XML it hands to FreeSWITCH (`app/switch/resources/scripts/app/xml_handler/resources/scripts/configuration/sofia.conf.lua:136`, `:158`). This is the live path; the on-disk include is commented out (`:119`).
- `save_gateway_xml()` also writes `<sip_profiles>/<profile>/v_<gateway_uuid>.xml` with the password (`resources/switch.php:133`, `:169`, `:182-184`). Those files are owned by `www-data` because the installer chowns `/etc/freeswitch` to `www-data` (`inst/debian/resources/switch/package-permissions.sh:8`, `source-permissions.sh:19`).

There is a third plaintext copy most people miss: `database::save()` logs old/new row JSON into `v_database_transactions` whenever `app/database_transactions` is installed (`resources/classes/database.php:2364`, `:3345`). It is installed by default, so every gateway save archives the password there too.

Stance (decided): **manage, don't claim.** Our app owns the authoritative copy of every carrier credential, encrypted with libsodium, with rotation and audit. It pushes what FreeSWITCH needs into `v_gateways` and outbound routes, which stay plaintext and are protected only by DB/file permissions. UI and docs say exactly that. We never say "encrypted at rest" without the qualifier "in the carrier store; the FreeSWITCH copy is not."

The optional `fusionpbx-app-sip_trunks` was inspected and is not relevant: it only adds a destination type that bridges inbound calls to registered extensions (`README.md`; `app_config.php:27-36`). It has no tables and no outbound or credential role. Do not install it for this feature.

## 2. Data model

All tables are created from `app/x_carriers/app_config.php` `['db']` entries (upstream discovers any `app/*/app_config.php` via `resources/classes/schema.php:61` and applies with `upgrade.php --schema`, `core/upgrade/upgrade.php:221`). No core file changes.

### `v_x_carriers` (global catalogue, superadmin)

| column | notes |
|---|---|
| `x_carrier_uuid` | pk |
| `x_carrier_name` | e.g. "Telnyx" |
| `x_carrier_default_proxy`, `x_carrier_default_register_proxy`, `x_carrier_default_outbound_proxy`, `x_carrier_default_transport`, `x_carrier_default_codec_prefs`, `x_carrier_default_profile` (default `external`) | template values copied into gateway rows |
| `x_carrier_supports_registration`, `x_carrier_supports_ip_auth`, `x_carrier_supports_api` | booleans gating which credential types the UI offers |
| `x_carrier_api_base_url` | used only by future account-management features |
| `x_carrier_enabled`, `x_carrier_description` | |

### `v_x_carrier_accounts` (per domain, per carrier)

| column | notes |
|---|---|
| `x_carrier_account_uuid` | pk |
| `domain_uuid` | fk `v_domains`, required (no global rows) |
| `x_carrier_uuid` | fk |
| `x_carrier_account_name` | label shown in route pickers |
| `x_carrier_account_purpose` | `voice`, `fax`, or `both` |
| `x_carrier_account_enabled` | |
| `x_carrier_account_description` | |

One domain may have several accounts for the same carrier (separate trunks, separate billing). A domain's outbound voice may use several accounts across several carriers.

### `v_x_carrier_credentials` (one row per credential type per account)

| column | notes |
|---|---|
| `x_carrier_credential_uuid` | pk |
| `x_carrier_account_uuid` | fk |
| `domain_uuid` | denormalised for permission filtering |
| `x_carrier_credential_type` | `sip_digest`, `ip_auth`, `api_token` |
| `x_carrier_credential_label` | e.g. "primary", "rotating-in" |
| `x_carrier_credential_public` | non-secret part: SIP username / auth-username / realm for `sip_digest`; carrier-facing source identity for `ip_auth`; API key id for `api_token`. Stored plaintext (it is not secret and must be searchable) |
| `x_carrier_credential_secret` | sodium secretbox ciphertext, base64. SIP password; tech prefix/token; API secret |
| `x_carrier_credential_key_id` | which master key encrypted it (for rotation) |
| `x_carrier_credential_version` | integer, bumped on every secret change |
| `x_carrier_credential_status` | `active`, `pending`, `retired` |
| `x_carrier_credential_not_before`, `x_carrier_credential_not_after` | optional validity window |
| `x_carrier_credential_last_pushed` | timestamp of last successful push to `v_gateways` / dialplan |
| `insert_date`, `insert_user`, `update_date`, `update_user` | upstream convention |

The tech prefix for `ip_auth` is treated as a secret even though it ends up in a dialplan `bridge` string (section 4). It is a bearer token: anyone who learns it and can reach the carrier from an allow-listed IP can place billable calls.

### `v_x_carrier_bindings` (what each credential drives)

| column | notes |
|---|---|
| `x_carrier_binding_uuid` | pk |
| `x_carrier_credential_uuid` | fk |
| `domain_uuid` | |
| `x_carrier_binding_type` | `gateway` or `outbound_route` |
| `gateway_uuid` | fk `v_gateways`, when type is `gateway` |
| `dialplan_uuid` | fk `v_dialplans`, when type is `outbound_route` |
| `x_carrier_binding_managed` | `true` means we created the target and will delete it with the binding; `false` means we adopted an existing upstream row and only update the credential fields |

A `sip_digest` credential normally has one `gateway` binding (two if the same password is used on two profiles or hostnames). An `ip_auth` credential has one `gateway` binding (a non-registering gateway carrying proxy and from-domain) plus one or more `outbound_route` bindings (the dialplan rows that prepend the prefix). An `api_token` credential has no bindings.

### `v_x_carrier_audit`

| column | notes |
|---|---|
| `x_carrier_audit_uuid`, `domain_uuid`, `x_carrier_account_uuid`, `x_carrier_credential_uuid` | |
| `x_carrier_audit_action` | `create`, `update_secret`, `rotate`, `reveal`, `push`, `retire`, `delete`, `key_rotate` |
| `x_carrier_audit_user_uuid`, `x_carrier_audit_username` | from `$_SESSION` |
| `x_carrier_audit_remote_address` | |
| `x_carrier_audit_detail` | JSON, never contains secret material; contains credential version numbers, gateway UUIDs, outcome, and the secret's fingerprint (first 8 hex of `sodium_crypto_generichash(secret)`) so two events can be compared without storing the value |
| `x_carrier_audit_date` | |

Rows in `v_x_carrier_credentials` and `v_x_carrier_audit` must be written with `$database->save($array, false)` (the second argument disables the transaction log, `resources/classes/database.php:2364`). Ciphertext in the transaction log would be harmless, but `reveal` events and plaintext public fields do not belong there either, and the pushes we make into `v_gateways` are logged by upstream anyway (section 5.4).

## 3. Credential types and how each reaches FreeSWITCH

| type | our store holds | pushed to | FreeSWITCH use |
|---|---|---|---|
| `sip_digest` | username, auth-username, realm (public); password (secret) | one or more `v_gateways` rows: `username`, `auth_username`, `realm`, `password`, `register=true/false` | registration (fax, inbound) or outbound digest auth with `register=false` |
| `ip_auth` | from-user / from-domain (public); tech prefix (secret) | one `v_gateways` row with `register=false`, empty `password`; plus outbound route(s) whose bridge string is `sofia/gateway/<uuid>/<prefix>$1` | carrier authenticates by source IP; prefix selects the account/trunk |
| `api_token` | key id (public); secret | nothing | account management, number ordering, endpoint-list fetch; consumed by our other apps through `x_carriers::api_secret($account_uuid)` |

Fax: fax sends resolve a route through `outbound_route_to_bridge($domain_uuid, $fax_prefix . $fax_number, ...)` (`app/fax/fax_send.php:808`; `resources/switch.php:347`), i.e. through the same domain outbound routes. A fax account is therefore a `sip_digest` credential with `register=true` on its gateway (inbound fax via registration) plus an `outbound_route` binding so outbound fax selects that gateway. The per-fax-server `fax_prefix` field (`app/fax/app_config.php:558`) is the stock lever for steering a fax server to a dedicated route: the "enable fax for this domain" action sets `fax_prefix` to a reserved steering prefix (e.g. `*33`) and our fax route matches `^\*33(\d+)$`. That keeps the fax route in the stock editor and avoids depending on `${sip_h_...}` tricks.

## 4. Tech prefix: outbound route, not gateway field

`v_gateways` has no prefix or number-translation field; the full field list is `app/gateways/app_config.php:96-250`. The prefix must therefore live in the outbound route. Upstream does exactly this: the outbound route wizard concatenates a user-supplied `prefix_number` into the bridge string, `sofia/gateway/<gateway_uuid>/<prefix>$1` (`app/dialplan_outbound/dialplan_outbound_add.php:353-364`, `:815`), stores the rows in `v_dialplans` and `v_dialplan_details`, and the dialplan class renders `dialplan_xml`, which the Lua handler serves verbatim (`.../xml_handler/resources/scripts/dialplan/dialplan.lua:224-227`).

Our writer emulates `dialplan_outbound_add.php` without calling it:

- Same `app_uuid` `8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3` (`:57`) so the route lists under Outbound Routes, is editable in the stock editor, and is found by `outbound_route_to_bridge()` (`resources/switch.php:390`).
- Same detail structure: `${user_exists}` `false` condition, `destination_number` regex, `call_direction=outbound` export, caller-id sets, `bridge` action (`:536-562`, `:598-600`, `:633-655`, `:815`). The one-line difference is the bridge data, which we compose from the decrypted prefix at push time.
- After save, clear `dialplan:<context>` exactly as upstream does (`:887-888`).

Consequences, stated to the user in the UI: the prefix is plaintext inside `v_dialplans.dialplan_xml` and `v_dialplan_details.dialplan_detail_data`, visible to anyone with `dialplan_edit` on that domain. If an admin edits the route in the stock editor, our binding detects drift on next view (bridge string no longer matches the derived value) and offers "re-push". We never silently overwrite a route a human changed; we mark the binding `drifted` and log it.

Multiple trunks: each `ip_auth` or `sip_digest` credential can be bound to several routes with distinct regexes and `dialplan_order` values. Fail-over uses upstream's own pattern of a second and third bridge action in one route (`:370-380`); our writer supports one primary binding plus ordered secondaries that reference other credentials in the same domain.

## 5. Propagation to `v_gateways`

### 5.1 Writer

`app/x_carriers/resources/classes/x_carrier_push.php::push($credential_uuid)`:

1. Load credential, decrypt secret (section 6).
2. For each `gateway` binding, build the `v_gateways` row: carrier defaults from `v_x_carriers`, identity from the credential, `domain_uuid` from the account, `enabled` from account and credential status. Set `gateway` (display name) to `<carrier>-<account>-<label>` so it is recognisable in the stock Gateways page.
3. `$database->save($array)` into `gateways` with `app_name`/`app_uuid` set to upstream's gateway app, so stock pages see it as a normal gateway.
4. Replicate the post-save sequence from `app/gateways/gateway_edit.php` verbatim:
   - unlink the XML file when disabling (`:234-239`);
   - `save_gateway_xml()` (`:242`; defined in `resources/switch.php:133`), which rewrites every gateway XML for the current domain, not only ours;
   - `$cache->delete(gethostname().":configuration:sofia.conf")` (`:245-246`);
   - `event_socket::api('sofia profile <profile> rescan')` (`:250-251`; upstream hard-codes `external`, we use the row's `profile`);
   - `$_SESSION["reload_xml"] = false` (`:254`).
5. For a credential change on a registering gateway, rescan is not enough to re-register with a new password; also issue `sofia profile <profile> killgw <gateway_uuid>` then rescan. Unverified: whether 1.11 `rescan` picks up a changed param without `killgw`; test on the reference build and keep `killgw` if it does not.
6. Update `x_carrier_credential_last_pushed`, write an audit row.

`save_gateway_xml()` depends on `$_SESSION['domains']` and `$_SESSION['domain_name']` for its glob (`resources/switch.php:143-149`), so pushes run inside a web request for the account's domain or from a CLI helper that sets those globals explicitly.

### 5.2 Adoption of existing gateways

An existing domain gateway can be adopted into a credential (`x_carrier_binding_managed=false`). We read its current `password` into the store on adoption (audit `create` with fingerprint), then only ever update the credential columns (`username`, `auth_username`, `realm`, `password`, `register`) on push; proxies and codecs stay the admin's.

### 5.3 Deletion

Deleting a managed binding deletes the gateway row and its XML file, then runs the same clear/rescan. Deleting a credential with bindings is refused until bindings are removed or re-pointed.

### 5.4 Transaction log leakage

Pushing through `$database->save()` with logging enabled writes the plaintext password into `v_database_transactions`, as the stock editor does. We call `save($array, false)` for our pushes so our app does not add to that log. The stock editor still does, and so does any prior history. The docs state: "The upstream transaction log may contain historical plaintext gateway passwords; superadmins can purge it from Advanced > Database Transactions."

## 6. Key management

### 6.1 Honest threat model

The web user must decrypt to push to `v_gateways` and to display a credential on demand. Whoever can run PHP as `www-data` can therefore read every secret. Today that user also owns the entire web root, `/etc/freeswitch`, and can read `/etc/fusionpbx/config.conf` (installed root-owned, mode 664, `inst/debian/resources/finish.sh:35`, `:115`), which contains the database password, so `www-data` already has the plaintext `v_gateways` column. The sodium layer therefore does not protect against web-user compromise. It protects against:

- offline copies of the database (pg_dump files, replicas, backups shipped off-box) for the credentials that are not also mirrored into `v_gateways`, which is the API tokens and any credential in `pending`/`retired` state;
- casual exposure through SQL consoles, DB export tools, and transaction logs;
- giving us a single place to rotate, audit, and expire.

The UI "About encryption" panel says the above in three sentences.

### 6.2 Separate privileged helper: considered, deferred

A root-owned helper (setuid or a Unix-socket daemon) that holds the key and performs decrypt-and-push would keep the key out of `www-data`'s reach. It does not change the outcome: the same helper must write plaintext into `v_gateways`, which `www-data` reads with the DB credentials it already holds. The helper would only materially protect `api_token` secrets. Recommendation: do not build it in v1; keep the key-file interface so a helper can be substituted later (the class reads the key through one function, `x_carrier_keyring::key($key_id)`). Revisit when the DB password is moved out of a world-readable `config.conf` and `www-data` loses ownership of `/etc/freeswitch`, which are separate hardening items this feature should not block on.

### 6.3 Key file

- Path: `/etc/fusionpbx/x_carriers/keys/<key_id>.key`, where `key_id` is `k` plus a UTC timestamp, e.g. `k20261111T1530Z`. Directory `/etc/fusionpbx/x_carriers/` is `root:www-data 0750`; key files `root:www-data 0640`.
- Content: 32 raw bytes from `sodium_crypto_secretbox_keygen()` (or `head -c 32 /dev/urandom`), base64 on one line. No passphrase; there is nobody to type one on a PBX.
- Active key: `/etc/fusionpbx/x_carriers/keys/active` is a symlink to the active key file. Old keys stay until no row references them (`x_carrier_credential_key_id`), then `x_carriers_keyring prune` removes them.
- Location is read from `config.conf` key `x_carriers.key_dir` if present (upstream's config class reads arbitrary keys from that file), defaulting to the path above. Nothing is stored under `/var/www`, which is `www-data`-writable and backed up by some site policies.
- Cipher: `sodium_crypto_secretbox` (XSalsa20-Poly1305) with a fresh 24-byte nonce per encryption, stored as `base64(nonce || ciphertext)`, prefixed with the `key_id` and a version tag: `v1:<key_id>:<base64>`. Additional data is not available in secretbox, so the row UUID is prepended to the plaintext before sealing and checked on open, which binds a ciphertext to its row and stops copy-paste of a ciphertext between rows.
- Do not reuse upstream's `encrypt()`/`decrypt()` (`resources/functions.php:2035`, `:2054`): AES-256-CBC with no authentication tag, and it stores the IV in a hand-rolled `::` format.

### 6.4 Rotation

Two independent rotations:

- Master key rotation (`x_carriers_keyring rotate`): generate a new key file, repoint `active`, re-encrypt every row under the new key in one transaction, update `key_id`, audit `key_rotate` per domain with counts only. Old key remains readable until prune. Run from the CLI as root; the PHP re-encryption step runs as `www-data` with both keys readable.
- Credential rotation (per credential, UI or CLI): create a new version in `pending` with the new secret, push it (for `sip_digest` this means a second gateway row so registration can be tested side by side; for `ip_auth` it means a parallel route at lower priority), then "promote": swap bindings, retire the old version, push again, delete the temporary rows. Every step is a separate audit row. Retired versions are kept encrypted for a configurable number of days (`x_carriers.retired_retention_days`, default 30), then the secret column is zeroed.

### 6.5 Backups

`inst/debian/resources/backup/fusionpbx-backup` only runs `pg_dump` into `/var/backups/fusionpbx/postgresql/`; the `tar` lines that would capture `/etc/fusionpbx` are commented out. The installer copies the script to `/etc/cron.daily` and seds the DB password into it (`finish.sh:26-30`). So a stock backup contains ciphertext plus plaintext `v_gateways` and no key.

Recommendation, documented and implemented in the installer contract:

- Do not add the key to the stock dump. Ship a separate `/etc/cron.daily/x_carriers-keybackup` that copies `/etc/fusionpbx/x_carriers/keys/` to `/var/backups/x_carriers-keys/` as `root:root 0700`, so a full-host backup gets both while a DB-only backup stays decryptable only by someone who also has host root. The restore guide says: restore the DB, restore the key directory, verify with `x_carriers_keyring check`.
- Say plainly in docs: a DB dump restored without the key loses API tokens and pending/retired secrets; active SIP credentials survive because they are also in `v_gateways`, and `x_carriers_keyring reseal-from-gateways` can rebuild the store for `sip_digest` credentials from `v_gateways` (with an audit note that they were re-imported).

## 7. Masking, logging, UI

- Secrets are never rendered in page HTML. The edit form shows a placeholder (`••••••••`, version number, last-changed date, fingerprint) and a "Reveal" button that POSTs with the CSRF token, requires the `x_carrier_credential_reveal` permission, writes a `reveal` audit row, and returns the value once over XHR with `Cache-Control: no-store`. Upstream's mouse-over reveal on the gateway page (`app/gateways/gateway_edit.php:440`) is left alone but our docs point out it shows the same plaintext.
- The stock gateway list query selects `password` (`app/gateways/gateways.php:200`); nothing to change, noted for completeness.
- Logging: our classes never pass secrets to `syslog`, `error_log`, exceptions, or `message::add`. A wrapper `x_carrier_secret` object with `__toString()` returning `[redacted]` and `__debugInfo()` returning nothing is the only thing that carries plaintext between decrypt and push, so an accidental `var_dump` or exception trace shows `[redacted]`.
- ESL: `sofia profile ... rescan`/`killgw` commands carry only UUIDs.
- Every list and edit page carries a one-line banner: "Secrets are encrypted in this app. The copy FreeSWITCH uses (Gateways, Outbound Routes, gateway XML) is not encrypted."

## 8. Permissions

Defined in `app/x_carriers/app_config.php` and applied by `upgrade.php --permissions` (`core/upgrade/upgrade.php:224`). Upstream's gateway permissions are superadmin-only (`app/gateways/app_config.php:53-76`); we follow that for anything that writes to `v_gateways`.

| permission | superadmin | admin (domain) | purpose |
|---|---|---|---|
| `x_carrier_view`, `x_carrier_add`, `x_carrier_edit`, `x_carrier_delete` | yes | no | global carrier catalogue |
| `x_carrier_account_view` | yes | yes | see this domain's accounts and credential metadata |
| `x_carrier_account_add/edit/delete` | yes | no (default) | creating an account creates gateways and routes, which are superadmin actions upstream. Can be granted to a domain admin group per deployment |
| `x_carrier_credential_edit` | yes | no (default) | set or rotate a secret |
| `x_carrier_credential_reveal` | yes | no | show plaintext once |
| `x_carrier_credential_api_use` | yes | yes | let other apps call `api_secret()` on behalf of this domain's user |
| `x_carrier_audit_view` | yes | yes (own domain) | |
| `x_carrier_all` | yes | no | cross-domain listing, mirrors `gateway_all` (`app/gateways/gateway_edit.php:18`) |

All queries filter `domain_uuid = $_SESSION['domain_uuid']` unless `x_carrier_all` is present, mirroring `gateway_edit.php:26`. POST handlers check `permission_exists()` and the upstream CSRF token class before touching the DB.

## 9. App layout

```
/var/www/fusionpbx/app/x_carriers/
  app_config.php          tables, permissions, default settings, menu
  app_defaults.php        seed carrier catalogue rows (Telnyx etc.), idempotent
  app_menu.php
  app_languages.php
  x_carriers.php, x_carrier_edit.php           catalogue (superadmin)
  x_carrier_accounts.php, x_carrier_account_edit.php
  x_carrier_credential_edit.php, x_carrier_credential_reveal.php (POST only)
  x_carrier_audit.php
  resources/classes/x_carrier_keyring.php      key file access, seal/open
  resources/classes/x_carrier_secret.php       redacting value object
  resources/classes/x_carrier_push.php         v_gateways + outbound route writer
  resources/classes/x_carrier_routes.php       outbound route composer modelled on dialplan_outbound_add.php
  resources/cli/x_carriers_keyring.php         generate|rotate|check|prune|reseal-from-gateways
```

No file outside `app/x_carriers/` is modified. Upstream upgrades (`git pull` of the core, then `upgrade.php --schema --defaults --menu --permissions`) leave the app in place because the installer treats `app/*` as the extension point (`core/upgrade/upgrade.php:453`, `resources/classes/schema.php:61`).

## 10. Installer contract

### Packages

- `php8.4-cli`, `php8.4-fpm`, `php8.4-pgsql` are already installed by `inst/debian/resources/php.sh:179-180` from the sury repo (`:122-129`).
- Sodium: the `sodium` extension is believed to be compiled into `php8.4-common` on Debian/sury packaging (it has shipped as a core extension since PHP 7.2, and Debian's `php8.x-common` provides `php-sodium`). **Unverified here**: the sandbox could not reach `packages.sury.org`. `install.sh` must assert at runtime and fail closed:

  ```sh
  php8.4 -m | grep -qi '^sodium$' || apt-get install -y --no-install-recommends php8.4-sodium
  php8.4 -m | grep -qi '^sodium$' || { echo "php sodium extension missing"; exit 1; }
  ```

  The `apt-get` line is a fallback in case sury splits it out again; if the package does not exist the second check still fails the install.
- No new system packages otherwise. `libsodium23` arrives as a dependency of the PHP package.

### Files, owners, modes

| path | owner | mode | created by |
|---|---|---|---|
| `/etc/fusionpbx/x_carriers/` | `root:www-data` | `0750` | install.sh |
| `/etc/fusionpbx/x_carriers/keys/` | `root:www-data` | `0750` | install.sh |
| `/etc/fusionpbx/x_carriers/keys/<key_id>.key` | `root:www-data` | `0640` | key generation |
| `/etc/fusionpbx/x_carriers/keys/active` | symlink | | key generation |
| `/var/backups/x_carriers-keys/` | `root:root` | `0700` | install.sh |
| `/etc/cron.daily/x_carriers-keybackup` | `root:root` | `0755` | install.sh |
| `/var/www/fusionpbx/app/x_carriers/` | `www-data:www-data` | as rest of web root | install.sh (copy from this repo, not a git clone inside the web root) |

`www-data` can read the key because the web app must decrypt (section 6.1). It cannot write the key directory, so a web-user compromise cannot swap or delete keys, only read them.

### Key generation (run as root, idempotent)

```sh
key_id="k$(date -u +%Y%m%dT%H%MZ)"
dir=/etc/fusionpbx/x_carriers/keys
install -d -o root -g www-data -m 0750 /etc/fusionpbx/x_carriers "$dir"
if [ ! -e "$dir/active" ]; then
  umask 027
  head -c 32 /dev/urandom | base64 -w0 > "$dir/$key_id.key"
  chown root:www-data "$dir/$key_id.key"; chmod 0640 "$dir/$key_id.key"
  ln -s "$key_id.key" "$dir/active"
fi
```

`php /var/www/fusionpbx/app/x_carriers/resources/cli/x_carriers_keyring.php generate` does the same from PHP using `sodium_crypto_secretbox_keygen()`; the shell form exists so install.sh does not depend on the app being deployed first.

### Commands install.sh must run (in order, after upstream `finish.sh` equivalent)

```sh
# 1. extension check (above)
# 2. deploy app
rsync -a --delete app/x_carriers/ /var/www/fusionpbx/app/x_carriers/
chown -R www-data:www-data /var/www/fusionpbx/app/x_carriers
# 3. key
<key generation block>
# 4. register with FusionPBX (schema, defaults seed the carrier catalogue, menu, permissions)
cd /var/www/fusionpbx
sudo -u www-data php core/upgrade/upgrade.php --schema
sudo -u www-data php core/upgrade/upgrade.php --defaults
sudo -u www-data php core/upgrade/upgrade.php --menu
sudo -u www-data php core/upgrade/upgrade.php --permissions
# 5. verify key readable by web user and store decrypts
sudo -u www-data php app/x_carriers/resources/cli/x_carriers_keyring.php check
# 6. key backup cron
install -o root -g root -m 0755 files/x_carriers-keybackup /etc/cron.daily/x_carriers-keybackup
install -d -o root -g root -m 0700 /var/backups/x_carriers-keys
```

### Commands upgrade.sh must run

```sh
rsync -a --delete app/x_carriers/ /var/www/fusionpbx/app/x_carriers/
chown -R www-data:www-data /var/www/fusionpbx/app/x_carriers
cd /var/www/fusionpbx
sudo -u www-data php core/upgrade/upgrade.php --schema
sudo -u www-data php core/upgrade/upgrade.php --defaults
sudo -u www-data php core/upgrade/upgrade.php --menu
sudo -u www-data php core/upgrade/upgrade.php --permissions
sudo -u www-data php app/x_carriers/resources/cli/x_carriers_keyring.php check
# optional, if the operator passed --rotate-carrier-key
php app/x_carriers/resources/cli/x_carriers_keyring.php rotate && \
sudo -u www-data php app/x_carriers/resources/cli/x_carriers_keyring.php reencrypt
```

`check` exits non-zero if `active` is missing, unreadable by `www-data`, or any `x_carrier_credential_key_id` refers to a key file that no longer exists. upgrade.sh must abort on non-zero before restarting services.

## 11. Unverified / open

- Sodium bundling in sury `php8.4-common` (section 10). Runtime assertion covers it.
- Whether `sofia profile external rescan` on FreeSWITCH 1.11 re-reads a changed `password` for an existing gateway, or whether `killgw` is required first (section 5.1 step 5).
- The `config.conf` 664 world-readable DB password (`finish.sh:115`) is an upstream installer weakness outside this feature's scope; it bounds how much this design can protect, and should be tracked separately.
- Carrier seed rows in `app_defaults.php` need per-carrier proxy/transport values confirmed from each carrier's documentation before shipping.
