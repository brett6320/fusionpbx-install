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
 * Diff and approval gate (docs/design/carrier_acl.md section 5.2 steps 3 and 4).
 */
class x_carrier_acl_diff {

	/**
	 * Current entries of a feed keyed "cidr|role".
	 */
	public static function current(database $database, string $feed_uuid): array {
		$sql = "select * from v_x_carrier_acl_entries where x_carrier_acl_feed_uuid = :feed_uuid ";
		$parameters['feed_uuid'] = $feed_uuid;
		$rows = $database->select($sql, $parameters, 'all');
		$current = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$current[$row['entry_cidr'].'|'.$row['entry_role']] = $row;
			}
		}
		return $current;
	}

	/**
	 * Diff by (cidr, role).
	 *
	 * @param array $current  rows keyed "cidr|role" (self::current)
	 * @param array $proposed accepted entries keyed "cidr|role" (x_carrier_acl_validate)
	 *
	 * @return array ['added' => [key => entry], 'removed' => [key => row], 'unchanged' => [key => entry]]
	 */
	public static function compute(array $current, array $proposed): array {
		$diff = ['added' => [], 'removed' => [], 'unchanged' => []];
		foreach ($proposed as $key => $entry) {
			if (isset($current[$key])) {
				$diff['unchanged'][$key] = $entry;
			}
			else {
				$diff['added'][$key] = $entry;
			}
		}
		foreach ($current as $key => $row) {
			if (!isset($proposed[$key])) {
				$diff['removed'][$key] = $row;
			}
		}
		return $diff;
	}

	/**
	 * Approval gate. Returns a list of reasons; empty means apply.
	 *
	 * @param array $current    current rows keyed "cidr|role"
	 * @param array $proposed   accepted entries keyed "cidr|role"
	 * @param array $diff       self::compute
	 * @param array $thresholds max_remove_pct, max_remove_abs, max_add_pct, max_add_abs
	 */
	public static function gate(array $current, array $proposed, array $diff, array $thresholds): array {
		$reasons = [];
		$current_count = count($current);
		$added = count($diff['added']);
		$removed = count($diff['removed']);

		//first successful run for the feed: the admin sees the whole list first
		if ($current_count === 0) {
			$reasons[] = 'first run for this feed';
		}
		else {
			if ($removed > $thresholds['max_remove_abs']) {
				$reasons[] = 'removes '.$removed.' entries (more than '.$thresholds['max_remove_abs'].')';
			}
			elseif ($removed * 100 > $thresholds['max_remove_pct'] * $current_count) {
				$reasons[] = 'removes '.$removed.' of '.$current_count.' entries (more than '.$thresholds['max_remove_pct'].'%)';
			}
			if ($added > $thresholds['max_add_abs']) {
				$reasons[] = 'adds '.$added.' entries (more than '.$thresholds['max_add_abs'].')';
			}
			elseif ($added * 100 > $thresholds['max_add_pct'] * $current_count) {
				$reasons[] = 'adds '.$added.' entries to '.$current_count.' (more than '.$thresholds['max_add_pct'].'%)';
			}
		}

		//a broad prefix needs approval the first time it appears; once approved it is remembered on the entry
		foreach ($proposed as $key => $entry) {
			if (!$entry['broad']) {
				continue;
			}
			$approved = isset($current[$key]) && self::bool($current[$key]['entry_broad_approved'] ?? false);
			if (!$approved) {
				$reasons[] = 'broad prefix '.$entry['cidr'].' needs approval';
			}
		}
		return $reasons;
	}

	/**
	 * Database boolean to php.
	 */
	public static function bool($value): bool {
		return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
	}

}

?>
