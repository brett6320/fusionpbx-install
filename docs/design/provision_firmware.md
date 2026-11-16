# Phone firmware (`x_provision_overlay` enhancement)

Status: implemented, 2026-11-16 (not yet tried on phones: see 5). Deviations
from the first draft are marked *Built:*. Builds on [provision_overlay.md](provision_overlay.md),
reuses the managed device settings of
[provision_wallpapers.md](provision_wallpapers.md) (3.1-3.4) and the reboot
queue of `x_endpoint_manager`. Upstream refs are to the commit pinned in
`config.sh` (`58ca4e9`).

## 1. Goal

- A superadmin keeps a **firmware library**: vendor firmware files, one
  **release** per file (vendor, family, version).
- Each domain sets, per family, a **domain default**: a release, **none**
  (phones are pointed at an address with no firmware and keep what they run),
  or **unset** (upstream behavior, see 3.6).
- A **device target** overrides it for one device: a release, none, or
  follow the domain.
- **Staged rollout**: move a domain to a release in batches. A batch sets
  device targets for chosen devices and activates them (3.4); the last step
  makes the release the domain default.
- An **inventory** shows per device the target and the version the phone
  reports, so a rollout can be checked.
- First version: Yealink and Grandstream. There is deliberately no system-wide
  default: one change would reach every domain's phones at their next fetch,
  unstaged.

## 2. What upstream does (verified)

Verified against the templates at the pin (`resources/templates/provision`
of `58ca4e9`), not `references/` (the old server's copies, which differ).

### 2.1 Yealink

70 template dirs write `static.firmware.url = {$yealink_firmware_url}/{$yealink_firmware_<var>}`
(`vp59` reads only `yealink_firmware_url`). The phone downloads it during auto
provisioning and upgrades (and reboots) when the version differs from what it
runs, older or newer. The `<var>` a template reads does not identify the
firmware family: in `t4x`, `y000000000107.cfg` (T43U) and `y000000000109.cfg`
(T48U), family 108, read `yealink_firmware_t48s` (family 66) and
`y000000000108.cfg` (T46U) reads `t46s`; `t42u` reads `t42g`, `w7xp` `w60b`,
`cp925` `cp920`, `ax86r` `ax83h`. Mixed templates (`t2x`, `t4x`, `t5x`) carry
one `y0000000000NN.cfg` per model; the phone fetches its own. Unset renders
`static.firmware.url = /`.

### 2.2 Grandstream

`P192` (or `provisioning.firmware.serverPath` in the named-setting
templates) takes several shapes:

- `{$grandstream_firmware_path}/<dir>/{$firmware_version}`, else
  `.../<dir>`, else the provision URL (most `gxp`, `ht`, `gxw`, `dp`, `gxv`,
  `gac` templates; some `<dir>` have a trailing slash);
- no `<dir>`: `{$grandstream_firmware_path}/{$firmware_version}`, else the
  path alone (`grp2612`-`grp2616`, `grp261x`, `grp26xx`, `gsc3516`,
  `gsc3518hs`, `gxp2130`-`gxp2170`, `gxp21xxbk`, `gxp2200`, `gxv3240`,
  `gxv3370`, `gxv3480`, `wp810`, `wp820`, `wp826`); `gxp2130`-`gxp2170` also
  have a branch with `{$grandstream_firmware_version}`;
- `gxp2100`, `gxp21xx`, `gxv300x`, `gxv3240` fall back to Grandstream's public
  server when the path is unset; `gswave` and `wave` have no firmware path.

`$firmware_version` is upstream's column `v_devices.device_firmware_version`
(`provision.php` 572, 1467); Smarty's `isset()` is true for `''`, so a blank
saved value adds an empty segment. The phone requests `<path>/<vendor file
name>` (e.g. `gxp2170fw.bin`). Scheme from `P212` (template-fixed, HTTPS in
most). When it checks (`P238`/`P194`) is template-fixed; several templates
check periodically, or wherever `{$grandstream_auto_upgrade}` is set.

### 2.3 Settings, signaling, reporting

- `provision.php` (812-841): default, domain, profile, then device settings;
  device rows win; an enabled empty device setting is still assigned.
- `event_notify` sends `check-sync` (Yealink) / `resync` (Grandstream) for
  `check_sync`, and a reboot for `reboot`. `x_endpoint_manager` queues reboots
  through it (rate limits, log). Its `enqueue()` hard-codes
  `reboot_trigger` to manual/schedule (341), skips devices already queued
  without returning them, treats `skipped_busy`/`skipped_unregistered` as
  final, expires pending rows after 120 minutes, and has a 5-minute
  duplicate guard.
- Each provisioning request stores `device_provisioned_agent` and
  `device_provisioned_date` (`provision.php` 561): `Yealink SIP-T46S
  66.86.0.15 805ec0...`, `Grandstream Model HW GXP2170 SW 1.0.11.79 DevId ...`.
  The version's first field is the Yealink family.
- Upstream's `provision/cidr` allow-list applies to provisioning
  (`app/provision/index.php` 287). nginx port 80 doesn't redirect URIs
  matching `(provision|xml_cdr|firmware)`.

## 3. Design

### 3.1 Library

- A release is vendor, family, version, vendor file name, size, sha256,
  uploader, time, note; one table `v_x_provision_overlay_firmware` (global);
  file at `/var/lib/fusionpbx/provision_overlay/firmware/<release_uuid>/<served name>`.
- Family:
  - Yealink: ROM prefix (`66`, `108`, ...), parsed with the version from the
    file name (`T46S(T48S,T42S,T41S)-66.86.0.15.rom`). Served as
    `<version>.rom`.
  - Grandstream: the vendor file name (`gxp2170fw.bin`); the admin enters the
    version. Served under that name (the phone asks for it).
  Files matching no catalog family are refused; `.zip` is refused (unpack
  first).
- Import: CLI `resources/cli/firmware.php --import <file> [--version v]
  [--note n]` (any size), and the Library page within the existing
  PHP/nginx upload limits. No per-location `PHP_VALUE` (it sticks to the
  php-fpm child).
- Delete: refused while a domain default, a device target, or any setting
  (domain, profile, device) contains the release uuid; the file goes after a
  day's grace (daily overlay job), as wallpapers.

### 3.2 Catalog

Keyed by what decides the firmware, not by the variable name:

- Yealink: (template dir, model cfg `y0000000000NN.cfg`) => family, variable
  pair. Single-model templates have one entry; mixed templates pick the entry
  from the model in the user agent (unknown => state `unknown_model`).
- Grandstream: template dir => families (file names its models request) and
  whether it has a usable firmware path with `grandstream_firmware_path`
  (no => state `unsupported`: `gswave`, `wave`).
- Template check as wallpapers 3.3 (variables still referenced in the merged
  template the domain uses).
- `test/` checks the catalog against the pinned templates.

*Built:* the catalog is generated (`test/firmware_catalog.php`, checked by
`test/checks.sh`) as template dir => every variable it prints: for Yealink
`yealink_firmware_url` and all `yealink_firmware_<var>` of the dir, for
Grandstream `grandstream_firmware_path`. No (template, model cfg) => family
table: a reliable model => ROM prefix source wasn't available, so it would
have been unverified data. Instead the family comes from the phone:

- Yealink: the first field of the version in the user agent provision.php
  records (`Yealink SIP-T46S 66.86.0.15 ...` => `66`). Every
  `yealink_firmware_<var>` of the template gets the same file name, so the cfg
  the phone actually fetches (mixed templates, upstream's crossed variables
  such as `t42u` reading `t42g`) reads the right value. A phone that never
  fetched has no family: `unknown_model`, no rows.
- Grandstream: the model in the user agent (`HW GXP2170 SW ...`), else the
  device's model field; a release lists the models it serves (default from
  its file name), else `<model>fw.bin`.

`family_mismatch` therefore can't happen and isn't a state.

### 3.3 Resolving and writing a device's target

For each enabled device on a catalog template, without `device_uuid_alternate`:

1. Family from the phone (3.2, *Built*); unknown => `unknown_model`.
2. Target: device target (manual or from a rollout), else domain default,
   else nothing (no managed rows). A target whose release isn't of the
   device's family (template changed, device moved) is ignored and shown as
   `invalid_target`.

Managed rows, exactly as wallpapers (precedence, opt-out by disabling,
links table `v_x_provision_overlay_firmware_links`, guarded update/delete):

| Vendor | Variables | Release | None |
|---|---|---|---|
| Yealink | `yealink_firmware_url`, the entry's `yealink_firmware_<var>` | `http://{$domain_name}/provision-firmware/<uuid>`, `<version>.rom` | `.../provision-firmware/none`, `none.rom` |
| Grandstream | `grandstream_firmware_path` | `{$domain_name}/provision-firmware/<uuid>` | `{$domain_name}/provision-firmware/none` |

- Grandstream: whatever the template appends (`/<dir>`, `/<version>`, an
  empty segment) is ignored by the front (3.5), which serves
  `<uuid>/**/<file>` when `<file>` is the release's file name. No hard links,
  and `device_firmware_version` doesn't make a device "own".
- Precedence: an enabled domain, profile or unmanaged device value for a
  variable of the set makes the device "own" (labelled). A row whose value is
  exactly what we would write is adopted (copied devices, pasted values)
  instead of counting as own.
- Sync: per domain in the request after a change; `x-provision-firmware.path`
  on its own request file `.firmware-sync.request`; timer every 2 minutes
  (devices added or changed in Devices). Holds the overlay lock for the write
  only.

### 3.4 Staged rollout

One table `v_x_provision_overlay_firmware_targets` (domain, device, family,
release or none, source = manual / rollout uuid, state, reboot log uuid,
attempts, times) and `v_x_provision_overlay_firmware_rollouts` (domain,
family, release, from release, state). One active rollout per domain and
family; the domain default of that family can't change meanwhile.

A batch is started by hand ("start batch": picked devices, or N of the
eligible remaining). Eligible = managed-capable devices of the family not on
the release, excluding own, opt-out, alternate, unknown model, unsupported,
family mismatch, and devices with a manual target. Starting a batch:

1. writes targets (source = rollout) and syncs the rows;
2. sends `check_sync` to each device (Yealink upgrades and reboots only if
   needed; Grandstream re-provisions);
3. waits until each device's `device_provisioned_date` is after the start
   (it fetched the new rows);
4. queues a reboot (through `x_endpoint_manager`, trigger `firmware`,
   column `firmware_rollout_uuid`) only for devices that fetched but don't
   yet report the target. Covers Grandstream checking firmware at boot.

Per-device state: written, fetched, rebooting, done (reports the release),
skipped (busy/unregistered: retried up to 3 times by the runner, then
"pending: next boot"), failed (rebooted and fetched again, still another
version after 30 minutes). Devices that never fetch within a day: "not
fetching".

- **Abort**: removes targets only of devices not yet fetched since the start
  and cancels their queued reboots (helper like `cancel_schedule_queue`, by
  rollout uuid). Devices that fetched keep their target (now source =
  manual), so a Yealink that upgraded is never downgraded by surprise; a new
  rollout moves them back.
- **Finish** (all eligible done or the admin decides): sets the domain
  default to the release, converts the rollout's targets to "follow domain",
  ends the rollout.
- *Built:* the runner moves each device written -> notified (`check_sync`
  sent, at most twice the domain's reboot rate per run; after 3 failures it
  goes on, the reboot follows) -> fetched -> rebooting -> rebooted, and to
  done as soon as the phone reports the release. `skipped` is not a separate
  state: a busy/unregistered/expired reboot goes back to fetched and is
  retried, after 3 attempts `pending_boot`. The page shows the state, the last
  detail and since when.
- The runner (`--run-rollouts`, from the firmware timer) does steps 3-4 and
  the retries; it doesn't hold the overlay lock while queuing.
- A domain default change outside a rollout reaches every phone of the family
  at its next fetch; the page says so and offers a rollout instead.

### 3.5 Serving

`/provision-firmware/<release_uuid>/<anything>/<file>`, :80 and :443:

- `/provision-firmware/none/` is a plain nginx `return 404`.
- Otherwise nginx passes to `firmware.php` (no session, GET/HEAD only):
  uuid must match the uuid pattern and a release row; `<file>` must equal
  the release's served name; the path on disk is built from the row only.
  Upstream's `provision/cidr` allow-list is honored. Then `X-Accel-Redirect`
  to an `internal` location (alias with matching trailing slashes), with
  `Content-Type: application/octet-stream`, `X-Content-Type-Options:
  nosniff`, `Content-Disposition: attachment`. `http_auth` does not apply
  (documented: vendor files, no secrets).
- `limit_conn` per IP on the location (2); the zone is in
  `/etc/nginx/conf.d/x_provision_firmware.conf` (http context). *Built:* no
  `limit_rate_after`.
- Each request is logged (release, domain, address, user agent, GET/HEAD) to
  a table, pruned after 90 days (*Built:* not status or bytes: nginx sends the
  file after PHP has finished). Gives a "downloaded" signal and
  shows if Yealink re-downloads on every fetch (5, risk).

### 3.6 Inventory and UI

**Firmware** page in Provision Templates:

- **Library** (superadmin, `x_provision_overlay_firmware_library`):
  releases by vendor/family, import, note, delete, used-by counts.
- **Domain** (`x_provision_overlay_firmware_edit`, admin and superadmin):
  per family in the domain: default (release / none / unset), with the
  difference spelled out: unset = upstream (Yealink empty URL, `gxp2100`
  Grandstream's public server), none = our 404 (logged on every Yealink
  fetch); device list with target, source, reported version, last fetch,
  state; per-device target (release / none / follow domain).
- **Rollout**: start, batches, per-device state, start batch, abort, finish.
- *Built:* one page with three views (domain, `?tab=library`,
  `?rollout=<uuid>`); recent downloads on the library view.

## 4. Code

- `resources/classes/provision_firmware.php`: catalog, library, resolve,
  sync, rollout steps, UA parsing. *Built:* the managed-row code is a copy of
  the wallpaper code adapted to firmware, not extracted: no test pins the
  wallpaper behavior yet, and extracting it would change wallpaper code
  without one. Extract once both are covered.
- Page `x_provision_overlay_firmware.php`;
  `firmware.php` front; nginx locations in `resources/apps/provision_overlay.sh`.
- `x_endpoint_manager`: `enqueue()` takes trigger `firmware` and a rollout
  uuid, returns per device queued / already queued; cancel by rollout uuid.
  *Built:* `enqueue()` keeps its signature and return value (queued log
  uuids); the new `enqueue_devices()` takes the rollout uuid and returns
  `device_uuid => queued | already_queued` with the log uuid.
  `cancel_rollout_queue()`, `log_statuses()`, and `check_sync()` (same busy
  and registration checks as a reboot, not queued) are new.
- CLI `firmware.php --import | --sync [--domain] | --run-rollouts | --cleanup`;
  systemd `x-provision-firmware.{service,timer,path}`.
- Backups: firmware under `/var/lib/fusionpbx`, in bundles.

## 5. Risks, to verify on hardware first

- Grandstream: does the phone fetch config before checking firmware at boot?
  Step 3-4 of 3.4 works either way, but costs a second reboot if not.
- Yealink: does it re-download an equal-version ROM on every auto provision?
  The download log shows it; if so, rows are written only while a device's
  reported version differs from the target.
- Grandstream file names per model and shared files; Yealink family prefixes
  per model cfg. Recorded per catalog entry with source.
- Downgrades: Yealink accepts them; some Grandstream builds refuse. Shown as
  failed.
- A bad image can brick a phone; the phone checks the vendor signature, we
  don't. Rollouts limit the blast radius.
