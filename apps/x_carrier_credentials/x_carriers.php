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
	if (!permission_exists('x_carrier_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get the catalogue (global, superadmin)
	$store = new x_carriers;
	$carriers = $store->carriers();

//show the header
	$document['title'] = $text['title-x_carriers'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carriers']."</b><div class='count'>".number_format(count($carriers))."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'x_carrier_accounts.php']);
	if (permission_exists('x_carrier_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add','link'=>'x_carrier_edit.php']);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-x_carriers']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-carrier_name']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-default_proxy']."</th>\n";
	echo "	<th>".$text['label-credential_types']."</th>\n";
	echo "	<th class='center'>".$text['label-enabled']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-description']."</th>\n";
	echo "</tr>\n";
	foreach ($carriers as $row) {
		$types = [];
		if ($row['x_carrier_supports_registration'] === 'true') { $types[] = $text['option-sip_digest']; }
		if ($row['x_carrier_supports_ip_auth'] === 'true') { $types[] = $text['option-ip_auth']; }
		if ($row['x_carrier_supports_api'] === 'true') { $types[] = $text['option-api_token']; }
		$list_row_url = permission_exists('x_carrier_edit') ? 'x_carrier_edit.php?id='.urlencode($row['x_carrier_uuid']) : '';
		echo "<tr class='list-row'".($list_row_url ? " href='".$list_row_url."'" : '').">\n";
		echo "	<td>";
		if ($list_row_url) {
			echo "<a href='".$list_row_url."'>".escape($row['x_carrier_name'])."</a>";
		}
		else {
			echo escape($row['x_carrier_name']);
		}
		echo "</td>\n";
		echo "	<td class='hide-sm-dn'>".escape($row['x_carrier_default_proxy'])."</td>\n";
		echo "	<td>".escape(implode(', ', $types))."</td>\n";
		echo "	<td class='center'>".$text['label-'.($row['x_carrier_enabled'] === 'true' ? 'true' : 'false')]."</td>\n";
		echo "	<td class='description overflow hide-sm-dn'>".escape($row['x_carrier_description'])."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
