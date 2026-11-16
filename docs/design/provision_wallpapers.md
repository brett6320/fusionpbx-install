# Phone wallpapers (`x_provision_overlay` enhancement)

Status: design, 2026-11-16. Extends the branding assets of
[provision_overlay.md](provision_overlay.md) (section 3.4). Upstream refs are
to the commit pinned in `config.sh` (`58ca4e9`).

## 1. Goal

- One wallpaper per **size group** (phones that take the same image), per
  domain. Admins upload only for the groups they want.
- If a domain has no image for a group, the **system default** for that group
  (uploaded by a superadmin) is used; with none, nothing is written and the
  template behaves as upstream ships it (factory wallpaper for most models;
  Yealink templates that always emit `wallpaper_upload.url` keep doing so).
- Values set elsewhere win: see 3.1.
- Uploads are **auto-fitted**: any PNG/JPEG is scaled and center-cropped to
  the group's size and re-encoded in each member's format and size limit. The
  page shows each group's requirements and a preview.
- First version: phones whose upstream templates already take a wallpaper URL
  from a setting. Phones that need a template override (Cisco, Fanvil, Htek,
  most Polycom, Digium, the per-model Grandstream GRP2612-2616 templates)
  come later, after testing on hardware. The shared `grandstream/grp261x`
  template was added after the first version (2.1).

## 2. Size groups (first version)

From the research in the PR. Format = what is generated for that model.

| Group | Models (device template) | Format, limit |
|---|---|---|
| `320x240` | yealink/t32g t33g t34w t44w t52s t73u t73w; snom/D735 D765; grandstream/gxp2130 gxp2135 grp261x (GRP2612/2612W/2613/2614/2616, 2.1); avaya/J139 J179 | Yealink, Grandstream JPEG ≤500 KB; Snom PNG ≤500 KB; Avaya JPEG ≤256 KB |
| `480x272` | yealink/t29g t38g t46g t46s t46u t54s t54w t74u t74w; snom/D375 D385 D785; grandstream/gxp2140 gxp2160 gxp2170 grp261x (GRP2615, 2.1); polycom/vvx450; poly/poly-vvx-450 | JPEG (Grandstream ≤500 KB); Snom PNG ≤500 KB |
| `800x480` | yealink/t48g t48s t48u t57w t77u t87w vp530 cp925; avaya/J189 | JPEG (Avaya ≤256 KB) |
| `1024x600` | yealink/t56a t58a t58v t58w t88v t88w | JPEG ≤1 MB |
| `960x480` | yealink/t85w | JPEG |
| `1280x720` | yealink/cp960 cp965; snom/D862 D865 | JPEG; Snom PNG |
| `1280x800` | yealink/t49g; grandstream/gxv3480 | JPEG |
| `240x320` | yealink/ax83h ax86r; grandstream/wp820 | JPEG |

All JPEG output is baseline (not progressive); PNG output is capped like JPEG
(palettized to 256, 128, then 64 colors; refused if still too large). Where no
limit is listed, 1 MB is used. A group generates one file per format, at the
strictest limit of its members of that format.

Left out of the first version:

- mixed templates, where the template does not tell the model: `yealink/t2x`,
  `t4x`, `t5x`, `polycom/5.x`, `6.x`, `grandstream/grp26xx`
  (GRP2615 is 480x272, the others 320x240). A later version can resolve the
  model from `v_devices.device_provisioned_agent` (as 2.1 does for
  `grp261x`);
- conflicting or unverified specs: snom/D717 (426x240 vs 320x240), D812/D815
  (template says 1280x720, datasheets 480x272), polycom/ccx500, sangoma/s500
  and s700 (the template hard-codes `Preference.WallPaper=5`, unverified);
- the Avaya J189 secondary screen.

### 2.1 Grandstream GRP261x

At the pin only the shared `grandstream/grp261x` template prints
`{$grandstream_wallpaper_url}` (`lcd.wallpaper.serverPath`). The per-model
`grp2612`, `grp2612w`, `grp2613`, `grp2614`, `grp2615` and `grp2616` templates
test it but write an empty `lcd.wallpaper.serverPath`, so they stay out until
a template override fills it.

`grp261x` serves models of two sizes: GRP2615 is 480x272, the others
320x240. Its catalog entry is in the 320x240 group and lists
`'models' => ['GRP2615' => '480x272']`: a device whose `device_model` or
`device_provisioned_agent` names GRP2615 gets the 480x272 group. Each device
gets its own value of the same variable, so nothing else changes. A GRP2615
that has never provisioned and has no model set gets the 320x240 image at its
first fetch (`provision.php` records the user agent while rendering); the next
sync updates its row and the next fetch corrects it. Sizes and limits as the
GXP21xx; not tested on hardware.

## 3. Linking phones to their group: device settings

Upstream templates read the wallpaper URL from a provision variable. Some are
per model (`yealink_t46s_wallpaper`, `snom_wallpaper_url_d785`,
`avaya_j179_wallpaper_url`), some per vendor (`grandstream_wallpaper_url`,
`polycom_background_url`, `sangoma_wallpaper`) although that vendor's models
differ in size, and several templates read another model's variable (t73w and
t74w read `yealink_t54w_*`, t58a reads `t56a`, ax86r reads `ax83h`, cp925 reads
`cp920`, D865 reads `d862`). Upstream's `provision.php` (826-841) lets a
device's own settings (`v_device_settings`) override default and domain
settings for that device.

So the link is made **per device**: for each enabled device whose
`device_template` is in the catalog, the variables that template reads are
written as device settings, valued with the URL of the image for the device's
group (domain image, else system default). This handles per-vendor variables
and the upstream variable mix-ups without template overrides.

Each catalog entry lists the template's **whole variable set**, written or
skipped together:

- the URL variable(s) the template reads (with the upstream mix-ups:
  t73w/t74w use `yealink_t54w_*`, t58a `t56a`, ax86r `ax83h`, cp925 `cp920`,
  D865 `d862`);
- the file name variable where the template emits one, which must equal the
  URL's file name: Yealink `*_wallpaper_filename`, Avaya
  `avaya_*_wallpaper_filename`;
- variables a template tests by another name: `avaya_j179_wallpaper` (J179
  checks it instead of `_url`), `yealink_t44w_wallpaper_filename` for
  ax83h/ax86r (they test it but print the ax83h one).

Devices with a `device_uuid_alternate` are skipped (`provision.php` 768-806 may
render them with another device's template and settings).

### 3.1 Precedence

`provision.php` merges default and domain settings, then the device profile's
(812-823), then the device's own (826-841); device rows win. A managed device
row would therefore hide values set elsewhere. Rule: **a managed value is
written only where the device's effective value for every variable of its set
would otherwise come from default settings or nothing.** If any variable of
the set has an enabled domain setting, device profile setting or unmanaged
device setting, the device is left alone and the page lists it as "uses its
own wallpaper (domain setting / profile X / device)". The domain admin can
remove that value to switch the device to the managed one.

The asset page's "apply to vendor settings" (`apply_vendor_setting`) no longer
offers wallpaper variables (`polycom_background_url`,
`grandstream_wallpaper_url`, `poly_obi_logo_*`, `yealink_*_wallpaper`); domain
settings it wrote earlier stay and, by the rule above, win until removed.

### 3.2 Managed rows

Managed rows are recorded in a table of this app,
`v_x_provision_overlay_wallpaper_links` (domain, device, device_setting_uuid,
subcategory, the value written, group, image). A row is ours only while its
value still equals what we wrote:

- value changed by an admin: the row becomes theirs; the link is dropped and
  the device counts as "own wallpaper";
- row disabled by an admin: opt-out; left alone (documented as the way to opt
  a device out);
- row deleted by an admin: the link is dropped; the next sync writes it again
  (deleting is not an opt-out, disabling is);
- device moved to another domain, or variable no longer in the catalog: the
  row is deleted while it still holds the value written (and is enabled), then
  the link is dropped. Links are selected by domain and by the domain's
  current devices.

The DELETE and UPDATE of a managed row are guarded by `device_setting_value =
<written> and device_setting_enabled = true`, so a row an admin disabled
between analysis and write is left as an opt-out.

Snom D735, D765, D785, D862 and D865 read a model-specific variable we write
and fall back to `snom_wallpaper_url`. A domain `snom_wallpaper_url` is counted
as "own" for them conservatively, so the "own" label may be wrong for these
models (the template may in fact prefer our value).

`provision.php` keys device settings by subcategory only, so competing rows
are matched by subcategory, any category. Duplicate managed rows for one
device and subcategory are collapsed.

### 3.3 Template check

The catalog is pinned to the upstream commit, but upgrades can change
templates, and overlay overrides can replace them. At sync, for each catalog
template, the merged template file the domain uses (its domain override dir
when present) must still reference every variable of the set; the result is
cached per overlay build id. If not, the template is treated as unsupported:
its managed rows are removed and the page shows a warning.

### 3.4 Sync

- Computes the desired rows from the stored image metadata (never from
  existing rows), diffs them against the links, and writes only the changes:
  a few set-based queries per domain (devices on catalog templates, links,
  competing rows), inserts/updates/deletes in one batch, without upstream's
  transaction log for unchanged rows.
- Holds the overlay's lock.
- Runs for one domain inside the request after a domain upload or removal;
  after a system default change the page writes the request file
  `/var/lib/fusionpbx/provision_overlay/.wallpapers-sync.request`, and
  `x-provision-wallpapers.path` starts `x-provision-wallpapers.service` (all
  domains), so the request returns at once. (php-fpm runs as www-data, which
  cannot start units without sudo or polkit; the CLI removes the request file
  first, so a failing run is not restarted in a loop.)
- `x-provision-wallpapers.timer` runs it every 2 minutes (www-data, hardening
  as the build unit) to cover devices added or changed in Devices. Phones that
  only fetch their config at boot pick a wallpaper up at their next fetch; the
  page says so.

## 4. Images

- Upload: PNG or JPEG, validated as today's assets (finfo, getimagesize, size
  cap `asset_max_bytes`, 4096 px), decoded with GD.
- Fit: apply the EXIF orientation, scale to cover the group's size and
  center-crop; warn when the source is smaller than the group (upscaled).
  Variants per group: baseline JPEG at the strictest member limit (quality
  stepped down from 90; refused if quality 40 still doesn't fit), and PNG when
  a member needs it (palettized when needed to fit its cap). No transparency
  (flattened on white). The source is limited to 4096 px (about 64 MB decoded
  in GD); the page checks `memory_limit`.
- Stored as `wp-<group>-<sha8>.<ext>` (content hash, so a new image gets a new
  URL and phones that cache by name download it again): domain images under
  `assets/<domain_uuid>/`, system defaults under a new `assets/global/`, each
  recorded in its own `.wallpapers.json` (group, file, variants, size, upload
  time). The `wp-` prefix is reserved: ordinary asset uploads can't use it.
  Wallpapers don't count toward the 50-asset limit and are not listed with the
  other assets.
- Only names of the form `wp-<group>-<hash8>.<jpg|png>` are wallpapers;
  other `wp-*` assets (uploaded before this feature) stay ordinary assets.
  New asset uploads named `wp-*` are stored as `wp_*`.
- `assets.php` serves a wallpaper name only if it is recorded in the domain's
  `.wallpapers.json`, else in the global one, through its own path helper with
  the realpath check, after today's checks (domain exists, enabled,
  provisioning on). Content-hashed names may be cached for a day.
- Order: write the file, then the settings; a replaced or removed file is
  deleted only after a grace period (a day, by the daily overlay job), so
  phones that already have the config can still download it. Removing an
  image deletes the settings first.
- The cleanup deletes a retired file only if no link and no enabled device,
  device profile, domain or default setting still contains its name (copied
  devices, pasted URLs, failed syncs). Without a database, or if that check
  fails, it deletes nothing. `wp-` files no metadata records get a first-seen
  time in `unrecorded` and are handled like retired files from then (never by
  mtime). Uploads (`.upload-*`) and temp files (`.*.tmp`) older than an hour
  are removed (a request killed by an out-of-memory error leaves them).
- An unreadable `.wallpapers.json` (invalid json, unreadable, symlink) stops
  that scope: no sync of the domain (of every domain for the global one), no
  cleanup there, no upload or remove. The page shows a generic error and logs
  the detail.
- The memory check counts a second copy of the source when the EXIF
  orientation needs a rotation or flip.
- Yealink keeps downloaded wallpapers in its own storage (the template asks to
  keep 2 MB free); removing an image here does not delete it from the phone,
  and each new image adds a file. Documented.
- URL written to settings: `http://{$domain_name}/provision-assets/<file>`
  (substituted by `provision.php` for device settings too).

## 5. UI

New **Wallpapers** page in Provision Templates (link from the assets page):

- One card per group: size, member models (the domain's devices of those
  models marked), formats and limits, current image preview (domain or "system
  default" or "factory"), Upload / Remove.
- Superadmins get a second tab for the system defaults.
- Counts: how many devices of the domain get this group's image.

Permissions: `x_provision_overlay_asset_edit` (admin, superadmin) for the
domain's wallpapers; new `x_provision_overlay_wallpaper_global` (superadmin)
for system defaults.

## 6. Code

- `resources/classes/provision_wallpapers.php`: catalog (template => group,
  format, limit, variables), fit/encode, store/remove, sync.
- `x_provision_overlay_wallpapers.php`: the page.
- `resources/cli/wallpapers.php --sync [--domain <uuid>]` and `--cleanup`;
  systemd `x-provision-wallpapers.{service,timer,path}` (hardening as the
  build unit); `x-provision-overlay.service` runs `--cleanup` before the
  daily build.
- `assets.php`: global fallback for `wp-*`.
- Backups: `assets/global/` is under `/var/lib/fusionpbx`, already in bundles;
  the links table is in the database. After a restore the sync rebuilds the
  rows from the image metadata.
- Domain delete: the daily overlay job records when it first sees
  `assets/<uuid>` without a domain (`assets/.wallpapers-missing-domains.json`)
  and removes the folder (it also holds branding assets) a week later; a
  domain that reappears is dropped from the list.
- `sync_all` takes the overlay lock once for the run, waiting at most 5 s; if
  another change holds it the run ends (exit 0) and the next timer run retries.
  One domain failing is logged; the unit fails only when every domain failed
  or the run itself failed.
- `provision_overlay.md`: correct 2.3 (Yealink templates do read
  `yealink_<model>_wallpaper`), and 3.4 (apply to vendor settings no longer
  covers wallpapers).

## 7. Risks

- Specs are from vendor docs and third-party tables (confidence recorded per
  model in the research); untested on hardware.
- Writing device settings shows rows in Devices > device settings; they are
  labelled and an admin's own value is respected.
- A phone that fails to download a wallpaper keeps or blanks its wallpaper
  depending on firmware; images are only linked once stored.
