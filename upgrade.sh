#!/bin/bash
# Move FusionPBX core to the pinned release in config.sh and reapply our layer.
#
# Usage: ./upgrade.sh [--restore-menu]
#        ./upgrade.sh --optional
#   --restore-menu  Rebuild the default menu. Needed when an upgrade adds menu
#                   entries; resets any admin menu edits.
#   --optional      Only update the optional upstream apps (speech, transcribe, ...),
#                   which track their own branches, as www-data; core stays put.
set -euo pipefail
cd "$(dirname "$0")"
. ./config.sh
. ./resources/common.sh

case "${1:-}" in
	""|--restore-menu|--optional) ;;
	*) die "Usage: $0 [--restore-menu] | --optional" ;;
esac

require_root
require_debian

#optional upstream apps are separate git checkouts under app/, owned by www-data; git refuses to work in
#them as another user, and running it as root would leave root-owned files behind
if [ "${1:-}" = --optional ]; then
	[ -f "$fusionpbx_dir/core/upgrade/upgrade.php" ] || die "FusionPBX not found at $fusionpbx_dir."
	info "Updating optional upstream apps"
	for git_dir in "$fusionpbx_dir"/app/*/.git; do
		[ -d "$git_dir" ] || continue
		chown -R www-data:www-data "$(dirname "$git_dir")"
	done
	#upgrade.php reports a failed pull in its output but always exits 0
	out=$( (cd "$fusionpbx_dir" && runuser -u www-data -- php core/upgrade/upgrade.php --optional) 2>&1 ) || true
	printf '%s\n' "$out"
	#upstream's git_pull() adds each app to www-data's global safe.directory, which fails because www-data cannot
	#write /var/www/.gitconfig. www-data owns the checkouts, so git does not need it: ignore only that error
	if grep -vE "^error: could not lock config file /var/www/\.gitconfig: Permission denied$" <<<"$out" \
		| grep -qE 'ERROR:|fatal:|dubious ownership|Permission denied'; then
		die "An optional app did not update (see above). Nothing else was changed."
	fi
	fusionpbx_upgrade --schema
	fusionpbx_upgrade --defaults
	fusionpbx_upgrade --permissions
	systemctl restart "php${php_version}-fpm"
	info "Optional apps updated."
	exit 0
fi

[ -d "$fusionpbx_dir/.git" ] || die "$fusionpbx_dir is not a git checkout."

#refuse to move core if someone edited tracked upstream files
if [ -n "$(core_git status --porcelain --untracked-files=no)" ]; then
	die "Local changes to upstream FusionPBX files. Resolve them before upgrading."
fi

#preflight: anything that can fail runs before core moves, so a failure never
#leaves new code on the old schema
info "Preflight"
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends rsync ffmpeg chrony ipset ipset-persistent
php -m | grep -ix sodium >/dev/null || die "PHP sodium extension missing."
keyring=$fusionpbx_dir/app/x_carrier_credentials/resources/cli/x_carriers_keyring.php
if [ -f "$keyring" ]; then
	(cd "$fusionpbx_dir" && runuser -u www-data -- php "$keyring" check) || die "Carrier credential key check failed; fix it before upgrading."
fi

info "Moving FusionPBX core to $fusionpbx_commit ($fusionpbx_branch)"
core_git fetch --quiet origin "$fusionpbx_branch"
core_git merge-base --is-ancestor "$fusionpbx_commit" FETCH_HEAD \
	|| die "$fusionpbx_commit is not on upstream branch $fusionpbx_branch."
#never move core backwards: the schema only migrates forward
core_git merge-base --is-ancestor HEAD "$fusionpbx_commit" \
	|| die "Core is at $(core_git rev-parse HEAD), which is not an ancestor of $fusionpbx_commit. Refusing to downgrade."

#stop our workers so none runs against half-moved code; apply.sh restarts them
#if anything below fails they stay stopped (fail-safe) until upgrade.sh succeeds
info "Stopping x-* workers; they restart when the upgrade completes"
for unit in /etc/systemd/system/x-*.timer /etc/systemd/system/x-*.path; do
	if [ -e "$unit" ]; then
		systemctl stop "$(basename "$unit")"
	fi
done
systemctl stop 'x-*.service' || true

core_git checkout --quiet "$fusionpbx_commit"
chown -R www-data:www-data "$fusionpbx_dir"

bash resources/apply.sh upgrade "${1:-}"

info "Restarting services"
fusionpbx_upgrade --services
systemctl restart "php${php_version}-fpm" nginx

info "Upgrade complete. Optional upstream apps (speech, transcribe, ...) track their own branches; update them with: ./upgrade.sh --optional"
