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
 * Local networks (docs/design/carrier_acl.md section 12): admin-entered LAN/VPN/office ranges that the firewall
 * always allows on the SIP and RTP ports, in both allow and restrict mode, and that fail2ban ignores.
 *
 * They are deliberately separate from carrier feeds: private ranges are allowed here (feeds always reject them),
 * edits take effect without the approval queue, and they never reach the FreeSWITCH ACL (x_carrier_auto bypasses
 * digest authentication; phones must keep authenticating). Every change is audited as a run row with
 * run_trigger = 'local_network' and no feed.
 */
class x_carrier_acl_local {

	const table = 'v_x_carrier_acl_local_networks';
	const run_trigger = 'local_network';

	/**
	 * @var database
	 */
	private $database;

	public function __construct(database $database) {
		$this->database = $database;
	}

	/**
	 * True once upgrade.php --schema has created the table.
	 */
	public function table_exists(): bool {
		return (bool)$this->database->table_exists(self::table);
	}

	/**
	 * All local networks, enabled first.
	 */
	public function all(): array {
		if (!$this->table_exists()) {
			return [];
		}
		$sql = "select * from ".self::table." ";
		$sql .= "order by local_network_family asc, local_network_cidr asc ";
		$rows = $this->database->select($sql, null, 'all');
		return is_array($rows) ? $rows : [];
	}

	/**
	 * One row or null.
	 */
	public function get(string $uuid): ?array {
		if (!is_uuid($uuid)) {
			return null;
		}
		$sql = "select * from ".self::table." where x_carrier_acl_local_network_uuid = :uuid ";
		$row = $this->database->select($sql, ['uuid' => $uuid], 'row');
		return is_array($row) ? $row : null;
	}

	/**
	 * Uuid of another row with the same cidr, or null.
	 */
	public function duplicate(string $cidr, string $except_uuid = ''): ?string {
		$sql = "select x_carrier_acl_local_network_uuid from ".self::table." where local_network_cidr = :cidr ";
		$parameters = ['cidr' => $cidr];
		if (is_uuid($except_uuid)) {
			$sql .= "and x_carrier_acl_local_network_uuid <> :uuid ";
			$parameters['uuid'] = $except_uuid;
		}
		$value = $this->database->select($sql, $parameters, 'column');
		return is_uuid((string)$value) ? (string)$value : null;
	}

	/**
	 * Number of rows.
	 */
	public function count(): int {
		return (int)$this->database->select("select count(*) from ".self::table, null, 'column');
	}

	/**
	 * Enabled local networks for the firewall snapshot, re-validated under the local rules (the applier repeats
	 * this). Returns [] before the table exists (upgrade, before --schema) and null on a database error, so a
	 * failed read never exports a snapshot that silently drops the LAN.
	 *
	 * @return array|null [['family' => 4|6, 'cidr' => string], ...]
	 */
	public function firewall_entries(): ?array {
		if (!$this->table_exists()) {
			return [];
		}
		$sql = "select local_network_cidr from ".self::table." where local_network_enabled = true ";
		$sql .= "order by local_network_cidr asc ";
		$rows = $this->database->select($sql, null, 'all');
		if ($rows === false) {
			return null;
		}
		$entries = [];
		foreach (is_array($rows) ? $rows : [] as $row) {
			$n = x_carrier_acl_validate::local_network((string)$row['local_network_cidr']);
			if (!is_array($n)) {
				x_carrier_acl::log(LOG_WARNING, 'local network '.$row['local_network_cidr'].' skipped: '.$n);
				continue;
			}
			$entries[$n['cidr']] = ['family' => $n['family'], 'cidr' => $n['cidr']];
		}
		return array_values(array_slice($entries, 0, x_carrier_acl_validate::local_max));
	}

	/**
	 * Audit a change as a run row (section 9). The worker fills run_firewall_generation when it exports the
	 * snapshot that carries the change.
	 *
	 * @param string $action  add, edit, delete, enable or disable
	 * @param array  $added   [['cidr', 'label']] now allowed
	 * @param array  $removed [['cidr', 'label']] no longer allowed
	 */
	public function audit(string $action, array $added, array $removed): void {
		$shape = fn($rows) => array_values(array_map(fn($r) => [
			'cidr' => (string)$r['cidr'], 'role' => 'signaling+media', 'label' => x_carrier_acl_validate::clean_label((string)($r['label'] ?? '')),
		], $rows));
		$user = $_SESSION['username'] ?? ($_SESSION['user_uuid'] ?? 'unknown');
		$proposal = [
			'added' => $shape($added),
			'removed' => $shape($removed),
			'reasons' => ['local network '.$action.' by '.$user.' (no approval required)'],
		];
		$sql = "insert into v_x_carrier_acl_runs (x_carrier_acl_run_uuid, run_started, run_finished, run_trigger, run_status, ";
		$sql .= "run_added, run_removed, run_rejected_count, run_proposal, run_approved_by, run_approved_at, insert_date, insert_user) ";
		$sql .= "values (:uuid, now(), now(), :trigger, 'applied', :added, :removed, 0, :proposal, :user_uuid, now(), now(), :user_uuid) ";
		$parameters = [
			'uuid' => uuid(),
			'trigger' => self::run_trigger,
			'added' => count($added),
			'removed' => count($removed),
			'proposal' => json_encode($proposal, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
			'user_uuid' => is_uuid($_SESSION['user_uuid'] ?? '') ? $_SESSION['user_uuid'] : null,
		];
		if ($this->database->execute($sql, $parameters) === false) {
			x_carrier_acl::log(LOG_ERR, 'local network audit row could not be recorded');
		}
		x_carrier_acl::log(LOG_NOTICE, 'local network '.$action.' by '.$user.': +'.implode(',', array_column($added, 'cidr')).' -'.implode(',', array_column($removed, 'cidr')));
	}

}

?>
