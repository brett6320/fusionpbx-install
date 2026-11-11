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
	if (!permission_exists('x_endpoint_manager_log_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set variables from GET parameters
	$page = (is_numeric($_GET['page'] ?? '') && $_GET['page'] > 0) ? (int)$_GET['page'] : 0;
	$order_by = in_array($_GET['order_by'] ?? '', ['request_date', 'process_date', 'device_address', 'sip_user', 'reboot_trigger', 'requested_by_username', 'reboot_status'], true) ? $_GET['order_by'] : '';
	$order = ($_GET['order'] ?? '') == 'asc' ? 'asc' : 'desc';
	$search = is_string($_GET['search'] ?? null) ? $_GET['search'] : '';
	$status = in_array($_GET['status'] ?? '', x_endpoint_manager::statuses, true) ? $_GET['status'] : '';

//build the query string
	$param = [];
	if (!empty($page)) {
		$param['page'] = $page;
	}
	if (!empty($order_by)) {
		$param['order_by'] = $order_by;
		$param['order'] = $order;
	}
	if (!empty($search)) {
		$param['search'] = $search;
	}
	if (!empty($status)) {
		$param['status'] = $status;
	}
	$query_string = http_build_query($param);

//build the where clause
	$sql_where = "where domain_uuid = :domain_uuid ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	if (!empty($status)) {
		$sql_where .= "and reboot_status = :reboot_status ";
		$parameters['reboot_status'] = $status;
	}
	if (!empty($search)) {
		$sql_where .= "and ( ";
		$sql_where .= "	lower(device_address) like :search ";
		$sql_where .= "	or lower(device_label) like :search ";
		$sql_where .= "	or lower(sip_user) like :search ";
		$sql_where .= "	or lower(schedule_name) like :search ";
		$sql_where .= "	or lower(requested_by_username) like :search ";
		$sql_where .= "	or lower(reboot_detail) like :search ";
		$sql_where .= ") ";
		$parameters['search'] = '%'.lower_case($search).'%';
	}

//get the count
	$sql = "select count(*) from v_x_endpoint_reboot_log ".$sql_where;
	$num_rows = $database->select($sql, $parameters, 'column');

//prepare to page the results
	$rows_per_page = $settings->get('domain', 'paging', 50);
	list($paging_controls, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page, true);
	$offset = $rows_per_page * $page;

//get the list
	$endpoint_manager = new x_endpoint_manager(['database' => $database]);
	$time_zone = $endpoint_manager->domain_time_zone($_SESSION['domain_uuid'])->getName();
	$sql = "select x_endpoint_reboot_log_uuid, schedule_name, reboot_trigger, requested_by_username, device_address, device_label, ";
	$sql .= "device_vendor, device_model, sip_user, sip_profile, reboot_status, reboot_detail, ";
	$sql .= "to_char(timezone(:time_zone, request_date), 'YYYY-MM-DD HH24:MI:SS') as request_date_formatted, ";
	$sql .= "to_char(timezone(:time_zone, process_date), 'YYYY-MM-DD HH24:MI:SS') as process_date_formatted ";
	$sql .= "from v_x_endpoint_reboot_log ";
	$sql .= $sql_where;
	$sql .= order_by($order_by, $order, 'request_date', 'desc');
	$sql .= limit_offset($rows_per_page, $offset);
	$parameters['time_zone'] = $time_zone;
	$result = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//include the header
	$document['title'] = $text['title-x_endpoint_reboot_logs'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_endpoint_reboot_logs']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'x_endpoint_manager.php']);
	echo button::create(['type'=>'button','label'=>$text['button-refresh'],'icon'=>$settings->get('theme', 'button_icon_refresh'),'link'=>'x_endpoint_reboot_logs.php'.($query_string ? '?'.$query_string : '')]);
	echo 		"<form id='form_search' class='inline' method='get'>\n";
	foreach ($param as $key => $value) {
		if (in_array($key, ['order_by', 'order'])) {
			echo "		<input type='hidden' name='".escape($key)."' value='".escape($value)."'>\n";
		}
	}
	echo "		<select class='formfld' name='status' style='margin-left: 15px;' onchange=\"document.getElementById('form_search').submit();\">\n";
	echo "			<option value=''>".$text['label-all']."</option>\n";
	foreach (x_endpoint_manager::statuses as $value) {
		echo "			<option value='".escape($value)."' ".($status == $value ? "selected='selected'" : '').">".escape($text['option-status_'.$value] ?? $value)."</option>\n";
	}
	echo "		</select>\n";
	echo "		<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\" onkeydown=''>";
	echo button::create(['label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search'),'type'=>'submit','id'=>'btn_search']);
	if (!empty($paging_controls_mini)) {
		echo "	<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-x_endpoint_reboot_logs']." (".escape($time_zone).")\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo th_order_by('request_date', $text['label-request_date'], $order_by, $order, null, "class='no-wrap'", $param);
	echo th_order_by('process_date', $text['label-process_date'], $order_by, $order, null, "class='no-wrap hide-sm-dn'", $param);
	echo th_order_by('device_address', $text['label-device'], $order_by, $order, null, null, $param);
	echo th_order_by('sip_user', $text['label-sip_user'], $order_by, $order, null, "class='hide-sm-dn'", $param);
	echo th_order_by('reboot_trigger', $text['label-trigger'], $order_by, $order, null, null, $param);
	echo th_order_by('requested_by_username', $text['label-requested_by'], $order_by, $order, null, "class='hide-sm-dn'", $param);
	echo th_order_by('reboot_status', $text['label-result'], $order_by, $order, null, null, $param);
	echo "	<th class='hide-md-dn pct-30'>".$text['label-detail']."</th>\n";
	echo "</tr>\n";

	if (!empty($result) && is_array($result)) {
		foreach ($result as $row) {
			//describe the device
			$device = !empty($row['device_address']) ? format_device_address($row['device_address']) : '';
			if (!empty($row['device_label'])) {
				$device .= " - ".$row['device_label'];
			}

			//describe the trigger
			$trigger = $row['reboot_trigger'] == 'schedule' ? $text['option-trigger_schedule'].(!empty($row['schedule_name']) ? ": ".$row['schedule_name'] : '') : $text['option-trigger_manual'];

			echo "<tr class='list-row'>\n";
			echo "	<td class='no-wrap'>".escape($row['request_date_formatted'])."</td>\n";
			echo "	<td class='no-wrap hide-sm-dn'>".escape($row['process_date_formatted'])."</td>\n";
			echo "	<td>".escape($device)."</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($row['sip_user'])."</td>\n";
			echo "	<td>".escape($trigger)."</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($row['requested_by_username'])."</td>\n";
			echo "	<td>".escape($text['option-status_'.$row['reboot_status']] ?? $row['reboot_status'])."</td>\n";
			echo "	<td class='description overflow hide-md-dn' title=\"".escape($row['reboot_detail'])."\">".escape($row['reboot_detail'])."</td>\n";
			echo "</tr>\n";
		}
		unset($result);
	}

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";
	echo "<div align='center'>".$paging_controls."</div>\n";

//include the footer
	require_once "resources/footer.php";

?>
