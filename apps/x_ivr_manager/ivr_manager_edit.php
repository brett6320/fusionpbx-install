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

//a schedule rule as form fields, from a normalized rule
	function x_ivr_manager_rule_form(array $rule) {
		$row = $rule;
		if (($rule['type'] ?? '') === 'once') {
			$row['start_date'] = substr($rule['start'], 0, 10);
			$row['start_time'] = substr($rule['start'], 11, 5);
			$row['stop_date'] = substr($rule['stop'], 0, 10);
			$row['stop_time'] = substr($rule['stop'], 11, 5);
		}
		return $row;
	}

//a posted schedule rule as rule fields
	function x_ivr_manager_rule_post($row) {
		if (!is_array($row)) {
			return [];
		}
		$rule = [];
		foreach (['type', 'label', 'start_date', 'start_time', 'stop_date', 'stop_time', 'all_day', 'from', 'to', 'month', 'kind', 'mday', 'observed', 'nth', 'wday', 'offset'] as $field) {
			$rule[$field] = is_string($row[$field] ?? null) ? trim($row[$field]) : '';
		}
		$rule['days'] = array_values(array_filter(is_array($row['days'] ?? null) ? $row['days'] : [], 'is_string'));
		$rule['start'] = $rule['start_date'].' '.substr($rule['start_time'], 0, 5);
		$rule['stop'] = $rule['stop_date'].' '.substr($rule['stop_time'], 0, 5);
		return $rule;
	}

//the form fields of a schedule rule; $i is the rule's index in the post, __i__ in the template
	function x_ivr_manager_rule_html($i, array $row, array $text, $summary = '') {
		$name = function($field) use ($i) { return "rules[".$i."][".$field."]"; };
		$value = function($field) use ($row) { return escape((string)($row[$field] ?? '')); };
		$selected = function($field, $option) use ($row) { return (string)($row[$field] ?? '') === (string)$option ? " selected='selected'" : ''; };
		$checked = function($on) { return $on ? " checked='checked'" : ''; };
		$type = in_array($row['type'] ?? '', ['once', 'weekly', 'annual'], true) ? $row['type'] : 'once';
		$all_day = in_array($row['all_day'] ?? false, [true, 'true', '1', 'on'], true);
		$html = "<div class='schedule_rule' style='border: 1px solid rgba(128,128,128,0.35); border-radius: 4px; padding: 8px 10px; margin-bottom: 8px;'>\n";
		$html .= "	<select class='formfld rule_type' name='".$name('type')."' onchange='schedule_rule_update(this);'>\n";
		foreach (['once', 'weekly', 'annual'] as $option) {
			$html .= "		<option value='".$option."'".($type === $option ? " selected='selected'" : '').">".$text['option-rule_'.$option]."</option>\n";
		}
		$html .= "	</select>\n";
		$html .= "	<input class='formfld' type='text' name='".$name('label')."' maxlength='64' style='width: 16em;' placeholder=\"".escape($text['label-rule_label'])."\" value=\"".$value('label')."\">\n";
		$html .= "	<span class='rule_annual'>\n";
		$html .= "		<select class='formfld' onchange='schedule_rule_preset(this);'>\n";
		$html .= "			<option value=''>".$text['option-rule_preset']."</option>\n";
		foreach (array_keys(x_ivr_manager_schedule_rules::presets()) as $preset) {
			$html .= "			<option value=\"".escape($preset)."\">".escape($preset)."</option>\n";
		}
		$html .= "		</select>\n";
		$html .= "	</span>\n";
		$html .= "	".button::create(['type'=>'button','label'=>$text['button-remove_rule'],'icon'=>'xmark','onclick'=>'schedule_rule_remove(this);','style'=>'float: right;'])."\n";
		if ($summary !== '') {
			$html .= "	<span style='margin-left: 10px; opacity: 0.8;'>".escape($summary)."</span>\n";
		}

		//one time
		$html .= "	<div class='rule_once' style='margin-top: 6px;'>\n";
		$html .= "		".$text['label-schedule_start']." <input class='formfld' type='date' name='".$name('start_date')."' value=\"".$value('start_date')."\">\n";
		$html .= "		<input class='formfld' type='time' name='".$name('start_time')."' value=\"".$value('start_time')."\">\n";
		$html .= "		&nbsp; ".$text['label-schedule_end']." <input class='formfld' type='date' name='".$name('stop_date')."' value=\"".$value('stop_date')."\">\n";
		$html .= "		<input class='formfld' type='time' name='".$name('stop_time')."' value=\"".$value('stop_time')."\">\n";
		$html .= "	</div>\n";

		//weekly
		$days = array_map('strval', is_array($row['days'] ?? null) ? $row['days'] : []);
		$html .= "	<div class='rule_weekly' style='margin-top: 6px;'>\n";
		foreach ([2, 3, 4, 5, 6, 7, 1] as $day) {
			$html .= "		<label style='margin-right: 8px;'><input type='checkbox' name='".$name('days')."[]' value='".$day."'".$checked(in_array((string)$day, $days, true))."> ".$text['label-wday_'.$day]."</label>\n";
		}
		$html .= "	</div>\n";

		//annual
		$kind = ($row['kind'] ?? 'date') === 'nth' ? 'nth' : 'date';
		$html .= "	<div class='rule_annual' style='margin-top: 6px;'>\n";
		$html .= "		<select class='formfld rule_kind' name='".$name('kind')."' onchange='schedule_rule_update(this);'>\n";
		$html .= "			<option value='date'".($kind === 'date' ? " selected='selected'" : '').">".$text['option-rule_kind_date']."</option>\n";
		$html .= "			<option value='nth'".($kind === 'nth' ? " selected='selected'" : '').">".$text['option-rule_kind_nth']."</option>\n";
		$html .= "		</select>\n";
		$html .= "		<span class='rule_kind_nth'>\n";
		$html .= "			<select class='formfld' name='".$name('nth')."'>\n";
		foreach (['1', '2', '3', '4', 'last'] as $option) {
			$html .= "				<option value='".$option."'".$selected('nth', $option).">".$text['option-nth_'.$option]."</option>\n";
		}
		$html .= "			</select>\n";
		$html .= "			<select class='formfld' name='".$name('wday')."'>\n";
		foreach ([2, 3, 4, 5, 6, 7, 1] as $day) {
			$html .= "				<option value='".$day."'".$selected('wday', $day).">".$text['label-wday_'.$day]."</option>\n";
		}
		$html .= "			</select>\n";
		$html .= "			".$text['label-rule_of']."\n";
		$html .= "		</span>\n";
		$html .= "		<select class='formfld' name='".$name('month')."'>\n";
		for ($month = 1; $month <= 12; $month++) {
			$html .= "			<option value='".$month."'".$selected('month', $month).">".$text['label-month_'.$month]."</option>\n";
		}
		$html .= "		</select>\n";
		$html .= "		<span class='rule_kind_date'>\n";
		$html .= "			<input class='formfld' type='number' name='".$name('mday')."' min='1' max='31' style='width: 4.5em;' value=\"".$value('mday')."\">\n";
		$html .= "			<label style='margin-left: 8px;'><input type='checkbox' name='".$name('observed')."' value='true'".$checked(in_array($row['observed'] ?? false, [true, 'true', '1', 'on'], true))."> ".$text['label-rule_observed']."</label>\n";
		$html .= "		</span>\n";
		$html .= "		<span class='rule_kind_nth'>\n";
		$html .= "			<select class='formfld' name='".$name('offset')."'>\n";
		$offset = (string)($row['offset'] ?? '0');
		for ($days_offset = -6; $days_offset <= 6; $days_offset++) {
			$label = $days_offset == 0 ? $text['option-offset_0'] : sprintf($text[$days_offset > 0 ? 'option-offset_after' : 'option-offset_before'], abs($days_offset));
			$html .= "				<option value='".$days_offset."'".((int)$offset === $days_offset && preg_match('/^[+-]?[0-6]$/', $offset) ? " selected='selected'" : '').">".escape($label)."</option>\n";
		}
		$html .= "			</select>\n";
		$html .= "		</span>\n";
		$html .= "	</div>\n";

		//time window of weekly and annual rules
		$html .= "	<div class='rule_window' style='margin-top: 6px;'>\n";
		$html .= "		<label style='margin-right: 10px;'><input type='checkbox' class='rule_all_day' name='".$name('all_day')."' value='true'".$checked($all_day)." onchange='schedule_rule_update(this);'> ".$text['label-rule_all_day']."</label>\n";
		$html .= "		<span class='rule_hours'>".$text['label-rule_from']." <input class='formfld' type='text' name='".$name('from')."' maxlength='5' size='5' pattern='([01][0-9]|2[0-3]):[0-5][0-9]' placeholder='08:00' value=\"".$value('from')."\">\n";
		$html .= "		".$text['label-rule_to']." <input class='formfld' type='text' name='".$name('to')."' maxlength='5' size='5' pattern='([01][0-9]|2[0-3]):[0-5][0-9]|24:00' placeholder='17:00' value=\"".$value('to')."\"></span>\n";
		$html .= "	</div>\n";
		$html .= "</div>\n";
		return $html;
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

//phrases are built with Google Text-to-Speech, independent of the speech app
	$google_tts = new x_ivr_manager_google_tts($settings);
	$phrase_allowed = permission_exists('x_ivr_manager_phrase') && permission_exists('x_ivr_manager_edit') && x_ivr_manager::phrases_installed();
	$phrase_enabled = $phrase_allowed && $google_tts->enabled();

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

			//set a greeting to an existing recording or phrase
			case 'greeting_select':
				if (!permission_exists('x_ivr_manager_edit')) {
					echo "access denied";
					exit;
				}
				if ($manager->select_greeting($ivr_menu, $greeting_type, $_POST['greeting_value'] ?? '')) {
					message::add($text['message-greeting_saved']);
				}
				else {
					message::add((is_string($manager->message) ? ($text[$manager->message] ?? null) : null) ?? $text['message-failed'], 'negative');
				}
				header('Location: '.$redirect);
				exit;

			//take a greeting off the menu, the recording or phrase is kept
			case 'greeting_remove':
				if (!permission_exists('x_ivr_manager_edit')) {
					echo "access denied";
					exit;
				}
				if ($manager->remove_greeting($ivr_menu, $greeting_type)) {
					message::add($text['message-greeting_removed']);
				}
				else {
					message::add($text['message-failed'], 'negative');
				}
				header('Location: '.$redirect);
				exit;

			//delete a recording or phrase, refused while anything uses it
			case 'greeting_delete':
				$greeting_value = (string)($_POST['greeting_value'] ?? '');
				$delete_permission = strpos($greeting_value, 'phrase:') === 0 ? 'phrase_delete' : 'recording_delete';
				if (!permission_exists('x_ivr_manager_edit') || !permission_exists($delete_permission)) {
					echo "access denied";
					exit;
				}
				if ($manager->delete_greeting($greeting_value, permission_exists('recording_delete'))) {
					message::add($text['message-greeting_deleted']);
				}
				else if ($manager->message === 'message-greeting_in_use') {
					message::add(sprintf($text['message-greeting_in_use'], implode(', ', array_slice($manager->usage, 0, 10)).(count($manager->usage) > 10 ? ', …' : '')), 'negative');
				}
				else {
					message::add((is_string($manager->message) ? ($text[$manager->message] ?? null) : null) ?? $text['message-failed'], 'negative');
				}
				header('Location: '.$redirect);
				exit;

			//create a phrase with Google Text-to-Speech
			case 'phrase_create':
				if (!$phrase_enabled) {
					echo "access denied";
					exit;
				}
				$phrase['phrase_name'] = $_POST['phrase_name'] ?? '';
				$phrase['voice'] = $_POST['phrase_voice'] ?? '';
				$phrase['speed'] = $_POST['phrase_speed'] ?? '1.0';
				$phrase['greeting_type'] = $_POST['phrase_greeting_type'] ?? '';
				$phrase['parts'] = [];
				$part_types = $_POST['part_type'] ?? [];
				$part_values = $_POST['part_value'] ?? [];
				if (is_array($part_types) && is_array($part_values)) {
					foreach (array_slice($part_types, 0, 21, true) as $i => $part_type) {
						$phrase['parts'][] = ['type' => (string)$part_type, 'value' => (string)($part_values[$i] ?? '')];
					}
				}
				//synthesis can take a while: release the session lock so other tabs keep working, then reopen it
				//for the message. The access token is not cached in this request.
				@set_time_limit(120);
				session_write_close();
				$created = $manager->create_phrase($ivr_menu, $phrase);
				session_start();
				if ($created !== false) {
					message::add($text['message-phrase_saved']);
				}
				else {
					message::add((is_string($manager->message) ? ($text[$manager->message] ?? null) : null) ?? $text['message-phrase_save_failed'], 'negative');
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
				$schedule_form['rules'] = [];
				foreach (is_array($_POST['rules'] ?? null) ? $_POST['rules'] : [] as $row) {
					$schedule_form['rules'][] = x_ivr_manager_rule_post($row);
				}
				$schedule_form['anti_action'] = $_POST['anti_action'] ?? '';
				$schedule_form['dialplan_enabled'] = ($_POST['dialplan_enabled'] ?? 'true') === 'false' ? 'false' : 'true';
				$schedule_form['confirm_replace'] = ($_POST['confirm_replace'] ?? '') === 'true' ? 'true' : '';

				$schedule_array = $schedule_form;
				if ($manager->save_schedule($ivr_menu, $schedule_array) !== false) {
					message::add($text['message-schedule_saved']);
					header('Location: '.$redirect);
					exit;
				}
				//show the form again with the posted values
				$error_key = is_string($manager->message) ? $manager->message : '';
				if ($error_key === 'message-schedule_number_in_use' || $error_key === 'message-schedule_number_routable') {
					message::add(sprintf($text[$error_key], $schedule_form['dialplan_number'], $manager->number_used_by), 'negative');
				}
				else if (!empty($manager->rule_number) && isset($text[$error_key])) {
					message::add(sprintf($text['message-schedule_rule_error'], $manager->rule_number, $text[$error_key]), 'negative');
				}
				else {
					message::add($text[$error_key] ?? $text['message-failed'], 'negative');
				}
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

//playback and text of the current greetings and the picker; playback goes through app/recordings/recordings.php,
//which applies its own recording_play / recording_download permission and domain checks
	$can_play = permission_exists('recording_play') || permission_exists('recording_download');
	$recording_path = $manager->recording_path();
	$previews = $manager->greeting_previews($can_play);
	$greetings = [];
	foreach (['greet_long', 'greet_short'] as $type) {
		$value = $ivr_menu['ivr_menu_'.$type] ?? '';
		$greetings[$type]['value'] = $value;
		$greetings[$type]['play_url'] = '';
		$greetings[$type]['preview'] = null;
		if (empty($value)) {
			continue;
		}
		//phrases, bare file names, or files directly inside the domain recordings directory
		if (strpos($value, 'phrase:') === 0) {
			$greetings[$type]['preview'] = $previews[$value] ?? null;
			continue;
		}
		if ($value === basename($value)) {
			$filename = $value;
		}
		else if (strpos($value, $recording_path.'/') === 0 && dirname($value) === $recording_path) {
			$filename = basename($value);
		}
		else {
			continue;
		}
		$preview = $previews[$filename] ?? null;
		if ($preview === null && $can_play && file_exists($recording_path.'/'.$filename)) {
			$preview = ['steps' => [['url' => '../recordings/recordings.php?action=download&type=rec&filename='.urlencode($filename)]], 'text' => '', 'complete' => true];
		}
		$greetings[$type]['preview'] = $preview;
		$greetings[$type]['play_url'] = $preview['steps'][0]['url'] ?? '';
	}

//recordings and phrases for the greeting picker, and phrase names for the current greetings
	$domain_recordings = permission_exists('x_ivr_manager_edit') ? $manager->get_recordings() : [];
	$domain_phrases = $manager->get_phrases();
	$phrase_names = [];
	foreach ($domain_phrases as $row) {
		$phrase_names[$row['phrase_uuid']] = $row['phrase_name'];
	}
	foreach (['greet_long', 'greet_short'] as $type) {
		$value = $greetings[$type]['value'];
		$greetings[$type]['label'] = $value;
		if (strpos($value, 'phrase:') === 0 && isset($phrase_names[substr($value, 7)])) {
			$greetings[$type]['label'] = $text['label-phrase'].': '.$phrase_names[substr($value, 7)];
		}
	}

//a preview as a data attribute for the player and the text box
	$preview_attribute = function($preview) {
		if (empty($preview)) {
			return '';
		}
		return " data-preview=\"".escape(json_encode(['steps' => $preview['steps'], 'text' => $preview['text'], 'complete' => $preview['complete']], JSON_INVALID_UTF8_SUBSTITUTE))."\"";
	};

//google voices for new phrases, the configured default first
	if ($phrase_enabled) {
		$phrase_voices = $google_tts->voices();
		$phrase_voice_default = $google_tts->default_voice();
		if (x_ivr_manager_google_tts::valid_voice($phrase_voice_default) && !isset($phrase_voices[$phrase_voice_default])) {
			$phrase_voices = [$phrase_voice_default => $phrase_voice_default] + $phrase_voices;
		}
	}

//get the schedule
	$time_zone = $manager->time_zone();
	if (permission_exists('x_ivr_manager_schedule')) {
		$schedule = $manager->get_schedule($ivr_menu);
		if ($schedule === false) {
			message::add($text['message-schedule_lookup_failed'], 'negative');
		}
		if (!isset($schedule_form)) {
			$schedule_form['dialplan_name'] = $schedule['dialplan_name'] ?? $ivr_menu['ivr_menu_name'].' Schedule';
			$schedule_form['dialplan_number'] = $schedule['dialplan_number'] ?? $manager->suggest_schedule_number();
			$schedule_form['rules'] = array_map('x_ivr_manager_rule_form', $schedule['rules'] ?? []);
			if (empty($schedule_form['rules']) && empty($schedule)) {
				$schedule_form['rules'][] = ['type' => 'once'];
			}
			$schedule_form['anti_action'] = $schedule['anti_action'] ?? '';
			$schedule_form['dialplan_enabled'] = $schedule['dialplan_enabled'] ?? 'true';
		}

		//schedule status in the domain time zone, and per saved rule when it applies next
		$schedule_status = '';
		$rule_summaries = [];
		if (!empty($schedule['rules'])) {
			//in the time zone the time condition was saved with, as the switch evaluates it
			$now = new DateTime('now', new DateTimeZone($schedule['dialplan_time_zone']));
			$now_text = $now->format('Y-m-d H:i');
			$ended = true;
			foreach ($schedule['rules'] as $i => $rule) {
				if ($rule['type'] === 'once') {
					$rule_summaries[$i] = $now_text < $rule['start'] ? $text['label-schedule_pending'] : ($now_text >= $rule['stop'] ? $text['label-schedule_expired'] : $text['label-schedule_active']);
					$ended = $ended && $now_text >= $rule['stop'];
				}
				else {
					$ended = false;
					if ($rule['type'] === 'annual') {
						$next = x_ivr_manager_schedule_rules::next_date($rule, $now);
						if ($next !== null) {
							$rule_summaries[$i] = sprintf($text['label-rule_next'], DateTime::createFromFormat('!Y-m-d', $next)->format('D, M j, Y'));
						}
					}
				}
			}
			if (($schedule['dialplan_enabled'] ?? 'true') === 'false') {
				$schedule_status = $text['label-schedule_disabled'];
			}
			else if (x_ivr_manager_schedule_rules::active($schedule['rules'], $now)) {
				$schedule_status = $text['label-schedule_active'];
			}
			else {
				$schedule_status = $ended ? $text['label-schedule_expired'] : $text['label-schedule_inactive'];
			}
		}

		$schedule_routes = !empty($schedule) ? $manager->schedule_inbound_routes($schedule) : [];
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
		echo "	".escape(!empty($greetings[$type]['label']) ? $greetings[$type]['label'] : $text['label-not_set'])."\n";
		$preview = $greetings[$type]['preview'];
		if (!empty($greetings[$type]['play_url'])) {
			echo "	<br /><audio controls preload='none' src='".escape($greetings[$type]['play_url'])."' style='margin-top: 5px; max-width: 100%;'></audio>\n";
		}
		else if (!empty($preview['steps'])) {
			echo "	<div class='greeting_preview'".$preview_attribute($preview)." style='margin-top: 5px;'>\n";
			echo button::create(['type'=>'button','label'=>$text['button-play'],'icon'=>'play','onclick'=>'greeting_play(this);']);
			echo "	</div>\n";
		}
		if (isset($preview['text']) && $preview['text'] !== '') {
			echo "	<div class='greeting_text' style='margin-top: 5px;'>\n";
			echo "		<textarea class='formfld' readonly='readonly' rows='".min(max(substr_count($preview['text'], "\n") + 1, 2), 8)."' style='width: 100%; max-width: 500px;'>".escape($preview['text'])."</textarea><br />\n";
			echo button::create(['type'=>'button','label'=>$text['button-copy_text'],'icon'=>'copy','onclick'=>'greeting_copy(this);']);
			echo "	</div>\n";
		}
		if (!empty($preview) && empty($preview['complete'])) {
			echo "	<br />".$text['description-preview_incomplete']."\n";
		}
		if (!empty($greetings[$type]['value']) && permission_exists('x_ivr_manager_edit')) {
			echo "	<div style='margin-top: 5px;'>".button::create(['type'=>'button','label'=>$text['button-remove_greeting'],'icon'=>'xmark','onclick'=>"greeting_remove_confirm('".$type."', ".escape(json_encode($greetings[$type]['label'], JSON_INVALID_UTF8_SUBSTITUTE)).");"])."</div>\n";
		}
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	if (permission_exists('x_ivr_manager_edit')) {
		echo "<form name='frm_greeting_remove' id='frm_greeting_remove' method='post'>\n";
		echo "<input type='hidden' name='action' value='greeting_remove'>\n";
		echo "<input type='hidden' name='greeting_type' id='greeting_remove_type' value=''>\n";
		echo "<input type='hidden' name='id' value='".escape($ivr_menu_uuid)."'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";
		echo modal::create(['id'=>'modal-greeting-remove','type'=>'general','message'=>"<span id='greeting_remove_message'></span>",'actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); document.getElementById('frm_greeting_remove').submit();"])]);
	}

	//target select, shared by the recorder and text to speech forms
	$greeting_type_select = function($id) use ($text) {
		$html = "<select class='formfld' name='greeting_type' id='".$id."'>\n";
		$html .= "	<option value='greet_long'>".$text['label-greet_long']."</option>\n";
		$html .= "	<option value='greet_short'>".$text['label-greet_short']."</option>\n";
		$html .= "</select>\n";
		return $html;
	};

	//choose an existing recording or phrase
	if (permission_exists('x_ivr_manager_edit') && (!empty($domain_recordings) || !empty($domain_phrases))) {
		echo "<br />\n";
		echo "<form name='frm_greeting' id='frm_greeting' method='post'>\n";
		echo "<b>".$text['header-select_greeting']."</b><br />\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-target']."</td>\n";
		echo "<td width='70%' class='vtable' align='left'>".$greeting_type_select('select_greeting_type')."</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-greeting']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='greeting_value' id='greeting_value' required='required' onchange='greeting_select_changed();'>\n";
		echo "		<option value=''></option>\n";
		if (!empty($domain_recordings)) {
			echo "		<optgroup label=\"".escape($text['label-recordings'])."\">\n";
			foreach ($domain_recordings as $row) {
				echo "			<option value=\"".escape($row['recording_filename'])."\"".$preview_attribute($previews[$row['recording_filename']] ?? null).(permission_exists('recording_delete') ? " data-deletable='true'" : '').">".escape($row['recording_name'])."</option>\n";
			}
			echo "		</optgroup>\n";
		}
		if (!empty($domain_phrases)) {
			echo "		<optgroup label=\"".escape($text['label-phrases'])."\">\n";
			foreach ($domain_phrases as $row) {
				$phrase_disabled = !($row['phrase_enabled'] === true || $row['phrase_enabled'] === 'true' || $row['phrase_enabled'] === 1 || $row['phrase_enabled'] === '1');
				echo "			<option value=\"phrase:".escape($row['phrase_uuid'])."\"".$preview_attribute($previews['phrase:'.$row['phrase_uuid']] ?? null).(permission_exists('phrase_delete') ? " data-deletable='true'" : '').">".escape($row['phrase_name'].' ('.$row['phrase_language'].')'.($phrase_disabled ? ' - '.$text['label-disabled'] : ''))."</option>\n";
			}
			echo "		</optgroup>\n";
		}
		echo "	</select>\n";
		echo "	<div id='greeting_select_preview' class='greeting_preview' style='display: none; margin-top: 5px;'>\n";
		echo button::create(['type'=>'button','label'=>$text['button-play'],'icon'=>'play','onclick'=>'greeting_play(this);']);
		echo "		<span class='greeting_incomplete' style='display: none; margin-left: 10px;'>".$text['description-preview_incomplete']."</span>\n";
		echo "	</div>\n";
		echo "	<div id='greeting_select_text' class='greeting_text' style='display: none; margin-top: 5px;'>\n";
		echo "		<textarea class='formfld' readonly='readonly' rows='4' style='width: 100%; max-width: 500px;'></textarea><br />\n";
		echo button::create(['type'=>'button','label'=>$text['button-copy_text'],'icon'=>'copy','onclick'=>'greeting_copy(this);']);
		echo "	</div>\n";
		echo "	<br />".$text['description-select_greeting']."<br />\n";
		echo button::create(['type'=>'submit','label'=>$text['button-set_greeting'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_greeting_select','style'=>'margin-top: 5px;']);
		echo button::create(['type'=>'button','label'=>$text['button-delete_greeting'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_greeting_delete','style'=>'display: none; margin-top: 5px; margin-left: 15px;','onclick'=>'greeting_delete_confirm();']);
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "<input type='hidden' name='action' value='greeting_select'>\n";
		echo "<input type='hidden' name='id' value='".escape($ivr_menu_uuid)."'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";
		echo "<form name='frm_greeting_delete' id='frm_greeting_delete' method='post'>\n";
		echo "<input type='hidden' name='action' value='greeting_delete'>\n";
		echo "<input type='hidden' name='greeting_value' id='greeting_delete_value' value=''>\n";
		echo "<input type='hidden' name='id' value='".escape($ivr_menu_uuid)."'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";
		echo modal::create(['id'=>'modal-greeting-delete','type'=>'general','message'=>"<span id='greeting_delete_message'></span>",'actions'=>button::create(['type'=>'button','label'=>$text['button-continue'],'icon'=>'check','style'=>'float: right; margin-left: 15px;','collapse'=>'never','onclick'=>"modal_close(); greeting_delete_submit();"])]);
	}

	//new phrase with google text to speech
	if ($phrase_allowed && !$phrase_enabled) {
		echo "<br />\n";
		echo "<b>".$text['header-new_phrase']."</b><br />\n";
		echo $text['description-phrase_tts_setup']."<br />\n";
	}
	if ($phrase_enabled) {
		echo "<br />\n";
		echo "<form name='frm_phrase' id='frm_phrase' method='post'>\n";
		echo "<b>".$text['header-new_phrase']."</b><br />\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td width='30%' class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-target']."</td>\n";
		echo "<td width='70%' class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='phrase_greeting_type'>\n";
		echo "		<option value='greet_long'>".$text['label-greet_long']."</option>\n";
		echo "		<option value='greet_short'>".$text['label-greet_short']."</option>\n";
		echo "		<option value=''>".$text['label-target_none']."</option>\n";
		echo "	</select>\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-phrase_name']."</td>\n";
		echo "<td class='vtable' align='left'><input class='formfld' type='text' name='phrase_name' maxlength='255' value=\"".escape($manager->phrase_default_name($ivr_menu))."\" required='required'></td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-voice']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		if (!empty($phrase_voices)) {
			echo "	<select class='formfld' name='phrase_voice' required='required'>\n";
			foreach ($phrase_voices as $voice_name => $voice_label) {
				echo "		<option value=\"".escape($voice_name)."\" ".($voice_name === $phrase_voice_default ? "selected='selected'" : '').">".escape($voice_label)."</option>\n";
			}
			echo "	</select>\n";
		}
		else {
			echo "	<input class='formfld' type='text' name='phrase_voice' maxlength='64' value=\"".escape($phrase_voice_default)."\" required='required'>\n";
		}
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-speed']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='phrase_speed'>\n";
		foreach (['0.75', '0.85', '0.9', '1.0', '1.1', '1.25'] as $speed_value) {
			echo "		<option value='".$speed_value."' ".($speed_value === '1.0' ? "selected='selected'" : '').">".$speed_value."x</option>\n";
		}
		echo "	</select>\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-parts']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<div id='phrase_parts'></div>\n";
		echo button::create(['type'=>'button','label'=>$text['button-add_text'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add_text','onclick'=>"phrase_part_add('text');"]);
		echo button::create(['type'=>'button','label'=>$text['button-add_pause'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add_pause','style'=>'margin-left: 5px;','onclick'=>"phrase_part_add('pause');"]);
		echo "	<br />".$text['description-new_phrase']."<br />\n";
		echo button::create(['type'=>'submit','label'=>$text['button-create_phrase'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_phrase_create','style'=>'margin-top: 5px;']);
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "<input type='hidden' name='action' value='phrase_create'>\n";
		echo "<input type='hidden' name='id' value='".escape($ivr_menu_uuid)."'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "</form>\n";
		//parts are built with the dom, so text never passes through html
		$phrase_text = [
			'text' => $text['label-part_text'],
			'pause' => $text['label-part_pause'],
			'remove' => $text['button-remove_part'],
		];
		echo "<script>\n";
		echo "	var phrase_text = ".json_encode($phrase_text, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).";\n";
		echo "	function phrase_part_add(type) {\n";
		echo "		var parts = document.getElementById('phrase_parts');\n";
		echo "		if (parts.children.length >= 20) { return; }\n";
		echo "		var row = document.createElement('div');\n";
		echo "		row.style.marginBottom = '6px';\n";
		echo "		var hidden = document.createElement('input');\n";
		echo "		hidden.type = 'hidden'; hidden.name = 'part_type[]'; hidden.value = type;\n";
		echo "		row.appendChild(hidden);\n";
		echo "		var label = document.createElement('div');\n";
		echo "		label.textContent = phrase_text[type];\n";
		echo "		row.appendChild(label);\n";
		echo "		var input;\n";
		echo "		if (type === 'text') {\n";
		echo "			input = document.createElement('textarea');\n";
		echo "			input.className = 'formfld'; input.required = true;\n";
		echo "			input.style.width = '100%'; input.style.maxWidth = '450px'; input.style.height = '70px';\n";
		echo "		}\n";
		echo "		else {\n";
		echo "			input = document.createElement('input');\n";
		echo "			input.type = 'number'; input.className = 'formfld'; input.min = '0.1'; input.max = '10'; input.step = '0.1'; input.value = '1'; input.required = true;\n";
		echo "			input.style.width = '90px';\n";
		echo "		}\n";
		echo "		input.name = 'part_value[]';\n";
		echo "		row.appendChild(input);\n";
		echo "		var remove = document.createElement('button');\n";
		echo "		remove.type = 'button'; remove.className = 'btn btn-default btn-xs'; remove.style.marginLeft = '5px'; remove.style.verticalAlign = 'top';\n";
		echo "		remove.textContent = phrase_text.remove;\n";
		echo "		remove.onclick = function() { row.remove(); };\n";
		echo "		row.appendChild(remove);\n";
		echo "		parts.appendChild(row);\n";
		echo "	}\n";
		echo "	phrase_part_add('text');\n";
		echo "</script>\n";
	}

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
		echo "<td class='vtable' align='left'><input class='formfld' type='text' id='recorder_recording_name' maxlength='255' value='' placeholder=\"".escape($text['placeholder-greeting_name'])."\"></td>\n";
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
		echo "<td class='vtable' align='left'><input class='formfld' type='text' name='recording_name' maxlength='255' value='' placeholder=\"".escape($text['placeholder-greeting_name'])."\"></td>\n";
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
		echo "	<input class='formfld' type='text' name='dialplan_number' maxlength='32' pattern='[0-9]+' inputmode='numeric' style='width: 22em;' value=\"".escape($schedule_form['dialplan_number'])."\" required='required'>\n";
		echo "	<br />".$text['description-schedule_number']."\n";
		if (!empty($schedule)) {
			echo "	<br /><br /><b>".$text['label-schedule_routes']."</b> ";
			if (!empty($schedule_routes)) {
				$route_labels = [];
				foreach ($schedule_routes as $route) {
					$route_labels[] = escape($route['destination_number'].(!empty($route['destination_description']) ? ' ('.$route['destination_description'].')' : ''));
				}
				echo implode(', ', $route_labels)."\n";
			}
			else {
				echo $text['label-schedule_routes_none']."\n";
			}
		}
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-schedule_rules']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<div id='schedule_rules'>\n";
		$rule_index = 0;
		foreach ($schedule_form['rules'] as $i => $row) {
			//the summary belongs to the saved rule, not to posted values shown again
			echo x_ivr_manager_rule_html($rule_index++, $row, $text, isset($_POST['rules']) ? '' : ($rule_summaries[$i] ?? ''));
		}
		echo "	</div>\n";
		echo "	<template id='schedule_rule_template'>".x_ivr_manager_rule_html('__i__', ['type' => 'weekly', 'all_day' => false], $text)."</template>\n";
		echo "	".button::create(['type'=>'button','label'=>$text['button-add_rule'],'icon'=>$settings->get('theme', 'button_icon_add'),'onclick'=>'schedule_rule_add();'])."\n";
		//the schedule's own time zone; it differs from the domain's after the domain time zone changed
		$schedule_time_zone = !empty($schedule['dialplan_time_zone']) ? $schedule['dialplan_time_zone'] : $time_zone;
		echo "	<br />".sprintf($text['description-schedule_rules'], escape($schedule_time_zone))."\n";
		if ($schedule_time_zone !== $time_zone) {
			echo "	<br /><b>".sprintf($text['message-schedule_time_zone_changed'], escape($schedule_time_zone), escape($time_zone))."</b>\n";
		}
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
		echo "	const schedule_rule_presets = ".json_encode(x_ivr_manager_schedule_rules::presets(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).";\n";
		echo "	let schedule_rule_next = ".(int)$rule_index.";\n";
		echo "	function schedule_rule_update(element) {\n";
		echo "		const box = element.closest('.schedule_rule');\n";
		echo "		const type = box.querySelector('.rule_type').value;\n";
		echo "		const kind = box.querySelector('.rule_kind').value;\n";
		echo "		const all_day = box.querySelector('.rule_all_day').checked;\n";
		echo "		box.querySelectorAll('.rule_once').forEach(function(e) { e.style.display = type === 'once' ? '' : 'none'; });\n";
		echo "		box.querySelectorAll('.rule_weekly').forEach(function(e) { e.style.display = type === 'weekly' ? '' : 'none'; });\n";
		echo "		box.querySelectorAll('.rule_annual').forEach(function(e) { e.style.display = type === 'annual' ? '' : 'none'; });\n";
		echo "		box.querySelectorAll('.rule_kind_date').forEach(function(e) { e.style.display = kind === 'date' ? '' : 'none'; });\n";
		echo "		box.querySelectorAll('.rule_kind_nth').forEach(function(e) { e.style.display = kind === 'nth' ? '' : 'none'; });\n";
		echo "		box.querySelectorAll('.rule_window').forEach(function(e) { e.style.display = type === 'once' ? 'none' : ''; });\n";
		echo "		box.querySelectorAll('.rule_hours').forEach(function(e) { e.style.display = all_day ? 'none' : ''; });\n";
		//hidden fields are disabled: the browser would refuse to save over a hidden invalid field
		echo "		box.querySelectorAll('input, select').forEach(function(e) {\n";
		echo "			let hidden = false;\n";
		echo "			for (let p = e.parentElement; p && p !== box; p = p.parentElement) { if (p.style.display === 'none') { hidden = true; } }\n";
		echo "			e.disabled = hidden;\n";
		echo "		});\n";
		echo "	}\n";
		echo "	function schedule_rule_add() {\n";
		echo "		const template = document.getElementById('schedule_rule_template');\n";
		echo "		const holder = document.createElement('div');\n";
		echo "		holder.innerHTML = template.innerHTML.split('__i__').join(String(schedule_rule_next++));\n";
		echo "		const box = holder.firstElementChild;\n";
		echo "		document.getElementById('schedule_rules').appendChild(box);\n";
		echo "		schedule_rule_update(box.querySelector('.rule_type'));\n";
		echo "	}\n";
		echo "	function schedule_rule_remove(button) {\n";
		echo "		button.closest('.schedule_rule').remove();\n";
		echo "	}\n";
		echo "	function schedule_rule_preset(select) {\n";
		echo "		const preset = schedule_rule_presets[select.value];\n";
		echo "		if (!preset) { return; }\n";
		echo "		const box = select.closest('.schedule_rule');\n";
		echo "		const field = function(name) { return box.querySelector('[name$=\"[' + name + ']\"]'); };\n";
		echo "		if (field('label').value === '') { field('label').value = select.value; }\n";
		echo "		field('month').value = String(preset.month);\n";
		echo "		field('kind').value = preset.kind;\n";
		echo "		if (preset.kind === 'date') {\n";
		echo "			field('mday').value = String(preset.mday);\n";
		echo "			field('observed').checked = !!preset.observed;\n";
		echo "		}\n";
		echo "		else {\n";
		echo "			field('nth').value = preset.nth;\n";
		echo "			field('wday').value = String(preset.wday);\n";
		echo "			field('offset').value = String(preset.offset);\n";
		echo "		}\n";
		echo "		field('all_day').checked = true;\n";
		echo "		select.value = '';\n";
		echo "		schedule_rule_update(select);\n";
		echo "	}\n";
		echo "	document.querySelectorAll('#schedule_rules .rule_type').forEach(function(e) { schedule_rule_update(e); });\n";
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

//greeting playback (a phrase plays its recordings in order with its pauses) and copying the greeting text
	echo "<script>\n";
	echo "	var greeting_player = {audio: null, timer: null, button: null};\n";
	echo "	function greeting_button_label(button, label) {\n";
	echo "		var span = button.querySelector('.button-label');\n";
	echo "		(span || button).textContent = label;\n";
	echo "	}\n";
	echo "	function greeting_stop() {\n";
	echo "		if (greeting_player.audio) { greeting_player.audio.pause(); greeting_player.audio.removeAttribute('src'); greeting_player.audio = null; }\n";
	echo "		if (greeting_player.timer) { clearTimeout(greeting_player.timer); greeting_player.timer = null; }\n";
	echo "		if (greeting_player.button) { greeting_button_label(greeting_player.button, ".json_encode($text['button-play'])."); greeting_player.button = null; }\n";
	echo "	}\n";
	echo "	function greeting_play(button) {\n";
	echo "		var was_playing = greeting_player.button === button;\n";
	echo "		greeting_stop();\n";
	echo "		if (was_playing) { return; }\n";
	echo "		var holder = button.closest('.greeting_preview');\n";
	echo "		var preview = holder && holder.dataset.preview ? JSON.parse(holder.dataset.preview) : null;\n";
	echo "		if (!preview || !preview.steps.length) { return; }\n";
	echo "		greeting_player.button = button;\n";
	echo "		greeting_button_label(button, ".json_encode($text['button-stop'])."); \n";
	echo "		var index = 0;\n";
	echo "		var next = function() {\n";
	echo "			if (greeting_player.button !== button) { return; }\n";
	echo "			if (index >= preview.steps.length) { greeting_stop(); return; }\n";
	echo "			var step = preview.steps[index++];\n";
	echo "			if (step.pause !== undefined) { greeting_player.timer = setTimeout(next, step.pause); return; }\n";
	echo "			var audio = new Audio(step.url);\n";
	echo "			var advanced = false;\n";
	echo "			var advance = function() { if (advanced || greeting_player.audio !== audio) { return; } advanced = true; next(); };\n";
	echo "			greeting_player.audio = audio;\n";
	echo "			audio.onended = advance;\n";
	echo "			audio.onerror = advance;\n";
	echo "			audio.play().catch(advance);\n";
	echo "		};\n";
	echo "		next();\n";
	echo "	}\n";
	echo "	function greeting_copy(button) {\n";
	echo "		var field = button.closest('.greeting_text').querySelector('textarea');\n";
	echo "		var done = function() { greeting_button_label(button, ".json_encode($text['button-copied'])."); setTimeout(function() { greeting_button_label(button, ".json_encode($text['button-copy_text'])."); }, 1500); };\n";
	echo "		if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(field.value).then(done); return; }\n";
	echo "		field.select();\n";
	echo "		if (document.execCommand('copy')) { done(); }\n";
	echo "	}\n";
	echo "	function greeting_select_changed() {\n";
	echo "		greeting_stop();\n";
	echo "		var select = document.getElementById('greeting_value');\n";
	echo "		var option = select.options[select.selectedIndex];\n";
	echo "		var preview = option && option.dataset.preview ? JSON.parse(option.dataset.preview) : null;\n";
	echo "		var player = document.getElementById('greeting_select_preview');\n";
	echo "		var text = document.getElementById('greeting_select_text');\n";
	echo "		player.dataset.preview = preview ? option.dataset.preview : '';\n";
	echo "		player.style.display = preview && preview.steps.length ? '' : 'none';\n";
	echo "		player.querySelector('.greeting_incomplete').style.display = preview && !preview.complete ? '' : 'none';\n";
	echo "		text.querySelector('textarea').value = preview ? preview.text : '';\n";
	echo "		text.style.display = preview && preview.text ? '' : 'none';\n";
	echo "		var delete_button = document.getElementById('btn_greeting_delete');\n";
	echo "		if (delete_button) { delete_button.style.display = option && option.value && option.dataset.deletable === 'true' ? '' : 'none'; }\n";
	echo "	}\n";
	echo "	function greeting_remove_confirm(type, label) {\n";
	echo "		document.getElementById('greeting_remove_type').value = type;\n";
	echo "		document.getElementById('greeting_remove_message').textContent = ".json_encode($text['confirm-greeting_remove']).".replace('%s', function() { return label; });\n";
	echo "		modal_open('modal-greeting-remove');\n";
	echo "	}\n";
	echo "	function greeting_delete_confirm() {\n";
	echo "		var select = document.getElementById('greeting_value');\n";
	echo "		var option = select.options[select.selectedIndex];\n";
	echo "		if (!option || !option.value) { return; }\n";
	echo "		document.getElementById('greeting_delete_value').value = option.value;\n";
	echo "		document.getElementById('greeting_delete_message').textContent = (option.value.indexOf('phrase:') === 0 ? ".json_encode($text['confirm-phrase_delete'])." : ".json_encode($text['confirm-recording_delete']).").replace('%s', function() { return option.text; });\n";
	echo "		modal_open('modal-greeting-delete');\n";
	echo "	}\n";
	echo "	function greeting_delete_submit() {\n";
	echo "		var value = document.getElementById('greeting_delete_value').value;\n";
	echo "		if (value) { document.getElementById('frm_greeting_delete').submit(); }\n";
	echo "	}\n";
	echo "	window.addEventListener('pageshow', function() { if (document.getElementById('greeting_value')) { greeting_select_changed(); } });\n";
	echo "</script>\n";

//include the footer
	require_once "resources/footer.php";

?>
