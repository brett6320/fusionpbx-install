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
	if (!(permission_exists('x_carrier_add') || permission_exists('x_carrier_edit'))) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//the catalogue is global; no domain filter applies
	$store = new x_carriers;

//action add or update
	$carrier_uuid = '';
	if (!empty($_REQUEST['id']) && is_uuid($_REQUEST['id'])) {
		$carrier_uuid = $_REQUEST['id'];
	}
	$action = $carrier_uuid !== '' ? 'update' : 'add';
	if ($action == 'update' && !permission_exists('x_carrier_edit')) {
		echo "access denied";
		exit;
	}
	if ($action == 'add' && !permission_exists('x_carrier_add')) {
		echo "access denied";
		exit;
	}

//field list
	$text_fields = ['x_carrier_name', 'x_carrier_default_proxy', 'x_carrier_default_register_proxy', 'x_carrier_default_outbound_proxy',
		'x_carrier_default_transport', 'x_carrier_default_codec_prefs', 'x_carrier_default_profile', 'x_carrier_api_base_url', 'x_carrier_description'];
	$bool_fields = ['x_carrier_supports_registration', 'x_carrier_supports_ip_auth', 'x_carrier_supports_api', 'x_carrier_enabled'];

//load the row
	$row = [];
	if ($action == 'update') {
		$row = $store->carrier($carrier_uuid);
		if ($row === null) {
			message::add($text['error-not_found'], 'negative');
			header('Location: x_carriers.php');
			exit;
		}
	}
	else {
		$row = ['x_carrier_default_profile' => 'external', 'x_carrier_default_transport' => 'udp', 'x_carrier_enabled' => 'true',
			'x_carrier_supports_registration' => 'true', 'x_carrier_supports_ip_auth' => 'false', 'x_carrier_supports_api' => 'false'];
	}

//process the http post
	if (!empty($_POST)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: x_carriers.php');
				exit;
			}

		//delete
			if (($_POST['action'] ?? '') == 'delete') {
				if (!permission_exists('x_carrier_delete') || $action != 'update') {
					message::add($text['error-permission'], 'negative');
					header('Location: x_carriers.php');
					exit;
				}
				$error = $store->delete_carrier($carrier_uuid);
				message::add($error === null ? $text['message-delete'] : $text[$error], $error === null ? 'positive' : 'negative');
				header('Location: x_carriers.php');
				exit;
			}

		//get the values
			foreach ($text_fields as $field) {
				$row[$field] = trim((string) ($_POST[$field] ?? ''));
			}
			foreach ($bool_fields as $field) {
				$row[$field] = ($_POST[$field] ?? '') === 'true' ? 'true' : 'false';
			}

		//validate
			$msg = [];
			if ($row['x_carrier_name'] === '') {
				$msg[] = $text['message-required'].' '.$text['label-carrier_name'];
			}
			if (!in_array($row['x_carrier_default_transport'], ['', 'udp', 'tcp', 'tls'], true)) {
				$msg[] = $text['error-transport'];
			}
			if ($row['x_carrier_default_profile'] !== '' && !x_carrier_push::valid_profile($row['x_carrier_default_profile'])) {
				$msg[] = $text['error-profile'];
			}
			if ($row['x_carrier_api_base_url'] !== '' && !preg_match('#^https://#i', $row['x_carrier_api_base_url'])) {
				$msg[] = $text['error-api_base_url'];
			}
			//these values are written unescaped into gateway XML by save_gateway_xml()
			if ($row['x_carrier_default_codec_prefs'] !== '' && !preg_match('/^[A-Za-z0-9.,@_\-]+$/', $row['x_carrier_default_codec_prefs'])) {
				$msg[] = $text['error-codec_prefs'];
			}
			foreach (['x_carrier_default_proxy', 'x_carrier_default_register_proxy', 'x_carrier_default_outbound_proxy'] as $field) {
				if ($row[$field] !== '' && !preg_match('/^[A-Za-z0-9.:\-\[\];=_]+$/', $row[$field])) {
					$msg[] = $text['error-proxy'];
					break;
				}
			}

		//save
			if (empty($msg)) {
				$row['x_carrier_uuid'] = $action == 'update' ? $carrier_uuid : uuid();
				if ($store->save_carrier($row)) {
					message::add($text[$action == 'update' ? 'message-update' : 'message-add']);
					header('Location: x_carriers.php');
					exit;
				}
				$msg[] = $text['error-save'];
			}
			foreach ($msg as $m) {
				message::add($m, 'negative');
			}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_carrier'];
	require_once "resources/header.php";

	echo "<form method='post' name='frm' id='frm'>\n";
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'x_carriers.php']);
	if ($action == 'update' && permission_exists('x_carrier_delete')) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_delete','onclick'=>"modal_open('modal-delete','btn_delete');"]);
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save','style'=>'margin-left: 15px;','name'=>'action','value'=>'save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if ($action == 'update' && permission_exists('x_carrier_delete')) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'submit','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','name'=>'action','value'=>'delete','onclick'=>"modal_close();"])]);
	}

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	$fields = [
		'x_carrier_name' => ['label-carrier_name', 'description-carrier_name', true],
		'x_carrier_default_proxy' => ['label-default_proxy', 'description-default_proxy', false],
		'x_carrier_default_register_proxy' => ['label-default_register_proxy', 'description-default_register_proxy', false],
		'x_carrier_default_outbound_proxy' => ['label-default_outbound_proxy', 'description-default_outbound_proxy', false],
		'x_carrier_default_codec_prefs' => ['label-default_codec_prefs', 'description-default_codec_prefs', false],
		'x_carrier_default_profile' => ['label-default_profile', 'description-default_profile', false],
		'x_carrier_api_base_url' => ['label-api_base_url', 'description-api_base_url', false],
	];
	foreach ($fields as $field => $meta) {
		echo "<tr>\n";
		echo "<td width='30%' class='".($meta[2] ? 'vncellreq' : 'vncell')."' valign='top' align='left' nowrap='nowrap'>".$text[$meta[0]]."</td>\n";
		echo "<td width='70%' class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='".$field."' maxlength='255' value=\"".escape($row[$field] ?? '')."\"".($meta[2] ? " required='required'" : '').">\n";
		echo "<br />".$text[$meta[1]]."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-default_transport']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='x_carrier_default_transport'>\n";
	foreach (['', 'udp', 'tcp', 'tls'] as $option) {
		echo "		<option value='".$option."'".(($row['x_carrier_default_transport'] ?? '') === $option ? " selected='selected'" : '').">".escape($option)."</option>\n";
	}
	echo "	</select>\n";
	echo "<br />".$text['description-default_transport']."\n";
	echo "</td>\n";
	echo "</tr>\n";
	foreach (['x_carrier_supports_registration' => 'label-supports_registration', 'x_carrier_supports_ip_auth' => 'label-supports_ip_auth', 'x_carrier_supports_api' => 'label-supports_api', 'x_carrier_enabled' => 'label-enabled'] as $field => $label) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text[$label]."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='".$field."'>\n";
		echo "		<option value='true'".(($row[$field] ?? '') === 'true' ? " selected='selected'" : '').">".$text['label-true']."</option>\n";
		echo "		<option value='false'".(($row[$field] ?? '') !== 'true' ? " selected='selected'" : '').">".$text['label-false']."</option>\n";
		echo "	</select>\n";
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-description']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='x_carrier_description' maxlength='255' value=\"".escape($row['x_carrier_description'] ?? '')."\">\n";
	echo "</td>\n";
	echo "</tr>\n";
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	if ($action == 'update') {
		echo "<input type='hidden' name='id' value='".escape($carrier_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
