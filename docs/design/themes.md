# Light and Dark themes (`x_local_defaults`)

Status: built, 2026-11-16. Upstream refs are to the commit pinned in
`config.sh` (`58ca4e9`).

## 1. Goal

- Two themes, **Light** and **Dark** (theme
  categories `Light` and `Dark`). Flat and modern: neutral surfaces and one
  blue accent, no gradients, no background images. Themes are managed at
  `/core/themes/themes.php` (superadmin; upstream has no menu item for it, the
  **Themes** button on the Global Profile page, Home > Global Profile, opens it).
- **Dark is the server default**, set once, replacing the upstream Cobalt Blue
  that earlier versions of this installer selected.
- Admins keep control: a theme they chose, and their edits to these themes,
  are never overwritten.

## 2. How themes work upstream

- `core/themes/app_defaults.php` seeds `v_themes` and `v_theme_settings` with
  fixed uuids, skipping any uuid that already exists, and saves with the temporary
  permissions `theme_add` and `theme_setting_add`. Upstream ships Cobalt Blue
  (`eda762b3-…`, Dark, 184 settings) and Blue Gradient (`7ca4a961-…`, Light, 238).
- The theme in use is the setting **domain > theme > uuid** (default setting
  `b1a99e57-8d4a-4bbf-910f-1f5a86d6532a`; domains and users can set their own).
  `resources/classes/settings.php` `theme_settings()` loads the enabled rows
  of that theme over the `theme` default settings. A setting the theme leaves
  out falls back to the default settings, then to the fallback in
  `themes/default/css.php`.
- A theme setting of type `array` with two values renders as a gradient
  (`background_color`, `login_background_color`, `dashboard_background_color`,
  `dashboard_detail_background_color`); one value renders flat.

## 3. What is built

- `apps/x_local_defaults/resources/themes.php`: both themes, every setting with a
  fixed uuid. Each theme sets **253 settings** (260 rows: the CPU, disk and
  network chart colors and the domain list rows are arrays of several values): the
  union of Cobalt Blue's and Blue Gradient's setting names, and also every other color, shadow or
  background setting `css.php`, `template.php` and the apps read
  (`body_header_brand_text_color`, `menu_brand_text_color`, `action_bar_*`,
  `modal_shadow`, `card_shadow_size`, `dashboard_network_usage_chart_main_color`),
  and `background_color_enabled`/`login_background_color_enabled` true,
  `background_image_enabled`/`login_background_image_enabled` false. So no
  upstream default color leaks in. Left unset: the image and video paths, the
  background blur and the gradient angle and style (unused with one color).
  Cobalt Blue's misspelled `input_background_color ` (trailing space) is set under
  its real name.
- `apps/x_local_defaults/app_defaults.php` (runs on install and every
  upgrade, first domain only):
  1. Adds the themes and their settings like upstream: only uuids not already
     in the database. Edited values are kept. A setting or a whole theme an
     admin deleted comes back on the next upgrade, as with upstream's themes
     (disable it instead).
  2. Once, recorded with the marker `x_install > theme_dark_initialized`:
     creates upstream's `domain > theme > uuid` row if missing, then sets it
     to Dark if it is empty, or if it is Cobalt Blue, enabled, and the
     earlier marker `x_install > theme_initialized` shows this installer set
     it. Any other value (Blue Gradient, a custom theme, Cobalt Blue chosen by
     an admin on an install without the earlier marker) is kept.

uuids: Dark `56c90fe5-8216-47e9-a846-1c72c5f19528`, Light
`e0409c11-bdba-47e0-b12a-59c575b7a980`.

## 4. Palette

Same accent family in both themes; links are lighter on dark surfaces.

| Role | Light | Dark |
|---|---|---|
| Page background | `#f3f4f6` | `#111827` |
| Content, cards, header, inputs | `#ffffff` | `#1f2937` (inputs `#111827`) |
| Alternate rows | `#f9fafb` | `#243041` |
| Form labels, dashboard counters | `#f3f4f6` | `#273244` |
| Main menu / sub menu | `#ffffff` / `#ffffff` | `#0b1120` / `#111827` |
| Borders / strong borders (table headings) | `#e5e7eb` / `#d1d5db` | `#374151` / `#4b5563` |
| Input borders, toggle off | `#858b95`, `#6b7280` | `#6b7280` |
| Headings | `#111827` | `#f3f4f6` |
| Body text | `#374151` | `#e5e7eb` |
| Muted text (table headings, descriptions) | `#4b5563` | `#d1d5db` |
| Placeholder, icons | `#6b7280` | `#9ca3af` |
| Links / hover | `#1d4ed8` / `#1e40af` | `#60a5fa` / `#93c5fd` |
| Buttons, active menu, heading counts (white text) | `#2563eb`, hover `#1d4ed8` | same |
| Focus ring, toggle on, charts | `#3b82f6` | same |
| Success / error / warning | `#16a34a` / `#dc2626` / `#f59e0b` | same |
| Messages (positive, negative, alert) | `#dcfce7`/`#166534`, `#fee2e2`/`#991b1b`, `#fef3c7`/`#92400e` | `#14532d`/`#dcfce7`, `#7f1d1d`/`#fee2e2`, `#78350f`/`#fef3c7` |

### Contrast (WCAG 2.1 AA)

Checked for 65 foreground/background pairs per theme: every text pair is at
least 4.5:1 and every non-text pair (icons, input borders, focus ring, toggle
track and handle, required-field border) at least 3:1. Lowest text pairs: Light placeholder on input 4.83:1,
white on the accent (buttons, counters, active menu) 5.17:1 in both. Lowest
non-text: Dark input border on a form field cell and toggle off 3.04:1, Light
input border 3.43:1, Dark required-field border 3.51:1. Divider lines
(table rows, cards) are decorative and lighter; inputs carry their own border. Status colors (green/red/orange dots and
chart segments) are not text and carry meaning by icon or label as well.

## 5. Not covered

- Blue Gradient and Cobalt Blue are unchanged and still selectable.
- Third-party apps that hard-code colors outside theme settings.
- Fonts, menu style and other non-color theme settings keep their defaults.
