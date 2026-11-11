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
	Add or edit a local network (docs/design/carrier_acl.md section 12). Validated with the local rules: private
	ranges allowed; default route, too-broad prefixes, loopback, link-local, multicast and special ranges refused.
	Saved without approval, audited as a run, exported by the worker run this page requests.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions
	if (!(permission_exists('x_carrier_acl_local_network_add') || permission_exists('x_carrier_acl_local_network_edit'))) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//add the settings object
	$settings = new settings(["domain_uuid" => $_SESSION['domain_uuid'], "user_uuid" => $_SESSION['user_uuid']]);

	$local = new x_carrier_acl_local($database);
	if (!$local->table_exists()) {
		message::add($text['message-local_schema_missing'],'negative');
		header('Location: x_carrier_acl_local_networks.php');
		exit;
	}

//action add or update
	$uuid = $_REQUEST['id'] ?? '';
	if (is_uuid($uuid)) {
		$action = 'update';
		if (!permission_exists('x_carrier_acl_local_network_edit')) {
			echo "access denied";
			exit;
		}
	}
	else {
		$action = 'add';
		$uuid = '';
		if (!permission_exists('x_carrier_acl_local_network_add')) {
			echo "access denied";
			exit;
		}
	}

//defaults
	$network = ['local_network_cidr' => '', 'local_network_enabled' => 'true', 'local_network_description' => ''];
	$original = null;
	if ($action == 'update') {
		$original = $local->get($uuid);
		if ($original === null) {
			message::add($text['message-local_network_not_found'],'negative');
			header('Location: x_carrier_acl_local_networks.php');
			exit;
		}
		$network['local_network_cidr'] = $original['local_network_cidr'];
		$network['local_network_enabled'] = x_carrier_acl_diff::bool($original['local_network_enabled']) ? 'true' : 'false';
		$network['local_network_description'] = (string)$original['local_network_description'];
	}

//process the http post
	if (!empty($_POST)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: x_carrier_acl_local_networks.php');
				exit;
			}

		//get the posted values
			$network['local_network_cidr'] = trim((string)($_POST['local_network_cidr'] ?? ''));
			$network['local_network_enabled'] = (($_POST['local_network_enabled'] ?? '') === 'true') ? 'true' : 'false';
			$network['local_network_description'] = x_carrier_acl_validate::clean_label(trim((string)($_POST['local_network_description'] ?? '')));

		//validate
			$msg = [];
			$n = x_carrier_acl_validate::local_network($network['local_network_cidr']);
			if (is_string($n)) {
				$msg[] = $text['error-local_network_cidr'].' ('.$n.')';
			}
			else {
				$network['local_network_cidr'] = $n['cidr'];
				if ($local->duplicate($n['cidr'], $uuid) !== null) {
					$msg[] = $text['error-local_network_duplicate'];
				}
			}
			if ($action == 'add' && $local->count() >= x_carrier_acl_validate::local_max) {
				$msg[] = $text['error-local_network_max'].' '.x_carrier_acl_validate::local_max;
			}

		//save
			if (empty($msg)) {
				if ($action == 'add') {
					$uuid = uuid();
				}
				$array['x_carrier_acl_local_networks'][0]['x_carrier_acl_local_network_uuid'] = $uuid;
				$array['x_carrier_acl_local_networks'][0]['local_network_cidr'] = $n['cidr'];
				$array['x_carrier_acl_local_networks'][0]['local_network_family'] = $n['family'];
				$array['x_carrier_acl_local_networks'][0]['local_network_enabled'] = $network['local_network_enabled'];
				$array['x_carrier_acl_local_networks'][0]['local_network_description'] = $network['local_network_description'];
				$database->app_name = x_carrier_acl::app_name;
				$database->app_uuid = x_carrier_acl::app_uuid;
				$saved = $database->save($array);
				unset($array);
				if ($saved) {
					//audit what the firewall gains and loses (only enabled rows reach it)
					$was = ($original !== null && x_carrier_acl_diff::bool($original['local_network_enabled']))
						? ['cidr' => $original['local_network_cidr'], 'label' => $original['local_network_description']] : null;
					$now = ($network['local_network_enabled'] === 'true')
						? ['cidr' => $n['cidr'], 'label' => $network['local_network_description']] : null;
					$added = ($now !== null && ($was === null || $was['cidr'] !== $now['cidr'])) ? [$now] : [];
					$removed = ($was !== null && ($now === null || $was['cidr'] !== $now['cidr'])) ? [$was] : [];
					$local->audit($action == 'add' ? 'add' : 'edit', $added, $removed);
					if (!empty($added) || !empty($removed)) {
						x_carrier_acl::request_run();
					}
					message::add($action == 'add' ? $text['message-add'] : $text['message-update']);
					header('Location: x_carrier_acl_local_networks.php');
					exit;
				}
				$msg[] = $text['error-local_network_save'];
			}
			foreach ($msg as $m) {
				message::add(escape($m),'negative');
			}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_carrier_acl_local_network'];
	require_once "resources/header.php";

	echo "<form method='post' name='frm' id='frm'>\n";
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_acl_local_network']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'x_carrier_acl_local_networks.php']);
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-local_networks']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-local_network_cidr']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='local_network_cidr' maxlength='64' required='required' value=\"".escape($network['local_network_cidr'])."\">\n";
	echo "<br />".$text['description-local_network_cidr']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-local_network_enabled']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='local_network_enabled'>\n";
	echo "		<option value='true' ".($network['local_network_enabled'] === 'true' ? "selected='selected'" : "").">".$text['label-true']."</option>\n";
	echo "		<option value='false' ".($network['local_network_enabled'] !== 'true' ? "selected='selected'" : "").">".$text['label-false']."</option>\n";
	echo "	</select>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-local_network_description']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='local_network_description' maxlength='128' value=\"".escape($network['local_network_description'])."\">\n";
	echo "<br />".$text['description-local_network_description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	if ($action == 'update') {
		echo "<input type='hidden' name='id' value='".escape($uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
