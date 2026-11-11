<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.
*/

/*
	Reveal a credential secret once. POST only, CSRF token required,
	x_carrier_credential_reveal required, audited, never cached.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//never cache, never frame
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	header('Pragma: no-cache');
	header('X-Content-Type-Options: nosniff');

	function x_carrier_reveal_respond(int $status, array $body): void {
		http_response_code($status);
		echo json_encode($body, JSON_UNESCAPED_SLASHES);
		exit;
	}

//post only
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
		header('Allow: POST');
		x_carrier_reveal_respond(405, ['ok' => false]);
	}

//check permissions
	if (!permission_exists('x_carrier_credential_reveal')) {
		x_carrier_reveal_respond(403, ['ok' => false]);
	}

//validate the token, then issue a fresh one (the used token is cleared on create)
	$object = new token;
	if (!$object->validate($_SERVER['PHP_SELF'])) {
		x_carrier_reveal_respond(403, ['ok' => false]);
	}
	$next_token = $object->create($_SERVER['PHP_SELF']);
	$next = ['name' => $next_token['name'], 'hash' => $next_token['hash']];

//load the credential, scoped to the session domain unless x_carrier_all
	$credential_uuid = $_POST['id'] ?? '';
	if (!is_uuid($credential_uuid)) {
		x_carrier_reveal_respond(400, ['ok' => false, 'token' => $next]);
	}
	$store = new x_carriers;
	$credential = $store->credential($credential_uuid, true);
	if ($credential === null || empty($credential['x_carrier_credential_secret'])) {
		x_carrier_reveal_respond(404, ['ok' => false, 'token' => $next]);
	}

//decrypt, audit, return once
	try {
		$secret = $store->open_secret($credential);
		$store->audit('reveal', $credential['x_carrier_account_uuid'], $credential_uuid, [
			'version' => (int) $credential['x_carrier_credential_version'],
			'fingerprint' => x_carrier_keyring::fingerprint($secret, $credential['x_carrier_credential_key_id']),
		], $credential['domain_uuid']);
		$value = $secret->reveal();
		$secret->wipe();
	}
	catch (Throwable $e) {
		x_carrier_reveal_respond(500, ['ok' => false, 'token' => $next]);
	}
	x_carrier_reveal_respond(200, ['ok' => true, 'value' => $value, 'token' => $next]);

?>
