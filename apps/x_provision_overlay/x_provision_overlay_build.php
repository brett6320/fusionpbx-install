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
	if (!permission_exists('x_provision_overlay_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//create the object
	$overlay = new provision_overlay(['database' => $database]);

//process the http post
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: x_provision_overlay_build.php');
				exit;
			}

		//rebuild and rollback are superadmin only
			if (!permission_exists('x_provision_overlay_build')) {
				message::add($text['error-permission'], 'negative');
				header('Location: x_provision_overlay_build.php');
				exit;
			}

		$action = $_POST['action'] ?? '';
		if ($action === 'rebuild') {
			$result = $overlay->build();
		}
		elseif ($action === 'rollback') {
			$result = $overlay->rollback();
		}
		if (isset($result)) {
			if ($result['success']) {
				message::add(($action === 'rebuild' ? $text['message-built'] : $text['message-rolled_back']).': '.escape($result['id']));
			}
			foreach ($result['errors'] ?? [] as $error) {
				message::add(escape($error), 'negative');
			}
			foreach ($result['warnings'] ?? [] as $warning) {
				message::add(escape($warning), 'alert');
			}
		}
		header('Location: x_provision_overlay_build.php');
		exit;
	}

//get the status and the verify report
	$status = $overlay->status();
	$can_build = permission_exists('x_provision_overlay_build');

//the verify report and build warnings name other domains, so they are shown only to the build permission (superadmin)
	$report = $can_build ? $overlay->verify() : null;

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_provision_overlay_build'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-x_provision_overlay_build']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'link'=>'x_provision_overlay.php']);
	if ($can_build) {
		echo "<form method='post' class='inline'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		if (count($status['builds']) > 1) {
			echo button::create(['type'=>'submit','label'=>$text['button-rollback'],'icon'=>'undo','name'=>'action','value'=>'rollback','onclick'=>"if (!confirm('".escape(addslashes($text['confirm-rollback']))."')) { this.blur(); return false; }"]);
		}
		echo button::create(['type'=>'submit','label'=>$text['button-rebuild'],'icon'=>$settings->get('theme', 'button_icon_reload'),'name'=>'action','value'=>'rebuild']);
		echo "</form>\n";
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

//status
	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	$rows = [];
	$rows[$text['label-link']] = provision_overlay::link_path.' ('.$status['link_state'].')';
	$rows[$text['label-disabled']] = $status['disabled'] ? $text['label-yes'] : $text['label-no'];
	$rows[$text['label-build']] = ($status['current_id'] !== '') ? $status['current_id'] : '-';
	$rows[$text['label-builds_on_disk']] = implode(', ', $status['builds']);
	$rows[$text['label-upstream_commit']] = $status['upstream_commit'];
	if (is_array($status['build'])) {
		$rows[$text['label-built_from']] = (string) ($status['build']['upstream_commit'] ?? '');
		$rows[$text['label-generated_at']] = (string) ($status['build']['generated_at'] ?? '');
		foreach (($status['build']['counts'] ?? []) as $key => $value) {
			$rows[$text['label-count_'.$key] ?? $key] = (string) $value;
		}
	}
	foreach ($rows as $label => $value) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".escape($label)."</td>\n";
		echo "<td class='vtable' align='left'>".escape($value)."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//verify report
	if ($can_build) {
		echo "<div class='card'>\n";
		echo "<b>".$text['header-verify']."</b><br><br>\n";
		if (empty($report['problems']) && empty($report['warnings'])) {
			echo $text['label-verify_ok']."\n";
		}
		foreach ($report['problems'] as $problem) {
			echo "<b>".$text['label-problem'].":</b> ".escape($problem)."<br>\n";
		}
		foreach ($report['warnings'] as $warning) {
			echo $text['label-warning'].": ".escape($warning)."<br>\n";
		}
		if (is_array($status['build']) && !empty($status['build']['warnings'])) {
			echo "<br><b>".$text['label-build_warnings']."</b><br>\n";
			foreach ($status['build']['warnings'] as $warning) {
				echo escape((string) $warning)."<br>\n";
			}
		}
		echo "</div>\n";
		echo "<br />\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
