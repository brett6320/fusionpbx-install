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
if ! command -v git >/dev/null || ! command -v curl >/dev/null; then
	apt-get update || die "apt-get update failed."
	apt-get install -y git curl ca-certificates || die "Cannot install git and curl."
fi
branch_head=$(git ls-remote "$fusionpbx_repo" "refs/heads/$fusionpbx_branch" | cut -f1)
[ -n "$branch_head" ] || die "Cannot read upstream branch $fusionpbx_branch."
if [ "$branch_head" != "$fusionpbx_commit" ]; then
	die "Upstream $fusionpbx_branch is at $branch_head, not the pinned $fusionpbx_commit. Test the new head, then set fusionpbx_commit in config.sh to it."
fi

#upstream's installer ignores failed downloads and carries on, leaving a host with no database. check
#everything it fetches is reachable, over the scheme it uses, before touching the host
codename=$(lsb_release -cs)
preflight_urls=(
	"http://apt.postgresql.org/pub/repos/apt/dists/$codename-pgdg/InRelease"
	"https://www.postgresql.org/media/keys/ACCC4CF8.asc"
	"https://packages.sury.org/php/dists/$codename/InRelease"
	"https://packages.sury.org/php/apt.gpg"
	"https://github.com/signalwire/libks"
	"https://github.com/freeswitch/sofia-sip"
	"https://files.freeswitch.org/releases/sounds/"
)
#refuse only when a host cannot be reached at all (blocked egress); an HTTP error is a warning, since a
#moved path or a CDN challenge does not mean upstream's download will fail
unreachable=()
for url in "${preflight_urls[@]}"; do
	code=$(curl -sS -L -o /dev/null -w '%{http_code}' --max-time 20 --retry 2 "$url" 2>/dev/null || true)
	case "$code" in
		000|"") unreachable+=("$url") ;;
		2??|3??) ;;
		*) warn "Preflight: $url answered HTTP $code." ;;
	esac
done
if [ "${#unreachable[@]}" -gt 0 ]; then
	printf '  %s\n' "${unreachable[@]}" >&2
	die "Cannot reach the URLs above, which upstream's installer needs. Check outbound TCP 80 and 443 (security group egress, network ACL, proxy). The FusionPBX install has not started."
fi

#install.sh cannot be re-run once upstream has installed; this is how to finish by hand
recover="run ./upgrade.sh --restore-menu and attach the carrier ACL in Advanced > Carrier ACL > Attach to Profiles."

log="$install_log_dir/x-install-$(date +%Y%m%d-%H%M%S).log"
install -m 0600 -o root -g root /dev/null "$log"
info "Logging to $log"

#X_INSTALL_STOP_AFTER_UPSTREAM=1 stops after upstream's installer and the checks of what it left, before
#our layer (resources/apply.sh install). Only test/install-test.sh --quick uses it, to save a base image.
stop_after_upstream=${X_INSTALL_STOP_AFTER_UPSTREAM:-}

{
	#"Timing:" lines (epoch seconds) let test/install-test.sh report how long each phase took
	info "Timing: upstream start $(date +%s)"
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

	#upstream's installer always exits 0, so check what it left behind. Without the database nothing can be
	#finished by hand: upstream's install is not re-runnable
	domains=$(cd / && runuser -u postgres -- psql -d fusionpbx -qtAX -c 'select count(*) from v_domains' 2>/dev/null || true)
	if ! [[ "$domains" =~ ^[1-9][0-9]*$ ]]; then
		die "Upstream's installer did not create the FusionPBX database; see the 'Install PostgreSQL' section of $log. This host cannot be completed in place: fix the cause, then install on a fresh host."
	fi
	runuser -u www-data -- test -r /etc/fusionpbx/config.conf || die "www-data cannot read /etc/fusionpbx/config.conf. Fix it, then $recover"
	for unit in freeswitch nginx "php${php_version}-fpm"; do
		ensure_active "$unit" || die "$unit is not running (see journalctl -u $unit). Fix it, then $recover"
	done
	#the event socket comes up some seconds after the unit starts (upstream waits up to 60s too)
	for _ in $(seq 30); do
		fs_cli -x status >/dev/null 2>&1 && break
		sleep 2
	done
	fs_cli -x status >/dev/null || die "FreeSWITCH is not answering on the event socket. Fix it, then $recover"

	info "Timing: layer start $(date +%s)"
	if [ "$stop_after_upstream" = 1 ]; then
		info "Stopping before our layer (X_INSTALL_STOP_AFTER_UPSTREAM=1). Finish with: bash resources/apply.sh install"
	else
		bash resources/apply.sh install
	fi
	info "Timing: done $(date +%s)"
} 2>&1 | tee -a "$log"

if [ "$stop_after_upstream" = 1 ]; then
	exit 0
fi
info "Done. Reboot to finish, as upstream recommends. Credentials are in $log."
