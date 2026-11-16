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
- Before it changes anything, `install.sh` checks that it can reach every
  repository upstream's installer downloads from. One of them is plain HTTP, so
  outbound TCP 80 and 443 must be open.
- The generated admin and database passwords are in the log,
  `/root/x-install-<timestamp>.log` (mode 0600).
- Reboot when it finishes, as upstream recommends.
- If `install.sh` fails before upstream's installer has created the database,
  install again on a fresh host. Reusing the host leaves upstream's state
  behind (for example a php-fpm setting that points at a deleted
  `/etc/fusionpbx`).

## Deploy on AWS

A CloudFormation template for a single AWS host is in
[deploy/aws/](deploy/aws/README.md).

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
`./upgrade.sh --optional`. It runs upstream's update as `www-data`, which owns
those checkouts, and then updates the schema, defaults and permissions. Each
app moves to the head of its own branch (core stays at the pin) and there is no
rollback, so take a backup first (`x-backup`).

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

## Phone codec order

One codec order for every phone: **Advanced > Default Settings > Provision >
`x_codec_1` to `x_codec_8`**, highest priority first. The installer seeds
G.722, PCMU, PCMA, G.723.1, G.729A/B, G.726-32, iLBC, OPUS; changes made there
are kept on upgrade. Codec names: `OPUS`, `G.722`, `PCMU`, `PCMA`, `G.723.1`,
`G.729A/B`, `G.726-32`, `iLBC`. To skip a slot, disable it or leave it empty in
Default Settings (an empty domain setting does not override the default).

Upstream's templates don't read it; the Provision Templates overrides do.
Each override maps the names to its phone's format, skips codecs that phone
lacks (for example OPUS on a DP750), and fills its remaining slots. Upstream
Yealink templates keep their own `yealink_codec_*` settings. Phones pick up a
change at their next provisioning.

## Theme

The installer makes FusionPBX's built-in dark theme (Cobalt Blue) the default,
once: **Advanced > Default Settings > Domain > theme**. A theme you choose there
later is kept, and domains and users can still pick their own.

## Time sync

chrony keeps the host clock synced, and FreeSWITCH resyncs to it every
`timesync_interval` minutes. On EC2 (detected from the instance hardware,
with or without the AWS settings file) chrony also uses the Amazon Time Sync
Service at `169.254.169.123`, which works even when outbound NTP to the
internet is blocked. The installer writes
`/etc/chrony/sources.d/x-aws-time.sources`, unless chrony already lists that
address, and removes it off EC2.

## Web login sessions

A web login ends about 8 hours after its last activity (`session_lifetime` in
`config.sh`, in seconds). FusionPBX has no setting for this: a login lasts as
long as PHP keeps its session file, so the installer sets
`session.gc_maxlifetime` in php-fpm's `php.ini`, where Debian's session cleanup
also reads it. The cleanup runs every 30 minutes in whole minutes, so a login
can last up to 30 minutes longer. A page that refreshes itself, such as a
dashboard with live calls, counts as activity and keeps its login alive. The
session cookie has no expiry, but a browser that restores tabs may keep it.
Upgrades set the value again, including after a PHP package update that
replaced `php.ini`.

## IVR phrases (Google Text-to-Speech)

In **Applications > IVR Manager**, a greeting can be set to any recording or
phrase of the domain, or to a new phrase built there from text and pauses.
Each text part is spoken by Google Text-to-Speech, saved as a recording, and
played in order by an upstream phrase (also editable in **Applications >
Phrases**). The phrase uses the IVR menu's language.

Credentials, either:

- **Service account key file** (preferred, what Google's client libraries
  use). Create a service account in a Google Cloud project with the
  Text-to-Speech API enabled, download its JSON key, then:

  ```sh
  sudo install -o root -g www-data -m 0640 key.json /etc/fusionpbx/google-tts.json
  rm key.json
  ```

  Give the service account no IAM roles: Text-to-Speech only needs the API
  enabled on the project, so a leaked key can do nothing else.

- **API key:** set **Advanced > Default Settings > x_ivr_manager >
  google_tts_api_key**, restricted to the Text-to-Speech API.

The default voice is `en-US-Wavenet-D` (`google_tts_voice`); the voice list
shows the voices for `google_tts_language_code` (default `en-US`). The server
needs outbound HTTPS to `texttospeech.googleapis.com` and
`oauth2.googleapis.com`. The admin and superadmin groups have the
`x_ivr_manager_phrase` permission. The credentials are read from Default
Settings only, never from domain or user settings.

A phrase only plays when it is enabled and its language matches the call's
language, so the picker refuses other phrases. A sub-menu keeps the language of
the menu callers came from: a phrase for a sub-menu in a different language
stays silent.

### Greeting playback, text and names

- The current greetings and the picker's selection can be played in the
  browser (users with `recording_play` or `recording_download`). A phrase plays
  its recordings in order with its pauses; other phrase actions (sounds, say)
  are skipped and the page says so.
- Text-to-speech greetings keep their text: each recording in
  `recording_message` (also shown in **Applications > Recordings**), and a
  phrase's script, pauses included, on one line in its description (up to 255
  characters, upstream's field size). IVR Manager shows the text
  under the greeting with a Copy button.
- New greetings get descriptive names, e.g. "Main Menu - Long Greeting -
  2026-11-16 14:05" (domain time zone), unless a name is given. A phrase's
  recordings are named "<phrase> - Part 1", "Part 2", and so on.

### Removing and deleting greetings

**Remove from Menu** takes a greeting off the menu and keeps the recording or
phrase. **Delete** in the greeting picker deletes a recording (and its audio
file) or a phrase for good, after a confirmation, for users with
`recording_delete` or `phrase_delete`. It is refused while anything still uses
it: an IVR menu (greetings, sounds, ringback) or menu option, a phrase, a ring
group, call center queue, call flow or conference center, or a dialplan that
names it; the message lists what. The check matches the file name or phrase
uuid as text, so a similarly named recording can also block a delete. Deleting
a phrase also deletes the recordings created for it (for users with
`recording_delete`), unless something else plays them.

### Schedule extension

An IVR schedule is a time condition with its own extension: calls sent to it
reach the IVR menu inside the time window and the fallback outside it. Send
calls to the schedule, not to the menu: in **Dialplan > Inbound Routes**,
choose the schedule under Time Conditions. IVR Manager suggests the first free
number in `time_conditions > extension_range` (e.g. `800-899` or
`800-899,900-999`; upstream ships it disabled, so enable it for a suggestion),
refuses a new number an extension or another dialplan entry of the domain
already uses (an existing schedule keeps its number), and lists the inbound
routes pointing at the schedule, from Destinations and Dialplan > Inbound
Routes.

## Email templates

The installer rewords upstream's English email templates (voicemail,
transcription, missed call, fax, emergency call, email test) in Advanced >
Email Templates, and fixes the fax subject that read "Subject, Fax to: …
SENT". A template is replaced only while it still has upstream's exact text,
so templates edited in the UI are left alone. Carrier ACL alerts use the
subject prefix `[Carrier ACL]`.

## Carrier credentials and encryption

Setting up a carrier, with Telnyx as the example: [docs/carrier-credentials.md](docs/carrier-credentials.md).

Carrier credentials are encrypted in our own store (libsodium). FreeSWITCH
needs them in plaintext, so plaintext copies still exist in `v_gateways`, the
generated gateway XML and FusionPBX's transaction log. The keys are in
`/etc/fusionpbx/x_carriers/keys`, with a copy in
`/var/backups/x_carriers-keys`. Back up both with the database. Without the
keys, the stored credentials cannot be recovered.

## AWS instance settings

If `/etc/default/fusionpbx-aws` exists (user data from the AWS template writes
it), `install.sh` and `upgrade.sh` also configure the host from AWS. The
instance role must be able to read the two Telnyx prefix lists and describe the
Elastic IP; the template grants this.

- **External address.** EC2 uses 1:1 NAT, so FreeSWITCH must advertise the
  Elastic IP. The installer sets the `external_sip_ip` and `external_rtp_ip`
  variables to it (Advanced > Variables), regenerates `vars.xml` from them, and
  checks the file. It does this only when instance metadata and AWS both show
  that this instance holds the address named in the file, so a restored or
  cloned disk never takes production's address. A value an admin set by hand is
  kept. On install, FreeSWITCH restarts to pick it up. If an upgrade changes
  it, the upgrade tells you to restart FreeSWITCH when no calls are up.
- **Carrier ACL.** The Telnyx signalling and media prefix lists become two
  carrier ACL feeds (Advanced > Carrier ACL). `x-aws-sync` reads them from AWS
  every hour (`x-aws-sync.timer`), and the worker applies changes as for any
  feed, including the approval thresholds. On install, the first run of each
  feed is approved automatically, because the security groups already allow
  exactly these addresses. A broad prefix still waits for approval. The feeds
  are created once: a feed you delete stays deleted.
- **Instance metadata.** Only root can reach the EC2 instance metadata service
  (`x-imds-guard`). It hands out the instance role's credentials, which can
  also write backups. The web app, FreeSWITCH and the workers run as other
  users and are refused. Root keeps access (`x-aws-sync`, cloud-init, the SSM
  agent), and the AWS time service is not affected. Run the AWS CLI with `sudo`
  on the host, including in Session Manager shells (`ssm-user`). Anything else
  that needs the instance role as another user is refused too, such as the
  CloudWatch agent (`cwagent` by default; run it as root). The rule is re-applied at boot and when `netfilter-persistent`
  or `nftables` restarts; after a firewall reload, run `x-imds-guard`.
- **Fail2ban whitelist.** If the file names the SSH, web and endpoint prefix
  lists (`SSH_PREFIX_LIST_ID`, `WEB_PREFIX_LIST_ID`,
  `ENDPOINT_PREFIX_LIST_ID`), Fail2ban never bans their addresses
  (`/etc/fail2ban/jail.d/x-aws-ignoreip.local`, rewritten after each hourly
  `x-aws-sync`). It applies to every jail, because upstream's jails block all
  ports: a phone that fails provisioning would otherwise cut off SIP for its
  whole office. Addresses already banned are unbanned once whitelisted.
  - The security groups only let these lists connect to SSH, web and SIP, so
    in practice Fail2ban then bans nobody on those ports; the security groups
    are the access control.
  - Networks broader than /16, such as `0.0.0.0/0` in the web list, are
    skipped with a warning: whitelisting them would turn off brute-force
    protection for everyone.
  - Addresses and networks in a `[DEFAULT]` `ignoreip` in `jail.local` are
    kept (host names are not).
  - Without the three keys, or once they are removed, there is no whitelist. An
    invalid value only skips that list, with a warning.
  - If a list can't be read (an invalid value, or a stack whose role wasn't
    updated), `x-aws-sync.service` shows as failed after each hourly run until
    it's fixed; the carrier ACL still updates.
- If AWS can't be reached during install, the install continues with a
  warning. The feeds then report the failure. Fix the cause and run
  `x-aws-sync`.
- Telnyx must also know the Elastic IP: add it to the IP authentication of
  your Telnyx connection.

## Backup and restore

`x-backup` runs nightly (`x-backup.timer`, settings in `config.sh`) and writes
one bundle per run to `/var/backups/x-install/<timestamp>/`. A bundle has:

- the whole `fusionpbx` database;
- `/etc/freeswitch`, FreeSWITCH storage (voicemail, fax) and recordings;
- custom music on hold;
- `/var/lib/fusionpbx` (provision overlay files, fax logos);
- a manifest and checksums.

Bundles older than `backup_retention_days` are removed, but never the newest
one. Run `x-backup` by hand at any time.

Not in a bundle:

- The carrier credential keys. They stay in `/var/backups/x_carriers-keys`, so
  a bundle copied off the host cannot decrypt the carrier store on its own.
- `/etc/fusionpbx/config.conf`. If you changed it by hand (for example
  `x_carriers.key_dir` or event socket settings), keep your own copy.
- Firewall state. It is rebuilt from the database.

**Bundles contain secrets**: gateway passwords, provisioning credentials,
password hashes, the event socket password and TLS private keys. Keep them
root-only, and encrypt any copy that leaves the host.

**Bundles stay on the host by default.** That does not protect you from losing
the host. Set `backup_offsite_cmd` to copy each bundle somewhere else (it gets
the bundle directory as `$1`), and copy `/var/backups/x_carriers-keys`
separately. On AWS, daily EBS snapshots (AWS Backup) are the fastest way back
from host loss. Use bundles for moving to another host and for restoring
selected data.

### Restore

```sh
cd /usr/src/x-install
./restore.sh /var/backups/x-install/<timestamp>
```

`restore.sh` checks the following before it changes anything:

- the checksums;
- that the bundle is not from a newer FusionPBX, installer or PostgreSQL than
  this host;
- that every carrier key the bundle's database needs is present (from
  `--keys DIR`, default `/var/backups/x_carriers-keys`);
- that the archive only contains the expected paths;
- that there is enough disk space.

Then it backs up the current state to `/var/backups/x-install/pre-restore/`
and stops services. It restores the database into a side database and swaps
it in. The old database is kept as `fusionpbx_pre_restore_<timestamp>`. Last,
it restores files and keys, reapplies this layer (which migrates an older
schema forward) and starts services.

If it fails before the swap, the host is unchanged and services restart. If it
fails after the swap, services stay stopped and it prints the rollback
commands. Logs go to `/root/x-restore-<timestamp>.log`. A restore can take a
long time, so run it inside `tmux` or `screen`. Pre-restore bundles age out
with `backup_retention_days`, like the nightly ones.

After a restore, the admin, provisioning and database-backed credentials are
the ones from the bundle. The passwords that `install.sh` printed on this host
no longer work.

**Restoring onto a new host** (after host loss):

1. Give the new host the old host's name. If phones use an IP address domain
   (`domain_name=ip_address`), also move the old address or Elastic IP to it.
   `restore.sh` refuses an IP address domain that is not on the host unless you
   pass `--accept-domain-mismatch`. In that case, rename the domain in
   Advanced > Domains afterwards. For production, use a DNS name as the domain.
2. Run `./install.sh` with the same `config.sh`. `install.sh` refuses to start
   if upstream has moved past `fusionpbx_commit`. If it has, set
   `fusionpbx_commit` to the new head, then install.
3. Copy the bundle and the key backup over, then restore:
   `./restore.sh <bundle> --keys <key dir> --no-pre-backup`.

**Drills and migrations while the old host still runs:** pass `--no-start`.
Otherwise the restored gateways register with the same carrier credentials
and take the registrations away from the old host, and the email and fax
queues start sending. `--no-start` stops and disables FreeSWITCH, the FusionPBX
services and our timers, so a reboot does not start them either. To go live,
turn the old host off, run `systemctl enable --now freeswitch`, then run
`./upgrade.sh`.

```

## Testing

```sh
test/lint.sh                    # shellcheck, php -l, python syntax (needs Docker)
test/install-test.sh [bookworm|trixie] [--keep]  # full install + upgrade in a systemd container
test/restore-test.sh [bookworm|trixie] [--keep]  # backup, refusals, restore on a fresh host and in place
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
