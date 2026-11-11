#!/bin/bash
# Fetch the upstream FusionPBX installer at the pinned commit and write our
# settings into its config.sh.
set -euo pipefail
cd "$(dirname "$0")/.."
. ./config.sh
. ./resources/common.sh

apt-get install -y git ca-certificates

if [ ! -d "$fusionpbx_installer_dir/.git" ]; then
	info "Cloning upstream installer"
	git clone "$fusionpbx_installer_repo" "$fusionpbx_installer_dir"
fi

info "Checking out upstream installer $fusionpbx_installer_commit"
git -C "$fusionpbx_installer_dir" fetch --quiet origin
git -C "$fusionpbx_installer_dir" checkout --quiet --force "$fusionpbx_installer_commit"
actual=$(git -C "$fusionpbx_installer_dir" rev-parse HEAD)
[ "$actual" = "$fusionpbx_installer_commit" ] || die "Upstream installer is at $actual, expected $fusionpbx_installer_commit."

upstream_config="$fusionpbx_installer_dir/debian/resources/config.sh"
[ -f "$upstream_config" ] || die "Missing $upstream_config."

# upstream clones FusionPBX with "git clone -b \$system_branch"; install.sh then
# checks out the pinned commit on that branch
set_config_value "$upstream_config" system_branch "$fusionpbx_branch"
set_config_value "$upstream_config" domain_name "$domain_name"
set_config_value "$upstream_config" system_username "$system_username"
set_config_value "$upstream_config" system_password "$system_password"
set_config_value "$upstream_config" switch_version "$switch_version"
set_config_value "$upstream_config" php_version "$php_version"
set_config_value "$upstream_config" database_version "$database_version"
set_config_value "$upstream_config" application_speech "$application_speech"
set_config_value "$upstream_config" application_sip_trunks "$application_sip_trunks"
