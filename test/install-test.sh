#!/bin/bash
# Run the full installer in a throwaway systemd container, then check the result.
# FreeSWITCH builds from source, so expect this to take a long time.
#
# Usage: test/install-test.sh [bookworm|trixie] [--keep]
set -euo pipefail
cd "$(dirname "$0")/.."

release=${1:-trixie}
keep=${2:-}
name=x-fusionpbx-test-$release
image=x-fusionpbx-test:$release

cleanup() {
	[ "$keep" = "--keep" ] && { echo "Container $name kept."; return; }
	docker rm -f "$name" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker build --build-arg DEBIAN_RELEASE="$release" -t "$image" test/
docker rm -f "$name" >/dev/null 2>&1 || true
docker run -d --name "$name" --privileged --cgroupns=host \
	-v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock \
	"$image"

#copy the working tree in (not a mount, so the installer can't touch the host)
docker exec "$name" mkdir -p /usr/src/x-install
COPYFILE_DISABLE=1 tar --no-xattrs --exclude=.git -cf - . | docker exec -i "$name" tar -xf - -C /usr/src/x-install

echo "== preflight refuses when outbound port 80 is blocked, without changing the host"
if docker exec "$name" iptables -I OUTPUT -p tcp --dport 80 -j REJECT 2>/dev/null; then
	if out=$(timeout 600 docker exec "$name" bash /usr/src/x-install/install.sh 2>&1); then
		echo "FAIL  install.sh ran with port 80 blocked"; exit 1
	fi
	docker exec "$name" iptables -D OUTPUT -p tcp --dport 80 -j REJECT
	grep -q "Cannot reach the URLs above" <<<"$out" || { printf '%s\n' "$out" | tail -n 5; echo "FAIL  preflight message"; exit 1; }
	docker exec "$name" bash -c 'test ! -e /var/www/fusionpbx && test ! -e /etc/fusionpbx && test ! -e /usr/src/fusionpbx-install.sh && ! compgen -G "/root/x-install-*.log" >/dev/null' \
		|| { echo "FAIL  preflight changed the host"; exit 1; }
	echo "PASS  preflight refused before changing the host"
else
	echo "WARN  cannot add an iptables rule in this container; preflight refusal not tested"
fi

echo "== install"
docker exec "$name" bash /usr/src/x-install/install.sh

echo "== checks"
docker exec -i "$name" bash -s < test/checks.sh

echo "== upgrade (idempotency)"
docker exec "$name" bash /usr/src/x-install/upgrade.sh
docker exec -i "$name" bash -s < test/checks.sh

echo "== optional apps update as www-data"
#upgrade.sh --optional fails on any pull error; also confirm www-data can still pull each checkout itself
docker exec "$name" bash /usr/src/x-install/upgrade.sh --optional
for repo in $(docker exec "$name" bash -c 'for g in /var/www/fusionpbx/app/*/.git; do [ -d "$g" ] && dirname "$g"; done'); do
	if ! docker exec "$name" runuser -u www-data -- git -C "$repo" fetch --dry-run >/dev/null 2>&1; then
		echo "FAIL  www-data cannot fetch in $repo"; exit 1
	fi
done
echo "PASS  optional apps update as www-data"
docker exec -i "$name" bash -s < test/checks.sh

echo "== theme: Cobalt Blue set by an earlier version moves to Dark; an admin's choice and edits are kept"
theme_db() {
	docker exec "$name" bash -c "cd / && runuser -u postgres -- psql -d fusionpbx -qtAX -c \"$1\""
}
theme_defaults() {
	docker exec "$name" bash -c 'cd /var/www/fusionpbx && php core/upgrade/upgrade.php --defaults' >/dev/null
}
theme_value() {
	theme_db "select default_setting_value from v_default_settings where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a'"
}
#an install from before this change: Cobalt Blue default and its marker, no Dark marker
theme_db "delete from v_default_settings where default_setting_category = 'x_install' and default_setting_subcategory = 'theme_dark_initialized'; insert into v_default_settings (default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled) values (gen_random_uuid(), 'x_install', 'theme_initialized', 'boolean', 'true', false); update v_default_settings set default_setting_value = 'eda762b3-e356-4225-a394-38b5f092fd44' where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a'" >/dev/null
theme_defaults
[ "$(theme_value)" = 56c90fe5-8216-47e9-a846-1c72c5f19528 ] || { echo "FAIL  Cobalt Blue default not moved to Dark ($(theme_value))"; exit 1; }
#an admin who chose another theme keeps it
theme_db "delete from v_default_settings where default_setting_category = 'x_install' and default_setting_subcategory = 'theme_dark_initialized'; update v_default_settings set default_setting_value = '7ca4a961-2b70-457d-93b8-026d687a9741' where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a'" >/dev/null
theme_defaults
[ "$(theme_value)" = 7ca4a961-2b70-457d-93b8-026d687a9741 ] || { echo "FAIL  admin's theme overwritten ($(theme_value))"; exit 1; }
#Cobalt Blue chosen by an admin (no earlier marker) is kept too
theme_db "delete from v_default_settings where default_setting_category = 'x_install' and default_setting_subcategory in ('theme_initialized', 'theme_dark_initialized'); update v_default_settings set default_setting_value = 'eda762b3-e356-4225-a394-38b5f092fd44' where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a'" >/dev/null
theme_defaults
[ "$(theme_value)" = eda762b3-e356-4225-a394-38b5f092fd44 ] || { echo "FAIL  admin's Cobalt Blue overwritten ($(theme_value))"; exit 1; }
#an admin's edit of a Dark setting is kept
theme_db "update v_theme_settings set theme_setting_value = '#123456' where theme_uuid = '56c90fe5-8216-47e9-a846-1c72c5f19528' and theme_setting_name = 'body_color'" >/dev/null
theme_defaults
[ "$(theme_db "select string_agg(theme_setting_value, ',') from v_theme_settings where theme_uuid = '56c90fe5-8216-47e9-a846-1c72c5f19528' and theme_setting_name = 'body_color'")" = '#123456' ] || { echo "FAIL  admin's theme setting overwritten or duplicated"; exit 1; }
#with the Dark marker set it runs no more: an empty value stays empty
theme_db "update v_default_settings set default_setting_value = '' where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a'" >/dev/null
theme_defaults
[ "$(theme_value)" = "" ] || { echo "FAIL  default changed again with the Dark marker set ($(theme_value))"; exit 1; }
#an earlier install where an admin cleared and disabled our Cobalt Blue default keeps it empty and disabled
theme_db "delete from v_default_settings where default_setting_category = 'x_install' and default_setting_subcategory = 'theme_dark_initialized'; insert into v_default_settings (default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled) values (gen_random_uuid(), 'x_install', 'theme_initialized', 'boolean', 'true', false); update v_default_settings set default_setting_value = '', default_setting_enabled = false where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a'" >/dev/null
theme_defaults
theme_row=$(theme_db "select coalesce(default_setting_value, 'null') || ',' || case when default_setting_enabled then 'enabled' else 'disabled' end from v_default_settings where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a'")
[ "$theme_row" = ",disabled" ] || { echo "FAIL  admin's cleared, disabled default changed ($theme_row)"; exit 1; }
#back to the installed state for what follows
theme_db "update v_theme_settings set theme_setting_value = '#1f2937' where theme_uuid = '56c90fe5-8216-47e9-a846-1c72c5f19528' and theme_setting_name = 'body_color'; update v_default_settings set default_setting_value = '56c90fe5-8216-47e9-a846-1c72c5f19528', default_setting_enabled = true where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a'" >/dev/null
docker exec "$name" bash -c 'systemctl restart "php$(php -r "echo PHP_MAJOR_VERSION.\".\".PHP_MINOR_VERSION;")-fpm"'
echo "PASS  theme default moved once, admin choices and edits kept"
docker exec -i "$name" bash -s < test/checks.sh

echo "== Amazon Time Sync Service on EC2 only"
docker exec -e X_IS_EC2=1 "$name" bash /usr/src/x-install/resources/timesync.sh >/dev/null
docker exec "$name" grep -qx 'server 169.254.169.123 prefer iburst minpoll 4 maxpoll 4' /etc/chrony/sources.d/x-aws-time.sources || { echo "FAIL  Amazon time source not written on EC2"; exit 1; }
grep -q 169.254.169.123 <<<"$(docker exec "$name" chronyc -n sources)" || { echo "FAIL  chrony did not load the Amazon time source"; exit 1; }
#an admin's own line for it wins
docker exec "$name" bash -c 'echo "server 169.254.169.123 iburst" > /etc/chrony/sources.d/aws.sources'
docker exec -e X_IS_EC2=1 "$name" bash /usr/src/x-install/resources/timesync.sh >/dev/null
docker exec "$name" test ! -e /etc/chrony/sources.d/x-aws-time.sources || { echo "FAIL  Amazon time source duplicated"; exit 1; }
docker exec "$name" rm -f /etc/chrony/sources.d/aws.sources
docker exec -e X_IS_EC2=1 "$name" bash /usr/src/x-install/resources/timesync.sh >/dev/null
docker exec "$name" bash /usr/src/x-install/resources/timesync.sh >/dev/null
docker exec "$name" test ! -e /etc/chrony/sources.d/x-aws-time.sources || { echo "FAIL  Amazon time source kept off EC2"; exit 1; }
echo "PASS  Amazon Time Sync Service on EC2 only"

echo "== AWS: prefix list feeds and the Elastic IP as external address"
docker exec "$name" bash -c 'cat > /etc/default/fusionpbx-aws <<CONF
AWS_REGION=us-east-1
AWS_STACK_NAME=pbx-test
INSTANCE_ID=i-0123456789abcdef0
ELASTIC_IP=203.0.113.10
ELASTIC_IP_ALLOCATION_ID=eipalloc-0123456789abcdef0
TELNYX_SIGNALLING_PREFIX_LIST_ID=pl-0123456789abcdef0
TELNYX_MEDIA_PREFIX_LIST_ID=pl-0fedcba9876543210
SSH_PREFIX_LIST_ID=pl-0aaaaaaaaaaaaaaa1
WEB_PREFIX_LIST_ID=pl-0aaaaaaaaaaaaaaa2
ENDPOINT_PREFIX_LIST_ID=pl-0aaaaaaaaaaaaaaa3
CONF'
docker exec -e X_AWS_SYNC_CMD=/usr/src/x-install/test/fake-x-aws-sync "$name" bash /usr/src/x-install/resources/aws.sh install
psql_value() {
	docker exec "$name" bash -c "cd / && runuser -u postgres -- psql -d fusionpbx -qtAX -c \"$1\""
}
docker exec "$name" grep -q 'data="external_sip_ip=203.0.113.10"' /etc/freeswitch/vars.xml || { echo "FAIL  vars.xml external_sip_ip"; exit 1; }
docker exec "$name" grep -q 'data="external_rtp_ip=203.0.113.10"' /etc/freeswitch/vars.xml || { echo "FAIL  vars.xml external_rtp_ip"; exit 1; }
[ "$(docker exec "$name" fs_cli -x 'global_getvar external_sip_ip' | tr -d '[:space:]')" = 203.0.113.10 ] || { echo "FAIL  FreeSWITCH external_sip_ip"; exit 1; }
echo "PASS  vars.xml and FreeSWITCH use the Elastic IP"
docker exec "$name" grep -q 'data="outbound_codec_prefs=G722,PCMU,PCMA"' /etc/freeswitch/vars.xml || { echo "FAIL  vars.xml outbound_codec_prefs"; exit 1; }
docker exec "$name" fs_cli -x 'global_getvar outbound_codec_prefs' | tr -d '[:space:]' | grep -qx 'G722,PCMU,PCMA' || { echo "FAIL  FreeSWITCH outbound_codec_prefs"; exit 1; }
echo "PASS  outbound codecs G722,PCMU,PCMA"
entries=$(psql_value "select e.entry_role || ':' || count(*) from v_x_carrier_acl_entries e join v_x_carrier_acl_feeds f using (x_carrier_acl_feed_uuid) where f.feed_url like 'file://%' group by e.entry_role order by 1")
[ "$entries" = "$(printf 'media:2\nsignaling:2')" ] || { echo "FAIL  AWS feed entries: $entries"; exit 1; }
echo "PASS  AWS prefix lists applied to the carrier ACL"
jail=/etc/fail2ban/jail.d/x-aws-ignoreip.local
docker exec "$name" grep -qx 'ignoreip = 127.0.0.1/8 ::1 192.0.2.0/24 198.51.100.0/24 203.0.113.64/26' "$jail" || { docker exec "$name" cat "$jail"; echo "FAIL  Fail2ban whitelist"; exit 1; }
#a list dropped from the settings file leaves the whitelist
docker exec "$name" sed -i '/^WEB_PREFIX_LIST_ID=/d' /etc/default/fusionpbx-aws
docker exec -e X_AWS_SYNC_CMD=/usr/src/x-install/test/fake-x-aws-sync "$name" bash /usr/src/x-install/resources/aws.sh upgrade >/dev/null
docker exec "$name" grep -qx 'ignoreip = 127.0.0.1/8 ::1 192.0.2.0/24 198.51.100.0/24' "$jail" || { docker exec "$name" cat "$jail"; echo "FAIL  Fail2ban whitelist after removing a list"; exit 1; }
#the real helper skips networks broader than /16
#(captured first: with pipefail, grep -q closing the pipe early would fail the pipeline)
out=$(docker exec "$name" bash -c 'printf "%s\n" "{\"entries\":{\"0.0.0.0/0\":{\"cidr\":\"0.0.0.0/0\"}}}" > /var/lib/x_carrier_acl/aws/allow-test.json && /usr/local/sbin/x-fail2ban-ignoreip; rm -f /var/lib/x_carrier_acl/aws/allow-test.json' 2>&1) || true
grep -q "0.0.0.0/0 in allow-test.json is broader" <<<"$out" || { printf '%s\n' "$out"; echo "FAIL  broad network not reported"; exit 1; }
! docker exec "$name" grep -q '0\.0\.0\.0/0' "$jail" || { echo "FAIL  0.0.0.0/0 whitelisted"; exit 1; }
#the unit runs the helper after every sync (ExecStopPost=+, outside the unit's sandbox), whatever the sync's result
docker exec "$name" systemctl start x-aws-sync.service || true
log=$(docker exec "$name" journalctl -u x-aws-sync.service --no-pager -n 50)
grep -q "x-fail2ban-ignoreip:" <<<"$log" || { echo "FAIL  x-aws-sync.service did not run x-fail2ban-ignoreip"; echo "$log"; exit 1; }
! grep -q -E "Address family not supported|Read-only file system|Permission denied" <<<"$log" || { echo "FAIL  x-fail2ban-ignoreip ran inside the unit's sandbox"; echo "$log"; exit 1; }
echo "PASS  Fail2ban whitelist from the SSH, web and endpoint lists"
#instance metadata: www-data is refused by our rule (its counter moves); root is not matched (counter unchanged).
#There is no metadata service in a container, so only the rule's counter tells the cases apart.
docker exec "$name" /usr/local/sbin/x-imds-guard check || { echo "FAIL  metadata guard not applied"; exit 1; }
imds_hits() {
	docker exec "$name" bash -c 'if command -v nft >/dev/null; then nft list table inet x_imds_guard | sed -n "s/.*ip daddr 169.254.169.254.*counter packets \([0-9]*\).*/\1/p"; else iptables -w -vnxL OUTPUT | awk "/x_imds_guard/ && /169.254.169.254/ {print \$1}"; fi' | head -n 1
}
before=$(imds_hits)
docker exec "$name" curl -s -m 3 -o /dev/null http://169.254.169.254/ || true
[ "$(imds_hits)" = "$before" ] || { echo "FAIL  root matched the metadata guard"; exit 1; }
rc=0; docker exec "$name" runuser -u www-data -- curl -s -m 3 -o /dev/null http://169.254.169.254/ || rc=$?
[ "$rc" -eq 7 ] && [ "$(imds_hits)" -gt "$before" ] || { echo "FAIL  www-data not refused by the metadata guard (curl exit $rc)"; exit 1; }
echo "PASS  instance metadata limited to root"

#an admin's own value is kept, and a deleted feed is not recreated
psql_value "update v_vars set var_value = '198.51.100.7' where var_name = 'external_rtp_ip'" >/dev/null
psql_value "delete from v_x_carrier_acl_entries where x_carrier_acl_feed_uuid = '5a9e3c72-1b4d-4f60-8e2a-7c3b9d1e5f02'; delete from v_x_carrier_acl_runs where x_carrier_acl_feed_uuid = '5a9e3c72-1b4d-4f60-8e2a-7c3b9d1e5f02'; delete from v_x_carrier_acl_feeds where x_carrier_acl_feed_uuid = '5a9e3c72-1b4d-4f60-8e2a-7c3b9d1e5f02'" >/dev/null
docker exec -e X_AWS_SYNC_CMD=/usr/src/x-install/test/fake-x-aws-sync "$name" bash /usr/src/x-install/resources/aws.sh upgrade
[ "$(psql_value "select var_value from v_vars where var_name = 'external_rtp_ip'")" = 198.51.100.7 ] || { echo "FAIL  admin external_rtp_ip overwritten"; exit 1; }
docker exec "$name" grep -q 'data="external_rtp_ip=198.51.100.7"' /etc/freeswitch/vars.xml || { echo "FAIL  vars.xml lost the admin external_rtp_ip"; exit 1; }
[ "$(psql_value "select count(*) from v_x_carrier_acl_feeds where feed_url like 'file://%'")" = 1 ] || { echo "FAIL  deleted AWS feed recreated"; exit 1; }
#with FreeSWITCH running the admin's rtp address, only the managed external_sip_ip is checked: no restart warning
docker exec "$name" systemctl restart freeswitch
for _ in $(seq 30); do docker exec "$name" fs_cli -x status >/dev/null 2>&1 && break; sleep 2; done
out=$(docker exec -e X_AWS_SYNC_CMD=/usr/src/x-install/test/fake-x-aws-sync "$name" bash /usr/src/x-install/resources/aws.sh upgrade 2>&1)
if grep -qE "still running with the old value|does not use it yet" <<<"$out"; then
	printf '%s\n' "$out"; echo "FAIL  restart warning for a variable the installer does not manage"; exit 1
fi
echo "PASS  admin values and deletions kept on upgrade"

#an invalid file configures nothing and removes the timer
docker exec "$name" sed -i 's/^INSTANCE_ID=.*/INSTANCE_ID=not-an-instance/' /etc/default/fusionpbx-aws
out=$(docker exec -e X_AWS_SYNC_CMD=/usr/src/x-install/test/fake-x-aws-sync "$name" bash /usr/src/x-install/resources/aws.sh upgrade 2>&1)
grep -q "is not valid, so nothing was configured from AWS" <<<"$out" || { printf '%s\n' "$out"; echo "FAIL  invalid AWS settings not refused"; exit 1; }
docker exec "$name" test ! -e /etc/systemd/system/x-aws-sync.timer || { echo "FAIL  x-aws-sync.timer left installed"; exit 1; }
echo "PASS  invalid AWS settings configure nothing"
