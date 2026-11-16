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
 * Schedule rules of IVR Manager and the time condition groups they become.
 *
 * A schedule is a list of rules; calls go to the IVR menu while any rule matches. Every rule becomes one or more
 * condition groups of the time condition, using only conditions FreeSWITCH evaluates itself every time, so
 * nothing needs updating from year to year:
 *  - once:   date-time start~stop
 *  - weekly: wday, one group per run of consecutive days, and an optional minute-of-day window
 *  - annual: mon and mday (a fixed date), or mon, an mday range of seven days and wday (the nth or last weekday
 *            of a month, e.g. US Thanksgiving: mon 11, mday 22-28, wday 5), shifted by an optional day offset;
 *            a fixed date can add the observed day (the Friday before when it falls on a Saturday, the Monday
 *            after when it falls on a Sunday); an optional minute-of-day window
 * minute-of-day follows the stock time condition editor: start h*60+m+1, end h*60+m (time_condition_edit.php).
 * Weekdays are numbered as FreeSWITCH does: 1 Sunday to 7 Saturday.
 */
class x_ivr_manager_schedule_rules {

	const max_rules = 40;

	/**
	 * US holidays for the annual rule presets: label => rule fields
	 *
	 * @return array
	 */
	public static function presets() {
		return [
			"New Year's Day" => ['month' => 1, 'kind' => 'date', 'mday' => 1, 'observed' => true],
			"Martin Luther King Jr. Day" => ['month' => 1, 'kind' => 'nth', 'nth' => '3', 'wday' => 2, 'offset' => 0],
			"Presidents' Day" => ['month' => 2, 'kind' => 'nth', 'nth' => '3', 'wday' => 2, 'offset' => 0],
			"Memorial Day" => ['month' => 5, 'kind' => 'nth', 'nth' => 'last', 'wday' => 2, 'offset' => 0],
			"Juneteenth" => ['month' => 6, 'kind' => 'date', 'mday' => 19, 'observed' => true],
			"Independence Day" => ['month' => 7, 'kind' => 'date', 'mday' => 4, 'observed' => true],
			"Labor Day" => ['month' => 9, 'kind' => 'nth', 'nth' => '1', 'wday' => 2, 'offset' => 0],
			"Columbus Day / Indigenous Peoples' Day" => ['month' => 10, 'kind' => 'nth', 'nth' => '2', 'wday' => 2, 'offset' => 0],
			"Veterans Day" => ['month' => 11, 'kind' => 'date', 'mday' => 11, 'observed' => true],
			"Thanksgiving Day" => ['month' => 11, 'kind' => 'nth', 'nth' => '4', 'wday' => 5, 'offset' => 0],
			"Day after Thanksgiving" => ['month' => 11, 'kind' => 'nth', 'nth' => '4', 'wday' => 5, 'offset' => 1],
			"Christmas Eve" => ['month' => 12, 'kind' => 'date', 'mday' => 24, 'observed' => false],
			"Christmas Day" => ['month' => 12, 'kind' => 'date', 'mday' => 25, 'observed' => true],
			"New Year's Eve" => ['month' => 12, 'kind' => 'date', 'mday' => 31, 'observed' => false],
		];
	}

	/**
	 * Days in a month, February counted with 29 so a leap day rule is allowed (it matches in leap years only)
	 *
	 * @param int $month
	 * @return int
	 */
	private static function month_days($month) {
		return [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31][$month];
	}

	/**
	 * Parse H:i (or 24:00 for an end) into minutes after midnight
	 *
	 * @param string $value
	 * @param bool $end allow 24:00
	 * @return int|null
	 */
	private static function minutes($value, $end = false) {
		if (!preg_match('/^([01][0-9]|2[0-4]):([0-5][0-9])$/', (string)$value, $m)) {
			return null;
		}
		$minutes = (int)$m[1] * 60 + (int)$m[2];
		if ($minutes > 1440 || ($minutes == 1440 && !$end)) {
			return null;
		}
		return $minutes;
	}

	/**
	 * Validate posted or stored rules and return them in their normal form
	 *
	 * @param array $input list of rules: type (once, weekly, annual), label and the fields of the type
	 * @param string|null $error set to a text key when a rule is invalid
	 * @param int|null $error_rule set to the 1 based number of the invalid rule
	 * @return array|false
	 */
	public static function normalize($input, &$error = null, &$error_rule = null) {
		$error = null;
		$error_rule = null;
		$rules = [];
		if (!is_array($input)) {
			$input = [];
		}
		foreach (array_values($input) as $i => $row) {
			if (!is_array($row)) {
				continue;
			}
			$error_rule = $i + 1;
			$type = (string)($row['type'] ?? '');
			$label = trim(preg_replace('/[\x00-\x1f\x7f]/', '', (string)($row['label'] ?? '')));
			if (!mb_check_encoding($label, 'UTF-8') || mb_strlen($label, 'UTF-8') > 64) {
				$error = 'message-schedule_rule_label_invalid';
				return false;
			}
			$rule = ['type' => $type, 'label' => $label];

			//the time window of weekly and annual rules
			if ($type === 'weekly' || $type === 'annual') {
				$all_day = in_array($row['all_day'] ?? false, [true, 'true', '1', 1, 'on'], true);
				$rule['all_day'] = $all_day;
				$rule['from'] = '';
				$rule['to'] = '';
				if (!$all_day) {
					$from = self::minutes($row['from'] ?? '');
					$to = self::minutes($row['to'] ?? '', true);
					if ($from === null || $to === null) {
						$error = 'message-schedule_rule_time_invalid';
						return false;
					}
					if ($to <= $from) {
						$error = 'message-schedule_rule_time_overnight';
						return false;
					}
					$rule['from'] = sprintf('%02d:%02d', intdiv($from, 60), $from % 60);
					$rule['to'] = sprintf('%02d:%02d', intdiv($to, 60), $to % 60);
				}
			}

			if ($type === 'once') {
				$start = (string)($row['start'] ?? '');
				$stop = (string)($row['stop'] ?? '');
				$dt_start = DateTime::createFromFormat('!Y-m-d H:i', $start);
				$dt_stop = DateTime::createFromFormat('!Y-m-d H:i', $stop);
				if ($dt_start === false || $dt_stop === false || $dt_start->format('Y-m-d H:i') !== $start || $dt_stop->format('Y-m-d H:i') !== $stop) {
					$error = 'message-schedule_date_invalid';
					return false;
				}
				if ($dt_stop <= $dt_start) {
					$error = 'message-schedule_range_invalid';
					return false;
				}
				$rule['start'] = $start;
				$rule['stop'] = $stop;
			}
			else if ($type === 'weekly') {
				$days = [];
				foreach ((array)($row['days'] ?? []) as $day) {
					if (preg_match('/^[1-7]$/', (string)$day)) {
						$days[(int)$day] = (int)$day;
					}
				}
				if (empty($days)) {
					$error = 'message-schedule_rule_days_required';
					return false;
				}
				ksort($days);
				$rule['days'] = array_values($days);
			}
			else if ($type === 'annual') {
				$month = (int)($row['month'] ?? 0);
				if ($month < 1 || $month > 12) {
					$error = 'message-schedule_rule_date_invalid';
					return false;
				}
				$rule['month'] = $month;
				$kind = ($row['kind'] ?? 'date') === 'nth' ? 'nth' : 'date';
				$rule['kind'] = $kind;
				if ($kind === 'date') {
					$mday = (int)($row['mday'] ?? 0);
					if ($mday < 1 || $mday > self::month_days($month)) {
						$error = 'message-schedule_rule_date_invalid';
						return false;
					}
					$rule['mday'] = $mday;
					$rule['observed'] = in_array($row['observed'] ?? false, [true, 'true', '1', 1, 'on'], true);
					//the neighbours of these days depend on the leap year
					if ($rule['observed'] && (($month == 2 && $mday >= 28) || ($month == 3 && $mday == 1))) {
						$error = 'message-schedule_rule_observed_february';
						return false;
					}
				}
				else {
					$nth = (string)($row['nth'] ?? '');
					$wday = (int)($row['wday'] ?? 0);
					$offset = (string)($row['offset'] ?? '0');
					if (!in_array($nth, ['1', '2', '3', '4', 'last'], true) || $wday < 1 || $wday > 7 || !preg_match('/^[+-]?[0-6]$/', $offset)) {
						$error = 'message-schedule_rule_date_invalid';
						return false;
					}
					if ($nth === 'last' && $month == 2) {
						$error = 'message-schedule_rule_last_february';
						return false;
					}
					$rule['nth'] = $nth;
					$rule['wday'] = $wday;
					$rule['offset'] = (int)$offset;
					[$low, $high] = self::nth_range($rule);
					//an offset past the 28th of february would miss common years
					if ($low < 1 || $high > ($month == 2 ? 28 : self::month_days($month))) {
						$error = 'message-schedule_rule_offset_month';
						return false;
					}
				}
			}
			else {
				$error = 'message-schedule_rule_type_invalid';
				return false;
			}
			$rules[] = $rule;
		}
		$error_rule = null;
		if (empty($rules)) {
			$error = 'message-schedule_rules_required';
			return false;
		}
		if (count($rules) > self::max_rules) {
			$error = 'message-schedule_rules_too_many';
			return false;
		}
		return $rules;
	}

	/**
	 * The mday range of an nth weekday rule, after the offset
	 *
	 * @param array $rule
	 * @return array low, high
	 */
	private static function nth_range(array $rule) {
		if ($rule['nth'] === 'last') {
			//february is refused, its length varies
			$high = [1 => 31, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31][$rule['month']];
			$low = $high - 6;
		}
		else {
			$low = ((int)$rule['nth'] - 1) * 7 + 1;
			$high = (int)$rule['nth'] * 7;
		}
		return [$low + $rule['offset'], $high + $rule['offset']];
	}

	/**
	 * The minute-of-day condition of a window, as the stock editor writes it
	 *
	 * @param array $rule
	 * @return array|null type, data
	 */
	private static function window(array $rule) {
		if (!empty($rule['all_day'])) {
			return null;
		}
		$from = self::minutes($rule['from']);
		$to = self::minutes($rule['to'], true);
		return ['minute-of-day', ($from + 1).'-'.$to];
	}

	/**
	 * The condition groups of a rule: a list of groups, each a list of [type, data] time conditions
	 *
	 * @param array $rule a normalized rule
	 * @param callable|null $to_utc converts a 'Y-m-d H:i' date-time as the stock editor does, see save_schedule
	 * @return array
	 */
	public static function groups(array $rule, $to_utc = null) {
		$groups = [];
		$window = self::window($rule);
		if ($rule['type'] === 'once') {
			$start = $to_utc ? $to_utc($rule['start']) : $rule['start'];
			$stop = $to_utc ? $to_utc($rule['stop']) : $rule['stop'];
			$groups[] = [['date-time', $start.'~'.$stop]];
		}
		else if ($rule['type'] === 'weekly') {
			//one group per run of consecutive days, as a single wday range like the stock editor writes
			$runs = [];
			foreach ($rule['days'] as $day) {
				$last = count($runs) - 1;
				if ($last >= 0 && end($runs[$last]) == $day - 1) {
					$runs[$last][] = $day;
				}
				else {
					$runs[] = [$day];
				}
			}
			foreach ($runs as $run) {
				$group = [['wday', count($run) == 1 ? (string)$run[0] : $run[0].'-'.end($run)]];
				if ($window) {
					$group[] = $window;
				}
				$groups[] = $group;
			}
		}
		else if ($rule['type'] === 'annual') {
			if ($rule['kind'] === 'date') {
				$days = [[$rule['month'], $rule['mday'], null]];
				if (!empty($rule['observed'])) {
					//observed on the friday before a saturday and the monday after a sunday
					//(2000 is a leap year, so a leap day rule has neighbours too)
					$before = new DateTime(sprintf('2000-%02d-%02d', $rule['month'], $rule['mday']));
					$after = clone $before;
					$before->modify('-1 day');
					$after->modify('+1 day');
					$days[] = [(int)$before->format('n'), (int)$before->format('j'), 6];
					$days[] = [(int)$after->format('n'), (int)$after->format('j'), 2];
				}
				foreach ($days as [$month, $mday, $wday]) {
					$group = [['mon', (string)$month], ['mday', (string)$mday]];
					if ($wday !== null) {
						$group[] = ['wday', (string)$wday];
					}
					if ($window) {
						$group[] = $window;
					}
					$groups[] = $group;
				}
			}
			else {
				[$low, $high] = self::nth_range($rule);
				$wday = (($rule['wday'] - 1 + $rule['offset']) % 7 + 7) % 7 + 1;
				$group = [['mon', (string)$rule['month']], ['mday', $low.'-'.$high], ['wday', (string)$wday]];
				if ($window) {
					$group[] = $window;
				}
				$groups[] = $group;
			}
		}
		return $groups;
	}

	/**
	 * Whether a value is in a FreeSWITCH number condition: n or n-m
	 *
	 * @param string $condition
	 * @param int $value
	 * @return bool
	 */
	private static function number_match($condition, $value) {
		foreach (explode(',', $condition) as $part) {
			if (preg_match('/^\s*([0-9]+)\s*-\s*([0-9]+)\s*$/', $part, $m)) {
				if ($value >= (int)$m[1] && $value <= (int)$m[2]) {
					return true;
				}
			}
			else if (preg_match('/^\s*([0-9]+)\s*$/', $part, $m) && $value == (int)$m[1]) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a group matches at a time, evaluated as FreeSWITCH does (minute-of-day counts from 1 at midnight)
	 *
	 * @param array $group list of [type, data]
	 * @param DateTime $time in the time zone the conditions are evaluated in
	 * @return bool
	 */
	public static function group_matches(array $group, DateTime $time) {
		foreach ($group as [$type, $data]) {
			switch ($type) {
				case 'date-time':
					[$start, $stop] = array_pad(explode('~', $data, 2), 2, '');
					$now = $time->format('Y-m-d H:i');
					if ($now < $start || $now >= $stop) {
						return false;
					}
					break;
				case 'mon':
					if (!self::number_match($data, (int)$time->format('n'))) {
						return false;
					}
					break;
				case 'mday':
					if (!self::number_match($data, (int)$time->format('j'))) {
						return false;
					}
					break;
				case 'wday':
					if (!self::number_match($data, (int)$time->format('w') + 1)) {
						return false;
					}
					break;
				case 'minute-of-day':
					if (!self::number_match($data, (int)$time->format('G') * 60 + (int)$time->format('i') + 1)) {
						return false;
					}
					break;
				default:
					return false;
			}
		}
		return true;
	}

	/**
	 * Whether any rule matches at a time
	 *
	 * @param array $rules normalized rules
	 * @param DateTime $time
	 * @return bool
	 */
	public static function active(array $rules, DateTime $time) {
		foreach ($rules as $rule) {
			foreach (self::groups($rule) as $group) {
				if (self::group_matches($group, $time)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The next day, from today on, an annual rule matches
	 *
	 * @param array $rule a normalized annual rule
	 * @param DateTime $today
	 * @return string|null Y-m-d
	 */
	public static function next_date(array $rule, DateTime $today) {
		//match on the day only, the window is shown with the rule
		$day_rule = $rule;
		$day_rule['all_day'] = true;
		$groups = self::groups($day_rule);
		$day = clone $today;
		$day->setTime(12, 0);
		//a leap day can be up to eight years away
		for ($x = 0; $x < 366 * 8 + 2; $x++) {
			foreach ($groups as $group) {
				if (self::group_matches($group, $day)) {
					return $day->format('Y-m-d');
				}
			}
			$day->modify('+1 day');
		}
		return null;
	}

}

?>
