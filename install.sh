#!/bin/bash
# Install FusionPBX (pinned upstream) plus our apps and host configuration.
set -euo pipefail
cd "$(dirname "$0")"
. ./config.sh
. ./resources/common.sh

require_root
require_debian

#upstream's installer is not re-runnable: it regenerates the database password,
#overwrites config.conf, adds a second domain and admin, and flushes iptables
if [ -e /etc/fusionpbx/config.conf ] || [ -d "$fusionpbx_dir/.git" ]; then
	die "FusionPBX is already installed. Use ./upgrade.sh to reapply this layer."
fi

#upstream prints the generated admin and database passwords; keep the log private.
#only the log is restricted: a umask here would leak into upstream's installer and
#make the directories it creates (e.g. /etc/fusionpbx) unreadable to www-data
#upstream's installer clones the head of the branch and builds the schema from it;
#checking out an older pin afterwards would run old code on a newer schema. refuse
#before touching the host if the branch has moved past the pin.
command -v git >/dev/null || { apt-get update && apt-get install -y git ca-certificates; }
branch_head=$(git ls-remote "$fusionpbx_repo" "refs/heads/$fusionpbx_branch" | cut -f1)
[ -n "$branch_head" ] || die "Cannot read upstream branch $fusionpbx_branch."
if [ "$branch_head" != "$fusionpbx_commit" ]; then
	die "Upstream $fusionpbx_branch is at $branch_head, not the pinned $fusionpbx_commit. Test the new head, then set fusionpbx_commit in config.sh to it."
fi

#install.sh cannot be re-run once upstream has installed; this is how to finish by hand
recover="run ./upgrade.sh --restore-menu and attach the carrier ACL in Advanced > Carrier ACL > Attach to Profiles."

log="$install_log_dir/x-install-$(date +%Y%m%d-%H%M%S).log"
install -m 0600 -o root -g root /dev/null "$log"
info "Logging to $log"

{
	bash resources/upstream.sh

	info "Running upstream installer"
	(cd "$fusionpbx_installer_dir/debian" && ./install.sh)

	info "Pinning FusionPBX core to $fusionpbx_commit"
	head=$(core_git rev-parse HEAD)
	[ "$head" = "$fusionpbx_commit" ] || die "Upstream installed $head, not the pinned $fusionpbx_commit (branch moved during install). Set fusionpbx_commit in config.sh to it after testing, then $recover"
	core_git checkout --quiet "$fusionpbx_commit"
	head=$(core_git rev-parse HEAD)
	[ "$head" = "$fusionpbx_commit" ] || die "FusionPBX is at $head, expected $fusionpbx_commit."
	chown -R www-data:www-data "$fusionpbx_dir"

	#upstream's installer always exits 0, so check what it left behind
	runuser -u www-data -- test -r /etc/fusionpbx/config.conf || die "www-data cannot read /etc/fusionpbx/config.conf. Fix it, then $recover"
	for unit in postgresql freeswitch nginx "php${php_version}-fpm"; do
		systemctl is-active --quiet "$unit" || die "$unit is not running. Fix it, then $recover"
	done
	fs_cli -x status >/dev/null || die "FreeSWITCH is not answering on the event socket. Fix it, then $recover"

	bash resources/apply.sh install
} 2>&1 | tee -a "$log"

info "Done. Reboot to finish, as upstream recommends. Credentials are in $log."
