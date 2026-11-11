#!/bin/bash
# Apply our layer on top of an installed FusionPBX. Idempotent; run by both
# install.sh and upgrade.sh.
#
# Usage: resources/apply.sh install|upgrade [--restore-menu]
set -euo pipefail
cd "$(dirname "$0")/.."
. ./config.sh
. ./resources/common.sh

mode=${1:-}
restore_menu=false
[ "${2:-}" = "--restore-menu" ] && restore_menu=true
case "$mode" in
	install|upgrade) ;;
	*) die "Usage: $0 install|upgrade [--restore-menu]" ;;
esac

[ -f "$fusionpbx_dir/core/upgrade/upgrade.php" ] || die "FusionPBX not found at $fusionpbx_dir."

#our workers must not run new app code against the old schema mid-update;
#they are re-enabled at the end
for unit in /etc/systemd/system/x-*.timer /etc/systemd/system/x-*.path; do
	[ -f "$unit" ] || continue
	systemctl stop "$(basename "$unit")" || true
done

#on upgrade, upgrade.sh installed these before moving core; a mirror failure here
#would leave new code on the old schema
if [ "$mode" = install ]; then
	info "Installing packages"
	apt-get update
	apt-get install -y rsync ffmpeg
fi

#copy our apps; --delete only inside each x_ app so upstream apps are never touched
info "Installing apps"
for app in apps/x_*/; do
	name=$(basename "$app")
	#units and root-run scripts are installed by hooks, never under the webroot
	rsync -a --delete --exclude 'systemd/' --exclude 'sbin/' "$app" "$fusionpbx_dir/app/$name/"
	chown -R www-data:www-data "$fusionpbx_dir/app/$name"
done

#app-specific host setup (users, keys, firewall sets, directories)
for hook in resources/apps/*.sh; do
	[ -f "$hook" ] || continue
	info "Running $(basename "$hook")"
	bash "$hook" "$mode"
done

#systemd units shipped by apps
info "Installing systemd units"
units_changed=false
for unit in apps/x_*/systemd/*.service apps/x_*/systemd/*.timer apps/x_*/systemd/*.path; do
	[ -f "$unit" ] || continue
	install -m 0644 -o root -g root "$unit" "/etc/systemd/system/$(basename "$unit")"
	units_changed=true
done
[ "$units_changed" = true ] && systemctl daemon-reload

bash resources/timesync.sh

info "Updating FusionPBX schema, defaults and permissions"
fusionpbx_upgrade --schema
fusionpbx_upgrade --defaults
fusionpbx_upgrade --permissions

#settings are cached in APCu with no TTL; app_defaults.php may have written some
systemctl restart "php${php_version}-fpm"

#restoring the menu deletes and rebuilds every menu item, losing admin edits
if [ "$mode" = install ] || [ "$restore_menu" = true ]; then
	info "Restoring the default menu"
	fusionpbx_upgrade --menu default
else
	warn "Menu not restored. If new app menu entries are missing, rerun with --restore-menu (this resets admin menu edits)."
fi

#enable timers and path units after the schema exists so the first run has its tables
for unit in apps/x_*/systemd/*.timer apps/x_*/systemd/*.path; do
	[ -f "$unit" ] || continue
	systemctl enable --now "$(basename "$unit")"
done

info "Layer applied ($mode)"
