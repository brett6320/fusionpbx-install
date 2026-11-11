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
	Phone-facing branding assets: http(s)://<domain_name>/provision-assets/<file>.
	nginx sends /provision-assets/ to this script in both the :80 and :443 server blocks.
	No session and no provision HTTP auth (phones do not reliably send credentials for
	secondary downloads; the files are non-sensitive branding). Everything that does not
	match exactly is a 404.
*/

//disable the php session, as app/provision/index.php does
	$no_session = true;

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";

//not found helper
	function x_provision_overlay_not_found(): void {
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

//get the file name from the request path
	$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
	if (preg_match('#^/provision-assets/([a-z0-9][a-z0-9._-]{0,63})$#', $path, $matches) !== 1) {
		x_provision_overlay_not_found();
	}
	$file_name = $matches[1];

//get the domain name from the host, as app/provision/index.php does (user:pass@domain_name:port)
	$domain_name = $_SERVER['HTTP_HOST'] ?? '';
	if (str_contains($domain_name, '@')) {
		$domain_name = explode("@", $domain_name, 2)[1];
	}
	if (str_contains($domain_name, ':')) {
		$domain_name = explode(":", $domain_name)[0];
	}
	if ($domain_name === '') {
		x_provision_overlay_not_found();
	}

//the domain must exist and be enabled
	$sql = "select domain_uuid from v_domains ";
	$sql .= "where lower(domain_name) = lower(:domain_name) ";
	$sql .= "and domain_enabled = true ";
	$parameters['domain_name'] = $domain_name;
	$domain_uuid = $database->select($sql, $parameters, 'column');
	unset($sql, $parameters);
	if (empty($domain_uuid) || !is_uuid($domain_uuid)) {
		x_provision_overlay_not_found();
	}

//provisioning must be enabled for the domain
	$domain_settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid]);
	if (!$domain_settings->get('provision', 'enabled', false)) {
		x_provision_overlay_not_found();
	}

//the file must be a recorded asset of this domain (realpath checked)
	$asset = provision_overlay::asset_info($domain_uuid, $file_name);
	if ($asset === null) {
		x_provision_overlay_not_found();
	}

//send the file with the type sniffed at upload, never one derived from the name
	$size = filesize($asset['path']);
	if ($size === false) {
		x_provision_overlay_not_found();
	}
	header('Content-Type: '.$asset['mime']);
	header('Content-Length: '.$size);
	header('X-Content-Type-Options: nosniff');
	header("Content-Security-Policy: default-src 'none'");
	header('Cache-Control: public, max-age=300');
	header('Last-Modified: '.gmdate('D, d M Y H:i:s', (int) filemtime($asset['path'])).' GMT');
	if ($method === 'GET') {
		readfile($asset['path']);
	}
	exit;

?>
