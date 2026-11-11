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
 * Validation of parsed feed entries (docs/design/carrier_acl.md section 3). Parser output is never trusted.
 *
 * Every entry goes through: syntax and normalisation, prefix width thresholds, reserved ranges (unless the feed allows
 * private ranges), the host's own addresses, duplicate collapse, and finally the min/max plausibility bounds.
 * The reserved list and the hard limits are mirrored in sbin/x-carrier-acl-apply; keep them in step.
 */
class x_carrier_acl_validate {

	//section 3.3, rejected unless feed_allow_private
	const reserved_v4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32'];
	const reserved_v6 = ['::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8'];

	//the root applier's fixed reject widths (section 7.3 step 3); used to filter the firewall snapshot
	const firewall_v4_reject_shorter_than = 16;
	const firewall_v6_reject_shorter_than = 32;

	//local networks (section 12): admin-entered LAN/VPN ranges. Private ranges (RFC1918, CGNAT, ULA) are allowed;
	//everything below is always refused. Mirrored in sbin/x-carrier-acl-apply LOCAL_RESERVED; keep them in step.
	//short prefixes are only allowed inside private containers; anything else gets the carrier widths above
	const local_v4_reject_shorter_than = 8;
	const local_v6_reject_shorter_than = 32;
	const local_private_v4 = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10'];
	const local_private_v6 = ['fc00::/7'];
	const local_reserved_v4 = ['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32'];
	const local_reserved_v6 = ['::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fe80::/10', 'ff00::/8'];
	const local_max = 500;

	private $v4_reject;
	private $v4_approve;
	private $v6_reject;
	private $v6_approve;
	private $allow_private;
	private $min_entries;
	private $max_entries;
	private $own_addresses;

	/**
	 * @param array $config v4_reject_shorter_than, v4_approve_shorter_than, v6_reject_shorter_than,
	 *                      v6_approve_shorter_than, allow_private, min_entries, max_entries, own_addresses
	 */
	public function __construct(array $config) {
		$this->v4_reject = min(32, max(0, (int)($config['v4_reject_shorter_than'] ?? 16)));
		$this->v4_approve = min(32, max($this->v4_reject, (int)($config['v4_approve_shorter_than'] ?? 20)));
		$this->v6_reject = min(128, max(0, (int)($config['v6_reject_shorter_than'] ?? 32)));
		$this->v6_approve = min(128, max($this->v6_reject, (int)($config['v6_approve_shorter_than'] ?? 48)));
		$this->allow_private = (bool)($config['allow_private'] ?? false);
		$this->min_entries = max(0, (int)($config['min_entries'] ?? 1));
		$this->max_entries = max(1, (int)($config['max_entries'] ?? 5000));
		$this->own_addresses = [];
		foreach ($config['own_addresses'] ?? [] as $address) {
			$packed = @inet_pton((string)$address);
			if ($packed !== false) {
				$this->own_addresses[] = $packed;
			}
		}
	}

	/**
	 * Build a validator for a feed row from the app settings.
	 */
	public static function for_feed(x_carrier_acl $app, array $feed, array $own_addresses): self {
		$allow_private = $feed['feed_allow_private'] ?? false;
		return new self([
			'v4_reject_shorter_than' => $app->setting_int('v4_reject_shorter_than'),
			'v4_approve_shorter_than' => $app->setting_int('v4_approve_shorter_than'),
			'v6_reject_shorter_than' => $app->setting_int('v6_reject_shorter_than'),
			'v6_approve_shorter_than' => $app->setting_int('v6_approve_shorter_than'),
			'allow_private' => ($allow_private === true || $allow_private === 'true' || $allow_private === 't' || $allow_private === 1),
			'min_entries' => is_numeric($feed['feed_min_entries'] ?? null) ? (int)$feed['feed_min_entries'] : 1,
			'max_entries' => is_numeric($feed['feed_max_entries'] ?? null) ? (int)$feed['feed_max_entries'] : 5000,
			'own_addresses' => $own_addresses,
		]);
	}

	/**
	 * Validate parser output.
	 *
	 * @return array [
	 *   'accepted' => ["cidr|role" => ['cidr', 'family', 'prefix', 'role', 'label', 'broad']],
	 *   'rejected' => [['value', 'role', 'label', 'reason']],
	 *   'warnings' => [string],
	 *   'error' => null|string   plausibility failure (min/max)
	 * ]
	 */
	public function validate(array $entries): array {
		$accepted = [];
		$rejected = [];
		$warnings = [];
		foreach ($entries as $entry) {
			$raw = is_array($entry) ? trim((string)($entry['cidr'] ?? '')) : '';
			$role = is_array($entry) ? ($entry['role'] ?? '') : '';
			$label = is_array($entry) ? self::clean_label((string)($entry['label'] ?? '')) : '';
			if (!in_array($role, ['signaling', 'media'], true)) {
				$rejected[] = ['value' => substr($raw, 0, 64), 'role' => (string)$role, 'label' => $label, 'reason' => 'invalid role'];
				continue;
			}
			$normalised = self::normalise($raw);
			if (is_string($normalised)) {
				$rejected[] = ['value' => substr($raw, 0, 64), 'role' => $role, 'label' => $label, 'reason' => $normalised];
				continue;
			}
			if ($normalised['host_bits']) {
				$warnings[] = $raw.' normalised to '.$normalised['cidr'];
			}
			$reason = $this->check($normalised);
			if ($reason !== null) {
				$rejected[] = ['value' => $normalised['cidr'], 'role' => $role, 'label' => $label, 'reason' => $reason];
				continue;
			}
			$key = $normalised['cidr'].'|'.$role;
			if (isset($accepted[$key])) {
				continue;
			}
			$accepted[$key] = [
				'cidr' => $normalised['cidr'],
				'family' => $normalised['family'],
				'prefix' => $normalised['prefix'],
				'role' => $role,
				'label' => $label,
				'broad' => $this->is_broad($normalised),
			];
		}
		ksort($accepted, SORT_STRING);
		$error = null;
		if (count($accepted) < $this->min_entries) {
			$error = 'only '.count($accepted).' valid entries (minimum '.$this->min_entries.')';
		}
		elseif (count($accepted) > $this->max_entries) {
			$error = count($accepted).' valid entries (maximum '.$this->max_entries.')';
		}
		return ['accepted' => $accepted, 'rejected' => $rejected, 'warnings' => array_values(array_unique($warnings)), 'error' => $error];
	}

	/**
	 * Valid UTF-8, no control characters, at most 128 bytes without splitting a character (postgres rejects
	 * invalid UTF-8, and json_encode fails on it).
	 */
	public static function clean_label(string $label): string {
		$label = mb_scrub($label, 'UTF-8');
		$label = preg_replace('/[\x00-\x1f\x7f]/u', '', $label) ?? '';
		return mb_strcut($label, 0, 128, 'UTF-8');
	}

	/**
	 * Width, reserved range and own address checks. Returns a reject reason or null.
	 */
	private function check(array $n): ?string {
		if ($n['prefix'] === 0) {
			return 'default route is never allowed';
		}
		$reject = ($n['family'] === 4) ? $this->v4_reject : $this->v6_reject;
		if ($n['prefix'] < $reject) {
			return 'prefix shorter than /'.$reject;
		}
		if (!$this->allow_private && self::is_reserved($n)) {
			return 'reserved or private range';
		}
		foreach ($this->own_addresses as $packed) {
			if (strlen($packed) === strlen($n['packed']) && self::contains($n['packed'], $n['prefix'], $packed)) {
				return 'contains an address of this host';
			}
		}
		return null;
	}

	/**
	 * Needs approval width (section 3.2).
	 */
	private function is_broad(array $n): bool {
		$approve = ($n['family'] === 4) ? $this->v4_approve : $this->v6_approve;
		return $n['prefix'] < $approve;
	}

	/**
	 * Parse "addr" or "addr/prefix". Returns an array (cidr, family, prefix, packed, host_bits) or an error string.
	 */
	public static function normalise(string $value): array|string {
		if ($value === '' || strlen($value) > 64) {
			return 'empty or too long';
		}
		$parts = explode('/', $value);
		if (count($parts) > 2) {
			return 'invalid syntax';
		}
		//inet_pton accepts no zone ids or whitespace, which is what we want
		$packed = @inet_pton($parts[0]);
		if ($packed === false) {
			return 'invalid address';
		}
		$family = (strlen($packed) === 4) ? 4 : 6;
		$max = ($family === 4) ? 32 : 128;
		if (count($parts) === 2) {
			if (!preg_match('/^\d{1,3}$/', $parts[1])) {
				return 'invalid prefix';
			}
			$prefix = (int)$parts[1];
			if ($prefix > $max) {
				return 'prefix out of range';
			}
		}
		else {
			$prefix = $max;
		}
		$network = self::mask($packed, $prefix);
		return [
			'cidr' => inet_ntop($network).'/'.$prefix,
			'family' => $family,
			'prefix' => $prefix,
			'packed' => $network,
			'host_bits' => ($network !== $packed),
		];
	}

	/**
	 * Clear host bits.
	 */
	public static function mask(string $packed, int $prefix): string {
		$result = '';
		$length = strlen($packed);
		for ($i = 0; $i < $length; $i++) {
			$bits = max(0, min(8, $prefix - ($i * 8)));
			$byte_mask = ($bits === 0) ? 0 : ((0xff << (8 - $bits)) & 0xff);
			$result .= chr(ord($packed[$i]) & $byte_mask);
		}
		return $result;
	}

	/**
	 * True when $address lies within network/prefix (same family).
	 */
	public static function contains(string $network, int $prefix, string $address): bool {
		return self::mask($address, $prefix) === $network;
	}

	/**
	 * True when the network overlaps any reserved range (either contains the other).
	 */
	public static function is_reserved(array $n): bool {
		return self::overlaps_any($n, ($n['family'] === 4) ? self::reserved_v4 : self::reserved_v6);
	}

	/**
	 * True when the network overlaps any of the given ranges (either contains the other).
	 */
	private static function overlaps_any(array $n, array $ranges): bool {
		foreach ($ranges as $range) {
			[$address, $prefix] = explode('/', $range);
			$prefix = (int)$prefix;
			$range_packed = self::mask(inet_pton($address), $prefix);
			$common = min($prefix, $n['prefix']);
			if (self::mask($n['packed'], $common) === self::mask($range_packed, $common)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when an entry may be exported to the firewall snapshot: the root applier's fixed rules.
	 */
	public static function firewall_allowed(string $cidr): bool {
		$n = self::normalise($cidr);
		if (!is_array($n) || $n['host_bits'] || $n['prefix'] === 0) {
			return false;
		}
		$reject = ($n['family'] === 4) ? self::firewall_v4_reject_shorter_than : self::firewall_v6_reject_shorter_than;
		if ($n['prefix'] < $reject) {
			return false;
		}
		return !self::is_reserved($n);
	}

	/**
	 * Validate an admin-entered local network (section 12). Private ranges are allowed; the default route, prefixes
	 * shorter than /8 (v4) inside a private range or /16 elsewhere, /32 (v6), loopback, unspecified, link-local, multicast, documentation and other
	 * special ranges are refused. Host bits are refused rather than silently cleared: an admin typing 10.1.2.3/24
	 * should see what is stored.
	 *
	 * @return array|string normalised network (self::normalise) or a reject reason
	 */
	public static function local_network(string $value): array|string {
		$n = self::normalise(trim($value));
		if (is_string($n)) {
			return $n;
		}
		if ($n['host_bits']) {
			return 'host bits set; use '.$n['cidr'];
		}
		if ($n['prefix'] === 0) {
			return 'default route is never allowed';
		}
		if (self::inside_any($n, ($n['family'] === 4) ? self::local_private_v4 : self::local_private_v6)) {
			$reject = ($n['family'] === 4) ? self::local_v4_reject_shorter_than : self::local_v6_reject_shorter_than;
		}
		else {
			$reject = ($n['family'] === 4) ? self::firewall_v4_reject_shorter_than : self::firewall_v6_reject_shorter_than;
		}
		if ($n['prefix'] < $reject) {
			return 'prefix shorter than /'.$reject;
		}
		if (self::overlaps_any($n, ($n['family'] === 4) ? self::local_reserved_v4 : self::local_reserved_v6)) {
			return 'loopback, link-local, multicast or other special range';
		}
		return $n;
	}

	/**
	 * True when the network lies wholly inside one of the ranges.
	 */
	private static function inside_any(array $n, array $ranges): bool {
		foreach ($ranges as $range) {
			[$address, $prefix] = explode('/', $range);
			$prefix = (int)$prefix;
			if ($n['prefix'] >= $prefix && self::mask($n['packed'], $prefix) === self::mask(inet_pton($address), $prefix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when a stored local network may be exported to the firewall snapshot (the applier's local rules).
	 */
	public static function local_firewall_allowed(string $cidr): bool {
		return is_array(self::local_network($cidr));
	}

}

?>
