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

/**
 * IVR manager helper.
 *
 * Works on the upstream tables (v_ivr_menus, v_ivr_menu_options, v_recordings,
 * v_dialplans, v_dialplan_details) through the database class. Every lookup is
 * scoped to the domain given to the constructor. The database class save() and
 * delete() only match on the primary key, so ownership is always checked here
 * with a domain scoped select before anything is written.
 */
class x_ivr_manager {

	/**
	 * declare constant variables
	 */
	const app_name = 'x_ivr_manager';
	const app_uuid = '9106e472-e7f3-4db3-8f47-ce4290b6ca19';

	//upstream application uuids
	const ivr_menu_app_uuid = 'a5788e9b-58bc-bd1b-df59-fff5d51253ab';
	const time_condition_app_uuid = '4b821450-926b-175a-af93-a03c441818b1';

	//dialplan detail group used for the schedule window, the same number the stock
	//time condition editor gives to the first custom group (time_condition_edit.php:1338)
	const schedule_group = 500;

	/**
	 * declare public variables
	 */
	public $domain_uuid;
	public $domain_name;
	public $message;

	/**
	 * declare private variables
	 */
	private $database;
	private $settings;

	/**
	 * Initialize the object
	 *
	 * @param array $setting_array database, settings, domain_uuid, domain_name
	 */
	public function __construct(array $setting_array = []) {
		$this->domain_uuid = $setting_array['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? '';
		$this->domain_name = $setting_array['domain_name'] ?? $_SESSION['domain_name'] ?? '';
		$this->database = $setting_array['database'] ?? database::new();
		$this->settings = $setting_array['settings'] ?? new settings(['database' => $this->database, 'domain_uuid' => $this->domain_uuid]);
	}

	/**
	 * Get an IVR menu that belongs to the domain
	 *
	 * @param string $ivr_menu_uuid
	 * @return array|null
	 */
	public function get_ivr_menu($ivr_menu_uuid) {
		if (!is_uuid($ivr_menu_uuid) || !is_uuid($this->domain_uuid)) {
			return null;
		}
		$sql = "select * from v_ivr_menus ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and ivr_menu_uuid = :ivr_menu_uuid ";
		$parameters['domain_uuid'] = $this->domain_uuid;
		$parameters['ivr_menu_uuid'] = $ivr_menu_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		return !empty($row) ? $row : null;
	}

	/**
	 * Get the options of an IVR menu in their current order
	 *
	 * @param string $ivr_menu_uuid
	 * @return array
	 */
	public function get_options($ivr_menu_uuid) {
		$sql = "select * from v_ivr_menu_options ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and ivr_menu_uuid = :ivr_menu_uuid ";
		$sql .= "order by ivr_menu_option_order asc, natural_sort(ivr_menu_option_digits::text) asc ";
		$parameters['domain_uuid'] = $this->domain_uuid;
		$parameters['ivr_menu_uuid'] = $ivr_menu_uuid;
		$options = $this->database->select($sql, $parameters, 'all');
		return is_array($options) ? $options : [];
	}

	/**
	 * Save the option order from a list of option uuids (drag and drop result)
	 *
	 * The numeric ivr_menu_option_order is kept in the background: 10, 20, 30...
	 * The stock editor shows the order in a 0-999 select, so the step drops to 1
	 * when there are too many options for steps of 10.
	 *
	 * @param array $ivr_menu the ivr menu row (from get_ivr_menu)
	 * @param array $option_uuids option uuids in the new order
	 * @return bool
	 */
	public function save_option_order(array $ivr_menu, array $option_uuids) {

		//only accept options that belong to this menu and domain
		$valid_uuids = [];
		foreach ($this->get_options($ivr_menu['ivr_menu_uuid']) as $row) {
			$valid_uuids[] = $row['ivr_menu_option_uuid'];
		}
		$ordered = [];
		foreach ($option_uuids as $uuid) {
			if (is_uuid($uuid) && in_array($uuid, $valid_uuids, true) && !in_array($uuid, $ordered, true)) {
				$ordered[] = $uuid;
			}
		}
		//the posted list must contain every option exactly once
		if (count($ordered) == 0 || count($ordered) != count($valid_uuids)) {
			return false;
		}

		//build the array
		$step = (count($ordered) * 10 <= 999) ? 10 : 1;
		foreach ($ordered as $x => $uuid) {
			$array['ivr_menu_options'][$x]['ivr_menu_option_uuid'] = $uuid;
			$array['ivr_menu_options'][$x]['domain_uuid'] = $this->domain_uuid;
			$array['ivr_menu_options'][$x]['ivr_menu_option_order'] = ($x + 1) * $step;
		}

		//save with a temporary permission, access is controlled by x_ivr_manager_edit
		$p = permissions::new();
		$p->add('ivr_menu_option_edit', 'temp');
		$result = $this->database->save($array);
		$this->message = $this->database->message;
		$p->delete('ivr_menu_option_edit', 'temp');
		unset($array);

		$this->clear_ivr_cache($ivr_menu);
		return $result !== false;
	}

	/**
	 * Set a greeting field of the IVR menu
	 *
	 * @param array $ivr_menu the ivr menu row
	 * @param string $type greet_long or greet_short
	 * @param string $recording_filename bare file name in the domain recordings directory
	 * @return bool
	 */
	public function set_greeting(array $ivr_menu, $type, $recording_filename) {
		if (!in_array($type, ['greet_long', 'greet_short'], true)) {
			return false;
		}
		$array['ivr_menus'][0]['ivr_menu_uuid'] = $ivr_menu['ivr_menu_uuid'];
		$array['ivr_menus'][0]['domain_uuid'] = $this->domain_uuid;
		$array['ivr_menus'][0]['ivr_menu_'.$type] = $recording_filename;

		$p = permissions::new();
		$p->add('ivr_menu_edit', 'temp');
		$result = $this->database->save($array);
		$this->message = $this->database->message;
		$p->delete('ivr_menu_edit', 'temp');
		unset($array);

		$this->clear_ivr_cache($ivr_menu);
		return $result !== false;
	}

	/**
	 * Clear the IVR cache
	 *
	 * Mirrors app/ivr_menus/ivr_menu_edit.php:553-577 (clear the cache, then the
	 * cache of every enabled parent menu).
	 *
	 * @param array $ivr_menu the ivr menu row
	 */
	public function clear_ivr_cache(array $ivr_menu) {
		//clear the cache
			$cache = new cache;
			$cache->delete("dialplan:".($_SESSION["context"] ?? $_SESSION["domain_name"]));
			$cache->delete("configuration:ivr.conf:".$ivr_menu['ivr_menu_uuid']);

		//get all ivr parent menus
			//upstream binds an empty string when there is no parent, which a uuid column rejects
			$ivr_menu_parent_uuid = $ivr_menu['ivr_menu_parent_uuid'] ?? '';
			if (!is_uuid($ivr_menu_parent_uuid)) {
				return;
			}
			$sql = "with recursive ivr_menus as ( ";
			$sql .="	select ivr_menu_parent_uuid ";
			$sql .="	 from v_ivr_menus ";
			$sql .="	 where ivr_menu_parent_uuid = :ivr_menu_parent_uuid ";
			$sql .="	 and ivr_menu_enabled = true ";
			$sql .="	 union all ";
			$sql .="	 select parent.ivr_menu_parent_uuid ";
			$sql .="	 from v_ivr_menus as parent, ivr_menus as child ";
			$sql .="	 where parent.ivr_menu_uuid = child.ivr_menu_parent_uuid ";
			$sql .="	 and parent.ivr_menu_enabled = true ";
			$sql .="	) ";
			$sql .="	select * from ivr_menus ";
			$parameters['ivr_menu_parent_uuid'] = $ivr_menu_parent_uuid;
			$parent_uuids = $this->database->select($sql, $parameters, "all");
			if (!empty($parent_uuids)) {
				foreach ($parent_uuids as $x => $row) {
					$cache->delete("configuration:ivr.conf:".$row['ivr_menu_parent_uuid']);
				}
			}
	}

	/**
	 * Path of the domain recordings directory
	 *
	 * @return string
	 */
	public function recording_path() {
		return $this->settings->get('switch', 'recordings').'/'.$this->domain_name;
	}

	/**
	 * Build a safe, unused recording file name
	 *
	 * @param string $recording_name
	 * @param string $extension wav or mp3
	 * @return string
	 */
	public function recording_filename($recording_name, $extension) {
		$base = preg_replace('#[^a-zA-Z0-9_\-]#', '_', $recording_name);
		$base = trim($base, '_');
		if ($base === '') {
			$base = 'recording';
		}
		$base = substr($base, 0, 100);
		$filename = $base.'.'.$extension;
		$i = 0;
		while (file_exists($this->recording_path().'/'.$filename) && $i < 20) {
			$filename = $base.'-'.str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT).'.'.$extension;
			$i++;
		}
		return $filename;
	}

	/**
	 * Add a row to v_recordings for a file in the domain recordings directory
	 *
	 * Mirrors app/recordings/recording_edit.php:303-322 and app/ivr_menus/ivr_menu_edit.php:520-527.
	 *
	 * @param array $recording recording_filename, recording_name, recording_description,
	 *                         optional recording_voice, recording_speed, recording_message
	 * @return string|false the recording uuid
	 */
	public function add_recording(array $recording) {
		$recording_uuid = uuid();
		$full_path = $this->recording_path().'/'.$recording['recording_filename'];

		$array['recordings'][0]['domain_uuid'] = $this->domain_uuid;
		$array['recordings'][0]['recording_uuid'] = $recording_uuid;
		$array['recordings'][0]['recording_filename'] = $recording['recording_filename'];
		$array['recordings'][0]['recording_name'] = $recording['recording_name'];
		if ($this->settings->get('recordings', 'storage_type', '') == 'base64' && file_exists($full_path)) {
			$array['recordings'][0]['recording_base64'] = base64_encode(file_get_contents($full_path));
		}
		if (array_key_exists('recording_message', $recording)) {
			$array['recordings'][0]['recording_voice'] = $recording['recording_voice'] ?? null;
			$array['recordings'][0]['recording_speed'] = $recording['recording_speed'] ?? null;
			$array['recordings'][0]['recording_message'] = $recording['recording_message'];
		}
		$array['recordings'][0]['recording_description'] = $recording['recording_description'] ?? '';

		$p = permissions::new();
		$p->add('recording_add', 'temp');
		$p->add('recording_edit', 'temp');
		$result = $this->database->save($array);
		$this->message = $this->database->message;
		$p->delete('recording_add', 'temp');
		$p->delete('recording_edit', 'temp');
		unset($array);

		//clear the destinations session array, as the recordings upload does
		if (isset($_SESSION['destinations']['array'])) {
			unset($_SESSION['destinations']['array']);
		}

		return $result === false ? false : $recording_uuid;
	}

	/**
	 * Make sure the domain recordings directory exists (app/recordings/recordings.php upload)
	 */
	private function recording_path_create() {
		if (!is_dir($this->recording_path())) {
			mkdir($this->recording_path(), 0770, false);
		}
		return is_dir($this->recording_path());
	}

	/**
	 * Generate a greeting with the speech app (fusionpbx-app-speech)
	 *
	 * Mirrors app/ivr_menus/ivr_menu_edit.php:466-531 (text to speech greeting).
	 *
	 * @param array $ivr_menu the ivr menu row
	 * @param string $type greet_long or greet_short
	 * @param array $tts recording_name, recording_voice, recording_speed, recording_message
	 * @return string|false the recording file name
	 */
	public function tts_greeting(array $ivr_menu, $type, array $tts) {
		$speech_enabled = class_exists('speech') && $this->settings->get('speech', 'enabled', false);
		$speech_engine = $this->settings->get('speech', 'engine', '');
		if (!$speech_enabled || empty($speech_engine) || empty($tts['recording_message'])) {
			return false;
		}
		if (!in_array($type, ['greet_long', 'greet_short'], true)) {
			return false;
		}

		$speech = new speech($this->settings);
		$recording_extension = $speech->get_format();
		$speed_enabled = $speech->is_speed_enabled();

		$recording_name = $tts['recording_name'] ?? '';
		if (empty($recording_name)) {
			$recording_name = 'recording'.$ivr_menu['ivr_menu_extension'].($type == 'greet_short' ? '-short' : null).'-'.str_pad(random_int(0, 9999), 4, '0', STR_PAD_LEFT);
		}

		//set the recording format for approved types
		if (!in_array($recording_extension, ['mp3', 'wav'], true)) {
			//default to wav
			$recording_extension = 'wav';
		}

		//get the recording path
		if (!$this->recording_path_create()) {
			return false;
		}
		$recording_path = $this->recording_path();

		//create the file name
		$recording_filename = $this->recording_filename($recording_name, $recording_extension);

		//text to audio - make a new audio file from the message
		$speech->audio_path = $recording_path;
		$speech->audio_filename = $recording_filename;
		$speech->audio_voice = $tts['recording_voice'] ?? '';
		if ($speed_enabled) {
			$speech->audio_speed = (float)($tts['recording_speed'] ?? '1.0');
		}
		$speech->audio_message = $tts['recording_message'];
		$speech->speech();

		//fix invalid riff & data header lengths in generated wave file
		if ($speech_engine == 'openai') {
			$recording_filename_temp = str_replace('.'.$recording_extension, '.tmp.'.$recording_extension, $recording_filename);
			if (file_exists($recording_path.'/'.$recording_filename)) {
				exec('sox --ignore-length '.escapeshellarg($recording_path.'/'.$recording_filename).' '.escapeshellarg($recording_path.'/'.$recording_filename_temp));
			}
			if (file_exists($recording_path.'/'.$recording_filename_temp)) {
				recursive_delete($recording_path.'/'.$recording_filename);
				exec('mv '.escapeshellarg($recording_path.'/'.$recording_filename_temp).' '.escapeshellarg($recording_path.'/'.$recording_filename));
			}
			unset($recording_filename_temp);
		}

		//the speech engine failed
		if (!file_exists($recording_path.'/'.$recording_filename) || filesize($recording_path.'/'.$recording_filename) == 0) {
			return false;
		}

		//add the recording
		$recording['recording_filename'] = $recording_filename;
		$recording['recording_name'] = $recording_name;
		$recording['recording_voice'] = $tts['recording_voice'] ?? null;
		$recording['recording_speed'] = $speed_enabled ? ($tts['recording_speed'] ?? '1.0') : null;
		$recording['recording_message'] = $tts['recording_message'];
		$recording['recording_description'] = $tts['recording_description'] ?? '';
		if ($this->add_recording($recording) === false) {
			//remove the orphaned audio file
			if (file_exists($recording_path.'/'.$recording_filename)) {
				unlink($recording_path.'/'.$recording_filename);
			}
			return false;
		}

		//update the ivr menu
		if (!$this->set_greeting($ivr_menu, $type, $recording_filename)) {
			return false;
		}
		return $recording_filename;
	}

	/**
	 * Detect the container of an uploaded browser recording from its content
	 *
	 * Never trusts the client mime type or file name. Both libmagic and the
	 * leading magic bytes must agree.
	 *
	 * @param string $path
	 * @return string|false ffmpeg demuxer name
	 */
	public function detect_audio_format($path) {
		if (!is_file($path) || filesize($path) < 16) {
			return false;
		}
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($path);
		$head = file_get_contents($path, false, null, 0, 16);
		if ($head === false || strlen($head) < 12) {
			return false;
		}

		//webm (chrome, firefox), EBML header
		if (substr($head, 0, 4) === "\x1A\x45\xDF\xA3"
			&& in_array($mime, ['video/webm', 'audio/webm', 'video/x-matroska', 'audio/x-matroska'], true)) {
			return 'matroska';
		}
		//mp4 (safari), ftyp box
		if (substr($head, 4, 4) === 'ftyp'
			&& in_array($mime, ['video/mp4', 'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/quicktime', 'application/mp4'], true)) {
			return 'mov';
		}
		//ogg (firefox)
		if (substr($head, 0, 4) === 'OggS'
			&& in_array($mime, ['audio/ogg', 'video/ogg', 'application/ogg'], true)) {
			return 'ogg';
		}
		//wav
		if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE'
			&& in_array($mime, ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'], true)) {
			return 'wav';
		}
		return false;
	}

	/**
	 * Convert an uploaded browser recording to a mono 16 bit PCM wav
	 *
	 * Runs ffmpeg with an argv array (no shell). The demuxer is forced to the
	 * format detected from the content and only the file protocol is allowed.
	 *
	 * @param string $source_path uploaded temp file
	 * @param string $format demuxer from detect_audio_format()
	 * @param string $destination_path full path of the wav to write
	 * @return bool
	 */
	public function convert_to_wav($source_path, $format, $destination_path) {
		//fixed path: settings can be overridden per user by tenant admins, so a
		//configurable path would let them choose the binary run as www-data
		$ffmpeg = '/usr/bin/ffmpeg';
		if (!is_executable($ffmpeg)) {
			$this->message = 'ffmpeg not found';
			return false;
		}
		if (!in_array($format, ['matroska', 'mov', 'ogg', 'wav'], true)) {
			return false;
		}
		$sample_rate = (int)$this->settings->get('x_ivr_manager', 'recording_sample_rate', 8000);
		if (!in_array($sample_rate, [8000, 16000], true)) {
			$sample_rate = 8000;
		}
		$max_seconds = (int)$this->settings->get('x_ivr_manager', 'recording_max_seconds', 300);
		if ($max_seconds < 1 || $max_seconds > 3600) {
			$max_seconds = 300;
		}

		$temp_path = $destination_path.'.tmp';
		$command = [
			$ffmpeg,
			'-nostdin', '-hide_banner', '-loglevel', 'error',
			'-protocol_whitelist', 'file',
			'-f', $format,
			'-i', 'file:'.$source_path,
			'-t', (string)$max_seconds,
			'-vn', '-sn', '-dn',
			'-map_metadata', '-1',
			'-ac', '1',
			'-ar', (string)$sample_rate,
			'-c:a', 'pcm_s16le',
			'-f', 'wav',
			'-y', 'file:'.$temp_path,
		];
		$descriptors = [
			0 => ['file', '/dev/null', 'r'],
			1 => ['file', '/dev/null', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = proc_open($command, $descriptors, $pipes);
		if (!is_resource($process)) {
			return false;
		}
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		$exit_code = proc_close($process);

		if ($exit_code !== 0 || !file_exists($temp_path) || filesize($temp_path) <= 44) {
			$this->message = trim((string)$error);
			if (file_exists($temp_path)) {
				unlink($temp_path);
			}
			return false;
		}

		//confirm the output is a wav before it is used
		if ($this->detect_audio_format($temp_path) !== 'wav') {
			unlink($temp_path);
			return false;
		}
		return rename($temp_path, $destination_path);
	}

	/**
	 * Store a browser recording as a domain recording and set it as the greeting
	 *
	 * @param array $ivr_menu the ivr menu row
	 * @param string $type greet_long or greet_short
	 * @param string $upload_path uploaded temp file (is_uploaded_file checked by the caller)
	 * @param string $recording_name
	 * @param string $recording_description
	 * @return string|false error key or the recording file name
	 */
	public function record_greeting(array $ivr_menu, $type, $upload_path, $recording_name, $recording_description = '') {
		if (!in_array($type, ['greet_long', 'greet_short'], true)) {
			return false;
		}
		$format = $this->detect_audio_format($upload_path);
		if ($format === false) {
			$this->message = 'unsupported';
			return false;
		}
		if (!$this->recording_path_create()) {
			return false;
		}
		if (empty($recording_name)) {
			$recording_name = 'recording'.$ivr_menu['ivr_menu_extension'].($type == 'greet_short' ? '-short' : null).'-'.date('Ymd-His');
		}
		$recording_filename = $this->recording_filename($recording_name, 'wav');
		$recording_path = $this->recording_path();
		if (!$this->convert_to_wav($upload_path, $format, $recording_path.'/'.$recording_filename)) {
			return false;
		}

		//add the recording
		$recording['recording_filename'] = $recording_filename;
		$recording['recording_name'] = $recording_name;
		$recording['recording_description'] = $recording_description;
		if ($this->add_recording($recording) === false) {
			//remove the orphaned audio file
			if (file_exists($recording_path.'/'.$recording_filename)) {
				unlink($recording_path.'/'.$recording_filename);
			}
			return false;
		}

		//update the ivr menu
		if (!$this->set_greeting($ivr_menu, $type, $recording_filename)) {
			return false;
		}
		return $recording_filename;
	}

	/**
	 * Get the domain time zone used for schedules (dialplan_time_zone)
	 *
	 * @return string
	 */
	public function time_zone() {
		$time_zone = $this->settings->get('domain', 'time_zone', date_default_timezone_get());
		if (!in_array($time_zone, DateTimeZone::listIdentifiers(), true)) {
			$time_zone = date_default_timezone_get();
		}
		return $time_zone;
	}

	/**
	 * Get the schedule (time condition) for an IVR menu
	 *
	 * Reads the dialplan details back the same way the stock editor does
	 * (time_condition_edit.php:689-760 and 1245-1312), so changes made in the
	 * stock editor are shown here.
	 *
	 * @param array $ivr_menu the ivr menu row
	 * @return array|null
	 */
	public function get_schedule(array $ivr_menu) {
		$sql = "select s.x_ivr_manager_schedule_uuid, d.dialplan_uuid, d.dialplan_name, d.dialplan_number, ";
		$sql .= "d.dialplan_context, d.dialplan_order, cast(d.dialplan_enabled as text) as dialplan_enabled, d.dialplan_description ";
		$sql .= "from v_x_ivr_manager_schedules as s, v_dialplans as d ";
		$sql .= "where s.domain_uuid = :domain_uuid ";
		$sql .= "and s.ivr_menu_uuid = :ivr_menu_uuid ";
		$sql .= "and d.dialplan_uuid = s.dialplan_uuid ";
		$sql .= "and d.domain_uuid = :domain_uuid ";
		$sql .= "and d.app_uuid = :app_uuid ";
		$parameters['domain_uuid'] = $this->domain_uuid;
		$parameters['ivr_menu_uuid'] = $ivr_menu['ivr_menu_uuid'];
		$parameters['app_uuid'] = self::time_condition_app_uuid;
		$schedule = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		if (empty($schedule)) {
			return null;
		}

		//get the dialplan details
		$sql = "select dialplan_detail_group, dialplan_detail_tag, dialplan_detail_type, dialplan_detail_data ";
		$sql .= "from v_dialplan_details ";
		$sql .= "where dialplan_uuid = :dialplan_uuid ";
		$sql .= "and domain_uuid = :domain_uuid ";
		$sql .= "order by dialplan_detail_group asc, dialplan_detail_order asc";
		$parameters['dialplan_uuid'] = $schedule['dialplan_uuid'];
		$parameters['domain_uuid'] = $this->domain_uuid;
		$dialplan_details = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);

		$schedule['dialplan_time_zone'] = '';
		$schedule['start'] = '';
		$schedule['stop'] = '';
		$schedule['action'] = '';
		$schedule['anti_action'] = '';
		$schedule['complex'] = false;
		$groups = [];
		if (is_array($dialplan_details)) {
			foreach ($dialplan_details as $row) {
				$group = (string)$row['dialplan_detail_group'];
				if ($row['dialplan_detail_tag'] == 'action' && $row['dialplan_detail_type'] == 'set' && strpos($row['dialplan_detail_data'], 'timezone=') === 0) {
					$schedule['dialplan_time_zone'] = explode('=', $row['dialplan_detail_data'])[1];
					continue;
				}
				if ($group === '0') {
					continue;
				}
				if ($row['dialplan_detail_tag'] == 'action') {
					$action = $row['dialplan_detail_type'].($row['dialplan_detail_data'] != '' || $row['dialplan_detail_type'] == 'hangup' ? ':'.$row['dialplan_detail_data'] : null);
					if ($group === '999') {
						$schedule['anti_action'] = $action;
					}
					else {
						$schedule['action'] = $action;
						$groups[$group] = true;
					}
				}
				else if ($row['dialplan_detail_tag'] == 'condition' && $group !== '999') {
					$groups[$group] = true;
					if ($row['dialplan_detail_type'] == 'date-time' && $schedule['start'] === '') {
						$tmp = explode('~', $row['dialplan_detail_data']);
						$schedule['start'] = $tmp[0];
						$schedule['stop'] = $tmp[1] ?? '';
						unset($tmp);
					}
					else if ($row['dialplan_detail_type'] != 'destination_number' && $row['dialplan_detail_type'] != 'date-time') {
						$schedule['complex'] = true;
					}
				}
			}
		}
		if (count($groups) > 1 || $schedule['start'] === '') {
			$schedule['complex'] = true;
		}

		//convert from utc to the domain time zone, mirrors time_condition_edit.php:1280-1303
		$server_time_zone = trim(shell_exec('date +%Z'));
		if ($server_time_zone === 'UTC') {
			$user_timezone = $this->time_zone();
			foreach (['start', 'stop'] as $key) {
				if (!empty($schedule[$key])) {
					$dt = DateTime::createFromFormat('Y-m-d H:i', $schedule[$key], new DateTimeZone('UTC'));
					if ($dt !== false) {
						$dt->setTimezone(new DateTimeZone($user_timezone));
						$schedule[$key] = $dt->format('Y-m-d H:i');
					}
				}
			}
		}

		return $schedule;
	}

	/**
	 * Create or update the time condition that routes to the IVR during the window
	 *
	 * The time condition editor (app/time_conditions/time_condition_edit.php) builds
	 * its dialplan inline, there is no reusable class. This method replicates what
	 * that editor writes for one custom condition group with a date-time range and
	 * an alternate destination, so the result opens and edits cleanly there:
	 *  - name clean up:                time_condition_edit.php:147-148
	 *  - context for users without time_condition_context: time_condition_edit.php:150-169
	 *  - v_dialplans insert/update:    time_condition_edit.php:172-227 (app_uuid 4b821450-926b-175a-af93-a03c441818b1,
	 *                                  dialplan_continue false, details deleted then rebuilt on update)
	 *  - timezone detail (group 0):    time_condition_edit.php:235-261
	 *  - date-time to UTC conversion:  time_condition_edit.php:365-399
	 *  - group destination_number, date-time condition (range '~', break never) and
	 *    group action:                 time_condition_edit.php:480-518 and 567-593
	 *  - alternate destination (group 999): time_condition_edit.php:600-638
	 *  - save details, regenerate dialplan_xml with the dialplan class, clear the
	 *    cache and the destinations session array: time_condition_edit.php:640-670
	 *
	 * @param array $ivr_menu the ivr menu row
	 * @param array $schedule dialplan_name, dialplan_number, start and stop ('Y-m-d H:i' in the
	 *                        domain time zone), anti_action, dialplan_enabled
	 * @return string|false the dialplan uuid, or false with $this->message set to an error key
	 */
	public function save_schedule(array $ivr_menu, array $schedule) {

		//validate the input
			$dialplan_name = trim($schedule['dialplan_name'] ?? '');
			$dialplan_number = trim($schedule['dialplan_number'] ?? '');
			$dialplan_enabled = ($schedule['dialplan_enabled'] ?? 'true') === 'false' ? 'false' : 'true';
			if ($dialplan_name === '') {
				$this->message = 'message-schedule_name_required';
				return false;
			}
			//digits only, the number is placed in a ^...$ regex unescaped by the stock editor
			if (!preg_match('/^[0-9]{1,20}$/', $dialplan_number)) {
				$this->message = 'message-schedule_number_invalid';
				return false;
			}
			$cond_start = $schedule['start'] ?? '';
			$cond_stop = $schedule['stop'] ?? '';
			$dt_start = DateTime::createFromFormat('!Y-m-d H:i', $cond_start);
			$dt_stop = DateTime::createFromFormat('!Y-m-d H:i', $cond_stop);
			if ($dt_start === false || $dt_stop === false || $dt_start->format('Y-m-d H:i') !== $cond_start || $dt_stop->format('Y-m-d H:i') !== $cond_stop) {
				$this->message = 'message-schedule_date_invalid';
				return false;
			}
			if ($dt_stop <= $dt_start) {
				$this->message = 'message-schedule_range_invalid';
				return false;
			}
			unset($dt_start, $dt_stop);

		//validate the destinations
			$destination = new destinations;
			//upstream ivr destinations only list enabled menus
			$ivr_menu_enabled = $ivr_menu['ivr_menu_enabled'] ?? true;
			if ($ivr_menu_enabled === false || $ivr_menu_enabled === 'false' || $ivr_menu_enabled === 0 || $ivr_menu_enabled === '0' || $ivr_menu_enabled === 'f') {
				$this->message = 'message-schedule_ivr_disabled';
				return false;
			}
			$dialplan_action = 'transfer:'.$ivr_menu['ivr_menu_extension'].' XML '.$ivr_menu['ivr_menu_context'];
			if (!$destination->valid($dialplan_action)) {
				$this->message = 'message-schedule_ivr_invalid';
				return false;
			}
			$dialplan_anti_action = $schedule['anti_action'] ?? '';
			$dialplan_anti_action_array = explode(":", $dialplan_anti_action);
			$dialplan_anti_action_app = array_shift($dialplan_anti_action_array);
			$dialplan_anti_action_data = join(':', $dialplan_anti_action_array);
			if (empty($dialplan_anti_action_app) || !$destination->valid($dialplan_anti_action_app.':'.$dialplan_anti_action_data)) {
				$this->message = 'message-schedule_fallback_invalid';
				return false;
			}

		//set the variables
			$domain_uuid = $this->domain_uuid;
			$dialplan_time_zone = $this->time_zone();

		//remove the invalid characters from the dialplan name (time_condition_edit.php:147-148)
			$dialplan_name = str_replace('/', '', $dialplan_name);

		//find the existing time condition
			$existing = $this->get_schedule($ivr_menu);
			$action = !empty($existing) ? 'update' : 'add';

		//do not silently drop conditions added in the stock time condition editor
			if ($action == 'update' && !empty($existing['complex']) && ($schedule['confirm_replace'] ?? '') !== 'true') {
				$this->message = 'message-schedule_complex_confirm';
				return false;
			}

		//process main dialplan entry (time_condition_edit.php:150-227)
			if ($action == "add") {
				//context for users without time_condition_context
					$dialplan_context = $_SESSION['domain_name'];
					$dialplan_order = '300';
					$dialplan_description = $schedule['dialplan_description'] ?? '';

				//build insert array
					$dialplan_uuid = uuid();
					$array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
					$array['dialplans'][0]['app_uuid'] = self::time_condition_app_uuid;
					$array['dialplans'][0]['dialplan_continue'] = 'false';
					$array['dialplans'][0]['dialplan_context'] = $dialplan_context;

				//grant temporary permissions
					$p = permissions::new();
					$p->add('dialplan_add', 'temp');
			}
			else {
				//keep the values the stock editor may have changed
					$dialplan_uuid = $existing['dialplan_uuid'];
					$dialplan_context = $existing['dialplan_context'];
					$dialplan_order = $existing['dialplan_order'];
					$dialplan_description = $existing['dialplan_description'];

				//build delete array
					$array['dialplan_details'][0]['dialplan_uuid'] = $dialplan_uuid;

				//grant temporary permissions
					$p = permissions::new();
					$p->add('dialplan_detail_delete', 'temp');

				//execute delete
					$this->database->delete($array);
					unset($array);

				//revoke temporary permissions
					$p->delete('dialplan_detail_delete', 'temp');

				//build update array
					$array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
					$array['dialplans'][0]['dialplan_continue'] = 'false';
					if (!empty($dialplan_context)) {
						$array['dialplans'][0]['dialplan_context'] = $dialplan_context;
					}

				//grant temporary permissions
					$p = permissions::new();
					$p->add('dialplan_edit', 'temp');
			}

		//add common fields to insert/update array
			$array['dialplans'][0]['domain_uuid'] = is_uuid($domain_uuid) ? $domain_uuid : null;
			$array['dialplans'][0]['dialplan_name'] = $dialplan_name;
			$array['dialplans'][0]['dialplan_number'] = $dialplan_number;
			$array['dialplans'][0]['dialplan_order'] = $dialplan_order;
			$array['dialplans'][0]['dialplan_enabled'] = $dialplan_enabled;
			$array['dialplans'][0]['dialplan_description'] = $dialplan_description;

		//execute insert/update
			$result = $this->database->save($array);
			unset($array);

		//revoke temporary permissions
			$p->delete('dialplan_add', 'temp');
			$p->delete('dialplan_edit', 'temp');

		//stop when the dialplan could not be saved
			if ($result === false) {
				$this->message = 'message-failed';
				return false;
			}

		//initialize dialplan detail group and order numbers
			$dialplan_detail_group = 0;
			$dialplan_detail_order = 0;
			$x = 0;

		//add the timezone (time_condition_edit.php:235-261)
			if (!empty($dialplan_time_zone)) {
				$array['dialplan_details'][$x]['domain_uuid'] = is_uuid($domain_uuid) ? $domain_uuid : null;
				$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
				$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
				$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'condition';
				$array['dialplan_details'][$x]['dialplan_detail_type'] = 'destination_number';
				$array['dialplan_details'][$x]['dialplan_detail_data'] = '^'.$dialplan_number.'$';
				$array['dialplan_details'][$x]['dialplan_detail_break'] = null;
				$array['dialplan_details'][$x]['dialplan_detail_inline'] = null;
				$array['dialplan_details'][$x]['dialplan_detail_group'] = 0;
				$array['dialplan_details'][$x]['dialplan_detail_order'] = 10;
				$array['dialplan_details'][$x]['dialplan_detail_enabled'] = 'true';
				$x++;
				$array['dialplan_details'][$x]['domain_uuid'] = is_uuid($domain_uuid) ? $domain_uuid : null;
				$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
				$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
				$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'action';
				$array['dialplan_details'][$x]['dialplan_detail_type'] = 'set';
				$array['dialplan_details'][$x]['dialplan_detail_data'] = 'timezone='.$dialplan_time_zone;
				$array['dialplan_details'][$x]['dialplan_detail_break'] = null;
				$array['dialplan_details'][$x]['dialplan_detail_inline'] = 'true';
				$array['dialplan_details'][$x]['dialplan_detail_group'] = 0;
				$array['dialplan_details'][$x]['dialplan_detail_order'] = 20;
				$array['dialplan_details'][$x]['dialplan_detail_enabled'] = 'true';
				$x++;
			}

		//convert to utc when the server runs in utc (time_condition_edit.php:365-399)
		//the inputs are already 24 hour 'Y-m-d H:i' in the domain time zone, which is what
		//the stock editor has after its own format parsing
			$server_time_zone = trim(shell_exec('date +%Z'));
			if ($server_time_zone === 'UTC') {
				$user_timezone = $this->time_zone();
				$dt = DateTime::createFromFormat('Y-m-d H:i', $cond_start, new DateTimeZone($user_timezone));
				if ($dt !== false) {
					$dt->setTimezone(new DateTimeZone('UTC'));
					$cond_start = $dt->format('Y-m-d H:i');
				}
				$dt = DateTime::createFromFormat('Y-m-d H:i', $cond_stop, new DateTimeZone($user_timezone));
				if ($dt !== false) {
					$dt->setTimezone(new DateTimeZone('UTC'));
					$cond_stop = $dt->format('Y-m-d H:i');
				}
				unset($dt);
			}
			$cond_value = $cond_start.'~'.$cond_stop;

		//custom condition group (time_condition_edit.php:480-518)
			$dialplan_detail_group = self::schedule_group;
			$dialplan_detail_order = 0;

			//add destination number condition
			$dialplan_detail_order += 10;
			$array['dialplan_details'][$x]['domain_uuid'] = is_uuid($domain_uuid) ? $domain_uuid : null;
			$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
			$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'condition';
			$array['dialplan_details'][$x]['dialplan_detail_type'] = 'destination_number';
			$array['dialplan_details'][$x]['dialplan_detail_data'] = '^'.$dialplan_number.'$';
			$array['dialplan_details'][$x]['dialplan_detail_break'] = null;
			$array['dialplan_details'][$x]['dialplan_detail_inline'] = null;
			$array['dialplan_details'][$x]['dialplan_detail_group'] = $dialplan_detail_group;
			$array['dialplan_details'][$x]['dialplan_detail_order'] = $dialplan_detail_order;
			$array['dialplan_details'][$x]['dialplan_detail_enabled'] = 'true';
			$x++;

			//add the date-time condition
			$dialplan_detail_order += 10;
			$array['dialplan_details'][$x]['domain_uuid'] = is_uuid($domain_uuid) ? $domain_uuid : null;
			$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
			$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'condition';
			$array['dialplan_details'][$x]['dialplan_detail_type'] = 'date-time';
			$array['dialplan_details'][$x]['dialplan_detail_data'] = $cond_value;
			$array['dialplan_details'][$x]['dialplan_detail_break'] = 'never';
			$array['dialplan_details'][$x]['dialplan_detail_inline'] = null;
			$array['dialplan_details'][$x]['dialplan_detail_group'] = $dialplan_detail_group;
			$array['dialplan_details'][$x]['dialplan_detail_order'] = $dialplan_detail_order;
			$array['dialplan_details'][$x]['dialplan_detail_enabled'] = 'true';
			$x++;

		//add group action to query (time_condition_edit.php:567-593)
			$dialplan_action_array = explode(":", $dialplan_action);
			$dialplan_action_app = array_shift($dialplan_action_array);
			$dialplan_action_data = join(':', $dialplan_action_array);
			$dialplan_detail_order += 10;
			$array['dialplan_details'][$x]['domain_uuid'] = is_uuid($domain_uuid) ? $domain_uuid : null;
			$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
			$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'action';
			$array['dialplan_details'][$x]['dialplan_detail_type'] = $dialplan_action_app;
			$array['dialplan_details'][$x]['dialplan_detail_data'] = $dialplan_action_data;
			$array['dialplan_details'][$x]['dialplan_detail_break'] = null;
			$array['dialplan_details'][$x]['dialplan_detail_inline'] = null;
			$array['dialplan_details'][$x]['dialplan_detail_group'] = $dialplan_detail_group;
			$array['dialplan_details'][$x]['dialplan_detail_order'] = $dialplan_detail_order;
			$array['dialplan_details'][$x]['dialplan_detail_enabled'] = 'true';
			$x++;

		//add the default anti-action (time_condition_edit.php:600-638)
			$dialplan_detail_group = 999;
			$dialplan_detail_order = 0;

			//add destination number condition
			$dialplan_detail_order += 10;
			$array['dialplan_details'][$x]['domain_uuid'] = is_uuid($domain_uuid) ? $domain_uuid : null;
			$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
			$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'condition';
			$array['dialplan_details'][$x]['dialplan_detail_type'] = 'destination_number';
			$array['dialplan_details'][$x]['dialplan_detail_data'] = '^'.$dialplan_number.'$';
			$array['dialplan_details'][$x]['dialplan_detail_break'] = null;
			$array['dialplan_details'][$x]['dialplan_detail_inline'] = null;
			$array['dialplan_details'][$x]['dialplan_detail_group'] = $dialplan_detail_group;
			$array['dialplan_details'][$x]['dialplan_detail_order'] = $dialplan_detail_order;
			$array['dialplan_details'][$x]['dialplan_detail_enabled'] = 'true';
			$x++;

			//add anti-action
			$dialplan_detail_order += 10;
			$array['dialplan_details'][$x]['domain_uuid'] = is_uuid($domain_uuid) ? $domain_uuid : null;
			$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
			$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'action';
			$array['dialplan_details'][$x]['dialplan_detail_type'] = $dialplan_anti_action_app;
			$array['dialplan_details'][$x]['dialplan_detail_data'] = $dialplan_anti_action_data;
			$array['dialplan_details'][$x]['dialplan_detail_break'] = null;
			$array['dialplan_details'][$x]['dialplan_detail_inline'] = null;
			$array['dialplan_details'][$x]['dialplan_detail_group'] = $dialplan_detail_group;
			$array['dialplan_details'][$x]['dialplan_detail_order'] = $dialplan_detail_order;
			$array['dialplan_details'][$x]['dialplan_detail_enabled'] = 'true';
			$x++;

		//execute query (time_condition_edit.php:640-654)
			$p = permissions::new();
			$p->add('dialplan_detail_add', 'temp');
			$p->add('dialplan_detail_edit', 'temp');
			$result = $this->database->save($array);
			unset($array);
			$p->delete('dialplan_detail_add', 'temp');
			$p->delete('dialplan_detail_edit', 'temp');
			if ($result === false) {
				$this->message = 'message-failed';
				return false;
			}

		//update the dialplan xml (time_condition_edit.php:656-661)
			$dialplans = new dialplan;
			$dialplans->source = "details";
			$dialplans->destination = "database";
			$dialplans->uuid = $dialplan_uuid;
			$dialplans->xml();

		//save the link between the ivr menu and the time condition
			if ($action == 'add') {
				//remove links to time conditions that were deleted in the stock editor
				$this->delete_schedule($ivr_menu);

				$array['x_ivr_manager_schedules'][0]['x_ivr_manager_schedule_uuid'] = uuid();
				$array['x_ivr_manager_schedules'][0]['domain_uuid'] = $domain_uuid;
				$array['x_ivr_manager_schedules'][0]['ivr_menu_uuid'] = $ivr_menu['ivr_menu_uuid'];
				$array['x_ivr_manager_schedules'][0]['dialplan_uuid'] = $dialplan_uuid;
				$p = permissions::new();
				$p->add('x_ivr_manager_schedule_add', 'temp');
				$this->database->save($array);
				$p->delete('x_ivr_manager_schedule_add', 'temp');
				unset($array);
			}

		//clear the cache and the destinations session array (time_condition_edit.php:663-670)
			$this->clear_time_condition_cache();

		return $dialplan_uuid;
	}

	/**
	 * Delete the schedule time condition of an IVR menu
	 *
	 * Mirrors app/dialplans/resources/classes/dialplan.php:1123-1178 (delete).
	 *
	 * @param array $ivr_menu the ivr menu row
	 * @return bool
	 */
	public function delete_schedule(array $ivr_menu) {
		//get the links, including ones whose time condition was already removed
		$sql = "select x_ivr_manager_schedule_uuid, dialplan_uuid from v_x_ivr_manager_schedules ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and ivr_menu_uuid = :ivr_menu_uuid ";
		$parameters['domain_uuid'] = $this->domain_uuid;
		$parameters['ivr_menu_uuid'] = $ivr_menu['ivr_menu_uuid'];
		$links = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		if (empty($links)) {
			return false;
		}

		$dialplan_contexts = [];
		foreach ($links as $x => $link) {
			//only delete time conditions in this domain
			$sql = "select dialplan_context from v_dialplans ";
			$sql .= "where dialplan_uuid = :dialplan_uuid ";
			$sql .= "and domain_uuid = :domain_uuid ";
			$sql .= "and app_uuid = :app_uuid ";
			$parameters['dialplan_uuid'] = $link['dialplan_uuid'];
			$parameters['domain_uuid'] = $this->domain_uuid;
			$parameters['app_uuid'] = self::time_condition_app_uuid;
			$dialplan_context = $this->database->select($sql, $parameters, 'column');
			unset($sql, $parameters);
			if (!empty($dialplan_context)) {
				$array['dialplans'][$x]['dialplan_uuid'] = $link['dialplan_uuid'];
				$array['dialplan_details'][$x]['dialplan_uuid'] = $link['dialplan_uuid'];
				$dialplan_contexts[] = $dialplan_context;
			}
			$array['x_ivr_manager_schedules'][$x]['x_ivr_manager_schedule_uuid'] = $link['x_ivr_manager_schedule_uuid'];
		}

		//grant temporary permissions
		$p = permissions::new();
		$p->add('dialplan_delete', 'temp');
		$p->add('dialplan_detail_delete', 'temp');
		$p->add('x_ivr_manager_schedule_delete', 'temp');

		//execute delete
		$this->database->delete($array);
		unset($array);

		//revoke temporary permissions
		$p->delete('dialplan_delete', 'temp');
		$p->delete('dialplan_detail_delete', 'temp');
		$p->delete('x_ivr_manager_schedule_delete', 'temp');

		//clear the cache
		if (!empty($dialplan_contexts)) {
			$dialplan_contexts = array_unique($dialplan_contexts, SORT_STRING);
			$cache = new cache;
			foreach ($dialplan_contexts as $dialplan_context) {
				$cache->delete("dialplan:".$dialplan_context);
			}
		}

		//clear the destinations session array
		if (isset($_SESSION['destinations']['array'])) {
			unset($_SESSION['destinations']['array']);
		}

		return true;
	}

	/**
	 * Clear the cache after a time condition change (time_condition_edit.php:663-670)
	 */
	private function clear_time_condition_cache() {
		//clear the cache
			$cache = new cache;
			$cache->delete("dialplan:".$_SESSION["domain_name"]);

		//clear the destinations session array
			if (isset($_SESSION['destinations']['array'])) {
				unset($_SESSION['destinations']['array']);
			}
	}

}

?>
