#!/bin/bash
# Host setup for x_carrier_acl (docs/design/carrier_acl.md section 10, Installer contract).
#
# Invoked by resources/apply.sh as "bash resources/apps/carrier_acl.sh install|upgrade" after the apps are copied
# and before upgrade.php runs. Idempotent. Does not run upgrade.php and does not enable the worker timer or the
# worker path unit (both start the worker, which needs the app tables; apply.sh enables them after --schema).
#
# Steps: guards, packages and kernel modules, root applier, directories, units (incl. .path units and drop-ins),
# private DB/ESL/DNS allowance for the worker sandbox, ACL list + SIP profile attachment (upstream tables only),
# privilege-split smoke test, enable the applier.
set -euo pipefail
cd "$(dirname "$0")/../.."
. ./resources/common.sh

mode=${1:-}
case "$mode" in
	install|upgrade) ;;
	*) die "Usage: $0 install|upgrade" ;;
esac

src=apps/x_carrier_acl
app_dir=$fusionpbx_dir/app/x_carrier_acl
unit_dir=/etc/systemd/system
php=/usr/bin/php

[ -d "$src" ] || die "x_carrier_acl: $src not found."
[ -d "$app_dir" ] || die "x_carrier_acl: $app_dir not found (apps must be copied before this hook)."

as_www() {
	(cd "$fusionpbx_dir" && runuser -u www-data -- "$@")
}

#-------------------------------------------------------------------------------------------------------------------
# 0. guards: upstream services.php installs and enables app/*/resources/service/**/*.service as root, so no app of
#    ours may ship anything there, and no unit file may live in the www-data owned webroot at all
#-------------------------------------------------------------------------------------------------------------------
for dir in apps/x_*/ "$fusionpbx_dir"/app/x_*/; do
	[ -d "$dir" ] || continue
	if [ -e "${dir}resources/service" ]; then
		die "x_carrier_acl: ${dir}resources/service exists; units must ship from apps/x_*/systemd/ (upstream services.php would install them as root)."
	fi
done
found=$(find "$fusionpbx_dir"/app/x_*/ \( -name '*.service' -o -name '*.timer' -o -name '*.path' -o -name '*.socket' \) -print -quit 2>/dev/null || true)
[ -z "$found" ] || die "x_carrier_acl: unit file found under the webroot: $found"
if [ -e "$app_dir/sbin" ]; then
	#the root applier must not live under the webroot; resources/apply.sh should rsync with --exclude 'sbin/'
	warn "x_carrier_acl: $app_dir/sbin was copied into the webroot; removing it. Add --exclude 'sbin/' to the rsync in resources/apply.sh."
	rm -f "$app_dir/sbin/x-carrier-acl-apply"
	rmdir "$app_dir/sbin"
fi

#-------------------------------------------------------------------------------------------------------------------
# 1. packages, kernel modules, iptables lock file
#-------------------------------------------------------------------------------------------------------------------
info "x_carrier_acl: installing packages"
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ipset ipset-persistent
command -v python3 >/dev/null || die "x_carrier_acl: python3 missing (needed by the firewall applier)."
[ -s /etc/ssl/certs/ca-certificates.crt ] || die "x_carrier_acl: ca-certificates missing (needed for TLS verification)."
"$php" -m | grep -ix curl >/dev/null || die "x_carrier_acl: the PHP curl extension is missing."
"$php" -m | grep -ix dom >/dev/null || die "x_carrier_acl: the PHP xml (dom) extension is missing."
#without the plugin, a saved rules.v4 that references our ipsets fails to restore
#on boot and the host comes up with the default ACCEPT policy (fails open)
if [ -f /etc/iptables/rules.v4 ] && [ ! -f /usr/share/netfilter-persistent/plugins.d/10-ipset ]; then
	if [ "$mode" = install ]; then
		die "x_carrier_acl: ipset-persistent plugin missing; refusing to install firewall sync."
	fi
	warn "x_carrier_acl: ipset-persistent plugin missing. Do not 'netfilter-persistent save' while our chain is loaded; reinstall ipset-persistent."
fi

install -o root -g root -m 0644 "$src/systemd/modules-load.conf" /etc/modules-load.d/x_carrier_acl.conf
systemctl restart systemd-modules-load.service || warn "x_carrier_acl: systemd-modules-load failed; check that ip_set, xt_set and xt_multiport load."
install -o root -g root -m 0644 "$src/systemd/tmpfiles.conf" /etc/tmpfiles.d/x_carrier_acl.conf
systemd-tmpfiles --create /etc/tmpfiles.d/x_carrier_acl.conf

#-------------------------------------------------------------------------------------------------------------------
# 2. root applier and directories
#-------------------------------------------------------------------------------------------------------------------
info "x_carrier_acl: installing the firewall applier and directories"
install -o root -g root -m 0755 "$src/sbin/x-carrier-acl-apply" /usr/local/sbin/x-carrier-acl-apply
install -d -o root -g root -m 0755 /var/lib/x_carrier_acl /var/lib/x_carrier_acl/state
install -d -o www-data -g www-data -m 0750 /var/lib/x_carrier_acl/export
#lock directory for hand runs of the worker (the unit creates it with RuntimeDirectory=)
install -d -o www-data -g www-data -m 0750 /run/x_carrier_acl

#-------------------------------------------------------------------------------------------------------------------
# 3. units: .service/.timer are also installed by apply.sh (same files); .path units and drop-ins only here
#-------------------------------------------------------------------------------------------------------------------
info "x_carrier_acl: installing systemd units"
for unit in "$src"/systemd/*.service "$src"/systemd/*.timer "$src"/systemd/*.path; do
	[ -f "$unit" ] || continue
	install -o root -g root -m 0644 "$unit" "$unit_dir/$(basename "$unit")"
done
for dropin_dir in "$src"/systemd/dropins/*.d; do
	[ -d "$dropin_dir" ] || continue
	install -d -o root -g root -m 0755 "$unit_dir/$(basename "$dropin_dir")"
	for dropin in "$dropin_dir"/*.conf; do
		[ -f "$dropin" ] || continue
		install -o root -g root -m 0644 "$dropin" "$unit_dir/$(basename "$dropin_dir")/$(basename "$dropin")"
	done
done

#the worker unit denies private destinations (SSRF); allow a private, non-loopback database, event socket or DNS
#server explicitly. Hostnames are resolved now; re-run the installer if they change.
config_value() {
	{ sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" /etc/fusionpbx/config.conf 2>/dev/null || true; } | head -n 1 | tr -d "\"' \r"
}
candidates=()
for key in 'database\.0\.host' 'database\.1\.host' 'switch\.event_socket\.host' 'event_socket\.ip_address'; do
	value=$(config_value "$key")
	[ -n "$value" ] || continue
	if [[ "$value" =~ ^[0-9a-fA-F:.]+$ ]]; then
		candidates+=("$value")
	else
		while read -r address _; do
			candidates+=("$address")
		done < <(getent ahosts "$value" 2>/dev/null | awk '{print $1}' | sort -u)
	fi
done
while read -r address; do
	candidates+=("$address")
done < <(awk '$1 == "nameserver" {print $2}' /etc/resolv.conf 2>/dev/null)
allow=""
if [ "${#candidates[@]}" -gt 0 ]; then
	allow=$(python3 -I -c '
import ipaddress, sys
denied = [ipaddress.ip_network(n) for n in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "169.254.0.0/16", "100.64.0.0/10", "fc00::/7", "fe80::/10", "224.0.0.0/4", "ff00::/8")]
out = []
for token in sys.argv[1:]:
    try:
        address = ipaddress.ip_address(token.split("%")[0])
    except ValueError:
        continue
    if address.is_loopback or not any(address in n for n in denied):
        continue
    if str(address) not in out:
        out.append(str(address))
print(" ".join(out))
' "${candidates[@]}")
fi
dropin=$unit_dir/x-carrier-acl-worker.service.d/10-db-host.conf
if [ -n "$allow" ]; then
	install -d -o root -g root -m 0755 "$unit_dir/x-carrier-acl-worker.service.d"
	printf '# Written by resources/apps/carrier_acl.sh: private database, event socket and DNS servers.\n[Service]\nIPAddressAllow=%s\n' "$allow" > "$dropin.tmp"
	chmod 0644 "$dropin.tmp"
	mv -f "$dropin.tmp" "$dropin"
	info "x_carrier_acl: worker sandbox allows private hosts: $allow"
elif [ -f "$dropin" ]; then
	rm -f "$dropin"
fi

systemctl daemon-reload

#-------------------------------------------------------------------------------------------------------------------
# 4. ACL list and SIP profile attachment (upstream tables only, so this works before upgrade.php --schema)
#-------------------------------------------------------------------------------------------------------------------
attach_cli=$app_dir/resources/cli/x_carrier_acl_attach.php
if [ "$mode" = install ]; then
	info "x_carrier_acl: creating the ACL list and attaching it to the SIP profiles"
	output=$(as_www "$php" "$attach_cli" --ensure-list --attach internal,external,internal-ipv6,external-ipv6) \
		|| die "x_carrier_acl: ACL list or profile attachment failed: $output"
	printf '%s\n' "$output" | grep -v '^restart ' || true
	#a new apply-inbound-acl param needs a profile restart (once, at install time)
	for profile in $(printf '%s\n' "$output" | sed -n 's/^restart \([a-z0-9-]*\)$/\1/p'); do
		if command -v fs_cli >/dev/null 2>&1; then
			fs_cli -x "sofia profile $profile restart" >/dev/null || warn "x_carrier_acl: could not restart sofia profile $profile; run: fs_cli -x 'sofia profile $profile restart'"
		else
			warn "x_carrier_acl: fs_cli not found; run: sofia profile $profile restart"
		fi
	done
else
	#upgrade: report, never auto-fix (an admin may have detached the list on purpose)
	if ! output=$(as_www "$php" "$attach_cli" --ensure-list --check); then
		warn "x_carrier_acl: $output"
		warn "x_carrier_acl: use Advanced > Carrier ACL > Attach to Profiles if the list should be attached."
	fi
fi

#-------------------------------------------------------------------------------------------------------------------
# 5. smoke test the privilege split: www-data writes a snapshot (empty, allow mode, only if none exists), root
#    applies it directly and again under the unit's hardening
#-------------------------------------------------------------------------------------------------------------------
#on install a failure stops the install; on upgrade it only warns, because apply.sh has already copied the new
#code and must still run upgrade.php --schema (dying here would leave new code on the old schema)
smoke_fail() {
	if [ "$mode" = install ]; then
		die "$1"
	fi
	warn "$1"
	warn "x_carrier_acl: the previous firewall state is unchanged; fix the cause and run: systemctl start x-carrier-acl-apply.service"
}
info "x_carrier_acl: smoke testing the firewall applier"
smoke_ok=true
as_www "$php" "$app_dir/resources/cli/x_carrier_acl_worker.php" --write-empty-snapshot \
	|| { smoke_fail "x_carrier_acl: the worker could not write the firewall snapshot."; smoke_ok=false; }
#upstream require.php exits 0 silently when config.conf is unreadable, so check the result, not the exit code
if [ "$smoke_ok" = true ] && [ ! -f /var/lib/x_carrier_acl/export/firewall.snapshot ]; then
	smoke_fail "x_carrier_acl: the worker exited without writing a snapshot (can www-data read /etc/fusionpbx/config.conf?)."
	smoke_ok=false
fi
rc=0
if [ "$smoke_ok" = true ]; then
	/usr/local/sbin/x-carrier-acl-apply --boot || rc=$?
	case "$rc" in
		0) ;;
		3) warn "x_carrier_acl: no supported firewall (neither /etc/iptables/rules.v4 nor /etc/nftables.conf); firewall sync is inactive." ;;
		*) smoke_fail "x_carrier_acl: firewall applier failed (exit $rc)."; smoke_ok=false ;;
	esac
fi
if [ "$smoke_ok" = true ]; then
	systemctl start x-carrier-acl-apply.service \
		|| { smoke_fail "x_carrier_acl: firewall applier failed under hardening; see: journalctl -u x-carrier-acl-apply.service"; smoke_ok=false; }
fi
if [ "$smoke_ok" = true ] && [ "$rc" -eq 0 ]; then
	#the applier accepts snapshot grammar 1 and 2, so an older snapshot left by the previous version is re-applied
	#here as is; the worker rewrites it as version 2 (with the local networks section) on its next run
	{ ipset list -n x_carrier_v4 >/dev/null 2>&1 && ipset list -n x_carrier_lan_v4 >/dev/null 2>&1; } \
		|| nft list set inet x_carrier_acl local_v4 >/dev/null 2>&1 \
		|| smoke_fail "x_carrier_acl: the applier reported success but the carrier or local network sets do not exist."
fi

#-------------------------------------------------------------------------------------------------------------------
# 6. enable the applier (the worker timer and path are enabled by apply.sh after the schema exists)
#-------------------------------------------------------------------------------------------------------------------
systemctl enable x-carrier-acl-apply.service
systemctl enable x-carrier-acl-apply.path
systemctl restart x-carrier-acl-apply.path

info "x_carrier_acl: host setup done ($mode)"
