# FusionPBX Installer

## Intent
- Refactor the existing FusionPBX installer (https://www.fusionpbx.com/download.php) to:
  - Enable device provisioning (https://docs.fusionpbx.com/en/latest/applications/provision.html) by default, and to allow device profile customizations without introducting conflicts in the upstream codebase. Logos and other customizations should also be implemented such that they do not cause conflict.
  - Include an IVR manager which is user friendly:
    - Rather than requiring numerical values to set priority, allow users to drag and drop within lists to set priority, while maintaining numerical priorities in the background.
    - Allow for TTS prompt generations, or recording from the web ui, with the ability to play back prompts through the interface.
    - Scheduling should be easy and clear: Start on date a and time b, and end on date x and time y.
  - Fax:
    - Fax should be enabled by default, or with a single click on the admin portal.
    - Fax coversheet customization should be integrated where admins of the fax service can upload a logo if they so choose.
- Upgradeabilty and security are paramount.
- All functions must support multi-tenancy.
- Target GA versions with horizontal compatibility: use the latest stable version of each component (OS, FusionPBX, FreeSWITCH, database, web server, etc.) such that all components are mutually compatible.
- We may want to include the ability to configure IPsec tunnels at a later time to allow secure endpoint (internal) communications. This is last priority.
- SIP tokens will need to be used for outbound voice calls - we should likely store these per-domain and per carrier (we may use multiple outbound trunks). Registration will be used for fax (bidirectional).
- Develop a capability to consume carrier endpoint lists (https://sip.telnyx.com/) in some format - this should be flexible - and should update the ACLs in Freeswitch without intervention.
- We should keep Freeswitch in sync with system time periodically.
- Add an endpoint manager app to managed on-demand and scheduled reboots of endpoints (phones)

## Upstream Baseline (reviewed 2026-11-11)
Sources: `fusionpbx/fusionpbx` (master, latest tag 5.6.5) and `fusionpbx/fusionpbx-install.sh` (`debian/`).

Current upstream installer defaults: FusionPBX 5.6, FreeSWITCH 1.11 (stable, source build), sofia-sip 1.13.18, PostgreSQL 18 (official repo), PHP 8.4, nginx, Debian bookworm/trixie. Optional apps are cloned as separate repos into `/var/www/fusionpbx/app/` (speech, transcribe, language_model, device_logs, dialplan_tools, edit, sip_trunks) - this is the upstream-sanctioned extension pattern and should be used for our custom apps.

## Gap Analysis

| Intent | Exists upstream | Gap |
|---|---|---|
| Provisioning on by default | `app/provision` + vendor apps (yealink, poly, grandstream, etc.). Default setting `provision.enabled` ships with value `true` but the row disabled. | Enable it on first install only (guarded by a marker), so an admin who later turns it off is not overridden on upgrade. |
| Provision template customization without conflicts | Provision class picks a base dir (`/usr/share/fusionpbx/templates/provision`, then `/etc/fusionpbx/resources/templates/provision`, then in-tree), but the `provision.template_dir` setting overrides it. Per-domain overrides exist via `<template_dir>/<domain_name>/`, but that subdir is all-or-nothing for the domain. | Settings-only overlay: generate a merged dir (symlinks to upstream templates plus our overlay files, merged per domain too) and point `provision.template_dir` at it. Regenerate on install and upgrade. |
| Logos and branding | Theme settings exist (default/domain settings). | Need a documented settings-only approach; no core file edits. |
| IVR drag-and-drop priority | `ivr_menu_options` has numeric `ivr_menu_option_order`; no drag-and-drop in IVR (or ring groups / time conditions). | New UI that writes `ivr_menu_option_order`. Prefer a separate app over patching `app/ivr_menus`. |
| IVR TTS prompts | `fusionpbx-app-speech` (optional, enabled in installer config) with providers: local, OpenAI, ElevenLabs, Inworld. Recordings app can generate audio via TTS when `speech.enabled`. | Already mostly covered. Gap is integrating it into the IVR workflow (generate from the IVR page) and per-domain provider credentials. |
| IVR prompt recording from web UI | Recordings app supports upload and playback (`recording_play.php`, waveform). No in-browser recording. | Browser recording (MediaRecorder over HTTPS) and conversion to FreeSWITCH-compatible WAV. |
| IVR scheduling (start/end date and time) | `app/time_conditions` supports `date-time` start/stop ranges. Separate from IVR. | Start/end picker from the IVR page that creates a time condition. Time conditions are dialplan rows built inline in `time_condition_edit.php` (no reusable class), so ours must match that structure exactly to stay editable in the stock editor. Use the domain timezone. |
| Fax enabled by default | `app/fax`, `app/fax_queue`, fax-to-email and email-to-fax present; spandsp built with FreeSWITCH. | Not settings-only: each domain needs its own fax server and extension (`v_fax`). Deliver as a one-click "enable fax for this domain" action. |
| Fax coversheet logo | Default/domain settings `fax.cover_logo`, `cover_header`, `cover_footer`, `cover_font`, `cover_message`. | No upload UI - admins must set a path/URL. Need per-domain logo upload with type/size validation (no SVG). |
| SIP credentials per domain and carrier | Gateways are domain-scoped (`domain_uuid`). Optional `fusionpbx-app-sip_trunks` for registration trunks. Passwords stored in plaintext. | Define what "SIP tokens" means for the carrier. Carrier abstraction over multiple gateways per domain. Encryption at rest is limited: FreeSWITCH reads the gateway password from `v_gateways` and writes it into gateway XML on disk, so an encrypted store alone does not remove plaintext copies without patching core. |
| Carrier IP list to ACL sync | `app/access_controls` (global, not domain-scoped) with manual CSV import/export and reload. | Scheduled fetch, parse, validate, diff, apply, `reloadacl`, alert. Keep host firewall (iptables/nftables) in sync. |
| FreeSWITCH time sync | `ntp` installed only on package/master install paths; default source-release path does not. Nothing calls `fsctl sync_clock`. | Install chrony on all paths; timer running `fsctl sync_clock_when_idle`. |
| Endpoint reboots | On-demand per device (`app/devices/cmd.php`, check-sync/reboot via `sofia ... flush_inbound_reg ... reboot`) and bulk from registrations. CLI script `reboot_phones.sh` (interactive, per domain + vendor). | Scheduled reboots, skip phones on active calls, rate limiting, audit log, multi-tenant UI. |
| Multi-tenancy | Core is domain-based; access controls are global. | Every new app must be domain-scoped and permission-gated. ACL sync stays global (system admin only). |
| IPsec (last priority) | None. | Out of scope for now. |
