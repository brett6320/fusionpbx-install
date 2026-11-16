#!/bin/bash
# AWS instance settings (deploy/aws/). Run by resources/apply.sh after the layer is applied, and only when
# /etc/default/fusionpbx-aws exists. Idempotent.
#
# - Carrier ACL: the Telnyx signalling and media prefix lists become carrier ACL feeds. x-aws-sync (root, hourly
#   timer) reads them from AWS into /var/lib/x_carrier_acl/aws/, and the worker applies them as for any feed. On
#   install, each feed's first run is approved here (the security group already admits these addresses).
# - SIP addresses: external_sip_ip and external_rtp_ip become the Elastic IP (EC2 uses 1:1 NAT), but only if
#   IMDS and AWS show this instance holds it, so a restored or cloned disk never takes production's address.
# - Instance metadata: only root may reach it (x-imds-guard), so code running as www-data cannot take the
#   instance role's credentials.
#
# Usage: resources/aws.sh install|upgrade
set -euo pipefail
cd "$(dirname "$0")/.."
. ./config.sh
. ./resources/common.sh

mode=${1:-}
case "$mode" in
	install|upgrade) ;;
	*) die "Usage: $0 install|upgrade" ;;
esac

settings=/etc/default/fusionpbx-aws
units=(/etc/systemd/system/x-aws-sync.service /etc/systemd/system/x-aws-sync.timer)

#without the file nothing here runs; a timer left from when the file existed is removed
remove_timer() {
	[ -f /etc/systemd/system/x-aws-sync.timer ] || return 0
	systemctl disable --now x-aws-sync.timer 2>/dev/null || true
	rm -f "${units[@]}"
	systemctl daemon-reload
	warn "Removed x-aws-sync.timer. The AWS carrier ACL feeds, if any, keep their entries; disable them in Advanced > Carrier ACL if they are no longer wanted."
}
#the metadata guard goes with the file: harmless without it, but this host is then no longer configured for AWS.
#An invalid file keeps the guard, so a typo never weakens the host.
remove_guard() {
	[ -f /etc/systemd/system/x-imds-guard.service ] || return 0
	systemctl disable x-imds-guard.service 2>/dev/null || true
	/usr/local/sbin/x-imds-guard remove 2>/dev/null || true
	rm -f /etc/systemd/system/x-imds-guard.service /usr/local/sbin/x-imds-guard \
		/etc/systemd/system/netfilter-persistent.service.d/x_imds_guard.conf /etc/systemd/system/nftables.service.d/x_imds_guard.conf
	systemctl daemon-reload
	warn "Removed the instance metadata guard (x-imds-guard)."
}
if [ ! -e "$settings" ]; then
	remove_timer
	remove_guard
	exit 0
fi

#a present but invalid file runs nothing either (checked with this checkout's copy, no network)
if ! check=$(python3 -I resources/sbin/x-aws-sync --check 2>&1); then
	remove_timer
	msg="$settings is not valid, so nothing was configured from AWS: ${check#x-aws-sync: }"
	[ "$mode" = install ] && die "$msg Fix it, then run ./upgrade.sh."
	warn "$msg"
	exit 0
fi
info "Configuring for AWS from $settings"

php=/usr/bin/php
as_www() {
	(cd "$fusionpbx_dir" && runuser -u www-data -- "$@")
}

#X_AWS_SYNC_CMD replaces x-aws-sync in test/install-test.sh (a container has no instance metadata)
sync_cmd=${X_AWS_SYNC_CMD:-/usr/local/sbin/x-aws-sync}

#instance role credentials and the Elastic IP association can lag first boot; exit 2 is a permanent error (this is
#not the instance or address in the file, access denied, not found), which no retry fixes
aws_sync() {
	local attempt rc
	for attempt in 1 2 3 4 5; do
		rc=0
		"$sync_cmd" "$@" || rc=$?
		[ "$rc" -eq 0 ] && return 0
		[ "$rc" -eq 2 ] && return 2
		[ "$attempt" -lt 5 ] && sleep $((attempt * 8))
	done
	return 1
}

install -o root -g root -m 0755 resources/sbin/x-aws-sync /usr/local/sbin/x-aws-sync
install -d -o root -g root -m 0755 /var/lib/x_carrier_acl /var/lib/x_carrier_acl/aws

#-------------------------------------------------------------------------------------------------------------------
# 0. instance metadata for root only; re-applied at boot and after netfilter-persistent or nftables start (their
#    restore flushes our rules). A firewall reload without a restart drops it until the next boot or apply.
#-------------------------------------------------------------------------------------------------------------------
install -o root -g root -m 0755 resources/sbin/x-imds-guard /usr/local/sbin/x-imds-guard
cat > /etc/systemd/system/x-imds-guard.service <<'EOF'
[Unit]
Description=Allow only root to reach the EC2 instance metadata service
;before the network is up, like nftables.service, so nothing reaches the metadata service before the rule exists
DefaultDependencies=no
After=local-fs.target netfilter-persistent.service nftables.service
Before=network-pre.target shutdown.target
Wants=network-pre.target
Conflicts=shutdown.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/x-imds-guard apply

[Install]
WantedBy=multi-user.target
EOF
for service in netfilter-persistent nftables; do
	install -d -o root -g root -m 0755 "/etc/systemd/system/$service.service.d"
	printf '[Unit]\nWants=x-imds-guard.service\nBefore=x-imds-guard.service\n' > "/etc/systemd/system/$service.service.d/x_imds_guard.conf"
	chmod 0644 "/etc/systemd/system/$service.service.d/x_imds_guard.conf"
done
systemctl daemon-reload
systemctl enable x-imds-guard.service
if ! systemctl start x-imds-guard.service || ! /usr/local/sbin/x-imds-guard check; then
	msg="Could not restrict instance metadata to root; see: journalctl -u x-imds-guard.service"
	[ "$mode" = install ] && die "$msg"
	warn "$msg"
fi

#-------------------------------------------------------------------------------------------------------------------
# 1. hourly refresh of the prefix lists; first, because apply.sh stopped every x- timer and a failure further down
#    must not leave this one stopped or missing
#-------------------------------------------------------------------------------------------------------------------
cat > /etc/systemd/system/x-aws-sync.service <<'EOF'
[Unit]
Description=Read the carrier prefix lists from AWS for the carrier ACL
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/x-aws-sync
TimeoutStartSec=5min
StateDirectory=x_carrier_acl/aws
StateDirectoryMode=0755
NoNewPrivileges=yes
CapabilityBoundingSet=
PrivateTmp=yes
PrivateDevices=yes
ProtectSystem=strict
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_INET AF_INET6
RestrictNamespaces=yes
LockPersonality=yes
SystemCallArchitectures=native
EOF

cat > /etc/systemd/system/x-aws-sync.timer <<'EOF'
[Unit]
Description=Hourly read of the carrier prefix lists from AWS

[Timer]
OnBootSec=5min
OnUnitActiveSec=1h
RandomizedDelaySec=5min

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
enable_unit x-aws-sync.timer

#-------------------------------------------------------------------------------------------------------------------
# 2. prefix lists
#-------------------------------------------------------------------------------------------------------------------
synced=true
if ! aws_sync; then
	synced=false
	warn "Could not read the prefix lists from AWS (see above). The carrier ACL feeds keep their last entries and"
	warn "report the failure. Fix the cause (instance role, $settings), then run: x-aws-sync"
fi

#-------------------------------------------------------------------------------------------------------------------
# 3. external SIP and RTP address
#-------------------------------------------------------------------------------------------------------------------
fs_restart_needed=false
address=""
managed=""
if address=$(aws_sync --address); then
	if output=$(as_www "$php" "$fusionpbx_dir/app/x_local_defaults/resources/cli/x_external_ip.php" "$address"); then
		printf '%s\n' "$output" | sed '$d' | grep -v '^managed:' || true
		#only the variables the script manages are checked below; an admin's own value is left alone
		managed=$(printf '%s\n' "$output" | sed -n 's/^managed: *//p')
		[ "$(printf '%s\n' "$output" | tail -n 1)" = changed ] && fs_restart_needed=true
	else
		msg="Could not set external_sip_ip and external_rtp_ip to $address in vars.xml. Calls may have one-way audio. Set them in Advanced > Variables."
		[ "$mode" = install ] && die "$msg"
		warn "$msg"
		address=""
	fi
else
	address=""
	warn "This instance does not hold the Elastic IP named in $settings (see above); SIP addresses not changed."
fi

if [ -n "$managed" ] && [ "${X_APPLY_NO_START:-}" != 1 ]; then
	if [ "$fs_restart_needed" = true ] && [ "$mode" = install ]; then
		info "Restarting FreeSWITCH for the new external address"
		systemctl restart freeswitch || warn "FreeSWITCH did not restart; see: journalctl -u freeswitch"
		for _ in $(seq 30); do
			fs_cli -x status >/dev/null 2>&1 && break
			sleep 2
		done
	fi
	stale=false
	for var in $managed; do
		live=$(fs_cli -x "global_getvar $var" 2>/dev/null || true)
		[ "$(printf '%s' "$live" | tr -d '[:space:]')" = "$address" ] || stale=true
	done
	if [ "$stale" = true ]; then
		warn "vars.xml has $address, but FreeSWITCH is still running with the old value. Restart it when no calls are up: systemctl restart freeswitch"
	else
		profile=$(fs_cli -x 'sofia status profile external' 2>/dev/null || true)
		profile_ok=true
		for var in $managed; do
			case "$var" in
				external_sip_ip) label=ext-sip-ip ;;
				external_rtp_ip) label=ext-rtp-ip ;;
				*) continue ;;
			esac
			printf '%s\n' "$profile" | grep -i "$label" | grep -qF "$address" || profile_ok=false
		done
		if [ "$profile_ok" = true ]; then
			info "FreeSWITCH uses $address as its external address ($managed)."
		else
			warn "vars.xml has $address, but the external profile does not use it yet. Restart it when no calls are up: fs_cli -x 'sofia profile external restart'"
		fi
	fi
fi

#-------------------------------------------------------------------------------------------------------------------
# 4. carrier ACL feeds; on install, approve their first run
#-------------------------------------------------------------------------------------------------------------------
if ! output=$(as_www "$php" "$fusionpbx_dir/app/x_carrier_acl/resources/cli/x_carrier_acl_aws.php" --ensure-feeds); then
	warn "Could not create the AWS carrier ACL feeds: $output"
elif [ -n "$output" ]; then
	printf '%s\n' "$output"
fi

if [ "$mode" = install ] && [ "$synced" = true ] && [ -n "$address" ] && [ "${X_APPLY_NO_START:-}" != 1 ]; then
	info "Applying the AWS prefix lists to the carrier ACL"
	#through the unit, so the worker runs in its sandbox. Exit 3 means a feed has no run yet: a timer run that
	#started before the feeds existed was still active, so "start" joined it instead of running again.
	for attempt in 1 2; do
		systemctl start x-carrier-acl-worker.service || warn "The carrier ACL worker failed; see: journalctl -u x-carrier-acl-worker.service"
		rc=0
		as_www "$php" "$fusionpbx_dir/app/x_carrier_acl/resources/cli/x_carrier_acl_aws.php" --approve-initial || rc=$?
		[ "$rc" -eq 3 ] && [ "$attempt" -eq 1 ] && continue
		[ "$rc" -eq 0 ] || [ "$rc" -eq 3 ] || warn "Could not approve the first AWS feed runs; approve them in Advanced > Carrier ACL > Runs."
		break
	done
	systemctl start x-carrier-acl-worker.service || warn "The carrier ACL worker failed; see: journalctl -u x-carrier-acl-worker.service"
fi
