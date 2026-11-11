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
 * Carrier credential store: catalogue, accounts, credentials, bindings, audit.
 *
 * Every query is filtered on the object's domain unless the object was created
 * with cross-domain access (x_carrier_all). Credential and audit rows are written
 * with $database->save($array, false) so the upstream transaction log never
 * receives them. Secrets only leave this class as x_carrier_secret objects.
 */
class x_carriers {

	const app_name = 'x_carrier_credentials';
	const app_uuid = '13482363-f17a-43bb-b261-b4cd5032f5b4';

	const types = ['sip_digest', 'ip_auth', 'api_token'];
	const purposes = ['voice', 'fax', 'both'];
	const statuses = ['active', 'pending', 'retired'];
	const audit_actions = ['create', 'update', 'update_secret', 'rotate', 'reveal', 'push', 'retire', 'delete', 'key_rotate'];

	//public fields per credential type (stored as JSON, not secret)
	const public_fields = [
		'sip_digest' => ['username', 'auth_username', 'realm'],
		'ip_auth' => ['from_user', 'from_domain'],
		'api_token' => ['key_id'],
	];

	/**
	 * @var database
	 */
	private $database;

	/**
	 * @var string
	 */
	private $domain_uuid;

	/**
	 * @var string
	 */
	private $user_uuid;

	/**
	 * @var string
	 */
	private $username;

	/**
	 * @var bool cross-domain access
	 */
	private $all;

	public function __construct(array $setting_array = []) {
		$this->database = $setting_array['database'] ?? database::new();
		$this->domain_uuid = $setting_array['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? '';
		$this->user_uuid = $setting_array['user_uuid'] ?? $_SESSION['user_uuid'] ?? '';
		$this->username = $setting_array['username'] ?? $_SESSION['username'] ?? (defined('STDIN') ? 'cli' : '');
		$this->all = $setting_array['all'] ?? permission_exists('x_carrier_all');
	}

	public function domain_uuid(): string {
		return $this->domain_uuid;
	}

	public function all(): bool {
		return (bool) $this->all;
	}

	public function database(): database {
		return $this->database;
	}

	/**
	 * Returns the domain filter for a query and sets its parameter.
	 */
	private function scope(string $alias, array &$parameters): string {
		if ($this->all) {
			return '';
		}
		$parameters['scope_domain_uuid'] = $this->domain_uuid;
		return "and ".$alias."domain_uuid = :scope_domain_uuid ";
	}

	/**
	 * Grants table-level permissions needed by database::save for the duration of one call.
	 */
	public static function with_permissions(array $permissions, callable $callback) {
		$p = permissions::new();
		foreach ($permissions as $permission) {
			$p->add($permission, 'temp');
		}
		try {
			return $callback();
		}
		finally {
			foreach ($permissions as $permission) {
				$p->delete($permission, 'temp');
			}
		}
	}

	/**
	 * Saves rows without the upstream transaction log.
	 */
	public function save_rows(array $array, array $permissions): bool {
		$database = $this->database;
		$result = self::with_permissions($permissions, function () use ($database, &$array) {
			return $database->save($array, false);
		});
		return $result !== false;
	}

	/**
	 * Deletes one row by primary key without the upstream transaction log.
	 * Table and key names come only from the constants in this class.
	 */
	private function delete_row(string $table, string $key, string $uuid): bool {
		$allowed = [
			'v_x_carriers' => 'x_carrier_uuid',
			'v_x_carrier_accounts' => 'x_carrier_account_uuid',
			'v_x_carrier_credentials' => 'x_carrier_credential_uuid',
			'v_x_carrier_bindings' => 'x_carrier_binding_uuid',
		];
		if (!isset($allowed[$table]) || $allowed[$table] !== $key || !is_uuid($uuid)) {
			return false;
		}
		$sql = "delete from ".$table." where ".$key." = :uuid ";
		$parameters['uuid'] = $uuid;
		$this->database->execute($sql, $parameters);
		return true;
	}

	/**
	 * JSON helpers for the public column.
	 */
	public static function public_encode(string $type, array $values): string {
		$out = [];
		foreach (self::public_fields[$type] ?? [] as $field) {
			$out[$field] = trim((string) ($values[$field] ?? ''));
		}
		return json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	public static function public_decode(?string $json): array {
		$values = json_decode((string) $json, true);
		return is_array($values) ? $values : [];
	}

	/**
	 * True when now is inside the credential's optional validity window.
	 */
	public static function within_window(array $credential): bool {
		$now = time();
		if (!empty($credential['x_carrier_credential_not_before']) && strtotime($credential['x_carrier_credential_not_before']) > $now) {
			return false;
		}
		if (!empty($credential['x_carrier_credential_not_after']) && strtotime($credential['x_carrier_credential_not_after']) <= $now) {
			return false;
		}
		return true;
	}

	/**
	 * Normalises a datetime from a form (YYYY-MM-DD HH:MM or YYYY-MM-DDTHH:MM). Returns null when empty or invalid.
	 */
	public static function normalize_datetime(?string $value): ?string {
		$value = trim((string) $value);
		if ($value === '') {
			return null;
		}
		$value = str_replace('T', ' ', $value);
		if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
			return null;
		}
		return strtotime($value) === false ? null : $value;
	}

	// ----- catalogue -----

	public function carriers(bool $enabled_only = false): array {
		$sql = "select x_carrier_uuid, x_carrier_name, x_carrier_default_proxy, x_carrier_default_register_proxy, ";
		$sql .= "x_carrier_default_outbound_proxy, x_carrier_default_transport, x_carrier_default_codec_prefs, x_carrier_default_profile, ";
		$sql .= "cast(x_carrier_supports_registration as text), cast(x_carrier_supports_ip_auth as text), cast(x_carrier_supports_api as text), ";
		$sql .= "x_carrier_api_base_url, cast(x_carrier_enabled as text), x_carrier_description ";
		$sql .= "from v_x_carriers ";
		if ($enabled_only) {
			$sql .= "where x_carrier_enabled = true ";
		}
		$sql .= "order by x_carrier_name asc ";
		$rows = $this->database->select($sql, null, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function carrier(string $carrier_uuid): ?array {
		if (!is_uuid($carrier_uuid)) {
			return null;
		}
		foreach ($this->carriers() as $row) {
			if ($row['x_carrier_uuid'] === $carrier_uuid) {
				return $row;
			}
		}
		return null;
	}

	public function save_carrier(array $row): bool {
		$array['x_carriers'][0] = $row;
		return $this->save_rows($array, ['x_carrier_add', 'x_carrier_edit']);
	}

	/**
	 * @return string|null null on success, otherwise a language key
	 */
	public function delete_carrier(string $carrier_uuid): ?string {
		$sql = "select count(*) from v_x_carrier_accounts where x_carrier_uuid = :x_carrier_uuid ";
		$parameters['x_carrier_uuid'] = $carrier_uuid;
		if ($this->database->select($sql, $parameters, 'column') > 0) {
			return 'error-carrier_in_use';
		}
		$this->delete_row('v_x_carriers', 'x_carrier_uuid', $carrier_uuid);
		return null;
	}

	// ----- accounts -----

	public function accounts(string $search = '', bool $show_all = false): array {
		$parameters = [];
		$sql = "select a.x_carrier_account_uuid, a.domain_uuid, a.x_carrier_uuid, a.x_carrier_account_name, ";
		$sql .= "a.x_carrier_account_purpose, cast(a.x_carrier_account_enabled as text), a.x_carrier_account_description, ";
		$sql .= "c.x_carrier_name, d.domain_name, ";
		$sql .= "(select count(*) from v_x_carrier_credentials as r where r.x_carrier_account_uuid = a.x_carrier_account_uuid and r.x_carrier_credential_status <> 'retired') as credential_count ";
		$sql .= "from v_x_carrier_accounts as a ";
		$sql .= "left join v_x_carriers as c on c.x_carrier_uuid = a.x_carrier_uuid ";
		$sql .= "left join v_domains as d on d.domain_uuid = a.domain_uuid ";
		$sql .= "where true ";
		if (!($show_all && $this->all)) {
			$sql .= "and a.domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $this->domain_uuid;
		}
		if ($search !== '') {
			$sql .= "and (lower(a.x_carrier_account_name) like :search or lower(a.x_carrier_account_description) like :search or lower(c.x_carrier_name) like :search) ";
			$parameters['search'] = '%'.mb_strtolower($search).'%';
		}
		$sql .= "order by d.domain_name asc, c.x_carrier_name asc, a.x_carrier_account_name asc ";
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function account(string $account_uuid): ?array {
		if (!is_uuid($account_uuid)) {
			return null;
		}
		$parameters = [];
		$sql = "select a.x_carrier_account_uuid, a.domain_uuid, a.x_carrier_uuid, a.x_carrier_account_name, ";
		$sql .= "a.x_carrier_account_purpose, cast(a.x_carrier_account_enabled as text), a.x_carrier_account_description, ";
		$sql .= "d.domain_name ";
		$sql .= "from v_x_carrier_accounts as a ";
		$sql .= "left join v_domains as d on d.domain_uuid = a.domain_uuid ";
		$sql .= "where a.x_carrier_account_uuid = :x_carrier_account_uuid ";
		$sql .= $this->scope('a.', $parameters);
		$parameters['x_carrier_account_uuid'] = $account_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		return is_array($row) && !empty($row) ? $row : null;
	}

	public function save_account(array $row): bool {
		if (!$this->all || !is_uuid($row['domain_uuid'] ?? '')) {
			$row['domain_uuid'] = $this->domain_uuid;
		}
		$array['x_carrier_accounts'][0] = $row;
		return $this->save_rows($array, ['x_carrier_account_add', 'x_carrier_account_edit']);
	}

	/**
	 * @return string|null null on success, otherwise a language key
	 */
	public function delete_account(string $account_uuid): ?string {
		$account = $this->account($account_uuid);
		if ($account === null) {
			return 'error-not_found';
		}
		$sql = "select count(*) from v_x_carrier_credentials where x_carrier_account_uuid = :x_carrier_account_uuid ";
		$parameters['x_carrier_account_uuid'] = $account_uuid;
		if ($this->database->select($sql, $parameters, 'column') > 0) {
			return 'error-account_has_credentials';
		}
		$this->delete_row('v_x_carrier_accounts', 'x_carrier_account_uuid', $account_uuid);
		return null;
	}

	// ----- credentials -----

	/**
	 * Lists credential metadata for an account. Never selects the secret.
	 */
	public function credentials(string $account_uuid): array {
		if (!is_uuid($account_uuid)) {
			return [];
		}
		$parameters = [];
		$sql = "select x_carrier_credential_uuid, x_carrier_account_uuid, domain_uuid, x_carrier_credential_type, ";
		$sql .= "x_carrier_credential_label, x_carrier_credential_public, x_carrier_credential_key_id, ";
		$sql .= "x_carrier_credential_version, x_carrier_credential_status, x_carrier_credential_replaces_uuid, ";
		$sql .= "x_carrier_credential_not_before, x_carrier_credential_not_after, x_carrier_credential_last_pushed, ";
		$sql .= "x_carrier_credential_retired_date, insert_date, update_date, ";
		$sql .= "(case when x_carrier_credential_secret is null or x_carrier_credential_secret = '' then 'false' else 'true' end) as has_secret, ";
		$sql .= "(select count(*) from v_x_carrier_bindings as b where b.x_carrier_credential_uuid = c.x_carrier_credential_uuid) as binding_count ";
		$sql .= "from v_x_carrier_credentials as c ";
		$sql .= "where x_carrier_account_uuid = :x_carrier_account_uuid ";
		$sql .= $this->scope('c.', $parameters);
		$sql .= "order by x_carrier_credential_type asc, x_carrier_credential_status asc, x_carrier_credential_label asc ";
		$parameters['x_carrier_account_uuid'] = $account_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Returns one credential. The sealed secret column is included only when asked for.
	 */
	public function credential(string $credential_uuid, bool $with_secret = false): ?array {
		if (!is_uuid($credential_uuid)) {
			return null;
		}
		$parameters = [];
		$sql = "select x_carrier_credential_uuid, x_carrier_account_uuid, domain_uuid, x_carrier_credential_type, ";
		$sql .= "x_carrier_credential_label, x_carrier_credential_public, x_carrier_credential_key_id, ";
		$sql .= "x_carrier_credential_version, x_carrier_credential_status, x_carrier_credential_replaces_uuid, ";
		$sql .= "x_carrier_credential_not_before, x_carrier_credential_not_after, x_carrier_credential_last_pushed, ";
		$sql .= "x_carrier_credential_retired_date, insert_date, update_date ";
		if ($with_secret) {
			$sql .= ", x_carrier_credential_secret ";
		}
		$sql .= "from v_x_carrier_credentials as c ";
		$sql .= "where x_carrier_credential_uuid = :x_carrier_credential_uuid ";
		$sql .= $this->scope('c.', $parameters);
		$parameters['x_carrier_credential_uuid'] = $credential_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		return is_array($row) && !empty($row) ? $row : null;
	}

	/**
	 * Decrypts a credential row loaded with $with_secret = true.
	 */
	public function open_secret(array $credential): x_carrier_secret {
		if (empty($credential['x_carrier_credential_secret'])) {
			return new x_carrier_secret('');
		}
		return x_carrier_keyring::open($credential['x_carrier_credential_secret'], $credential['x_carrier_credential_uuid']);
	}

	/**
	 * Creates a credential. Returns the new uuid or null.
	 */
	public function create_credential(array $account, string $type, string $label, array $public, x_carrier_secret $secret, string $status = 'active', ?string $replaces_uuid = null, ?string $not_before = null, ?string $not_after = null, string $audit_action = 'create', array $audit_detail = []): ?string {
		if (!in_array($type, self::types, true) || !in_array($status, self::statuses, true)) {
			return null;
		}
		$credential_uuid = uuid();
		$key_id = x_carrier_keyring::active_key_id();
		$sealed = $secret->is_empty() ? '' : x_carrier_keyring::seal($secret, $credential_uuid, $key_id);
		$row = [];
		$row['x_carrier_credential_uuid'] = $credential_uuid;
		$row['x_carrier_account_uuid'] = $account['x_carrier_account_uuid'];
		$row['domain_uuid'] = $account['domain_uuid'];
		$row['x_carrier_credential_type'] = $type;
		$row['x_carrier_credential_label'] = $label;
		$row['x_carrier_credential_public'] = self::public_encode($type, $public);
		$row['x_carrier_credential_secret'] = $sealed;
		$row['x_carrier_credential_key_id'] = $sealed === '' ? null : $key_id;
		$row['x_carrier_credential_version'] = 1;
		$row['x_carrier_credential_status'] = $status;
		$row['x_carrier_credential_replaces_uuid'] = $replaces_uuid;
		$row['x_carrier_credential_not_before'] = $not_before;
		$row['x_carrier_credential_not_after'] = $not_after;
		$array['x_carrier_credentials'][0] = $row;
		if (!$this->save_rows($array, ['x_carrier_credential_add', 'x_carrier_credential_edit'])) {
			return null;
		}
		$detail = array_merge(['type' => $type, 'version' => 1, 'status' => $status], $audit_detail);
		if (!$secret->is_empty()) {
			$detail['fingerprint'] = x_carrier_keyring::fingerprint($secret, $key_id);
			$detail['key_id'] = $key_id;
		}
		$this->audit($audit_action, $account['x_carrier_account_uuid'], $credential_uuid, $detail, $account['domain_uuid']);
		return $credential_uuid;
	}

	/**
	 * Replaces the secret of a credential, bumps the version and audits update_secret.
	 */
	public function set_secret(array $credential, x_carrier_secret $secret, array $audit_detail = [], string $audit_action = 'update_secret'): bool {
		$key_id = x_carrier_keyring::active_key_id();
		$version = (int) $credential['x_carrier_credential_version'] + 1;
		$row['x_carrier_credential_uuid'] = $credential['x_carrier_credential_uuid'];
		$row['x_carrier_credential_secret'] = $secret->is_empty() ? '' : x_carrier_keyring::seal($secret, $credential['x_carrier_credential_uuid'], $key_id);
		$row['x_carrier_credential_key_id'] = $secret->is_empty() ? null : $key_id;
		$row['x_carrier_credential_version'] = $version;
		$array['x_carrier_credentials'][0] = $row;
		if (!$this->save_rows($array, ['x_carrier_credential_edit'])) {
			return false;
		}
		$detail = array_merge(['version_from' => (int) $credential['x_carrier_credential_version'], 'version' => $version], $audit_detail);
		if (!$secret->is_empty()) {
			$detail['fingerprint'] = x_carrier_keyring::fingerprint($secret, $key_id);
			$detail['key_id'] = $key_id;
		}
		$this->audit($audit_action, $credential['x_carrier_account_uuid'], $credential['x_carrier_credential_uuid'], $detail, $credential['domain_uuid']);
		return true;
	}

	/**
	 * Updates non-secret metadata of a credential.
	 */
	public function update_credential(string $credential_uuid, array $fields): bool {
		$allowed = ['x_carrier_credential_label', 'x_carrier_credential_public', 'x_carrier_credential_status',
			'x_carrier_credential_not_before', 'x_carrier_credential_not_after', 'x_carrier_credential_last_pushed',
			'x_carrier_credential_retired_date', 'x_carrier_credential_replaces_uuid'];
		$row['x_carrier_credential_uuid'] = $credential_uuid;
		foreach ($fields as $key => $value) {
			if (in_array($key, $allowed, true)) {
				$row[$key] = $value;
			}
		}
		$array['x_carrier_credentials'][0] = $row;
		return $this->save_rows($array, ['x_carrier_credential_edit']);
	}

	/**
	 * Clears columns that are null-able but save() skips when empty.
	 */
	public function clear_credential_columns(string $credential_uuid, array $columns): void {
		$allowed = ['x_carrier_credential_not_before', 'x_carrier_credential_not_after', 'x_carrier_credential_replaces_uuid'];
		foreach ($columns as $column) {
			if (in_array($column, $allowed, true)) {
				$sql = "update v_x_carrier_credentials set ".$column." = null where x_carrier_credential_uuid = :uuid ";
				$parameters['uuid'] = $credential_uuid;
				$this->database->execute($sql, $parameters);
				unset($parameters);
			}
		}
	}

	/**
	 * Erases the secret of a credential (retention expiry).
	 */
	public function erase_secret(string $credential_uuid): void {
		$sql = "update v_x_carrier_credentials set x_carrier_credential_secret = '', x_carrier_credential_key_id = null ";
		$sql .= "where x_carrier_credential_uuid = :uuid ";
		$parameters['uuid'] = $credential_uuid;
		$this->database->execute($sql, $parameters);
	}

	/**
	 * @return string|null null on success, otherwise a language key
	 */
	public function delete_credential(array $credential): ?string {
		if (count($this->bindings($credential['x_carrier_credential_uuid'])) > 0) {
			return 'error-credential_has_bindings';
		}
		$sql = "select count(*) from v_x_carrier_credentials where x_carrier_credential_replaces_uuid = :uuid and x_carrier_credential_status = 'pending' ";
		$parameters['uuid'] = $credential['x_carrier_credential_uuid'];
		if ($this->database->select($sql, $parameters, 'column') > 0) {
			return 'error-rotation_pending';
		}
		$this->delete_row('v_x_carrier_credentials', 'x_carrier_credential_uuid', $credential['x_carrier_credential_uuid']);
		$this->audit('delete', $credential['x_carrier_account_uuid'], $credential['x_carrier_credential_uuid'], [
			'type' => $credential['x_carrier_credential_type'],
			'version' => (int) $credential['x_carrier_credential_version'],
		], $credential['domain_uuid']);
		return null;
	}

	/**
	 * Returns the pending credential that will replace this one, if any.
	 */
	public function pending_for(string $credential_uuid): ?array {
		$parameters = [];
		$sql = "select x_carrier_credential_uuid from v_x_carrier_credentials as c ";
		$sql .= "where x_carrier_credential_replaces_uuid = :uuid and x_carrier_credential_status = 'pending' ";
		$sql .= $this->scope('c.', $parameters);
		$parameters['uuid'] = $credential_uuid;
		$uuid = $this->database->select($sql, $parameters, 'column');
		return is_uuid($uuid) ? $this->credential($uuid) : null;
	}

	/**
	 * API secret for other apps. Requires x_carrier_credential_api_use and is
	 * always limited to the session domain. Returns the active, in-window
	 * api_token secret of the account, or null.
	 */
	public static function api_secret(string $account_uuid): ?x_carrier_secret {
		if (!permission_exists('x_carrier_credential_api_use') || !is_uuid($account_uuid)) {
			return null;
		}
		$store = new x_carriers(['all' => false]);
		if ($store->account($account_uuid) === null) {
			return null;
		}
		foreach ($store->credentials($account_uuid) as $row) {
			if ($row['x_carrier_credential_type'] === 'api_token' && $row['x_carrier_credential_status'] === 'active' && self::within_window($row)) {
				$credential = $store->credential($row['x_carrier_credential_uuid'], true);
				if ($credential !== null && !empty($credential['x_carrier_credential_secret'])) {
					return $store->open_secret($credential);
				}
			}
		}
		return null;
	}

	// ----- bindings -----

	public function bindings(string $credential_uuid): array {
		if (!is_uuid($credential_uuid)) {
			return [];
		}
		$parameters = [];
		$sql = "select b.x_carrier_binding_uuid, b.x_carrier_credential_uuid, b.domain_uuid, b.x_carrier_binding_type, ";
		$sql .= "b.gateway_uuid, b.dialplan_uuid, cast(b.x_carrier_binding_managed as text), b.x_carrier_binding_order, ";
		$sql .= "cast(b.x_carrier_binding_temporary as text), b.x_carrier_binding_status, ";
		$sql .= "g.gateway, g.profile, cast(g.register as text) as register, cast(g.enabled as text) as gateway_enabled, ";
		$sql .= "d.dialplan_name, d.dialplan_order, d.dialplan_context ";
		$sql .= "from v_x_carrier_bindings as b ";
		$sql .= "left join v_gateways as g on g.gateway_uuid = b.gateway_uuid ";
		$sql .= "left join v_dialplans as d on d.dialplan_uuid = b.dialplan_uuid ";
		$sql .= "where b.x_carrier_credential_uuid = :uuid ";
		$sql .= $this->scope('b.', $parameters);
		$sql .= "order by b.x_carrier_binding_type desc, b.insert_date asc ";
		$parameters['uuid'] = $credential_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function binding(string $binding_uuid): ?array {
		if (!is_uuid($binding_uuid)) {
			return null;
		}
		$parameters = [];
		$sql = "select b.x_carrier_binding_uuid, b.x_carrier_credential_uuid, b.domain_uuid, b.x_carrier_binding_type, ";
		$sql .= "b.gateway_uuid, b.dialplan_uuid, cast(b.x_carrier_binding_managed as text), b.x_carrier_binding_order, ";
		$sql .= "cast(b.x_carrier_binding_temporary as text), b.x_carrier_binding_status ";
		$sql .= "from v_x_carrier_bindings as b ";
		$sql .= "where b.x_carrier_binding_uuid = :uuid ";
		$sql .= $this->scope('b.', $parameters);
		$parameters['uuid'] = $binding_uuid;
		$row = $this->database->select($sql, $parameters, 'row');
		return is_array($row) && !empty($row) ? $row : null;
	}

	/**
	 * All bindings that drive one outbound route, in bridge order.
	 */
	public function route_bindings(string $dialplan_uuid): array {
		if (!is_uuid($dialplan_uuid)) {
			return [];
		}
		$parameters = [];
		$sql = "select b.x_carrier_binding_uuid, b.x_carrier_credential_uuid, b.domain_uuid, b.dialplan_uuid, ";
		$sql .= "cast(b.x_carrier_binding_managed as text), b.x_carrier_binding_order, b.x_carrier_binding_status ";
		$sql .= "from v_x_carrier_bindings as b ";
		$sql .= "where b.x_carrier_binding_type = 'outbound_route' and b.dialplan_uuid = :uuid ";
		$sql .= $this->scope('b.', $parameters);
		$sql .= "order by b.x_carrier_binding_order asc, b.insert_date asc ";
		$parameters['uuid'] = $dialplan_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Outbound routes created by this app in a domain, for secondary (fail-over) bindings.
	 */
	public function managed_routes(string $domain_uuid): array {
		$parameters = [];
		$sql = "select distinct b.dialplan_uuid, d.dialplan_name, d.dialplan_order ";
		$sql .= "from v_x_carrier_bindings as b ";
		$sql .= "inner join v_dialplans as d on d.dialplan_uuid = b.dialplan_uuid ";
		$sql .= "where b.x_carrier_binding_type = 'outbound_route' and b.x_carrier_binding_managed = true ";
		$sql .= "and b.domain_uuid = :domain_uuid ";
		$sql .= $this->scope('b.', $parameters);
		$sql .= "order by d.dialplan_order asc, d.dialplan_name asc ";
		$parameters['domain_uuid'] = $domain_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	public function add_binding(array $credential, string $type, ?string $gateway_uuid, ?string $dialplan_uuid, bool $managed, int $order = 0, bool $temporary = false): ?string {
		if (!in_array($type, ['gateway', 'outbound_route'], true)) {
			return null;
		}
		$binding_uuid = uuid();
		$row['x_carrier_binding_uuid'] = $binding_uuid;
		$row['x_carrier_credential_uuid'] = $credential['x_carrier_credential_uuid'];
		$row['domain_uuid'] = $credential['domain_uuid'];
		$row['x_carrier_binding_type'] = $type;
		$row['gateway_uuid'] = $type === 'gateway' ? $gateway_uuid : null;
		$row['dialplan_uuid'] = $type === 'outbound_route' ? $dialplan_uuid : null;
		$row['x_carrier_binding_managed'] = $managed ? 'true' : 'false';
		$row['x_carrier_binding_order'] = $order;
		$row['x_carrier_binding_temporary'] = $temporary ? 'true' : 'false';
		$row['x_carrier_binding_status'] = 'ok';
		$array['x_carrier_bindings'][0] = $row;
		return $this->save_rows($array, ['x_carrier_binding_add', 'x_carrier_binding_edit']) ? $binding_uuid : null;
	}

	public function update_binding(string $binding_uuid, array $fields): bool {
		$allowed = ['x_carrier_credential_uuid', 'x_carrier_binding_status', 'x_carrier_binding_order', 'x_carrier_binding_temporary'];
		$row['x_carrier_binding_uuid'] = $binding_uuid;
		foreach ($fields as $key => $value) {
			if (in_array($key, $allowed, true)) {
				$row[$key] = $value;
			}
		}
		$array['x_carrier_bindings'][0] = $row;
		return $this->save_rows($array, ['x_carrier_binding_edit']);
	}

	public function delete_binding_row(string $binding_uuid): void {
		$this->delete_row('v_x_carrier_bindings', 'x_carrier_binding_uuid', $binding_uuid);
	}

	/**
	 * Gateways of a domain that no binding uses yet, for adoption.
	 */
	public function adoptable_gateways(string $domain_uuid): array {
		$sql = "select g.gateway_uuid, g.gateway, g.proxy, g.profile, cast(g.register as text) as register ";
		$sql .= "from v_gateways as g ";
		$sql .= "where g.domain_uuid = :domain_uuid ";
		$sql .= "and not exists (select 1 from v_x_carrier_bindings as b where b.gateway_uuid = g.gateway_uuid) ";
		$sql .= "order by g.gateway asc ";
		$parameters['domain_uuid'] = $domain_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	// ----- audit -----

	/**
	 * Writes an audit row. $detail must never contain secret material.
	 */
	public function audit(string $action, ?string $account_uuid, ?string $credential_uuid, array $detail = [], ?string $domain_uuid = null): void {
		if (!in_array($action, self::audit_actions, true)) {
			return;
		}
		$row['x_carrier_audit_uuid'] = uuid();
		$row['domain_uuid'] = is_uuid($domain_uuid ?? '') ? $domain_uuid : (is_uuid($this->domain_uuid) ? $this->domain_uuid : null);
		$row['x_carrier_account_uuid'] = is_uuid($account_uuid ?? '') ? $account_uuid : null;
		$row['x_carrier_credential_uuid'] = is_uuid($credential_uuid ?? '') ? $credential_uuid : null;
		$row['x_carrier_audit_action'] = $action;
		$row['x_carrier_audit_user_uuid'] = is_uuid($this->user_uuid) ? $this->user_uuid : null;
		$row['x_carrier_audit_username'] = $this->username;
		$row['x_carrier_audit_remote_address'] = $_SERVER['REMOTE_ADDR'] ?? 'cli';
		$row['x_carrier_audit_detail'] = json_encode($detail, JSON_UNESCAPED_SLASHES);
		$row['x_carrier_audit_date'] = 'now()';
		$array['x_carrier_audit'][0] = $row;
		$this->save_rows($array, ['x_carrier_audit_add']);
	}

	/**
	 * Date of the last secret change (create or update_secret) from the audit log.
	 */
	public function last_secret_change(string $credential_uuid): string {
		$parameters = [];
		$sql = "select max(x_carrier_audit_date) from v_x_carrier_audit as a ";
		$sql .= "where x_carrier_credential_uuid = :uuid and x_carrier_audit_action in ('create', 'update_secret', 'rotate') ";
		$sql .= $this->scope('a.', $parameters);
		$parameters['uuid'] = $credential_uuid;
		return (string) $this->database->select($sql, $parameters, 'column');
	}

	public function audit_count(string $account_uuid = '', bool $show_all = false): int {
		$parameters = [];
		$sql = "select count(*) from v_x_carrier_audit where true ";
		if (!($show_all && $this->all)) {
			$sql .= "and domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $this->domain_uuid;
		}
		if (is_uuid($account_uuid)) {
			$sql .= "and x_carrier_account_uuid = :account_uuid ";
			$parameters['account_uuid'] = $account_uuid;
		}
		return (int) $this->database->select($sql, $parameters, 'column');
	}

	public function audit_rows(string $account_uuid = '', bool $show_all = false, int $limit = 50, int $offset = 0): array {
		$parameters = [];
		$sql = "select a.x_carrier_audit_uuid, a.domain_uuid, a.x_carrier_account_uuid, a.x_carrier_credential_uuid, ";
		$sql .= "a.x_carrier_audit_action, a.x_carrier_audit_username, a.x_carrier_audit_remote_address, ";
		$sql .= "a.x_carrier_audit_detail, a.x_carrier_audit_date, d.domain_name, ac.x_carrier_account_name ";
		$sql .= "from v_x_carrier_audit as a ";
		$sql .= "left join v_domains as d on d.domain_uuid = a.domain_uuid ";
		$sql .= "left join v_x_carrier_accounts as ac on ac.x_carrier_account_uuid = a.x_carrier_account_uuid ";
		$sql .= "where true ";
		if (!($show_all && $this->all)) {
			$sql .= "and a.domain_uuid = :domain_uuid ";
			$parameters['domain_uuid'] = $this->domain_uuid;
		}
		if (is_uuid($account_uuid)) {
			$sql .= "and a.x_carrier_account_uuid = :account_uuid ";
			$parameters['account_uuid'] = $account_uuid;
		}
		$sql .= "order by a.x_carrier_audit_date desc ";
		$sql .= limit_offset($limit, $offset);
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

}

?>
