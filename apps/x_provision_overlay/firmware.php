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
	Phone-facing firmware: http(s)://<domain_name>/provision-firmware/<release_uuid>/[<segments>/]<file>
	(docs/design/provision_firmware.md 3.5). nginx sends /provision-firmware/ to this script in the :80 and :443
	server blocks and answers /provision-firmware/none/ with a 404 itself.

	No session and no provision HTTP auth (vendor files, no secrets); upstream's provision cidr allow-list
	applies. The path on disk is built from the release row only; the file goes out through nginx
	(X-Accel-Redirect to an internal location), so PHP never streams it. Everything that doesn't match exactly
	is a 404.
*/

//disable the php session, as app/provision/index.php does
	$no_session = true;

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once __DIR__ . "/resources/classes/provision_firmware.php";

//not found helper
	function x_provision_firmware_not_found(): void {
		http_response_code(404);
		header('Content-Type: text/plain');
		header('Cache-Control: no-store');
		exit;
	}

//only GET and HEAD
	$method = $_SERVER['REQUEST_METHOD'] ?? '';
	if ($method !== 'GET' && $method !== 'HEAD') {
		header('Allow: GET, HEAD');
		http_response_code(405);
		exit;
	}

//release and file name from the request path
	$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
	$request = provision_firmware::parse_request($path);
	if ($request === null) {
		x_provision_firmware_not_found();
	}

//get the domain name from the host, as app/provision/index.php does (user:pass@domain_name:port)
	$domain_name = $_SERVER['HTTP_HOST'] ?? '';
	if (str_contains($domain_name, '@')) {
		$domain_name = explode("@", $domain_name, 2)[1];
	}
	if (str_contains($domain_name, ':')) {
		$domain_name = explode(":", $domain_name)[0];
	}
	if ($domain_name === '') {
		x_provision_firmware_not_found();
	}

//the domain must exist and be enabled, with provisioning on
	$sql = "select domain_uuid from v_domains ";
	$sql .= "where lower(domain_name) = lower(:domain_name) ";
	$sql .= "and domain_enabled = true ";
	$parameters['domain_name'] = $domain_name;
	$domain_uuid = $database->select($sql, $parameters, 'column');
	unset($sql, $parameters);
	if (empty($domain_uuid) || !is_uuid($domain_uuid)) {
		x_provision_firmware_not_found();
	}
	$domain_settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);
	if (!$domain_settings->get('provision', 'enabled', false)) {
		x_provision_firmware_not_found();
	}

//upstream's provision cidr allow-list (app/provision/index.php)
	$cidrs = $domain_settings->get('provision', 'cidr', []);
	$cidrs = is_array($cidrs) ? $cidrs : (($cidrs === '' || $cidrs === null) ? [] : [$cidrs]);
	if (!empty($cidrs)) {
		$allowed = false;
		foreach ($cidrs as $cidr) {
			if (function_exists('check_cidr') && check_cidr($cidr, $_SERVER['REMOTE_ADDR'] ?? '')) {
				$allowed = true;
				break;
			}
		}
		if (!$allowed) {
			x_provision_firmware_not_found();
		}
	}

//the release, and the file under the name it is served as
	$sql = "select firmware_uuid, firmware_file, firmware_size from v_x_provision_overlay_firmware where firmware_uuid = :uuid ";
	$parameters['uuid'] = $request['uuid'];
	$release = $database->select($sql, $parameters, 'row');
	unset($sql, $parameters);
	if (!is_array($release) || empty($release) || $release['firmware_file'] !== $request['file']) {
		x_provision_firmware_not_found();
	}
	$file = provision_firmware::release_path($release);
	if ($file === null || is_link($file) || !is_file($file)) {
		x_provision_firmware_not_found();
	}

//log the request, then hand the file to nginx
	provision_firmware::log_download($database, $release['firmware_uuid'], $domain_uuid, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $method);
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename="'.$release['firmware_file'].'"');
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: no-store');
	header('X-Accel-Redirect: /x-provision-firmware-files/'.$release['firmware_uuid'].'/'.rawurlencode($release['firmware_file']));
	exit;

?>
