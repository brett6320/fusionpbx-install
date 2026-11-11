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
 * Shared constants, settings and file paths for the carrier ACL app (docs/design/carrier_acl.md).
 *
 * The app is host-global (superadmin only): settings are read from v_default_settings only, never from domain or
 * user settings, so a domain admin can never change how the host firewall is programmed.
 */
class x_carrier_acl {

	/**
	 * declare constant variables
	 */
	const app_name = 'x_carrier_acl';
	const app_uuid = '68422d65-e03b-4506-8334-bcc8b1d924f2';

	//uuid of the default setting that caches the uuid of our access control list (declared in app_config.php)
	const acl_uuid_setting_uuid = 'e60fa85c-795a-49eb-b635-246924b43b57';

	//files shared with the root applier (section 7.3)
	const export_dir = '/var/lib/x_carrier_acl/export';
	const state_dir = '/var/lib/x_carrier_acl/state';
	const snapshot_file = self::export_dir.'/firewall.snapshot';
	const generation_file = self::export_dir.'/firewall.gen';
	const run_request_file = self::export_dir.'/run.request';
	const status_file = self::state_dir.'/status.json';

	//snapshot grammar version written by the worker; the applier also accepts 1 (section 7.3, 12)
	const snapshot_version = 2;
	const snapshot_header = 'x_carrier_acl snapshot 2';

	//registered parsers: name => class (resources/classes/parsers/<name>.php)
	const parsers = [
		'cidr_list' => 'x_carrier_acl_parser_cidr_list',
		'csv' => 'x_carrier_acl_parser_csv',
		'json_path' => 'x_carrier_acl_parser_json_path',
		'html_ip_scrape' => 'x_carrier_acl_parser_html_ip_scrape',
	];

	//setting defaults (section 4)
	const defaults = [
		'acl_name' => 'x_carrier_auto',
		'max_remove_pct' => 20,
		'max_remove_abs' => 25,
		'max_add_pct' => 100,
		'max_add_abs' => 50,
		'v4_reject_shorter_than' => 16,
		'v4_approve_shorter_than' => 20,
		'v6_reject_shorter_than' => 32,
		'v6_approve_shorter_than' => 48,
		'alert_email' => '',
		'alert_on' => 'change,failure,approval',
		'failure_alert_after' => 3,
		'stale_after_days' => 30,
		'firewall_enabled' => true,
		'firewall_mode' => 'allow',
		'firewall_sip_ports' => '5060:5091',
		'firewall_rtp_ports' => '16384:32768',
		'fail2ban_ignoreip' => true,
	];

	//run statuses
	const statuses = ['unchanged', 'applied', 'pending_approval', 'approved', 'rejected', 'superseded', 'failed'];

	//postgres advisory lock key serializing ACL projection writes between the worker and web purges
	const lock_key = 7310482952;

	/**
	 * @var database
	 */
	private $database;

	//per object cache of default settings
	private $settings = null;

	/**
	 * @param database|null $database
	 */
	public function __construct($database = null) {
		$this->database = $database ?? database::new();
	}

	/**
	 * Database object.
	 */
	public function database(): database {
		return $this->database;
	}

	/**
	 * Raw setting value from v_default_settings (category x_carrier_acl, enabled rows only).
	 */
	public function setting(string $subcategory) {
		if ($this->settings === null) {
			$this->settings = [];
			$sql = "select default_setting_subcategory, default_setting_value from v_default_settings ";
			$sql .= "where default_setting_category = 'x_carrier_acl' ";
			$sql .= "and default_setting_enabled = true ";
			$rows = $this->database->select($sql, null, 'all');
			if (is_array($rows)) {
				foreach ($rows as $row) {
					$this->settings[$row['default_setting_subcategory']] = $row['default_setting_value'];
				}
			}
		}
		if (array_key_exists($subcategory, $this->settings) && $this->settings[$subcategory] !== null && $this->settings[$subcategory] !== '') {
			return $this->settings[$subcategory];
		}
		return self::defaults[$subcategory] ?? null;
	}

	/**
	 * Drop the settings cache (after the acl_uuid setting is written).
	 */
	public function reload_settings(): void {
		$this->settings = null;
	}

	/**
	 * Non-negative integer setting.
	 */
	public function setting_int(string $subcategory): int {
		$value = $this->setting($subcategory);
		if (is_numeric($value) && (int)$value >= 0) {
			return (int)$value;
		}
		return (int)(self::defaults[$subcategory] ?? 0);
	}

	/**
	 * Boolean setting.
	 */
	public function setting_bool(string $subcategory): bool {
		$value = $this->setting($subcategory);
		if (is_bool($value)) {
			return $value;
		}
		return in_array(strtolower(trim((string)$value)), ['true', '1', 'yes', 'on'], true);
	}

	/**
	 * Name of the ACL list we own. Restricted to characters FreeSWITCH and the ESL "acl" command accept safely.
	 */
	public function acl_name(): string {
		$name = (string)$this->setting('acl_name');
		if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $name)) {
			$name = self::defaults['acl_name'];
		}
		return $name;
	}

	/**
	 * Firewall mode: allow or restrict.
	 */
	public function firewall_mode(): string {
		return ($this->setting('firewall_mode') === 'restrict') ? 'restrict' : 'allow';
	}

	/**
	 * Port range "low:high" validated as the applier does; falls back to the default on bad input.
	 */
	public function port_range(string $subcategory): string {
		$value = trim((string)$this->setting($subcategory));
		$max_width = ($subcategory === 'firewall_sip_ports') ? 100 : 32768;
		if (self::valid_port_range($value, $max_width)) {
			return $value;
		}
		self::log(LOG_WARNING, 'setting '.$subcategory.' "'.$value.'" is not accepted by the firewall applier; using '.self::defaults[$subcategory]);
		return self::defaults[$subcategory];
	}

	/**
	 * The root applier's port rules (sbin/x-carrier-acl-apply parse_ports): "low:high", 1024 <= low <= high <= 65535,
	 * at most $max_width ports, not covering a local service port.
	 */
	public static function valid_port_range(string $value, int $max_width = 32768): bool {
		if (!preg_match('/^(\d{1,5}):(\d{1,5})$/', $value, $m)) {
			return false;
		}
		$low = (int)$m[1];
		$high = (int)$m[2];
		if ($low < 1024 || $high > 65535 || $low > $high || ($high - $low + 1) > $max_width) {
			return false;
		}
		foreach ([3306, 5432, 6379, 7443, 8021, 11211] as $port) {
			if ($low <= $port && $port <= $high) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Approval thresholds for a feed: per feed values, null meaning use the global setting.
	 */
	public function thresholds(array $feed): array {
		$thresholds = [];
		foreach (['max_remove_pct', 'max_remove_abs', 'max_add_pct', 'max_add_abs'] as $name) {
			$value = $feed['feed_'.$name] ?? null;
			$thresholds[$name] = (is_numeric($value) && (int)$value >= 0) ? (int)$value : $this->setting_int($name);
		}
		return $thresholds;
	}

	/**
	 * Parser object by registered name. Returns null for an unknown name.
	 */
	public static function parser(string $name) {
		if (!array_key_exists($name, self::parsers)) {
			return null;
		}
		require_once __DIR__.'/parsers/'.$name.'.php';
		$class = self::parsers[$name];
		return new $class();
	}

	/**
	 * Decode parser options JSON. Returns [options, error].
	 */
	public static function decode_options(?string $json): array {
		$json = trim((string)$json);
		if ($json === '') {
			return [[], null];
		}
		if (strlen($json) > 65536) {
			return [null, 'options too large'];
		}
		$options = json_decode($json, true, 16);
		if (!is_array($options) || json_last_error() !== JSON_ERROR_NONE) {
			return [null, 'options are not a JSON object'];
		}
		return [$options, null];
	}

	/**
	 * Ask the worker to run now: the path unit x-carrier-acl-worker.path starts the worker when this file exists.
	 */
	public static function request_run(): bool {
		if (!is_dir(self::export_dir)) {
			return false;
		}
		$fp = @fopen(self::run_request_file, 'c');
		if ($fp === false) {
			return false;
		}
		fwrite($fp, (string)time()."\n");
		fclose($fp);
		return true;
	}

	/**
	 * Applier status (state/status.json) or null.
	 */
	public static function firewall_status(): ?array {
		if (!is_file(self::status_file) || filesize(self::status_file) > 65536) {
			return null;
		}
		$status = json_decode((string)@file_get_contents(self::status_file), true);
		return is_array($status) ? $status : null;
	}

	/**
	 * Generation last exported by the worker, or null.
	 */
	public static function exported_generation(): ?int {
		if (!is_file(self::generation_file)) {
			return null;
		}
		$value = trim((string)@file_get_contents(self::generation_file, false, null, 0, 32));
		return ctype_digit($value) ? (int)$value : null;
	}

	/**
	 * Feeds with entry counts by role (UI list).
	 */
	public function feeds(): array {
		$sql = "select f.*, ";
		$sql .= "(select count(*) from v_x_carrier_acl_entries e where e.x_carrier_acl_feed_uuid = f.x_carrier_acl_feed_uuid and e.entry_role = 'signaling') as signaling_count, ";
		$sql .= "(select count(*) from v_x_carrier_acl_entries e where e.x_carrier_acl_feed_uuid = f.x_carrier_acl_feed_uuid and e.entry_role = 'media') as media_count, ";
		$sql .= "(select count(*) from v_x_carrier_acl_runs r where r.x_carrier_acl_feed_uuid = f.x_carrier_acl_feed_uuid and r.run_status in ('pending_approval', 'approved')) as pending_count ";
		$sql .= "from v_x_carrier_acl_feeds f ";
		$sql .= "order by f.feed_name asc ";
		$rows = $this->database->select($sql, null, 'all');
		return is_array($rows) ? $rows : [];
	}

	/**
	 * One feed row or null.
	 */
	public function feed(string $feed_uuid): ?array {
		if (!is_uuid($feed_uuid)) {
			return null;
		}
		$sql = "select * from v_x_carrier_acl_feeds where x_carrier_acl_feed_uuid = :feed_uuid ";
		$parameters['feed_uuid'] = $feed_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		return is_array($row) ? $row : null;
	}

	/**
	 * Uuid of our access control list from the cached setting, or null.
	 */
	public function acl_uuid(): ?string {
		$sql = "select default_setting_value from v_default_settings ";
		$sql .= "where default_setting_uuid = :uuid ";
		$parameters['uuid'] = self::acl_uuid_setting_uuid;
		$value = $this->database->select($sql, $parameters, 'column');
		return (is_string($value) && is_uuid($value)) ? $value : null;
	}

	/**
	 * State of our access control list: ['uuid', 'exists', 'name', 'renamed'].
	 */
	public function acl_state(): array {
		$state = ['uuid' => $this->acl_uuid(), 'exists' => false, 'name' => null, 'renamed' => false];
		if ($state['uuid'] !== null) {
			$sql = "select access_control_name from v_access_controls where access_control_uuid = :uuid ";
			$parameters['uuid'] = $state['uuid'];
			$name = $this->database->select($sql, $parameters, 'column');
			if (is_string($name)) {
				$state['exists'] = true;
				$state['name'] = $name;
				$state['renamed'] = ($name !== $this->acl_name());
			}
		}
		return $state;
	}

	/**
	 * Attachment state of the apply-inbound-acl rows on the managed profiles (section 6.3).
	 * Returns a list of ['profile', 'sip_profile_uuid', 'profile_enabled', 'attached', 'enabled'].
	 */
	public function attachment_state(): array {
		$acl_name = $this->acl_name();
		$sql = "select p.sip_profile_uuid, p.sip_profile_name, p.sip_profile_enabled, ";
		$sql .= "(select count(*) from v_sip_profile_settings s where s.sip_profile_uuid = p.sip_profile_uuid ";
		$sql .= "	and s.sip_profile_setting_name = 'apply-inbound-acl' and s.sip_profile_setting_value = :acl_name) as attached, ";
		$sql .= "(select count(*) from v_sip_profile_settings s where s.sip_profile_uuid = p.sip_profile_uuid ";
		$sql .= "	and s.sip_profile_setting_name = 'apply-inbound-acl' and s.sip_profile_setting_value = :acl_name ";
		$sql .= "	and s.sip_profile_setting_enabled = true) as attached_enabled ";
		$sql .= "from v_sip_profiles p ";
		$sql .= "where p.sip_profile_name in ('internal', 'external', 'internal-ipv6', 'external-ipv6') ";
		$sql .= "order by p.sip_profile_name asc ";
		$parameters['acl_name'] = $acl_name;
		$rows = $this->database->select($sql, $parameters, 'all');
		$state = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$state[] = [
					'profile' => $row['sip_profile_name'],
					'sip_profile_uuid' => $row['sip_profile_uuid'],
					'profile_enabled' => ($row['sip_profile_enabled'] === true || $row['sip_profile_enabled'] === 'true' || $row['sip_profile_enabled'] === 't' || $row['sip_profile_enabled'] === 1),
					'attached' => ((int)$row['attached'] > 0),
					'enabled' => ((int)$row['attached_enabled'] > 0),
				];
			}
		}
		return $state;
	}

	/**
	 * Addresses of this host that a carrier list must never allow-list (section 3.4).
	 * Literal values from config.conf and the sip profile settings; $${var} values are resolved through the
	 * event socket when it is reachable.
	 */
	public function own_addresses(): array {
		global $config;
		$candidates = [];
		if (isset($config) && $config instanceof config) {
			$candidates[] = (string)$config->get('switch.event_socket.host', '');
		}
		$sql = "select distinct sip_profile_setting_value from v_sip_profile_settings ";
		$sql .= "where sip_profile_setting_name in ('sip-ip', 'ext-sip-ip', 'rtp-ip', 'ext-rtp-ip') ";
		$sql .= "and sip_profile_setting_enabled = true ";
		$rows = $this->database->select($sql, null, 'all');
		$variables = ['local_ip_v4', 'local_ip_v6'];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$value = trim((string)$row['sip_profile_setting_value']);
				if (preg_match('/^\$\$\{([A-Za-z0-9_]{1,64})\}$/', $value, $m)) {
					$variables[] = $m[1];
				}
				else {
					$candidates[] = $value;
				}
			}
		}
		//resolve freeswitch global variables
		$esl = event_socket::create();
		if ($esl !== false && $esl->is_connected()) {
			foreach (array_unique($variables) as $variable) {
				$value = event_socket::api('global_getvar '.$variable);
				if (is_string($value)) {
					$candidates[] = trim($value);
				}
			}
		}
		$addresses = [];
		foreach ($candidates as $candidate) {
			//values like "autonat:1.2.3.4" or "stun:..." carry the address after the colon for ipv4
			if (preg_match('/^(?:autonat|host|stun):(.+)$/i', $candidate, $m)) {
				$candidate = $m[1];
			}
			if ($candidate !== '' && @inet_pton($candidate) !== false) {
				$addresses[] = $candidate;
			}
		}
		return array_values(array_unique($addresses));
	}

	/**
	 * Write a line to syslog with ident x_carrier_acl and echo it for the journal.
	 */
	public static function log(int $priority, string $message): void {
		openlog('x_carrier_acl', LOG_PID, LOG_DAEMON);
		syslog($priority, $message);
		closelog();
		if (PHP_SAPI === 'cli') {
			if ($priority <= LOG_WARNING) {
				fwrite(STDERR, "x_carrier_acl: ".$message."\n");
			}
			else {
				echo "x_carrier_acl: ".$message."\n";
			}
		}
	}

}

?>
