#!/bin/bash
# Move FusionPBX core to the pinned release in config.sh and reapply our layer.
#
# Usage: ./upgrade.sh [--restore-menu]
#   --restore-menu  Rebuild the default menu. Needed when an upgrade adds menu
#                   entries; resets any admin menu edits.
set -euo pipefail
cd "$(dirname "$0")"
. ./config.sh
. ./resources/common.sh

case "${1:-}" in
	""|--restore-menu) ;;
	*) die "Usage: $0 [--restore-menu]" ;;
esac

require_root
require_debian

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

info "Upgrade complete. Optional upstream apps (speech, sip_trunks, ...) track their own branches; update them with: cd $fusionpbx_dir && php core/upgrade/upgrade.php --optional"
