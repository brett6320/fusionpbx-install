#!/bin/bash
# Restore a bundle made by x-backup onto this host.
#
# The host must already be installed by install.sh (for a lost host: install a new one with install.sh,
# then restore). Everything is checked before anything changes; the database is restored into a side
# database and swapped in, and the current one is kept as fusionpbx_pre_restore_<timestamp>.
#
# Usage: ./restore.sh <bundle-dir> [options]
#   --keys DIR                carrier key directory to restore from (default /var/backups/x_carriers-keys)
#   --yes                     do not ask for confirmation
#   --no-start                leave FreeSWITCH, the FusionPBX services and our timers stopped; use this
#                             for drills or migrations while the original host is still running, or the
#                             restored gateways register with the same carrier credentials
#   --no-pre-backup           do not back up this host's current state first (e.g. a fresh install)
#   --accept-domain-mismatch  restore although an IP address domain in the bundle is not on this host
set -euo pipefail
cd "$(dirname "$0")"
. ./config.sh
. ./resources/common.sh

usage="Usage: $0 <bundle-dir> [--keys DIR] [--yes] [--no-start] [--no-pre-backup] [--accept-domain-mismatch]"
bundle=""
keys_src=/var/backups/x_carriers-keys
assume_yes=false
start=true
pre_backup=true
accept_domain_mismatch=false
while [ $# -gt 0 ]; do
	case "$1" in
		--keys) [ $# -ge 2 ] || die "$usage"; keys_src=$2; shift ;;
		--yes) assume_yes=true ;;
		--no-start) start=false ;;
		--no-pre-backup) pre_backup=false ;;
		--accept-domain-mismatch) accept_domain_mismatch=true ;;
		-*) die "$usage" ;;
		*) [ -z "$bundle" ] || die "$usage"; bundle=$1 ;;
	esac
	shift
done
[ -n "$bundle" ] || die "$usage"

require_root
require_debian

[ -r /etc/fusionpbx/config.conf ] && [ -d "$fusionpbx_dir/.git" ] \
	|| die "FusionPBX is not installed here. Run ./install.sh first, then restore."

stamp=$(date -u +%Y%m%dT%H%M%SZ)
log="$install_log_dir/x-restore-$stamp.log"
install -m 0600 -o root -g root /dev/null "$log"
exec > >(tee -a "$log") 2>&1
info "Logging to $log"

#one backup or restore at a time (x-backup takes the same lock)
exec 9>/run/lock/x-backup.lock
flock -n 9 || die "A backup or restore is running."

psql_q() { (cd / && runuser -u postgres -- psql -d "${2:-fusionpbx}" -qtAX -v ON_ERROR_STOP=1 -c "$1"); }
key_id_re='^k[0-9]{8}T[0-9]{4}([0-9]{2})?Z$'

#===================================================================================================
# 1. preflight: nothing below changes the host
#===================================================================================================
info "Checking the bundle"
bundle=$(cd "$bundle" 2>/dev/null && pwd) || die "Bundle directory not found."
for f in manifest database.dump files.tar.gz SHA256SUMS; do
	[ -f "$bundle/$f" ] && [ ! -L "$bundle/$f" ] || die "Bundle is missing $f."
done
[ "$(awk '{print $2}' "$bundle/SHA256SUMS" | sort | tr '\n' ' ')" = "database.dump files.tar.gz manifest " ] \
	|| die "SHA256SUMS does not list exactly manifest, database.dump and files.tar.gz."
(cd "$bundle" && sha256sum --quiet --strict -c SHA256SUMS) || die "Checksum mismatch; the bundle is damaged."

#the manifest is untrusted input: read single values and validate each, never source it
manifest() {
	local value
	value=$(sed -n "s/^$1=//p" "$bundle/manifest" | head -n 1)
	[[ "$value" =~ $2 ]] || die "Manifest value $1 is invalid: '$value'"
	printf '%s' "$value"
}
manifest format '^1$' >/dev/null
b_created=$(manifest created '^[0-9]{8}T[0-9]{6}Z$')
b_hostname=$(manifest hostname '^[A-Za-z0-9._-]*$')
b_commit=$(manifest fusionpbx_commit '^[0-9a-f]{40}$')
b_layer=$(manifest layer_commit '^([0-9a-f]{40}|unknown)$')
b_pg=$(manifest postgres_major '^[0-9]+$')
b_encoding=$(manifest database_encoding '^[A-Z0-9_]+$')
b_collate=$(manifest database_collate '^[A-Za-z0-9_.@-]+$')
b_ctype=$(manifest database_ctype '^[A-Za-z0-9_.@-]+$')
b_active=$(manifest active_key_id '^(k[0-9]{8}T[0-9]{4}([0-9]{2})?Z)?$')
b_key_ids=$(manifest key_ids '^(k[0-9]{8}T[0-9]{4}([0-9]{2})?Z(,k[0-9]{8}T[0-9]{4}([0-9]{2})?Z)*)?$')
b_domains=$(manifest domains '^([A-Za-z0-9.:_-]+(,[A-Za-z0-9.:_-]+)*)?$')

#core: the bundle's schema must not be newer than the code installed here
if ! core_git cat-file -e "$b_commit^{commit}" 2>/dev/null; then
	core_git fetch --quiet origin "$fusionpbx_branch" || true
fi
core_git cat-file -e "$b_commit^{commit}" 2>/dev/null \
	|| die "Bundle was made on FusionPBX $b_commit, which is not on branch $fusionpbx_branch."
core_git merge-base --is-ancestor "$b_commit" HEAD \
	|| die "Bundle was made on FusionPBX $b_commit, newer than the installed $(core_git rev-parse HEAD). Move fusionpbx_commit forward and run ./upgrade.sh first."

#our layer: the same rule for our apps' tables (the checkout may not be owned by root)
layer_git() { git -c safe.directory="$PWD" "$@"; }
if [ "$b_layer" = unknown ]; then
	warn "Bundle does not record the installer version; cannot check that its app tables are not newer than this checkout."
elif ! layer_git rev-parse --git-dir >/dev/null 2>&1; then
	warn "This checkout has no git metadata; cannot check the bundle's installer version $b_layer."
elif ! layer_git cat-file -e "$b_layer^{commit}" 2>/dev/null; then
	die "Bundle was made by installer commit $b_layer, which this checkout does not have. git pull, then ./upgrade.sh."
elif ! layer_git merge-base --is-ancestor "$b_layer" HEAD; then
	die "Bundle was made by installer commit $b_layer, newer than this checkout. git pull, then ./upgrade.sh."
fi

#postgres can restore older dumps, not newer ones
pg_major=$(( $(psql_q "show server_version_num") / 10000 ))
[ "$b_pg" -le "$pg_major" ] || die "Bundle is from PostgreSQL $b_pg; this host runs $pg_major."

#archive members: only the expected trees, no escapes, no links that later members could write through
tar_bytes=$(python3 -I resources/sbin/x-backup-check "$bundle/files.tar.gz") || die "files.tar.gz failed validation."

#carrier keys: every key the database needs, well formed, not conflicting with a different key of the same id
key_dir=$(carrier_key_dir)
[ -d "$key_dir" ] || die "Carrier key directory $key_dir not found; is x_carrier_credentials installed?"
needed_keys=$(printf '%s\n' "$b_active" "${b_key_ids//,/$'\n'}" | sed '/^$/d' | sort -u)
check_key() {
	local id=$1 src
	src="$keys_src/$id.key"
	[ -f "$src" ] && [ ! -L "$src" ] || die "Carrier key $id is not in $keys_src. Pass --keys with the key backup from the original host."
	[ "$(base64 -d < "$src" 2>/dev/null | wc -c)" -eq 32 ] || die "Carrier key $src is malformed."
	if [ -e "$key_dir/$id.key" ] && ! cmp -s "$src" "$key_dir/$id.key"; then
		die "A different key with id $id already exists in $key_dir; refusing to overwrite it."
	fi
}
for id in $needed_keys; do
	check_key "$id"
done

#an IP address domain (domain_name=ip_address) only works on a host with that address
host_names=" $(hostname) $(hostname -f 2>/dev/null || true) $(hostname -I 2>/dev/null || true) "
mismatched=()
for domain in ${b_domains//,/ }; do
	if [[ "$domain" =~ ^[0-9.]+$ || "$domain" == *:* ]] && [[ "$host_names" != *" $domain "* ]]; then
		mismatched+=("$domain")
	fi
done
if [ "${#mismatched[@]}" -gt 0 ]; then
	msg="Bundle has IP address domains not on this host: ${mismatched[*]}. Phones and the web UI use the domain name, so they will not reach it. Rename the domain in Advanced > Domains after the restore."
	[ "$accept_domain_mismatch" = true ] || die "$msg Pass --accept-domain-mismatch to restore anyway."
	warn "$msg"
fi

#disk: the restored database (roughly 4x the compressed dump), the staged files, and the pre-restore bundle
avail() { df -B1 --output=avail "$1" | tail -n 1; }
dump_bytes=$(stat -c %s "$bundle/database.dump")
pg_data=$(psql_q "show data_directory")
[ "$(avail "$pg_data")" -gt $((dump_bytes * 4)) ] || die "Not enough space in $pg_data for the restored database (about $((dump_bytes * 4)) bytes)."
need=$tar_bytes
if [ "$pre_backup" = true ]; then
	need=$((need + $(psql_q "select pg_database_size('fusionpbx')") + $(du -sbc /etc/freeswitch /var/lib/freeswitch /var/lib/fusionpbx 2>/dev/null | tail -n 1 | cut -f1)))
fi
install -d -o root -g root -m 0700 "$backup_dir"
[ "$(avail "$backup_dir")" -gt "$need" ] || die "Not enough space in $backup_dir for the staged files and the pre-restore backup (about $need bytes). Free space, or pass --no-pre-backup."

[ "$pre_backup" = false ] || [ -x /usr/local/sbin/x-backup ] || die "/usr/local/sbin/x-backup not found; run ./upgrade.sh, or pass --no-pre-backup."

#===================================================================================================
# 2. confirm
#===================================================================================================
cat <<EOF

  Bundle:     $bundle
  Made:       $b_created on ${b_hostname:-unknown host}
  FusionPBX:  $b_commit (installed: $(core_git rev-parse HEAD))
  Domains:    ${b_domains:-none}
  Keys:       ${needed_keys:-none} from $keys_src

  This replaces the database, /etc/freeswitch, FreeSWITCH storage and recordings, custom music
  and /var/lib/fusionpbx on $(hostname). Admin, database-backed and provisioning credentials become
  those of the bundle; passwords printed by install.sh on this host stop working.
  $( [ "$pre_backup" = true ] && echo "The current state is backed up first." || echo "The current state is NOT backed up (--no-pre-backup); only its database is kept." )
  $( [ "$start" = true ] && echo "Services start when it finishes." || echo "FreeSWITCH, the FusionPBX services and our timers stay stopped (--no-start)." )

EOF
if [ "$assume_yes" = false ]; then
	read -r -p "Type 'restore' to continue: " answer
	[ "$answer" = restore ] || die "Cancelled."
fi

#===================================================================================================
# 3. pre-restore backup (undo path)
#===================================================================================================
pre_dir=""
if [ "$pre_backup" = true ]; then
	info "Backing up the current state"
	/usr/local/sbin/x-backup --dir "$backup_dir/pre-restore" --lock-held --no-prune --no-offsite
	pre_dir=$(find "$backup_dir/pre-restore" -mindepth 1 -maxdepth 1 -type d -name '20*Z' -printf '%p\n' | sort | tail -n 1)
fi

#===================================================================================================
# 4. stop everything that uses the database or the files
#===================================================================================================
phase=stopping
old_db="fusionpbx_pre_restore_$stamp"
staging="$backup_dir/.restore-$stamp"
stopped_units=()

start_units() {
	local unit
	for unit in "$@"; do
		systemctl start "$unit" || warn "Could not start $unit."
	done
}

on_exit() {
	local rc=$?
	set +e
	rm -rf "$staging"
	[ "$rc" -eq 0 ] && return
	case "$phase" in
		stopping)
			warn "Restore failed before the database swap. This host is unchanged; restarting services."
			psql_q "alter database fusionpbx allow_connections true" postgres >/dev/null
			(cd / && runuser -u postgres -- dropdb --if-exists fusionpbx_restore)
			start_units freeswitch "php${php_version}-fpm" nginx "${stopped_units[@]}"
			;;
		swapped)
			warn "Restore failed after the database swap. Services are left stopped. To put the previous database back:"
			warn "  runuser -u postgres -- psql -c \"alter database $old_db allow_connections true\""
			warn "  runuser -u postgres -- psql -c \"begin; alter database fusionpbx rename to fusionpbx_failed_$stamp; alter database $old_db rename to fusionpbx; commit;\""
			if [ -n "$pre_dir" ]; then
				warn "Files and keys may be partly restored. The previous state is in $pre_dir; to restore all of it:"
				warn "  ./restore.sh $pre_dir --no-pre-backup --accept-domain-mismatch"
			else
				warn "Files may be partly restored and were not backed up first (--no-pre-backup)."
			fi
			warn "Log: $log"
			;;
		started)
			warn "The restore was applied, but the final check failed (see above). Log: $log"
			;;
	esac
}
trap on_exit EXIT
#a restore can run for hours: an interrupt or a dropped session must still run on_exit for the current phase
trap 'exit 130' INT TERM HUP

info "Stopping services"
units=()
for unit in /etc/systemd/system/x-*.timer /etc/systemd/system/x-*.path; do
	[ -e "$unit" ] && units+=("$(basename "$unit")")
done
#upstream services (email queue, fax queue, xml_cdr, event guard, ...) hold database connections
upstream_units=()
while IFS= read -r name; do
	[ -e "/etc/systemd/system/$name" ] || [ -e "/lib/systemd/system/$name" ] || continue
	units+=("$name")
	upstream_units+=("$name")
done < <(find "$fusionpbx_dir/app" "$fusionpbx_dir/core" -path '*/resources/service/*' -name '*.service' ! -path '*/app/x_*' -printf '%f\n' 2>/dev/null | sort -u)
for unit in "${units[@]}"; do
	if systemctl is-active --quiet "$unit"; then
		systemctl stop "$unit"
		stopped_units+=("$unit")
	fi
done
systemctl stop 'x-*.service' || true
systemctl stop freeswitch nginx "php${php_version}-fpm"

#===================================================================================================
# 5. database: restore into a side database, check keys against it, swap
#===================================================================================================
info "Restoring the database into fusionpbx_restore"
(cd / && runuser -u postgres -- dropdb --if-exists fusionpbx_restore)
if ! psql_q "create database fusionpbx_restore template template0 encoding '$b_encoding' lc_collate '$b_collate' lc_ctype '$b_ctype'" postgres >/dev/null; then
	IFS='|' read -r collate ctype < <(psql_q "select datcollate, datctype from pg_database where datname = 'fusionpbx'" postgres)
	warn "Cannot create the database with encoding $b_encoding and locale $b_collate/$b_ctype (locale missing, or the encoding does not fit it); trying this host's locale $collate/$ctype."
	psql_q "create database fusionpbx_restore template template0 encoding '$b_encoding' lc_collate '$collate' lc_ctype '$ctype'" postgres >/dev/null
fi
psql_q "grant all privileges on database fusionpbx_restore to fusionpbx" postgres >/dev/null
#fusionpbx owns every object on an upstream install, so ownership and grants are not carried over
(cd / && runuser -u postgres -- pg_restore --dbname=fusionpbx_restore --no-owner --no-privileges --role=fusionpbx \
	--exit-on-error --single-transaction) < "$bundle/database.dump"

#the manifest lists the keys at backup time; the restored data is what counts
if [ "$(psql_q "select to_regclass('public.v_x_carrier_credentials') is not null" fusionpbx_restore)" = t ]; then
	restored_keys=$(psql_q "select distinct split_part(x_carrier_credential_secret, ':', 2) as k from v_x_carrier_credentials where x_carrier_credential_secret like 'v1:%' order by k" fusionpbx_restore)
	for id in $restored_keys; do
		[[ "$id" =~ $key_id_re ]] || die "Restored database references an invalid carrier key id: $id"
		check_key "$id"
		# shellcheck disable=SC2086  # one id per word
		needed_keys=$(printf '%s\n' $needed_keys "$id" | sort -u)
	done
fi

info "Swapping the restored database in; the current one is kept as $old_db"
psql_q "alter database fusionpbx allow_connections false" postgres >/dev/null
psql_q "select pg_terminate_backend(pid) from pg_stat_activity where datname in ('fusionpbx', 'fusionpbx_restore') and pid <> pg_backend_pid()" postgres >/dev/null
#both renames in one transaction: there is always a database called fusionpbx
(cd / && runuser -u postgres -- psql -d postgres -qX -v ON_ERROR_STOP=1) <<EOF
begin;
alter database fusionpbx rename to $old_db;
alter database fusionpbx_restore rename to fusionpbx;
commit;
EOF
phase=swapped

#===================================================================================================
# 6. files and keys
#===================================================================================================
info "Restoring files"
install -d -o root -g root -m 0700 "$staging"
tar -C "$staging" --extract --gzip --file "$bundle/files.tar.gz" --same-owner --same-permissions
#the same excludes as x-backup, so --delete keeps the stock and derived trees on this host
for path in etc/freeswitch var/lib/freeswitch/storage var/lib/freeswitch/recordings usr/share/freeswitch/sounds/music var/lib/fusionpbx; do
	[ -d "$staging/$path" ] || continue
	excludes=()
	case "$path" in
		usr/share/freeswitch/sounds/music) excludes=(--exclude=/default/) ;;
		var/lib/fusionpbx) excludes=(--exclude=/provision_overlay/merged/) ;;
	esac
	mkdir -p "/$path"
	rsync -aH --delete "${excludes[@]}" "$staging/$path/" "/$path/"
done
rm -rf "$staging"

info "Restoring carrier keys"
for id in $needed_keys; do
	install -o root -g www-data -m 0640 "$keys_src/$id.key" "$key_dir/$id.key"
done
if [ -n "$b_active" ]; then
	ln -sfn "$b_active.key" "$key_dir/.active.tmp"
	mv -Tf "$key_dir/.active.tmp" "$key_dir/active"
fi
[ -x /etc/cron.daily/x_carriers-keybackup ] && /etc/cron.daily/x_carriers-keybackup

#settings are cached on disk (cache.method = file) and in APCu (cleared by the php-fpm restart in apply.sh)
find /var/cache/fusionpbx -mindepth 1 -delete 2>/dev/null || true

#===================================================================================================
# 7. reapply our layer (migrates an older schema forward), then start
#===================================================================================================
if [ "$start" = false ]; then
	#enable our timers without starting them; they are disabled below
	X_APPLY_NO_START=1 bash resources/apply.sh upgrade
	#disabled, not just stopped, so a reboot of a drill host does not register the restored gateways
	for unit in /etc/systemd/system/x-*.timer /etc/systemd/system/x-*.path; do
		[ -e "$unit" ] && systemctl disable --now "$(basename "$unit")"
	done
	systemctl stop 'x-*.service' || true
	systemctl disable --now freeswitch "${upstream_units[@]}"
	systemctl start nginx
else
	bash resources/apply.sh upgrade
	info "Starting services"
	systemctl start freeswitch
	for _ in $(seq 60); do
		fs_cli -x status >/dev/null 2>&1 && break
		sleep 1
	done
	fs_cli -x status >/dev/null 2>&1 || warn "FreeSWITCH is not answering on the event socket yet."
	fusionpbx_upgrade --services
	start_units "${stopped_units[@]}"
	systemctl restart nginx
	fs_cli -x reloadxml >/dev/null 2>&1 || true
	fs_cli -x reloadacl >/dev/null 2>&1 || true
	#rebuild the firewall snapshot from the restored database now that FreeSWITCH answers
	if [ -e /etc/systemd/system/x-carrier-acl-worker.service ]; then
		systemctl start x-carrier-acl-worker.service || warn "Carrier ACL worker failed; see journalctl -u x-carrier-acl-worker.service"
	fi
fi

phase=started
info "Checking carrier credential keys"
keyring=$fusionpbx_dir/app/x_carrier_credentials/resources/cli/x_carriers_keyring.php
if [ -f "$keyring" ] && ! (cd "$fusionpbx_dir" && runuser -u www-data -- php "$keyring" check); then
	warn "Carrier credential key check failed. Active SIP credentials can be rebuilt from the gateways with:"
	warn "  cd $fusionpbx_dir && runuser -u www-data -- php $keyring reseal-from-gateways"
	exit 1
fi

phase=finished
info "Restore complete."
info "  Previous database kept as $old_db. Drop it when satisfied:"
info "    runuser -u postgres -- dropdb $old_db"
[ -n "$pre_dir" ] && info "  Previous state backed up to $pre_dir"
[ "$start" = false ] && info "  FreeSWITCH, the FusionPBX services and our timers are stopped and disabled (--no-start). When the original host is off: systemctl enable --now freeswitch, then ./upgrade.sh (enables and starts the FusionPBX services and our timers)."
info "  Log: $log"
