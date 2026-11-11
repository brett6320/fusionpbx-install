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

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!(permission_exists('x_fax_setup_view') && permission_exists('x_fax_setup_logo'))) {
		http_response_code(403);
		echo "access denied";
		exit;
	}

//the domain is always the session domain, no request parameter selects a file
	if (empty($_SESSION['domain_uuid']) || !is_uuid($_SESSION['domain_uuid'])) {
		http_response_code(403);
		echo "access denied";
		exit;
	}
	$x_fax = new x_fax_setup;
	$file = $x_fax->logo_file();

//only serve the logo this app manages for the domain
	$rows = $x_fax->get_logo_setting();
	if (!is_array($rows) || $rows[0]['domain_setting_value'] !== $x_fax->logo_setting_value() || !is_file($file)) {
		http_response_code(404);
		exit;
	}

//confirm the content is a png
	$finfo = new finfo(FILEINFO_MIME_TYPE);
	if ($finfo->file($file) !== 'image/png') {
		http_response_code(404);
		exit;
	}

//send the image
	header('Content-Type: image/png');
	header('Content-Length: '.filesize($file));
	header('X-Content-Type-Options: nosniff');
	header("Content-Security-Policy: default-src 'none'; img-src 'self'");
	header('Cache-Control: private, no-store');
	readfile($file);
	exit;

?>
