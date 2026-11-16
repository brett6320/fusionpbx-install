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

`upgrade.sh` installs the packages it needs (rsync, ffmpeg, chrony, ipset,
ipset-persistent), checks PHP sodium and the carrier credential keys,
then stops our workers and moves core to `fusionpbx_commit`. It refuses to move
core backwards, refuses a `fusionpbx_commit` that is not on
`fusionpbx_branch`, and refuses if tracked upstream files were edited locally.

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

Upstream's templates don't read these settings, and this repo ships no
template that does. To use them, write a Provision Templates override that
reads `{$x_codec_1}` … `{$x_codec_8}`, maps the names to the phone's format and
skips codecs the phone lacks (for example OPUS on a DP750). Upstream Yealink
templates keep their own `yealink_codec_*` settings. Phones pick up a
change at their next provisioning.

## Theme

The installer makes FusionPBX's built-in dark theme (Cobalt Blue) the default,
once: **Advanced > Default Settings > Domain > theme**. A theme you choose there
later is kept, and domains and users can still pick their own.

## Time sync

chrony keeps the host clock synced. Every `timesync_interval` minutes (default
15; it should divide 60, since it is used as `OnCalendar=*:0/N`) FreeSWITCH is told to resync to it the next time no
calls are up (`fsctl sync_clock_when_idle`). On EC2 (detected from the instance hardware,
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

How to use IVR Manager step by step (menus, options, greetings, schedules and
holidays) is in [docs/ivr-manager.md](docs/ivr-manager.md); the sections below
cover setup and details.

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
`oauth2.googleapis.com`. Building phrases needs the `x_ivr_manager_phrase` and
`x_ivr_manager_edit` permissions (the admin and superadmin groups have both)
and the upstream Phrases app. The credentials are read from Default
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
  `recording_message` (also shown in **Applications > Recordings**). A
  phrase's description holds the voice and the script on one line ("Google
  Text-to-Speech, <voice>: … / [pause 1s] / …", cut at 255 characters,
  upstream's field size). IVR Manager shows the full text under the greeting,
  one part per line, with a **Copy Text** button.
- Recorded and text-to-speech greetings without a name get a descriptive one,
  e.g. "Main Menu - Long Greeting - 2026-11-16 14:05" (domain time zone). A
  phrase's name is filled in as e.g. "Main_Menu_Greeting_2026-11-16_1405";
  characters other than letters, digits, `_`, `.` and `-` become `_`. Its
  recordings are named "<phrase> - Part 1", "Part 2", and so on.

### Removing and deleting greetings

**Remove from Menu** takes a greeting off the menu and keeps the recording or
phrase. **Delete** in the greeting picker deletes a recording (and its audio
file) or a phrase for good, after a confirmation, for users with
`x_ivr_manager_edit` and `recording_delete` (recordings) or `phrase_delete`
(phrases). It is refused while anything still uses it: an IVR menu (greetings,
sounds, ringback) or menu option, a phrase (when deleting a recording), a ring
group, call center queue, call flow or conference center, or a dialplan that
names it; the message lists what. The check matches the file name or phrase
uuid as text, so a similarly named recording can also block a delete. Deleting
a phrase also deletes the recordings created for it (for users with
`recording_delete`), unless something else plays them.

### Schedule extension

An IVR schedule is a time condition with its own extension: calls sent to it
reach the IVR menu while one of its rules matches and the fallback otherwise. Send
calls to the schedule, not to the menu: in **Dialplan > Inbound Routes**,
choose the schedule under Time Conditions. IVR Manager suggests a random,
unused 22 digit number, as for a new IVR menu (see below; nobody needs to dial
it), or the first free number in `time_conditions > extension_range` (e.g.
`800-899` or `800-899,900-999`) when that setting is enabled (upstream ships it
disabled). A new or changed number is refused when an extension or another
dialplan entry of the domain already uses it, or an outbound route matches it
(an existing schedule keeps its number). IVR Manager also lists the inbound
routes pointing at the schedule, from Destinations and Dialplan > Inbound
Routes.

### Schedule rules

A schedule has one or more rules, each with an optional label:

- **One time:** from a start to an end date and time, e.g. a closure.
- **Weekly:** chosen days, all day or from one time to another, e.g. Mon–Wed
  08:00–17:00, Thu 07:00–15:00, and Fri–Sun all day as three rules. Times are
  24 hour; `24:00` ends at midnight.
- **Annual:** a date every year, or a weekday of a month (1st–4th or last,
  e.g. the 4th Thursday of November), shifted by up to six days (the day after
  Thanksgiving is the 4th Thursday + 1). A date can include the observed day:
  the Friday before when it falls on a Saturday, the Monday after when on a
  Sunday. Presets fill in US federal holidays, Christmas Eve, the day after
  Thanksgiving and New Year's Eve.

Each rule becomes condition groups of the time condition using only `date-time`,
`wday`, `mon`, `mday` and `minute-of-day`, so FreeSWITCH works out floating
dates every year with nothing to update. Weekly days split into one group per
run of consecutive days, as the stock editor writes them. IVR Manager shows
whether the schedule is active now and the next date of each annual rule,
computed from the same conditions in the schedule's time zone.

The rules are stored with the schedule (`v_x_ivr_manager_schedules`). A schedule
with a single start and end from before rules opens as one one-time rule.

#### Schedule rule limitations

- **Easter-based holidays** (Good Friday, Easter Monday, ...) can't repeat on
  their own, since FreeSWITCH has no condition for them. Add each year as a one
  time rule.
- **Other holidays outside the patterns** (a fixed date, or the 1st–4th or
  last weekday of a month plus or minus up to six days) need one time rules too,
  e.g. lunar calendar holidays or one-off "bridge" days.
- **No window past midnight in one rule.** The end time must be after the start
  time; for evening hours into the next morning add a second rule from 00:00
  (e.g. Mon–Thu 17:00–24:00 and Tue–Fri 00:00–08:00).
- **One time window per rule.** A day with two windows (a lunch closure) takes
  two rules.
- **Rules only add time.** The menu answers while any rule matches; a rule
  can't exclude times (e.g. "Mon–Fri 08:00–17:00 except holidays"). Put the
  schedule on the menu for the closed times (after hours and holidays) and make
  the open-hours menu the fallback, as in the guide's example.
- **Observed day** is refused on save for Feb 28, Feb 29 and Mar 1, whose
  neighbouring days depend on the leap year. Feb 29 itself only matches in leap
  years.
- **Last weekday of February** is not supported, and a February weekday rule
  can't be shifted past the 28th, since the month's length varies.
- **An offset can't leave the month.** A weekday rule whose shifted days could
  fall in the next or previous month is refused (e.g. last Monday of May + 1).
- **The observed day** follows the US federal rule only (Saturday → Friday,
  Sunday → Monday). Other observance rules, e.g. moving a Sunday holiday to the
  next free weekday after another holiday, aren't supported.
- **Presets are US federal holidays** plus Christmas Eve, the day after
  Thanksgiving and New Year's Eve. Other countries' holidays are entered by
  hand, as dates or weekday rules.
- **Up to 40 rules** per schedule.
- **Changes in Dialplan > Time Conditions** to a rule's times or to where it
  sends calls (or a condition disabled in Dialplan Manager) make the schedule
  no longer match its rules. IVR Manager then shows no rules and asks for
  confirmation before replacing the time condition with the rules you enter.
  Other changes there, such as the schedule's name, number, fallback or
  enabled state, are shown and kept. A schedule left with a single date and
  time window opens as one one-time rule, without its label.
- **A `24:00` end** can't be edited in the stock Time Conditions editor, which
  only offers times up to 23:59. Saving there changes it.
- **Time zones:** weekly and annual rules are evaluated by FreeSWITCH in the
  schedule's time zone (`domain > time_zone` when saved; a User Setting of
  the person saving overrides the domain's), using FreeSWITCH's
  own `timezones.conf.xml`. A zone missing there, or with outdated daylight
  saving rules, makes FreeSWITCH use other times than IVR Manager's "active
  now" and next dates. One time rules follow the stock editor: stored in UTC
  when the server runs in UTC and read by FreeSWITCH in the server's time zone,
  so they are off when the server is neither UTC nor in the domain time zone.
  After changing the domain time zone, save the schedule again.
- **"Active now" and the next dates** are computed by IVR Manager from the same
  conditions, not reported by FreeSWITCH.

### Creating IVR menus

**Add** in IVR Manager (permission `x_ivr_manager_add`, admin and superadmin)
creates an IVR menu from a name, an extension and a description, with
upstream's defaults for a new menu (including `ivr_menu > default_ringback`),
then opens it in IVR Manager to set the greeting, options and schedule. Until a
greeting is set the menu plays nothing and ends after its timeouts.

The extension is filled in with a random, unused 22 digit number: nobody needs
to dial it, since calls reach the menu from Inbound Routes, a schedule or
another menu. If the menu's own dialplan entry is skipped (the menu is
disabled, or an outbound route is ordered before it), a call to its number
falls through to the outbound routes, and a 12 to 20 digit number matches the
stock International route and goes out to a carrier. 22 digits is longer than
every fixed length stock pattern (the longest, `^9(\d{12,20})$`, takes up to 21
digits), and any number an enabled outbound route of the domain (or a global
one) matches is refused, which covers open ended patterns such as a custom
prefix `^9(\d*)$`. Any other unused number can be entered instead.
`limit > ivr_menus` applies as in the IVR Menu editor.

## Fax setup

**Applications > Fax Setup** creates a domain's fax server in one step (fax
extension, name and email, created the same way as in the Fax Server app) and
holds the domain's fax cover logo. The **Fax Extension** is filled in with a
suggestion: the first free number of `fax > extension_range` when that is set,
otherwise a random unused number. Nobody needs to dial it, since inbound
routes send faxes to it. The suggestion skips numbers any enabled outbound
route of the domain matches, so a call sent to a disabled fax server doesn't
go out to a carrier. The stock route options cover every length up to 20
digits and the Fax Server editor keeps 15, so the suggestion is 15 digits when
no route matches that length, and shorter otherwise (e.g. 9 digits with the
international, 11 and 10 digit routes). An outbound route added later is not
checked against existing fax numbers. You can type your own number instead.

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
needs them in plaintext, so plaintext copies still exist in `v_gateways`, Outbound Routes (IP
authentication tech prefixes), the generated gateway XML and FusionPBX's
transaction log. The keys are in
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
  - Networks broader than /16 (IPv6: /48), such as `0.0.0.0/0` in the web list, are
    skipped with a warning: whitelisting them would turn off brute-force
    protection for everyone.
  - Addresses and networks in a `[DEFAULT]` `ignoreip` in `jail.local` are
    kept (host names are not).
  - Without the three keys, or once they are removed, there is no whitelist. An
    invalid value keeps that list's last good whitelist (if any), with a
    warning.
  - If a list can't be read (an invalid value, or a stack whose role wasn't
    updated), `x-aws-sync.service` shows as failed after each hourly run until
    it's fixed; the carrier ACL still updates.
- If AWS can't be reached during install, the install continues with a
  warning. The feeds then report the failure. Fix the cause and run
  `x-aws-sync`.
- Telnyx must also know the Elastic IP: add it to the IP authentication of
  your Telnyx connection.

## Backup and restore

`x-backup` runs nightly (`x-backup.timer`) and writes one bundle per run to
`/var/backups/x-install/<timestamp>/`. Its settings are in `config.sh` and
take effect after `./upgrade.sh`:

| Setting | Default | |
|---|---|---|
| `backup_dir` | `/var/backups/x-install` | absolute, not under `/var/www` |
| `backup_time` | `*-*-* 02:30:00` | systemd `OnCalendar`, plus up to 15 min random delay; a missed run happens at boot |
| `backup_retention_days` | 14 | |
| `backup_min_free_percent` | 15 | refuse a backup that would leave less free space |
| `backup_offsite_cmd` | empty | run after each backup with the bundle directory as `$1`; if it fails the run fails, the local bundle is kept |

The timer skips a run while a backup or restore is going. A bundle has:

- the whole `fusionpbx` database;
- `/etc/freeswitch`, FreeSWITCH storage (voicemail, fax) and recordings;
- custom music on hold;
- `/var/lib/fusionpbx` (provision overlay files, fax logos);
- a manifest and checksums.

Bundles older than `backup_retention_days` are removed, but never the newest
one. Run `x-backup` by hand at any time (`--dir DIR`, `--no-prune`,
`--no-offsite`).

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

It also refuses when a different key with the same id is already in the key
directory. It then shows a summary and asks you to type `restore` (`--yes`
skips this, for unattended runs).

Then it backs up the current state to `/var/backups/x-install/pre-restore/`
(`--no-pre-backup` skips this)
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

## Testing

```sh
test/lint.sh                    # shellcheck (local), cfn-lint if installed, php -l, python syntax, x-aws-sync signing self-test (Docker)
test/install-test.sh [bookworm|trixie] [--keep]  # install, preflight refusal, upgrade (also --optional), EC2 time sync, AWS flow; default trixie
test/restore-test.sh [bookworm|trixie] [--keep]  # backup, refusals, restore on a fresh host and in place
```

## Known limitations

- IVR schedules use upstream's time condition handling, including its time
  zone behavior. Routing was tested manually with loopback calls in the test
  container, not with calls from a carrier; the automated tests don't place
  calls. Schedule rules have their own limitations,
  listed under [Schedule rule limitations](#schedule-rule-limitations).
- Rebooting an endpoint reboots every phone that shares its extension.
- The provision overlay cannot stop one tenant from reading another tenant's
  templates. This is an upstream limitation.
- Carrier ACL on nftables: in `allow` mode the firewall rule only counts
  traffic. Carriers are protected from bans by the fail2ban exemption.
  `restrict` mode works as described.
- A firewall reload or flush that is not a restart of netfilter-persistent or
  nftables removes the carrier ACL rules (and, on AWS, the metadata guard)
  until the next apply or boot. Run `systemctl start x-carrier-acl-apply` (and
  `x-imds-guard apply`). Restarts re-apply them automatically.
- On trixie, upstream's fail2ban ssh jail fails because it expects
  `/var/log/auth.log`. This is an upstream issue.
