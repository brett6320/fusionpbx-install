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
	if (!(permission_exists('x_endpoint_manager_schedule_add') || permission_exists('x_endpoint_manager_schedule_edit'))) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//create the endpoint manager object
	$endpoint_manager = new x_endpoint_manager(['database' => $database]);

//action add or update
	if (!empty($_REQUEST['id']) && is_uuid($_REQUEST['id'])) {
		$action = 'update';
		$schedule_uuid = $_REQUEST['id'];
		if (!permission_exists('x_endpoint_manager_schedule_edit')) {
			echo "access denied";
			exit;
		}
	}
	else {
		$action = 'add';
		if (!permission_exists('x_endpoint_manager_schedule_add')) {
			echo "access denied";
			exit;
		}
	}

//set the defaults
	$schedule_name = '';
	$schedule_enabled = 'true';
	$target_type = 'all';
	$target_vendor = '';
	$target_model = '';
	$target_devices = [];
	$recurrence = 'weekly';
	$run_date = '';
	$run_time = '03:00';
	$run_days = [];
	$schedule_description = '';
	$next_run_formatted = '';
	$time_zone = $endpoint_manager->domain_time_zone($_SESSION['domain_uuid'])->getName();

//make sure an existing schedule belongs to this domain
	if ($action == 'update') {
		$sql = "select *, to_char(timezone(:time_zone, next_run_date), 'YYYY-MM-DD HH24:MI') as next_run_formatted ";
		$sql .= "from v_x_endpoint_reboot_schedules ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
		$parameters['time_zone'] = $time_zone;
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$parameters['schedule_uuid'] = $schedule_uuid;
		$row = $database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		if (empty($row)) {
			message::add($text['message-schedule_not_found'], 'negative');
			header('Location: x_endpoint_reboot_schedules.php');
			exit;
		}
		$existing_schedule = $row;
		unset($row);
	}

//delete the schedule
	if (!empty($_POST) && ($_POST['action'] ?? '') == 'delete' && $action == 'update') {
		if (permission_exists('x_endpoint_manager_schedule_delete')) {
			//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: x_endpoint_reboot_schedules.php');
				exit;
			}

			//stop queued reboots, then delete the targets and the schedule
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
			message::add($text['message-delete']);
		}
		header('Location: x_endpoint_reboot_schedules.php');
		exit;
	}

//get the http post values and set them as php variables
	if (!empty($_POST)) {
		$schedule_name = trim((string)($_POST['schedule_name'] ?? ''));
		$schedule_enabled = ($_POST['schedule_enabled'] ?? '') == 'true' ? 'true' : 'false';
		$target_type = in_array($_POST['target_type'] ?? '', ['all', 'vendor', 'devices'], true) ? $_POST['target_type'] : '';
		$target_vendor = strtolower(trim((string)($_POST['target_vendor'] ?? '')));
		$target_model = trim((string)($_POST['target_model'] ?? ''));
		$target_devices = [];
		if (!empty($_POST['target_devices']) && is_array($_POST['target_devices'])) {
			foreach ($_POST['target_devices'] as $device_uuid) {
				if (is_string($device_uuid) && is_uuid($device_uuid)) {
					$target_devices[] = $device_uuid;
				}
			}
			$target_devices = array_values(array_unique($target_devices));
		}
		$recurrence = in_array($_POST['recurrence'] ?? '', ['once', 'daily', 'weekly'], true) ? $_POST['recurrence'] : '';
		$run_date = trim((string)($_POST['run_date'] ?? ''));
		$run_time = trim((string)($_POST['run_time'] ?? ''));
		$run_days = [];
		if (!empty($_POST['run_days']) && is_array($_POST['run_days'])) {
			$run_days = x_endpoint_manager::parse_days(implode(',', array_filter($_POST['run_days'], 'is_string')));
		}
		$schedule_description = trim((string)($_POST['schedule_description'] ?? ''));
	}

//process the http post
	if (!empty($_POST) && empty($_POST['action'])) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: x_endpoint_reboot_schedules.php');
				exit;
			}

		//only keep target devices that belong to this domain
			if ($target_type == 'devices' && !empty($target_devices)) {
				$target_devices = array_column($endpoint_manager->get_devices($_SESSION['domain_uuid'], $target_devices), 'device_uuid');
			}

		//build the schedule to validate it
			$schedule = [
				'recurrence' => $recurrence,
				'run_date' => ($recurrence == 'once' ? $run_date : null),
				'run_time' => $run_time,
				'run_days' => ($recurrence == 'weekly' ? implode(',', $run_days) : null),
			];

		//check for all required data
			$msg = [];
			if ($schedule_name === '') { $msg[] = $text['message-required']." ".$text['label-schedule_name']; }
			if ($target_type === '') { $msg[] = $text['message-required']." ".$text['label-target']; }
			if ($target_type == 'vendor' && $target_vendor === '') { $msg[] = $text['message-required']." ".$text['label-target_vendor']; }
			if ($target_type == 'devices' && empty($target_devices)) { $msg[] = $text['message-required']." ".$text['label-target_devices']; }
			if ($recurrence === '') { $msg[] = $text['message-required']." ".$text['label-recurrence']; }
			if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $run_time)) { $msg[] = $text['message-invalid_time']; }
			if ($recurrence == 'once' && !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $run_date)) { $msg[] = $text['message-invalid_date']; }
			if ($recurrence == 'weekly' && empty($run_days)) { $msg[] = $text['message-required']." ".$text['label-run_days']; }
			if (empty($msg)) {
				$state = $endpoint_manager->schedule_state($schedule, $_SESSION['domain_uuid']);
				if ($state['next_run_date'] === null) {
					$msg[] = ($recurrence == 'once') ? $text['message-date_in_past'] : $text['message-invalid_date'];
				}
			}

		//save the data
			if (empty($msg)) {
				if ($action == 'add') {
					$schedule_uuid = uuid();
				}

				$array['x_endpoint_reboot_schedules'][0]['x_endpoint_reboot_schedule_uuid'] = $schedule_uuid;
				$array['x_endpoint_reboot_schedules'][0]['domain_uuid'] = $_SESSION['domain_uuid'];
				$array['x_endpoint_reboot_schedules'][0]['schedule_name'] = $schedule_name;
				$array['x_endpoint_reboot_schedules'][0]['target_type'] = $target_type;
				$array['x_endpoint_reboot_schedules'][0]['target_vendor'] = ($target_type == 'vendor' ? $target_vendor : null);
				$array['x_endpoint_reboot_schedules'][0]['target_model'] = ($target_type == 'vendor' && $target_model !== '' ? $target_model : null);
				$array['x_endpoint_reboot_schedules'][0]['recurrence'] = $recurrence;
				$array['x_endpoint_reboot_schedules'][0]['run_date'] = $schedule['run_date'];
				$array['x_endpoint_reboot_schedules'][0]['run_time'] = $run_time;
				$array['x_endpoint_reboot_schedules'][0]['run_days'] = $schedule['run_days'];
				$array['x_endpoint_reboot_schedules'][0]['next_run_date'] = $state['next_run_date'];
				$array['x_endpoint_reboot_schedules'][0]['schedule_status'] = $state['schedule_status'];
				$array['x_endpoint_reboot_schedules'][0]['schedule_enabled'] = $schedule_enabled;
				$array['x_endpoint_reboot_schedules'][0]['schedule_description'] = $schedule_description;

				//grant temporary permissions
				$p = permissions::new();
				$p->add('x_endpoint_reboot_schedule_add', 'temp');
				$p->add('x_endpoint_reboot_schedule_edit', 'temp');
				$p->add('x_endpoint_reboot_target_add', 'temp');

				//save the schedule
				$database->save($array);
				unset($array);

				//replace the targets
				$sql = "delete from v_x_endpoint_reboot_targets ";
				$sql .= "where domain_uuid = :domain_uuid ";
				$sql .= "and x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
				$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
				$parameters['schedule_uuid'] = $schedule_uuid;
				$database->execute($sql, $parameters);
				unset($sql, $parameters);
				if ($target_type == 'devices') {
					foreach ($target_devices as $i => $device_uuid) {
						$array['x_endpoint_reboot_targets'][$i]['x_endpoint_reboot_target_uuid'] = uuid();
						$array['x_endpoint_reboot_targets'][$i]['domain_uuid'] = $_SESSION['domain_uuid'];
						$array['x_endpoint_reboot_targets'][$i]['x_endpoint_reboot_schedule_uuid'] = $schedule_uuid;
						$array['x_endpoint_reboot_targets'][$i]['device_uuid'] = $device_uuid;
					}
					if (!empty($array)) {
						$database->save($array);
						unset($array);
					}
				}

				//revoke temporary permissions
				$p->delete('x_endpoint_reboot_schedule_add', 'temp');
				$p->delete('x_endpoint_reboot_schedule_edit', 'temp');
				$p->delete('x_endpoint_reboot_target_add', 'temp');

				//stop queued reboots of a disabled schedule
				if ($schedule_enabled == 'false' && $action == 'update') {
					$endpoint_manager->cancel_schedule_queue($_SESSION['domain_uuid'], $schedule_uuid, 'Schedule disabled.');
				}

				//redirect
				message::add($action == 'add' ? $text['message-add'] : $text['message-update']);
				header('Location: x_endpoint_reboot_schedules.php');
				exit;
			}

		//show the validation errors and redisplay the form with the posted values
			message::add(implode("<br />\n", array_map('escape', $msg)), 'negative', 7000);
	}

//pre-populate the form
	if (empty($_POST) && $action == 'update' && !empty($existing_schedule)) {
		$schedule_name = $existing_schedule['schedule_name'];
		$enabled_value = $existing_schedule['schedule_enabled'];
		$schedule_enabled = ($enabled_value === true || $enabled_value === 'true' || $enabled_value === 't' || $enabled_value === 1) ? 'true' : 'false';
		$target_type = $existing_schedule['target_type'];
		$target_vendor = $existing_schedule['target_vendor'] ?? '';
		$target_model = $existing_schedule['target_model'] ?? '';
		$recurrence = $existing_schedule['recurrence'];
		$run_date = $existing_schedule['run_date'] ?? '';
		$run_time = $existing_schedule['run_time'] ?? '';
		$run_days = x_endpoint_manager::parse_days($existing_schedule['run_days'] ?? '');
		$schedule_description = $existing_schedule['schedule_description'] ?? '';

		$sql = "select device_uuid from v_x_endpoint_reboot_targets ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
		$parameters['domain_uuid'] = $_SESSION['domain_uuid'];
		$parameters['schedule_uuid'] = $schedule_uuid;
		$rows = $database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		$target_devices = is_array($rows) ? array_column($rows, 'device_uuid') : [];
	}
	if ($action == 'update' && !empty($existing_schedule)) {
		$next_run_formatted = $existing_schedule['next_run_formatted'] ?? '';
	}

//get the devices, vendors and models of this domain
	$devices = $endpoint_manager->get_devices($_SESSION['domain_uuid']);
	$vendors = [];
	$models = [];
	foreach ($devices as $device) {
		if (!empty($device['device_vendor'])) {
			$vendors[strtolower($device['device_vendor'])] = true;
		}
		if (!empty($device['device_model'])) {
			$models[$device['device_model']] = true;
		}
	}
	$vendors = array_keys($vendors);
	sort($vendors);
	$models = array_keys($models);
	sort($models);

//weekday names
	$weekdays = [1 => $text['label-monday'], 2 => $text['label-tuesday'], 3 => $text['label-wednesday'], 4 => $text['label-thursday'], 5 => $text['label-friday'], 6 => $text['label-saturday'], 7 => $text['label-sunday']];

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-x_endpoint_reboot_schedule'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm'>\n";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_endpoint_reboot_schedule']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','collapse'=>'hide-xs','style'=>'margin-right: 15px;','link'=>'x_endpoint_reboot_schedules.php']);
	if ($action == 'update' && permission_exists('x_endpoint_manager_schedule_delete')) {
		echo button::create(['type'=>'button','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'name'=>'btn_delete','collapse'=>'hide-xs','style'=>'margin-right: 15px;','onclick'=>"modal_open('modal-delete','btn_delete');"]);
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save','collapse'=>'hide-xs']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if ($action == 'update' && permission_exists('x_endpoint_manager_schedule_delete')) {
		echo modal::create(['id'=>'modal-delete','type'=>'delete','actions'=>button::create(['type'=>'submit','label'=>$text['button-continue'],'icon'=>'check','id'=>'btn_delete','style'=>'float: right; margin-left: 15px;','collapse'=>'never','name'=>'action','value'=>'delete','onclick'=>"modal_close();"])]);
	}

	echo $text['description-x_endpoint_reboot_schedule']." (".escape($time_zone).")\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-schedule_name']."\n";
	echo "</td>\n";
	echo "<td width='70%' class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='schedule_name' maxlength='255' value=\"".escape($schedule_name)."\" required='required'>\n";
	echo "<br />\n";
	echo $text['description-schedule_name']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-target']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='target_type' id='target_type' onchange='x_endpoint_manager_show_fields();'>\n";
	echo "		<option value='all' ".($target_type == 'all' ? "selected='selected'" : '').">".$text['option-target_all']."</option>\n";
	echo "		<option value='vendor' ".($target_type == 'vendor' ? "selected='selected'" : '').">".$text['option-target_vendor']."</option>\n";
	echo "		<option value='devices' ".($target_type == 'devices' ? "selected='selected'" : '').">".$text['option-target_devices']."</option>\n";
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-target']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr id='row_target_vendor'>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-target_vendor']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='target_vendor'>\n";
	echo "		<option value=''></option>\n";
	foreach ($vendors as $vendor) {
		echo "		<option value='".escape($vendor)."' ".($target_vendor == $vendor ? "selected='selected'" : '').">".escape($vendor)."</option>\n";
	}
	if ($target_vendor !== '' && !in_array($target_vendor, $vendors, true)) {
		echo "		<option value='".escape($target_vendor)."' selected='selected'>".escape($target_vendor)."</option>\n";
	}
	echo "	</select>\n";
	echo "	<input class='formfld' type='text' name='target_model' maxlength='255' list='target_models' placeholder=\"".escape($text['label-target_model'])."\" value=\"".escape($target_model)."\">\n";
	echo "	<datalist id='target_models'>\n";
	foreach ($models as $model) {
		echo "		<option value='".escape($model)."'>\n";
	}
	echo "	</datalist>\n";
	echo "<br />\n";
	echo $text['description-target_vendor']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr id='row_target_devices'>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-target_devices']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='target_devices[]' multiple='multiple' size='10' style='height: auto; min-width: 300px;'>\n";
	foreach ($devices as $device) {
		$selected = in_array($device['device_uuid'], $target_devices, true) ? "selected='selected'" : '';
		$label = format_device_address($device['device_address']);
		if (!empty($device['device_label'])) {
			$label .= " - ".$device['device_label'];
		}
		if (!empty($device['device_vendor'])) {
			$label .= " (".$device['device_vendor'].(!empty($device['device_model']) ? " ".$device['device_model'] : '').")";
		}
		echo "		<option value='".escape($device['device_uuid'])."' ".$selected.">".escape($label)."</option>\n";
	}
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-target_devices']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-recurrence']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='recurrence' id='recurrence' onchange='x_endpoint_manager_show_fields();'>\n";
	echo "		<option value='once' ".($recurrence == 'once' ? "selected='selected'" : '').">".$text['option-recurrence_once']."</option>\n";
	echo "		<option value='daily' ".($recurrence == 'daily' ? "selected='selected'" : '').">".$text['option-recurrence_daily']."</option>\n";
	echo "		<option value='weekly' ".($recurrence == 'weekly' ? "selected='selected'" : '').">".$text['option-recurrence_weekly']."</option>\n";
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-recurrence']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr id='row_run_date'>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-run_date']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='date' name='run_date' value=\"".escape($run_date)."\">\n";
	echo "<br />\n";
	echo $text['description-run_date']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr id='row_run_days'>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-run_days']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	foreach ($weekdays as $day => $day_name) {
		echo "	<label style='margin-right: 12px;'><input type='checkbox' name='run_days[]' value='".$day."' ".(in_array($day, $run_days, true) ? "checked='checked'" : '')."> ".escape($day_name)."</label>\n";
	}
	echo "<br />\n";
	echo $text['description-run_days']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-run_time']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='time' name='run_time' value=\"".escape($run_time)."\" required='required'>\n";
	echo "<br />\n";
	echo $text['description-run_time']." (".escape($time_zone).")\n";
	echo "</td>\n";
	echo "</tr>\n";

	if (!empty($next_run_formatted)) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
		echo "	".$text['label-next_run']."\n";
		echo "</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	".escape($next_run_formatted)."\n";
		echo "</td>\n";
		echo "</tr>\n";
	}

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	if (substr($settings->get('theme', 'input_toggle_style', ''), 0, 6) == 'switch') {
		echo "	<label class='switch'>\n";
		echo "		<input type='checkbox' id='schedule_enabled' name='schedule_enabled' value='true' ".($schedule_enabled == 'true' ? "checked='checked'" : null).">\n";
		echo "		<span class='slider'></span>\n";
		echo "	</label>\n";
	}
	else {
		echo "	<select class='formfld' id='schedule_enabled' name='schedule_enabled'>\n";
		echo "		<option value='true' ".($schedule_enabled == 'true' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
		echo "		<option value='false' ".($schedule_enabled == 'false' ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
		echo "	</select>\n";
	}
	echo "<br />\n";
	echo $text['description-enabled']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-description']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' type='text' name='schedule_description' maxlength='255' value=\"".escape($schedule_description)."\">\n";
	echo "<br />\n";
	echo $text['description-description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "</div>\n";
	echo "<br /><br />\n";

	if ($action == 'update') {
		echo "<input type='hidden' name='id' value='".escape($schedule_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";

	echo "</form>\n";

//show only the fields that apply to the selected target and recurrence
	echo "<script>\n";
	echo "	function x_endpoint_manager_show_fields() {\n";
	echo "		var target = document.getElementById('target_type').value;\n";
	echo "		var recurrence = document.getElementById('recurrence').value;\n";
	echo "		document.getElementById('row_target_vendor').style.display = (target == 'vendor') ? '' : 'none';\n";
	echo "		document.getElementById('row_target_devices').style.display = (target == 'devices') ? '' : 'none';\n";
	echo "		document.getElementById('row_run_date').style.display = (recurrence == 'once') ? '' : 'none';\n";
	echo "		document.getElementById('row_run_days').style.display = (recurrence == 'weekly') ? '' : 'none';\n";
	echo "	}\n";
	echo "	x_endpoint_manager_show_fields();\n";
	echo "</script>\n";

//include the footer
	require_once "resources/footer.php";

?>
