# Using Carrier Credentials (Telnyx example)

Carrier Credentials (**Accounts > Carrier Credentials**) keeps every carrier
secret in one place: SIP passwords, tech prefixes and API tokens. It encrypts
them, records who changed or viewed them, and writes the values FreeSWITCH
needs into Gateways and Outbound Routes for you. This guide sets up Telnyx, the
carrier the installer ships with.

The design and its security limits are in
[design/carrier_credentials.md](design/carrier_credentials.md).

## Before you start

- **Who can do what.** Superadmins manage the carrier catalogue and every
  account, credential, gateway and route. Admins can view their domain's
  accounts, the audit log, and use API tokens in other apps; they cannot see or
  change secrets.
- **What is encrypted.** The secret is encrypted in this app's store. The copy
  FreeSWITCH uses is not: Gateways, Outbound Routes, the gateway XML files and
  FusionPBX's transaction log keep it in plaintext, protected only by database
  and file permissions. The page shows this as a banner. Don't describe the
  system as "encrypting carrier passwords" without that qualifier.
- **Keys.** The encryption keys are in `/etc/fusionpbx/x_carriers/keys`, with
  a copy in `/var/backups/x_carriers-keys` (a daily cron job refreshes it).
  `x-backup` leaves the keys out of its bundles on purpose, so back up that
  directory separately. A database restored without its keys loses API
  tokens and any pending or retired secrets.

## 1. Check the Telnyx carrier defaults

Open **Carrier Credentials > Carriers > Telnyx** (superadmin). The installer
seeds it with:

| Field | Value |
|---|---|
| Proxy | `sip.telnyx.com` |
| Transport | `udp` |
| SIP Profile | `external` |
| Credential Types | SIP Digest, IP Authentication, API Token |
| API Base URL | `https://api.telnyx.com/v2` |

These values are copied into each gateway the app creates. Editing them here
doesn't change existing gateways right away: managed gateways take the new
values at their next push (saving a credential or the account, Push,
rotation, or a validity window change); adopted gateways never do.

- Set **Codec Preferences** to the codecs your Telnyx connection allows, in
  order, for example `PCMU,PCMA` (empty uses the profile's codecs).
- For TLS, set Transport to `tls`. TLS also has to be enabled on the Telnyx
  connection and on the `external` profile.

## 2. Choose how this server authenticates to Telnyx

Telnyx offers a **SIP connection** per trunk. The connection type decides
which credential type you add here:

| Telnyx connection type | Credential type here | Use it when |
|---|---|---|
| **IP Address** | IP Authentication | The server has a fixed public address. On AWS this is the Elastic IP. This is the recommended setup for this installer. |
| **Credentials** | SIP Digest | The address can change, or you need registration (for example, inbound calls to a host behind NAT). |
| (API key) | API Token | Other apps need the Telnyx API. It is never sent to FreeSWITCH. |

In the Telnyx portal, every connection also needs an **Outbound Voice
Profile** before it can place calls. Assign your phone numbers to the
connection to receive calls on it.

## 3. Create the account

On **Carrier Credentials**, add an account:

- **Carrier:** Telnyx.
- **Account:** a name you'll recognise in route pickers, for example `Telnyx main`.
- **Purpose:** Voice, Fax, or Voice and Fax. Fax and Voice and Fax accounts
  default to a registering gateway; Fax accounts also default to the fax route
  expression `^\*33(\d+)$`.

Each domain has its own accounts. A domain can hold several Telnyx accounts,
for example separate trunks or separate billing.

## 4a. IP authentication (recommended on AWS)

**In the Telnyx portal**

1. Create a SIP connection of type **IP Address**.
2. Add the server's public address (the AWS Elastic IP, from the stack's
   outputs), with the port of the `external` profile, **5080**.
3. Note the connection's **tech prefix**, if it has one. Telnyx uses it to
   tell connections from the same address apart. Without one, Telnyx picks the
   connection by your address.
4. Attach an Outbound Voice Profile and assign your numbers.

**Here**

1. In the account, **Add Credential**, type **IP Authentication**, label
   `primary`.
2. **Secret:** the tech prefix (digits, `*`, `#` and `+` only). Leave it empty
   if the connection has none.
3. **From Domain / From User:** usually empty. Set them only if Telnyx asks
   for a specific From header.
4. Save, then under **Gateways and Outbound Routes**:
   - **Add Gateway** creates a non-registering gateway to `sip.telnyx.com` on
     the `external` profile.
   - **Add Route** creates an outbound route. **Expression** is the
     `destination_number` pattern, for example `^\+?1?(\d{10})$` for North
     American numbers; `$1` is sent to Telnyx after the tech prefix. The route
     bridges `sofia/gateway/<gateway>/<tech prefix>$1`.

**Inbound calls** arrive from Telnyx on the `external` profile. On AWS, the
security groups and the carrier ACL only accept Telnyx's published signalling
and media addresses (see the main README, "AWS instance settings"). Create an
inbound destination for each number as usual (**Dialplan > Destinations**).

## 4b. SIP digest (registration)

**In the Telnyx portal**

1. Create a SIP connection of type **Credentials**, and note its user name
   and password.
2. Attach an Outbound Voice Profile and assign your numbers.

**Here**

1. **Add Credential**, type **SIP Digest**, label `primary`.
2. **Username:** the Telnyx connection user name. Auth Username and Realm are
   usually left empty.
3. **Secret:** the Telnyx connection password. It is shown only once, while
   you type it.
4. Save, then **Add Gateway** with **Register** on, and **Add Route** as in 4a.
5. Check **Status > SIP Status**: the gateway should show `REGED`.

**Already have a Telnyx gateway?** Use **Adopt an Existing Gateway** on the
account page (shown for carriers with SIP digest when the domain has gateways
to adopt) instead of Add Credential
and Add Gateway. The app imports the gateway's password
into a new SIP digest credential. From then on it updates only the user name,
auth user name, realm, password and register fields; proxies and codecs stay as
you set them.

## 4c. API token

1. In the Telnyx portal, create an API key.
2. **Add Credential**, type **API Token**. **Key ID** is optional (Telnyx v2
   keys have no separate ID). **Secret** is the API key.

Nothing is pushed to FreeSWITCH. Other apps read it with the
`x_carrier_credential_api_use` permission.

## 5. Day-to-day

- **Change a secret.** Edit the credential and enter a **New Secret**. It is
  pushed to the gateway and routes at once. Calls in progress are not
  affected; a registering gateway re-registers.
- **Rotate without downtime.** **Start Rotation** and enter the new secret:
  - SIP digest: a second gateway with the new password is created next to the
    current one, so you can check it registers.
  - IP authentication: a parallel route with the new tech prefix is added at a
    lower priority.
  - Then **Promote** to move everything to the new secret and retire the old
    one, or **Cancel Rotation** to remove the temporary gateway or route.
- **Validity window.** **Valid From** and **Valid Until** are optional. Outside
  the window, the credential's gateways are disabled.
- **Retire.** **Retire** disables the managed gateways but keeps the
  credential for the audit log.
- **Reveal.** Superadmins can **Reveal** a secret. Every reveal is recorded.
- **Audit.** **Audit** lists every create, change, rotation, reveal, push,
  retirement and deletion, with who, when and from where. It never contains
  secrets; fingerprints let you tell whether two events used the same value.
- **Edited in the stock editor.** If someone edits a managed outbound route in
  **Dialplan > Outbound Routes**, it is marked *drifted* and never overwritten.
  **Re-push** overwrites it with this app's values. Gateways the app manages
  should be changed here, not on the Gateways page.

## 6. Keys, backups and restores

Run these as shown, from `/var/www/fusionpbx`:

```sh
# check the active key and every key a stored secret uses
sudo -u www-data php app/x_carrier_credentials/resources/cli/x_carriers_keyring.php check

# new key: create it as root, then re-encrypt as www-data
sudo php app/x_carrier_credentials/resources/cli/x_carriers_keyring.php rotate
sudo -u www-data php app/x_carrier_credentials/resources/cli/x_carriers_keyring.php reencrypt

# refresh the key backup now instead of at the next daily run
sudo /etc/cron.daily/x_carriers-keybackup
```

`resources/apps/carrier_credentials.sh upgrade --rotate-key` (from the
installer directory, as root) does all three in one step.

**Maintenance.** `x-carrier-credentials-maintenance.timer` runs every 15
minutes. It pushes credentials whose Valid From / Valid Until window opened or
closed, so those take effect within about 15 minutes, and erases retired
secrets after `x_carriers > retired_retention_days` (default 30).

**Restore:** restore the database, restore the key directory from
`/var/backups/x_carriers-keys/`, then run `check`. If the keys are lost,
active SIP digest secrets can be rebuilt from the gateways:
`... x_carriers_keyring.php reseal-from-gateways`. API tokens and pending or
retired secrets cannot.

**Old plaintext copies.** FusionPBX's transaction log may hold plaintext
gateway passwords from earlier saves. Superadmins can purge it in
**Advanced > Database Transactions**. This app's own writes skip that log, but
saves on the stock Gateways page are still logged.

## Troubleshooting

| Symptom | Check |
|---|---|
| Outbound calls get 403 from Telnyx | IP auth: the connection lists the Elastic IP, and the tech prefix matches. Digest: the gateway is `REGED`. Either way, the connection has an Outbound Voice Profile. |
| Inbound calls never arrive | The numbers are assigned to the connection. IP auth: Telnyx sends to port 5080. On AWS: **Advanced > Carrier ACL** shows the Telnyx feeds applied. |
| One-way audio | `external_sip_ip` and `external_rtp_ip` are the public address (**Advanced > Variables**; on AWS the installer sets them). |
| "outside validity window" on a credential | Adjust Valid From / Valid Until. |
| A route shows *drifted* | Someone changed it in the stock editor. Compare, then **Re-push** or keep their change. |
