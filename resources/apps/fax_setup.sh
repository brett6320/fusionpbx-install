#!/bin/bash
# Host setup for x_fax_setup: per-domain coversheet logo storage outside the webroot.
set -euo pipefail

install -d -m 0750 -o www-data -g www-data /var/lib/fusionpbx
install -d -m 0750 -o www-data -g www-data /var/lib/fusionpbx/x_fax_setup
