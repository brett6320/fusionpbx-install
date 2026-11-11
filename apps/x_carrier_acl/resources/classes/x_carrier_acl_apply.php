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
 * DB apply, FreeSWITCH ACL projection, reloadacl and the firewall snapshot writer
 * (docs/design/carrier_acl.md sections 5.2 step 5, 5.3, 6 and 7.3).
 *
 * v_x_carrier_acl_entries is the source of truth. Our access control list (default name x_carrier_auto) and the
 * firewall snapshot are projections of it. We never write to a list we did not create.
 *
 * An apply is one database transaction: entries are inserted/updated/deleted and the list is reconciled (nodes not
 * referenced by an entry are deleted, signaling entries without a live node get one). database::save() opens its
 * own transaction and cannot be nested, so the transaction uses database::execute() inside an explicit PDO
 * transaction on the same connection, serialized against web purges by a postgres advisory lock.
 */
class x_carrier_acl_apply {

	private $database;
	private $app;

	public function __construct(database $database, x_carrier_acl $app) {
		$this->database = $database;
		$this->app = $app;
	}

	/**
	 * Ensure our access control list exists (section 6.1).
	 *
	 * @return array ['uuid' => ?string, 'created' => bool, 'error' => ?string]
	 */
	public function ensure_list(): array {
		$state = $this->app->acl_state();
		$acl_name = $this->app->acl_name();
		if ($state['exists'] && !$state['renamed']) {
			return ['uuid' => $state['uuid'], 'created' => false, 'error' => null];
		}
		if ($state['exists'] && $state['renamed']) {
			x_carrier_acl::log(LOG_WARNING, 'access control list '.$state['uuid'].' was renamed to "'.$state['name'].'"; it is no longer written, a new list "'.$acl_name.'" is created');
		}

		//a list with our name that we did not create is never adopted
		$sql = "select count(*) from v_access_controls where access_control_name = :name ";
		$parameters['name'] = $acl_name;
		if ((int)$this->database->select($sql, $parameters, 'column') > 0) {
			return ['uuid' => null, 'created' => false, 'error' => 'an access control list named "'.$acl_name.'" exists but was not created by this app'];
		}
		unset($sql, $parameters);

		//insert the list as upstream app_defaults does, with a temporary permission
		$access_control_uuid = uuid();
		$array['access_controls'][0]['access_control_uuid'] = $access_control_uuid;
		$array['access_controls'][0]['access_control_name'] = $acl_name;
		$array['access_controls'][0]['access_control_default'] = 'deny';
		$array['access_controls'][0]['access_control_description'] = 'Managed by x_carrier_acl. Do not edit.';
		$p = permissions::new();
		$p->add('access_control_add', 'temp');
		$saved = $this->database->save($array, false);
		$p->delete('access_control_add', 'temp');
		unset($array);
		if (!$saved) {
			return ['uuid' => null, 'created' => false, 'error' => 'access control list could not be created'];
		}

		//cache the uuid in the default setting declared in app_config.php
		if (!$this->store_acl_uuid($access_control_uuid)) {
			return ['uuid' => null, 'created' => false, 'error' => 'access control list uuid could not be stored'];
		}
		$this->app->reload_settings();
		x_carrier_acl::log(LOG_NOTICE, 'created access control list "'.$acl_name.'" ('.$access_control_uuid.')');
		//make freeswitch aware of the (empty, default deny) list before any profile references it
		$this->reload_acl(null);
		return ['uuid' => $access_control_uuid, 'created' => true, 'error' => null];
	}

	/**
	 * Insert-or-update the acl_uuid default setting (same uuid as app_config.php, so upgrade.php never duplicates it).
	 */
	private function store_acl_uuid(string $uuid): bool {
		$sql = "select count(*) from v_default_settings where default_setting_uuid = :setting_uuid ";
		$parameters['setting_uuid'] = x_carrier_acl::acl_uuid_setting_uuid;
		$exists = (int)$this->database->select($sql, $parameters, 'column') > 0;
		unset($sql, $parameters);
		if ($exists) {
			$sql = "update v_default_settings set default_setting_value = :value, default_setting_enabled = true, update_date = now() ";
			$sql .= "where default_setting_uuid = :setting_uuid ";
		}
		else {
			$sql = "insert into v_default_settings (default_setting_uuid, app_uuid, default_setting_category, default_setting_subcategory, ";
			$sql .= "default_setting_name, default_setting_value, default_setting_enabled, default_setting_description, insert_date) ";
			$sql .= "values (:setting_uuid, '".x_carrier_acl::app_uuid."', 'x_carrier_acl', 'acl_uuid', 'text', :value, true, ";
			$sql .= "'Managed by the app: uuid of the access control list it owns. Do not edit.', now()) ";
		}
		$parameters['setting_uuid'] = x_carrier_acl::acl_uuid_setting_uuid;
		$parameters['value'] = $uuid;
		return $this->database->execute($sql, $parameters) !== false;
	}

	/**
	 * Attach our list to SIP profiles (section 6.3): insert-if-missing an enabled apply-inbound-acl row keyed on
	 * (sip_profile_uuid, name, value) for each named profile that exists and is enabled. Existing rows, including
	 * rows an admin disabled, are left alone. The existing apply-inbound-acl = providers rows are never touched.
	 *
	 * @param array $profile_names subset of internal, external, internal-ipv6, external-ipv6
	 *
	 * @return array ['attached' => [profile names that got a new row and need "sofia profile <name> restart"],
	 *                'error' => ?string]
	 */
	public function attach_profiles(array $profile_names): array {
		$allowed = ['internal', 'external', 'internal-ipv6', 'external-ipv6'];
		$profile_names = array_values(array_intersect($allowed, $profile_names));
		$attached = [];
		$acl_name = $this->app->acl_name();
		foreach ($this->app->attachment_state() as $state) {
			if (!in_array($state['profile'], $profile_names, true) || !$state['profile_enabled'] || $state['attached']) {
				continue;
			}
			$sql = "insert into v_sip_profile_settings (sip_profile_setting_uuid, sip_profile_uuid, sip_profile_setting_name, ";
			$sql .= "sip_profile_setting_value, sip_profile_setting_enabled, sip_profile_setting_description, insert_date) ";
			$sql .= "values (:uuid, :sip_profile_uuid, 'apply-inbound-acl', :acl_name, true, 'Managed by x_carrier_acl', now()) ";
			$parameters = ['uuid' => uuid(), 'sip_profile_uuid' => $state['sip_profile_uuid'], 'acl_name' => $acl_name];
			if ($this->database->execute($sql, $parameters) === false) {
				return ['attached' => $attached, 'error' => 'apply-inbound-acl row could not be added to profile '.$state['profile']];
			}
			$attached[] = $state['profile'];
		}
		//clear the sofia.conf cache as sip_profile_edit.php does, so the restart reads the new row
		if (!empty($attached)) {
			$sql = "select distinct coalesce(nullif(sip_profile_hostname, ''), :hostname) as hostname from v_sip_profiles ";
			$rows = $this->database->select($sql, ['hostname' => gethostname()], 'all');
			$cache = new cache;
			foreach (is_array($rows) ? $rows : [] as $row) {
				$cache->delete($row['hostname'].":configuration:sofia.conf");
			}
		}
		return ['attached' => $attached, 'error' => null];
	}

	/**
	 * Apply a proposal for one feed in one transaction (sections 5.2 step 5, 5.3, 6.1).
	 *
	 * @param string $feed_uuid
	 * @param array  $proposed accepted entries keyed "cidr|role"
	 * @param array  $diff     x_carrier_acl_diff::compute
	 *
	 * @return array ['ok' => bool, 'error' => ?string]
	 */
	public function apply_feed(string $feed_uuid, array $proposed, array $diff): array {
		$list = $this->ensure_list();
		if ($list['error'] !== null) {
			return ['ok' => false, 'error' => $list['error']];
		}
		return $this->transaction(function () use ($feed_uuid, $proposed, $diff, $list) {
			//the feed must still exist (a concurrent delete would otherwise leave orphan entries), and the diff is
			//recomputed under the lock so a concurrent purge cannot leave a partial set
			$exists = $this->exec("select x_carrier_acl_feed_uuid from v_x_carrier_acl_feeds where x_carrier_acl_feed_uuid = :feed_uuid for update ", ['feed_uuid' => $feed_uuid]);
			if (empty($exists)) {
				throw new RuntimeException('feed was deleted during the run');
			}
			$current = [];
			foreach ($this->exec("select * from v_x_carrier_acl_entries where x_carrier_acl_feed_uuid = :feed_uuid ", ['feed_uuid' => $feed_uuid]) as $row) {
				$current[$row['entry_cidr'].'|'.$row['entry_role']] = $row;
			}
			$diff = x_carrier_acl_diff::compute($current, $proposed);
			//removed entries (their nodes go in the reconcile below)
			foreach ($diff['removed'] as $row) {
				$sql = "delete from v_x_carrier_acl_entries where x_carrier_acl_entry_uuid = :uuid and x_carrier_acl_feed_uuid = :feed_uuid ";
				$this->exec($sql, ['uuid' => $row['x_carrier_acl_entry_uuid'], 'feed_uuid' => $feed_uuid]);
			}
			//added entries (signaling nodes are created by the reconcile below)
			foreach ($diff['added'] as $entry) {
				$sql = "insert into v_x_carrier_acl_entries (x_carrier_acl_entry_uuid, x_carrier_acl_feed_uuid, entry_cidr, entry_family, ";
				$sql .= "entry_role, entry_label, entry_broad_approved, entry_first_seen, entry_last_seen, insert_date) ";
				$sql .= "values (:uuid, :feed_uuid, :cidr, :family, :role, :label, :broad, now(), now(), now()) ";
				$this->exec($sql, [
					'uuid' => uuid(), 'feed_uuid' => $feed_uuid, 'cidr' => $entry['cidr'], 'family' => $entry['family'],
					'role' => $entry['role'], 'label' => $entry['label'], 'broad' => $entry['broad'] ? 'true' : 'false',
				]);
			}
			//unchanged entries: refresh last seen and label; an applied broad entry is approved by definition
			foreach ($diff['unchanged'] as $entry) {
				$sql = "update v_x_carrier_acl_entries set entry_last_seen = now(), entry_label = :label, ";
				$sql .= "entry_broad_approved = (coalesce(entry_broad_approved, false) or :broad), update_date = now() ";
				$sql .= "where x_carrier_acl_feed_uuid = :feed_uuid and entry_cidr = :cidr and entry_role = :role ";
				$this->exec($sql, ['label' => $entry['label'], 'broad' => $entry['broad'] ? 'true' : 'false', 'feed_uuid' => $feed_uuid, 'cidr' => $entry['cidr'], 'role' => $entry['role']]);
			}
			$this->reconcile($list['uuid']);
		});
	}

	/**
	 * Remove every entry of a feed (and optionally the feed itself) and its nodes (section 5.3).
	 */
	public function purge_feed(string $feed_uuid, bool $delete_feed): array {
		$list_uuid = $this->app->acl_state();
		return $this->transaction(function () use ($feed_uuid, $delete_feed, $list_uuid) {
			$sql = "delete from v_access_control_nodes where access_control_node_uuid in ( ";
			$sql .= "select access_control_node_uuid from v_x_carrier_acl_entries where x_carrier_acl_feed_uuid = :feed_uuid and access_control_node_uuid is not null) ";
			if ($list_uuid['exists'] && !$list_uuid['renamed']) {
				$sql .= "and access_control_uuid = :acl_uuid ";
				$this->exec($sql, ['feed_uuid' => $feed_uuid, 'acl_uuid' => $list_uuid['uuid']]);
			}
			$this->exec("delete from v_x_carrier_acl_entries where x_carrier_acl_feed_uuid = :feed_uuid ", ['feed_uuid' => $feed_uuid]);
			if ($delete_feed) {
				$this->exec("delete from v_x_carrier_acl_feeds where x_carrier_acl_feed_uuid = :feed_uuid ", ['feed_uuid' => $feed_uuid]);
			}
		});
	}

	/**
	 * Reconcile our list with the entries (section 6.1): our list is ours.
	 * Must run inside transaction().
	 */
	private function reconcile(string $acl_uuid): void {
		//nodes in our list not referenced by a signaling entry (hand-added, or a crash mid-way)
		$sql = "delete from v_access_control_nodes where access_control_uuid = :acl_uuid ";
		$sql .= "and access_control_node_uuid not in ( ";
		$sql .= "	select access_control_node_uuid from v_x_carrier_acl_entries ";
		$sql .= "	where entry_role = 'signaling' and access_control_node_uuid is not null ";
		$sql .= ") ";
		$this->exec($sql, ['acl_uuid' => $acl_uuid]);

		//media entries never carry a node
		$this->exec("update v_x_carrier_acl_entries set access_control_node_uuid = null where entry_role = 'media' and access_control_node_uuid is not null ", null);

		//signaling entries without a live node in our list get one
		$sql = "select e.x_carrier_acl_entry_uuid, e.entry_cidr, e.entry_label, f.feed_name ";
		$sql .= "from v_x_carrier_acl_entries e ";
		$sql .= "join v_x_carrier_acl_feeds f on f.x_carrier_acl_feed_uuid = e.x_carrier_acl_feed_uuid ";
		$sql .= "where e.entry_role = 'signaling' ";
		$sql .= "and (e.access_control_node_uuid is null or not exists ( ";
		$sql .= "	select 1 from v_access_control_nodes n ";
		$sql .= "	where n.access_control_node_uuid = e.access_control_node_uuid and n.access_control_uuid = :acl_uuid ";
		$sql .= ")) ";
		$rows = $this->exec($sql, ['acl_uuid' => $acl_uuid]);
		foreach ($rows as $row) {
			$node_uuid = uuid();
			$description = mb_strcut(mb_scrub('x_carrier_acl:'.$row['feed_name'].':'.$row['entry_label'], 'UTF-8'), 0, 255, 'UTF-8');
			$sql = "insert into v_access_control_nodes (access_control_node_uuid, access_control_uuid, node_type, node_cidr, node_description, insert_date) ";
			$sql .= "values (:node_uuid, :acl_uuid, 'allow', :cidr, :description, now()) ";
			$this->exec($sql, ['node_uuid' => $node_uuid, 'acl_uuid' => $acl_uuid, 'cidr' => $row['entry_cidr'], 'description' => $description]);
			$sql = "update v_x_carrier_acl_entries set access_control_node_uuid = :node_uuid where x_carrier_acl_entry_uuid = :uuid ";
			$this->exec($sql, ['node_uuid' => $node_uuid, 'uuid' => $row['x_carrier_acl_entry_uuid']]);
		}
	}

	/**
	 * Run $work in one transaction under the advisory lock. Returns ['ok', 'error'].
	 */
	private function transaction(callable $work): array {
		//make sure the connection is open, then use the same PDO handle as database::execute()
		$this->database->select("select 1", null, 'column');
		$pdo = $this->database->db;
		if (!($pdo instanceof PDO)) {
			return ['ok' => false, 'error' => 'database not connected'];
		}
		try {
			$pdo->beginTransaction();
			$this->exec("select pg_advisory_xact_lock(".x_carrier_acl::lock_key.")", null);
			$work();
			$pdo->commit();
			return ['ok' => true, 'error' => null];
		}
		catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['ok' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * database::execute() that throws on failure (inside transaction()).
	 */
	private function exec(string $sql, ?array $parameters): array {
		$result = $this->database->execute($sql, $parameters, 'all');
		if ($result === false) {
			$message = $this->database->message;
			throw new RuntimeException('database error: '.(is_array($message) ? ($message['message'] ?? 'unknown') : 'unknown'));
		}
		return is_array($result) ? $result : [];
	}

	/**
	 * Clear the acl.conf cache and reloadacl exactly like upstream (section 6.2), then verify with "acl <ip> <list>".
	 *
	 * @param string|null $verify_cidr a signaling cidr expected in the list; null skips verification
	 *
	 * @return string|null error or null
	 */
	public function reload_acl(?string $verify_cidr): ?string {
		$esl = event_socket::create();
		if (!$esl->is_connected()) {
			return 'event socket unavailable; reloadacl not sent';
		}
		$cache = new cache;
		$cache->delete("configuration:acl.conf");
		event_socket::async("reloadacl");
		if ($verify_cidr === null) {
			return null;
		}
		$ip = explode('/', $verify_cidr)[0];
		if (@inet_pton($ip) === false) {
			return null;
		}
		//bgapi is asynchronous: poll up to 5 times, 1 s apart
		for ($i = 0; $i < 5; $i++) {
			sleep(1);
			$response = event_socket::api('acl '.$ip.' '.$this->app->acl_name());
			if (is_string($response) && trim($response) === 'true') {
				return null;
			}
		}
		return 'reloadacl not confirmed';
	}

	/**
	 * Entries for the firewall snapshot: union of all feeds, both roles, deduplicated, filtered through the root
	 * applier's fixed rules (feed_allow_private never reaches the firewall).
	 */
	public function snapshot_entries(): array {
		$sql = "select distinct e.entry_family, e.entry_cidr, e.entry_role from v_x_carrier_acl_entries e ";
		$sql .= "join v_x_carrier_acl_feeds f on f.x_carrier_acl_feed_uuid = e.x_carrier_acl_feed_uuid ";
		$sql .= "order by e.entry_family asc, e.entry_cidr asc, e.entry_role asc ";
		$rows = $this->database->select($sql, null, 'all');
		$entries = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (!in_array($row['entry_role'], ['signaling', 'media'], true)) {
					continue;
				}
				if (!x_carrier_acl_validate::firewall_allowed($row['entry_cidr'])) {
					continue;
				}
				$entries[] = ['family' => (int)$row['entry_family'] === 6 ? 6 : 4, 'cidr' => $row['entry_cidr'], 'role' => $row['entry_role']];
			}
		}
		return $entries;
	}

	/**
	 * Enabled local networks for the snapshot (section 12), or null on a database error.
	 */
	public function snapshot_local_entries(): ?array {
		return (new x_carrier_acl_local($this->database))->firewall_entries();
	}

	/**
	 * Snapshot text after the generation line (section 7.3; version 2 adds the local section, section 12).
	 * The "fail2ban on|off" line is our addition: the applier needs the fail2ban_ignoreip setting.
	 * Local networks are allowed for signaling and media, so each is written once per role.
	 */
	public function snapshot_body(array $entries, array $local = []): string {
		$lines = [];
		$lines[] = 'mode '.$this->app->firewall_mode();
		$sip = $this->app->port_range('firewall_sip_ports');
		$rtp = $this->app->port_range('firewall_rtp_ports');
		[$sip_low, $sip_high] = array_map('intval', explode(':', $sip));
		[$rtp_low, $rtp_high] = array_map('intval', explode(':', $rtp));
		if ($sip_low <= $rtp_high && $rtp_low <= $sip_high) {
			x_carrier_acl::log(LOG_WARNING, 'sip and rtp port ranges overlap; using the defaults');
			$sip = x_carrier_acl::defaults['firewall_sip_ports'];
			$rtp = x_carrier_acl::defaults['firewall_rtp_ports'];
		}
		$lines[] = 'ports sip '.$sip;
		$lines[] = 'ports rtp '.$rtp;
		$lines[] = 'fail2ban '.($this->app->setting_bool('fail2ban_ignoreip') ? 'on' : 'off');
		$lines[] = 'count '.count($entries);
		foreach ($entries as $entry) {
			$lines[] = 'v'.$entry['family'].' '.$entry['cidr'].' '.$entry['role'];
		}
		$lines[] = 'local '.(count($local) * 2);
		foreach ($local as $entry) {
			foreach (['signaling', 'media'] as $role) {
				$lines[] = 'local v'.$entry['family'].' '.$entry['cidr'].' '.$role;
			}
		}
		$lines[] = 'end';
		return implode("\n", $lines)."\n";
	}

	/**
	 * Body of the snapshot on disk (after the generation line), or null.
	 */
	public static function disk_snapshot_body(): ?string {
		if (!is_file(x_carrier_acl::snapshot_file)) {
			return null;
		}
		$content = @file_get_contents(x_carrier_acl::snapshot_file, false, null, 0, 1048576);
		if (!is_string($content)) {
			return null;
		}
		$lines = explode("\n", $content);
		//an older grammar version on disk never matches, so the worker rewrites it in the current version
		if (count($lines) < 3 || $lines[0] !== x_carrier_acl::snapshot_header || !preg_match('/^generation \d+$/', $lines[1])) {
			return null;
		}
		return implode("\n", array_slice($lines, 2));
	}

	/**
	 * Next generation number: above anything exported, applied or recorded.
	 */
	private function next_generation(): int {
		$candidates = [0, (int)x_carrier_acl::exported_generation()];
		$status = x_carrier_acl::firewall_status();
		if (is_array($status) && isset($status['generation']) && is_numeric($status['generation'])) {
			$candidates[] = (int)$status['generation'];
		}
		$max = $this->database->select("select max(run_firewall_generation) from v_x_carrier_acl_runs", null, 'column');
		if (is_numeric($max)) {
			$candidates[] = (int)$max;
		}
		return max($candidates) + 1;
	}

	/**
	 * Write the snapshot and bump firewall.gen (which fires x-carrier-acl-apply.path).
	 *
	 * @param bool $force write even when the body on disk already matches the database
	 *
	 * @return array ['written' => bool, 'generation' => ?int, 'error' => ?string, 'skipped' => ?string]
	 */
	public function write_snapshot(bool $force = false): array {
		$result = ['written' => false, 'generation' => null, 'error' => null, 'skipped' => null];
		if (!$this->app->setting_bool('firewall_enabled')) {
			$result['skipped'] = 'firewall_enabled is false';
			return $result;
		}
		$entries = $this->snapshot_entries();
		$local = $this->snapshot_local_entries();
		if ($local === null) {
			$result['error'] = 'local networks could not be read; snapshot not written';
			return $result;
		}
		//the applier refuses more than 10000 lines
		if (count($entries) + 2 * count($local) > 9980) {
			$result['error'] = 'too many firewall entries ('.count($entries).' carrier, '.count($local).' local); snapshot not written';
			return $result;
		}
		if (count($entries) === 0 && $this->app->firewall_mode() === 'restrict') {
			$result['error'] = 'refusing an empty snapshot in restrict mode';
			return $result;
		}
		$body = $this->snapshot_body($entries, $local);
		if (!$force && self::disk_snapshot_body() === $body) {
			$result['skipped'] = 'unchanged';
			return $result;
		}
		$generation = $this->next_generation();
		$content = x_carrier_acl::snapshot_header."\ngeneration ".$generation."\n".$body;
		if (!self::atomic_write(x_carrier_acl::snapshot_file, $content)) {
			$result['error'] = 'snapshot could not be written to '.x_carrier_acl::snapshot_file;
			return $result;
		}
		if (!self::atomic_write(x_carrier_acl::generation_file, $generation."\n")) {
			$result['error'] = 'generation could not be written to '.x_carrier_acl::generation_file;
			return $result;
		}
		$result['written'] = true;
		$result['generation'] = $generation;
		x_carrier_acl::log(LOG_NOTICE, 'firewall snapshot generation '.$generation.' exported ('.count($entries).' carrier entries, '.count($local).' local networks)');
		return $result;
	}

	/**
	 * Write an empty allow-mode snapshot only when none exists (installer smoke test, section 10 step 6).
	 */
	public function write_empty_snapshot_if_missing(): array {
		if (is_file(x_carrier_acl::snapshot_file)) {
			return ['written' => false, 'generation' => null, 'error' => null, 'skipped' => 'snapshot exists'];
		}
		$generation = max(1, (int)x_carrier_acl::exported_generation() + 1);
		$content = x_carrier_acl::snapshot_header."\ngeneration ".$generation."\nmode allow\nports sip ".x_carrier_acl::defaults['firewall_sip_ports']."\nports rtp ".x_carrier_acl::defaults['firewall_rtp_ports']."\nfail2ban off\ncount 0\nlocal 0\nend\n";
		if (!self::atomic_write(x_carrier_acl::snapshot_file, $content) || !self::atomic_write(x_carrier_acl::generation_file, $generation."\n")) {
			return ['written' => false, 'generation' => null, 'error' => 'empty snapshot could not be written', 'skipped' => null];
		}
		return ['written' => true, 'generation' => $generation, 'error' => null, 'skipped' => null];
	}

	/**
	 * tmp + rename in the same directory, mode 0640.
	 */
	private static function atomic_write(string $file, string $content): bool {
		$tmp = dirname($file).'/.'.basename($file).'.'.getmypid().'.tmp';
		$fp = @fopen($tmp, 'x');
		if ($fp === false) {
			@unlink($tmp);
			$fp = @fopen($tmp, 'x');
			if ($fp === false) {
				return false;
			}
		}
		@chmod($tmp, 0640);
		$ok = fwrite($fp, $content) === strlen($content);
		$ok = $ok && fflush($fp);
		if (function_exists('fsync')) {
			$ok = $ok && fsync($fp);
		}
		fclose($fp);
		if (!$ok || !@rename($tmp, $file)) {
			@unlink($tmp);
			return false;
		}
		return true;
	}

}

?>
