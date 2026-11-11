<?php

//titles
$text['title-x_carriers']['en-us'] = "Carriers";
$text['title-x_carrier']['en-us'] = "Carrier";
$text['title-x_carrier_accounts']['en-us'] = "Carrier Credentials";
$text['title-x_carrier_account']['en-us'] = "Carrier Account";
$text['title-x_carrier_credential']['en-us'] = "Carrier Credential";
$text['title-x_carrier_audit']['en-us'] = "Carrier Credential Audit";

//headers
$text['header-credentials']['en-us'] = "Credentials";
$text['header-adopt_gateway']['en-us'] = "Adopt an Existing Gateway";
$text['header-bindings']['en-us'] = "Gateways and Outbound Routes";
$text['header-add_gateway']['en-us'] = "Add a Gateway";
$text['header-add_route']['en-us'] = "Add an Outbound Route";
$text['header-rotation']['en-us'] = "Rotation";
$text['header-about_encryption']['en-us'] = "About Encryption";

//the plaintext banner shown on every list and edit page
$text['message-plaintext_banner']['en-us'] = "Secrets are encrypted in this app. The copy FreeSWITCH uses (Gateways, Outbound Routes, gateway XML) is not encrypted.";

//about encryption
$text['description-about_encryption']['en-us'] = "Secrets are encrypted at rest in the carrier store only; the copies FreeSWITCH uses in Gateways (v_gateways), Outbound Routes (v_dialplans and v_dialplan_details), the gateway XML files and the database transaction log (v_database_transactions) are not encrypted and are protected only by database and file permissions. The web server must decrypt to push credentials, so this does not protect against a compromise of the web server user, which can already read those plaintext copies. It protects API tokens and pending or retired secrets in database dumps and backups, keeps secrets out of SQL consoles and exports, and gives one place to rotate, audit and expire credentials.";
$text['description-transaction_log']['en-us'] = "The upstream transaction log may contain historical plaintext gateway passwords; superadmins can purge it from Advanced > Database Transactions. This app writes its own changes without adding to that log, but the stock Gateways editor still logs every save.";
$text['description-gateway_hover']['en-us'] = "The stock Gateways page shows the same plaintext password when you hover over its password field.";
$text['description-restore']['en-us'] = "Restore: restore the database, restore the key directory from /var/backups/x_carriers-keys/, then run x_carriers_keyring.php check. A database restored without its keys loses API tokens and pending or retired secrets; active SIP credentials can be rebuilt from Gateways with x_carriers_keyring.php reseal-from-gateways.";

//page descriptions
$text['description-x_carriers']['en-us'] = "The global carrier catalogue. Defaults here are copied into the gateways this app creates.";
$text['description-x_carrier_accounts']['en-us'] = "Carrier accounts for this domain. Each account holds SIP digest, IP authentication and API token credentials, and drives the Gateways and Outbound Routes that use them.";
$text['description-x_carrier_audit']['en-us'] = "Every create, secret change, rotation, reveal, push, retirement and deletion. Details never contain secrets; fingerprints let you compare two events without storing the value.";
$text['description-bindings']['en-us'] = "Managed targets were created by this app and are deleted with the binding. Adopted targets existed before; only their credential fields are updated.";
$text['description-route_plaintext']['en-us'] = "A tech prefix is plaintext inside the outbound route's bridge action, visible to anyone who can edit dialplans in this domain. If someone edits a route in the stock editor, it is marked drifted and never overwritten until you re-push.";
$text['description-adopt_gateway']['en-us'] = "Import the password of a gateway in this domain into a new SIP digest credential. From then on only its username, auth username, realm, password and register fields are updated; proxies and codecs stay as they are.";
$text['description-add_gateway']['en-us'] = "Create a gateway for this credential using the carrier defaults.";
$text['description-add_route']['en-us'] = "Create an outbound route that bridges through this credential's gateway, or add this credential as a fail-over bridge to a route this app already manages.";
$text['description-rotation_sip_digest']['en-us'] = "Enter the new password. A second gateway is created next to the current one so registration can be tested side by side. Promote it to move the current bindings to the new password and retire the old one.";
$text['description-rotation_ip_auth']['en-us'] = "Enter the new tech prefix. A parallel outbound route is created at a lower priority. Promote it to update the current routes and retire the old prefix.";
$text['description-rotation_api_token']['en-us'] = "Enter the new API secret. Promote it to make it active and retire the old one.";
$text['description-rotation_pending']['en-us'] = "This credential is pending. Its temporary gateway or route uses the new secret. Promote it to replace the active credential, or cancel to remove it.";
$text['description-rotation_in_progress']['en-us'] = "A rotation is in progress for this credential.";

//field descriptions
$text['description-carrier_name']['en-us'] = "Carrier name.";
$text['description-default_proxy']['en-us'] = "Proxy host or IP, optionally with :port.";
$text['description-default_register_proxy']['en-us'] = "Register proxy, if different from the proxy.";
$text['description-default_outbound_proxy']['en-us'] = "Outbound proxy, if required.";
$text['description-default_transport']['en-us'] = "Register transport.";
$text['description-default_codec_prefs']['en-us'] = "Codec preferences, comma separated, e.g. PCMU,PCMA.";
$text['description-default_profile']['en-us'] = "SIP profile for new gateways, usually external.";
$text['description-api_base_url']['en-us'] = "HTTPS base URL of the carrier API, for future account management features.";
$text['description-account_name']['en-us'] = "Name shown in route pickers.";
$text['description-purpose']['en-us'] = "Voice, fax or both. Fax accounts default to a registering gateway and a fax route.";
$text['description-account_enabled']['en-us'] = "Disabling the account disables the gateways this app manages for it.";
$text['description-credential_type']['en-us'] = "SIP digest: username and password. IP authentication: the carrier trusts this server's IP and a tech prefix selects the account. API token: used by other apps, not pushed to FreeSWITCH.";
$text['description-credential_label']['en-us'] = "e.g. primary.";
$text['description-username']['en-us'] = "SIP username.";
$text['description-auth_username']['en-us'] = "Authentication username, if different from the username.";
$text['description-realm']['en-us'] = "Authentication realm, if required.";
$text['description-from_user']['en-us'] = "From user sent to the carrier.";
$text['description-from_domain']['en-us'] = "From domain sent to the carrier.";
$text['description-key_id']['en-us'] = "API key id (not secret).";
$text['description-secret']['en-us'] = "SIP password, tech prefix (digits, *, # and + only) or API secret. Stored encrypted; never shown again unless revealed.";
$text['description-new_secret']['en-us'] = "Leave empty to keep the current secret. A new value is pushed to Gateways and Outbound Routes immediately.";
$text['description-not_before']['en-us'] = "Optional. The credential is not used before this time.";
$text['description-not_after']['en-us'] = "Optional. The credential is disabled after this time.";
$text['description-register']['en-us'] = "Register with the carrier (needed for inbound and fax over registration).";
$text['description-route']['en-us'] = "Create a new route, or add this credential as the next fail-over bridge of an existing route.";
$text['description-dialplan_name']['en-us'] = "Route name. Defaults to carrier-account-label.";
$text['description-dialplan_expression']['en-us'] = "destination_number regular expression; $1 is sent to the carrier after the prefix. Fax routes use the fax steering prefix, e.g. ^\\*33(\\d+)$.";
$text['description-order']['en-us'] = "Dialplan order of a new route.";

//labels
$text['label-carrier_name']['en-us'] = "Carrier";
$text['label-carrier']['en-us'] = "Carrier";
$text['label-default_proxy']['en-us'] = "Proxy";
$text['label-default_register_proxy']['en-us'] = "Register Proxy";
$text['label-default_outbound_proxy']['en-us'] = "Outbound Proxy";
$text['label-default_transport']['en-us'] = "Transport";
$text['label-default_codec_prefs']['en-us'] = "Codec Preferences";
$text['label-default_profile']['en-us'] = "SIP Profile";
$text['label-api_base_url']['en-us'] = "API Base URL";
$text['label-supports_registration']['en-us'] = "SIP Digest";
$text['label-supports_ip_auth']['en-us'] = "IP Authentication";
$text['label-supports_api']['en-us'] = "API Token";
$text['label-credential_types']['en-us'] = "Credential Types";
$text['label-account_name']['en-us'] = "Account";
$text['label-purpose']['en-us'] = "Purpose";
$text['label-credentials']['en-us'] = "Credentials";
$text['label-credential_label']['en-us'] = "Label";
$text['label-credential_type']['en-us'] = "Type";
$text['label-public']['en-us'] = "Identity";
$text['label-secret']['en-us'] = "Secret";
$text['label-new_secret']['en-us'] = "New Secret";
$text['label-no_secret']['en-us'] = "(erased)";
$text['label-version']['en-us'] = "Version";
$text['label-status']['en-us'] = "Status";
$text['label-bindings']['en-us'] = "Bindings";
$text['label-last_pushed']['en-us'] = "Last Pushed";
$text['label-last_changed']['en-us'] = "Changed";
$text['label-fingerprint']['en-us'] = "Fingerprint";
$text['label-outside_window']['en-us'] = "outside validity window";
$text['label-not_before']['en-us'] = "Valid From";
$text['label-not_after']['en-us'] = "Valid Until";
$text['label-username']['en-us'] = "Username";
$text['label-auth_username']['en-us'] = "Auth Username";
$text['label-realm']['en-us'] = "Realm";
$text['label-from_user']['en-us'] = "From User";
$text['label-from_domain']['en-us'] = "From Domain";
$text['label-key_id']['en-us'] = "Key ID";
$text['label-gateway']['en-us'] = "Gateway";
$text['label-profile']['en-us'] = "SIP Profile";
$text['label-register']['en-us'] = "Register";
$text['label-route']['en-us'] = "Route";
$text['label-dialplan_name']['en-us'] = "Name";
$text['label-dialplan_expression']['en-us'] = "Expression";
$text['label-order']['en-us'] = "Order";
$text['label-binding_type']['en-us'] = "Type";
$text['label-target']['en-us'] = "Target";
$text['label-managed']['en-us'] = "Ownership";
$text['label-managed_true']['en-us'] = "Managed";
$text['label-managed_false']['en-us'] = "Adopted";
$text['label-temporary']['en-us'] = "temporary";
$text['label-state']['en-us'] = "State";
$text['label-date']['en-us'] = "Date";
$text['label-audit_action']['en-us'] = "Action";
$text['label-remote_address']['en-us'] = "Address";
$text['label-detail']['en-us'] = "Detail";

//options
$text['option-sip_digest']['en-us'] = "SIP Digest";
$text['option-ip_auth']['en-us'] = "IP Authentication";
$text['option-api_token']['en-us'] = "API Token";
$text['option-voice']['en-us'] = "Voice";
$text['option-fax']['en-us'] = "Fax";
$text['option-both']['en-us'] = "Voice and Fax";
$text['option-active']['en-us'] = "Active";
$text['option-pending']['en-us'] = "Pending";
$text['option-retired']['en-us'] = "Retired";
$text['option-gateway']['en-us'] = "Gateway";
$text['option-outbound_route']['en-us'] = "Outbound Route";
$text['option-new_route']['en-us'] = "New route";

//binding states
$text['state-ok']['en-us'] = "In sync";
$text['state-drifted']['en-us'] = "Drifted (edited outside this app)";
$text['state-missing']['en-us'] = "Missing";
$text['state-error']['en-us'] = "Cannot verify";

//audit actions
$text['audit-create']['en-us'] = "Create";
$text['audit-update']['en-us'] = "Updated";
$text['audit-update_secret']['en-us'] = "Secret changed";
$text['audit-rotate']['en-us'] = "Rotate";
$text['audit-reveal']['en-us'] = "Reveal";
$text['audit-push']['en-us'] = "Push";
$text['audit-retire']['en-us'] = "Retire";
$text['audit-delete']['en-us'] = "Delete";
$text['audit-key_rotate']['en-us'] = "Key rotation";

//buttons
$text['button-carriers']['en-us'] = "Carriers";
$text['button-audit']['en-us'] = "Audit";
$text['button-add_credential']['en-us'] = "Add Credential";
$text['button-adopt']['en-us'] = "Adopt";
$text['button-reveal']['en-us'] = "Reveal";
$text['button-push']['en-us'] = "Push";
$text['button-repush']['en-us'] = "Re-push";
$text['button-retire']['en-us'] = "Retire";
$text['button-rotate']['en-us'] = "Start Rotation";
$text['button-promote']['en-us'] = "Promote";
$text['button-cancel_rotation']['en-us'] = "Cancel Rotation";
$text['button-open_pending']['en-us'] = "Open the pending credential";
$text['button-add_gateway']['en-us'] = "Add Gateway";
$text['button-add_route']['en-us'] = "Add Route";
$text['button-remove']['en-us'] = "Remove";

//confirmations
$text['confirm-retire']['en-us'] = "Retire this credential? Its managed gateways are disabled.";
$text['confirm-delete_credential']['en-us'] = "Delete this credential and its encrypted secret?";
$text['confirm-delete_binding']['en-us'] = "Remove this binding? A managed gateway or route is deleted.";
$text['confirm-repush']['en-us'] = "Overwrite the route's bridge actions with the values from this app? Changes made in the stock editor are lost.";

//messages
$text['message-adopted']['en-us'] = "Gateway adopted.";
$text['message-pushed']['en-us'] = "Pushed to Gateways and Outbound Routes.";
$text['message-binding_added']['en-us'] = "Binding added.";
$text['message-binding_removed']['en-us'] = "Binding removed.";
$text['message-retired']['en-us'] = "Credential retired.";
$text['message-rotation_started']['en-us'] = "Rotation started. Test the temporary target, then promote.";
$text['message-rotation_promoted']['en-us'] = "Rotation complete. The old credential is retired.";
$text['message-rotation_cancelled']['en-us'] = "Rotation cancelled.";
$text['message-route_drifted']['en-us'] = "An outbound route was changed outside this app and was not overwritten. Review it, then re-push.";

//errors
$text['error-permission']['en-us'] = "Access denied.";
$text['error-not_found']['en-us'] = "Not found.";
$text['error-save']['en-us'] = "The record could not be saved.";
$text['error-transport']['en-us'] = "Transport must be udp, tcp or tls.";
$text['error-profile']['en-us'] = "Invalid SIP profile name.";
$text['error-api_base_url']['en-us'] = "The API base URL must start with https://.";
$text['error-proxy']['en-us'] = "Proxies may contain only letters, digits and . : - [ ] ; = _.";
$text['error-codec_prefs']['en-us'] = "Codec preferences may contain only letters, digits and . , @ _ -.";
$text['error-carrier_in_use']['en-us'] = "This carrier has accounts and cannot be deleted.";
$text['error-account_has_credentials']['en-us'] = "Delete the account's credentials first.";
$text['error-credential_has_bindings']['en-us'] = "Remove the credential's gateways and routes first.";
$text['error-gateway_has_routes']['en-us'] = "Remove the outbound routes that bridge through this gateway first.";
$text['error-rotation_pending']['en-us'] = "Promote or cancel the pending rotation first.";
$text['error-not_pending']['en-us'] = "This credential is not pending.";
$text['error-route_drifted']['en-us'] = "The route was changed outside this app. Re-push or fix it before removing this binding.";
$text['error-public_value']['en-us'] = "Invalid characters in";
$text['error-secret_sip_digest']['en-us'] = "Enter a password without control characters or \" < > &.";
$text['error-secret_ip_auth']['en-us'] = "Enter a tech prefix of digits, *, # or + (up to 32 characters).";
$text['error-secret_api_token']['en-us'] = "Enter a secret without control characters.";
$text['error-retire_adopted']['en-us'] = "This credential drives an adopted gateway, which this app never disables. Remove that binding (or disable the gateway) before retiring.";
$text['error-retired_secret']['en-us'] = "A retired credential's secret cannot be changed.";
$text['error-adopt']['en-us'] = "The gateway could not be adopted.";
$text['error-binding']['en-us'] = "The gateway could not be created.";
$text['error-route_binding']['en-us'] = "The route could not be created. The credential needs a gateway, and an existing route must not have been edited outside this app.";
$text['error-expression']['en-us'] = "Enter a valid regular expression that captures the number, e.g. ^(\\d{11})$.";
$text['error-push']['en-us'] = "Some targets could not be updated. See the audit log.";
$text['error-push_incomplete']['en-us'] = "The rotation finished but some targets were not updated. See the audit log.";
$text['error-rotate']['en-us'] = "The rotation could not be started.";
$text['error-reveal']['en-us'] = "The secret could not be revealed.";
$text['error-key_unavailable']['en-us'] = "The encryption key for this secret is unavailable. Check the key directory and run x_carriers_keyring.php check.";

?>
