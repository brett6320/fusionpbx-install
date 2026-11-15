#!/bin/bash
# Install x-backup and its nightly timer. Run by resources/apply.sh. Idempotent.
set -euo pipefail
cd "$(dirname "$0")/.."
. ./config.sh
. ./resources/common.sh

info "Configuring backups"

case "$backup_dir" in
	/var/www/*) die "backup_dir must not be under /var/www." ;;
	/*) ;;
	*) die "backup_dir must be an absolute path." ;;
esac
[[ "$backup_retention_days" =~ ^[0-9]+$ ]] || die "backup_retention_days must be a number."
[[ "$backup_min_free_percent" =~ ^[0-9]+$ ]] && [ "$backup_min_free_percent" -lt 100 ] || die "backup_min_free_percent must be 0-99."

#the layer's commit goes into each manifest so restore.sh can refuse a backup from newer app code
layer_commit=$(git -c safe.directory="$PWD" rev-parse HEAD 2>/dev/null || echo unknown)

install -o root -g root -m 0755 resources/sbin/x-backup /usr/local/sbin/x-backup
install -o root -g root -m 0755 resources/sbin/x-backup-check /usr/local/sbin/x-backup-check
install -d -o root -g root -m 0700 "$backup_dir"
{
	echo "# Written by resources/backup.sh from config.sh. Do not edit; change config.sh and rerun upgrade.sh."
	printf 'backup_dir=%q\n' "$backup_dir"
	printf 'backup_retention_days=%q\n' "$backup_retention_days"
	printf 'backup_min_free_percent=%q\n' "$backup_min_free_percent"
	printf 'backup_offsite_cmd=%q\n' "$backup_offsite_cmd"
	printf 'layer_commit=%q\n' "$layer_commit"
} > /etc/default/x-backup.tmp
chmod 0600 /etc/default/x-backup.tmp
mv -f /etc/default/x-backup.tmp /etc/default/x-backup

cat > /etc/systemd/system/x-backup.service <<'EOF'
[Unit]
Description=Back up FusionPBX (database, FreeSWITCH and app data)
After=postgresql.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/x-backup --skip-if-busy
Nice=10
IOSchedulingClass=idle
PrivateTmp=true
EOF

cat > /etc/systemd/system/x-backup.timer <<EOF
[Unit]
Description=Nightly FusionPBX backup

[Timer]
OnCalendar=${backup_time}
RandomizedDelaySec=15min
Persistent=true

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
enable_unit x-backup.timer
