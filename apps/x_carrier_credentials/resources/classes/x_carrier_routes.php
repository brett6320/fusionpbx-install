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
 * Outbound route composer. Emulates app/dialplan_outbound/dialplan_outbound_add.php
 * (gateway type, no toll_allow, no emergency special case) without calling it:
 * same app_uuid, same detail structure, bridge data sofia/gateway/<uuid>/<prefix>$1.
 * The route stays listed under Outbound Routes and editable in the stock editor.
 *
 * Differences from the wizard: no separate call_direction-outbound helper
 * dialplan (the route itself exports call_direction=outbound), and no
 * provider_prefix variable, so the tech prefix exists only in the bridge data.
 * Rows and dialplan_xml are written with save($array, false); the xml is built
 * by upstream's dialplan class with destination "array".
 */
class x_carrier_routes {

	//outbound routes app uuid, dialplan_outbound_add.php:57
	const outbound_app_uuid = '8c914ec3-9fc0-8ab5-4cda-6c9288bdc9a3';

	//continue_on_fail list from dialplan_outbound_add.php
	const continue_on_fail = '1,2,3,6,18,21,27,28,31,34,38,41,42,44,58,88,111,403,501,602,607,809';

	/**
	 * @var database
	 */
	private $database;

	public function __construct(?database $database = null) {
		$this->database = $database ?? database::new();
	}

	/**
	 * Bridge data as composed by dialplan_outbound_add.php:363.
	 */
	public static function bridge_data(string $gateway_uuid, string $prefix): string {
		return "sofia/gateway/".$gateway_uuid."/".$prefix."\$1";
	}

	/**
	 * Tech prefixes are digits, *, # or + only, so they cannot alter the bridge string.
	 */
	public static function valid_prefix(string $prefix): bool {
		return $prefix === '' || preg_match('/^[0-9*#+]{1,32}$/', $prefix) === 1;
	}

	/**
	 * A destination_number regex must compile and capture the number in $1.
	 */
	public static function valid_expression(string $expression): bool {
		if ($expression === '' || strlen($expression) > 255) {
			return false;
		}
		//dialplan::xml writes the expression unescaped into expression="...": allowlist regex characters only
		if (!preg_match('/^[0-9A-Za-z\\^$()\[\]{}|.*+?,#_\-]+$/', $expression)) {
			return false;
		}
		if (@preg_match('/'.str_replace('/', '\/', $expression).'/', '') === false) {
			return false;
		}
		return strpos($expression, '(') !== false;
	}

	/**
	 * Creates the outbound route. Returns the dialplan uuid or null.
	 */
	public function create(string $domain_uuid, string $dialplan_context, string $dialplan_name, string $dialplan_expression, int $dialplan_order, string $dialplan_description, array $bridges): ?string {
		$dialplan_uuid = uuid();

		//outbound prefix: digits between ^ and the first (, as the wizard does
		$tmp_prefix = preg_replace("/^\^(\d{1,})\(.*/", "$1", $dialplan_expression);
		$outbound_prefix = ($tmp_prefix == $dialplan_expression) ? '' : $tmp_prefix;

		$array['dialplans'][0]['domain_uuid'] = $domain_uuid;
		$array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
		$array['dialplans'][0]['app_uuid'] = self::outbound_app_uuid;
		$array['dialplans'][0]['dialplan_name'] = $dialplan_name;
		$array['dialplans'][0]['dialplan_order'] = $dialplan_order;
		$array['dialplans'][0]['dialplan_continue'] = 'false';
		$array['dialplans'][0]['dialplan_context'] = $dialplan_context;
		$array['dialplans'][0]['dialplan_enabled'] = 'true';
		$array['dialplans'][0]['dialplan_description'] = $dialplan_description;

		//details in the wizard's order (dialplan_outbound_add.php:536-655)
		$details = [];
		$details[] = ['condition', '${user_exists}', 'false', 'true'];
		$details[] = ['condition', 'destination_number', $dialplan_expression, 'true'];
		$details[] = ['action', 'set', 'sip_h_accountcode=${accountcode}', 'false'];
		$details[] = ['action', 'export', 'call_direction=outbound', 'true', 'true'];
		$details[] = ['action', 'unset', 'call_timeout', 'true'];
		$details[] = ['action', 'set', 'hangup_after_bridge=true', 'true'];
		$details[] = ['action', 'set', 'effective_caller_id_name=${outbound_caller_id_name}', 'true'];
		$details[] = ['action', 'set', 'effective_caller_id_number=${outbound_caller_id_number}', 'true'];
		$details[] = ['action', 'set', 'inherit_codec=true', 'true'];
		$details[] = ['action', 'set', 'ignore_display_updates=true', 'true'];
		$details[] = ['action', 'set', 'callee_id_number=$1', 'true'];
		$details[] = ['action', 'set', 'continue_on_fail='.self::continue_on_fail, 'true'];
		$details[] = ['action', 'set', 'limit_max=', 'false'];
		$details[] = ['action', 'limit', 'hash ${domain_name} outbound ${limit_max} !USER_BUSY', 'false'];
		if (!empty($outbound_prefix)) {
			$details[] = ['action', 'set', 'outbound_prefix='.$outbound_prefix, 'true'];
		}
		$details[] = ['action', 'set', 'pin_number=', 'false'];
		$details[] = ['action', 'lua', 'pin_number.lua', 'false'];
		foreach ($bridges as $bridge_data) {
			$details[] = ['action', 'bridge', $bridge_data, 'true'];
		}

		$y = 1;
		foreach ($details as $detail) {
			$row = [];
			$row['dialplan_detail_uuid'] = uuid();
			$row['domain_uuid'] = $domain_uuid;
			$row['dialplan_uuid'] = $dialplan_uuid;
			$row['dialplan_detail_tag'] = $detail[0];
			$row['dialplan_detail_type'] = $detail[1];
			$row['dialplan_detail_data'] = $detail[2];
			if (!empty($detail[4])) {
				$row['dialplan_detail_inline'] = $detail[4];
			}
			$row['dialplan_detail_order'] = $y * 10;
			$row['dialplan_detail_group'] = '0';
			$row['dialplan_detail_enabled'] = $detail[3];
			$array['dialplans'][0]['dialplan_details'][$y] = $row;
			$y++;
		}

		$database = $this->database;
		$saved = x_carriers::with_permissions(['dialplan_add', 'dialplan_edit', 'dialplan_detail_add', 'dialplan_detail_edit'], function () use ($database, &$array) {
			return $database->save($array, false);
		});
		if ($saved === false) {
			return null;
		}

		$this->rebuild_xml($dialplan_uuid);
		$this->clear_cache($dialplan_context);
		return $dialplan_uuid;
	}

	/**
	 * Returns the route's bridge actions in order, or null when the route is gone.
	 */
	public function bridges(string $dialplan_uuid): ?array {
		$sql = "select dialplan_context from v_dialplans where dialplan_uuid = :dialplan_uuid ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$context = $this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		if (empty($context)) {
			return null;
		}
		$sql = "select dialplan_detail_uuid, dialplan_detail_data, dialplan_detail_order from v_dialplan_details ";
		$sql .= "where dialplan_uuid = :dialplan_uuid and dialplan_detail_tag = 'action' and dialplan_detail_type = 'bridge' ";
		$sql .= "order by dialplan_detail_group asc, dialplan_detail_order asc ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Returns only the bridge data strings.
	 */
	public static function bridge_values(array $bridges): array {
		return array_map(function ($row) { return (string) $row['dialplan_detail_data']; }, $bridges);
	}

	/**
	 * Rewrites the bridge actions of a route to the given list, then rebuilds its xml.
	 */
	public function set_bridges(string $dialplan_uuid, array $expected): bool {
		$current = $this->bridges($dialplan_uuid);
		if ($current === null) {
			return false;
		}
		$sql = "select domain_uuid, dialplan_context from v_dialplans where dialplan_uuid = :dialplan_uuid ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$route = $this->database->select($sql, $parameters, 'row');
		unset($sql, $parameters);

		$array = [];
		$x = 0;
		$last_order = 0;
		foreach (array_values($current) as $i => $row) {
			$last_order = max($last_order, (int) $row['dialplan_detail_order']);
			if (isset($expected[$i])) {
				if ($row['dialplan_detail_data'] !== $expected[$i]) {
					$array['dialplan_details'][$x]['dialplan_detail_uuid'] = $row['dialplan_detail_uuid'];
					$array['dialplan_details'][$x]['dialplan_detail_data'] = $expected[$i];
					$x++;
				}
			}
			else {
				//remove surplus bridge actions directly, so their data is not copied into the transaction log
				$sql = "delete from v_dialplan_details where dialplan_detail_uuid = :uuid and dialplan_uuid = :dialplan_uuid ";
				$parameters['uuid'] = $row['dialplan_detail_uuid'];
				$parameters['dialplan_uuid'] = $dialplan_uuid;
				$this->database->execute($sql, $parameters);
				unset($sql, $parameters);
			}
		}
		for ($i = count($current); $i < count($expected); $i++) {
			$last_order += 10;
			$array['dialplan_details'][$x]['dialplan_detail_uuid'] = uuid();
			$array['dialplan_details'][$x]['domain_uuid'] = $route['domain_uuid'];
			$array['dialplan_details'][$x]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplan_details'][$x]['dialplan_detail_tag'] = 'action';
			$array['dialplan_details'][$x]['dialplan_detail_type'] = 'bridge';
			$array['dialplan_details'][$x]['dialplan_detail_data'] = $expected[$i];
			$array['dialplan_details'][$x]['dialplan_detail_order'] = $last_order;
			$array['dialplan_details'][$x]['dialplan_detail_group'] = '0';
			$array['dialplan_details'][$x]['dialplan_detail_enabled'] = 'true';
			$x++;
		}
		if (!empty($array)) {
			$database = $this->database;
			$saved = x_carriers::with_permissions(['dialplan_detail_add', 'dialplan_detail_edit'], function () use ($database, &$array) {
				return $database->save($array, false);
			});
			if ($saved === false) {
				return false;
			}
		}
		$this->rebuild_xml($dialplan_uuid);
		$this->clear_cache($route['dialplan_context']);
		return true;
	}

	/**
	 * Deletes a route and its details, then clears the context cache.
	 * Mirrors dialplan::delete without the transaction log.
	 */
	public function delete(string $dialplan_uuid): void {
		$sql = "select dialplan_context from v_dialplans where dialplan_uuid = :dialplan_uuid and app_uuid = :app_uuid ";
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$parameters['app_uuid'] = self::outbound_app_uuid;
		$context = $this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		if (empty($context)) {
			return;
		}
		$parameters['dialplan_uuid'] = $dialplan_uuid;
		$this->database->execute("delete from v_dialplan_details where dialplan_uuid = :dialplan_uuid ", $parameters);
		$this->database->execute("delete from v_dialplans where dialplan_uuid = :dialplan_uuid ", $parameters);
		$this->clear_cache($context);
	}

	/**
	 * Regenerates dialplan_xml with upstream's dialplan class and stores it
	 * without the transaction log (dialplan::xml with destination "database"
	 * would log the xml, which contains the prefix).
	 */
	private function rebuild_xml(string $dialplan_uuid): void {
		$dialplans = new dialplan;
		$dialplans->source = "details";
		$dialplans->destination = "array";
		$dialplans->uuid = $dialplan_uuid;
		$result = $dialplans->xml();
		if (is_array($result) && !empty($result[$dialplan_uuid])) {
			$array['dialplans'][0]['dialplan_uuid'] = $dialplan_uuid;
			$array['dialplans'][0]['dialplan_xml'] = $result[$dialplan_uuid];
			$database = $this->database;
			x_carriers::with_permissions(['dialplan_edit'], function () use ($database, &$array) {
				return $database->save($array, false);
			});
		}
	}

	/**
	 * Clears the dialplan cache for a context, dialplan_outbound_add.php:887-888.
	 */
	private function clear_cache(string $dialplan_context): void {
		$cache = new cache;
		$cache->delete("dialplan:".$dialplan_context);
	}

}

?>
