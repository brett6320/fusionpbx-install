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
	$domain_name = $_SESSION['domain_name'];
	$post_action = $_POST['action'] ?? '';
	$is_ajax = ($post_action === 'record');

//an upload larger than post_max_size arrives with an empty post, answer the recorder with json
	if (($_GET['ajax'] ?? '') === 'record' && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
		http_response_code(413);
		header('Content-Type: application/json');
		echo json_encode(['ok' => false, 'message' => $text['message-recording_too_large']]);
		exit;
	}

//send a json response to the browser recorder and stop
	function x_ivr_manager_json($ok, $message = '', $code = 200) {
		http_response_code($code);
		header('Content-Type: application/json');
		header('Cache-Control: no-store');
		echo json_encode(['ok' => (bool)$ok, 'message' => (string)$message]);
		exit;
	}

//initialize the ivr manager
	$manager = new x_ivr_manager(['database' => $database, 'settings' => $settings, 'domain_uuid' => $domain_uuid, 'domain_name' => $domain_name]);

//get the ivr menu, only from this domain
	$ivr_menu_uuid = $_REQUEST['id'] ?? '';
	$ivr_menu = is_uuid($ivr_menu_uuid) ? $manager->get_ivr_menu($ivr_menu_uuid) : null;
	if (empty($ivr_menu)) {
		if ($is_ajax) {
			x_ivr_manager_json(false, $text['message-ivr_not_found'], 404);
		}
		message::add($text['message-ivr_not_found'], 'negative');
		header('Location: ivr_manager.php');
		exit;
	}
	$ivr_menu_uuid = $ivr_menu['ivr_menu_uuid'];
	$redirect = 'ivr_manager_edit.php?id='.urlencode($ivr_menu_uuid);

//set speech enabled and engine variables, mirrors app/ivr_menus/ivr_menu_edit.php:68-87
	$speech_enabled = class_exists('speech') && $settings->get('speech', 'enabled', false);
	$speech_engine = $settings->get('speech', 'engine', '');
	$tts_enabled = $speech_enabled && !empty($speech_engine) && permission_exists('x_ivr_manager_tts');

//process the http post data
	if (!empty($post_action)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				if ($is_ajax) {
					x_ivr_manager_json(false, $text['message-invalid_token'], 403);
				}
				message::add($text['message-invalid_token'], 'negative');
				header('Location: '.$redirect);
				exit;
			}

		//the greeting the change applies to
			$greeting_type = ($_POST['greeting_type'] ?? '') === 'greet_short' ? 'greet_short' : 'greet_long';

		//clean the recording name
			$recording_name = trim(str_replace(['"', "'", "`"], '', $_POST['recording_name'] ?? ''));
			$recording_name = substr($recording_name, 0, 255);

		switch ($post_action) {

			//save the option order from the drag and drop list
			case 'order':
				if (!permission_exists('x_ivr_manager_edit')) {
					echo "access denied";
					exit;
				}
				$option_uuids = explode(',', $_POST['option_order'] ?? '');
				if ($manager->save_option_order($ivr_menu, $option_uuids)) {
					message::add($text['message-order_saved']);
				}
				else {
					message::add($text['message-order_invalid'], 'negative');
				}
				header('Location: '.$redirect);
				exit;

			//browser recording upload (ajax)
			case 'record':
				if (!permission_exists('x_ivr_manager_record')) {
					x_ivr_manager_json(false, 'access denied', 403);
				}
				$file = $_FILES['audio'] ?? null;
				//user settings can raise this, so clamp it to a hard ceiling
				$max_size = min(max((int)$settings->get('x_ivr_manager', 'recording_max_size', 10485760), 1), 20971520);
				if (is_array($file) && isset($file['error']) && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
					x_ivr_manager_json(false, $text['message-recording_too_large'], 413);
				}
				if (empty($file) || !is_array($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
					x_ivr_manager_json(false, $text['message-recording_failed'], 400);
				}
				if ($file['size'] > $max_size || filesize($file['tmp_name']) > $max_size) {
					x_ivr_manager_json(false, $text['message-recording_too_large'], 413);
				}
				$result = $manager->record_greeting($ivr_menu, $greeting_type, $file['tmp_name'], $recording_name);
				if ($result === false) {
					if ($manager->message === 'unsupported') {
						x_ivr_manager_json(false, $text['message-recording_unsupported_format'], 415);
					}
					x_ivr_manager_json(false, $text['message-recording_failed'], 500);
				}
				message::add($text['message-recording_saved']);
				x_ivr_manager_json(true);

			//text to speech greeting
			case 'tts':
				if (!$tts_enabled) {
					echo "access denied";
					exit;
				}
				$tts['recording_name'] = $recording_name;
				$tts['recording_voice'] = $_POST['recording_voice'] ?? '';
				$tts['recording_speed'] = $_POST['recording_speed'] ?? '1.0';
				$tts['recording_message'] = trim($_POST['recording_message'] ?? '');
				if ($manager->tts_greeting($ivr_menu, $greeting_type, $tts) !== false) {
					message::add($text['message-tts_saved']);
				}
				else {
					message::add($text['message-tts_failed'], 'negative');
				}
				header('Location: '.$redirect);
				exit;

			//create or update the schedule
			case 'schedule_save':
				if (!permission_exists('x_ivr_manager_schedule')) {
					echo "access denied";
					exit;
				}
				$schedule_form['dialplan_name'] = $_POST['dialplan_name'] ?? '';
				$schedule_form['dialplan_number'] = $_POST['dialplan_number'] ?? '';
				$schedule_form['start_date'] = $_POST['start_date'] ?? '';
				$schedule_form['start_time'] = substr($_POST['start_time'] ?? '', 0, 5);
				$schedule_form['stop_date'] = $_POST['stop_date'] ?? '';
				$schedule_form['stop_time'] = substr($_POST['stop_time'] ?? '', 0, 5);
				$schedule_form['anti_action'] = $_POST['anti_action'] ?? '';
				$schedule_form['dialplan_enabled'] = ($_POST['dialplan_enabled'] ?? 'true') === 'false' ? 'false' : 'true';
				$schedule_form['confirm_replace'] = ($_POST['confirm_replace'] ?? '') === 'true' ? 'true' : '';

				$schedule_array = $schedule_form;
				$schedule_array['start'] = $schedule_form['start_date'].' '.$schedule_form['start_time'];
				$schedule_array['stop'] = $schedule_form['stop_date'].' '.$schedule_form['stop_time'];
				if ($manager->save_schedule($ivr_menu, $schedule_array) !== false) {
					message::add($text['message-schedule_saved']);
					header('Location: '.$redirect);
					exit;
				}
				//show the form again with the posted values
				$error_key = is_string($manager->message) ? $manager->message : '';
				message::add($text[$error_key] ?? $text['message-failed'], 'negative');
				unset($error_key);
				unset($schedule_array);
				break;

			//remove the schedule
			case 'schedule_delete':
				if (!permission_exists('x_ivr_manager_schedule')) {
					echo "access denied";
					exit;
				}
				if ($manager->delete_schedule($ivr_menu)) {
					message::add($text['message-schedule_deleted']);
				}
				header('Location: '.$redirect);
				exit;

			default:
				header('Location: '.$redirect);
				exit;
		}
	}

//get the options
	$ivr_menu_options = $manager->get_options($ivr_menu_uuid);

//get the speech voices, mirrors app/ivr_menus/ivr_menu_edit.php:73-87
	if ($tts_enabled) {
		$speech = new speech($settings);
		$voices = $speech->get_voices();
		$speed_enabled = $speech->is_speed_enabled();
		$speed_options = $speed_enabled ? $speech->get_speed_options() : [];
		if (!empty($voices) && array_type($voices) == 'multi') {
			ksort($voices);
		}
	}

//build the greeting playback links, playback goes through app/recordings/recordings.php
//which applies its own recording_play / recording_download permission and domain checks
	$can_play = permission_exists('recording_play') || permission_exists('recording_download');
	$recording_path = $manager->recording_path();
	$greetings = [];
	foreach (['greet_long', 'greet_short'] as $type) {
		$value = $ivr_menu['ivr_menu_'.$type] ?? '';
		$greetings[$type]['value'] = $value;
		$greetings[$type]['play_url'] = '';
		if (!$can_play || empty($value)) {
			continue;
		}
		//only bare file names or files directly inside the domain recordings directory
		if ($value === basename($value)) {
			$filename = $value;
		}
		else if (strpos($value, $recording_path.'/') === 0 && dirname($value) === $recording_path) {
			$filename = basename($value);
		}
		else {
			continue;
		}
		$sql = "select recording_uuid from v_recordings ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and recording_filename = :recording_filename ";
		$parameters['domain_uuid'] = $domain_uuid;
		$parameters['recording_filename'] = $filename;
		$recording_uuid = $database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		if (is_uuid($recording_uuid)) {
			$greetings[$type]['play_url'] = '../recordings/recordings.php?action=download&type=rec&id='.urlencode($recording_uuid);
		}
		else if (file_exists($recording_path.'/'.$filename)) {
			$greetings[$type]['play_url'] = '../recordings/recordings.php?action=download&type=rec&filename='.urlencode($filename);
		}
	}

//get the schedule
	$time_zone = $manager->time_zone();
	if (permission_exists('x_ivr_manager_schedule')) {
		$schedule = $manager->get_schedule($ivr_menu);
		if (!isset($schedule_form)) {
			$schedule_form['dialplan_name'] = $schedule['dialplan_name'] ?? $ivr_menu['ivr_menu_name'].' Schedule';
			$schedule_form['dialplan_number'] = $schedule['dialplan_number'] ?? '';
			$schedule_form['start_date'] = !empty($schedule['start']) ? substr($schedule['start'], 0, 10) : '';
			$schedule_form['start_time'] = !empty($schedule['start']) ? substr($schedule['start'], 11, 5) : '';
			$schedule_form['stop_date'] = !empty($schedule['stop']) ? substr($schedule['stop'], 0, 10) : '';
			$schedule_form['stop_time'] = !empty($schedule['stop']) ? substr($schedule['stop'], 11, 5) : '';
			$schedule_form['anti_action'] = $schedule['anti_action'] ?? '';
			$schedule_form['dialplan_enabled'] = $schedule['dialplan_enabled'] ?? 'true';
		}

		//schedule status in the domain time zone
		$schedule_status = '';
		if (!empty($schedule['start']) && !empty($schedule['stop'])) {
			$now = (new DateTime('now', new DateTimeZone($time_zone)))->format('Y-m-d H:i');
			if ($now < $schedule['start']) {
				$schedule_status = $text['label-schedule_pending'];
			}
			else if ($now >= $schedule['stop']) {
				$schedule_status = $text['label-schedule_expired'];
			}
			else {
				$schedule_status = $text['label-schedule_active'];
			}
		}

		$destination = new destinations;
	}

//recording limits for the browser recorder
	$recording_max_seconds = (int)$settings->get('x_ivr_manager', 'recording_max_seconds', 300);
	if ($recording_max_seconds < 1 || $recording_max_seconds > 3600) {
		$recording_max_seconds = 300;
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//include the header
	$document['title'] = $text['title-ivr_manager_edit'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-ivr_manager_edit']."</b>: ".escape($ivr_menu['ivr_menu_name'])." (".escape($ivr_menu['ivr_menu_extension']).")</div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','link'=>'ivr_manager.php']);
	if (permission_exists('ivr_menu_edit')) {
		echo button::create(['type'=>'button','label'=>$text['button-open_ivr_menu'],'icon'=>$settings->get('theme', 'button_icon_edit'),'id'=>'btn_ivr_menu','style'=>'margin-left: 15px;','link'=>'../ivr_menus/ivr_menu_edit.php?id='.urlencode($ivr_menu_uuid)]);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-ivr_manager']."\n";
	echo "<br /><br />\n";

//option order
	echo "<form name='frm_order' id='frm_order' method='post'>\n";
	echo "<div class='card'>\n";
	echo "<div class='action_bar sub'>\n";
	echo "	<div class='heading'><b>".$text['header-options']."</b></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('x_ivr_manager_edit') && !empty($ivr_menu_options)) {
		echo button::create(['type'=>'submit','label'=>$text['button-save_order'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save_order']);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";
	if (empty($ivr_menu_options)) {
		echo $text['label-options_none']."\n";
	}
	else {
		if (permission_exists('x_ivr_manager_edit')) {
			echo $text['description-options']."<br /><br />\n";
		}
		echo "<table class='list' id='ivr_options'>\n";
		echo "<thead>\n";
		echo "<tr class='list-header'>\n";
		if (permission_exists('x_ivr_manager_edit')) {
			echo "	<th class='shrink'>&nbsp;</th>\n";
		}
		echo "	<th class='shrink'>".$text['label-order']."</th>\n";
		echo "	<th class='shrink'>".$text['label-digits']."</th>\n";
		echo "	<th>".$text['label-destination']."</th>\n";
		echo "	<th class='hide-sm-dn'>".$text['label-description']."</th>\n";
		echo "	<th class='center shrink'>".$text['label-enabled']."</th>\n";
		if (permission_exists('x_ivr_manager_edit')) {
			echo "	<th class='shrink'>&nbsp;</th>\n";
		}
		echo "</tr>\n";
		echo "</thead>\n";
		echo "<tbody id='ivr_options_sortable'>\n";
		foreach ($ivr_menu_options as $row) {
			$option_enabled = ($row['ivr_menu_option_enabled'] === true || $row['ivr_menu_option_enabled'] === 'true' || $row['ivr_menu_option_enabled'] === 1 || $row['ivr_menu_option_enabled'] === '1') ? 'true' : 'false';
			echo "<tr class='list-row draggable' data-uuid='".escape($row['ivr_menu_option_uuid'])."'>\n";
			if (permission_exists('x_ivr_manager_edit')) {
				echo "	<td class='drag_handle' style='cursor: move;' title=\"".$text['label-order']."\"><span class='fa-solid fa-grip-vertical'></span></td>\n";
			}
			echo "	<td class='option_order'>".escape($row['ivr_menu_option_order'])."</td>\n";
			echo "	<td>".escape($row['ivr_menu_option_digits'])."</td>\n";
			echo "	<td>".escape(trim($row['ivr_menu_option_action'].' '.$row['ivr_menu_option_param']))."</td>\n";
			echo "	<td class='description overflow hide-sm-dn'>".escape($row['ivr_menu_option_description'])."&nbsp;</td>\n";
			echo "	<td class='center'>".$text['label-'.$option_enabled]."</td>\n";
			if (permission_exists('x_ivr_manager_edit')) {
				echo "	<td class='no-wrap'>";
				echo "<button type='button' class='btn btn-default btn-xs' onclick='option_move(this, -1);' aria-label='up'><span class='fa-solid fa-chevron-up'></span></button> ";
				echo "<button type='button' class='btn btn-default btn-xs' onclick='option_move(this, 1);' aria-label='down'><span class='fa-solid fa-chevron-down'></span></button>";
				echo "</td>\n";
			}
			echo "</tr>\n";
		}
		echo "</tbody>\n";
		echo "</table>\n";
	}
	echo "</div>\n";
	echo "<input type='hidden' name='action' value='order'>\n";
	echo "<input type='hidden' id='option_order' name='option_order' value=''>\n";
	echo "<input type='hidden' name='id' value='".escape($ivr_menu_uuid)."'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";
	echo "<br />\n";

//greetings
	echo "<div class='card'>\n";
	echo "<div class='action_bar sub'>\n";
	echo "	<div class='heading'><b>".$text['header-greetings']."</b></div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";
	echo $text['description-greetings']."<br /><br />\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	foreach (['greet_long', 'greet_short'] as $type) {
		echo "<tr>\n";
		echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-'.$type]."</td>\n";
		echo "<td width='70%' class='vtable' align='left'>\n";
		echo "	".escape(!empty($greetings[$type]['value']) ? $greetings[$type]['value'] : $text['label-not_set'])."\n";
		if (!empty($greetings[$type]['play_url'])) {
			echo "	<br /><audio controls preload='none' src='".escape($greetings[$type]['play_url'])."' style='margin-top: 5px; max-width: 100%;'></audio>\n";
		}
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";

	//target select, shared by the recorder and text to speech forms
	$greeting_type_select = function($id) use ($text) {
		$html = "<select class='formfld' name='greeting_type' id='".$id."'>\n";
		$html .= "	<option value='greet_long'>".$text['label-greet_long']."</option>\n";
		$html .= "	<option value='greet_short'>".$text['label-greet_short']."</option>\n";
		$html .= "</select>\n";
		return $html;
	};

	//browser recording
	if (permission_exists('x_ivr_manager_record')) {
		echo "<br />\n";
		echo "<b>".$text['header-record']."</b><br />\n";
		echo "<div id='recorder_unsupported' style='display: none;'>".$text['label-recording_unsupported']."</div>\n";
		echo "<div id='recorder'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-target']."</td>\n";
		echo "<td width='70%' class='vtable' align='left'>".$greeting_type_select('recorder_greeting_type')."</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-recording_name']."</td>\n";
		echo "<td class='vtable' align='left'><input class='formfld' type='text' id='recorder_recording_name' maxlength='255' value=''></td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-recording']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo button::create(['type'=>'button','label'=>$text['button-record'],'icon'=>'microphone','id'=>'btn_record','onclick'=>'recorder_start();']);
		echo button::create(['type'=>'button','label'=>$text['button-stop'],'icon'=>'stop','id'=>'btn_record_stop','style'=>'display: none;','onclick'=>'recorder_stop();']);
		echo button::create(['type'=>'button','label'=>$text['button-save_greeting'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_record_save','style'=>'display: none; margin-left: 15px;','onclick'=>'recorder_upload();']);
		echo "	<span id='recorder_status' style='margin-left: 10px;'></span>\n";
		echo "	<br /><audio id='recorder_preview' controls style='display: none; margin-top: 5px; max-width: 100%;'></audio>\n";
		echo "	<br />".$text['description-record']."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "</div>\n";
	}

	//text to speech, only when the speech app is installed and enabled
	if ($tts_enabled) {
		echo "<br />\n";
		echo "<form name='frm_tts' id='frm_tts' method='post'>\n";
		echo "<b>".$text['header-tts']."</b><br />\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-target']."</td>\n";
		echo "<td width='70%' class='vtable' align='left'>".$greeting_type_select('tts_greeting_type')."</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-recording_name']."</td>\n";
		echo "<td class='vtable' align='left'><input class='formfld' type='text' name='recording_name' maxlength='255' value=''></td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-voice']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		if (!empty($voices)) {
			echo "	<select class='formfld' name='recording_voice'>\n";
			echo "		<option value=''></option>\n";
			foreach ($voices as $key => $voice) {
				if (is_array($voice)) {
					echo "		<optgroup label=\"".escape($text['label-'.$key] ?? $key)."\">\n";
					foreach ($voice as $voice_key => $voice_name) {
						echo "			<option value=\"".escape($voice_key)."\">".escape(ucwords($voice_name))."</option>\n";
					}
					echo "		</optgroup>\n";
				}
				else {
					echo "		<option value=\"".escape($key)."\">".escape(ucwords($voice))."</option>\n";
				}
			}
			echo "	</select>\n";
		}
		else {
			echo "	<input class='formfld' type='text' name='recording_voice' maxlength='255' value=''>\n";
		}
		echo "</td>\n";
		echo "</tr>\n";
		if ($speed_enabled && !empty($speed_options)) {
			echo "<tr>\n";
			echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-speed']."</td>\n";
			echo "<td class='vtable' align='left'>\n";
			echo "	<select class='formfld' name='recording_speed'>\n";
			foreach ($speed_options as $speed_value => $speed_label) {
				$selected = (string)$speed_value === '1.0' ? "selected='selected'" : '';
				echo "		<option value='".escape($speed_value)."' ".$selected.">".escape($speed_label)."</option>\n";
			}
			echo "	</select>\n";
			echo "</td>\n";
			echo "</tr>\n";
		}
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-message']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<textarea class='formfld' name='recording_message' style='width: 100%; max-width: 450px; height: 120px;' required='required'></textarea>\n";
		echo "	<br />".$text['description-tts']."<br />\n";
		echo button::create(['type'=>'submit','label'=>$text['button-generate'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_tts','style'=>'margin-top: 5px;']);
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "<input type='hidden' name='action' value='tts'>\n";
		echo "<input type='hidden' name='id' value='".escape($ivr_menu_uuid)."'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";
	}
	echo "</div>\n";
	echo "<br />\n";

//schedule
	if (permission_exists('x_ivr_manager_schedule')) {
		echo "<form name='frm_schedule' id='frm_schedule' method='post'>\n";
		echo "<div class='card'>\n";
		echo "<div class='action_bar sub'>\n";
		echo "	<div class='heading'><b>".$text['header-schedule']."</b>".(!empty($schedule_status) ? " (".escape($schedule_status).")" : '')."</div>\n";
		echo "	<div class='actions'>\n";
		if (!empty($schedule) && permission_exists('time_condition_edit')) {
			echo button::create(['type'=>'button','label'=>$text['button-open_time_condition'],'icon'=>$settings->get('theme', 'button_icon_edit'),'id'=>'btn_time_condition','link'=>'../time_conditions/time_condition_edit.php?id='.urlencode($schedule['dialplan_uuid'])]);
		}
		if (!empty($schedule)) {
			echo button::create(['type'=>'button','label'=>$text['button-delete_schedule'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_schedule_delete','style'=>'margin-left: 15px;','onclick'=>'schedule_delete();']);
		}
		echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_schedule_save','style'=>'margin-left: 15px;']);
		echo "	</div>\n";
		echo "	<div style='clear: both;'></div>\n";
		echo "</div>\n";
		echo $text['description-schedule']."<br /><br />\n";
		if (!empty($schedule['complex'])) {
			echo "<div style='margin-bottom: 10px;'><b>".$text['message-schedule_complex']."</b><br />\n";
			echo "<label><input type='checkbox' name='confirm_replace' value='true'> ".$text['label-confirm_replace']."</label></div>\n";
		}

		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td width='30%' class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-schedule_name']."</td>\n";
		echo "<td width='70%' class='vtable' align='left'><input class='formfld' type='text' name='dialplan_name' maxlength='255' value=\"".escape($schedule_form['dialplan_name'])."\" required='required'></td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-schedule_number']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='dialplan_number' maxlength='20' pattern='[0-9]+' inputmode='numeric' value=\"".escape($schedule_form['dialplan_number'])."\" required='required'>\n";
		echo "	<br />".$text['description-schedule_number']."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-schedule_start']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='date' name='start_date' value=\"".escape($schedule_form['start_date'])."\" required='required'>\n";
		echo "	<input class='formfld' type='time' name='start_time' value=\"".escape($schedule_form['start_time'])."\" required='required'>\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-schedule_end']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='date' name='stop_date' value=\"".escape($schedule_form['stop_date'])."\" required='required'>\n";
		echo "	<input class='formfld' type='time' name='stop_time' value=\"".escape($schedule_form['stop_time'])."\" required='required'>\n";
		echo "	<br />".$text['description-schedule_time_zone']." (".escape($time_zone).")\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-fallback']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	".$destination->select('dialplan', 'anti_action', $schedule_form['anti_action'])."\n";
		echo "	<br />".$text['description-fallback']."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-enabled']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='dialplan_enabled'>\n";
		echo "		<option value='true' ".($schedule_form['dialplan_enabled'] !== 'false' ? "selected='selected'" : null).">".$text['option-true']."</option>\n";
		echo "		<option value='false' ".($schedule_form['dialplan_enabled'] === 'false' ? "selected='selected'" : null).">".$text['option-false']."</option>\n";
		echo "	</select>\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "</div>\n";
		echo "<input type='hidden' id='schedule_action' name='action' value='schedule_save'>\n";
		echo "<input type='hidden' name='id' value='".escape($ivr_menu_uuid)."'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";
		echo "<br />\n";

		//remove the schedule after a confirmation, form.submit() skips the required field validation
		echo "<script>\n";
		echo "	function schedule_delete() {\n";
		echo "		if (!confirm(".json_encode($text['confirm-delete_schedule'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).")) { return; }\n";
		echo "		document.getElementById('schedule_action').value = 'schedule_delete';\n";
		echo "		document.getElementById('frm_schedule').submit();\n";
		echo "	}\n";
		echo "</script>\n";
	}

//option order drag and drop, sortablejs ships with upstream (resources/sortablejs, used by app/phrases/phrase_edit.php)
	if (permission_exists('x_ivr_manager_edit') && !empty($ivr_menu_options)) {
		echo "<script src='/resources/sortablejs/sortable.min.js'></script>\n";
		echo "<script>\n";
		echo "	const option_list = document.getElementById('ivr_options_sortable');\n";
		echo "	const option_step = (option_list.querySelectorAll('.draggable').length * 10 <= 999) ? 10 : 1;\n";
		echo "	function update_option_order(renumber) {\n";
		echo "		const uuids = [];\n";
		echo "		let order = option_step;\n";
		echo "		option_list.querySelectorAll('.draggable').forEach(function(row) {\n";
		echo "			uuids.push(row.getAttribute('data-uuid'));\n";
		echo "			if (renumber !== false) { row.querySelector('.option_order').textContent = order; }\n";
		echo "			order += option_step;\n";
		echo "		});\n";
		echo "		document.getElementById('option_order').value = uuids.join(',');\n";
		echo "	}\n";
		echo "	function option_move(button, direction) {\n";
		echo "		const row = button.closest('tr');\n";
		echo "		if (direction < 0 && row.previousElementSibling) {\n";
		echo "			option_list.insertBefore(row, row.previousElementSibling);\n";
		echo "		}\n";
		echo "		else if (direction > 0 && row.nextElementSibling) {\n";
		echo "			option_list.insertBefore(row.nextElementSibling, row);\n";
		echo "		}\n";
		echo "		update_option_order();\n";
		echo "	}\n";
		echo "	if (typeof Sortable !== 'undefined') {\n";
		echo "		Sortable.create(option_list, {\n";
		echo "			animation: 150,\n";
		echo "			draggable: '.draggable',\n";
		echo "			handle: '.drag_handle',\n";
		echo "			onSort: update_option_order,\n";
		echo "		});\n";
		echo "	}\n";
		echo "	update_option_order(false);\n";
		echo "</script>\n";
	}

//browser recorder, MediaRecorder needs a secure context (https)
//chrome and firefox record webm/opus or ogg/opus, safari records mp4/aac, the server converts to wav
	if (permission_exists('x_ivr_manager_record')) {
		echo "<script>\n";
		echo "	const recorder_max_seconds = ".(int)$recording_max_seconds.";\n";
		echo "	const recorder_text = ".json_encode([
			'recording' => $text['label-recording_in_progress'],
			'ready' => $text['label-recording_ready'],
			'uploading' => $text['label-uploading'],
			'denied' => $text['label-microphone_denied'],
			'failed' => $text['message-recording_failed'],
		], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).";\n";
		echo "	const recorder_token_name = ".json_encode($token['name']).";\n";
		echo "	const recorder_token_hash = ".json_encode($token['hash']).";\n";
		echo "	const recorder_ivr_menu_uuid = ".json_encode($ivr_menu_uuid).";\n";
		echo "	let media_recorder = null;\n";
		echo "	let media_stream = null;\n";
		echo "	let recorder_chunks = [];\n";
		echo "	let recorder_blob = null;\n";
		echo "	let recorder_timer = null;\n";
		echo "	let recorder_seconds = 0;\n";
		echo "\n";
		echo "	function recorder_supported() {\n";
		echo "		return window.isSecureContext && !!navigator.mediaDevices && !!navigator.mediaDevices.getUserMedia && typeof window.MediaRecorder !== 'undefined';\n";
		echo "	}\n";
		echo "	if (!recorder_supported()) {\n";
		echo "		document.getElementById('recorder').style.display = 'none';\n";
		echo "		document.getElementById('recorder_unsupported').style.display = 'block';\n";
		echo "	}\n";
		echo "\n";
		echo "	function recorder_set_status(message) {\n";
		echo "		document.getElementById('recorder_status').textContent = message;\n";
		echo "	}\n";
		echo "\n";
		echo "	function recorder_message(message, mood) {\n";
		echo "		if (typeof display_message === 'function') { display_message(message, mood); }\n";
		echo "		else { alert(message); }\n";
		echo "	}\n";
		echo "\n";
		echo "	async function recorder_start() {\n";
		echo "		if (!recorder_supported()) { return; }\n";
		echo "		try {\n";
		echo "			media_stream = await navigator.mediaDevices.getUserMedia({ audio: true });\n";
		echo "		}\n";
		echo "		catch (e) {\n";
		echo "			recorder_message(recorder_text.denied, 'negative');\n";
		echo "			return;\n";
		echo "		}\n";
		echo "		const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];\n";
		echo "		const mime_type = types.find(function(t) { return MediaRecorder.isTypeSupported(t); });\n";
		echo "		media_recorder = mime_type ? new MediaRecorder(media_stream, { mimeType: mime_type }) : new MediaRecorder(media_stream);\n";
		echo "		recorder_chunks = [];\n";
		echo "		recorder_blob = null;\n";
		echo "		media_recorder.ondataavailable = function(event) { if (event.data && event.data.size > 0) { recorder_chunks.push(event.data); } };\n";
		echo "		media_recorder.onstop = function() {\n";
		echo "			recorder_blob = new Blob(recorder_chunks, { type: media_recorder.mimeType || 'application/octet-stream' });\n";
		echo "			const preview = document.getElementById('recorder_preview');\n";
		echo "			if (preview.src) { URL.revokeObjectURL(preview.src); }\n";
		echo "			preview.src = URL.createObjectURL(recorder_blob);\n";
		echo "			preview.style.display = 'block';\n";
		echo "			media_stream.getTracks().forEach(function(track) { track.stop(); });\n";
		echo "			document.getElementById('btn_record').style.display = '';\n";
		echo "			document.getElementById('btn_record_stop').style.display = 'none';\n";
		echo "			document.getElementById('btn_record_save').style.display = '';\n";
		echo "			recorder_set_status(recorder_text.ready);\n";
		echo "		};\n";
		echo "		media_recorder.start(1000);\n";
		echo "		recorder_seconds = 0;\n";
		echo "		recorder_set_status(recorder_text.recording + ' 0:00');\n";
		echo "		recorder_timer = setInterval(function() {\n";
		echo "			recorder_seconds++;\n";
		echo "			recorder_set_status(recorder_text.recording + ' ' + Math.floor(recorder_seconds / 60) + ':' + String(recorder_seconds % 60).padStart(2, '0'));\n";
		echo "			if (recorder_seconds >= recorder_max_seconds) { recorder_stop(); }\n";
		echo "		}, 1000);\n";
		echo "		document.getElementById('btn_record').style.display = 'none';\n";
		echo "		document.getElementById('btn_record_stop').style.display = '';\n";
		echo "		document.getElementById('btn_record_save').style.display = 'none';\n";
		echo "	}\n";
		echo "\n";
		echo "	function recorder_stop() {\n";
		echo "		if (recorder_timer) { clearInterval(recorder_timer); recorder_timer = null; }\n";
		echo "		if (media_recorder && media_recorder.state !== 'inactive') { media_recorder.stop(); }\n";
		echo "	}\n";
		echo "\n";
		echo "	function recorder_upload() {\n";
		echo "		if (!recorder_blob) { return; }\n";
		echo "		const form_data = new FormData();\n";
		echo "		form_data.append('action', 'record');\n";
		echo "		form_data.append('id', recorder_ivr_menu_uuid);\n";
		echo "		form_data.append('greeting_type', document.getElementById('recorder_greeting_type').value);\n";
		echo "		form_data.append('recording_name', document.getElementById('recorder_recording_name').value);\n";
		echo "		form_data.append(recorder_token_name, recorder_token_hash);\n";
		echo "		form_data.append('audio', recorder_blob, 'recording');\n";
		echo "		document.getElementById('btn_record_save').disabled = true;\n";
		echo "		recorder_set_status(recorder_text.uploading);\n";
		echo "		fetch(window.location.pathname + '?ajax=record&id=' + encodeURIComponent(recorder_ivr_menu_uuid), { method: 'POST', body: form_data, credentials: 'same-origin' })\n";
		echo "			.then(function(response) { return response.json(); })\n";
		echo "			.then(function(result) {\n";
		echo "				if (result.ok) {\n";
		echo "					window.location.href = 'ivr_manager_edit.php?id=' + encodeURIComponent(recorder_ivr_menu_uuid);\n";
		echo "					return;\n";
		echo "				}\n";
		echo "				document.getElementById('btn_record_save').disabled = false;\n";
		echo "				recorder_set_status('');\n";
		echo "				recorder_message(result.message || recorder_text.failed, 'negative');\n";
		echo "			})\n";
		echo "			.catch(function() {\n";
		echo "				document.getElementById('btn_record_save').disabled = false;\n";
		echo "				recorder_set_status('');\n";
		echo "				recorder_message(recorder_text.failed, 'negative');\n";
		echo "			});\n";
		echo "	}\n";
		echo "</script>\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
