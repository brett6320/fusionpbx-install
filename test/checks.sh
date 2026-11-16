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

#phone codec order for the provisioning templates
check "codec order seeded" test "$(db "select string_agg(default_setting_value, ',' order by default_setting_subcategory) from v_default_settings where default_setting_category = 'provision' and default_setting_subcategory like 'x\_codec\__' and default_setting_enabled")" = "G.722,PCMU,PCMA,G.723.1,G.729A/B,G.726-32,iLBC,OPUS"

#dark theme is the default (core/themes: Cobalt Blue)
check "dark theme is the default" test "$(db "select default_setting_value from v_default_settings where default_setting_uuid='b1a99e57-8d4a-4bbf-910f-1f5a86d6532a' and default_setting_enabled")" = "eda762b3-e356-4225-a394-38b5f092fd44"

#upstream's English email templates reworded (x_local_defaults); the fax subject no longer reads "Subject, Fax to: ..."
# shellcheck disable=SC2016  # FreeSWITCH variables, literal
check "voicemail email reworded" test "$(db "select template_subject from v_email_templates where email_template_uuid = 'fbd0c8ea-6adb-4f8b-92cf-00e9087e3568'")" = 'New voicemail from ${caller_id_name} (${caller_id_number}), ${message_duration}'
# shellcheck disable=SC2016
check "fax email reworded" test "$(db "select template_subject from v_email_templates where email_template_uuid = 'b1eefbfc-c008-4c82-b93f-6a6df237aeaa'")" = 'Fax to ${number_dialed} sent'

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

#phone firmware: the catalog matches the pinned templates; a release is linked and served, none is a 404
check "firmware catalog matches the templates" php /usr/src/x-install/test/firmware_catalog.php --check /var/www/fusionpbx/resources/templates/provision
fw_cli=/var/www/fusionpbx/app/x_provision_overlay/resources/cli/firmware.php
fw_rom=$(mktemp -d)/T46S-66.86.0.250.rom
head -c 4096 /dev/urandom > "$fw_rom"
chmod 0755 "$(dirname "$fw_rom")"; chmod 0644 "$fw_rom"
check "firmware import" runuser -u www-data -- php "$fw_cli" --import "$fw_rom" --note checks
fw_uuid=$(db "select firmware_uuid from v_x_provision_overlay_firmware where firmware_family = '66' and firmware_version = '66.86.0.250'")
fw_device=$(cat /proc/sys/kernel/random/uuid)
db "insert into v_devices (device_uuid, domain_uuid, device_address, device_vendor, device_template, device_enabled, device_provisioned_agent) select '$fw_device', domain_uuid, '0011223344ab', 'yealink', 'yealink/t46s', true, 'Yealink SIP-T46S 66.86.0.15 001122334ab' from v_domains where domain_name = '$test_domain'" >/dev/null
db "insert into v_x_provision_overlay_firmware_defaults (firmware_default_uuid, domain_uuid, firmware_vendor, firmware_family, firmware_uuid, insert_date) select '$(cat /proc/sys/kernel/random/uuid)', domain_uuid, 'yealink', '66', '$fw_uuid', now() from v_domains where domain_name = '$test_domain'" >/dev/null
check "firmware sync" runuser -u www-data -- php "$fw_cli" --sync
check "firmware rows written" test "$(db "select count(*) from v_device_settings where device_uuid = '$fw_device' and device_setting_enabled and (device_setting_value = '66.86.0.250.rom' or device_setting_value = 'http://{\$domain_name}/provision-firmware/$fw_uuid')")" -ge 2
fw_url="https://127.0.0.1/provision-firmware/$fw_uuid/66.86.0.250.rom"
check "firmware served (200, same file)" cmp -s "$fw_rom" <(curl -fsk -H "Host: $test_domain" "$fw_url")
check "firmware served as attachment, nosniff" test "$(curl -sk -o /dev/null -D - -H "Host: $test_domain" "$fw_url" | grep -ciE '^(content-type: application/octet-stream|x-content-type-options: nosniff|content-disposition: attachment)')" -ge 3
check "firmware none is 404" test "$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: $test_domain" "https://127.0.0.1/provision-firmware/none/none.rom")" = 404
check "firmware wrong name is 404" test "$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: $test_domain" "https://127.0.0.1/provision-firmware/$fw_uuid/other.rom")" = 404
check "firmware unknown domain is 404" test "$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: unknown.invalid" "$fw_url")" = 404
check "firmware POST refused" test "$(curl -sk -o /dev/null -w '%{http_code}' -X POST -H "Host: $test_domain" "$fw_url")" = 403
check "firmware files not reachable directly" test "$(curl -sk -o /dev/null -w '%{http_code}' -H "Host: $test_domain" "https://127.0.0.1/x-provision-firmware-files/$fw_uuid/66.86.0.250.rom")" = 404
db "delete from v_x_provision_overlay_firmware_defaults where firmware_uuid = '$fw_uuid'" >/dev/null
db "delete from v_devices where device_uuid = '$fw_device'" >/dev/null
check "firmware sync after removal" runuser -u www-data -- php "$fw_cli" --sync
check "firmware rows removed" test "$(db "select count(*) from v_device_settings where device_uuid = '$fw_device'")" = 0
db "delete from v_x_provision_overlay_firmware_links where device_uuid = '$fw_device'; delete from v_x_provision_overlay_firmware_downloads where firmware_uuid = '$fw_uuid'; delete from v_x_provision_overlay_firmware where firmware_uuid = '$fw_uuid'" >/dev/null
rm -rf "/var/lib/fusionpbx/provision_overlay/firmware/$fw_uuid" "$(dirname "$fw_rom")"

#web login lifetime, read the way phpsessionclean reads it
check "php-fpm session lifetime is $session_lifetime" test "$(PHP_INI_SCAN_DIR="/etc/php/$php_version/fpm/conf.d" "php$php_version" -c "/etc/php/$php_version/fpm/php.ini" -r 'echo ini_get("session.gc_maxlifetime");')" = "$session_lifetime"

#time sync
check "chrony active" systemctl is-active chrony
#a container is not EC2, so the Amazon Time Sync Service is not configured
check "no Amazon time source off EC2" test ! -e /etc/chrony/sources.d/x-aws-time.sources
check "clock sync timer enabled" systemctl is-enabled x-freeswitch-clock-sync.timer
check "clock sync runs" systemctl start x-freeswitch-clock-sync.service

#backups
check "x-backup installed" test -x /usr/local/sbin/x-backup
check "x-backup timer enabled" systemctl is-enabled x-backup.timer

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
