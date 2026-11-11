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
 * Pushes carrier credentials into what FreeSWITCH reads: v_gateways rows and
 * outbound route bridge actions. Those copies are plaintext; only this app's
 * store is encrypted.
 *
 * Gateway writes replicate the post-save sequence of app/gateways/gateway_edit.php
 * (unlink xml when disabled, save_gateway_xml(), clear the sofia.conf cache,
 * rescan the profile, clear reload_xml). Deletions replicate
 * app/gateways/resources/classes/gateways.php::delete. All writes use
 * save($array, false), so this app adds nothing to the transaction log.
 *
 * save_gateway_xml() reads global $domain_uuid, $_SESSION['domain_name'] and
 * $_SESSION['domains'], so every push runs inside with_domain() for the
 * account's domain.
 */
class x_carrier_push {

	/**
	 * @var x_carriers
	 */
	private $store;

	/**
	 * @var database
	 */
	private $database;

	/**
	 * @var x_carrier_routes
	 */
	private $routes;

	/**
	 * @var array profile => true, profiles to rescan
	 */
	private $rescan = [];

	/**
	 * @var array gateway_uuid => profile, gateways to killgw before rescan
	 */
	private $killgw = [];

	/**
	 * @var bool gateways changed, xml must be regenerated
	 */
	private $gateways_changed = false;

	public function __construct(x_carriers $store) {
		$this->store = $store;
		$this->database = $store->database();
		$this->routes = new x_carrier_routes($this->database);
	}

	public function routes(): x_carrier_routes {
		return $this->routes;
	}

	/**
	 * Runs a callback with the FusionPBX domain globals set to the given domain.
	 */
	public function with_domain(string $domain_uuid, callable $callback) {
		$saved_global = $GLOBALS['domain_uuid'] ?? null;
		$saved_session_uuid = $_SESSION['domain_uuid'] ?? null;
		$saved_session_name = $_SESSION['domain_name'] ?? null;
		$saved_domains = $_SESSION['domains'] ?? null;

		$sql = "select domain_uuid, domain_name from v_domains ";
		$domains = $this->database->select($sql, null, 'all');
		$domain_name = null;
		$session_domains = [];
		foreach ((array) $domains as $row) {
			$session_domains[$row['domain_uuid']] = $row;
			if ($row['domain_uuid'] === $domain_uuid) {
				$domain_name = $row['domain_name'];
			}
		}
		if ($domain_name === null) {
			throw new RuntimeException('Domain not found.');
		}
		if (empty($_SESSION['domains'])) {
			$_SESSION['domains'] = $session_domains;
		}
		//a fatal error skips finally; restore the session domain at shutdown as well
		$restored = false;
		register_shutdown_function(function () use (&$restored, $saved_session_uuid, $saved_session_name) {
			if (!$restored) {
				$_SESSION['domain_uuid'] = $saved_session_uuid;
				$_SESSION['domain_name'] = $saved_session_name;
			}
		});
		$GLOBALS['domain_uuid'] = $domain_uuid;
		$_SESSION['domain_uuid'] = $domain_uuid;
		$_SESSION['domain_name'] = $domain_name;
		try {
			return $callback($domain_name);
		}
		finally {
			$restored = true;
			$GLOBALS['domain_uuid'] = $saved_global;
			$_SESSION['domain_uuid'] = $saved_session_uuid;
			$_SESSION['domain_name'] = $saved_session_name;
			if ($saved_domains === null) {
				unset($_SESSION['domains']);
			}
			else {
				$_SESSION['domains'] = $saved_domains;
			}
		}
	}

	/**
	 * Loads the credential, its account and carrier (scoped). Returns null when not visible.
	 */
	private function context(string $credential_uuid): ?array {
		$credential = $this->store->credential($credential_uuid, true);
		if ($credential === null) {
			return null;
		}
		$account = $this->store->account($credential['x_carrier_account_uuid']);
		if ($account === null) {
			return null;
		}
		$carrier = $this->store->carrier((string) $account['x_carrier_uuid']);
		return ['credential' => $credential, 'account' => $account, 'carrier' => $carrier ?? []];
	}

	/**
	 * Whether FreeSWITCH should use this credential now.
	 */
	private static function effective_enabled(array $account, array $credential): bool {
		return $account['x_carrier_account_enabled'] === 'true'
			&& in_array($credential['x_carrier_credential_status'], ['active', 'pending'], true)
			&& x_carriers::within_window($credential);
	}

	/**
	 * Gateway display name: <carrier>-<account>-<label>.
	 */
	public static function gateway_name(array $carrier, array $account, string $label): string {
		$name = ($carrier['x_carrier_name'] ?? 'carrier').'-'.$account['x_carrier_account_name'].'-'.$label;
		$name = preg_replace('/\s+/', '-', $name);
		return substr(preg_replace('/[^A-Za-z0-9._@-]/', '', $name), 0, 128);
	}

	public static function valid_profile(string $profile): bool {
		return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $profile) === 1;
	}

	/**
	 * Pushes one credential to all its bindings.
	 *
	 * @param x_carrier_secret|null $previous previous secret when the secret just changed;
	 *        used to tell an expected bridge string from one a human edited (drift)
	 * @param bool $force overwrite drifted routes (explicit re-push)
	 * @return array result with keys ok, gateways, routes, drifted, errors
	 */
	public function push(string $credential_uuid, ?x_carrier_secret $previous = null, bool $force = false): array {
		$result = ['ok' => false, 'gateways' => [], 'routes' => [], 'drifted' => [], 'errors' => []];
		$context = $this->context($credential_uuid);
		if ($context === null) {
			$result['errors'][] = 'not_found';
			return $result;
		}
		$credential = $context['credential'];
		$account = $context['account'];
		$carrier = $context['carrier'];
		$secret = $this->store->open_secret($credential);
		$enabled = self::effective_enabled($account, $credential);

		$this->with_domain($account['domain_uuid'], function ($domain_name) use (&$result, $credential, $account, $carrier, $secret, $previous, $force, $enabled) {
			$route_uuids = [];
			foreach ($this->store->bindings($credential['x_carrier_credential_uuid']) as $binding) {
				if ($binding['x_carrier_binding_type'] === 'gateway') {
					$gateway_uuid = $this->push_gateway($binding, $credential, $account, $carrier, $secret, $enabled, $previous !== null);
					if ($gateway_uuid === null) {
						$result['errors'][] = 'gateway_missing';
					}
					else {
						$result['gateways'][] = $gateway_uuid;
					}
				}
				elseif ($binding['x_carrier_binding_type'] === 'outbound_route' && is_uuid($binding['dialplan_uuid'])) {
					$route_uuids[$binding['dialplan_uuid']] = true;
				}
			}
			$this->apply_gateway_changes();
			foreach (array_keys($route_uuids) as $dialplan_uuid) {
				$state = $this->push_route($dialplan_uuid, $credential['x_carrier_credential_uuid'], $previous, $force);
				if ($state === 'ok') {
					$result['routes'][] = $dialplan_uuid;
				}
				elseif ($state === 'drifted') {
					$result['drifted'][] = $dialplan_uuid;
				}
				else {
					$result['errors'][] = 'route_'.$state;
				}
			}
		});

		$result['ok'] = empty($result['errors']) && empty($result['drifted']);
		//recorded on every completed push (outcome is in the audit row), so validity-window maintenance does not repeat it
		$this->store->update_credential($credential['x_carrier_credential_uuid'], ['x_carrier_credential_last_pushed' => 'now()']);
		$detail = [
			'version' => (int) $credential['x_carrier_credential_version'],
			'gateways' => $result['gateways'],
			'routes' => $result['routes'],
			'drifted' => $result['drifted'],
			'errors' => $result['errors'],
			'enabled' => $enabled,
			'forced' => $force,
			'outcome' => $result['ok'] ? 'ok' : 'incomplete',
		];
		if (!$secret->is_empty() && !empty($credential['x_carrier_credential_key_id'])) {
			$detail['fingerprint'] = x_carrier_keyring::fingerprint($secret, $credential['x_carrier_credential_key_id']);
		}
		$this->store->audit('push', $account['x_carrier_account_uuid'], $credential['x_carrier_credential_uuid'], $detail, $account['domain_uuid']);
		$secret->wipe();
		return $result;
	}

	/**
	 * Builds and saves the v_gateways row for one gateway binding.
	 * Managed rows get carrier defaults plus identity; adopted rows only get
	 * the credential columns. Returns the gateway uuid or null.
	 */
	private function push_gateway(array $binding, array $credential, array $account, array $carrier, x_carrier_secret $secret, bool $enabled, bool $secret_changed): ?string {
		$gateway_uuid = $binding['gateway_uuid'];
		$sql = "select gateway_uuid, gateway, profile, cast(register as text) as register, cast(enabled as text) as enabled, password ";
		$sql .= "from v_gateways where gateway_uuid = :gateway_uuid and domain_uuid = :domain_uuid ";
		$parameters['gateway_uuid'] = $gateway_uuid;
		$parameters['domain_uuid'] = $account['domain_uuid'];
		$existing = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		if (empty($existing)) {
			return null;
		}
		$managed = $binding['x_carrier_binding_managed'] === 'true';
		$public = x_carriers::public_decode($credential['x_carrier_credential_public']);
		$type = $credential['x_carrier_credential_type'];

		$row = [];
		$row['gateway_uuid'] = $gateway_uuid;
		$row['domain_uuid'] = $account['domain_uuid'];
		if ($type === 'sip_digest') {
			$row['username'] = $public['username'] ?? '';
			$row['auth_username'] = $public['auth_username'] ?? '';
			$row['realm'] = $public['realm'] ?? '';
			$row['password'] = $secret->reveal();
			$register = $existing['register'] === 'true' ? 'true' : 'false';
			$row['register'] = $register;
		}
		elseif ($type === 'ip_auth') {
			$row['from_user'] = $public['from_user'] ?? '';
			$row['from_domain'] = $public['from_domain'] ?? '';
			$row['password'] = '';
			$row['register'] = 'false';
			$register = 'false';
		}
		else {
			return null;
		}
		$profile = !empty($existing['profile']) ? $existing['profile'] : ($carrier['x_carrier_default_profile'] ?? 'external');
		if (!self::valid_profile($profile)) {
			$profile = 'external';
		}
		if ($managed) {
			$row['gateway'] = self::gateway_name($carrier, $account, $credential['x_carrier_credential_label'].($binding['x_carrier_binding_temporary'] === 'true' ? '-rotating-in' : ''));
			$row['proxy'] = $carrier['x_carrier_default_proxy'] ?? '';
			$row['register_proxy'] = $carrier['x_carrier_default_register_proxy'] ?? '';
			$row['outbound_proxy'] = $carrier['x_carrier_default_outbound_proxy'] ?? '';
			$row['register_transport'] = $carrier['x_carrier_default_transport'] ?? '';
			$row['codec_prefs'] = $carrier['x_carrier_default_codec_prefs'] ?? '';
			$row['profile'] = $profile;
			$row['enabled'] = $enabled ? 'true' : 'false';
			$row['description'] = 'Managed by Carrier Credentials';
		}
		$gateway_enabled = $managed ? $enabled : ($existing['enabled'] === 'true');
		$gateway_name = $managed ? $row['gateway'] : $existing['gateway'];

		$array['gateways'][0] = $row;
		$saved = $this->store->save_rows($array, ['gateway_add', 'gateway_edit']);
		unset($array, $row);
		if (!$saved) {
			return null;
		}

		//update gateway session variable, gateway_edit.php
		if ($gateway_enabled) {
			$_SESSION['gateways'][$gateway_uuid] = $gateway_name;
		}
		else {
			unset($_SESSION['gateways'][$gateway_uuid]);
		}

		//remove xml file (if any) if not enabled, gateway_edit.php
		if (!$gateway_enabled) {
			$this->unlink_gateway_xml($profile, $gateway_uuid);
		}

		//a registering gateway needs killgw to re-register with a changed password
		if (($secret_changed && $register === 'true') || !$gateway_enabled) {
			$this->killgw[$gateway_uuid] = $profile;
		}
		$this->rescan[$profile] = true;
		$this->gateways_changed = true;
		return $gateway_uuid;
	}

	private function unlink_gateway_xml(string $profile, string $gateway_uuid): void {
		global $settings;
		$sip_profiles = $settings->get('switch', 'sip_profiles');
		if (!empty($sip_profiles) && self::valid_profile($profile) && is_uuid($gateway_uuid)) {
			$gateway_xml_file = $sip_profiles."/".$profile."/v_".$gateway_uuid.".xml";
			if (file_exists($gateway_xml_file)) {
				unlink($gateway_xml_file);
			}
		}
	}

	/**
	 * Post-save sequence from gateway_edit.php: save_gateway_xml(), clear the
	 * sofia.conf cache, killgw where needed, rescan each profile, clear reload_xml.
	 * ESL commands carry only uuids and profile names.
	 */
	public function apply_gateway_changes(): void {
		if (!$this->gateways_changed) {
			return;
		}
		save_gateway_xml();
		$cache = new cache;
		$cache->delete(gethostname().":configuration:sofia.conf");
		$esl = event_socket::create();
		if ($esl && $esl->is_connected()) {
			foreach ($this->killgw as $gateway_uuid => $profile) {
				event_socket::api('sofia profile '.$profile.' killgw '.$gateway_uuid);
			}
			foreach (array_keys($this->rescan) as $profile) {
				event_socket::api('sofia profile '.$profile.' rescan');
			}
			usleep(1000);
		}
		$_SESSION["reload_xml"] = false;
		$this->killgw = [];
		$this->rescan = [];
		$this->gateways_changed = false;
	}

	/**
	 * Gateway used by a credential's route bridges: its first gateway binding.
	 */
	private function credential_gateway(string $credential_uuid): ?string {
		foreach ($this->store->bindings($credential_uuid) as $binding) {
			if ($binding['x_carrier_binding_type'] === 'gateway' && is_uuid($binding['gateway_uuid'])) {
				return $binding['gateway_uuid'];
			}
		}
		return null;
	}

	/**
	 * Expected bridge list for a route from its bindings. When $previous is set,
	 * the credential $changed_uuid contributes its previous prefix instead.
	 * $exclude drops one binding (for removal).
	 */
	private function expected_bridges(array $route_bindings, ?string $changed_uuid = null, ?x_carrier_secret $previous = null, ?string $exclude = null, bool $include_disabled = false): ?array {
		$bridges = [];
		foreach ($route_bindings as $binding) {
			if ($exclude !== null && $binding['x_carrier_binding_uuid'] === $exclude) {
				continue;
			}
			$credential = $this->store->credential($binding['x_carrier_credential_uuid'], true);
			if ($credential === null) {
				return null;
			}
			//a retired, disabled or out-of-window credential contributes no bridge
			if (!$include_disabled) {
				$account = $this->store->account($credential['x_carrier_account_uuid']);
				if ($account === null || !self::effective_enabled($account, $credential)) {
					continue;
				}
			}
			$gateway_uuid = $this->credential_gateway($credential['x_carrier_credential_uuid']);
			if ($gateway_uuid === null) {
				return null;
			}
			$prefix = '';
			if ($credential['x_carrier_credential_type'] === 'ip_auth') {
				if ($previous !== null && $credential['x_carrier_credential_uuid'] === $changed_uuid) {
					$prefix = $previous->reveal();
				}
				else {
					$secret = $this->store->open_secret($credential);
					$prefix = $secret->reveal();
					$secret->wipe();
				}
				//an ip_auth bridge without its prefix would route on the wrong account
				if ($prefix === '' || !x_carrier_routes::valid_prefix($prefix)) {
					return null;
				}
			}
			$bridges[] = x_carrier_routes::bridge_data($gateway_uuid, $prefix);
		}
		return $bridges;
	}

	/**
	 * Route state: ok, drifted, missing or error.
	 */
	public function route_state(string $dialplan_uuid): string {
		$current = $this->routes->bridges($dialplan_uuid);
		if ($current === null) {
			return 'missing';
		}
		try {
			$expected = $this->expected_bridges($this->store->route_bindings($dialplan_uuid));
		}
		catch (Throwable $e) {
			return 'error';
		}
		if ($expected === null) {
			return 'error';
		}
		return x_carrier_routes::bridge_values($current) === $expected ? 'ok' : 'drifted';
	}

	/**
	 * Pushes the bridge list of one route. A route a human changed (current
	 * matches neither the previous nor the new derived list) is never
	 * overwritten unless forced; its bindings are marked drifted.
	 * $exclude: binding being removed. $added: binding just added.
	 */
	public function push_route(string $dialplan_uuid, ?string $changed_uuid = null, ?x_carrier_secret $previous = null, bool $force = false, ?string $exclude = null, ?string $added = null): string {
		$current = $this->routes->bridges($dialplan_uuid);
		if ($current === null) {
			return 'missing';
		}
		$current = x_carrier_routes::bridge_values($current);
		$route_bindings = $this->store->route_bindings($dialplan_uuid);
		$expected = $this->expected_bridges($route_bindings, null, null, $exclude);
		if ($expected === null) {
			return 'error';
		}
		$acceptable = [$expected];
		//the route may still carry bridges of credentials that were just disabled or retired
		$acceptable[] = $this->expected_bridges($route_bindings, null, null, $exclude, true);
		if ($previous !== null && $changed_uuid !== null) {
			$acceptable[] = $this->expected_bridges($route_bindings, $changed_uuid, $previous, $exclude);
			$acceptable[] = $this->expected_bridges($route_bindings, $changed_uuid, $previous, $exclude, true);
		}
		if ($exclude !== null) {
			//binding being removed: the route may still carry its bridge
			$acceptable[] = $this->expected_bridges($route_bindings);
		}
		if ($added !== null) {
			//binding being added: the route does not carry its bridge yet
			$acceptable[] = $this->expected_bridges($route_bindings, null, null, $added);
		}
		if (!$force && !in_array($current, $acceptable, true)) {
			foreach ($route_bindings as $binding) {
				if ($binding['x_carrier_binding_status'] !== 'drifted') {
					$this->store->update_binding($binding['x_carrier_binding_uuid'], ['x_carrier_binding_status' => 'drifted']);
				}
			}
			return 'drifted';
		}
		if ($current !== $expected && !$this->routes->set_bridges($dialplan_uuid, $expected)) {
			return 'error';
		}
		foreach ($route_bindings as $binding) {
			if ($binding['x_carrier_binding_status'] !== 'ok' && $binding['x_carrier_binding_uuid'] !== $exclude) {
				$this->store->update_binding($binding['x_carrier_binding_uuid'], ['x_carrier_binding_status' => 'ok']);
			}
		}
		return 'ok';
	}

	/**
	 * Creates a managed gateway for a credential and binds it.
	 */
	public function create_gateway_binding(array $credential, string $profile, bool $register, bool $temporary = false): ?string {
		$context = $this->context($credential['x_carrier_credential_uuid']);
		if ($context === null || !self::valid_profile($profile)) {
			return null;
		}
		if ($credential['x_carrier_credential_type'] === 'ip_auth') {
			$register = false;
		}
		$account = $context['account'];
		$gateway_uuid = uuid();
		$row['gateway_uuid'] = $gateway_uuid;
		$row['domain_uuid'] = $account['domain_uuid'];
		$row['gateway'] = self::gateway_name($context['carrier'], $account, $credential['x_carrier_credential_label'].($temporary ? '-rotating-in' : ''));
		$row['profile'] = $profile;
		$row['register'] = $register ? 'true' : 'false';
		$row['expire_seconds'] = 800;
		$row['retry_seconds'] = 30;
		$row['channels'] = 0;
		$row['context'] = 'public';
		$row['enabled'] = 'false';
		$row['description'] = 'Managed by Carrier Credentials';
		$array['gateways'][0] = $row;
		if (!$this->store->save_rows($array, ['gateway_add', 'gateway_edit'])) {
			return null;
		}
		$binding_uuid = $this->store->add_binding($credential, 'gateway', $gateway_uuid, null, true, 0, $temporary);
		if ($binding_uuid === null) {
			return null;
		}
		$this->store->audit('create', $account['x_carrier_account_uuid'], $credential['x_carrier_credential_uuid'], [
			'binding' => $binding_uuid, 'gateway_uuid' => $gateway_uuid, 'managed' => true, 'temporary' => $temporary,
		], $account['domain_uuid']);
		return $binding_uuid;
	}

	/**
	 * Adopts an existing gateway of the account's domain into a new sip_digest
	 * credential. The gateway's current password is read into the store; from
	 * then on only the credential columns are updated.
	 */
	public function adopt_gateway(array $account, string $gateway_uuid, string $label): ?string {
		$sql = "select gateway_uuid, username, auth_username, realm, password from v_gateways ";
		$sql .= "where gateway_uuid = :gateway_uuid and domain_uuid = :domain_uuid ";
		$sql .= "and not exists (select 1 from v_x_carrier_bindings as b where b.gateway_uuid = v_gateways.gateway_uuid) ";
		$parameters['gateway_uuid'] = $gateway_uuid;
		$parameters['domain_uuid'] = $account['domain_uuid'];
		$gateway = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		if (empty($gateway)) {
			return null;
		}
		$secret = new x_carrier_secret((string) $gateway['password']);
		unset($gateway['password']);
		$credential_uuid = $this->store->create_credential($account, 'sip_digest', $label, [
			'username' => $gateway['username'],
			'auth_username' => $gateway['auth_username'],
			'realm' => $gateway['realm'],
		], $secret, 'active', null, null, null, 'create', ['adopted_gateway_uuid' => $gateway_uuid]);
		$secret->wipe();
		if ($credential_uuid === null) {
			return null;
		}
		$credential = $this->store->credential($credential_uuid);
		if ($this->store->add_binding($credential, 'gateway', $gateway_uuid, null, false) === null) {
			return null;
		}
		return $credential_uuid;
	}

	/**
	 * Creates a managed outbound route for a credential, or adds the credential
	 * as a fail-over bridge to an existing managed route.
	 *
	 * @return string|null binding uuid
	 */
	public function create_route_binding(array $credential, ?string $existing_dialplan_uuid, string $name, string $expression, int $order, bool $temporary = false): ?string {
		$context = $this->context($credential['x_carrier_credential_uuid']);
		if ($context === null) {
			return null;
		}
		$account = $context['account'];
		if ($this->credential_gateway($credential['x_carrier_credential_uuid']) === null) {
			return null;
		}
		return $this->with_domain($account['domain_uuid'], function ($domain_name) use ($credential, $account, $existing_dialplan_uuid, $name, $expression, $order, $temporary, $context) {
			if (is_uuid($existing_dialplan_uuid ?? '')) {
				//secondary bridge on a route this app manages in the same domain
				$route_bindings = $this->store->route_bindings($existing_dialplan_uuid);
				if (empty($route_bindings) || $route_bindings[0]['domain_uuid'] !== $account['domain_uuid']) {
					return null;
				}
				$position = count($route_bindings);
				$binding_uuid = $this->store->add_binding($credential, 'outbound_route', null, $existing_dialplan_uuid, true, $position, $temporary);
				if ($binding_uuid === null) {
					return null;
				}
				$state = $this->push_route($existing_dialplan_uuid, null, null, false, null, $binding_uuid);
				if ($state !== 'ok') {
					//the route changed under us: undo the binding rather than overwrite
					$this->store->delete_binding_row($binding_uuid);
					return null;
				}
			}
			else {
				$secret = $this->store->open_secret($this->store->credential($credential['x_carrier_credential_uuid'], true));
				$prefix = $credential['x_carrier_credential_type'] === 'ip_auth' ? $secret->reveal() : '';
				$secret->wipe();
				if (!x_carrier_routes::valid_prefix($prefix) || !x_carrier_routes::valid_expression($expression)) {
					return null;
				}
				$gateway_uuid = $this->credential_gateway($credential['x_carrier_credential_uuid']);
				$dialplan_name = $name !== '' ? $name : self::gateway_name($context['carrier'], $account, $credential['x_carrier_credential_label']);
				$dialplan_uuid = $this->routes->create($account['domain_uuid'], $domain_name, $dialplan_name, $expression, $order, 'Managed by Carrier Credentials', [x_carrier_routes::bridge_data($gateway_uuid, $prefix)]);
				$prefix = '';
				if ($dialplan_uuid === null) {
					return null;
				}
				$binding_uuid = $this->store->add_binding($credential, 'outbound_route', null, $dialplan_uuid, true, 0, $temporary);
				if ($binding_uuid === null) {
					$this->routes->delete($dialplan_uuid);
					return null;
				}
			}
			$this->store->audit('create', $account['x_carrier_account_uuid'], $credential['x_carrier_credential_uuid'], [
				'binding' => $binding_uuid, 'dialplan_uuid' => $existing_dialplan_uuid ?? null, 'managed' => true, 'temporary' => $temporary,
			], $account['domain_uuid']);
			return $binding_uuid;
		});
	}

	/**
	 * Removes a binding. A managed gateway is deleted with its xml file; a
	 * managed route is deleted when no other binding uses it, otherwise its
	 * bridge list is rewritten without this credential. Adopted targets are left.
	 *
	 * @return string|null null on success, otherwise a language key
	 */
	public function delete_binding(array $binding): ?string {
		$credential = $this->store->credential($binding['x_carrier_credential_uuid']);
		if ($credential === null) {
			return 'error-not_found';
		}
		$account = $this->store->account($credential['x_carrier_account_uuid']);
		if ($account === null) {
			return 'error-not_found';
		}
		$managed = $binding['x_carrier_binding_managed'] === 'true';

		return $this->with_domain($account['domain_uuid'], function ($domain_name) use ($binding, $credential, $account, $managed) {
			if ($binding['x_carrier_binding_type'] === 'gateway') {
				//routes of this credential bridge through its gateway
				$gateway_bindings = 0;
				$route_bindings = 0;
				foreach ($this->store->bindings($credential['x_carrier_credential_uuid']) as $row) {
					if ($row['x_carrier_binding_type'] === 'gateway') {
						$gateway_bindings++;
					}
					else {
						$route_bindings++;
					}
				}
				if ($route_bindings > 0 && $gateway_bindings <= 1) {
					return 'error-gateway_has_routes';
				}
				if ($managed && is_uuid($binding['gateway_uuid'])) {
					$this->delete_gateway($binding['gateway_uuid'], $account['domain_uuid']);
				}
				$this->store->delete_binding_row($binding['x_carrier_binding_uuid']);
			}
			else {
				$others = array_filter($this->store->route_bindings($binding['dialplan_uuid']), function ($row) use ($binding) {
					return $row['x_carrier_binding_uuid'] !== $binding['x_carrier_binding_uuid'];
				});
				if (!empty($others)) {
					$state = $this->push_route($binding['dialplan_uuid'], null, null, false, $binding['x_carrier_binding_uuid']);
					if ($state === 'drifted') {
						return 'error-route_drifted';
					}
					$this->store->delete_binding_row($binding['x_carrier_binding_uuid']);
					//renumber the remaining bindings
					$position = 0;
					foreach ($others as $row) {
						$this->store->update_binding($row['x_carrier_binding_uuid'], ['x_carrier_binding_order' => $position++]);
					}
				}
				else {
					if ($managed && is_uuid($binding['dialplan_uuid'])) {
						$this->routes->delete($binding['dialplan_uuid']);
					}
					$this->store->delete_binding_row($binding['x_carrier_binding_uuid']);
				}
			}
			$this->store->audit('delete', $account['x_carrier_account_uuid'], $credential['x_carrier_credential_uuid'], [
				'binding' => $binding['x_carrier_binding_uuid'],
				'type' => $binding['x_carrier_binding_type'],
				'gateway_uuid' => $binding['gateway_uuid'],
				'dialplan_uuid' => $binding['dialplan_uuid'],
				'managed' => $managed,
			], $account['domain_uuid']);
			return null;
		});
	}

	/**
	 * Deletes a managed gateway as gateways::delete does. The password is
	 * blanked first so the row copied into the transaction log by
	 * database::delete carries no secret.
	 */
	private function delete_gateway(string $gateway_uuid, string $domain_uuid): void {
		$sql = "select gateway_uuid, gateway, profile from v_gateways where gateway_uuid = :gateway_uuid and domain_uuid = :domain_uuid ";
		$parameters['gateway_uuid'] = $gateway_uuid;
		$parameters['domain_uuid'] = $domain_uuid;
		$gateway = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);
		if (empty($gateway)) {
			return;
		}
		$profile = self::valid_profile((string) $gateway['profile']) ? $gateway['profile'] : 'external';

		$array['gateways'][0]['gateway_uuid'] = $gateway_uuid;
		$array['gateways'][0]['password'] = '';
		$this->store->save_rows($array, ['gateway_edit']);
		unset($array);

		unset($_SESSION['gateways'][$gateway_uuid]);
		$this->unlink_gateway_xml($profile, $gateway_uuid);

		$esl = event_socket::create();
		if ($esl && $esl->is_connected()) {
			event_socket::api('sofia profile '.$profile.' killgw '.$gateway_uuid);
		}

		$array['gateways'][0]['gateway_uuid'] = $gateway_uuid;
		$database = $this->database;
		x_carriers::with_permissions(['gateway_delete'], function () use ($database, &$array) {
			return $database->delete($array);
		});

		$this->rescan[$profile] = true;
		$this->gateways_changed = true;
		$this->apply_gateway_changes();
	}

	/**
	 * Starts a credential rotation: a pending copy with the new secret and
	 * side-by-side targets (a second gateway for sip_digest so registration can
	 * be tested; a parallel route at a lower priority for ip_auth).
	 *
	 * @return string|null pending credential uuid
	 */
	public function rotate_start(array $credential, x_carrier_secret $new_secret): ?string {
		if ($credential['x_carrier_credential_status'] !== 'active' || $this->store->pending_for($credential['x_carrier_credential_uuid']) !== null) {
			return null;
		}
		$account = $this->store->account($credential['x_carrier_account_uuid']);
		if ($account === null) {
			return null;
		}
		$pending_uuid = $this->store->create_credential(
			$account,
			$credential['x_carrier_credential_type'],
			$credential['x_carrier_credential_label'],
			x_carriers::public_decode($credential['x_carrier_credential_public']),
			$new_secret,
			'pending',
			$credential['x_carrier_credential_uuid'],
			$credential['x_carrier_credential_not_before'] ?: null,
			$credential['x_carrier_credential_not_after'] ?: null,
			'rotate',
			['step' => 'start', 'replaces' => $credential['x_carrier_credential_uuid']]
		);
		if ($pending_uuid === null) {
			return null;
		}
		$pending = $this->store->credential($pending_uuid);

		//side-by-side targets
		$type = $credential['x_carrier_credential_type'];
		$bindings = $this->store->bindings($credential['x_carrier_credential_uuid']);
		if ($type === 'sip_digest') {
			foreach ($bindings as $binding) {
				if ($binding['x_carrier_binding_type'] === 'gateway') {
					$profile = self::valid_profile((string) $binding['profile']) ? $binding['profile'] : 'external';
					$this->create_gateway_binding($pending, $profile, $binding['register'] === 'true', true);
					break;
				}
			}
		}
		elseif ($type === 'ip_auth') {
			$gateway_uuid = $this->credential_gateway($credential['x_carrier_credential_uuid']);
			if ($gateway_uuid !== null) {
				//the pending credential bridges through the same gateway (it has no password)
				$this->store->add_binding($pending, 'gateway', $gateway_uuid, null, false, 0, true);
				foreach ($bindings as $binding) {
					if ($binding['x_carrier_binding_type'] === 'outbound_route' && (int) $binding['x_carrier_binding_order'] === 0) {
						$expression = $this->route_expression($binding['dialplan_uuid']);
						if ($expression !== null) {
							$this->create_route_binding($pending, null, ($binding['dialplan_name'] ?? 'route').'.rotating-in', $expression, ((int) $binding['dialplan_order']) + 1, true);
						}
						break;
					}
				}
			}
		}
		if ($type !== 'api_token') {
			$this->push($pending_uuid);
		}
		return $pending_uuid;
	}

	private function route_expression(?string $dialplan_uuid): ?string {
		if (!is_uuid($dialplan_uuid ?? '')) {
			return null;
		}
		$sql = "select dialplan_detail_data from v_dialplan_details ";
		$sql .= "where dialplan_uuid = :dialplan_uuid and dialplan_detail_tag = 'condition' and dialplan_detail_type = 'destination_number' ";
		$sql .= "order by dialplan_detail_order asc limit 1 ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$expression = $this->database->select($sql, $parameters, 'column');
		return (!empty($expression) && x_carrier_routes::valid_expression($expression)) ? $expression : null;
	}

	/**
	 * Removes the temporary side-by-side targets of a pending credential.
	 */
	private function remove_temporary(array $pending): void {
		foreach ($this->store->bindings($pending['x_carrier_credential_uuid']) as $binding) {
			if ($binding['x_carrier_binding_temporary'] !== 'true') {
				continue;
			}
			if ($binding['x_carrier_binding_type'] === 'outbound_route') {
				$this->delete_binding($binding);
			}
		}
		foreach ($this->store->bindings($pending['x_carrier_credential_uuid']) as $binding) {
			if ($binding['x_carrier_binding_temporary'] === 'true') {
				$this->delete_binding($binding);
			}
		}
	}

	/**
	 * Promotes a pending credential: remove its temporary targets, move the old
	 * credential's bindings to it, push, retire the old credential.
	 *
	 * @return string|null null on success, otherwise a language key
	 */
	public function rotate_promote(array $pending): ?string {
		if ($pending['x_carrier_credential_status'] !== 'pending' || !is_uuid($pending['x_carrier_credential_replaces_uuid'] ?? '')) {
			return 'error-not_pending';
		}
		$old = $this->store->credential($pending['x_carrier_credential_replaces_uuid'], true);
		if ($old === null) {
			return 'error-not_found';
		}
		$old_secret = $this->store->open_secret($old);

		$this->remove_temporary($pending);
		$this->store->audit('rotate', $pending['x_carrier_account_uuid'], $pending['x_carrier_credential_uuid'], ['step' => 'remove_temporary'], $pending['domain_uuid']);

		foreach ($this->store->bindings($old['x_carrier_credential_uuid']) as $binding) {
			$this->store->update_binding($binding['x_carrier_binding_uuid'], ['x_carrier_credential_uuid' => $pending['x_carrier_credential_uuid']]);
		}
		$this->store->audit('rotate', $pending['x_carrier_account_uuid'], $pending['x_carrier_credential_uuid'], ['step' => 'swap_bindings', 'from' => $old['x_carrier_credential_uuid']], $pending['domain_uuid']);

		$this->store->update_credential($pending['x_carrier_credential_uuid'], ['x_carrier_credential_status' => 'active']);
		$this->store->clear_credential_columns($pending['x_carrier_credential_uuid'], ['x_carrier_credential_replaces_uuid']);
		$result = $this->push($pending['x_carrier_credential_uuid'], $old_secret);
		$old_secret->wipe();

		$this->store->update_credential($old['x_carrier_credential_uuid'], ['x_carrier_credential_status' => 'retired', 'x_carrier_credential_retired_date' => 'now()']);
		$this->store->audit('retire', $old['x_carrier_account_uuid'], $old['x_carrier_credential_uuid'], ['version' => (int) $old['x_carrier_credential_version'], 'replaced_by' => $pending['x_carrier_credential_uuid']], $old['domain_uuid']);
		$this->store->audit('rotate', $pending['x_carrier_account_uuid'], $pending['x_carrier_credential_uuid'], ['step' => 'promote', 'outcome' => $result['ok'] ? 'ok' : 'incomplete', 'drifted' => $result['drifted']], $pending['domain_uuid']);
		return $result['ok'] ? null : 'error-push_incomplete';
	}

	/**
	 * Cancels a pending rotation: remove temporary targets and the pending row.
	 */
	public function rotate_cancel(array $pending): ?string {
		if ($pending['x_carrier_credential_status'] !== 'pending') {
			return 'error-not_pending';
		}
		$this->remove_temporary($pending);
		$this->store->audit('rotate', $pending['x_carrier_account_uuid'], $pending['x_carrier_credential_uuid'], ['step' => 'cancel'], $pending['domain_uuid']);
		return $this->store->delete_credential($this->store->credential($pending['x_carrier_credential_uuid']));
	}

	/**
	 * Retires a credential without a replacement. Its targets are disabled by
	 * a push (status retired is not enabled).
	 */
	public function retire(array $credential): ?string {
		if ($credential['x_carrier_credential_status'] === 'retired') {
			return null;
		}
		if ($this->store->pending_for($credential['x_carrier_credential_uuid']) !== null) {
			return 'error-rotation_pending';
		}
		//an adopted gateway is never disabled by this app, so retiring would not stop FreeSWITCH using it
		foreach ($this->store->bindings($credential['x_carrier_credential_uuid']) as $binding) {
			if ($binding['x_carrier_binding_type'] === 'gateway' && $binding['x_carrier_binding_managed'] !== 'true' && $binding['x_carrier_binding_temporary'] !== 'true') {
				return 'error-retire_adopted';
			}
		}
		$this->store->update_credential($credential['x_carrier_credential_uuid'], ['x_carrier_credential_status' => 'retired', 'x_carrier_credential_retired_date' => 'now()']);
		$this->store->audit('retire', $credential['x_carrier_account_uuid'], $credential['x_carrier_credential_uuid'], ['version' => (int) $credential['x_carrier_credential_version']], $credential['domain_uuid']);
		if ($credential['x_carrier_credential_type'] !== 'api_token') {
			$this->push($credential['x_carrier_credential_uuid']);
		}
		return null;
	}

}

?>
