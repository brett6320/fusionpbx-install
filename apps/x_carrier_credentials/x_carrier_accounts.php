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

//get the http values
	$search = trim((string) ($_GET['search'] ?? ''));
	$show = (($_GET['show'] ?? '') === 'all' && permission_exists('x_carrier_all')) ? 'all' : '';

//get the accounts, scoped to the session domain unless show all
	$store = new x_carriers;
	$accounts = $store->accounts($search, $show === 'all');

//show the header
	$document['title'] = $text['title-x_carrier_accounts'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_accounts']."</b><div class='count'>".number_format(count($accounts))."</div></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('x_carrier_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-carriers'],'icon'=>'building','link'=>'x_carriers.php']);
	}
	if (permission_exists('x_carrier_audit_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-audit'],'icon'=>'list','link'=>'x_carrier_audit.php']);
	}
	if (permission_exists('x_carrier_account_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add','style'=>'margin-left: 15px;','link'=>'x_carrier_account_edit.php']);
	}
	echo "		<form id='form_search' class='inline' method='get'>\n";
	if ($show === 'all') {
		echo "		<input type='hidden' name='show' value='all'>\n";
	}
	elseif (permission_exists('x_carrier_all')) {
		echo button::create(['type'=>'button','label'=>$text['button-show_all'],'icon'=>$settings->get('theme', 'button_icon_all'),'link'=>'?show=all']);
	}
	echo "		<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\">";
	echo button::create(['label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search'),'type'=>'submit','id'=>'btn_search']);
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

//plaintext banner, on every list and edit page
	echo "<div class='card' style='font-weight: bold;'>".$text['message-plaintext_banner']."</div>\n";
	echo "<br />\n";

	echo $text['description-x_carrier_accounts']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if ($show === 'all') {
		echo "	<th>".$text['label-domain']."</th>\n";
	}
	echo "	<th>".$text['label-account_name']."</th>\n";
	echo "	<th>".$text['label-carrier']."</th>\n";
	echo "	<th>".$text['label-purpose']."</th>\n";
	echo "	<th class='center'>".$text['label-credentials']."</th>\n";
	echo "	<th class='center'>".$text['label-enabled']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-description']."</th>\n";
	echo "</tr>\n";
	foreach ($accounts as $row) {
		$list_row_url = 'x_carrier_account_edit.php?id='.urlencode($row['x_carrier_account_uuid']);
		echo "<tr class='list-row' href='".$list_row_url."'>\n";
		if ($show === 'all') {
			echo "	<td>".escape($row['domain_name'])."</td>\n";
		}
		echo "	<td><a href='".$list_row_url."'>".escape($row['x_carrier_account_name'])."</a></td>\n";
		echo "	<td>".escape($row['x_carrier_name'])."</td>\n";
		echo "	<td>".escape($text['option-'.$row['x_carrier_account_purpose']] ?? $row['x_carrier_account_purpose'])."</td>\n";
		echo "	<td class='center'>".escape($row['credential_count'])."</td>\n";
		echo "	<td class='center'>".$text['label-'.($row['x_carrier_account_enabled'] === 'true' ? 'true' : 'false')]."</td>\n";
		echo "	<td class='description overflow hide-sm-dn'>".escape($row['x_carrier_account_description'])."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//about encryption
	echo "<div class='card'>\n";
	echo "<b>".$text['header-about_encryption']."</b><br><br>\n";
	echo $text['description-about_encryption']."<br><br>\n";
	echo $text['description-transaction_log']."<br><br>\n";
	echo $text['description-gateway_hover']."<br><br>\n";
	echo $text['description-restore']."\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
