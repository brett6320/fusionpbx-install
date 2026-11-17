#!/bin/bash
# Run the full installer in a throwaway systemd container, then check the result.
# FreeSWITCH builds from source, so a full run takes a while (about 10 minutes).
#
# Usage: test/install-test.sh [trixie] [--keep] [--quick] [--rebuild-base]
#   --quick         start from a saved base image (upstream's installer already run, our
#                   layer not), then apply our layer and run the same checks. The preflight
#                   refusal stage is skipped. README "Testing" says which run a PR needs before merging.
#   --rebuild-base  with --quick, rebuild the base image even if one matches.
set -euo pipefail
cd "$(dirname "$0")/.."

release=trixie keep='' quick='' rebuild_base=''
for arg in "$@"; do
	case "$arg" in
		trixie) release=$arg ;;
		--keep) keep=--keep ;;
		--quick) quick=1 ;;
		--rebuild-base) rebuild_base=1 ;;
		*) echo "Usage: $0 [trixie] [--keep] [--quick] [--rebuild-base]" >&2; exit 2 ;;
	esac
done
[ -n "$rebuild_base" ] && [ -z "$quick" ] && echo "WARN  --rebuild-base has no effect without --quick"
name=x-fusionpbx-test-$release
image=x-fusionpbx-test:$release

#stage timings: each stage prints the previous one's duration; the table is printed on exit, pass or fail
stages=() stage_name='' stage_start=''
run_start=$(date +%s)
stage_end() {
	[ -n "$stage_name" ] || return 0
	stages+=("$(printf '%6ss  %s' "$(($(date +%s) - stage_start))" "$stage_name")")
	stage_name=
}
stage() {
	stage_end
	stage_name=$1 stage_start=$(date +%s)
	echo "== $1"
}
timings() {
	stage_end
	echo "== timings"
	printf '%s\n' "${stages[@]}"
	#phases inside install.sh, from its "Timing:" lines; a full install that failed has not set install_log
	if [ -z "${install_log:-}" ] && [ -z "$quick" ]; then
		install_log=$(latest_log "$name" 2>/dev/null) || install_log=''
	fi
	if [ -n "${install_log:-}" ]; then
		docker exec "$name" bash -c "cat $install_log" 2>/dev/null | sed -n 's/.*Timing: \(.*\) \([0-9]*\)$/\1 \2/p' | awk '
			NR > 1 && p != "done" { printf "%6ss    install: %s\n", $NF - t, p }
			{ t = $NF; $NF = ""; sub(/ (start )?$/, ""); p = $0 }'
	fi
	printf '%6ss  total\n' "$(($(date +%s) - run_start))"
}

cleanup() {
	local rc=$?
	timings || true
	if [ "$keep" = "--keep" ]; then echo "Container $name kept."; else docker rm -f "$name" >/dev/null 2>&1 || true; fi
	if [ -n "${base_builder:-}" ]; then
		if [ "$keep" = "--keep" ]; then echo "Base builder $base_builder kept."; else docker rm -f "$base_builder" >/dev/null 2>&1 || true; fi
	fi
	return $rc
}
trap cleanup EXIT

#copy the working tree in (not a mount, so the installer can't touch the host)
copy_tree() {
	docker exec "$1" bash -c 'rm -rf /usr/src/x-install && mkdir -p /usr/src/x-install'
	COPYFILE_DISABLE=1 tar --no-xattrs --exclude=.git -cf - . | docker exec -i "$1" tar -xf - -C /usr/src/x-install
}
latest_log() {
	docker exec "$1" bash -c 'ls -t /root/x-install-*.log | head -n 1'
}

stage "image"
docker build --build-arg DEBIAN_RELEASE="$release" -t "$image" test/
docker rm -f "$name" >/dev/null 2>&1 || true
run_opts=(--privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock)

if [ -n "$quick" ]; then
	#upstream names the domain after the host address, so the base and every quick run share a
	#fixed address and hostname on their own network
	net=x-fusionpbx-test net_host=x-fusionpbx-test net_ip=172.31.250.10
	docker network create --subnet 172.31.250.0/24 "$net" >/dev/null 2>&1 \
		|| docker network inspect "$net" >/dev/null
	run_opts+=(--network "$net" --ip "$net_ip" --hostname "$net_host")

	#the base depends on everything that runs before our layer: the release and test/Dockerfile, the address
	#and hostname, config.sh (all of it), install.sh, upstream.sh and common.sh. not the test image's id:
	#other checkouts rebuild that tag; newer Debian packages are picked up with --rebuild-base
	key=$(cat <(printf '%s\n' "$release" "$net_ip" "$net_host") test/Dockerfile config.sh install.sh resources/upstream.sh resources/common.sh \
		| shasum -a 256 | cut -c1-12)
	base=x-fusionpbx-base:$release-$key

	stage "base image"
	if [ -n "$rebuild_base" ] || ! docker image inspect "$base" >/dev/null 2>&1; then
		echo "Building $base (runs upstream's installer, several minutes)"
		base_builder=x-fusionpbx-base-build-$release
		docker rm -f "$base_builder" >/dev/null 2>&1 || true
		docker run -d --name "$base_builder" "${run_opts[@]}" "$image" >/dev/null
		copy_tree "$base_builder"
		docker exec -e X_INSTALL_STOP_AFTER_UPSTREAM=1 "$base_builder" bash /usr/src/x-install/install.sh
		#stop systemd cleanly so PostgreSQL is committed shut down, not mid-write
		docker stop -t 120 "$base_builder" >/dev/null
		old_base=$(docker image inspect -f '{{.Id}}' "$base" 2>/dev/null) || old_base=''
		docker commit -c 'CMD ["/sbin/init"]' "$base_builder" "$base" >/dev/null
		docker rm -f "$base_builder" >/dev/null
		base_builder=
		#--rebuild-base re-tags the same name; drop the image it replaced
		[ -z "$old_base" ] || docker rmi "$old_base" >/dev/null 2>&1 || true
	else
		echo "Reusing $base"
	fi
	#keep the two newest base images for this release
	docker images x-fusionpbx-base --format '{{.CreatedAt}}\t{{.Repository}}:{{.Tag}}' | grep -F ":$release-" | sort -r \
		| cut -f2 | tail -n +3 | grep -vxF "$base" | xargs -r docker rmi >/dev/null 2>&1 || true

	docker run -d --name "$name" "${run_opts[@]}" "$base"
	copy_tree "$name"

	echo "== preflight refusal: SKIPPED (quick mode: the base is not a pristine host; the full run tests it)"
	#a full run refuses when upstream's branch has moved past the pin (install.sh); so does a quick one
	docker exec "$name" bash -c '. /usr/src/x-install/config.sh
		head=$(git ls-remote "$fusionpbx_repo" "refs/heads/$fusionpbx_branch" | cut -f1)
		[ -n "$head" ] || { echo "FAIL  cannot read upstream branch $fusionpbx_branch"; exit 1; }
		[ "$head" = "$fusionpbx_commit" ] || { echo "FAIL  upstream $fusionpbx_branch is at $head, not the pinned $fusionpbx_commit"; exit 1; }'

	stage "install (our layer, from the base image)"
	#systemd boots the services upstream enabled; wait for them before applying our layer
	for _ in $(seq 60); do
		docker exec "$name" bash -c '. /usr/src/x-install/config.sh
			for u in postgresql freeswitch nginx "php${php_version}-fpm"; do systemctl is-active -q "$u" || exit 1; done
			fs_cli -x status' >/dev/null 2>&1 && break
		sleep 2
	done
	docker exec "$name" bash -c '. /usr/src/x-install/config.sh
		for u in postgresql freeswitch nginx "php${php_version}-fpm"; do systemctl is-active -q "$u" || { echo "FAIL  $u not active in the base"; exit 1; }; done
		fs_cli -x status >/dev/null || { echo "FAIL  FreeSWITCH not answering in the base"; exit 1; }'
	#the rest of install.sh: our layer, logged privately like install.sh does
	install_log=/root/x-install-layer-$(date +%Y%m%d-%H%M%S).log
	docker exec "$name" bash -c "set -o pipefail; install -m 0600 /dev/null $install_log && cd /usr/src/x-install && . resources/common.sh \
		&& { info \"Timing: layer start \$(date +%s)\"; bash resources/apply.sh install || exit 1; info \"Timing: done \$(date +%s)\"; } 2>&1 | tee -a $install_log"
else
	docker run -d --name "$name" "${run_opts[@]}" "$image"
	copy_tree "$name"

	stage "preflight refuses when outbound port 80 is blocked, without changing the host"
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

	stage "install"
	docker exec "$name" bash /usr/src/x-install/install.sh
	install_log=$(latest_log "$name")
fi

stage "checks"
docker exec -i "$name" bash -s < test/checks.sh

stage "upgrade (idempotency)"
docker exec "$name" bash /usr/src/x-install/upgrade.sh
docker exec -i "$name" bash -s < test/checks.sh

stage "optional apps update as www-data"
#upgrade.sh --optional fails on any pull error; also confirm www-data can still pull each checkout itself
docker exec "$name" bash /usr/src/x-install/upgrade.sh --optional
for repo in $(docker exec "$name" bash -c 'for g in /var/www/fusionpbx/app/*/.git; do [ -d "$g" ] && dirname "$g"; done'); do
	if ! docker exec "$name" runuser -u www-data -- git -C "$repo" fetch --dry-run >/dev/null 2>&1; then
		echo "FAIL  www-data cannot fetch in $repo"; exit 1
	fi
done
echo "PASS  optional apps update as www-data"
docker exec -i "$name" bash -s < test/checks.sh

stage "theme: Cobalt Blue set by an earlier version moves to Dark; an admin's choice and edits are kept"
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

stage "Amazon Time Sync Service on EC2 only"
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

stage "AWS: prefix list feeds and the Elastic IP as external address"
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
