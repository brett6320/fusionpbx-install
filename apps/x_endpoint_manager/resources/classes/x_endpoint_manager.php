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
 * Endpoint reboot queue shared by the web pages and resources/worker.php.
 *
 * Every reboot, on-demand or scheduled, is first written to v_x_endpoint_reboot_log as 'pending' and then sent by
 * process_queue(), which applies the active call check and the rate limits and records the result on the same row.
 *
 * Reboots are signaled the same way upstream app/registrations does it (registrations::switch_api 'reboot'):
 * api lua app.lua event_notify <profile> reboot <user@domain> <vendor> <host>
 */
class x_endpoint_manager {

	/**
	 * declare constant variables
	 */
	const app_name = 'x_endpoint_manager';
	const app_uuid = '663403ba-6eb8-4936-84ce-8caf56683f02';

	//postgres advisory lock key that serializes queue processing between the worker and web requests
	const lock_key = 7310482951;

	//vendors that app/switch/resources/scripts/app/event_notify/index.lua sends a reboot event-string for
	const event_notify_vendors = ['aastra', 'algo', 'avaya', 'bittel', 'cisco', 'cisco-spa', 'digium', 'fanvil', 'grandstream', 'htek', 'linksys', 'panasonic', 'poly', 'polycom', 'sangoma', 'snom', 'yealink'];

	//final states written to the log
	const statuses = ['pending', 'processing', 'sent', 'failed', 'skipped_busy', 'skipped_unregistered', 'skipped_unsupported', 'expired', 'missed'];

	/**
	 * @var database
	 */
	private $database;

	/**
	 * @var event_socket|null
	 */
	private $event_socket;

	//per run caches
	private $settings = [];
	private $domain_names = [];
	private $registrations = [];
	private $busy_users = null;
	private $busy_loaded = false;
	private $locked = false;

	/**
	 * @param array $setting_array optional 'database' and 'event_socket' objects
	 */
	public function __construct(array $setting_array = []) {
		$this->database = $setting_array['database'] ?? database::new();
		$this->event_socket = $setting_array['event_socket'] ?? null;
	}

	/**
	 * Connected event socket or null.
	 */
	private function event_socket() {
		if (!($this->event_socket instanceof event_socket)) {
			$this->event_socket = event_socket::create();
		}
		if (!$this->event_socket->is_connected()) {
			$this->event_socket->connect();
		}
		return $this->event_socket->is_connected() ? $this->event_socket : null;
	}

	/**
	 * Settings object for a domain (default settings overlaid with domain settings).
	 */
	private function domain_settings(string $domain_uuid): settings {
		if (!isset($this->settings[$domain_uuid])) {
			$this->settings[$domain_uuid] = new settings(['database' => $this->database, 'domain_uuid' => $domain_uuid]);
		}
		return $this->settings[$domain_uuid];
	}

	/**
	 * Read a non-negative integer from default settings only, so a domain cannot override it.
	 */
	private function default_setting_int(string $subcategory, int $default): int {
		$sql = "select default_setting_value from v_default_settings ";
		$sql .= "where default_setting_category = 'x_endpoint_manager' ";
		$sql .= "and default_setting_subcategory = :subcategory ";
		$sql .= "and default_setting_enabled = true ";
		$parameters['subcategory'] = $subcategory;
		$value = $this->database->select($sql, $parameters, 'column');
		return (is_numeric($value) && (int)$value >= 0) ? (int)$value : $default;
	}

	/**
	 * Read a non-negative integer from the domain settings, falling back to default settings.
	 */
	private function domain_setting_int(string $domain_uuid, string $subcategory, int $default): int {
		$value = $this->domain_settings($domain_uuid)->get('x_endpoint_manager', $subcategory, $default);
		return (is_numeric($value) && (int)$value >= 0) ? (int)$value : $default;
	}

	/**
	 * Global reboots per minute.
	 */
	public function global_limit(): int {
		return $this->default_setting_int('rate_limit_global', 20);
	}

	/**
	 * Reboots per minute for a domain, never above the global limit.
	 */
	public function domain_limit(string $domain_uuid): int {
		return min($this->domain_setting_int($domain_uuid, 'rate_limit_domain', 5), $this->global_limit());
	}

	/**
	 * Minutes a scheduled run may be late and still fire.
	 */
	public function grace_minutes(string $domain_uuid): int {
		return $this->domain_setting_int($domain_uuid, 'grace_minutes', 15);
	}

	/**
	 * Domain time zone, falling back to the server time zone.
	 */
	public function domain_time_zone(string $domain_uuid): DateTimeZone {
		$time_zone = $this->domain_settings($domain_uuid)->get('domain', 'time_zone', date_default_timezone_get());
		try {
			return new DateTimeZone((string)$time_zone);
		} catch (Exception $e) {
			return new DateTimeZone(date_default_timezone_get());
		}
	}

	/**
	 * Domain name for a domain uuid.
	 */
	public function domain_name(string $domain_uuid): string {
		if (!isset($this->domain_names[$domain_uuid])) {
			$sql = "select domain_name from v_domains where domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $domain_uuid;
			$this->domain_names[$domain_uuid] = (string)$this->database->select($sql, $parameters, 'column');
		}
		return $this->domain_names[$domain_uuid];
	}

	/**
	 * Current registrations for a domain using the upstream registrations class, or null when the switch is
	 * unavailable. The class filters to the given domain because show is 'local'.
	 */
	public function get_registrations(string $domain_uuid): ?array {
		if (!array_key_exists($domain_uuid, $this->registrations)) {
			$event_socket = $this->event_socket();
			if ($event_socket === null) {
				return null;
			}
			$obj = new registrations([
				'database' => $this->database,
				'event_socket' => $event_socket,
				'domain_uuid' => $domain_uuid,
				'domain_name' => $this->domain_name($domain_uuid),
			]);
			$obj->show = 'local';
			$rows = $obj->get('all');
			$this->registrations[$domain_uuid] = is_array($rows) ? array_values($rows) : [];
		}
		return $this->registrations[$domain_uuid];
	}

	/**
	 * Set of lower case user@domain values that are on an active channel, or null when it cannot be determined.
	 *
	 * Source: 'show channels as json' (as app/calls_active does). A user is busy when it is the channel's
	 * presence_id (set for calls to and from the user) or the user@domain part of a sofia channel name
	 * (sofia/<profile>/<user>@<domain>, calls placed by the phone).
	 */
	public function get_busy_users(): ?array {
		if ($this->busy_loaded) {
			return $this->busy_users;
		}
		$this->busy_loaded = true;
		$this->busy_users = null;

		$event_socket = $this->event_socket();
		if ($event_socket === null) {
			return null;
		}
		$json = $event_socket->request('api show channels as json');
		if (!is_string($json)) {
			return null;
		}
		$result = json_decode(trim($json), true);
		if (!is_array($result)) {
			return null;
		}

		$busy = [];
		foreach ($result['rows'] ?? [] as $row) {
			if (!empty($row['presence_id']) && substr_count($row['presence_id'], '@') == 1) {
				$busy[strtolower($row['presence_id'])] = true;
			}
			if (!empty($row['name']) && preg_match('#^sofia/[^/]+/([^@/]+@[^:;/>]+)#', $row['name'], $matches)) {
				$busy[strtolower($matches[1])] = true;
			}
		}
		$this->busy_users = $busy;
		return $this->busy_users;
	}

	/**
	 * Enabled line users (user@domain, lower case) per device for a domain, ordered by line number.
	 *
	 * @return array device_uuid => [user@domain, ...]
	 */
	public function get_device_users(string $domain_uuid, array $device_uuids = []): array {
		$sql = "select device_uuid, user_id from v_device_lines ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and (enabled is null or enabled = true) ";
		$sql .= "and user_id is not null and user_id <> '' ";
		$sql .= "order by device_uuid asc, line_number asc ";
		$parameters['domain_uuid'] = $domain_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');

		$domain_name = strtolower($this->domain_name($domain_uuid));
		$users = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (!empty($device_uuids) && !in_array($row['device_uuid'], $device_uuids, true)) {
					continue;
				}
				$user = strtolower(trim($row['user_id'])).'@'.$domain_name;
				if (!in_array($user, $users[$row['device_uuid']] ?? [], true)) {
					$users[$row['device_uuid']][] = $user;
				}
			}
		}
		return $users;
	}

	/**
	 * Registrations matching any of the given users, in the order of the users.
	 */
	public function match_registrations(array $registrations, array $users): array {
		$matches = [];
		foreach ($users as $user) {
			foreach ($registrations as $registration) {
				if (strtolower($registration['user'] ?? '') === $user) {
					$matches[] = $registration;
				}
			}
		}
		return $matches;
	}

	/**
	 * Devices in a domain, optionally limited to a list of uuids and to enabled devices. Always domain filtered.
	 */
	public function get_devices(string $domain_uuid, ?array $device_uuids = null, bool $enabled_only = false): array {
		$sql = "select device_uuid, device_address, device_label, device_vendor, device_model, cast(device_enabled as text) as device_enabled ";
		$sql .= "from v_devices ";
		$sql .= "where domain_uuid = :domain_uuid ";
		if ($enabled_only) {
			$sql .= "and device_enabled = true ";
		}
		$parameters['domain_uuid'] = $domain_uuid;
		if ($device_uuids !== null) {
			$uuids = array_values(array_unique(array_filter($device_uuids, 'is_uuid')));
			if (empty($uuids)) {
				return [];
			}
			$placeholders = [];
			foreach ($uuids as $i => $uuid) {
				$placeholders[] = ':device_uuid_'.$i;
				$parameters['device_uuid_'.$i] = $uuid;
			}
			$sql .= "and device_uuid in (".implode(', ', $placeholders).") ";
		}
		$sql .= "order by device_address asc ";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Queue reboots for devices in a domain.
	 *
	 * @param string     $domain_uuid  domain the devices must belong to
	 * @param array      $device_uuids devices to reboot (filtered to the domain)
	 * @param string     $trigger      manual or schedule
	 * @param array      $requester    ['user_uuid' => ..., 'username' => ...]
	 * @param array|null $schedule     schedule row when queued by a schedule
	 * @param bool       $enabled_only skip disabled devices
	 *
	 * @return array log uuids of the queued rows (devices that already have a queued reboot are not queued twice)
	 */
	public function enqueue(string $domain_uuid, array $device_uuids, string $trigger, array $requester, ?array $schedule = null, bool $enabled_only = false): array {
		if (!is_uuid($domain_uuid) || empty($device_uuids)) {
			return [];
		}
		$devices = $this->get_devices($domain_uuid, $device_uuids, $enabled_only);
		if (empty($devices)) {
			return [];
		}

		//devices that already have a queued reboot
		$sql = "select device_uuid from v_x_endpoint_reboot_log ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and reboot_status in ('pending', 'processing') ";
		$sql .= "and device_uuid is not null ";
		$parameters['domain_uuid'] = $domain_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);
		$queued = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$queued[$row['device_uuid']] = true;
			}
		}

		$log_uuids = [];
		foreach ($devices as $device) {
			if (isset($queued[$device['device_uuid']])) {
				continue;
			}
			$log_uuid = uuid();
			$this->log_insert([
				'x_endpoint_reboot_log_uuid' => $log_uuid,
				'domain_uuid' => $domain_uuid,
				'x_endpoint_reboot_schedule_uuid' => $schedule['x_endpoint_reboot_schedule_uuid'] ?? null,
				'schedule_name' => $schedule['schedule_name'] ?? null,
				'reboot_trigger' => ($trigger === 'schedule' ? 'schedule' : 'manual'),
				'requested_by_user_uuid' => is_uuid($requester['user_uuid'] ?? '') ? $requester['user_uuid'] : null,
				'requested_by_username' => $requester['username'] ?? null,
				'device_uuid' => $device['device_uuid'],
				'device_address' => $device['device_address'],
				'device_label' => $device['device_label'],
				'device_vendor' => $device['device_vendor'],
				'device_model' => $device['device_model'],
				'reboot_status' => 'pending',
				'reboot_detail' => null,
			]);
			$log_uuids[] = $log_uuid;
		}
		return $log_uuids;
	}

	/**
	 * Insert a log row. Keys are a fixed whitelist of columns.
	 */
	private function log_insert(array $fields): bool {
		$columns = ['x_endpoint_reboot_log_uuid', 'domain_uuid', 'x_endpoint_reboot_schedule_uuid', 'schedule_name', 'reboot_trigger',
			'requested_by_user_uuid', 'requested_by_username', 'device_uuid', 'device_address', 'device_label', 'device_vendor',
			'device_model', 'reboot_status', 'reboot_detail'];
		$parameters = [];
		foreach ($columns as $column) {
			$parameters[$column] = $fields[$column] ?? null;
		}
		$parameters['insert_user'] = $parameters['requested_by_user_uuid'];
		$is_final = ($parameters['reboot_status'] !== 'pending');

		$sql = "insert into v_x_endpoint_reboot_log (";
		$sql .= implode(', ', $columns).", request_date, process_date, insert_date, insert_user";
		$sql .= ") values (";
		$sql .= ":".implode(', :', $columns).", now(), ".($is_final ? "now()" : "null").", now(), :insert_user";
		$sql .= ") ";
		return $this->database->execute($sql, $parameters) !== false;
	}

	/**
	 * Take the queue lock. Returns false when another process holds it.
	 */
	private function lock(): bool {
		$result = $this->database->select("select case when pg_try_advisory_lock(".self::lock_key.") then 1 else 0 end", null, 'column');
		if ($result === false || $result === null) {
			//advisory locks are not available (not postgres); the per row claim still prevents double sends
			$this->locked = false;
			return true;
		}
		$this->locked = ((int)$result === 1);
		return $this->locked;
	}

	/**
	 * Release the queue lock.
	 */
	private function unlock(): void {
		if ($this->locked) {
			$this->database->select("select pg_advisory_unlock(".self::lock_key.")", null, 'column');
			$this->locked = false;
		}
	}

	/**
	 * Mark old queued rows as expired and rows stuck in processing (crashed run) as failed.
	 */
	private function expire_queue(): void {
		$minutes = $this->default_setting_int('queue_expire_minutes', 120);

		$sql = "update v_x_endpoint_reboot_log set ";
		$sql .= "reboot_status = 'expired', ";
		$sql .= "reboot_detail = 'Not sent within ".$minutes." minutes (rate limit or switch unavailable).', ";
		$sql .= "process_date = now(), update_date = now() ";
		$sql .= "where reboot_status = 'pending' ";
		$sql .= "and request_date < now() - interval '".$minutes." minutes' ";
		$this->database->execute($sql, null);

		$sql = "update v_x_endpoint_reboot_log set ";
		$sql .= "reboot_status = 'failed', ";
		$sql .= "reboot_detail = 'Processing was interrupted.', ";
		$sql .= "process_date = now(), update_date = now() ";
		$sql .= "where reboot_status = 'processing' ";
		$sql .= "and update_date < now() - interval '10 minutes' ";
		$this->database->execute($sql, null);
	}

	/**
	 * Reboots sent since the start of the current minute, for one domain or all.
	 */
	private function sent_this_minute(?string $domain_uuid = null): int {
		$sql = "select count(*) from v_x_endpoint_reboot_log ";
		$sql .= "where reboot_status = 'sent' ";
		$sql .= "and process_date >= date_trunc('minute', now()) ";
		$parameters = null;
		if ($domain_uuid !== null) {
			$sql .= "and domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $domain_uuid;
		}
		return (int)$this->database->select($sql, $parameters, 'column');
	}

	/**
	 * Claim a pending row so no other process sends it.
	 */
	private function claim(string $log_uuid): bool {
		$sql = "update v_x_endpoint_reboot_log set reboot_status = 'processing', update_date = now() ";
		$sql .= "where x_endpoint_reboot_log_uuid = :log_uuid ";
		$sql .= "and reboot_status = 'pending' ";
		$sql .= "returning x_endpoint_reboot_log_uuid ";
		$parameters['log_uuid'] = $log_uuid;
		$result = $this->database->execute($sql, $parameters, 'column');
		return !empty($result);
	}

	/**
	 * Write the result of a processed row.
	 */
	private function finish(string $log_uuid, array $result): void {
		$sql = "update v_x_endpoint_reboot_log set ";
		$sql .= "reboot_status = :reboot_status, ";
		$sql .= "reboot_detail = :reboot_detail, ";
		$sql .= "sip_user = :sip_user, ";
		$sql .= "sip_profile = :sip_profile, ";
		$sql .= "process_date = now(), update_date = now() ";
		$sql .= "where x_endpoint_reboot_log_uuid = :log_uuid ";
		$parameters['reboot_status'] = $result['status'];
		$parameters['reboot_detail'] = mb_substr((string)($result['detail'] ?? ''), 0, 2000);
		$parameters['sip_user'] = $result['sip_user'] ?? null;
		$parameters['sip_profile'] = $result['sip_profile'] ?? null;
		$parameters['log_uuid'] = $log_uuid;
		$this->database->execute($sql, $parameters);
	}

	/**
	 * Send the queued reboots that the rate limits allow. Rows held back stay pending for the next run.
	 *
	 * @param string|null $domain_uuid only process this domain's queue (web requests); null for all (worker)
	 *
	 * @return array counts: sent, skipped, failed, deferred and flags locked, switch_unavailable
	 */
	public function process_queue(?string $domain_uuid = null): array {
		$summary = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'deferred' => 0, 'locked' => false, 'switch_unavailable' => false];

		if (!$this->lock()) {
			$summary['locked'] = true;
			return $summary;
		}

		try {
			$this->expire_queue();

			//get the queue, oldest first
			$sql = "select * from v_x_endpoint_reboot_log ";
			$sql .= "where reboot_status = 'pending' ";
			$parameters = null;
			if ($domain_uuid !== null) {
				$sql .= "and domain_uuid = :domain_uuid ";
				$parameters['domain_uuid'] = $domain_uuid;
			}
			$sql .= "order by request_date asc, x_endpoint_reboot_log_uuid asc ";
			$rows = $this->database->select($sql, $parameters, 'all');
			if (!is_array($rows) || empty($rows)) {
				return $summary;
			}

			//fail closed: without the switch we cannot tell whether a phone is on a call, so leave the queue alone
			if ($this->event_socket() === null || $this->get_busy_users() === null) {
				$summary['switch_unavailable'] = true;
				$summary['deferred'] = count($rows);
				return $summary;
			}

			//rate limits are per calendar minute and shared by the worker and the web pages through the log
			$global_remaining = $this->global_limit() - $this->sent_this_minute();
			$domain_remaining = [];

			foreach ($rows as $row) {
				$row_domain_uuid = $row['domain_uuid'];
				if (!isset($domain_remaining[$row_domain_uuid])) {
					$domain_remaining[$row_domain_uuid] = $this->domain_limit($row_domain_uuid) - $this->sent_this_minute($row_domain_uuid);
				}
				if ($global_remaining <= 0 || $domain_remaining[$row_domain_uuid] <= 0) {
					$summary['deferred']++;
					continue;
				}
				if (!$this->claim($row['x_endpoint_reboot_log_uuid'])) {
					continue;
				}

				$result = $this->reboot_device($row);
				$this->finish($row['x_endpoint_reboot_log_uuid'], $result);

				if ($result['status'] === 'sent') {
					$summary['sent']++;
					$global_remaining--;
					$domain_remaining[$row_domain_uuid]--;
				}
				elseif ($result['status'] === 'failed') {
					$summary['failed']++;
				}
				else {
					$summary['skipped']++;
				}
			}
		}
		finally {
			$this->unlock();
		}

		return $summary;
	}

	/**
	 * Busy check and reboot for one queued device.
	 *
	 * @return array status, detail, sip_user, sip_profile
	 */
	private function reboot_device(array $row): array {
		$domain_uuid = $row['domain_uuid'];

		//make sure the device still exists in this domain
		if (empty($row['device_uuid']) || empty($this->get_devices($domain_uuid, [$row['device_uuid']]))) {
			return ['status' => 'failed', 'detail' => 'Device no longer exists in this domain.'];
		}

		//never reboot the same device twice in a short time (two requests can queue it concurrently)
		$sql = "select count(*) from v_x_endpoint_reboot_log ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and device_uuid = :device_uuid ";
		$sql .= "and x_endpoint_reboot_log_uuid <> :log_uuid ";
		$sql .= "and (reboot_status = 'processing' or (reboot_status = 'sent' and process_date > now() - interval '5 minutes')) ";
		$parameters['domain_uuid'] = $domain_uuid;
		$parameters['device_uuid'] = $row['device_uuid'];
		$parameters['log_uuid'] = $row['x_endpoint_reboot_log_uuid'];
		if ((int)$this->database->select($sql, $parameters, 'column') > 0) {
			return ['status' => 'expired', 'detail' => 'Duplicate: the device was rebooted in the last 5 minutes or is being rebooted.'];
		}
		unset($sql, $parameters);

		//line users of the device
		$users = $this->get_device_users($domain_uuid, [$row['device_uuid']])[$row['device_uuid']] ?? [];
		if (empty($users)) {
			return ['status' => 'skipped_unregistered', 'detail' => 'Device has no enabled lines.'];
		}

		//skip the device when any of its lines is on a call
		$busy = $this->get_busy_users();
		if ($busy === null) {
			return ['status' => 'failed', 'detail' => 'Active call check unavailable.'];
		}
		foreach ($users as $user) {
			if (isset($busy[$user])) {
				return ['status' => 'skipped_busy', 'detail' => 'Line '.$user.' is on an active call.', 'sip_user' => $user];
			}
		}

		//find a current registration for one of the lines
		$registrations = $this->get_registrations($domain_uuid);
		if ($registrations === null) {
			return ['status' => 'failed', 'detail' => 'Event socket unavailable.'];
		}
		$matches = $this->match_registrations($registrations, $users);
		if (empty($matches)) {
			return ['status' => 'skipped_unregistered', 'detail' => 'No current registration for '.implode(', ', $users).'.'];
		}
		$registration = $matches[0];

		//validate the arguments the same way registrations::switch_api does
		$profile = $registration['sip_profile_name'] ?? '';
		if (!preg_match('/^[a-zA-Z0-9_-]+$/u', $profile)) {
			return ['status' => 'failed', 'detail' => 'Invalid SIP profile name.'];
		}
		$user = preg_replace('#[^a-zA-Z0-9_\-\.\@]#', '', $registration['user'] ?? '');
		$host = preg_replace('#[^a-zA-Z0-9_\-\.]#', '', $registration['host'] ?? '');
		if (empty($user) || empty($host)) {
			return ['status' => 'failed', 'detail' => 'Registration is missing the user or host.', 'sip_profile' => $profile];
		}

		//vendor from the user agent (as upstream does), then from the device record
		$vendor = !empty($registration['agent']) ? (string)device::get_vendor_by_agent($registration['agent']) : '';
		if (empty($vendor)) {
			$vendor = strtolower(trim((string)($row['device_vendor'] ?? '')));
		}
		if (!in_array($vendor, self::event_notify_vendors, true)) {
			return ['status' => 'skipped_unsupported', 'detail' => 'No reboot event is defined for vendor "'.$vendor.'".', 'sip_user' => $user, 'sip_profile' => $profile];
		}

		//send the reboot
		$event_socket = $this->event_socket();
		if ($event_socket === null) {
			return ['status' => 'failed', 'detail' => 'Event socket unavailable.', 'sip_user' => $user, 'sip_profile' => $profile];
		}
		$command = "lua app.lua event_notify ".$profile." reboot ".$user." ".$vendor." ".$host;
		$response = $event_socket->request('api '.$command);
		$event_socket->request('api log notice [x_endpoint_manager] '.$command);

		if ($response === false || !is_string($response)) {
			return ['status' => 'failed', 'detail' => $command.' => no response', 'sip_user' => $user, 'sip_profile' => $profile];
		}
		$response = trim($response);
		if (strpos($response, '-ERR') === 0) {
			return ['status' => 'failed', 'detail' => $command.' => '.$response, 'sip_user' => $user, 'sip_profile' => $profile];
		}
		return ['status' => 'sent', 'detail' => $command.($response !== '' ? ' => '.$response : ''), 'sip_user' => $user, 'sip_profile' => $profile];
	}

	/**
	 * Expire the queued reboots of a schedule (used when the schedule is disabled or deleted).
	 */
	public function cancel_schedule_queue(string $domain_uuid, string $schedule_uuid, string $reason): void {
		$sql = "update v_x_endpoint_reboot_log set ";
		$sql .= "reboot_status = 'expired', ";
		$sql .= "reboot_detail = :reason, ";
		$sql .= "process_date = now(), update_date = now() ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
		$sql .= "and reboot_status = 'pending' ";
		$parameters['reason'] = $reason;
		$parameters['domain_uuid'] = $domain_uuid;
		$parameters['schedule_uuid'] = $schedule_uuid;
		$this->database->execute($sql, $parameters);
	}

	/**
	 * Count of statuses for a set of log rows in a domain.
	 */
	public function status_counts(string $domain_uuid, array $log_uuids): array {
		$counts = [];
		$log_uuids = array_values(array_filter($log_uuids, 'is_uuid'));
		if (empty($log_uuids)) {
			return $counts;
		}
		$placeholders = [];
		$parameters['domain_uuid'] = $domain_uuid;
		foreach ($log_uuids as $i => $uuid) {
			$placeholders[] = ':log_uuid_'.$i;
			$parameters['log_uuid_'.$i] = $uuid;
		}
		$sql = "select reboot_status, count(*) as total from v_x_endpoint_reboot_log ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and x_endpoint_reboot_log_uuid in (".implode(', ', $placeholders).") ";
		$sql .= "group by reboot_status ";
		$rows = $this->database->select($sql, $parameters, 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$counts[$row['reboot_status']] = (int)$row['total'];
			}
		}
		return $counts;
	}

	/**
	 * Parse a comma separated list of ISO weekdays (1-7).
	 */
	public static function parse_days($value): array {
		$days = [];
		foreach (explode(',', (string)$value) as $day) {
			$day = trim($day);
			if (ctype_digit($day) && (int)$day >= 1 && (int)$day <= 7) {
				$days[] = (int)$day;
			}
		}
		$days = array_values(array_unique($days));
		sort($days);
		return $days;
	}

	/**
	 * Next run of a schedule strictly after $after, in UTC, or null when there is none.
	 *
	 * Times are wall clock times in the domain time zone, so daily and weekly runs follow DST changes. For a
	 * one-time schedule the configured moment is returned even when it is in the past; callers decide.
	 */
	public static function next_run(array $schedule, DateTimeZone $time_zone, DateTimeImmutable $after): ?DateTimeImmutable {
		$utc = new DateTimeZone('UTC');
		if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', (string)($schedule['run_time'] ?? ''), $time)) {
			return null;
		}
		$hour = (int)$time[1];
		$minute = (int)$time[2];

		switch ($schedule['recurrence'] ?? '') {
			case 'once':
				if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', (string)($schedule['run_date'] ?? ''), $date)) {
					return null;
				}
				if (!checkdate((int)$date[2], (int)$date[3], (int)$date[1])) {
					return null;
				}
				$run = (new DateTimeImmutable('now', $time_zone))->setDate((int)$date[1], (int)$date[2], (int)$date[3])->setTime($hour, $minute, 0);
				return $run->setTimezone($utc);
			case 'daily':
				$days = [1, 2, 3, 4, 5, 6, 7];
				break;
			case 'weekly':
				$days = self::parse_days($schedule['run_days'] ?? '');
				if (empty($days)) {
					return null;
				}
				break;
			default:
				return null;
		}

		//start from noon local time so adding days never lands in a DST gap
		$base = $after->setTimezone($time_zone)->setTime(12, 0, 0);
		for ($i = 0; $i <= 7; $i++) {
			$day = $base->modify('+'.$i.' day');
			if (!in_array((int)$day->format('N'), $days, true)) {
				continue;
			}
			$candidate = $day->setTime($hour, $minute, 0);
			//compare wall clock too, so the repeated hour on a DST fall-back night does not run twice
			if ($candidate > $after && $candidate->format('Y-m-d H:i') > $after->setTimezone($time_zone)->format('Y-m-d H:i')) {
				return $candidate->setTimezone($utc);
			}
		}
		return null;
	}

	/**
	 * Next run date (UTC, database format) and status for a schedule being saved or enabled, computed from now.
	 * A one-time schedule whose moment has passed gets no next run and the status 'missed'.
	 *
	 * @return array next_run_date (string|null) and schedule_status
	 */
	public function schedule_state(array $schedule, string $domain_uuid): array {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$is_once = (($schedule['recurrence'] ?? '') === 'once');
		$next = self::next_run($schedule, $this->domain_time_zone($domain_uuid), $now);
		if ($next === null || ($is_once && $next <= $now)) {
			return ['next_run_date' => null, 'schedule_status' => ($is_once ? 'missed' : 'active')];
		}
		return ['next_run_date' => $next->format('Y-m-d H:i:sP'), 'schedule_status' => 'active'];
	}

	/**
	 * Device uuids a schedule targets (enabled devices in the schedule's domain only).
	 */
	public function schedule_device_uuids(array $schedule): array {
		$parameters['domain_uuid'] = $schedule['domain_uuid'];
		switch ($schedule['target_type'] ?? '') {
			case 'all':
				$sql = "select device_uuid from v_devices ";
				$sql .= "where domain_uuid = :domain_uuid ";
				$sql .= "and device_enabled = true ";
				break;
			case 'vendor':
				if (empty($schedule['target_vendor'])) {
					return [];
				}
				$sql = "select device_uuid from v_devices ";
				$sql .= "where domain_uuid = :domain_uuid ";
				$sql .= "and device_enabled = true ";
				$sql .= "and lower(device_vendor) = lower(:target_vendor) ";
				$parameters['target_vendor'] = $schedule['target_vendor'];
				if (!empty($schedule['target_model'])) {
					$sql .= "and lower(device_model) = lower(:target_model) ";
					$parameters['target_model'] = $schedule['target_model'];
				}
				break;
			case 'devices':
				$sql = "select d.device_uuid from v_x_endpoint_reboot_targets as t ";
				$sql .= "join v_devices as d on d.device_uuid = t.device_uuid and d.domain_uuid = t.domain_uuid ";
				$sql .= "where t.domain_uuid = :domain_uuid ";
				$sql .= "and t.x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
				$sql .= "and d.device_enabled = true ";
				$parameters['schedule_uuid'] = $schedule['x_endpoint_reboot_schedule_uuid'];
				break;
			default:
				return [];
		}
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? array_column($rows, 'device_uuid') : [];
	}

	/**
	 * Run schedules that are due (worker only).
	 *
	 * A run found later than the grace window (e.g. the server was down) is logged as missed and not fired, and the
	 * next run is always computed from now, so downtime never causes a burst of catch-up reboots.
	 *
	 * @return array counts: started, missed, queued
	 */
	public function run_due_schedules(): array {
		$summary = ['started' => 0, 'missed' => 0, 'queued' => 0];
		$utc = new DateTimeZone('UTC');
		$now = new DateTimeImmutable('now', $utc);

		$sql = "select s.*, ";
		$sql .= "coalesce(s.update_user, s.insert_user) as owner_uuid, ";
		$sql .= "(select username from v_users as u where u.user_uuid = coalesce(s.update_user, s.insert_user)) as owner_username ";
		$sql .= "from v_x_endpoint_reboot_schedules as s ";
		$sql .= "join v_domains as d on d.domain_uuid = s.domain_uuid ";
		$sql .= "where s.schedule_enabled = true ";
		$sql .= "and s.schedule_status = 'active' ";
		$sql .= "and (s.next_run_date is null or s.next_run_date <= now()) ";
		$sql .= "and d.domain_enabled = true ";
		$sql .= "order by s.next_run_date asc ";
		$schedules = $this->database->select($sql, null, 'all');
		if (!is_array($schedules)) {
			return $summary;
		}

		foreach ($schedules as $schedule) {
			$domain_uuid = $schedule['domain_uuid'];
			$time_zone = $this->domain_time_zone($domain_uuid);
			$is_once = ($schedule['recurrence'] === 'once');

			//no next run yet (e.g. restored data): compute it and wait for it
			if (empty($schedule['next_run_date'])) {
				$state = $this->schedule_state($schedule, $domain_uuid);
				$next = $state['next_run_date'] !== null ? new DateTimeImmutable($state['next_run_date']) : null;
				$this->update_schedule_run($schedule['x_endpoint_reboot_schedule_uuid'], $domain_uuid, $next, $state['schedule_status'], false);
				continue;
			}

			$due = new DateTimeImmutable($schedule['next_run_date']);
			$late_seconds = $now->getTimestamp() - $due->getTimestamp();
			$missed = ($late_seconds > $this->grace_minutes($domain_uuid) * 60);

			//advance the schedule before queueing so a crash can never fire it twice
			$next = $is_once ? null : self::next_run($schedule, $time_zone, $now);
			$status = $is_once ? ($missed ? 'missed' : 'completed') : 'active';
			$this->update_schedule_run($schedule['x_endpoint_reboot_schedule_uuid'], $domain_uuid, $next, $status, !$missed);

			if ($missed) {
				$this->log_insert([
					'x_endpoint_reboot_log_uuid' => uuid(),
					'domain_uuid' => $domain_uuid,
					'x_endpoint_reboot_schedule_uuid' => $schedule['x_endpoint_reboot_schedule_uuid'],
					'schedule_name' => $schedule['schedule_name'],
					'reboot_trigger' => 'schedule',
					'requested_by_user_uuid' => $schedule['owner_uuid'] ?? null,
					'requested_by_username' => $schedule['owner_username'] ?? null,
					'reboot_status' => 'missed',
					'reboot_detail' => 'Run due '.$due->setTimezone($time_zone)->format('Y-m-d H:i T').' was found '.intdiv($late_seconds, 60).' minutes late (grace '.$this->grace_minutes($domain_uuid).' minutes) and was skipped.',
				]);
				$summary['missed']++;
				continue;
			}

			$device_uuids = $this->schedule_device_uuids($schedule);
			$requester = ['user_uuid' => $schedule['owner_uuid'] ?? null, 'username' => $schedule['owner_username'] ?? null];
			$log_uuids = $this->enqueue($domain_uuid, $device_uuids, 'schedule', $requester, $schedule, true);
			$summary['started']++;
			$summary['queued'] += count($log_uuids);
		}

		return $summary;
	}

	/**
	 * Store the next run, status and optionally the last run of a schedule.
	 */
	private function update_schedule_run(string $schedule_uuid, string $domain_uuid, ?DateTimeImmutable $next, string $status, bool $ran): void {
		$sql = "update v_x_endpoint_reboot_schedules set ";
		$sql .= "next_run_date = :next_run_date, ";
		$sql .= "schedule_status = :schedule_status ";
		if ($ran) {
			$sql .= ", last_run_date = now() ";
		}
		$sql .= "where x_endpoint_reboot_schedule_uuid = :schedule_uuid ";
		$sql .= "and domain_uuid = :domain_uuid ";
		$parameters['next_run_date'] = $next !== null ? $next->format('Y-m-d H:i:sP') : null;
		$parameters['schedule_status'] = $status;
		$parameters['schedule_uuid'] = $schedule_uuid;
		$parameters['domain_uuid'] = $domain_uuid;
		$this->database->execute($sql, $parameters);
	}

}
