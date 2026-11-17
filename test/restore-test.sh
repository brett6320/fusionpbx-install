#!/bin/bash
# Backup and restore test in throwaway systemd containers.
#
#   A: install, seed data, back up; check that bad bundles are refused without changes; then a same-host
#      restore (mutate, restore, the mutation is gone and the pre-restore backup exists).
#   B: a separate fresh install (its own passwords and keys) that restores A's bundle, as after host loss.
#
# Both installs build FreeSWITCH from source; they run in parallel. Expect this to take a long time.
#
# Usage: test/restore-test.sh [trixie] [--keep]
# shellcheck disable=SC2016  # single-quoted commands expand inside the containers
set -euo pipefail
cd "$(dirname "$0")/.."
. ./config.sh

release=${1:-trixie}
[ "$release" = trixie ] || { echo "Usage: $0 [trixie] [--keep]" >&2; exit 2; }
keep=${2:-}
image=x-fusionpbx-test:$release
a=x-fusionpbx-restore-a-$release
b=x-fusionpbx-restore-b-$release
logs=$(mktemp -d)

# shellcheck disable=SC2329  # called by the EXIT trap
cleanup() {
	[ "$keep" = "--keep" ] && { echo "Containers $a and $b kept. Install logs: $logs"; return; }
	docker rm -f "$a" "$b" >/dev/null 2>&1 || true
	rm -rf "$logs"
}
trap cleanup EXIT

fail=0
pass() { printf 'PASS  %s\n' "$1"; }
bad()  { printf 'FAIL  %s\n' "$1"; fail=1; }
check() { local desc=$1; shift; if "$@" >/dev/null 2>&1; then pass "$desc"; else bad "$desc"; fi; }

start() {
	docker rm -f "$1" >/dev/null 2>&1 || true
	docker run -d --name "$1" --privileged --cgroupns=host \
		-v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock \
		"$image" >/dev/null
	docker exec "$1" mkdir -p /usr/src/x-install
	COPYFILE_DISABLE=1 tar --no-xattrs --exclude=.git -cf - . | docker exec -i "$1" tar -xf - -C /usr/src/x-install
}
in_a() { docker exec "$a" bash -c "$1"; }
in_b() { docker exec "$b" bash -c "$1"; }
db_a() { docker exec "$a" sudo -u postgres psql -d fusionpbx -qtAX -c "$1"; }
db_b() { docker exec "$b" sudo -u postgres psql -d fusionpbx -qtAX -c "$1"; }
marker_count() { "$1" "select count(*) from v_default_settings where default_setting_category = 'x_restore_test' and default_setting_subcategory = '$2'"; }
seed() { docker exec "$1" bash -c "cd /var/www/fusionpbx && runuser -u www-data -- php /usr/src/x-install/test/restore_seed.php $2"; }

docker build --build-arg DEBIAN_RELEASE="$release" -t "$image" test/
start "$a"
start "$b"

echo "== install A and B (logs in $logs)"
docker exec "$a" bash /usr/src/x-install/install.sh > "$logs/a.log" 2>&1 & pid_a=$!
docker exec "$b" bash /usr/src/x-install/install.sh > "$logs/b.log" 2>&1 & pid_b=$!
rc_a=0; rc_b=0
wait "$pid_a" || rc_a=$?
wait "$pid_b" || rc_b=$?
[ "$rc_a" -eq 0 ] && [ "$rc_b" -eq 0 ] || { tail -n 40 "$logs/a.log" "$logs/b.log"; echo "install failed (A $rc_a, B $rc_b)"; exit 1; }

echo "== seed and back up A"
seed "$a" original
in_a 'domain=$(sudo -u postgres psql -d fusionpbx -qtAX -c "select domain_name from v_domains limit 1"); install -d -o www-data -g www-data /var/lib/freeswitch/recordings/$domain; echo original > /var/lib/freeswitch/recordings/$domain/x-restore-test.txt; chown www-data:www-data /var/lib/freeswitch/recordings/$domain/x-restore-test.txt'
in_a '/usr/local/sbin/x-backup'
bundle=$(in_a 'find /var/backups/x-install -mindepth 1 -maxdepth 1 -type d -name "20*Z" | sort | tail -n 1')
check "x-backup wrote a bundle ($bundle)" test -n "$bundle"
check "bundle is root-only" in_a "test \"\$(stat -c %a $bundle)\" = 700 && test -z \"\$(find $bundle -type f ! -perm 600)\""
check "manifest lists carrier key ids" in_a "grep -q '^key_ids=k' $bundle/manifest"

echo "== copy the bundle and keys to B"
docker exec "$a" tar -C / -cf - "${bundle#/}" var/backups/x_carriers-keys | docker exec -i "$b" bash -c 'mkdir -p /root/from-a && tar -C /root/from-a -xf -'
b_bundle=/root/from-a$bundle
b_keys=/root/from-a/var/backups/x_carriers-keys
b_domains_before=$(db_b "select string_agg(domain_name, ',') from v_domains")

echo "== refusals on B (nothing may change)"
refused() {
	local desc=$1 cmd=$2
	if in_b "$cmd" >/dev/null 2>&1; then
		bad "refused: $desc"
	elif [ "$(db_b "select string_agg(domain_name, ',') from v_domains")" = "$b_domains_before" ] \
		&& in_b 'systemctl is-active --quiet freeswitch'; then
		pass "refused: $desc"
	else
		bad "refused without changes: $desc"
	fi
}
in_b "cp -a $b_bundle /root/tampered && echo x >> /root/tampered/files.tar.gz"
refused "checksum mismatch" "/usr/src/x-install/restore.sh /root/tampered --keys $b_keys --yes --no-pre-backup --accept-domain-mismatch"
in_b "cp -a $b_bundle /root/newer && sed -i 's/^fusionpbx_commit=.*/fusionpbx_commit=$(printf 'f%.0s' {1..40})/' /root/newer/manifest && (cd /root/newer && sha256sum manifest database.dump files.tar.gz > SHA256SUMS)"
refused "unknown or newer FusionPBX commit" "/usr/src/x-install/restore.sh /root/newer --keys $b_keys --yes --no-pre-backup --accept-domain-mismatch"
in_b "mkdir -p /root/nokeys"
refused "missing carrier key" "/usr/src/x-install/restore.sh $b_bundle --keys /root/nokeys --yes --no-pre-backup --accept-domain-mismatch"
refused "IP address domain not on this host" "/usr/src/x-install/restore.sh $b_bundle --keys $b_keys --yes --no-pre-backup"

echo "== failure after services stop on B (host unchanged, services back)"
in_b "cp -a $b_bundle /root/truncated && truncate -s 1024 /root/truncated/database.dump && (cd /root/truncated && sha256sum manifest database.dump files.tar.gz > SHA256SUMS)"
check "truncated dump fails" bash -c "! docker exec $b /usr/src/x-install/restore.sh /root/truncated --keys $b_keys --yes --no-pre-backup --accept-domain-mismatch"
check "B's database is unchanged" test "$(db_b "select string_agg(domain_name, ',') from v_domains")" = "$b_domains_before"
check "B's database accepts connections" test "$(db_b "select datallowconn from pg_database where datname = 'fusionpbx'")" = t
check "fusionpbx_restore is gone" test "$(db_b "select count(*) from pg_database where datname = 'fusionpbx_restore'")" = 0
check "FreeSWITCH is running again" in_b 'systemctl is-active --quiet freeswitch'
check "php-fpm and nginx are running again" in_b "systemctl is-active --quiet php${php_version}-fpm nginx"
check "upstream services are running again" in_b 'for u in $(find /var/www/fusionpbx/app /var/www/fusionpbx/core -path "*/resources/service/*" -name "*.service" ! -path "*/app/x_*" -printf "%f\n" | sort -u); do systemctl is-enabled --quiet $u 2>/dev/null || continue; systemctl is-active --quiet $u || exit 1; done'

echo "== restore A's bundle on B (host loss)"
if in_b "/usr/src/x-install/restore.sh $b_bundle --keys $b_keys --yes --no-pre-backup --accept-domain-mismatch"; then
	pass "restore on B"
else
	bad "restore on B"
fi
check "B has A's data" test "$(marker_count db_b original)" = 1
check "B has A's recordings, owned by www-data" \
	in_b 'test "$(cat /var/lib/freeswitch/recordings/*/x-restore-test.txt)" = original && test "$(stat -c %U /var/lib/freeswitch/recordings/*/x-restore-test.txt)" = www-data'
check "B decrypts A's carrier credentials" \
	in_b 'cd /var/www/fusionpbx && runuser -u www-data -- php app/x_carrier_credentials/resources/cli/x_carriers_keyring.php check'
check "B kept its previous database" test "$(db_b "select count(*) from pg_database where datname like 'fusionpbx_pre_restore_%'")" = 1
echo "== checks on B"
docker exec -i "$b" bash -s < test/checks.sh || fail=1

echo "== same-host restore on A"
seed "$a" after_backup
db_a "delete from v_default_settings where default_setting_category = 'x_restore_test' and default_setting_subcategory = 'original'" >/dev/null
if in_a "/usr/src/x-install/restore.sh $bundle --yes"; then
	pass "restore on A"
else
	bad "restore on A"
fi
check "A's deleted data is back" test "$(marker_count db_a original)" = 1
check "A's later change is gone" test "$(marker_count db_a after_backup)" = 0
check "A has a pre-restore backup" \
	in_a 'test -n "$(find /var/backups/x-install/pre-restore -mindepth 1 -maxdepth 1 -type d -name "20*Z")"'
echo "== checks on A"
docker exec -i "$a" bash -s < test/checks.sh || fail=1

exit $fail
