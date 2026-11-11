#!/bin/bash
# shellcheck disable=SC2034  # sourced by other scripts
# Installer configuration. Sourced by install.sh and upgrade.sh.

# Upstream versions (latest stable GA, mutually compatible).
# Both are pinned to commits. Upstream's installer installs the head of the
# FusionPBX stable branch (not a release tag), and its nginx config matches that
# branch, so core is pinned to a commit on the stable branch.
fusionpbx_repo=https://github.com/fusionpbx/fusionpbx.git
fusionpbx_branch=5.6
fusionpbx_commit=f135e49e4a5be651ba98739f405981a4cb7feb3d
fusionpbx_installer_repo=https://github.com/fusionpbx/fusionpbx-install.sh.git
fusionpbx_installer_commit=e9b56d71f8b65a59df9598c29649b0fb6ff959fb
fusionpbx_installer_dir=/usr/src/fusionpbx-install.sh

# Values passed through to the upstream installer's debian/resources/config.sh.
# Anything not listed keeps the upstream default for the pinned commit.
domain_name=ip_address                  # hostname, ip_address or a custom value
system_username="admin"
system_password=random
switch_version=1.11                     # FreeSWITCH stable
php_version=8.4
database_version=18

# Optional upstream applications.
application_speech=true                 # Text to Speech (used by x_ivr_manager)
application_sip_trunks=false            # Not needed: inbound-only, carrier gateways are managed by x_carrier_credentials

# Features.
timesync_interval=15                     # Minutes between FreeSWITCH clock resyncs

# Where the installer logs. Created mode 0600 because upstream prints passwords.
install_log_dir=/root
