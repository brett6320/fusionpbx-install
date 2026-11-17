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
	Web interface branding images: https://<host>/branding-assets/<scope>/<file>, scope 'global' or a
	domain uuid (docs/design/branding.md). nginx sends /branding-assets/ to this script. No session: the
	login page shows the logo and favicon before anyone signs in; the files are non-sensitive branding.
	The path comes from the scope's metadata only. Everything that does not match exactly is a 404.
*/

//disable the php session, as app/provision/index.php does
	$no_session = true;

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once __DIR__ . "/resources/classes/provision_branding.php";

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

//get the scope and the file name from the request path
	$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
	if (preg_match('#^/branding-assets/(global|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/([a-z0-9._-]{1,80})$#', $path, $matches) !== 1) {
		x_provision_overlay_not_found();
	}
	$scope = $matches[1];
	$file_name = $matches[2];

//a domain's images are served only while the domain exists and is enabled
	if ($scope !== provision_branding::global_scope) {
		$sql = "select count(*) from v_domains ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and domain_enabled = true ";
		$parameters['domain_uuid'] = $scope;
		$count = $database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		if (empty($count)) {
			x_provision_overlay_not_found();
		}
	}

//the file must be recorded in the scope's metadata (realpath checked)
	$asset = provision_branding::file_info($scope, $file_name);
	if ($asset === null) {
		x_provision_overlay_not_found();
	}

//send the file with the type sniffed at upload, never one derived from the name; names are content-hashed,
//so a new image gets a new url and the old one can be cached long
	$size = filesize($asset['path']);
	if ($size === false) {
		x_provision_overlay_not_found();
	}
	header('Content-Type: '.$asset['mime']);
	header('Content-Length: '.$size);
	header('X-Content-Type-Options: nosniff');
	header("Content-Security-Policy: default-src 'none'");
	header('Cache-Control: public, max-age=2592000, immutable');
	header('Last-Modified: '.gmdate('D, d M Y H:i:s', (int) filemtime($asset['path'])).' GMT');
	if ($method === 'GET') {
		readfile($asset['path']);
	}
	exit;

?>
