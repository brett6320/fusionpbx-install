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

//permissions used on this page
	$can_manage = permission_exists('x_carrier_account_edit') && permission_exists('x_carrier_credential_edit');
	$can_secret = permission_exists('x_carrier_credential_edit');
	$can_reveal = permission_exists('x_carrier_credential_reveal');

//create the store, scoped to the session domain unless x_carrier_all
	$store = new x_carriers;
	$push = new x_carrier_push($store);

//validation helpers; public values and SIP passwords are written unescaped into gateway XML by save_gateway_xml()
	function x_carrier_valid_public(string $type, string $field, string $value): bool {
		if ($value === '') {
			return true;
		}
		if (in_array($field, ['realm', 'from_domain'], true)) {
			return preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $value) === 1;
		}
		if ($field === 'key_id') {
			return preg_match('/^[A-Za-z0-9._@:+\-]{1,255}$/', $value) === 1;
		}
		return preg_match('/^[A-Za-z0-9._@+*#:\-]{1,128}$/', $value) === 1;
	}

	function x_carrier_valid_secret(string $type, string $value): bool {
		if (strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
			return false;
		}
		//database::save trims strings, so the gateway copy would differ from the stored secret
		if ($value !== trim($value)) {
			return false;
		}
		if ($type === 'ip_auth') {
			return $value !== '' && x_carrier_routes::valid_prefix($value);
		}
		if ($type === 'sip_digest') {
			return strpbrk($value, "\"<>&") === false;
		}
		return true;
	}

//load the credential or the account for a new credential
	$credential_uuid = (!empty($_REQUEST['id']) && is_uuid($_REQUEST['id'])) ? $_REQUEST['id'] : '';
	$credential = null;
	if ($credential_uuid !== '') {
		$credential = $store->credential($credential_uuid);
		if ($credential === null) {
			message::add($text['error-not_found'], 'negative');
			header('Location: x_carrier_accounts.php');
			exit;
		}
		$account_uuid = $credential['x_carrier_account_uuid'];
	}
	else {
		$account_uuid = (!empty($_REQUEST['account']) && is_uuid($_REQUEST['account'])) ? $_REQUEST['account'] : '';
		if (!$can_manage) {
			echo "access denied";
			exit;
		}
	}
	$account = $store->account($account_uuid);
	if ($account === null) {
		message::add($text['error-not_found'], 'negative');
		header('Location: x_carrier_accounts.php');
		exit;
	}
	$carrier = $store->carrier($account['x_carrier_uuid']) ?? [];
	$action = $credential === null ? 'add' : 'update';

//credential types the carrier offers
	$offered_types = [];
	if (($carrier['x_carrier_supports_registration'] ?? '') === 'true') { $offered_types[] = 'sip_digest'; }
	if (($carrier['x_carrier_supports_ip_auth'] ?? '') === 'true') { $offered_types[] = 'ip_auth'; }
	if (($carrier['x_carrier_supports_api'] ?? '') === 'true') { $offered_types[] = 'api_token'; }

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
			$self_url = $credential !== null ? 'x_carrier_credential_edit.php?id='.urlencode($credential_uuid) : 'x_carrier_account_edit.php?id='.urlencode($account_uuid);
			$deny = function () use ($text, $self_url) {
				message::add($text['error-permission'], 'negative');
				header('Location: '.$self_url);
				exit;
			};
			$report_push = function (array $result) use ($text) {
				if (!empty($result['drifted'])) {
					message::add($text['message-route_drifted'], 'negative');
				}
				if (!empty($result['errors'])) {
					message::add($text['error-push'], 'negative');
				}
			};

		try {

		//create a credential
			if ($post_action == 'create') {
				if (!$can_manage || $credential !== null) {
					$deny();
				}
				$type = $_POST['x_carrier_credential_type'] ?? '';
				$label = substr(preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) ($_POST['x_carrier_credential_label'] ?? '')), 0, 64);
				$public = [];
				$msg = [];
				if (!in_array($type, $offered_types, true)) {
					$msg[] = $text['message-required'].' '.$text['label-credential_type'];
				}
				if ($label === '') {
					$msg[] = $text['message-required'].' '.$text['label-credential_label'];
				}
				foreach (x_carriers::public_fields[$type] ?? [] as $field) {
					$public[$field] = trim((string) ($_POST['public_'.$field] ?? ''));
					if (!x_carrier_valid_public($type, $field, $public[$field])) {
						$msg[] = $text['error-public_value'].' '.$text['label-'.$field];
					}
				}
				$secret = new x_carrier_secret((string) ($_POST['secret'] ?? ''));
				unset($_POST['secret'], $_REQUEST['secret']);
				if (!x_carrier_valid_secret($type, $secret->reveal()) || $secret->is_empty()) {
					$msg[] = $text['error-secret_'.($type ?: 'api_token')] ?? $text['error-secret_api_token'];
				}
				$not_before = x_carriers::normalize_datetime($_POST['not_before'] ?? '');
				$not_after = x_carriers::normalize_datetime($_POST['not_after'] ?? '');
				if (empty($msg)) {
					$new_uuid = $store->create_credential($account, $type, $label, $public, $secret, 'active', null, $not_before, $not_after);
					$secret->wipe();
					if ($new_uuid !== null) {
						message::add($text['message-add']);
						header('Location: x_carrier_credential_edit.php?id='.urlencode($new_uuid));
						exit;
					}
					$msg[] = $text['error-save'];
				}
				$secret->wipe();
				foreach ($msg as $m) {
					message::add($m, 'negative');
				}
				header('Location: x_carrier_credential_edit.php?account='.urlencode($account_uuid));
				exit;
			}

			if ($credential === null) {
				$deny();
			}
			$type = $credential['x_carrier_credential_type'];

		//save metadata and optionally a new secret
			if ($post_action == 'save') {
				if (!$can_secret) {
					$deny();
				}
				$fields = [];
				$msg = [];
				$label = substr(preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) ($_POST['x_carrier_credential_label'] ?? '')), 0, 64);
				if ($label === '') {
					$msg[] = $text['message-required'].' '.$text['label-credential_label'];
				}
				$public = [];
				foreach (x_carriers::public_fields[$type] as $field) {
					$public[$field] = trim((string) ($_POST['public_'.$field] ?? ''));
					if (!x_carrier_valid_public($type, $field, $public[$field])) {
						$msg[] = $text['error-public_value'].' '.$text['label-'.$field];
					}
				}
				$secret = new x_carrier_secret((string) ($_POST['secret'] ?? ''));
				unset($_POST['secret'], $_REQUEST['secret']);
				if (!$secret->is_empty() && !x_carrier_valid_secret($type, $secret->reveal())) {
					$msg[] = $text['error-secret_'.$type];
				}
				if (!$secret->is_empty() && $credential['x_carrier_credential_status'] === 'retired') {
					$msg[] = $text['error-retired_secret'];
				}
				if (!empty($msg)) {
					$secret->wipe();
					foreach ($msg as $m) {
						message::add($m, 'negative');
					}
					header('Location: '.$self_url);
					exit;
				}
				$not_before = x_carriers::normalize_datetime($_POST['not_before'] ?? '');
				$not_after = x_carriers::normalize_datetime($_POST['not_after'] ?? '');
				$store->update_credential($credential_uuid, [
					'x_carrier_credential_label' => $label,
					'x_carrier_credential_public' => x_carriers::public_encode($type, $public),
				]);
				$store->update_credential($credential_uuid, array_filter([
					'x_carrier_credential_not_before' => $not_before,
					'x_carrier_credential_not_after' => $not_after,
				]));
				$clear = [];
				if ($not_before === null) { $clear[] = 'x_carrier_credential_not_before'; }
				if ($not_after === null) { $clear[] = 'x_carrier_credential_not_after'; }
				$store->clear_credential_columns($credential_uuid, $clear);
				$store->audit('update', $account_uuid, $credential_uuid, [
					'label_from' => $credential['x_carrier_credential_label'], 'label' => $label,
					'public_from' => x_carriers::public_decode($credential['x_carrier_credential_public']), 'public' => $public,
					'not_before' => $not_before, 'not_after' => $not_after,
				], $account['domain_uuid']);

				$previous = null;
				if (!$secret->is_empty()) {
					$full = $store->credential($credential_uuid, true);
					$previous = $store->open_secret($full);
					if ($previous->equals($secret)) {
						$previous->wipe();
						$previous = null;
					}
					else {
						$store->set_secret($full, $secret);
					}
				}
				$secret->wipe();
				if ($type !== 'api_token' && $credential['x_carrier_credential_status'] !== 'retired') {
					$report_push($push->push($credential_uuid, $previous));
				}
				if ($previous !== null) {
					$previous->wipe();
				}
				message::add($text['message-update']);
				header('Location: '.$self_url);
				exit;
			}

		//push or forced re-push
			if ($post_action == 'push' || $post_action == 'repush') {
				if (!$can_secret) {
					$deny();
				}
				$result = $push->push($credential_uuid, null, $post_action == 'repush');
				$report_push($result);
				if ($result['ok']) {
					message::add($text['message-pushed']);
				}
				header('Location: '.$self_url);
				exit;
			}

		//add a managed gateway
			if ($post_action == 'add_gateway') {
				if (!$can_manage || $type === 'api_token') {
					$deny();
				}
				$profile = (string) ($_POST['profile'] ?? '');
				$register = ($_POST['register'] ?? '') === 'true';
				if (!x_carrier_push::valid_profile($profile) || $push->create_gateway_binding($credential, $profile, $register) === null) {
					message::add($text['error-binding'], 'negative');
				}
				else {
					$report_push($push->push($credential_uuid));
					message::add($text['message-binding_added']);
				}
				header('Location: '.$self_url);
				exit;
			}

		//add an outbound route, new or as fail-over on an existing managed route
			if ($post_action == 'add_route') {
				if (!$can_manage || $type === 'api_token') {
					$deny();
				}
				$existing = $_POST['dialplan_uuid'] ?? '';
				$existing = is_uuid($existing) ? $existing : null;
				$name = substr(preg_replace('/[^A-Za-z0-9 ._@-]/', '', (string) ($_POST['dialplan_name'] ?? '')), 0, 128);
				$expression = trim((string) ($_POST['dialplan_expression'] ?? ''));
				$order = (int) ($_POST['dialplan_order'] ?? 300);
				if ($order < 0 || $order > 999) {
					$order = 300;
				}
				if ($existing === null && !x_carrier_routes::valid_expression($expression)) {
					message::add($text['error-expression'], 'negative');
				}
				elseif ($push->create_route_binding($credential, $existing, $name, $expression, $order) === null) {
					message::add($text['error-route_binding'], 'negative');
				}
				else {
					message::add($text['message-binding_added']);
				}
				header('Location: '.$self_url);
				exit;
			}

		//remove a binding
			if ($post_action == 'delete_binding') {
				if (!$can_manage) {
					$deny();
				}
				$binding = $store->binding($_POST['binding_uuid'] ?? '');
				if ($binding === null || $binding['x_carrier_credential_uuid'] !== $credential_uuid) {
					$deny();
				}
				$error = $push->delete_binding($binding);
				message::add($error === null ? $text['message-binding_removed'] : $text[$error], $error === null ? 'positive' : 'negative');
				header('Location: '.$self_url);
				exit;
			}

		//start a rotation
			if ($post_action == 'rotate_start') {
				if (!$can_secret || !$can_manage) {
					$deny();
				}
				$secret = new x_carrier_secret((string) ($_POST['new_secret'] ?? ''));
				unset($_POST['new_secret'], $_REQUEST['new_secret']);
				if ($secret->is_empty() || !x_carrier_valid_secret($type, $secret->reveal())) {
					$secret->wipe();
					message::add($text['error-secret_'.$type], 'negative');
					header('Location: '.$self_url);
					exit;
				}
				$pending_uuid = $push->rotate_start($credential, $secret);
				$secret->wipe();
				if ($pending_uuid === null) {
					message::add($text['error-rotate'], 'negative');
					header('Location: '.$self_url);
				}
				else {
					message::add($text['message-rotation_started']);
					header('Location: x_carrier_credential_edit.php?id='.urlencode($pending_uuid));
				}
				exit;
			}

		//promote or cancel a pending rotation
			if ($post_action == 'rotate_promote' || $post_action == 'rotate_cancel') {
				if (!$can_secret || !$can_manage) {
					$deny();
				}
				$full = $store->credential($credential_uuid, true);
				$error = ($post_action == 'rotate_promote') ? $push->rotate_promote($full) : $push->rotate_cancel($full);
				if ($error === null) {
					message::add($text[$post_action == 'rotate_promote' ? 'message-rotation_promoted' : 'message-rotation_cancelled']);
				}
				else {
					message::add($text[$error], 'negative');
				}
				header('Location: '.($post_action == 'rotate_cancel' && $error === null ? 'x_carrier_account_edit.php?id='.urlencode($account_uuid) : $self_url));
				exit;
			}

		//retire
			if ($post_action == 'retire') {
				if (!$can_secret) {
					$deny();
				}
				$error = $push->retire($credential);
				message::add($error === null ? $text['message-retired'] : $text[$error], $error === null ? 'positive' : 'negative');
				header('Location: '.$self_url);
				exit;
			}

		//delete
			if ($post_action == 'delete') {
				if (!$can_manage) {
					$deny();
				}
				$error = $store->delete_credential($credential);
				if ($error === null) {
					message::add($text['message-delete']);
					header('Location: x_carrier_account_edit.php?id='.urlencode($account_uuid));
				}
				else {
					message::add($text[$error], 'negative');
					header('Location: '.$self_url);
				}
				exit;
			}

		}
		catch (Throwable $e) {
			//never echo the exception: messages are generic and secrets never reach them, but keep the page quiet
			message::add($text['error-key_unavailable'], 'negative');
			header('Location: '.$self_url);
			exit;
		}

		$deny();
	}

//gather view data (metadata only)
	$bindings = [];
	$route_states = [];
	$fingerprint = '';
	$key_error = false;
	$pending = null;
	$managed_routes = [];
	if ($credential !== null) {
		$bindings = $store->bindings($credential_uuid);
		foreach ($bindings as $binding) {
			if ($binding['x_carrier_binding_type'] === 'outbound_route' && is_uuid($binding['dialplan_uuid'])) {
				$route_states[$binding['dialplan_uuid']] = $push->route_state($binding['dialplan_uuid']);
			}
		}
		try {
			$full = $store->credential($credential_uuid, true);
			if (!empty($full['x_carrier_credential_secret'])) {
				$secret = $store->open_secret($full);
				$fingerprint = x_carrier_keyring::fingerprint($secret, $full['x_carrier_credential_key_id']);
				$secret->wipe();
			}
			unset($full);
		}
		catch (Throwable $e) {
			$key_error = true;
		}
		$pending = $credential['x_carrier_credential_status'] === 'active' ? $store->pending_for($credential_uuid) : null;
		$managed_routes = $store->managed_routes($account['domain_uuid']);
	}
	$public = $credential !== null ? x_carriers::public_decode($credential['x_carrier_credential_public']) : [];

//sip profiles for new gateways
	$sql = "select sip_profile_name from v_sip_profiles where sip_profile_enabled = true order by sip_profile_name asc ";
	$sip_profiles = $database->select($sql, null, 'all');
	unset($sql);

//create tokens: one for this page, one for the reveal endpoint
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);
	$reveal_path = dirname($_SERVER['PHP_SELF']).'/x_carrier_credential_reveal.php';
	$reveal_token = $can_reveal ? $object->create($reveal_path) : null;

//show the header
	$document['title'] = $text['title-x_carrier_credential'];
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_credential']."</b> &nbsp; ".escape($account['x_carrier_account_name'])."</div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'x_carrier_account_edit.php?id='.urlencode($account_uuid)]);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card' style='font-weight: bold;'>".$text['message-plaintext_banner']."</div>\n";
	echo "<br />\n";

//shared form helpers
	$hidden = function (string $post_action) use ($token, $credential_uuid, $account_uuid) {
		$out = "<input type='hidden' name='action' value='".$post_action."'>\n";
		if ($credential_uuid !== '') {
			$out .= "<input type='hidden' name='id' value='".escape($credential_uuid)."'>\n";
		}
		else {
			$out .= "<input type='hidden' name='account' value='".escape($account_uuid)."'>\n";
		}
		$out .= "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		return $out;
	};
	$field_row = function (string $label, string $input, string $description = '', bool $required = false) {
		$out = "<tr>\n";
		$out .= "<td width='30%' class='".($required ? 'vncellreq' : 'vncell')."' valign='top' align='left' nowrap='nowrap'>".$label."</td>\n";
		$out .= "<td width='70%' class='vtable' align='left'>\n".$input;
		if ($description !== '') {
			$out .= "<br />".$description."\n";
		}
		$out .= "</td>\n</tr>\n";
		return $out;
	};

//new credential
	if ($action == 'add') {
		echo "<div class='card'>\n";
		echo "<form method='post' name='frm' id='frm' autocomplete='off'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		$select = "	<select class='formfld' name='x_carrier_credential_type' id='x_carrier_credential_type' onchange='x_carrier_type_change();' required='required'>\n";
		foreach ($offered_types as $offered) {
			$select .= "		<option value='".$offered."'>".$text['option-'.$offered]."</option>\n";
		}
		$select .= "	</select>\n";
		echo $field_row($text['label-credential_type'], $select, $text['description-credential_type'], true);
		echo $field_row($text['label-credential_label'], "	<input class='formfld' type='text' name='x_carrier_credential_label' maxlength='64' value='primary' required='required'>\n", $text['description-credential_label'], true);
		foreach (x_carriers::public_fields as $public_type => $fields) {
			foreach ($fields as $field) {
				echo str_replace("<tr>", "<tr class='x_public x_public_".$public_type."'>", $field_row($text['label-'.$field], "	<input class='formfld' type='text' name='public_".$field."' maxlength='255' value=''>\n", $text['description-'.$field]));
			}
		}
		echo $field_row($text['label-secret'], "	<input class='formfld' type='password' name='secret' maxlength='4096' value='' autocomplete='new-password' required='required'>\n", $text['description-secret'], true);
		echo $field_row($text['label-not_before'], "	<input class='formfld' type='datetime-local' name='not_before' value=''>\n", $text['description-not_before']);
		echo $field_row($text['label-not_after'], "	<input class='formfld' type='datetime-local' name='not_after' value=''>\n", $text['description-not_after']);
		echo "</table>\n";
		echo "<br />\n";
		echo $hidden('create');
		echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save')]);
		echo "</form>\n";
		echo "</div>\n";
		echo "<script>\n";
		echo "function x_carrier_type_change() {\n";
		echo "	var type = document.getElementById('x_carrier_credential_type').value;\n";
		echo "	document.querySelectorAll('tr.x_public').forEach(function (row) {\n";
		echo "		var show = row.classList.contains('x_public_' + type);\n";
		echo "		row.style.display = show ? '' : 'none';\n";
		echo "		row.querySelectorAll('input').forEach(function (input) { input.disabled = !show; });\n";
		echo "	});\n";
		echo "}\n";
		echo "x_carrier_type_change();\n";
		echo "</script>\n";
		require_once "resources/footer.php";
		exit;
	}

//existing credential: metadata form
	$type = $credential['x_carrier_credential_type'];
	echo "<div class='card'>\n";
	echo "<form method='post' name='frm' id='frm' autocomplete='off'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo $field_row($text['label-credential_type'], escape($text['option-'.$type]));
	echo $field_row($text['label-status'], escape($text['option-'.$credential['x_carrier_credential_status']]).(x_carriers::within_window($credential) ? '' : ' ('.$text['label-outside_window'].')'));
	if ($can_secret) {
		echo $field_row($text['label-credential_label'], "	<input class='formfld' type='text' name='x_carrier_credential_label' maxlength='64' value=\"".escape($credential['x_carrier_credential_label'])."\" required='required'>\n", '', true);
		foreach (x_carriers::public_fields[$type] as $field) {
			echo $field_row($text['label-'.$field], "	<input class='formfld' type='text' name='public_".$field."' maxlength='255' value=\"".escape($public[$field] ?? '')."\">\n", $text['description-'.$field]);
		}
	}
	else {
		echo $field_row($text['label-credential_label'], escape($credential['x_carrier_credential_label']));
		foreach (x_carriers::public_fields[$type] as $field) {
			echo $field_row($text['label-'.$field], escape($public[$field] ?? ''));
		}
	}

	//masked secret: placeholder, version, last changed, fingerprint; never the value
	$masked = "<span style='font-family: monospace;'>&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;</span>";
	$masked .= " &nbsp; ".$text['label-version']." ".escape($credential['x_carrier_credential_version']);
	$masked .= " &nbsp; ".$text['label-last_changed']." ".escape($store->last_secret_change($credential_uuid) ?: $credential['insert_date']);
	if ($fingerprint !== '') {
		$masked .= " &nbsp; ".$text['label-fingerprint']." <span style='font-family: monospace;'>".escape($fingerprint)."</span>";
	}
	if ($key_error) {
		$masked .= "<br /><b>".$text['error-key_unavailable']."</b>";
	}
	if ($can_reveal && !$key_error && $fingerprint !== '') {
		$masked .= "<br />".button::create(['type'=>'button','label'=>$text['button-reveal'],'icon'=>'eye','id'=>'btn_reveal','onclick'=>'x_carrier_reveal();']);
		$masked .= " <span id='x_carrier_revealed' style='font-family: monospace;'></span>\n";
	}
	echo $field_row($text['label-secret'], $masked);
	if ($can_secret && $credential['x_carrier_credential_status'] !== 'retired') {
		echo $field_row($text['label-new_secret'], "	<input class='formfld' type='password' name='secret' maxlength='4096' value='' autocomplete='new-password'>\n", $text['description-new_secret']);
	}
	$not_before = $credential['x_carrier_credential_not_before'] ? date('Y-m-d\TH:i', strtotime($credential['x_carrier_credential_not_before'])) : '';
	$not_after = $credential['x_carrier_credential_not_after'] ? date('Y-m-d\TH:i', strtotime($credential['x_carrier_credential_not_after'])) : '';
	if ($can_secret) {
		echo $field_row($text['label-not_before'], "	<input class='formfld' type='datetime-local' name='not_before' value='".escape($not_before)."'>\n", $text['description-not_before']);
		echo $field_row($text['label-not_after'], "	<input class='formfld' type='datetime-local' name='not_after' value='".escape($not_after)."'>\n", $text['description-not_after']);
	}
	else {
		echo $field_row($text['label-not_before'], escape($not_before));
		echo $field_row($text['label-not_after'], escape($not_after));
	}
	echo $field_row($text['label-last_pushed'], escape($credential['x_carrier_credential_last_pushed']));
	echo "</table>\n";
	if ($can_secret) {
		echo "<br />\n";
		echo $hidden('save');
		echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save')]);
	}
	echo "</form>\n";

	//lifecycle actions
	if ($can_secret || $can_manage) {
		echo "<br />\n";
		if ($can_secret && $type !== 'api_token' && !empty($bindings)) {
			echo "<form method='post' class='inline'>".$hidden('push').button::create(['type'=>'submit','label'=>$text['button-push'],'icon'=>'upload'])."</form>\n";
		}
		if ($can_secret && $credential['x_carrier_credential_status'] === 'active') {
			echo "<form method='post' class='inline' onsubmit=\"return confirm('".escape($text['confirm-retire'])."');\">".$hidden('retire').button::create(['type'=>'submit','label'=>$text['button-retire'],'icon'=>'ban'])."</form>\n";
		}
		if ($can_manage && empty($bindings)) {
			echo "<form method='post' class='inline' onsubmit=\"return confirm('".escape($text['confirm-delete_credential'])."');\">".$hidden('delete').button::create(['type'=>'submit','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete')])."</form>\n";
		}
	}
	echo "</div>\n";
	echo "<br />\n";

//rotation
	if ($can_secret && $can_manage) {
		if ($credential['x_carrier_credential_status'] === 'pending') {
			echo "<div class='card'>\n";
			echo "<b>".$text['header-rotation']."</b><br><br>\n";
			echo $text['description-rotation_pending']."<br><br>\n";
			echo "<form method='post' class='inline'>".$hidden('rotate_promote').button::create(['type'=>'submit','label'=>$text['button-promote'],'icon'=>'check'])."</form>\n";
			echo "<form method='post' class='inline'>".$hidden('rotate_cancel').button::create(['type'=>'submit','label'=>$text['button-cancel_rotation'],'icon'=>'times'])."</form>\n";
			echo "</div>\n";
			echo "<br />\n";
		}
		elseif ($pending !== null) {
			echo "<div class='card'>\n";
			echo "<b>".$text['header-rotation']."</b><br><br>\n";
			echo $text['description-rotation_in_progress']." <a href='x_carrier_credential_edit.php?id=".urlencode($pending['x_carrier_credential_uuid'])."'>".$text['button-open_pending']."</a>\n";
			echo "</div>\n";
			echo "<br />\n";
		}
		elseif ($credential['x_carrier_credential_status'] === 'active') {
			echo "<div class='card'>\n";
			echo "<b>".$text['header-rotation']."</b><br><br>\n";
			echo $text['description-rotation_'.$type]."<br><br>\n";
			echo "<form method='post' autocomplete='off'>\n";
			echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
			echo $field_row($text['label-new_secret'], "	<input class='formfld' type='password' name='new_secret' maxlength='4096' value='' autocomplete='new-password' required='required'>\n", '', true);
			echo "</table>\n<br />\n";
			echo $hidden('rotate_start');
			echo button::create(['type'=>'submit','label'=>$text['button-rotate'],'icon'=>'sync-alt']);
			echo "</form>\n";
			echo "</div>\n";
			echo "<br />\n";
		}
	}

//bindings
	if ($type !== 'api_token') {
		echo "<div class='action_bar sub'>\n";
		echo "	<div class='heading'><b>".$text['header-bindings']."</b></div>\n";
		echo "	<div style='clear: both;'></div>\n";
		echo "</div>\n";
		echo "<div class='card'>\n";
		echo $text['description-bindings']."<br><br>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-binding_type']."</th>\n";
		echo "	<th>".$text['label-target']."</th>\n";
		echo "	<th>".$text['label-managed']."</th>\n";
		echo "	<th class='center'>".$text['label-order']."</th>\n";
		echo "	<th>".$text['label-state']."</th>\n";
		if ($can_manage) {
			echo "	<th class='right'>".$text['label-action']."</th>\n";
		}
		echo "</tr>\n";
		foreach ($bindings as $binding) {
			echo "<tr class='list-row'>\n";
			echo "	<td>".$text['option-'.$binding['x_carrier_binding_type']].($binding['x_carrier_binding_temporary'] === 'true' ? ' ('.$text['label-temporary'].')' : '')."</td>\n";
			echo "	<td>";
			if ($binding['x_carrier_binding_type'] === 'gateway') {
				$label = escape(($binding['gateway'] ?? '').' ['.($binding['profile'] ?? '').']'.($binding['register'] === 'true' ? ' '.$text['label-register'] : ''));
				if (permission_exists('gateway_edit') && is_uuid($binding['gateway_uuid'])) {
					echo "<a href='".PROJECT_PATH."/app/gateways/gateway_edit.php?id=".urlencode($binding['gateway_uuid'])."'>".$label."</a>";
				}
				else {
					echo $label;
				}
			}
			else {
				$label = escape(($binding['dialplan_name'] ?? '').' ('.($binding['dialplan_order'] ?? '').')');
				if (permission_exists('dialplan_edit') && is_uuid($binding['dialplan_uuid'])) {
					echo "<a href='".PROJECT_PATH."/app/dialplans/dialplan_edit.php?id=".urlencode($binding['dialplan_uuid'])."&app_uuid=".x_carrier_routes::outbound_app_uuid."'>".$label."</a>";
				}
				else {
					echo $label;
				}
			}
			echo "</td>\n";
			echo "	<td>".$text[$binding['x_carrier_binding_managed'] === 'true' ? 'label-managed_true' : 'label-managed_false']."</td>\n";
			echo "	<td class='center'>".($binding['x_carrier_binding_type'] === 'outbound_route' ? escape($binding['x_carrier_binding_order']) : '')."</td>\n";
			$state = 'ok';
			if ($binding['x_carrier_binding_type'] === 'outbound_route') {
				$state = $route_states[$binding['dialplan_uuid']] ?? 'error';
			}
			elseif (empty($binding['gateway'])) {
				$state = 'missing';
			}
			echo "	<td>".$text['state-'.$state];
			if ($state === 'drifted' && $can_secret) {
				echo " <form method='post' class='inline' onsubmit=\"return confirm('".escape($text['confirm-repush'])."');\">".$hidden('repush').button::create(['type'=>'submit','label'=>$text['button-repush'],'icon'=>'upload'])."</form>";
			}
			echo "</td>\n";
			if ($can_manage) {
				echo "	<td class='right'><form method='post' class='inline' onsubmit=\"return confirm('".escape($text['confirm-delete_binding'])."');\">";
				echo $hidden('delete_binding')."<input type='hidden' name='binding_uuid' value='".escape($binding['x_carrier_binding_uuid'])."'>";
				echo button::create(['type'=>'submit','label'=>$text['button-remove'],'icon'=>$settings->get('theme', 'button_icon_delete')]);
				echo "</form></td>\n";
			}
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "<br />".$text['description-route_plaintext']."\n";
		echo "</div>\n";
		echo "<br />\n";

		//add bindings
		if ($can_manage && $credential['x_carrier_credential_status'] !== 'retired') {
			$has_gateway = false;
			foreach ($bindings as $binding) {
				if ($binding['x_carrier_binding_type'] === 'gateway') {
					$has_gateway = true;
				}
			}

			echo "<div class='card'>\n";
			echo "<b>".$text['header-add_gateway']."</b><br><br>\n";
			echo $text['description-add_gateway']."<br><br>\n";
			echo "<form method='post'>\n";
			echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
			$select = "	<select class='formfld' name='profile'>\n";
			$default_profile = ($carrier['x_carrier_default_profile'] ?? '') ?: 'external';
			foreach ((array) $sip_profiles as $row) {
				$select .= "		<option value='".escape($row['sip_profile_name'])."'".($row['sip_profile_name'] === $default_profile ? " selected='selected'" : '').">".escape($row['sip_profile_name'])."</option>\n";
			}
			$select .= "	</select>\n";
			echo $field_row($text['label-profile'], $select, '', true);
			if ($type === 'sip_digest') {
				$select = "	<select class='formfld' name='register'>\n";
				$select .= "		<option value='false'>".$text['label-false']."</option>\n";
				$select .= "		<option value='true'".(in_array($account['x_carrier_account_purpose'], ['fax', 'both'], true) ? " selected='selected'" : '').">".$text['label-true']."</option>\n";
				$select .= "	</select>\n";
				echo $field_row($text['label-register'], $select, $text['description-register']);
			}
			echo "</table>\n<br />\n";
			echo $hidden('add_gateway');
			echo button::create(['type'=>'submit','label'=>$text['button-add_gateway'],'icon'=>$settings->get('theme', 'button_icon_add')]);
			echo "</form>\n";
			echo "</div>\n";
			echo "<br />\n";

			if ($has_gateway) {
				echo "<div class='card'>\n";
				echo "<b>".$text['header-add_route']."</b><br><br>\n";
				echo $text['description-add_route']."<br><br>\n";
				echo "<form method='post'>\n";
				echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
				$select = "	<select class='formfld' name='dialplan_uuid'>\n";
				$select .= "		<option value=''>".$text['option-new_route']."</option>\n";
				foreach ($managed_routes as $row) {
					$select .= "		<option value='".escape($row['dialplan_uuid'])."'>".escape($row['dialplan_name'].' ('.$row['dialplan_order'].')')."</option>\n";
				}
				$select .= "	</select>\n";
				echo $field_row($text['label-route'], $select, $text['description-route']);
				echo $field_row($text['label-dialplan_name'], "	<input class='formfld' type='text' name='dialplan_name' maxlength='128' value=''>\n", $text['description-dialplan_name']);
				$default_expression = ($account['x_carrier_account_purpose'] === 'fax') ? '^\*33(\d+)$' : '^(\d{11})$';
				echo $field_row($text['label-dialplan_expression'], "	<input class='formfld' type='text' name='dialplan_expression' maxlength='255' value=\"".escape($default_expression)."\">\n", $text['description-dialplan_expression']);
				echo $field_row($text['label-order'], "	<input class='formfld' type='number' name='dialplan_order' min='0' max='999' value='300'>\n", $text['description-order']);
				echo "</table>\n<br />\n";
				echo $hidden('add_route');
				echo button::create(['type'=>'submit','label'=>$text['button-add_route'],'icon'=>$settings->get('theme', 'button_icon_add')]);
				echo "</form>\n";
				echo "</div>\n";
				echo "<br />\n";
			}
		}
	}

//reveal: POST with the CSRF token, value returned once over XHR and cleared after 30 seconds
	if ($can_reveal && $reveal_token !== null) {
		echo "<script>\n";
		echo "var x_carrier_reveal_token = {name: ".json_encode($reveal_token['name']).", hash: ".json_encode($reveal_token['hash'])."};\n";
		echo "function x_carrier_reveal() {\n";
		echo "	var data = new FormData();\n";
		echo "	data.append('id', ".json_encode($credential_uuid).");\n";
		echo "	data.append(x_carrier_reveal_token.name, x_carrier_reveal_token.hash);\n";
		echo "	fetch('x_carrier_credential_reveal.php', {method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store'})\n";
		echo "		.then(function (response) { return response.json(); })\n";
		echo "		.then(function (result) {\n";
		echo "			var target = document.getElementById('x_carrier_revealed');\n";
		echo "			if (result.token) { x_carrier_reveal_token = result.token; }\n";
		echo "			target.textContent = result.ok ? result.value : ".json_encode($text['error-reveal']).";\n";
		echo "			setTimeout(function () { target.textContent = ''; }, 30000);\n";
		echo "		})\n";
		echo "		.catch(function () { document.getElementById('x_carrier_revealed').textContent = ".json_encode($text['error-reveal'])."; });\n";
		echo "}\n";
		echo "</script>\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
