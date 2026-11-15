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
	command -v lsb_release >/dev/null 2>&1 || apt-get install -y lsb-release
	local os codename
	os=$(lsb_release -is)
	codename=$(lsb_release -cs)
	[ "$os" = "Debian" ] || die "Only Debian is supported (found $os)."
	case "$codename" in
		bookworm|trixie) ;;
		*) die "Unsupported Debian release: $codename (need bookworm or trixie)." ;;
	esac
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
