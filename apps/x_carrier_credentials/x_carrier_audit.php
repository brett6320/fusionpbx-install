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
	require_once "resources/paging.php";

//check permissions
	if (!permission_exists('x_carrier_audit_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get the http values
	$account_uuid = (!empty($_GET['account']) && is_uuid($_GET['account'])) ? $_GET['account'] : '';
	$show = (($_GET['show'] ?? '') === 'all' && permission_exists('x_carrier_all')) ? 'all' : '';
	$page = is_numeric($_GET['page'] ?? '') ? (int) $_GET['page'] : 0;

//build the query string
	$url_params = [];
	if ($account_uuid !== '') {
		$url_params['account'] = $account_uuid;
	}
	if ($show === 'all') {
		$url_params['show'] = 'all';
	}
	$query_string = http_build_query($url_params);

//get the rows, scoped to the session domain unless show all
	$store = new x_carriers;
	$num_rows = $store->audit_count($account_uuid, $show === 'all');
	$rows_per_page = $settings->get('domain', 'paging', 50);
	list($paging_controls, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page, true);
	$offset = $rows_per_page * $page;
	$rows = $store->audit_rows($account_uuid, $show === 'all', (int) $rows_per_page, (int) $offset);

//show the header
	$document['title'] = $text['title-x_carrier_audit'];
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_audit']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'link'=>$account_uuid !== '' ? 'x_carrier_account_edit.php?id='.urlencode($account_uuid) : 'x_carrier_accounts.php']);
	if ($show !== 'all' && permission_exists('x_carrier_all')) {
		echo button::create(['type'=>'button','label'=>$text['button-show_all'],'icon'=>$settings->get('theme', 'button_icon_all'),'link'=>'?'.http_build_query(array_merge($url_params, ['show' => 'all']))]);
	}
	if ($paging_controls_mini != '') {
		echo "	<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-x_carrier_audit']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-date']."</th>\n";
	if ($show === 'all') {
		echo "	<th>".$text['label-domain']."</th>\n";
	}
	echo "	<th>".$text['label-account_name']."</th>\n";
	echo "	<th>".$text['label-audit_action']."</th>\n";
	echo "	<th>".$text['label-username']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-remote_address']."</th>\n";
	echo "	<th>".$text['label-detail']."</th>\n";
	echo "</tr>\n";
	foreach ($rows as $row) {
		echo "<tr class='list-row'>\n";
		echo "	<td nowrap='nowrap'>".escape($row['x_carrier_audit_date'])."</td>\n";
		if ($show === 'all') {
			echo "	<td>".escape($row['domain_name'])."</td>\n";
		}
		echo "	<td>";
		if (is_uuid($row['x_carrier_credential_uuid'] ?? '')) {
			echo "<a href='x_carrier_credential_edit.php?id=".urlencode($row['x_carrier_credential_uuid'])."'>".escape($row['x_carrier_account_name'] ?? '')."</a>";
		}
		else {
			echo escape($row['x_carrier_account_name'] ?? '');
		}
		echo "</td>\n";
		echo "	<td>".escape($text['audit-'.$row['x_carrier_audit_action']] ?? $row['x_carrier_audit_action'])."</td>\n";
		echo "	<td>".escape($row['x_carrier_audit_username'])."</td>\n";
		echo "	<td class='hide-sm-dn'>".escape($row['x_carrier_audit_remote_address'])."</td>\n";
		echo "	<td class='description overflow' style='font-family: monospace; font-size: 85%;'>".escape($row['x_carrier_audit_detail'])."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "<br />\n";
	echo "<div align='center'>".$paging_controls."</div>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
