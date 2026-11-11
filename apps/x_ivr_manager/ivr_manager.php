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
	if (!permission_exists('x_ivr_manager_view')) {
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
	$search = $_GET['search'] ?? '';

//get the ivr menus of this domain
	$sql = "select i.ivr_menu_uuid, i.ivr_menu_name, i.ivr_menu_extension, i.ivr_menu_greet_long, ";
	$sql .= "cast(i.ivr_menu_enabled as text) as ivr_menu_enabled, i.ivr_menu_description, ";
	$sql .= "(select count(*) from v_ivr_menu_options as o where o.ivr_menu_uuid = i.ivr_menu_uuid and o.domain_uuid = i.domain_uuid) as option_count, ";
	$sql .= "(select d.dialplan_number from v_x_ivr_manager_schedules as s, v_dialplans as d ";
	$sql .= "	where s.ivr_menu_uuid = i.ivr_menu_uuid and s.domain_uuid = i.domain_uuid ";
	$sql .= "	and d.dialplan_uuid = s.dialplan_uuid and d.domain_uuid = i.domain_uuid ";
	$sql .= "	and d.app_uuid = '4b821450-926b-175a-af93-a03c441818b1' limit 1) as schedule_number ";
	$sql .= "from v_ivr_menus as i ";
	$sql .= "where i.domain_uuid = :domain_uuid ";
	if (!empty($search)) {
		$sql .= "and (";
		$sql .= "	lower(i.ivr_menu_name) like :search ";
		$sql .= "	or lower(i.ivr_menu_extension) like :search ";
		$sql .= "	or lower(i.ivr_menu_description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.lower_case($search).'%';
	}
	$sql .= "order by i.ivr_menu_name asc ";
	$parameters['domain_uuid'] = $domain_uuid;
	$ivr_menus = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//include the header
	$document['title'] = $text['title-ivr_manager'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-ivr_manager']."</b><div class='count'>".number_format(!empty($ivr_menus) ? count($ivr_menus) : 0)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo "		<form id='form_search' class='inline' method='get'>\n";
	echo "		<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\">";
	echo button::create(['label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search'),'type'=>'submit','id'=>'btn_search']);
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-ivr_manager']."\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-name']."</th>\n";
	echo "	<th>".$text['label-extension']."</th>\n";
	echo "	<th class='center'>".$text['label-options']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-greet_long']."</th>\n";
	echo "	<th>".$text['label-schedule']."</th>\n";
	echo "	<th class='center'>".$text['label-enabled']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-description']."</th>\n";
	echo "</tr>\n";

	if (!empty($ivr_menus)) {
		foreach ($ivr_menus as $row) {
			$list_row_url = "ivr_manager_edit.php?id=".urlencode($row['ivr_menu_uuid']);
			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			echo "	<td><a href='".$list_row_url."' title=\"".$text['button-edit']."\">".escape($row['ivr_menu_name'])."</a></td>\n";
			echo "	<td>".escape($row['ivr_menu_extension'])."&nbsp;</td>\n";
			echo "	<td class='center'>".escape($row['option_count'])."</td>\n";
			echo "	<td class='hide-sm-dn'>".escape(!empty($row['ivr_menu_greet_long']) ? basename($row['ivr_menu_greet_long']) : $text['label-not_set'])."</td>\n";
			echo "	<td>".escape(!empty($row['schedule_number']) ? $row['schedule_number'] : $text['label-schedule_none'])."</td>\n";
			echo "	<td class='center'>".($text['label-'.$row['ivr_menu_enabled']] ?? escape($row['ivr_menu_enabled']))."</td>\n";
			echo "	<td class='description overflow hide-sm-dn'>".escape($row['ivr_menu_description'])."&nbsp;</td>\n";
			echo "</tr>\n";
		}
	}
	unset($ivr_menus);

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
