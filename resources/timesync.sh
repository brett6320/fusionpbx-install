#!/bin/bash
# Keep the host clock synced (chrony) and periodically tell FreeSWITCH to
# resync its internal clock to the host.
set -euo pipefail
cd "$(dirname "$0")/.."
. ./config.sh
. ./resources/common.sh

info "Configuring time sync"

#chrony replaces ntp/ntpsec, which upstream installs on some install paths only
apt-get install -y chrony
systemctl enable --now chrony

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
systemctl enable --now x-freeswitch-clock-sync.timer
