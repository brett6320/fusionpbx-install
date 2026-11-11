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
	if (!permission_exists('x_carrier_account_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//create the store, scoped to the session domain unless x_carrier_all
	$store = new x_carriers;

//action add or update
	$account_uuid = '';
	if (!empty($_REQUEST['id']) && is_uuid($_REQUEST['id'])) {
		$account_uuid = $_REQUEST['id'];
	}
	$action = $account_uuid !== '' ? 'update' : 'add';
	if ($action == 'add' && !permission_exists('x_carrier_account_add')) {
		echo "access denied";
		exit;
	}
	$can_edit = ($action == 'add') ? permission_exists('x_carrier_account_add') : permission_exists('x_carrier_account_edit');
	$can_manage_credentials = permission_exists('x_carrier_account_edit') && permission_exists('x_carrier_credential_edit');

//load the account
	if ($action == 'update') {
		$account = $store->account($account_uuid);
		if ($account === null) {
			message::add($text['error-not_found'], 'negative');
			header('Location: x_carrier_accounts.php');
			exit;
		}
	}
	else {
		$account = ['x_carrier_account_name' => '', 'x_carrier_uuid' => '', 'x_carrier_account_purpose' => 'voice',
			'x_carrier_account_enabled' => 'true', 'x_carrier_account_description' => '', 'domain_uuid' => $_SESSION['domain_uuid']];
	}
	$carriers = $store->carriers();

//process the http post
	if (!empty($_POST)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: x_carrier_accounts.php');
				exit;
			}

			$post_action = $_POST['action'] ?? '';
			$return_url = 'x_carrier_account_edit.php?id='.urlencode($account_uuid);

		//delete the account
			if ($post_action == 'delete') {
				if ($action != 'update' || !permission_exists('x_carrier_account_delete')) {
					message::add($text['error-permission'], 'negative');
					header('Location: x_carrier_accounts.php');
					exit;
				}
				$error = $store->delete_account($account_uuid);
				if ($error === null) {
					message::add($text['message-delete']);
					header('Location: x_carrier_accounts.php');
				}
				else {
					message::add($text[$error], 'negative');
					header('Location: '.$return_url);
				}
				exit;
			}

		//adopt an existing gateway into a new sip_digest credential
			if ($post_action == 'adopt') {
				if ($action != 'update' || !$can_manage_credentials) {
					message::add($text['error-permission'], 'negative');
					header('Location: '.$return_url);
					exit;
				}
				$gateway_uuid = $_POST['gateway_uuid'] ?? '';
				$label = substr(preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) ($_POST['label'] ?? '')), 0, 64);
				if (!is_uuid($gateway_uuid) || $label === '') {
					message::add($text['error-adopt'], 'negative');
					header('Location: '.$return_url);
					exit;
				}
				try {
					$push = new x_carrier_push($store);
					$credential_uuid = $push->adopt_gateway($account, $gateway_uuid, $label);
				}
				catch (Throwable $e) {
					$credential_uuid = null;
				}
				if ($credential_uuid === null) {
					message::add($text['error-adopt'], 'negative');
					header('Location: '.$return_url);
				}
				else {
					message::add($text['message-adopted']);
					header('Location: x_carrier_credential_edit.php?id='.urlencode($credential_uuid));
				}
				exit;
			}

		//save the account
			if (!$can_edit) {
				message::add($text['error-permission'], 'negative');
				header('Location: x_carrier_accounts.php');
				exit;
			}
			$account['x_carrier_account_name'] = substr(trim((string) ($_POST['x_carrier_account_name'] ?? '')), 0, 64);
			if ($action == 'add') {
				$account['x_carrier_uuid'] = $_POST['x_carrier_uuid'] ?? '';
			}
			$account['x_carrier_account_purpose'] = $_POST['x_carrier_account_purpose'] ?? '';
			$account['x_carrier_account_enabled'] = ($_POST['x_carrier_account_enabled'] ?? '') === 'true' ? 'true' : 'false';
			$account['x_carrier_account_description'] = substr(trim((string) ($_POST['x_carrier_account_description'] ?? '')), 0, 255);

			$msg = [];
			if ($account['x_carrier_account_name'] === '') {
				$msg[] = $text['message-required'].' '.$text['label-account_name'];
			}
			$selected_carrier = is_uuid($account['x_carrier_uuid']) ? $store->carrier($account['x_carrier_uuid']) : null;
			if ($selected_carrier === null || ($action == 'add' && $selected_carrier['x_carrier_enabled'] !== 'true')) {
				$msg[] = $text['message-required'].' '.$text['label-carrier'];
			}
			if (!in_array($account['x_carrier_account_purpose'], x_carriers::purposes, true)) {
				$msg[] = $text['message-required'].' '.$text['label-purpose'];
			}
			if (empty($msg)) {
				$row = [
					'x_carrier_account_uuid' => $action == 'update' ? $account_uuid : uuid(),
					'domain_uuid' => $account['domain_uuid'],
					'x_carrier_uuid' => $account['x_carrier_uuid'],
					'x_carrier_account_name' => $account['x_carrier_account_name'],
					'x_carrier_account_purpose' => $account['x_carrier_account_purpose'],
					'x_carrier_account_enabled' => $account['x_carrier_account_enabled'],
					'x_carrier_account_description' => $account['x_carrier_account_description'],
				];
				if ($store->save_account($row)) {
					//enabled state and names flow into gateways: re-push the account's credentials
					if ($action == 'update') {
						$push = new x_carrier_push($store);
						foreach ($store->credentials($account_uuid) as $credential) {
							if ($credential['x_carrier_credential_type'] !== 'api_token' && (int) $credential['binding_count'] > 0) {
								try {
									$push->push($credential['x_carrier_credential_uuid']);
								}
								catch (Throwable $e) {
									message::add($text['error-push'], 'negative');
								}
							}
						}
					}
					message::add($text[$action == 'update' ? 'message-update' : 'message-add']);
					header('Location: x_carrier_account_edit.php?id='.urlencode($row['x_carrier_account_uuid']));
					exit;
				}
				$msg[] = $text['error-save'];
			}
			foreach ($msg as $m) {
				message::add($m, 'negative');
			}
	}

//get the credentials and adoptable gateways
	$credentials = $action == 'update' ? $store->credentials($account_uuid) : [];
	$carrier = $action == 'update' ? $store->carrier($account['x_carrier_uuid']) : null;
	$adoptable = ($action == 'update' && $can_manage_credentials && ($carrier['x_carrier_supports_registration'] ?? '') === 'true') ? $store->adoptable_gateways($account['domain_uuid']) : [];

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_carrier_account'];
	require_once "resources/header.php";

	echo "<form method='post' name='frm' id='frm'>\n";
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_account']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'x_carrier_accounts.php']);
	if ($action == 'update' && permission_exists('x_carrier_audit_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-audit'],'icon'=>'list','link'=>'x_carrier_audit.php?account='.urlencode($account_uuid)]);
	}
	if ($action == 'update' && permission_exists('x_carrier_account_delete')) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_delete','onclick'=>"modal_open('modal-delete','btn_delete');"]);
	}
	if ($can_edit) {
		echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save','style'=>'margin-left: 15px;','name'=>'action','value'=>'save']);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if ($action == 'update' && permission_exists('x_carrier_account_delete')) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'submit','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','name'=>'action','value'=>'delete','onclick'=>"modal_close();"])]);
	}

	echo "<div class='card' style='font-weight: bold;'>".$text['message-plaintext_banner']."</div>\n";
	echo "<br />\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-account_name']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	if ($can_edit) {
		echo "	<input class='formfld' type='text' name='x_carrier_account_name' maxlength='64' value=\"".escape($account['x_carrier_account_name'])."\" required='required'>\n";
		echo "<br />".$text['description-account_name']."\n";
	}
	else {
		echo escape($account['x_carrier_account_name']);
	}
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-carrier']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	if ($can_edit && $action == 'add') {
		echo "	<select class='formfld' name='x_carrier_uuid' required='required'>\n";
		echo "		<option value=''></option>\n";
		foreach ($carriers as $row) {
			if ($row['x_carrier_enabled'] !== 'true') {
				continue;
			}
			echo "		<option value='".escape($row['x_carrier_uuid'])."'".($row['x_carrier_uuid'] === $account['x_carrier_uuid'] ? " selected='selected'" : '').">".escape($row['x_carrier_name'])."</option>\n";
		}
		echo "	</select>\n";
	}
	else {
		foreach ($carriers as $row) {
			if ($row['x_carrier_uuid'] === $account['x_carrier_uuid']) {
				echo escape($row['x_carrier_name']);
			}
		}
		if ($can_edit) {
			echo "	<input type='hidden' name='x_carrier_uuid' value='".escape($account['x_carrier_uuid'])."'>\n";
		}
	}
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-purpose']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	if ($can_edit) {
		echo "	<select class='formfld' name='x_carrier_account_purpose'>\n";
		foreach (x_carriers::purposes as $purpose) {
			echo "		<option value='".$purpose."'".($purpose === $account['x_carrier_account_purpose'] ? " selected='selected'" : '').">".$text['option-'.$purpose]."</option>\n";
		}
		echo "	</select>\n";
		echo "<br />".$text['description-purpose']."\n";
	}
	else {
		echo escape($text['option-'.$account['x_carrier_account_purpose']] ?? $account['x_carrier_account_purpose']);
	}
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-enabled']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	if ($can_edit) {
		echo "	<select class='formfld' name='x_carrier_account_enabled'>\n";
		echo "		<option value='true'".($account['x_carrier_account_enabled'] === 'true' ? " selected='selected'" : '').">".$text['label-true']."</option>\n";
		echo "		<option value='false'".($account['x_carrier_account_enabled'] !== 'true' ? " selected='selected'" : '').">".$text['label-false']."</option>\n";
		echo "	</select>\n";
		echo "<br />".$text['description-account_enabled']."\n";
	}
	else {
		echo $text['label-'.($account['x_carrier_account_enabled'] === 'true' ? 'true' : 'false')];
	}
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-description']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	if ($can_edit) {
		echo "	<input class='formfld' type='text' name='x_carrier_account_description' maxlength='255' value=\"".escape($account['x_carrier_account_description'])."\">\n";
	}
	else {
		echo escape($account['x_carrier_account_description']);
	}
	echo "</td>\n";
	echo "</tr>\n";
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	if ($action == 'update') {
		echo "<input type='hidden' name='id' value='".escape($account_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

//credentials of the account (metadata only, never secrets)
	if ($action == 'update') {
		echo "<div class='action_bar sub'>\n";
		echo "	<div class='heading'><b>".$text['header-credentials']."</b></div>\n";
		echo "	<div class='actions'>\n";
		if ($can_manage_credentials) {
			echo button::create(['type'=>'button','label'=>$text['button-add_credential'],'icon'=>$settings->get('theme', 'button_icon_add'),'link'=>'x_carrier_credential_edit.php?account='.urlencode($account_uuid)]);
		}
		echo "	</div>\n";
		echo "	<div style='clear: both;'></div>\n";
		echo "</div>\n";

		echo "<div class='card'>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-credential_label']."</th>\n";
		echo "	<th>".$text['label-credential_type']."</th>\n";
		echo "	<th>".$text['label-public']."</th>\n";
		echo "	<th>".$text['label-secret']."</th>\n";
		echo "	<th class='center'>".$text['label-version']."</th>\n";
		echo "	<th>".$text['label-status']."</th>\n";
		echo "	<th class='center'>".$text['label-bindings']."</th>\n";
		echo "	<th class='hide-sm-dn'>".$text['label-last_pushed']."</th>\n";
		echo "</tr>\n";
		foreach ($credentials as $row) {
			$public = x_carriers::public_decode($row['x_carrier_credential_public']);
			$public_text = [];
			foreach ($public as $key => $value) {
				if ($value !== '') {
					$public_text[] = ($text['label-'.$key] ?? $key).': '.$value;
				}
			}
			$list_row_url = 'x_carrier_credential_edit.php?id='.urlencode($row['x_carrier_credential_uuid']);
			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			echo "	<td><a href='".$list_row_url."'>".escape($row['x_carrier_credential_label'])."</a></td>\n";
			echo "	<td>".escape($text['option-'.$row['x_carrier_credential_type']] ?? $row['x_carrier_credential_type'])."</td>\n";
			echo "	<td>".escape(implode(', ', $public_text))."</td>\n";
			echo "	<td>".($row['has_secret'] === 'true' ? "&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;" : escape($text['label-no_secret']))."</td>\n";
			echo "	<td class='center'>".escape($row['x_carrier_credential_version'])."</td>\n";
			echo "	<td>".escape($text['option-'.$row['x_carrier_credential_status']] ?? $row['x_carrier_credential_status']).(x_carriers::within_window($row) ? '' : ' ('.$text['label-outside_window'].')')."</td>\n";
			echo "	<td class='center'>".escape($row['binding_count'])."</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($row['x_carrier_credential_last_pushed'])."</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";

		//adopt an existing gateway
		if (!empty($adoptable)) {
			echo "<div class='card'>\n";
			echo "<b>".$text['header-adopt_gateway']."</b><br><br>\n";
			echo $text['description-adopt_gateway']."<br><br>\n";
			echo "<form method='post' name='frm_adopt' id='frm_adopt'>\n";
			echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
			echo "<tr>\n";
			echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-gateway']."</td>\n";
			echo "<td width='70%' class='vtable' align='left'>\n";
			echo "	<select class='formfld' name='gateway_uuid' required='required'>\n";
			echo "		<option value=''></option>\n";
			foreach ($adoptable as $row) {
				echo "		<option value='".escape($row['gateway_uuid'])."'>".escape($row['gateway'].' ('.$row['proxy'].', '.$row['profile'].')')."</option>\n";
			}
			echo "	</select>\n";
			echo "</td>\n";
			echo "</tr>\n";
			echo "<tr>\n";
			echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-credential_label']."</td>\n";
			echo "<td class='vtable' align='left'>\n";
			echo "	<input class='formfld' type='text' name='label' maxlength='64' value='primary' required='required'>\n";
			echo "</td>\n";
			echo "</tr>\n";
			echo "</table>\n";
			echo "<br />\n";
			echo "<input type='hidden' name='id' value='".escape($account_uuid)."'>\n";
			echo "<input type='hidden' name='action' value='adopt'>\n";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-adopt'],'icon'=>'link']);
			echo "</form>\n";
			echo "</div>\n";
			echo "<br />\n";
		}
	}

//include the footer
	require_once "resources/footer.php";

?>
