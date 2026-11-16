# Conventions for Custom Apps

These rules apply to every app under `apps/`.

## Layout
- Each app is a standard FusionPBX app in `apps/x_<name>/`, installed to `/var/www/fusionpbx/app/x_<name>/`.
- The `x_` prefix keeps our apps from colliding with future upstream apps.
- Required files: `app_config.php` and `app_defaults.php`; apps with pages also have `app_languages.php`, `app_menu.php`, the pages and `resources/`.
- Model every file on an upstream app of similar shape (e.g. `app/call_block`, `app/ivr_menus`). Match upstream style: tab indentation, `//comment` section headers, `$apps[$x][...]` arrays.
- Generate UUIDs once (`uuidgen | tr A-Z a-z`) and never change them.
- Add `en-us` strings only in `app_languages.php` (other languages fall back).

## Upstream
- Never edit files outside our app directories. Never patch upstream core.
- Reference: upstream fusionpbx/fusionpbx at the commit pinned in `config.sh` (`fusionpbx_branch`, `fusionpbx_commit`), checked out read-only.

## Security
- Every page: `require_once dirname(__DIR__, 2) . "/resources/require.php";`, `resources/check_auth.php`, and a `permission_exists()` check. The only exception is a phone-facing endpoint that sets `$no_session` and does its own checks (`x_provision_overlay/assets.php`).
- Every state-changing request: validate the upstream CSRF token (`$token = new token;` / `$token->validate($_SERVER['PHP_SELF'])`, as upstream edit pages do).
- Every query in a domain-scoped app filters on `domain_uuid = $_SESSION['domain_uuid']` (or the `$domain_uuid` upstream pattern) and uses bound parameters via the `database` class.
- Escape output with `escape()`.
- Uploads: detect type from file content (`finfo`), never the client type or extension; enforce a size cap; re-encode server-side (images via GD, audio via ffmpeg); store under domain-scoped paths outside the webroot; no SVG.
- Shell commands: `escapeshellarg()` on every argument, or `proc_open` with an argv array.
- Event socket commands go through upstream's `event_socket` class, as upstream apps do.

## Defaults
- `app_defaults.php` runs on every `upgrade.php --defaults`. Never overwrite a value an admin may have changed. Apply one-time changes only behind a first-install marker (a default setting row such as `x_install.<app>_initialized`).

## Workers
- Background jobs are PHP CLI scripts in `resources/` run by systemd timers, as `www-data` unless a design doc says otherwise. Each worker takes a lock (`flock`) so runs never overlap.
- Put systemd units for an app in `apps/x_<name>/systemd/`, and scripts that run as root in `apps/x_<name>/sbin/`. Both are left out of the webroot copy. `resources/apply.sh` installs the units; the app's hook in `resources/apps/` installs `sbin/` and any extras (drop-ins, modules-load, tmpfiles).

## Verification
- PHP cannot be run on the dev machine. Code is linted later in a container. Write carefully and keep PHP 8.4-compatible.
