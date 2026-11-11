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
	Approve or reject a pending run (docs/design/carrier_acl.md section 8). POST only, token validated.
	Approve marks the run approved and asks the worker to run; the worker applies the stored proposal on its next
	tick only if the feed content hash still matches (section 5.2). The web process never applies anything itself.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!permission_exists('x_carrier_acl_approve')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//post only, with a valid token
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: x_carrier_acl_runs.php');
		exit;
	}
	$token = new token;
	if (!$token->validate($_SERVER['PHP_SELF'])) {
		message::add($text['message-invalid_token'],'negative');
		header('Location: x_carrier_acl_runs.php');
		exit;
	}

	$run_uuid = is_uuid($_POST['id'] ?? '') ? $_POST['id'] : '';
	$action = $_POST['action'] ?? '';
	if ($run_uuid === '' || !in_array($action, ['approve', 'reject'], true)) {
		header('Location: x_carrier_acl_runs.php');
		exit;
	}

//only a pending run can be decided; the status check is part of the update so a double submit does nothing
	$sql = "update v_x_carrier_acl_runs set run_status = :status, run_approved_by = :user_uuid, run_approved_at = now(), ";
	$sql .= "update_date = now(), update_user = :user_uuid ";
	$sql .= "where x_carrier_acl_run_uuid = :uuid and run_status = 'pending_approval' ";
	$sql .= "returning x_carrier_acl_feed_uuid ";
	$parameters['status'] = ($action === 'approve') ? 'approved' : 'rejected';
	$parameters['user_uuid'] = $_SESSION['user_uuid'];
	$parameters['uuid'] = $run_uuid;
	$feed_uuid = $database->execute($sql, $parameters, 'column');

	if (!is_uuid((string)$feed_uuid)) {
		message::add($text['message-run_not_pending'],'negative');
		header('Location: x_carrier_acl_runs.php?id='.urlencode($run_uuid));
		exit;
	}

	x_carrier_acl::log(LOG_NOTICE, 'run '.$run_uuid.' '.$parameters['status'].' by '.($_SESSION['username'] ?? $_SESSION['user_uuid']));

	if ($action === 'approve') {
		$database->execute("update v_x_carrier_acl_feeds set feed_run_requested = true where x_carrier_acl_feed_uuid = :uuid ", ['uuid' => $feed_uuid]);
		x_carrier_acl::request_run();
		message::add($text['message-run_approved']);
	}
	else {
		message::add($text['message-run_rejected']);
	}
	header('Location: x_carrier_acl_runs.php?id='.urlencode($run_uuid));
	exit;

?>
