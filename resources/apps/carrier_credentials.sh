#!/bin/bash
# Host setup for x_carrier_credentials (docs/design/carrier_credentials.md, "Installer contract").
#
# Run by resources/apply.sh after apps are copied and before upgrade.php --schema/--defaults/--permissions.
# Idempotent. Never replaces an existing key.
#
# Usage: resources/apps/carrier_credentials.sh install|upgrade [--rotate-key]
#   --rotate-key  (upgrade only, run by hand) create a new master key and re-encrypt every stored secret.
set -euo pipefail
cd "$(dirname "$0")/../.."
. ./config.sh
. ./resources/common.sh

mode=${1:-}
rotate_key=false
[ "${2:-}" = "--rotate-key" ] && rotate_key=true
case "$mode" in
	install|upgrade) ;;
	*) die "Usage: $0 install|upgrade [--rotate-key]" ;;
esac

php_bin="php${php_version}"
command -v "$php_bin" >/dev/null 2>&1 || php_bin=php
cli="$fusionpbx_dir/app/x_carrier_credentials/resources/cli/x_carriers_keyring.php"
[ -f "$cli" ] || die "x_carrier_credentials is not deployed at $fusionpbx_dir/app/x_carrier_credentials."

#1. sodium extension, fail closed
#capture the module list first: grep -q closing the pipe early would trip pipefail
php_modules=$("$php_bin" -m)
if ! grep -qi '^sodium$' <<<"$php_modules"; then
	apt-get install -y --no-install-recommends "php${php_version}-sodium" || true
	php_modules=$("$php_bin" -m)
fi
grep -qi '^sodium$' <<<"$php_modules" || die "PHP sodium extension missing; carrier credentials cannot be encrypted."

#2. key directory, from config.conf x_carriers.key_dir if set
key_dir=/etc/fusionpbx/x_carriers/keys
if [ -r /etc/fusionpbx/config.conf ]; then
	configured=$(sed -n 's/^[[:space:]]*x_carriers\.key_dir[[:space:]]*=[[:space:]]*//p' /etc/fusionpbx/config.conf | tail -n 1 | tr -d "\"'" | sed 's/[[:space:]]*$//')
	if [ -n "$configured" ]; then
		key_dir=${configured%/}
	fi
fi
case "$key_dir" in
	/var/www/*) die "x_carriers.key_dir must not be under /var/www." ;;
	/*) ;;
	*) die "x_carriers.key_dir must be an absolute path." ;;
esac
#own the parent only when it is ours; a custom parent (e.g. /etc) must already exist and is left alone
if [ "$key_dir" = /etc/fusionpbx/x_carriers/keys ]; then
	install -d -o root -g www-data -m 0750 /etc/fusionpbx/x_carriers
else
	[ "$(printf '%s' "$key_dir" | tr -cd / | wc -c)" -ge 3 ] || die "x_carriers.key_dir must be at least three levels deep (e.g. /etc/fusionpbx/keys)."
	[ -d "$(dirname "$key_dir")" ] || die "Parent of x_carriers.key_dir does not exist: $(dirname "$key_dir")"
fi
install -d -o root -g www-data -m 0750 "$key_dir"

#existing key files keep root:www-data 0640 (www-data reads, cannot write or replace)
find "$key_dir" -maxdepth 1 -type f -name 'k*.key' -exec chown root:www-data {} + -exec chmod 0640 {} +

#3. key generation, only when no active key exists
if [ -L "$key_dir/active" ]; then
	[ -e "$key_dir/active" ] || die "$key_dir/active points to a missing key file. Restore it from /var/backups/x_carriers-keys/; refusing to create a new key."
elif [ -e "$key_dir/active" ]; then
	die "$key_dir/active exists but is not a symlink."
else
	key_id="k$(date -u +%Y%m%dT%H%MZ)"
	[ -e "$key_dir/$key_id.key" ] && die "$key_dir/$key_id.key already exists without an active link; investigate before continuing."
	info "Creating carrier credential master key $key_id"
	(
		umask 027
		head -c 32 /dev/urandom | base64 -w0 > "$key_dir/$key_id.key"
	)
	chown root:www-data "$key_dir/$key_id.key"
	chmod 0640 "$key_dir/$key_id.key"
	ln -s "$key_id.key" "$key_dir/active"
fi

#4. key backup: separate from the stock pg_dump, root only
backup_dir=/var/backups/x_carriers-keys
#kept outside /var/backups/fusionpbx so shipping the database dumps offsite
#does not also ship the keys that decrypt them
install -d -o root -g root -m 0700 "$backup_dir"
cron_tmp=$(mktemp)
trap 'rm -f "$cron_tmp"' EXIT
cat > "$cron_tmp" <<EOF
#!/bin/sh
# Installed by fusionpbx-install resources/apps/carrier_credentials.sh. Do not edit; rerun the installer.
# Copies the carrier credential master keys to a root-only backup directory.
# Old keys are kept in the backup so older database dumps stay decryptable.
set -eu
src='$key_dir'
dst='$backup_dir'
[ -d "\$src" ] || exit 0
umask 077
install -d -o root -g root -m 0700 "\$dst"
rsync -a --chown=root:root --chmod=D0700,F0600 "\$src"/ "\$dst"/
EOF
install -o root -g root -m 0755 "$cron_tmp" /etc/cron.daily/x_carriers-keybackup
#back up a new key now rather than tomorrow
/etc/cron.daily/x_carriers-keybackup

#5. optional master key rotation (upgrade only; tables must exist)
if [ "$rotate_key" = true ]; then
	[ "$mode" = upgrade ] || die "--rotate-key is only valid with upgrade."
	info "Rotating the carrier credential master key"
	(cd "$fusionpbx_dir" && "$php_bin" "$cli" rotate)
	(cd "$fusionpbx_dir" && runuser -u www-data -- "$php_bin" "$cli" reencrypt)
	/etc/cron.daily/x_carriers-keybackup
fi

#6. verify the web user can read the key and decrypt every stored secret; fail closed
info "Checking carrier credential keys"
if ! (cd "$fusionpbx_dir" && runuser -u www-data -- "$php_bin" "$cli" check); then
	#on upgrade, dying here would leave the new code on the old schema; upgrade.sh
	#already ran this check before moving core
	[ "$mode" = upgrade ] || die "Carrier credential key check failed. Fix the key directory before restarting services."
	warn "Carrier credential key check failed. Fix the key directory, then run: x_carriers_keyring.php check"
fi
