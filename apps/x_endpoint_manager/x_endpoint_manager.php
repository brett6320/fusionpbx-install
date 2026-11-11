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
	if (!permission_exists('x_endpoint_manager_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set variables from GET parameters
	$page = (is_numeric($_GET['page'] ?? '') && $_GET['page'] > 0) ? (int)$_GET['page'] : 0;
	$order_by = in_array($_GET['order_by'] ?? '', ['device_address', 'device_label', 'device_vendor', 'device_model', 'device_enabled'], true) ? $_GET['order_by'] : '';
	$order = ($_GET['order'] ?? '') == 'desc' ? 'desc' : 'asc';
	$search = is_string($_GET['search'] ?? null) ? $_GET['search'] : '';

//build the query string
	$param = [];
	if (!empty($page)) {
		$param['page'] = $page;
	}
	if (!empty($order_by)) {
		$param['order_by'] = $order_by;
	}
	if (!empty($_GET['order'])) {
		$param['order'] = $order;
	}
	if (!empty($search)) {
		$param['search'] = $search;
	}
	$query_string = http_build_query($param);

//create the endpoint manager object
	$endpoint_manager = new x_endpoint_manager(['database' => $database]);

//process the reboot request
	if (!empty($_POST['action']) && $_POST['action'] == 'reboot') {

		//check permissions
			if (!permission_exists('x_endpoint_manager_reboot')) {
				echo "access denied";
				exit;
			}

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: x_endpoint_manager.php'.($query_string ? '?'.$query_string : ''));
				exit;
			}

		//get the checked devices
			$device_uuids = [];
			if (!empty($_POST['devices']) && is_array($_POST['devices'])) {
				foreach ($_POST['devices'] as $row) {
					if (is_array($row) && ($row['checked'] ?? '') == 'true' && is_uuid($row['uuid'] ?? '')) {
						$device_uuids[] = $row['uuid'];
					}
				}
			}

		//queue the reboots, then send what the busy check and rate limits allow
			if (!empty($device_uuids)) {
				$requester = [
					'user_uuid' => $_SESSION['user_uuid'] ?? null,
					'username' => $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? null),
				];
				$log_uuids = $endpoint_manager->enqueue($_SESSION['domain_uuid'], $device_uuids, 'manual', $requester);
				$summary = $endpoint_manager->process_queue($_SESSION['domain_uuid']);
				$counts = $endpoint_manager->status_counts($_SESSION['domain_uuid'], $log_uuids);

				//build the message
				$message = $text['message-reboot_sent'].": ".($counts['sent'] ?? 0);
				$message .= ", ".$text['message-reboot_skipped_busy'].": ".($counts['skipped_busy'] ?? 0);
				$message .= ", ".$text['message-reboot_skipped_other'].": ".(($counts['skipped_unregistered'] ?? 0) + ($counts['skipped_unsupported'] ?? 0));
				$message .= ", ".$text['message-reboot_failed'].": ".($counts['failed'] ?? 0);
				$message .= ", ".$text['message-reboot_queued'].": ".(($counts['pending'] ?? 0) + ($counts['processing'] ?? 0));
				$already_queued = count(array_unique($device_uuids)) - count($log_uuids);
				if ($already_queued > 0) {
					$message .= ", ".$text['message-reboot_already_queued'].": ".$already_queued;
				}
				if ($summary['switch_unavailable']) {
					message::add($text['message-switch_unavailable'], 'negative', 7000);
				}
				$failed = ($counts['failed'] ?? 0) > 0;
				message::add($message, ($failed ? 'negative' : 'positive'), 7000);
			}

		//redirect
			header('Location: x_endpoint_manager.php'.($query_string ? '?'.$query_string : ''));
			exit;
	}

//get the count
	$sql = "select count(*) from v_devices ";
	$sql .= "where domain_uuid = :domain_uuid ";
	if (!empty($search)) {
		$sql .= "and ( ";
		$sql .= "	lower(device_address) like :search ";
		$sql .= "	or lower(device_label) like :search ";
		$sql .= "	or lower(device_vendor) like :search ";
		$sql .= "	or lower(device_model) like :search ";
		$sql .= "	or lower(device_description) like :search ";
		$sql .= ") ";
		$parameters['search'] = '%'.lower_case($search).'%';
	}
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$num_rows = $database->select($sql, $parameters, 'column');

//prepare to page the results
	$rows_per_page = $settings->get('domain', 'paging', 50);
	list($paging_controls, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page);
	list($paging_controls_mini, $rows_per_page) = paging($num_rows, $query_string, $rows_per_page, true);
	$offset = $rows_per_page * $page;

//get the list
	$sql = "select device_uuid, device_address, device_label, device_vendor, device_model, ";
	$sql .= "cast(device_enabled as text) as device_enabled, device_description ";
	$sql .= "from v_devices ";
	$sql .= "where domain_uuid = :domain_uuid ";
	if (!empty($search)) {
		$sql .= "and ( ";
		$sql .= "	lower(device_address) like :search ";
		$sql .= "	or lower(device_label) like :search ";
		$sql .= "	or lower(device_vendor) like :search ";
		$sql .= "	or lower(device_model) like :search ";
		$sql .= "	or lower(device_description) like :search ";
		$sql .= ") ";
	}
	$sql .= order_by($order_by, $order, 'device_address', 'asc');
	$sql .= limit_offset($rows_per_page, $offset);
	$devices = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);

//get the last sent reboot of the listed devices
	$time_zone = $endpoint_manager->domain_time_zone($_SESSION['domain_uuid'])->getName();
	$last_reboots = [];
	if (!empty($devices) && is_array($devices)) {
		$placeholders = [];
		foreach (array_column($devices, 'device_uuid') as $i => $device_uuid) {
			$placeholders[] = ':device_uuid_'.$i;
			$parameters['device_uuid_'.$i] = $device_uuid;
		}
		$sql = "select device_uuid, to_char(timezone(:time_zone, max(process_date)), 'YYYY-MM-DD HH24:MI') as last_reboot ";
		$sql .= "from v_x_endpoint_reboot_log ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and reboot_status = 'sent' ";
		$sql .= "and device_uuid in (".implode(', ', $placeholders).") ";
		$sql .= "group by device_uuid ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$parameters['time_zone'] = $time_zone;
		$rows = $database->select($sql, $parameters, 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$last_reboots[$row['device_uuid']] = $row['last_reboot'];
			}
		}
		unset($sql, $parameters, $rows, $row);
	}

//get the queued reboots for the domain
	$sql = "select count(*) from v_x_endpoint_reboot_log ";
	$sql .= "where domain_uuid = :domain_uuid ";
	$sql .= "and reboot_status in ('pending', 'processing') ";
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$queued_count = (int)$database->select($sql, $parameters, 'column');
	unset($sql, $parameters);

//get the live state from the switch
	$registrations = $endpoint_manager->get_registrations($_SESSION['domain_uuid']);
	$busy_users = $endpoint_manager->get_busy_users();
	$device_users = $endpoint_manager->get_device_users($_SESSION['domain_uuid']);

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-x_endpoint_manager'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_endpoint_manager']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-refresh'],'icon'=>$settings->get('theme', 'button_icon_refresh'),'link'=>'x_endpoint_manager.php'.($query_string ? '?'.$query_string : '')]);
	if (permission_exists('x_endpoint_manager_schedule_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-schedules'],'icon'=>'calendar-alt','link'=>'x_endpoint_reboot_schedules.php']);
	}
	if (permission_exists('x_endpoint_manager_log_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-log'],'icon'=>'list','link'=>'x_endpoint_reboot_logs.php']);
	}
	if (permission_exists('x_endpoint_manager_reboot') && $devices) {
		echo button::create(['type'=>'button','label'=>$text['button-reboot'],'icon'=>'power-off','id'=>'btn_reboot','name'=>'btn_reboot','onclick'=>"modal_open('modal-reboot','btn_reboot');"]);
	}
	echo 		"<form id='form_search' class='inline' method='get'>\n";
	foreach ($param as $key => $value) {
		if (in_array($key, ['order_by', 'order'])) {
			echo "		<input type='hidden' name='".escape($key)."' value='".escape($value)."'>\n";
		}
	}
	echo "		<input type='text' class='txt list-search' name='search' id='search' value=\"".escape($search)."\" placeholder=\"".$text['label-search']."\" onkeydown=''>";
	echo button::create(['label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search'),'type'=>'submit','id'=>'btn_search']);
	if (!empty($paging_controls_mini)) {
		echo "	<span style='margin-left: 15px;'>".$paging_controls_mini."</span>";
	}
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if (permission_exists('x_endpoint_manager_reboot') && $devices) {
		echo modal::create(['id'=>'modal-reboot','type'=>'general','message'=>$text['confirm-reboot'],'actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_reboot','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('reboot'); list_form_submit('form_list');"])]);
	}

	echo $text['description-x_endpoint_manager']."\n";
	if ($queued_count > 0) {
		echo "<br /><br /><b>".$text['label-queued_reboots'].":</b> ".number_format($queued_count)."\n";
	}
	if ($registrations === null || $busy_users === null) {
		echo "<br /><br /><b>".$text['message-switch_unavailable']."</b>\n";
	}
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('x_endpoint_manager_reboot')) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".(!empty($devices) ?: "style='visibility: hidden;'").">\n";
		echo "	</th>\n";
	}
	echo th_order_by('device_address', $text['label-device_address'], $order_by, $order, null, null, $param);
	echo th_order_by('device_label', $text['label-device_label'], $order_by, $order, null, null, $param);
	echo th_order_by('device_vendor', $text['label-device_vendor'], $order_by, $order, null, null, $param);
	echo th_order_by('device_model', $text['label-device_model'], $order_by, $order, null, "class='hide-sm-dn'", $param);
	echo "	<th class='hide-sm-dn'>".$text['label-lines']."</th>\n";
	echo "	<th>".$text['label-registered']."</th>\n";
	echo "	<th class='center'>".$text['label-on_call']."</th>\n";
	echo "	<th class='hide-md-dn'>".$text['label-last_reboot']."</th>\n";
	echo th_order_by('device_enabled', $text['label-enabled'], $order_by, $order, null, "class='center hide-sm-dn'", $param);
	if (permission_exists('x_endpoint_manager_reboot')) {
		echo "	<td class='action-button'>&nbsp;</td>\n";
	}
	echo "</tr>\n";

	if (!empty($devices) && is_array($devices)) {
		$x = 0;
		foreach ($devices as $row) {
			//live state
			$users = $device_users[$row['device_uuid']] ?? [];
			$matches = is_array($registrations) ? $endpoint_manager->match_registrations($registrations, $users) : [];
			$on_call = false;
			if (is_array($busy_users)) {
				foreach ($users as $user) {
					if (isset($busy_users[$user])) {
						$on_call = true;
						break;
					}
				}
			}

			echo "<tr class='list-row' href='#'>\n";
			if (permission_exists('x_endpoint_manager_reboot')) {
				echo "	<td class='checkbox'>\n";
				echo "		<input type='checkbox' name='devices[".$x."][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "		<input type='hidden' name='devices[".$x."][uuid]' value='".escape($row['device_uuid'])."' />\n";
				echo "	</td>\n";
			}
			echo "	<td>".escape(format_device_address($row['device_address']))."</td>\n";
			echo "	<td>".escape($row['device_label'])."</td>\n";
			echo "	<td>".escape($row['device_vendor'])."</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($row['device_model'])."</td>\n";
			echo "	<td class='hide-sm-dn'>".escape(implode(', ', array_map(function ($user) { return explode('@', $user)[0]; }, $users)))."</td>\n";
			if ($registrations === null) {
				echo "	<td>&nbsp;</td>\n";
			}
			elseif (!empty($matches)) {
				echo "	<td title=\"".escape($matches[0]['agent'] ?? '')."\"><span class='cursor-help'>".$text['label-yes']." (".count($matches).")</span></td>\n";
			}
			else {
				echo "	<td>".$text['label-no']."</td>\n";
			}
			echo "	<td class='center'>".($busy_users === null ? '' : ($on_call ? $text['label-yes'] : $text['label-no']))."</td>\n";
			echo "	<td class='hide-md-dn no-wrap'>".escape($last_reboots[$row['device_uuid']] ?? '')."</td>\n";
			echo "	<td class='center hide-sm-dn'>".$text['label-'.($row['device_enabled'] == 'true' ? 'true' : 'false')]."</td>\n";
			if (permission_exists('x_endpoint_manager_reboot')) {
				echo "	<td class='action-button'>";
				echo button::create(['type'=>'button','title'=>$text['button-reboot'],'icon'=>'power-off','onclick'=>"list_self_check('checkbox_".$x."'); modal_open('modal-reboot','btn_reboot');"]);
				echo "	</td>\n";
			}
			echo "</tr>\n";
			$x++;
		}
		unset($devices);
	}

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";
	echo "<div align='center'>".$paging_controls."</div>\n";

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
