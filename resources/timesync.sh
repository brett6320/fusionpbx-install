#!/bin/bash
# Keep the host clock synced (chrony) and periodically tell FreeSWITCH to
# resync its internal clock to the host. On EC2, chrony also uses the Amazon
# Time Sync Service (169.254.169.123), which needs no outbound internet access.
set -euo pipefail
cd "$(dirname "$0")/.."
. ./config.sh
. ./resources/common.sh

info "Configuring time sync"

#chrony replaces ntp/ntpsec, which upstream installs on some install paths only
apt-get install -y chrony
systemctl enable --now chrony

#EC2 (Nitro reports "Amazon EC2" in DMI, older Xen instances an ec2 hypervisor uuid). X_IS_EC2 overrides the
#detection in test/install-test.sh
is_ec2() {
	case "${X_IS_EC2:-}" in 1) return 0 ;; 0) return 1 ;; esac
	[ "$(cat /sys/class/dmi/id/sys_vendor 2>/dev/null)" = "Amazon EC2" ] && return 0
	case "$(cat /sys/hypervisor/uuid 2>/dev/null)" in ec2*|EC2*) return 0 ;; esac
	return 1
}
aws_time=/etc/chrony/sources.d/x-aws-time.sources
aws_time_old=$(cat "$aws_time" 2>/dev/null || true)
if is_ec2; then
	#a server line an admin already added for it (in chrony.conf or another sources file) is left alone
	if grep -rqsE '^[[:space:]]*(server|pool)[[:space:]]+169\.254\.169\.123([[:space:]]|$)' /etc/chrony/chrony.conf /etc/chrony/conf.d /etc/chrony/sources.d --exclude="$(basename "$aws_time")"; then
		rm -f "$aws_time"
		info "chrony already lists the Amazon Time Sync Service"
	else
		install -d -m 0755 /etc/chrony/sources.d
		printf '%s\n' '# Managed by the installer: Amazon Time Sync Service, reachable from every EC2 instance' \
			'server 169.254.169.123 prefer iburst minpoll 4 maxpoll 4' > "$aws_time"
		chmod 0644 "$aws_time"
		info "chrony uses the Amazon Time Sync Service"
	fi
else
	rm -f "$aws_time"
fi
if [ "$(cat "$aws_time" 2>/dev/null || true)" != "$aws_time_old" ]; then
	chronyc reload sources >/dev/null || systemctl restart chrony
fi

cat > /etc/systemd/system/x-freeswitch-clock-sync.service <<'EOF'
[Unit]
Description=Resync FreeSWITCH clock to the system clock
After=freeswitch.service chrony.service
ConditionPathExists=/usr/bin/fs_cli

[Service]
Type=oneshot
DynamicUser=true
ExecStart=/usr/bin/fs_cli -x "fsctl sync_clock_when_idle"
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
EOF

cat > /etc/systemd/system/x-freeswitch-clock-sync.timer <<EOF
[Unit]
Description=Periodically resync FreeSWITCH clock

[Timer]
OnCalendar=*:0/${timesync_interval}
Persistent=false

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
enable_unit x-freeswitch-clock-sync.timer
