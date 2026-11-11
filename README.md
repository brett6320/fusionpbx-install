# fusionpbx-install

A thin layer over the upstream [fusionpbx-install.sh](https://github.com/fusionpbx/fusionpbx-install.sh)
installer. It installs FusionPBX at a pinned commit, then adds our apps
(`apps/x_*`) and host configuration. See [DESIGN.md](DESIGN.md) for the intent
and the gap analysis against upstream, and [docs/](docs/) for conventions and
the detailed designs.

Supported: Debian 12 (bookworm) and 13 (trixie), run as root on a fresh host.

## Install

```sh
git clone <this repo> /usr/src/x-install
cd /usr/src/x-install
./install.sh
```

- Versions are set in `config.sh`. Upstream's installer always installs the head
  of the FusionPBX branch, so `install.sh` refuses to start if that head is not
  the pinned `fusionpbx_commit`. To move forward: test the new head, set
  `fusionpbx_commit` to it, and run again.
- `install.sh` runs once. Upstream's installer is not re-runnable (it
  regenerates the database password and adds a second domain), so a second run
  refuses. Use `upgrade.sh` to reapply this layer.
- The generated admin and database passwords are in the log,
  `/root/x-install-<timestamp>.log` (mode 0600).
- Reboot when it finishes, as upstream recommends.

## Upgrade

```sh
cd /usr/src/x-install
git pull
./upgrade.sh                 # reapply our layer, move core to the pin
./upgrade.sh --restore-menu  # also rebuild the default menu (resets menu edits)
```

`upgrade.sh` checks packages, PHP sodium and the carrier credential keys first,
then stops our workers and moves core to `fusionpbx_commit`. It refuses to move
core backwards, and refuses if tracked upstream files were edited locally.

**Do not use FusionPBX's in-app source update** (Advanced > Upgrade > source
code). It moves core off the pin, and `upgrade.sh` will then refuse to run
until the pin catches up.

Optional upstream apps track their own branches. Update them with
`cd /var/www/fusionpbx && php core/upgrade/upgrade.php --optional`.

## Provisioning credentials

On first install, provisioning is turned on and protected with random HTTP
digest credentials. Without them, anyone who guesses a phone's MAC address
could download its config, including its SIP password.

Find them in **Advanced > Default Settings > Provision**:
`http_auth_username` and `http_auth_password`. A domain can override them in
its own Domain Settings. Changes you make are kept: the installer sets them
only once.

Give them to phones in one of these ways:

- **Phone web UI**: set the auto-provisioning server URL to
  `https://<your-domain>/app/provision/` and enter the user name and password
  in the phone's provisioning user/password fields.
- **DHCP option 66**: `https://<user>:<password>@<your-domain>/app/provision/`.
  Anyone on that network can read DHCP options, so prefer a dedicated voice
  VLAN.
- **Vendor redirect services** (Yealink RPS, Polycom ZTP, etc.): enter the same
  URL and credentials there.

Upstream checks the HTTP `Host` header against the domain name, so phones must
use exactly the domain name FusionPBX was installed with. With the default
`domain_name=ip_address`, that is the server's IP address.

A default install has a self-signed certificate, which most phones reject.
Install a trusted certificate (upstream ships a Let's Encrypt script) or turn
off certificate checks on the phones.

## Carrier credentials and encryption

Carrier credentials are encrypted in our own store (libsodium). FreeSWITCH
needs them in plaintext, so plaintext copies still exist in `v_gateways`, the
generated gateway XML and FusionPBX's transaction log. The keys are in
`/etc/fusionpbx/x_carriers/keys`, with a copy in
`/var/backups/x_carriers-keys`. Back up both with the database. Without the
keys, the stored credentials cannot be recovered.

## Testing

```sh
test/lint.sh                    # shellcheck, php -l, python syntax (needs Docker)
test/install-test.sh [bookworm|trixie] [--keep]  # full install + upgrade in a systemd container
```

## Known limitations

- IVR schedules use upstream's time condition handling, including its time
  zone behavior. They have not been tested with real calls.
- Rebooting an endpoint reboots every phone that shares its extension.
- The provision overlay cannot stop one tenant from reading another tenant's
  templates. This is an upstream limitation.
- Carrier ACL on nftables: in `allow` mode the firewall rule only counts
  traffic. Carriers are protected from bans by the fail2ban exemption.
  `restrict` mode works as described.
- A firewall reload (iptables-persistent, nftables) removes our rules until the
  next apply.
- On trixie, upstream's fail2ban ssh jail fails because it expects
  `/var/log/auth.log`. This is an upstream issue.
