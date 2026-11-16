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
	if (!permission_exists('x_ivr_manager_view') || !permission_exists('x_ivr_manager_add')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//initialize the settings object
	$settings = new settings(['database' => $database, 'domain_uuid' => $_SESSION['domain_uuid'] ?? '', 'user_uuid' => $_SESSION['user_uuid'] ?? '']);

//set the variables
	$domain_uuid = $_SESSION['domain_uuid'];
	$domain_name = $_SESSION['domain_name'];
	$form = [
		'ivr_menu_name' => '',
		'ivr_menu_extension' => '',
		'ivr_menu_description' => '',
	];

//initialize the ivr manager
	$manager = new x_ivr_manager(['database' => $database, 'settings' => $settings, 'domain_uuid' => $domain_uuid, 'domain_name' => $domain_name]);

//check the ivr menu limit before the form is filled in, as upstream does
	$limit_ok = $manager->ivr_menu_limit_ok();
	if ($limit_ok !== true) {
		message::add($limit_ok === null ? $text['message-ivr_create_failed'] : $text['message-ivr_limit'], 'negative');
		header('Location: ivr_manager.php');
		exit;
	}

//create the ivr menu
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: ivr_manager.php');
				exit;
			}

		//create it, then continue in the manager
			foreach (array_keys($form) as $field) {
				$form[$field] = (string)($_POST[$field] ?? '');
			}
			$ivr_menu_uuid = $manager->create_ivr_menu($form);
			if ($ivr_menu_uuid !== false) {
				message::add($text['message-ivr_created']);
				header('Location: ivr_manager_edit.php?id='.urlencode($ivr_menu_uuid));
				exit;
			}

		//show the form again with the posted values
			$error_key = is_string($manager->message) ? $manager->message : '';
			if ($error_key === 'message-ivr_extension_in_use' || $error_key === 'message-ivr_extension_routable') {
				message::add(sprintf($text[$error_key], $form['ivr_menu_extension'], $manager->number_used_by), 'negative');
			}
			else {
				message::add($text[$error_key] ?? $text['message-failed'], 'negative');
			}
			unset($error_key);
	}
	else {
		$form['ivr_menu_extension'] = $manager->generate_extension();
		if ($form['ivr_menu_extension'] === '') {
			message::add($text['message-ivr_extension_generate_failed'], 'negative');
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-ivr_manager_add'];
	require_once "resources/header.php";

//show the content
	echo "<form name='frm' id='frm' method='post'>\n";
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-ivr_manager_add']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'ivr_manager.php']);
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save','style'=>'margin-left: 15px;']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-ivr_manager_add']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-name']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='ivr_menu_name' maxlength='255' value=\"".escape($form['ivr_menu_name'])."\" required='required' autofocus>\n";
	echo "	<br />".$text['description-ivr_menu_name']."\n";
	echo "</td>\n";
	echo "</tr>\n";
	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-extension']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='ivr_menu_extension' maxlength='32' pattern='[0-9]+' inputmode='numeric' style='width: 22em;' value=\"".escape($form['ivr_menu_extension'])."\" required='required'>\n";
	echo "	<br />".$text['description-ivr_menu_extension']."\n";
	echo "</td>\n";
	echo "</tr>\n";
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-description']."</td>\n";
	echo "<td class='vtable' align='left'><input class='formfld' type='text' name='ivr_menu_description' maxlength='255' value=\"".escape($form['ivr_menu_description'])."\"></td>\n";
	echo "</tr>\n";
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
