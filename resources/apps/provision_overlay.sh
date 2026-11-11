#!/bin/bash
# Host setup for x_provision_overlay (docs/design/provision_overlay.md, section 9).
# Run by resources/apply.sh after the app copy and before upgrade.php. Idempotent.
#
#  - overlay state tree under /var/lib/fusionpbx/provision_overlay (www-data, 0750/0640)
#  - /usr/share/fusionpbx/templates (www-data) and the provision symlink into the merged tree
#  - initial/refresh build of the merged tree as www-data (no database needed unless domain overrides exist)
#  - nginx location ^~ /provision-assets/ in the :80 and :443 server blocks, between marker comments
#
# Never touches provision.enabled, provision.template_dir, overlay files or assets.
#
# Because this runs before upgrade.php, a failure during "upgrade" must not leave new core code on
# the old schema: upgrade-mode problems are warnings and the previous build (merged/current) stays.
#
# Usage: provision_overlay.sh install|upgrade
set -euo pipefail

repo_dir=$(cd "$(dirname "$0")/../.." && pwd)
. "$repo_dir/config.sh"
. "$repo_dir/resources/common.sh"

mode=${1:-}
case "$mode" in
	install|upgrade) ;;
	*) die "Usage: $0 install|upgrade" ;;
esac

app_dir="$fusionpbx_dir/app/x_provision_overlay"
state_root=/var/lib/fusionpbx/provision_overlay
templates_parent=/usr/share/fusionpbx/templates
link_path=$templates_parent/provision
link_target=$state_root/merged/current
nginx_conf=/etc/nginx/sites-available/fusionpbx
marker_begin='# BEGIN x_provision_overlay'
marker_end='# END x_provision_overlay'

[ -d "$app_dir" ] || die "x_provision_overlay: $app_dir not found (the app copy must run first)."

overlay_cli() {
	(cd "$fusionpbx_dir" && runuser -u www-data -- /usr/bin/php "$app_dir/resources/cli/build.php" "$@")
}

#fatal on install, warning on upgrade
fail() {
	if [ "$mode" = install ]; then
		die "$*"
	fi
	warn "$* Continuing the upgrade with the previous build."
}

#app files: readable by php-fpm only. apply.sh chowned the tree to www-data; chmod runs as that user so
#root never follows a symlink swapped into a www-data writable directory
runuser -u www-data -- find "$app_dir" -type d -exec chmod 0750 {} +
runuser -u www-data -- find "$app_dir" -type f -exec chmod 0640 {} +

#overlay state tree; /var/lib/fusionpbx is also created by fax_setup.sh with the same owner and mode
info "x_provision_overlay: state directories"
install -d -m 0750 -o www-data -g www-data /var/lib/fusionpbx
install -d -m 0750 -o www-data -g www-data "$state_root"
for dir in global domains assets merged; do
	install -d -m 0750 -o www-data -g www-data "$state_root/$dir"
done
[ -e "$state_root/.lock" ] || install -m 0640 -o www-data -g www-data /dev/null "$state_root/.lock"

#re-assert ownership (-h -P: symlinks into the upstream tree are not followed) and modes (as www-data)
chown -R -h -P www-data:www-data "$state_root"
runuser -u www-data -- find "$state_root" -type d -exec chmod 0750 {} +
runuser -u www-data -- find "$state_root" -type f -exec chmod 0640 {} +

#parent of the link; upstream's update_php_fpm() creates /usr/share/fusionpbx and adds it to php-fpm ReadWritePaths
[ -d /usr/share/fusionpbx ] || install -d -m 0755 -o root -g root /usr/share/fusionpbx
install -d -m 0755 -o www-data -g www-data "$templates_parent"

#refuse to replace anything we did not create (e.g. a packaged template directory)
link_ok=true
if [ -L "$link_path" ]; then
	if [ "$(readlink "$link_path")" != "$link_target" ]; then
		fail "x_provision_overlay: $link_path points to $(readlink "$link_path"), not $link_target. Remove it or point it at the overlay, then rerun."
		link_ok=false
	fi
elif [ -e "$link_path" ]; then
	fail "x_provision_overlay: $link_path exists and is not a symlink. It would shadow the merged templates; move it away and rerun."
	link_ok=false
fi

#build the merged tree as www-data (creates merged/<id> and merged/current)
if [ "$link_ok" = true ]; then
	info "x_provision_overlay: building the merged template tree"
	if ! overlay_cli --build; then
		fail "x_provision_overlay: build failed."
		link_ok=false
	fi
fi

#point the first upstream search path at the merged tree, unless an admin disabled the overlay
if [ "$link_ok" = true ]; then
	if [ -e "$state_root/.disabled" ]; then
		warn "x_provision_overlay: disabled ($state_root/.disabled exists); $link_path not created. Re-enable with: runuser -u www-data -- php $app_dir/resources/cli/build.php --enable"
	elif [ ! -d "$link_target" ]; then
		fail "x_provision_overlay: $link_target does not exist; $link_path not created."
	else
		ln -sfn "$link_target" "$link_path"
		chown -h www-data:www-data "$link_path"
	fi
fi

#nginx block for the :80 and :443 server blocks; it must not contain "/websockets/" or
#"#redirect letsencrypt to dehydrated" because upgrade.php --defaults string-searches this file
asset_block() {
	local socket=$1
	printf '\t%s\n' "$marker_begin"
	printf '\t%s\n' "#provisioning branding assets for phones (app/x_provision_overlay/assets.php); managed by the installer"
	printf '\t%s\n' "location ^~ /provision-assets/ {"
	printf '\t\t%s\n' "limit_except GET {"
	printf '\t\t\t%s\n' "deny all;"
	printf '\t\t%s\n' "}"
	printf '\t\t%s\n' "fastcgi_pass unix:$socket;"
	printf '\t\t%s\n' "include fastcgi_params;"
	printf '\t\t%s\n' "fastcgi_param   SCRIPT_FILENAME $fusionpbx_dir/app/x_provision_overlay/assets.php;"
	printf '\t\t%s\n' "fastcgi_param   SCRIPT_NAME /app/x_provision_overlay/assets.php;"
	printf '\t%s\n' "}"
	printf '\t%s\n' "$marker_end"
}

#:80-only blocks: upstream's https exemption regex (provision|xml_cdr|firmware) also matches
#/app/x_provision_overlay/, which would serve the admin pages and the login form over plain http
http_redirect_block() {
	printf '\t%s\n' "$marker_begin"
	printf '\t%s\n' "#admin pages of app/x_provision_overlay are https only; managed by the installer"
	printf '\t%s\n' "location ^~ /app/x_provision_overlay/ {"
	# shellcheck disable=SC2016  # nginx variables, written literally
	printf '\t\t%s\n' 'return 301 https://$host$request_uri;'
	printf '\t%s\n' "}"
	printf '\t%s\n' "$marker_end"
}

#branding is not needed for provisioning itself, so nginx problems are warnings and the file is left alone
update_nginx() {
	if [ ! -f "$nginx_conf" ]; then
		warn "x_provision_overlay: $nginx_conf not found; asset location not added."
		return 0
	fi
	if ! nginx -t >/dev/null 2>&1; then
		warn "x_provision_overlay: nginx -t already fails before any change; asset location not updated. Fix nginx and rerun."
		return 0
	fi

	#markers must be balanced, or stripping would drop the rest of the file
	local begin_count end_count
	begin_count=$(grep -cF -- "$marker_begin" "$nginx_conf" || true)
	end_count=$(grep -cF -- "$marker_end" "$nginx_conf" || true)
	if [ "$begin_count" != "$end_count" ]; then
		warn "x_provision_overlay: unbalanced '$marker_begin' / '$marker_end' markers in $nginx_conf; fix them by hand and rerun. nginx not changed."
		return 0
	fi

	#current file without our blocks
	awk -v b="$marker_begin" -v e="$marker_end" '
		{ t = $0; sub(/^[ \t]+/, "", t); sub(/[ \t]+$/, "", t) }
		t == b { skip = 1; next }
		t == e { skip = 0; next }
		!skip { print }
	' "$nginx_conf" > "$work/stripped"

	#use the php-fpm socket the vhost already uses (upstream nginx.sh rewrites it per php version)
	local fpm_socket
	fpm_socket=$(grep -m1 -oE 'unix:[^;[:space:]]*php[^;[:space:]]*-fpm\.sock' "$work/stripped" | sed 's/^unix://' || true)
	[ -n "$fpm_socket" ] || fpm_socket="/var/run/php/php${php_version}-fpm.sock"

	X_BLOCK=$(asset_block "$fpm_socket")
	X_BLOCK_HTTP=$(http_redirect_block)
	export X_BLOCK X_BLOCK_HTTP

	#insert before the closing brace of every top-level server block listening on port 80 or 443
	#(the 127.0.0.1:80 internal block is skipped); plain http blocks also get the admin redirect
	awk -v countfile="$work/count" '
		BEGIN { depth = 0; inserver = 0; inserted = 0; block = ENVIRON["X_BLOCK"]; block_http = ENVIRON["X_BLOCK_HTTP"] }
		{
			line = $0
			code = line
			sub(/#.*/, "", code)
			if (!inserver && depth == 0 && code ~ /^[ \t]*server[ \t]*\{/) {
				inserver = 1; n = 0; want = 0; tls = 0
			}
			opens = gsub(/\{/, "{", code)
			closes = gsub(/\}/, "}", code)
			depth += opens - closes
			if (inserver) {
				if (code ~ /^[ \t]*listen[ \t]+(\[::\]:)?(80|443)([ \t;]|$)/) {
					want = 1
				}
				if (code ~ /^[ \t]*listen[ \t]+(\[::\]:)?443([ \t;]|$)/ || code ~ /^[ \t]*listen[ \t].*[ \t]ssl([ \t;]|$)/) {
					tls = 1
				}
				if (depth == 0) {
					for (i = 1; i <= n; i++) print buf[i]
					if (want) { print block; inserted++ }
					if (want && !tls) { print block_http }
					print line
					inserver = 0
				}
				else {
					buf[++n] = line
				}
			}
			else {
				print line
			}
		}
		END {
			if (inserver) { for (i = 1; i <= n; i++) print buf[i] }
			print inserted > countfile
		}
	' "$work/stripped" > "$work/new"

	local inserted
	inserted=$(cat "$work/count")
	if [ "$inserted" -lt 1 ]; then
		warn "x_provision_overlay: no server block listening on port 80 or 443 found in $nginx_conf; nginx not changed."
		return 0
	fi
	[ "$inserted" -ge 2 ] || warn "x_provision_overlay: the asset location was added to only $inserted server block(s) (expected the :80 and :443 blocks)."

	if cmp -s "$work/new" "$nginx_conf"; then
		info "x_provision_overlay: nginx asset location already present"
		return 0
	fi
	info "x_provision_overlay: updating the nginx asset location"
	cp -p "$nginx_conf" "$work/backup"
	#write in place so the sites-enabled symlink, owner and mode are kept
	cat "$work/new" > "$nginx_conf"
	if ! nginx -t; then
		cat "$work/backup" > "$nginx_conf"
		warn "x_provision_overlay: nginx -t failed with the asset location; $nginx_conf restored."
		return 0
	fi
	if systemctl is-active --quiet nginx; then
		systemctl reload nginx
	fi
}

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
update_nginx

#report; problems are printed but do not stop the installer
overlay_cli --verify || warn "x_provision_overlay: verify reported problems (see above)."

info "x_provision_overlay: done ($mode)"
