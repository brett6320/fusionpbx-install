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
	Local networks (docs/design/carrier_acl.md section 12): LAN/VPN/office ranges the firewall always allows on the
	SIP and RTP ports, in allow and restrict mode. Never added to the FreeSWITCH ACL. Changes apply without approval
	and are audited as runs; the worker exports them on its next run, which this page requests.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions (host-global app: superadmin only, see app_config.php)
	if (!permission_exists('x_carrier_acl_local_network_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//add the settings object
	$settings = new settings(["domain_uuid" => $_SESSION['domain_uuid'], "user_uuid" => $_SESSION['user_uuid']]);

	$app = new x_carrier_acl($database);
	$local = new x_carrier_acl_local($database);

//process the http post: toggle or delete one row
	if (!empty($_POST)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: x_carrier_acl_local_networks.php');
				exit;
			}

		$action = $_POST['action'] ?? '';
		$row = $local->get((string)($_POST['id'] ?? ''));
		if ($row === null) {
			message::add($text['message-local_network_not_found'],'negative');
			header('Location: x_carrier_acl_local_networks.php');
			exit;
		}
		$entry = [['cidr' => $row['local_network_cidr'], 'label' => $row['local_network_description']]];
		$enabled = x_carrier_acl_diff::bool($row['local_network_enabled']);

		//toggle
			if ($action == 'toggle' && permission_exists('x_carrier_acl_local_network_edit')) {
				$array['x_carrier_acl_local_networks'][0]['x_carrier_acl_local_network_uuid'] = $row['x_carrier_acl_local_network_uuid'];
				$array['x_carrier_acl_local_networks'][0]['local_network_enabled'] = $enabled ? 'false' : 'true';
				$database->app_name = x_carrier_acl::app_name;
				$database->app_uuid = x_carrier_acl::app_uuid;
				if ($database->save($array)) {
					$local->audit($enabled ? 'disable' : 'enable', $enabled ? [] : $entry, $enabled ? $entry : []);
					x_carrier_acl::request_run();
					message::add($text['message-toggle']);
				}
				else {
					message::add($text['error-local_network_save'],'negative');
				}
				unset($array);
			}

		//delete
			elseif ($action == 'delete' && permission_exists('x_carrier_acl_local_network_delete')) {
				$array['x_carrier_acl_local_networks'][0]['x_carrier_acl_local_network_uuid'] = $row['x_carrier_acl_local_network_uuid'];
				$database->app_name = x_carrier_acl::app_name;
				$database->app_uuid = x_carrier_acl::app_uuid;
				if ($database->delete($array)) {
					$local->audit('delete', [], $enabled ? $entry : []);
					x_carrier_acl::request_run();
					message::add($text['message-delete']);
				}
				else {
					message::add($text['error-local_network_save'],'negative');
				}
				unset($array);
			}

			else {
				message::add($text['error-permission'],'negative');
			}

		header('Location: x_carrier_acl_local_networks.php');
		exit;
	}

//get the data
	$rows = $local->all();
	$status = x_carrier_acl::firewall_status();

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_carrier_acl_local_networks'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_acl_local_networks']."</b><div class='count'>".number_format(count($rows))."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'x_carrier_acl_feeds.php']);
	if (permission_exists('x_carrier_acl_local_network_add') && $local->table_exists()) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add','link'=>'x_carrier_acl_local_network_edit.php']);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-local_networks']."\n";
	echo "<br /><br />\n";
	if ($app->firewall_mode() === 'restrict') {
		echo "<span style='color: #b00;'><b>".$text['label-restrict_local']."</b></span><br /><br />\n";
	}
	if (is_array($status) && isset($status['local_v4'])) {
		echo $text['label-local_applied']." v4 ".escape($status['local_v4'])." / v6 ".escape($status['local_v6'] ?? 0)." (".$text['label-generation_applied']." ".escape($status['generation'] ?? '').")<br /><br />\n";
	}
	elseif (is_array($status)) {
		echo "<span style='color: #b60;'>".$text['label-local_not_applied']."</span><br /><br />\n";
	}

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-local_network_cidr']."</th>\n";
	echo "	<th>".$text['label-local_network_enabled']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-local_network_description']."</th>\n";
	if (permission_exists('x_carrier_acl_local_network_edit') || permission_exists('x_carrier_acl_local_network_delete')) {
		echo "	<td class='action-button'>&nbsp;</td>\n";
	}
	echo "</tr>\n";
	foreach ($rows as $row) {
		$uuid = $row['x_carrier_acl_local_network_uuid'];
		$url = 'x_carrier_acl_local_network_edit.php?id='.urlencode($uuid);
		$enabled = x_carrier_acl_diff::bool($row['local_network_enabled']);
		$can_edit = permission_exists('x_carrier_acl_local_network_edit');
		echo "<tr class='list-row'".($can_edit ? " href='".$url."'" : "").">\n";
		echo "	<td>".($can_edit ? "<a href='".$url."'>".escape($row['local_network_cidr'])."</a>" : escape($row['local_network_cidr']))."</td>\n";
		echo "	<td>";
		if ($can_edit) {
			echo "<form method='post' class='inline'>";
			echo "<input type='hidden' name='action' value='toggle'>";
			echo "<input type='hidden' name='id' value='".escape($uuid)."'>";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
			echo button::create(['type'=>'submit','class'=>'link','label'=>$enabled ? $text['label-true'] : $text['label-false'],'title'=>$text['button-toggle']]);
			echo "</form>";
		}
		else {
			echo $enabled ? $text['label-true'] : $text['label-false'];
		}
		echo "</td>\n";
		echo "	<td class='description overflow hide-sm-dn'>".escape($row['local_network_description'])."</td>\n";
		if ($can_edit || permission_exists('x_carrier_acl_local_network_delete')) {
			echo "	<td class='action-button'>";
			if ($can_edit) {
				echo button::create(['type'=>'button','title'=>$text['button-edit'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>$url]);
			}
			if (permission_exists('x_carrier_acl_local_network_delete')) {
				echo "<form method='post' class='inline'>";
				echo "<input type='hidden' name='action' value='delete'>";
				echo "<input type='hidden' name='id' value='".escape($uuid)."'>";
				echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
				echo button::create(['type'=>'submit','title'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'onclick'=>"return confirm('".escape($text['confirm-delete_local_network'])."');"]);
				echo "</form>";
			}
			echo "</td>\n";
		}
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
