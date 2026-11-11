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
	if (!permission_exists('x_endpoint_manager_schedule_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set variables from GET parameters
	$order_by = in_array($_GET['order_by'] ?? '', ['schedule_name', 'next_run_date', 'last_run_date', 'schedule_status', 'schedule_enabled'], true) ? $_GET['order_by'] : '';
	$order = ($_GET['order'] ?? '') == 'desc' ? 'desc' : 'asc';
	$param = [];
	if (!empty($order_by)) {
		$param['order_by'] = $order_by;
		$param['order'] = $order;
	}
	$query_string = http_build_query($param);

//create the endpoint manager object
	$endpoint_manager = new x_endpoint_manager(['database' => $database]);

//get posted data
	$action = '';
	$schedules = [];
	if (!empty($_POST['schedules']) && is_array($_POST['schedules'])) {
		$action = $_POST['action'] ?? '';
		foreach ($_POST['schedules'] as $row) {
			if (is_array($row) && ($row['checked'] ?? '') == 'true' && is_uuid($row['uuid'] ?? '')) {
				$schedules[] = $row['uuid'];
			}
		}
	}

//process the http post data by action
	if (!empty($action) && !empty($schedules)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: x_endpoint_reboot_schedules.php'.($query_string ? '?'.$query_string : ''));
				exit;
			}

		switch ($action) {
			case 'toggle':
				if (permission_exists('x_endpoint_manager_schedule_edit')) {
					foreach ($schedules as $schedule_uuid) {
						$sql = "select * from v_x_endpoint_reboot_schedules ";
						$sql .= "where domain_uuid = :domain_uuid ";
						$sql .= "and x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
						$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
						$parameters['schedule_uuid'] = $schedule_uuid;
						$schedule = $database->select($sql, $parameters, 'row');
						unset($sql, $parameters);
						if (empty($schedule)) {
							continue;
						}

						//when enabling, compute the next run from now so a stale run is not reported as missed
						$enabled = !($schedule['schedule_enabled'] === true || $schedule['schedule_enabled'] === 'true' || $schedule['schedule_enabled'] === 't' || $schedule['schedule_enabled'] === 1);
						$state = $enabled ? $endpoint_manager->schedule_state($schedule, $_SESSION['domain_uuid']) : ['next_run_date' => $schedule['next_run_date'], 'schedule_status' => $schedule['schedule_status']];

						$sql = "update v_x_endpoint_reboot_schedules set ";
						$sql .= "schedule_enabled = :schedule_enabled, ";
						$sql .= "next_run_date = :next_run_date, ";
						$sql .= "schedule_status = :schedule_status, ";
						$sql .= "update_date = now(), ";
						$sql .= "update_user = :update_user ";
						$sql .= "where domain_uuid = :domain_uuid ";
						$sql .= "and x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
						$parameters['schedule_enabled'] = $enabled ? 'true' : 'false';
						$parameters['next_run_date'] = $state['next_run_date'];
						$parameters['schedule_status'] = $state['schedule_status'];
						$parameters['update_user'] = is_uuid($_SESSION['user_uuid'] ?? '') ? $_SESSION['user_uuid'] : null;
						$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
						$parameters['schedule_uuid'] = $schedule_uuid;
						$database->execute($sql, $parameters);
						unset($sql, $parameters);

						//stop queued reboots of a disabled schedule
						if (!$enabled) {
							$endpoint_manager->cancel_schedule_queue($_SESSION['domain_uuid'], $schedule_uuid, 'Schedule disabled.');
						}
					}
					message::add($text['message-toggle']);
				}
				break;
			case 'delete':
				if (permission_exists('x_endpoint_manager_schedule_delete')) {
					foreach ($schedules as $schedule_uuid) {
						$endpoint_manager->cancel_schedule_queue($_SESSION['domain_uuid'], $schedule_uuid, 'Schedule deleted.');
						$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
						$parameters['schedule_uuid'] = $schedule_uuid;
						$sql = "delete from v_x_endpoint_reboot_targets ";
						$sql .= "where domain_uuid = :domain_uuid ";
						$sql .= "and x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
						$database->execute($sql, $parameters);
						$sql = "delete from v_x_endpoint_reboot_schedules ";
						$sql .= "where domain_uuid = :domain_uuid ";
						$sql .= "and x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
						$database->execute($sql, $parameters);
						unset($sql, $parameters);
					}
					message::add($text['message-delete']);
				}
				break;
		}

		header('Location: x_endpoint_reboot_schedules.php'.($query_string ? '?'.$query_string : ''));
		exit;
	}

//get the list
	$time_zone = $endpoint_manager->domain_time_zone($_SESSION['domain_uuid'])->getName();
	$sql = "select s.x_endpoint_reboot_schedule_uuid, s.schedule_name, s.target_type, s.target_vendor, s.target_model, ";
	$sql .= "s.recurrence, s.run_date, s.run_time, s.run_days, s.schedule_status, cast(s.schedule_enabled as text) as schedule_enabled, ";
	$sql .= "s.schedule_description, ";
	$sql .= "to_char(timezone(:time_zone, s.next_run_date), 'YYYY-MM-DD HH24:MI') as next_run_formatted, ";
	$sql .= "to_char(timezone(:time_zone, s.last_run_date), 'YYYY-MM-DD HH24:MI') as last_run_formatted, ";
	$sql .= "(select count(*) from v_x_endpoint_reboot_targets as t where t.x_endpoint_reboot_schedule_uuid = s.x_endpoint_reboot_schedule_uuid and t.domain_uuid = s.domain_uuid) as target_count ";
	$sql .= "from v_x_endpoint_reboot_schedules as s ";
	$sql .= "where s.domain_uuid = :domain_uuid ";
	$sql .= order_by(!empty($order_by) ? 's.'.$order_by : '', $order, 's.schedule_name', 'asc');
	$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
	$parameters['time_zone'] = $time_zone;
	$result = $database->select($sql, $parameters, 'all');
	unset($sql, $parameters);
	$num_rows = is_array($result) ? count($result) : 0;

//weekday names
	$weekdays = [1 => $text['label-monday'], 2 => $text['label-tuesday'], 3 => $text['label-wednesday'], 4 => $text['label-thursday'], 5 => $text['label-friday'], 6 => $text['label-saturday'], 7 => $text['label-sunday']];

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-x_endpoint_reboot_schedules'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_endpoint_reboot_schedules']."</b><div class='count'>".number_format($num_rows)."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'x_endpoint_manager.php']);
	if (permission_exists('x_endpoint_manager_schedule_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add','link'=>'x_endpoint_reboot_schedule_edit.php']);
	}
	if (permission_exists('x_endpoint_manager_schedule_edit') && $result) {
		echo button::create(['type'=>'button','label'=>$text['button-toggle'],'icon'=>$settings->get('theme', 'button_icon_toggle'),'id'=>'btn_toggle','name'=>'btn_toggle','style'=>'display: none;','onclick'=>"modal_open('modal-toggle','btn_toggle');"]);
	}
	if (permission_exists('x_endpoint_manager_schedule_delete') && $result) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_delete','name'=>'btn_delete','style'=>'display: none;','onclick'=>"modal_open('modal-delete','btn_delete');"]);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if (permission_exists('x_endpoint_manager_schedule_edit') && $result) {
		echo modal::create(['id'=>'modal-toggle','type'=>'toggle','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_toggle','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('toggle'); list_form_submit('form_list');"])]);
	}
	if (permission_exists('x_endpoint_manager_schedule_delete') && $result) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); list_action_set('delete'); list_form_submit('form_list');"])]);
	}

	echo $text['description-x_endpoint_reboot_schedules']." (".escape($time_zone).")\n";
	echo "<br /><br />\n";

	echo "<form id='form_list' method='post'>\n";
	echo "<input type='hidden' id='action' name='action' value=''>\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	if (permission_exists('x_endpoint_manager_schedule_edit') || permission_exists('x_endpoint_manager_schedule_delete')) {
		echo "	<th class='checkbox'>\n";
		echo "		<input type='checkbox' id='checkbox_all' name='checkbox_all' onclick='list_all_toggle(); checkbox_on_change(this);' ".(!empty($result) ?: "style='visibility: hidden;'").">\n";
		echo "	</th>\n";
	}
	echo th_order_by('schedule_name', $text['label-schedule_name'], $order_by, $order, null, null, $param);
	echo "	<th>".$text['label-target']."</th>\n";
	echo "	<th>".$text['label-recurrence']."</th>\n";
	echo th_order_by('next_run_date', $text['label-next_run'], $order_by, $order, null, "class='no-wrap'", $param);
	echo th_order_by('last_run_date', $text['label-last_run'], $order_by, $order, null, "class='no-wrap hide-sm-dn'", $param);
	echo th_order_by('schedule_status', $text['label-schedule_status'], $order_by, $order, null, "class='hide-sm-dn'", $param);
	echo th_order_by('schedule_enabled', $text['label-enabled'], $order_by, $order, null, "class='center'", $param);
	echo "	<th class='hide-md-dn pct-20'>".$text['label-description']."</th>\n";
	if (permission_exists('x_endpoint_manager_schedule_edit')) {
		echo "	<td class='action-button'>&nbsp;</td>\n";
	}
	echo "</tr>\n";

	if (!empty($result) && is_array($result)) {
		$x = 0;
		foreach ($result as $row) {
			$list_row_url = '';
			if (permission_exists('x_endpoint_manager_schedule_edit')) {
				$list_row_url = "x_endpoint_reboot_schedule_edit.php?id=".urlencode($row['x_endpoint_reboot_schedule_uuid']);
			}

			//describe the target
			switch ($row['target_type']) {
				case 'all':
					$target = $text['option-target_all'];
					break;
				case 'vendor':
					$target = $text['option-target_vendor'].": ".$row['target_vendor'].(!empty($row['target_model']) ? " / ".$row['target_model'] : '');
					break;
				case 'devices':
					$target = $text['option-target_devices'].": ".$row['target_count'];
					break;
				default:
					$target = '';
			}

			//describe the recurrence
			switch ($row['recurrence']) {
				case 'once':
					$recurrence = $text['option-recurrence_once']." ".$row['run_date']." ".$row['run_time'];
					break;
				case 'daily':
					$recurrence = $text['option-recurrence_daily']." ".$row['run_time'];
					break;
				case 'weekly':
					$day_names = [];
					foreach (x_endpoint_manager::parse_days($row['run_days']) as $day) {
						$day_names[] = $weekdays[$day];
					}
					$recurrence = $text['option-recurrence_weekly']." ".implode(', ', $day_names)." ".$row['run_time'];
					break;
				default:
					$recurrence = '';
			}

			echo "<tr class='list-row' href='".$list_row_url."'>\n";
			if (permission_exists('x_endpoint_manager_schedule_edit') || permission_exists('x_endpoint_manager_schedule_delete')) {
				echo "	<td class='checkbox'>\n";
				echo "		<input type='checkbox' name='schedules[".$x."][checked]' id='checkbox_".$x."' value='true' onclick=\"checkbox_on_change(this); if (!this.checked) { document.getElementById('checkbox_all').checked = false; }\">\n";
				echo "		<input type='hidden' name='schedules[".$x."][uuid]' value='".escape($row['x_endpoint_reboot_schedule_uuid'])."' />\n";
				echo "	</td>\n";
			}
			echo "	<td>";
			if (permission_exists('x_endpoint_manager_schedule_edit')) {
				echo "<a href='".$list_row_url."'>".escape($row['schedule_name'])."</a>";
			}
			else {
				echo escape($row['schedule_name']);
			}
			echo "</td>\n";
			echo "	<td>".escape($target)."</td>\n";
			echo "	<td>".escape($recurrence)."</td>\n";
			echo "	<td class='no-wrap'>".escape($row['next_run_formatted'])."</td>\n";
			echo "	<td class='no-wrap hide-sm-dn'>".escape($row['last_run_formatted'])."</td>\n";
			echo "	<td class='hide-sm-dn'>".escape($text['option-status_'.$row['schedule_status']] ?? $row['schedule_status'])."</td>\n";
			if (permission_exists('x_endpoint_manager_schedule_edit')) {
				echo "	<td class='no-link center'>";
				echo button::create(['type'=>'submit','class'=>'link','label'=>$text['label-'.($row['schedule_enabled'] == 'true' ? 'true' : 'false')],'title'=>$text['button-toggle'],'onclick'=>"list_self_check('checkbox_".$x."'); list_action_set('toggle'); list_form_submit('form_list')"]);
			}
			else {
				echo "	<td class='center'>";
				echo $text['label-'.($row['schedule_enabled'] == 'true' ? 'true' : 'false')];
			}
			echo "	</td>\n";
			echo "	<td class='description overflow hide-md-dn'>".escape($row['schedule_description'])."</td>\n";
			if (permission_exists('x_endpoint_manager_schedule_edit')) {
				echo "	<td class='action-button'>";
				echo button::create(['type'=>'button','title'=>$text['button-edit'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>$list_row_url]);
				echo "	</td>\n";
			}
			echo "</tr>\n";
			$x++;
		}
		unset($result);
	}

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
