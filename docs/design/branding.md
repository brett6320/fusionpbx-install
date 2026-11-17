# Web interface branding (`x_provision_overlay` enhancement)

Status: design, 2026-11-16. Replaces the phone branding assets page of
[provision_overlay.md](provision_overlay.md) (section 3.4). Upstream refs are
to the commit pinned in `config.sh` (`58ca4e9`).

## 1. Goal

- Brand the FusionPBX **web interface**: menu logo, collapsed side menu icon,
  login logo and its size, favicon, page title, menu brand text and menu
  brand type. No colors (those belong to upstream's Themes app).
- A **system default** set by a superadmin, and a **per-domain override** set
  by the domain's admins. An item a domain leaves empty uses the system
  default; with none, upstream's default applies.
- Values set by hand win (3.3): the page never overwrites a setting it did not
  write.
- The old phone branding assets page (upload any image, "apply to vendor
  settings", template snippet) is removed. Phone wallpapers are unchanged
  ([provision_wallpapers.md](provision_wallpapers.md)). Images the old page
  stored keep being served to phones, read only (section 6).

## 2. What upstream reads

All are theme settings (category `theme`, type `text`). Load order
(`resources/classes/settings.php` `reload()`): default settings, domain
settings (with parent domains), user settings, then the domain's assigned
Theme (`domain > theme`, core/themes), then device profile and device. So a
domain setting overrides the default setting of the same name, and a user's
own theme setting or an assigned Theme override both.

| Item | Settings written | Read by |
|---|---|---|
| Menu logo | `menu_brand_image`, `menu_side_brand_image_expanded` | `resources/classes/menu.php` horizontal menu (`menu_brand_image`, default `/themes/default/images/logo.png`) and expanded side menu |
| Side menu icon | `menu_side_brand_image_contracted` | `menu.php`, collapsed side menu (upstream seeds it disabled) |
| Login logo | `logo`, `logo_login` | the login views of the authentication plugins (`core/authentication/resources/classes/plugins/*.php`: `logo`, default `logo_login.png`), `resources/footer.php` (`logo_login`, else `logo`; also the inline menu style) |
| Favicon | `favicon` | `footer.php` and the login views; must look like a path with an extension |
| Page title | `title` | `footer.php` document title. Upstream seeds an enabled default setting `title = FusionPBX` |
| Menu brand text | `menu_brand_text` | `menu.php` (default `FusionPBX`) |
| Menu brand | `menu_brand_type` | `menu.php`: `image` (default), `text`, `image_text`, `none` |
| Login logo width, height | `login_logo_width`, `login_logo_height` | the login views, written into a `style` attribute (defaults `auto; max-width: 300px` / `auto; max-height: 300px`) |

Each item's settings are written or skipped together.

Not offered:

- **Login brand text.** `login_brand_type`, `login_brand_image` and
  `login_brand_text` are read by the authentication plugins but never passed
  to the login views at the pin, so they show nothing.
- **QR brand** (`qr_brand_*`, core/contacts): not web interface branding.
- **Colors** and everything else a Theme sets.

### 2.1 Which domain the login page uses

`core/authentication/resources/classes/authentication.php` `get_domain()`
takes the domain from `HTTP_HOST` (or the `@domain` of the username), and the
login view settings are built for that domain. So a domain's branding shows on
the login page only when the site is opened with the domain's name; any other
host name shows the system default.

## 3. Settings

### 3.1 Scope

| Tab | Written as | Permission |
|---|---|---|
| This Domain (session domain) | domain settings of the domain | `x_provision_overlay_branding_edit` (admin, superadmin) |
| System Default | default settings | `x_provision_overlay_branding_global` (superadmin) |

`x_provision_overlay_branding_view` (admin, superadmin) shows the page and
its menu item.

### 3.2 Values

- Images: the value is a path, `/branding-assets/<scope>/<file>` (section 5),
  so it works on any host name the page is opened with.
- Title and menu brand text: up to 64 characters, no `< > " ' & \` `` ` `` or
  control characters. Upstream prints some of these values without escaping
  (e.g. the menu brand text), so markup characters are refused.
- Logo size: `auto` or a number with `px`, `%`, `em`, `rem`, `vw` or `vh`.
  The value lands in a `style` attribute.
- Menu brand: one of the four types.

An empty text field removes the item.

### 3.3 Managed rows

Rows written are recorded in `v_x_provision_overlay_branding_links`
(domain_uuid, empty for the system; setting_uuid; subcategory; value written).
A row is ours only while its value equals what we wrote, as for wallpapers:

- value changed or row deleted by an admin: the link is dropped; the row is
  theirs.
- row disabled by an admin: opt-out; left alone, the item shows "not applied".
- an enabled row of the same subcategory in the same scope that is not ours
  (set by hand under Default or Domain Settings): **own value, wins**; we
  write nothing for the whole item and the page names the setting. The admin
  can change it there, or press **Take Over**, which disables that row (kept,
  so enabling it again gives the old value back) and writes ours. This is
  needed once for the system title, because upstream seeds an enabled
  `theme > title = FusionPBX` default setting.

Writes run in one transaction. Updates and deletes are guarded by the value
written and `enabled = true`, so a row an admin changed meanwhile is left
alone. Removing an image or a text deletes our rows. After writing,
`settings::clear_cache()` drops upstream's APCu settings cache
(`settings_global_*`, `settings_domain_*`), as upstream's
`default_setting_edit.php` does.

## 4. Images

- Accepted: PNG, JPEG, GIF, WebP for logos; ICO or PNG for the favicon. The
  type is sniffed with `finfo` and confirmed with `getimagesize`; the client
  type and the extension are ignored; the extension is set from the type.
  **SVG is refused**: it can carry script and would be served from the PBX's
  own origin.
- Limits: `x_provision_overlay.asset_max_bytes` (default 2 MiB), 4096 x 4096
  pixels; the favicon at most 256 KiB and 512 x 512.
- Stored as uploaded (no re-encoding, so GIF animation and the sizes inside an
  ICO are kept). Safe because the type is verified, the file is served with
  the recorded type, `nosniff` and `Content-Security-Policy: default-src
  'none'`.
- Named by content: `br-<item>-<sha256:12>.<ext>`, under
  `/var/lib/fusionpbx/provision_overlay/branding/<global|domain_uuid>/`
  (www-data, 0750/0640), with `.branding.json` (images: file, type, size,
  dimensions, sha256, uploader; values; retired files). A new image gets a new
  name and URL, so browsers can cache an image for a long time and still see a
  change at once. The previous file is kept a day (pages already rendered
  still load it), then deleted at the next change of that scope.
- Changes of all scopes are serialized by a flock on `branding/.branding.lock`.

## 5. Serving

`https://<host>/branding-assets/<scope>/<file>`, scope `global` or a domain
uuid. The scope is in the URL because the value must not depend on the host
name: a superadmin may view a domain under another host.

- nginx: `location ^~ /branding-assets/` in the installer's marker block of
  every :80/:443 server block (`resources/apps/provision_overlay.sh`),
  `limit_except GET`, fastcgi to `app/x_provision_overlay/branding.php`. On
  :80 the location answers with a 301 to https (`if ($scheme = http)`), like
  the rest of the UI; upstream's :80 block would otherwise not redirect it.
- `branding.php`: `$no_session = true` (the login page needs the images before
  sign-in; they are non-sensitive); GET/HEAD only; the path must match
  `^/branding-assets/(global|<uuid>)/<name>$`; a domain scope only while the
  domain exists and is enabled; the file must be recorded in the scope's
  `.branding.json` (current, or retired less than a day ago) and realpath
  inside the scope directory. The path never comes from the request.
- Headers: the recorded `Content-Type`, `Content-Length`,
  `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src
  'none'`, `Cache-Control: public, max-age=2592000, immutable`,
  `Last-Modified`. Anything else is a 404.

## 6. Upgrade from the phone branding assets page

- `apply.sh` copies the app with `rsync --delete`, so
  `x_provision_overlay_assets.php` disappears; the Wallpapers page's back
  button now goes to Provision Templates.
- **Images the old page stored** (`assets/<domain_uuid>/` listed in
  `.meta.json`) are kept and still served to phones at
  `http(s)://<domain_name>/provision-assets/<file>` by `assets.php`, read only.
  No new ones can be uploaded. The domain tab of the Branding page lists them
  with their URLs. To remove one, delete it from that directory and from
  `.meta.json` on the server.
- **Domain settings the old "apply to vendor settings" wrote** stay as they
  are; change or delete them under Domain Settings.
- The permission `x_provision_overlay_asset_edit` stays: the Wallpapers page
  uses it for domain wallpapers.
- The **menu item** Branding (under the parent of Provision Templates) is
  added by a fresh install. On an upgrade upstream does not restore menus;
  run `upgrade.sh --restore-menu`, or add it under Menu Manager. The page is
  also reachable from the Branding button on Provision Templates.
- The new table and permissions are created by `upgrade.php` as usual.

## 7. UI

`x_provision_overlay_branding.php`, menu Branding. Tabs This Domain and System
Default (superadmin). One card per image (preview, status, upload, remove,
take over) and one form for the texts, sizes and menu brand type. On the
domain tab an item without a value previews the system default, faded, and
shows its value. Status per item: set here, not set (system default /
FusionPBX default), not applied: own setting (names it; Take Over), not
applied: our row disabled.

## 8. Code

- `resources/classes/provision_branding.php`: items, validation, storage,
  `.branding.json`, `analyze()` (read only: item states and the plan),
  `apply()` (writes the plan), `take_over()`, syslog `LOG_LOCAL0`.
- `branding.php`: the image front (section 5).
- `x_provision_overlay_branding.php`: the page.
- `app_config.php`: the links table and the three permissions;
  `app_menu.php`: the menu item.
- `test/branding.php` (run by `test/checks.sh` as www-data): images stored
  through the class, refused types and texts, settings written, replaced and
  removed, domain override over the system default, own values kept, take
  over, opt-out, the seeded system title. `test/checks.sh`: the served files
  and their headers, 404s, the login page of a domain host and of another
  host, the old assets page gone and its images still served.

## 9. Risks

- **A Theme assigned to a domain that sets one of these settings, or a user's
  own theme setting, wins** over domain branding (load order, section 2). The
  page says so. The Themes upstream ships (including Cobalt Blue, the default
  this installer selects) set none of them.
- **Deleted domains**: their `branding/<domain_uuid>/` directory stays (the
  settings and link rows go with the domain); `branding.php` no longer serves
  it.
- **Login page host**: domain branding needs the domain's host name (2.1).
- **Upstream changes** of these setting names would make an item silently do
  nothing; the names are verified at the pin and must be checked when the pin
  moves.
- **Browser favicon cache**: browsers may show the old favicon for a while.
- **Take Over** disables a row an admin set; it is reversible by enabling the
  row again, and asks for confirmation.
