#!/bin/bash
# Post-install checks. Runs inside the test container as root.
set -uo pipefail

fail=0
pass() { printf 'PASS  %s\n' "$1"; }
bad()  { printf 'FAIL  %s\n' "$1"; fail=1; }
check() { local desc=$1; shift; if "$@" >/dev/null 2>&1; then pass "$desc"; else bad "$desc"; fi; }

# shellcheck source=../config.sh
. /usr/src/x-install/config.sh
db() { sudo -u postgres psql -d fusionpbx -qtAX -c "$1"; }

#core
check "FusionPBX at $fusionpbx_commit" test "$(git -c safe.directory=/var/www/fusionpbx -C /var/www/fusionpbx rev-parse HEAD)" = "$fusionpbx_commit"
check "nginx config valid" nginx -t
check "FreeSWITCH responds" fs_cli -x status
check "web UI login page" curl -fskL https://127.0.0.1/

#provisioning enabled once, marker present
check "provision.enabled is on" test "$(db "select default_setting_enabled from v_default_settings where default_setting_category='provision' and default_setting_subcategory='enabled'")" = "t"
check "provision marker present" test "$(db "select count(*) from v_default_settings where default_setting_category='x_install' and default_setting_subcategory='provision_initialized'")" -ge 1

check "provisioning http auth enabled" test "$(db "select count(*) from v_default_settings where default_setting_category='provision' and default_setting_subcategory in ('http_auth_username','http_auth_password') and default_setting_enabled and default_setting_value <> ''")" -ge 2
#upstream answers 404 for unknown MACs before checking auth, so use a throwaway device
test_domain=$(db "select domain_name from v_domains order by domain_name limit 1")
test_device=$(cat /proc/sys/kernel/random/uuid)
db "insert into v_devices (device_uuid, domain_uuid, device_address, device_vendor, device_template, device_enabled) select '$test_device', domain_uuid, '0011223344aa', 'yealink', 'yealink/t54w', true from v_domains where domain_name = '$test_domain'" >/dev/null
check "provisioning requires auth (401)" test "$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: $test_domain" "https://127.0.0.1/app/provision/?mac=0011223344aa&file=0011223344aa.cfg")" = 401
db "delete from v_devices where device_uuid = '$test_device'" >/dev/null

#our apps: copied, syntactically valid on the target PHP, registered
for app in /usr/src/x-install/apps/x_*/; do
	name=$(basename "$app")
	check "$name installed" test -d "/var/www/fusionpbx/app/$name"
	check "$name owned by www-data" test "$(stat -c %U "/var/www/fusionpbx/app/$name")" = www-data
	check "$name has no systemd units under webroot" test ! -d "/var/www/fusionpbx/app/$name/systemd"
	while IFS= read -r f; do
		check "php -l ${f#/var/www/fusionpbx/}" php -l "$f"
	done < <(find "/var/www/fusionpbx/app/$name" -name '*.php')
done

#every table our apps declare exists
while IFS= read -r table; do
	check "table $table exists" test "$(db "select to_regclass('public.$table') is not null")" = "t"
done < <(grep -rhoE "\['db'\]\[\\\$y\]\['table'\]\['name'\] = \"v_x_[a-z0-9_]+\"" /usr/src/x-install/apps | grep -oE 'v_x_[a-z0-9_]+' | sort -u)

#time sync
check "chrony active" systemctl is-active chrony
check "clock sync timer enabled" systemctl is-enabled x-freeswitch-clock-sync.timer
check "clock sync runs" systemctl start x-freeswitch-clock-sync.service

#our timers
for timer in /usr/src/x-install/apps/x_*/systemd/*.timer; do
	[ -f "$timer" ] || continue
	check "$(basename "$timer") enabled" systemctl is-enabled "$(basename "$timer")"
done

#no failed units; fail2ban is reported separately because upstream's jail.local points
#the ssh jail at /var/log/auth.log, which Debian trixie does not write by default
failed=$(systemctl --failed --no-legend --plain | awk '{print $1}' | grep -v '^fail2ban.service$' || true)
check "no failed systemd units (excluding fail2ban)" test -z "$failed"
# shellcheck disable=SC2086  # one line per unit
[ -n "$failed" ] && printf '      failed: %s\n' $failed
if ! systemctl is-active --quiet fail2ban; then
	printf 'WARN  fail2ban not running (upstream ssh jail config); see journalctl -u fail2ban\n'
fi

exit $fail
