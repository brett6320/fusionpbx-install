# Provision template overlay (`x_provision_overlay`)

Status: design, 2026-11-11. Upstream refs are to `fusionpbx/fusionpbx` master
(commit `13c5dda`, 2026-11-11, post-5.6.5) and `fusionpbx-install.sh` `debian/`.
Paths below are relative to `/var/www/fusionpbx` unless absolute.

## 1. Problem

Admins need to customize provisioning templates (per vendor/model, globally
and per domain) and the logos/wallpapers those templates reference, without
editing upstream files under `resources/templates/provision/`, and while still
receiving upstream template fixes when `upgrade.php --main` runs `git pull`
(`core/upgrade/upgrade.php:534-544`, `resources/functions.php:3056-3109`; it is
a plain `git pull`, no reset, so untracked files survive but tracked edits
cause merge conflicts).

## 2. What upstream actually does (verified)

### 2.1 Template directory selection

- `app/provision/resources/classes/provision.php:115-126` (constructor, Linux):
  `template_dir` = first existing of
  `/usr/share/fusionpbx/templates/provision`,
  `/etc/fusionpbx/resources/templates/provision`,
  `<project>/resources/templates/provision`.
- `provision.php:845-847` (`render()`): if the merged provision settings
  contain `template_dir` (default/domain/device-profile/device scope), it
  replaces the constructor value.
- `provision.php:850-852`: if `<template_dir>/<domain_name>/` is a directory,
  the template is resolved as `<domain_name>/<vendor>/<model>` - the domain
  subdir is all-or-nothing.
- `provision.php:856-869`: the default file is the first of `{$address}`,
  `{$address}.xml`, `{$mac}`, `{$mac}.xml`, `{$mac}.cfg` that exists;
  `provision.php:1574-1583` 404s if the requested file does not exist.
- `provision.php:878-881`: Smarty `template_dir` is
  `<template_dir>/<device_template>/`; compile dir is `sys_get_temp_dir()`.
  `resources/classes/template.php:45-51` configures Smarty 4.3.1
  (`resources/templates/engine/smarty/Smarty.class.php:110`) with no security
  policy.

### 2.2 Code paths that do NOT honor `provision.template_dir`

These all use the constructor/hard-coded search order, so the setting alone
is not enough:

| Where | What it does |
|---|---|
| `app/devices/resources/classes/device.php:499-547` `get_template_dir()` | Same hard-coded search as the provision constructor; appends `<domain_name>` subdir if present (541-543). Never reads settings. |
| `app/devices/device_edit.php:1291-1292` | Template dropdown: `new device` (no `domain_name` set) + `get_template_dir()`; enumerates `<dir>/<vendor>/<model>` for every vendor row in `v_device_vendors` (`device_edit.php:710-713`, `1295-1317`). Only directories are listed; dotfiles skipped. |
| `device_edit.php:1176-1178` | "Files" download list: `glob($prov->template_dir.'/'.$device_template.'/*')` from the constructor default, no domain subdir. |
| `device_edit.php:910-917, 935-942, 963-970` | `template.csv` / `qr_template.txt` lookups hard-code `/usr/share/fusionpbx/templates/provision` then `/var/www/fusionpbx/resources/templates/provision`. |
| `provision.php:1668-1700` `write()` | Writes files to `provision.path` by `readdir` of `$this->template_dir` (constructor default, no setting, no domain subdir). Called from `device_edit.php:545-547`, `device.php:617-619, 1164-1168`, `device_imports.php:447`, `device_keys.php:284`, `theme_import.php:398`. |

Consequence: the merged directory must live at
`/usr/share/fusionpbx/templates/provision`, the first entry of every
hard-coded search list. Then every path above picks it up with no setting
required. `provision.template_dir` is set as well (section 6) only as a guard
against a future change of the search order.

### 2.3 Upstream template inventory

`resources/templates/provision/`: 31 vendor dirs, 385 `<vendor>/<model>`
template dirs, 1037 files; no sub-subdirectories, no symlinks, no binaries,
no Smarty `{include}` (verified with `find`/`grep`). Extensions: xml, cfg,
boot, txt, conf, cnf, ini, csv, md, ott. Branding images are never shipped;
templates reference them by URL from settings, e.g.
`{$polycom_background_url}` (`app/polycom/app_config.php:957`,
`resources/templates/provision/polycom/vvx450/{$mac}.cfg:420-423`),
`{$grandstream_wallpaper_url}` (`app/grandstream/app_config.php:292`,
`grandstream/gxp2170/{$mac}.xml:2671-2677`), `poly_obi_logo_450`
(`app/poly/app_config.php:53`). Yealink templates have no wallpaper/logo
setting at all, so a Yealink logo requires a template override (the main use
case for this app). `{$domain_name}` / `${domain_name}` inside provision
setting values are substituted at render time (`provision.php:1563-1568`).

### 2.4 Settings, defaults and upgrades

- Settings load default -> domain -> user (`resources/classes/settings.php:6`);
  disabled rows are ignored (`settings.php:172, 232`). Results are cached in
  APCu with no TTL (`settings.php:200-202`); `settings::clear_cache()`
  (`settings.php:435`) only clears the cache of the process calling it.
- `provision.enabled` ships as uuid `931f9369-9aac-4620-8d4b-7d2bf642b1d2`,
  value `true`, `default_setting_enabled = false`
  (`app/provision/app_config.php:35-41`).
- `upgrade.php --defaults` (`upgrade.php:255-321`) runs `domains::upgrade()`
  (`resources/classes/domains.php:323-385`), which calls
  `domains::settings()` (`domains.php:397-440`): it inserts only default
  settings whose uuid is missing (`domains.php:421`). It never updates value
  or enabled flag of existing rows. Then every `app_defaults.php` is included
  once per domain (`domains.php:364-383`); `app/provision/app_defaults.php`
  only normalizes types (lines 81-150) and does not touch `enabled`.
  So a row enabled once stays enabled, and an admin disabling it later is not
  overridden by upgrades.
- `--defaults` also runs `update_php_fpm()` (`upgrade.php:600-719`): creates
  `/usr/share/fusionpbx` (636-641) and writes
  `ReadWritePaths=/tmp /etc/freeswitch /usr/share/freeswitch
  /var/lib/freeswitch <cache> /usr/share/fusionpbx /etc/fusionpbx` into the
  php-fpm unit, then `daemon-reload` + restart (709-719). This is upstream's
  signal that www-data is expected to write under `/usr/share/fusionpbx`.
- `--permissions` (`upgrade.php:362-385`, `734-790`) does
  `chown -R www-data:www-data` on the project root and FreeSWITCH dirs
  (`-R` without `-L`, so it does not follow symlinks).
- Installer: `fusionpbx.sh:31-32` clones and chowns `/var/www/fusionpbx`;
  `finish.sh:35-36` creates `/etc/fusionpbx/config.conf`; `finish.sh:42,61,95,
  98,109` run `--schema`, `--defaults` (twice), `--permissions`, `--services`.
  Optional apps are separate git clones under `app/` (`applications.sh:12-32`).
  `.gitignore` does not cover `app/x_*`, but `git pull` leaves untracked
  paths alone unless upstream adds the same path - the `x_` prefix avoids that.

### 2.5 HTTP entry for phones

- nginx (`inst/debian/resources/nginx/fusionpbx`): port 80 redirects to
  https unless `$request_uri` matches `(provision|xml_cdr|firmware)`
  (lines 70, 83); everything goes through `try_files ... /public/index.php`
  (76, 170-174). Port 443 has `location ~ \.php$` (188-195) that executes
  PHP files directly; port 80 does not.
- Front controller `core/public/index.php`: provision paths skip the session
  (37-40) and route to `app/provision/index.php` (135-146). Static assets are
  served only if the file exists under `PROJECT_ROOT` (177-194, 231-240).
  Any other `/app/<name>/<file>` falls through to `<name>/<name>.php`
  (215-219) - brittle; do not depend on it for phone-facing URLs.
- `app/provision/index.php` takes the domain from `HTTP_HOST` (50-57),
  requires `provision.enabled` for that domain (271-277), then renders
  (507-510).

## 3. Design

### 3.1 Layers

```
upstream   /var/www/fusionpbx/resources/templates/provision/<vendor>/<model>/<file>   (git, read-only to us)
global     /var/lib/fusionpbx/provision_overlay/global/<vendor>/<model>/<file>         (admin, all domains)
domain     /var/lib/fusionpbx/provision_overlay/domains/<domain_uuid>/<vendor>/<model>/<file>
assets     /var/lib/fusionpbx/provision_overlay/assets/<domain_uuid>/<file>            (logos, wallpapers)
merged     /var/lib/fusionpbx/provision_overlay/merged/<build-id>/                     (generated, disposable)
link       /usr/share/fusionpbx/templates/provision -> ../../../var/lib/fusionpbx/provision_overlay/merged/current
```

Overlay state is keyed by `domain_uuid`, never by `domain_name`, so renaming a
domain does not orphan overrides; the generator looks up the current name.
Everything under `/var/lib/fusionpbx/provision_overlay` is outside the webroot
and outside the upstream git tree.

### 3.2 Merge algorithm

Granularity is per file inside a template directory. A per-template-dir
symlink would lose upstream fixes to the sibling files the admin did not
touch (e.g. overriding `yealink/t46s/{$mac}.cfg` must not freeze
`y000000000066.cfg`).

```
build(id):
  M = merged/<id>            # fresh dir, 0750 www-data:www-data
  U = upstream templates, G = global overlay
  T = set of <vendor>/<model> dirs in U ∪ G
  for t in T:
      mkdir M/t
      for f in files(U/t): symlink U/t/f -> M/t/f
      for f in files(G/t): copy G/t/f -> M/t/f   (replaces upstream symlink)
  for each domain d with a non-empty domains/<d.uuid>/ tree:
      D = M/<d.domain_name>                  # only domains that have overrides
      for t in T ∪ dirs(domains/<d.uuid>):
          mkdir D/t
          for f in files(M/t):     symlink M/t/f -> D/t/f    (global-merged view)
          for f in files(dom/t):   copy dom/t/f -> D/t/f
  write M/.build.json {id, upstream_commit, counts, generated_at}
  rename-swap: merged/current -> merged/<id>  (symlink replaced atomically with
      ln -sfn + rename of a temp link); delete previous build dirs except the
      last one (kept for rollback).
```

Notes

- Overlay files are copied, not symlinked, into the merged tree so Smarty
  mtime checks and `readdir`/`glob` see ordinary files; upstream files are
  symlinked so a `git pull` is live immediately (symlinks resolve at read
  time; Smarty recompiles on mtime change).
- Domain subdirs are generated only for domains that have at least one
  override. Because the domain subdir is all-or-nothing
  (`provision.php:850-852`), a domain dir must contain every template, hence
  the full symlink fan-out (about 1040 links per such domain).
- Domain subdir name must equal `domain_name` exactly; it is created only if
  it matches `^[a-z0-9][a-z0-9.-]{0,252}$` (refuse and log otherwise).
- A vendor dir that exists only in the overlay is also written, but the
  device dropdown lists only vendors in `v_device_vendors`
  (`device_edit.php:710-713`), so the UI warns if the vendor row is missing.
- Files named `*.db`, dotfiles, `.git*` are skipped (matches `write()`'s
  ignore list, `provision.php:1672-1676`, and nginx deny rules).
- Writers hold `flock` on `provision_overlay/.lock`; builds are serialized.

### 3.3 Upstream adds / removes / renames templates

| Upstream change | Effect after regen |
|---|---|
| New template dir or file | Appears via symlink in the next build; appears in the dropdown (which reads the live link, no restart). |
| File removed | Symlink disappears; if an overlay file for it exists, the overlay copy remains and the UI flags it "no upstream counterpart". |
| Template dir removed/renamed | Upstream symlinks vanish. An overlay dir for the old name still materializes as an admin-owned template (device rows still reference it, so provisioning keeps working). UI flags "upstream template missing"; admin re-points devices or deletes the override. Nothing is auto-migrated. |
| Overlay file whose upstream base changed | Rendered from the admin copy (upstream fix not applied). UI shows a diff of overlay vs current upstream and a one-click "reset to upstream". |

Regen triggers: install, every wrapper upgrade (after `upgrade.php --main`
and `--optional`), every UI save/delete/upload, and a daily systemd timer
(catches out-of-band `git pull`, domain renames and domain deletes).

### 3.4 Branding assets

Stored per domain under `assets/<domain_uuid>/`, served over HTTP and HTTPS at
`http(s)://<domain_name>/provision-assets/<file>` by a dedicated nginx
location in both the :80 and :443 server blocks that fastcgi-passes to
`app/x_provision_overlay/assets.php` (installer-owned nginx vhost; see
section 8). Reasons: port 80 has no `\.php$` location and the front
controller cannot serve files outside `PROJECT_ROOT` (2.5); the URL must
contain `provision` to escape the https redirect (nginx:70,83). `assets.php`:
`$no_session = true`; domain from `HTTP_HOST` exactly like
`app/provision/index.php:50-57`; 404 unless the domain exists, is enabled,
`provision.enabled` is true for it, the filename matches
`^[a-z0-9][a-z0-9._-]{0,63}$`, and the file exists under that domain's asset
dir (realpath-checked); content type from the sniffed type recorded at upload
(never from the extension); `Cache-Control: public, max-age=300`. No
provision HTTP auth: phones do not reliably send credentials for secondary
downloads, and the files are non-sensitive branding.

Templates reference assets through settings, not paths. The branding page
offers "apply to vendor settings", which writes domain settings such as
`provision.polycom_background_url = http://{$domain_name}/provision-assets/wallpaper.jpg`
(substituted at `provision.php:1563-1568`). For vendors without an upstream
setting (Yealink), the page offers a canned per-template override snippet
that the admin saves as a domain override.

### 3.5 First-install enabling of provisioning

`provision.enabled` row exists but is disabled (2.4). The wrapper's
`install.sh` runs, after `finish.sh`'s `--defaults`:

```
update v_default_settings set default_setting_enabled = true
 where default_setting_uuid = '931f9369-9aac-4620-8d4b-7d2bf642b1d2';
```

guarded by marker `/var/lib/fusionpbx-install/state/provision_enabled.done`.
`upgrade.sh` never touches the row. Because `domains::settings()` only
inserts missing uuids, upstream upgrades do not revert an admin who later
disables it. The marker protects against re-running our own installer.
Restart php-fpm afterwards (APCu, 2.4).

## 4. Regeneration mechanics

- Generator: `app/x_provision_overlay/resources/classes/provision_overlay.php`
  (pure PHP, no DB writes; reads `v_domains` for uuid->name). CLI wrapper:
  `app/x_provision_overlay/resources/cli/build.php` with `--build`,
  `--status`, `--rollback`, `--verify` (reports dangling symlinks, orphaned
  overrides, invalid domain names).
- Runs as `www-data` everywhere: from php-fpm (UI save) directly, from the
  installer via `runuser -u www-data -- php .../build.php --build`, and from
  `x-provision-overlay.timer` (`User=www-data`). Nothing needs root, no sudo
  rule, no setuid. `/usr/share/fusionpbx/templates` is owned by www-data so
  the `current` symlink swap works from php-fpm; `/usr/share/fusionpbx` is
  already in the php-fpm `ReadWritePaths` written by upstream (2.4).
- Modes: dirs 0750, files 0640, owner www-data:www-data. nginx never reads
  these paths directly (assets go through PHP).
- Smarty compiles into `sys_get_temp_dir()`; with Debian's `PrivateTmp` on
  php-fpm this is per-service and needs no handling.
- Settings cache: builds do not change settings, so no APCu work. The
  installer's one-off setting writes are followed by
  `systemctl restart php<ver>-fpm`.

## 5. Admin UI (`app/x_provision_overlay/`)

Standard app layout: `app_config.php` (uuid, name, permissions, default
settings), `app_menu.php`, `app_languages.php`, pages, `resources/classes/`.
All pages include `resources/check_auth.php` and are domain-scoped by
`$_SESSION['domain_uuid']` like `app/devices`.

Pages

- `x_provision_overlay.php` - list: every `<vendor>/<model>` (upstream ∪
  overlay) with per-file status for the current domain: upstream / global
  override / domain override / orphaned. Filter by vendor. Shows build id and
  upstream commit from `.build.json`.
- `x_provision_overlay_edit.php` - edit one file for one scope: text editor
  with upstream/global source shown read-only, diff view, Save (writes
  overlay file, runs build), Reset to upstream (deletes override, runs build).
- `x_provision_overlay_assets.php` - upload/list/delete branding files for the
  current domain; shows the resulting URL; "apply to vendor settings".
- `x_provision_overlay_build.php` - status, Rebuild now, Rollback to previous
  build (superadmin).

Permissions (`app_config.php`; `superadmin` only unless noted)

| Permission | Grants | Default groups |
|---|---|---|
| `x_provision_overlay_view` | list and status pages | admin, superadmin |
| `x_provision_overlay_domain_edit` | create/edit/delete overrides for own domain | superadmin |
| `x_provision_overlay_global_edit` | global scope overrides | superadmin |
| `x_provision_overlay_asset_edit` | upload/delete assets for own domain, apply settings | admin, superadmin |
| `x_provision_overlay_build` | rebuild / rollback | superadmin |

Template editing is code execution: Smarty runs without a security policy
(`template.php:45-51`), so a template author can call arbitrary PHP
functions as modifiers as www-data. Therefore `*_domain_edit` is NOT
granted to `admin` by default even though it is domain-scoped; the
installer documents how to grant it per deployment. Asset upload is safe to
give to domain admins.

Upload validation (assets)

- Sniff with `finfo` and `getimagesize`; accept only `image/png`,
  `image/jpeg`, `image/bmp`, `image/gif`. Reject SVG and anything whose
  sniffed type is not in the list, regardless of extension; the stored
  extension is derived from the sniffed type.
- Size cap: default setting `x_provision_overlay.asset_max_bytes` (default
  2097152); dimension cap 4096x4096; max 50 files per domain.
- Filename: lower-cased, `^[a-z0-9][a-z0-9._-]{0,63}$`, no path separators,
  no double extension tricks (`a.php.png` is renamed to `a_php.png`).
- Written with `move_uploaded_file` to a temp file, validated, then renamed
  into place; mode 0640.

Validation (template overrides)

- Scope/vendor/model/file names: `^[A-Za-z0-9._{}$-]{1,64}$` per path
  component (upstream names contain `{$mac}` and `{$address}`), no `..`,
  must not start with `.`; vendor/model must exist upstream or be explicitly
  "new template" with a vendor row in `v_device_vendors`.
- Content: UTF-8 text, max 1 MiB, no NUL bytes. No attempt to sandbox Smarty
  (see permissions above).

## 6. `provision.template_dir` default setting

Set once on first install (same marker mechanism as 3.5, marker
`template_dir.done`), as a default setting:

```
category=provision subcategory=template_dir name=text
value=/usr/share/fusionpbx/templates/provision enabled=true
uuid=<fixed uuid owned by this project>
```

Because the value equals the first hard-coded search path it is redundant
today; it is set only so `render()` keeps using the merged tree if upstream
reorders the search list. `upgrade.sh` never rewrites it, so an admin
override (or a domain-level override to a different directory) survives.
The build also refuses to run if `/usr/share/fusionpbx/templates/provision`
exists and is not our symlink (e.g. a real directory from the upstream
Debian package) and reports it instead.

## 7. Rollback / disable

- `build.php --rollback`: repoint `current` to the previous build dir.
- Disable entirely: `build.php --disable` removes the
  `/usr/share/fusionpbx/templates/provision` symlink (and the parent dir if
  empty) and disables the `provision.template_dir` row; upstream then falls
  back to `/etc/fusionpbx/resources/templates/provision` or the in-tree dir
  (`provision.php:121-124`). Overlay state in `/var/lib` is kept. Device rows
  pointing at overlay-only templates will 404 until re-enabled.
- Uninstall: `--disable`, remove `app/x_provision_overlay`, remove the nginx
  location snippet, `upgrade.php --defaults --menu --permissions` to prune
  app rows, optionally delete `/var/lib/fusionpbx/provision_overlay`.

## 8. Risks and unverified items

- php-fpm hardening: I could not inspect Debian's `php8.4-fpm.service` here.
  Upstream's `update_php_fpm()` assumes `ProtectSystem`/`ReadWritePaths`
  semantics; if the unit is not hardened the `ReadWritePaths` line is
  harmless, if it is hardened our symlink swap under `/usr/share/fusionpbx`
  depends on upstream having added that path (it does on every `--defaults`).
  The installer re-checks writability after `--defaults` and fails loudly.
- APCu reload semantics (whether `systemctl reload php-fpm` clears the
  settings cache) not verified; the installer uses `restart`.
- nginx vhost edit: the asset location is inserted into the installer-owned
  `/etc/nginx/sites-available/fusionpbx` between marker comments
  (`# BEGIN x_provision_overlay` / `# END`). Upstream's `--defaults` also
  edits this file (websockets block, `upgrade.php:263-313`) using a string
  search, so our block must not contain `/websockets/` or
  `#redirect letsencrypt to dehydrated`.
- Front-controller routing of `/app/x_provision_overlay/*` on port 443 goes
  through `location ~ \.php$` (direct PHP), so admin pages work; do not rely
  on the router's fallthrough rule for phone URLs (2.5).
- `write()` (`provision.path` pre-rendering) and the "Files" download list
  ignore the domain subdir (2.2): devices in a domain with overrides get
  files pre-written from the global merged tree, not the domain tree. This
  is an upstream limitation; document it and recommend `provision.path`
  stay unset (its default is empty and disabled, `app_config.php:117-120`).
- The device template dropdown lists directories only; overlay-only vendors
  need a `v_device_vendors` row (UI warns).
- Upstream template renames leave device rows pointing at dead names until
  an admin acts (3.3).
- Domain names with characters outside `[a-z0-9.-]` cannot have domain
  subdirs (refused; global overrides still apply).
- Template editing is RCE-equivalent for whoever holds the edit permissions
  (section 5). Logged with username, domain, path and sha256 of content to
  syslog `LOG_LOCAL0` like `app/provision`.
- Disk: each domain with overrides costs roughly 1040 symlinks; 500 such
  domains is about 520k inodes. Acceptable; noted.
- Build runs as www-data from php-fpm: a long build (many domains) is done
  synchronously on save; if it exceeds a few seconds, move to the timer with
  a "pending" flag. Not measured.
- Concurrency with `upgrade.php --main`: the build reads upstream files
  mid-pull at worst; the post-upgrade build fixes it.

## 9. Installer contract

Packages (Debian trixie/bookworm, already present from upstream installer
unless noted): `php8.4-fpm`, `php8.4-cli`, `php8.4-gd` (`getimagesize` on
all accepted types; upstream installs it, `php.sh:139`), `php8.4-apcu` if
upstream installs it (for cache behaviour parity), `file` (libmagic for
`finfo`, usually present), `rsync` (not required; builds use PHP only).

Files and directories created by `install.sh` (idempotent; `upgrade.sh`
re-asserts ownership/modes but never rewrites overlay content or settings)

| Path | Owner | Mode | Notes |
|---|---|---|---|
| `/var/www/fusionpbx/app/x_provision_overlay/` | www-data:www-data | 0750 dirs / 0640 files | Copied from this repo (`apps/x_provision_overlay/`), not a git clone, so `--optional` ignores it. Re-copied on every `upgrade.sh`. |
| `/var/lib/fusionpbx/provision_overlay/` | www-data:www-data | 0750 | state root |
| `.../global/`, `.../domains/`, `.../assets/`, `.../merged/` | www-data:www-data | 0750 | created empty |
| `.../.lock` | www-data:www-data | 0640 | flock |
| `/usr/share/fusionpbx/templates/` | www-data:www-data | 0755 | parent of the link |
| `/usr/share/fusionpbx/templates/provision` | symlink | - | `-> /var/lib/fusionpbx/provision_overlay/merged/current` |
| `/var/lib/fusionpbx-install/state/provision_enabled.done`, `template_dir.done` | root:root | 0644 | first-install markers |
| `/etc/systemd/system/x-provision-overlay.service` | root:root | 0644 | `Type=oneshot`, `User=www-data`, `ExecStart=/usr/bin/php /var/www/fusionpbx/app/x_provision_overlay/resources/cli/build.php --build` |
| `/etc/systemd/system/x-provision-overlay.timer` | root:root | 0644 | `OnCalendar=daily`, `RandomizedDelaySec=1h`, `Persistent=true` |
| `/etc/nginx/sites-available/fusionpbx` | root:root | 0644 | marker-delimited `location ^~ /provision-assets/` block inserted into both the `:80` and `:443` server blocks, fastcgi to `/var/run/php/php8.4-fpm.sock` with `SCRIPT_FILENAME /var/www/fusionpbx/app/x_provision_overlay/assets.php`, `SCRIPT_NAME /app/x_provision_overlay/assets.php`; `nginx -t` before reload |

Order of operations in `install.sh` (after upstream `finish.sh` completes)

1. Copy app dir, chown, chmod.
2. Create `/var/lib/fusionpbx/provision_overlay` tree and `/usr/share/fusionpbx/templates`.
3. `runuser -u www-data -- php /var/www/fusionpbx/app/x_provision_overlay/resources/cli/build.php --build` (creates `merged/<id>` and `current`).
4. `ln -sfn /var/lib/fusionpbx/provision_overlay/merged/current /usr/share/fusionpbx/templates/provision` (if the path exists and is not our symlink: abort with message).
5. `cd /var/www/fusionpbx && php core/upgrade/upgrade.php --defaults` (registers app, default settings, re-asserts php-fpm `ReadWritePaths` including `/usr/share/fusionpbx`), then `--menu`, `--permissions`.
6. Enable provisioning (3.5) and set `provision.template_dir` (6), each guarded by its marker, via `psql` as in `finish.sh`.
7. Insert nginx block, `nginx -t && systemctl reload nginx`.
8. Install service + timer, `systemctl daemon-reload && systemctl enable --now x-provision-overlay.timer`.
9. `systemctl restart php8.4-fpm`.
10. `build.php --verify` and print the summary.

`upgrade.sh` hooks (run after `upgrade.php --main` / `--optional` /
`--defaults` / `--menu` / `--permissions`)

1. Re-copy app dir (overwrite), chown/chmod.
2. Re-assert ownership of `/var/lib/fusionpbx/provision_overlay` and the symlink.
3. `runuser -u www-data -- php .../build.php --build` then `--verify`; print orphaned overrides.
4. Re-insert nginx block only if the markers are missing; reload nginx if changed.
5. Reinstall unit files if changed; `daemon-reload`.
6. Never touch `provision.enabled`, `provision.template_dir`, overlay files or assets.

CLI commands provided by the app

```
php build.php --build        # regenerate merged tree, swap current
php build.php --status       # current build id, upstream commit, counts
php build.php --verify       # dangling links, orphaned overrides, bad domain names; exit 1 on problems
php build.php --rollback     # point current at previous build
php build.php --disable      # remove symlink, disable template_dir setting
```
