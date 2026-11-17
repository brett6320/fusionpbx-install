#!/bin/bash
# Shared helpers. Sourced, not executed.

fusionpbx_dir=/var/www/fusionpbx

info() { printf '\033[1;32m[x-install]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m[x-install]\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31m[x-install]\033[0m %s\n' "$*" >&2; exit 1; }

require_root() {
	[ "$(id -u)" -eq 0 ] || die "Run as root."
}

require_debian() {
	if ! command -v lsb_release >/dev/null 2>&1; then
		apt-get update || die "apt-get update failed."
		apt-get install -y lsb-release || die "Cannot install lsb-release."
	fi
	local os codename
	os=$(lsb_release -is)
	codename=$(lsb_release -cs)
	[ "$os" = "Debian" ] || die "Only Debian is supported (found $os)."
	case "$codename" in
		trixie) ;;
		#bookworm was supported before; existing hosts can still upgrade, untested
		bookworm) [ "${1:-}" = upgrade ] || die "Unsupported Debian release: $codename (need trixie)."
			warn "Debian bookworm is no longer supported or tested. Upgrading anyway; move this host to trixie." ;;
		*) die "Unsupported Debian release: $codename (need trixie)." ;;
	esac
}

# Start a unit that is not active, once (clearing a "start request repeated too quickly" failure), and
# report whether it is active. Upstream restarts php-fpm before /etc/fusionpbx exists in some paths, which
# its own ReadWritePaths drop-in makes fatal until the directory appears.
ensure_active() {
	systemctl is-active --quiet "$1" && return 0
	warn "$1 is not running; starting it once."
	systemctl reset-failed "$1" 2>/dev/null || true
	systemctl start "$1" 2>/dev/null || true
	sleep 2
	systemctl is-active --quiet "$1"
}

# Run a FusionPBX upgrade.php step from the FusionPBX root, as upstream does.
fusionpbx_upgrade() {
	(cd "$fusionpbx_dir" && /usr/bin/php "$fusionpbx_dir/core/upgrade/upgrade.php" "$@")
}

# Git on the FusionPBX checkout. It is owned by www-data and we run as root, so
# allow it for this command only instead of changing global git config.
core_git() {
	git -c safe.directory="$fusionpbx_dir" -C "$fusionpbx_dir" "$@"
}

# Enable a unit and start it, unless X_APPLY_NO_START=1 (restore.sh --no-start).
enable_unit() {
	if [ "${X_APPLY_NO_START:-}" = 1 ]; then
		systemctl enable "$1"
	else
		systemctl enable --now "$1"
	fi
}

# Carrier credential key directory: config.conf x_carriers.key_dir, else the default.
carrier_key_dir() {
	local dir=""
	if [ -r /etc/fusionpbx/config.conf ]; then
		dir=$(sed -n 's/^[[:space:]]*x_carriers\.key_dir[[:space:]]*=[[:space:]]*//p' /etc/fusionpbx/config.conf | tail -n 1 | tr -d "\"'" | sed 's/[[:space:]]*$//')
	fi
	dir=${dir%/}
	printf '%s\n' "${dir:-/etc/fusionpbx/x_carriers/keys}"
}

# Replace or append key=value in a shell config file.
set_config_value() {
	local file=$1 key=$2 value=$3
	if grep -q "^${key}=" "$file"; then
		sed -i "s|^${key}=[^ #]*|${key}=${value}|" "$file"
	else
		printf '%s=%s\n' "$key" "$value" >> "$file"
	fi
}
