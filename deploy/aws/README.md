# Deploy on AWS

`deploy/aws/fusionpbx.yaml` creates one Debian 13 instance (`c6a.large` by
default) with an Elastic IP and five security groups. Each group allows one
customer-managed IPv4 prefix list. `deploy/aws/prefix-lists.yaml` creates the
five lists in a separate stack (or bring your own):

| Parameter                       | Opens                                                          | Max entries |
|---------------------------------|----------------------------------------------------------------|-------------|
| `EndpointPrefixListId`          | Internal profile: UDP 5060, TCP 5060-5061; RTP UDP 16384-32768 | 6           |
| `TelnyxSignallingPrefixListId`  | External profile: UDP 5080, TCP 5080-5081                      | 25          |
| `TelnyxMediaPrefixListId`       | RTP UDP 16384-32768                                            | 30          |
| `WebPrefixListId`               | TCP 80, 443                                                    | 6           |
| `SshPrefixListId`               | TCP 22; ICMP echo (ping)                                       | 6           |

The AMI comes from the public SSM parameter
`/aws/service/debian/release/13/latest/amd64`, so a new stack gets the current
Debian image. Updates to an existing stack get it too, unless you pin the AMI
(see below). The template creates an IAM role, so the stack needs
`CAPABILITY_IAM`:

```sh
aws cloudformation deploy \
  --template-file deploy/aws/fusionpbx.yaml \
  --stack-name pbx1 \
  --capabilities CAPABILITY_IAM \
  --parameter-overrides \
    VpcId=vpc-... SubnetId=subnet-... KeyName=my-key \
    EndpointPrefixListId=pl-... TelnyxSignallingPrefixListId=pl-... \
    TelnyxMediaPrefixListId=pl-... WebPrefixListId=pl-... \
    SshPrefixListId=pl-... BackupAdminRoleName=<admin-role> \
    Environment=prod ClientID=... AppCatID=...
```

Keep your own values out of git: put them in `deploy/aws/local/` (ignored),
one `Key=Value` per line, and pass them with
`--parameter-overrides $(grep -v '^#' deploy/aws/local/fpbx.params)`. The
same works for `prefix-lists.yaml`, and for a customized copy of
`backup-plan.json`.

- **Pin the AMI after the first deploy.** CloudFormation resolves the SSM
  parameter again on every update. Once Debian publishes a newer image, any
  update would replace the instance. Pass the stack's
  `AmiId` output as `PinnedImageId` on every update. Changing `KeyName`,
  `SubnetId` or `RootVolumeSize` also replaces the instance. The stack keeps a
  replaced instance (`UpdateReplacePolicy: Retain`), but the Elastic IP moves to
  the new, empty one. Grow the disk with `aws ec2 modify-volume`, not the
  parameter.
- Deleting the stack keeps the instance and its disk, its security groups,
  and the Elastic IP, still attached (`DeletionPolicy: Retain`), so the
  address phones and carriers use is never released by CloudFormation. To
  remove them afterwards:
  1. Turn off termination protection and terminate the instance:
     `aws ec2 modify-instance-attribute --instance-id <id>
     --no-disable-api-termination`, then `aws ec2 terminate-instances`.
     Terminating it detaches the Elastic IP.
  2. Release the address: `aws ec2 release-address --allocation-id
     <eipalloc-id>`. An Elastic IP is billed until you release it.
  3. Delete the five security groups, then the prefix list stack if you no
     longer need it.
- **Prefix lists:** deploy `prefix-lists.yaml` first, in the same region.
  The endpoint, web and SSH lists come from the comma-separated parameters
  `EndpointCidrs`, `WebCidrs` and `SshCidrs`: up to 6 IPv4 CIDRs each, no
  duplicates or empty items (`a,,b`). More than 6 makes the deploy fail.
  After an update that shrinks a list, check it with
  `aws ec2 get-managed-prefix-list-entries`. The Telnyx lists hold
  Telnyx's published addresses, written in the template
  (https://sip.telnyx.com, `voice.json` version 2026-05-25); recheck them
  there from time to time.

  ```sh
  aws cloudformation deploy \
    --template-file deploy/aws/prefix-lists.yaml \
    --stack-name pbx1-prefix-lists \
    --parameter-overrides Environment=prod ClientID=... AppCatID=... \
      EndpointCidrs=192.0.2.0/24,192.0.2.50/32 \
      WebCidrs=203.0.113.5/32 SshCidrs=203.0.113.5/32
  aws cloudformation describe-stacks --stack-name pbx1-prefix-lists \
    --query 'Stacks[0].Outputs' --output table
  ```

  Pass the five IDs from its outputs to the main stack. To change who can
  connect, change the parameters (or the Telnyx entries) and deploy that
  stack again; the security groups
  pick up the change at once, with no update to the main stack, as long as
  each list stays within its max entries. Renaming a list keeps its ID.
  An address family can't be changed in place; make a new list instead.
  Change `MaxEntries` in its own update, not together with `Entries`, and
  don't update again while a change is still applying. The same list can back more than
  one parameter, but its max entries must then fit every group it backs
  (see the ceilings under **Rule quota**).
  - Use IPv4 lists. An IPv6 list deploys without error but admits no IPv4
    traffic. Check with `aws ec2 describe-managed-prefix-lists
    --prefix-list-ids pl-... --query 'PrefixLists[].AddressFamily'`.
- `SubnetId` must be a public subnet.
- Telnyx can reach only the external profile (5080/5081), where FusionPBX
  puts carrier gateways. Calls Telnyx sends to 5060 are blocked.
- RTP is open only to the endpoint and Telnyx media lists. Telnyx media comes
  from different addresses than its signalling; a missing media range means
  calls with no audio. Another carrier needs its own lists and groups.
- **Rule quota.** A rule that references a prefix list counts as the list's
  *max entries*, not its current entries, against the limit of 60 inbound
  rules per group. The sizes above fit without a quota increase: endpoints
  3 × 6, Telnyx signalling 2 × 25, Telnyx media 1 × 30, web 2 × 6, SSH
  2 × 6. Each list's ceiling without a quota increase is 60 divided by its
  group's rule count: endpoints 20, Telnyx signalling 30, Telnyx media 60,
  web 30, SSH 30. Raising a max past its ceiling fails with
  `RulesPerSecurityGroupLimitExceeded` unless you raise the quota (VPC quota
  `L-0EA8095F`) first. A list's max entries can't go below the number of
  entries it has.
- Five security groups is AWS's default maximum per network interface, so
  the instance can't take another group without a quota increase.
- A failed create keeps whatever retained resources it had already made: the
  backup bucket, the Elastic IP, the security groups, and the instance if it
  launched. Clean them up as above (the bucket as the admin role) before
  retrying, or leave them.
- **Tags:** `Environment`, `ClientID` and `AppCatID` go on the security
  groups, launch template, instance and its root volume, Elastic IP, IAM role
  and bucket. The instance's network interface isn't tagged, and the
  instance profile, Elastic IP association and bucket policy can't be. The
  root volume gets the tags only when the instance is created; tag it by hand
  if you change them later.
- **SSM:** official Debian AMIs don't include the SSM agent, so user data
  installs it from AWS's regional package bucket on first boot. The instance
  role has `AmazonSSMManagedInstanceCore`, so Session Manager works without
  SSH: `aws ssm start-session --target <InstanceId>` (needs the Session
  Manager plugin locally).
  - Session Manager ignores `SshPrefixListId`. Anyone allowed `ssm:StartSession` or
    `ssm:SendCommand` on the instance gets a root shell and the instance
    role's access to the bucket. Restrict those actions in IAM.
  - User data runs on first boot only. Editing it later restarts the instance
    (dropping calls) without running it again.
  - The agent needs outbound HTTPS. The default security group egress allows
    it; keep that if you tighten egress.
- **Instance settings:** on first boot, user data writes
  `/etc/default/fusionpbx-aws` (shell `KEY=value`, world-readable, no
  secrets) with `AWS_REGION`, `AWS_STACK_NAME`, `INSTANCE_ID`, `ELASTIC_IP`,
  `ELASTIC_IP_ALLOCATION_ID`, `TELNYX_SIGNALLING_PREFIX_LIST_ID`,
  `TELNYX_MEDIA_PREFIX_LIST_ID`, `SSH_PREFIX_LIST_ID`, `WEB_PREFIX_LIST_ID`
  and `ENDPOINT_PREFIX_LIST_ID`, so the application can build carrier ACLs,
  set its SIP addresses and keep Fail2ban from banning addresses in the SSH,
  web and endpoint lists (see `fusionpbx-aws.example`). The instance role can
  read the entries of those five lists only (`aws ec2
  get-managed-prefix-list-entries --prefix-list-id
  "$TELNYX_MEDIA_PREFIX_LIST_ID"`), and describe the Elastic IP.
  `DescribeAddresses` and `DescribeManagedPrefixLists` can't be limited to
  one resource in IAM, so the role can list all addresses and prefix lists
  in the region, but not their entries.
  - The file is written once. Changing a Telnyx prefix list parameter
    later restarts the instance (dropping calls) but doesn't update the
    file, and the role then can't read the old lists (AccessDenied); edit
    the file by hand. The same applies to the SSH, web and endpoint list
    parameters. The list IDs stay the same when you
    change a list's entries, so a re-read of the entries picks up Telnyx
    changes.
  - An instance created before this change doesn't get the file: the update
    restarts it (user data changed) but doesn't run user data again. Write
    the file by hand from the stack's parameters and outputs.
  - An instance whose file predates `SSH_PREFIX_LIST_ID`,
    `WEB_PREFIX_LIST_ID` and `ENDPOINT_PREFIX_LIST_ID` keeps working without
    them (no Fail2ban whitelist). After the stack update (which gives the role
    access, and restarts the instance), add the three lines to the file by
    hand from the stack's parameters.
- **Backups** go to the bucket in the `BackupBucketName` output. The bucket is
  private, encrypted and versioned, and only accepts HTTPS.
  - The bucket is write-once (WORM) for the instance: it can create new
    objects and read everything, including old versions, but can never
    delete or overwrite an object, even if someone adds IAM permissions to
    its role. Every upload must be a conditional create with
    `If-None-Match: *`, or S3 returns 403 Access Denied. Uploading to an
    existing key returns 412 Precondition Failed, so give each backup a
    unique key (e.g. a timestamp). For example:
    `aws s3api put-object --bucket <bucket> --key <host>/<timestamp>.tar
    --body <file> --if-none-match '*'`. `aws s3 cp` and `aws s3 sync` don't
    send the header unless your CLI version has an option for it, so check
    before relying on them. The admin role isn't affected.
  - `BackupAdminRoleName` (required) has full control.
    It must be an IAM role in this account with no path, matched by exact
    name. IAM Identity Center permission-set roles don't match. If the role
    doesn't exist, the deploy fails.
  - Everyone else can at most read; no IAM policy can give them more.
    Same-account principals still need IAM permission to read.
  - The bucket and its policy can only be changed by the admin role, so
    deploy, update, delete, and roll back the stack as that role. Change
    `BackupAdminRoleName` in an update on its own.
  - If the admin role is lost, only the account root user (or, with
    centralized root access, the management account via `sts:AssumeRoot`
    with `S3UnlockBucketPolicy`) can unlock the bucket.
  - Deleting the stack keeps the bucket and its policy. It deletes the
    instance role, so a kept instance can no longer write backups.
- **AWS Backup:** the instance has the tag `BackupPlan=fusionpbx`.
  `backup-plan.json` takes a daily snapshot at 03:30 UTC, kept 35 days, and
  a monthly one on the 1st, kept 365 days, in the `Default` vault. On-host
  `x-backup` runs an hour earlier, at 02:30 host time (UTC on a fresh Debian
  AMI). For a local time zone, copy the plan to `deploy/aws/local/` and
  change `ScheduleExpressionTimezone`. `backup-selection.json` picks EC2 instances with that tag; the root
  volume carries the tag too, but is backed up as part of the instance, not
  separately. Create them once per account and region:

  ```sh
  aws backup describe-backup-vault --backup-vault-name Default >/dev/null \
    || aws backup create-backup-vault --backup-vault-name Default
  plan_id=$(aws backup create-backup-plan \
    --backup-plan file://deploy/aws/backup-plan.json \
    --backup-plan-tags Environment=prod,ClientID=...,AppCatID=... \
    --query BackupPlanId --output text)
  account=$(aws sts get-caller-identity --query Account --output text)
  sed "s/ACCOUNT_ID/$account/" deploy/aws/backup-selection.json > "$TMPDIR/selection.json"
  aws backup create-backup-selection --backup-plan-id "$plan_id" \
    --backup-selection "file://$TMPDIR/selection.json"
  ```

  The selection uses `AWSBackupDefaultServiceRole`. AWS creates that role the
  first time someone uses AWS Backup's default role in the console; if it
  doesn't exist, create it there first or put another role in the selection.
  To import with the console instead, paste `backup-plan.json` under
  **Create backup plan > Define a plan using JSON**, then assign resources by
  the tag. The snapshots are crash-consistent: the database is restored as if
  the power was cut. The `x-backup` bundles are the consistent copy.
  - A kept instance (after a stack delete or replacement) still has the tag
    and is still backed up every day. Remove the tag from it when you no
    longer need it.
  - After the first run, check that only `EC2` jobs appear:
    `aws backup list-backup-jobs --by-backup-vault-name Default`.
- Then SSH in as `admin` and follow [Install](../../README.md#install). EC2 uses 1:1 NAT, so
  set FreeSWITCH's `external_sip_ip` and `external_rtp_ip` to the Elastic IP,
  or calls may have one-way audio.

