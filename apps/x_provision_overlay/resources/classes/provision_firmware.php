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

/*
	Phone firmware (docs/design/provision_firmware.md).

	A library of vendor firmware files (releases), one per file, stored as

		/var/lib/fusionpbx/provision_overlay/firmware/<release_uuid>/<served name>

	and recorded in v_x_provision_overlay_firmware. A domain sets a default per family (release, none, or
	unset); a device target overrides it (manual, or written by a rollout). Phones are pointed at their release
	with device settings, exactly as wallpapers (provision_wallpapers.php): the rows written are recorded in
	v_x_provision_overlay_firmware_links, and values set elsewhere win.

	Family: Yealink, the ROM prefix (first field of the version: 66.86.0.15 is family 66), read from the user
	agent the phone reports and from the release's file name. Grandstream, the vendor file name the phone
	requests (gxp2170fw.bin); a release lists the models it serves.
*/

//define the provision_firmware class
class provision_firmware {

	//limits
	const grace_seconds = 86400;
	const download_keep_days = 90;
	const sync_all_lock_timeout = 5;
	const template_max_bytes = 2097152;
	const notify_attempts = 3;
	const reboot_attempts = 3;
	const fail_after_seconds = 1800;
	const not_fetching_seconds = 86400;
	const rollout_lock_timeout = 30;

	//seconds between a rollout device's fetch and its reboot: a Yealink that fetches a newer ROM downloads and
	//flashes it on its own (and reboots), so it must not be rebooted meanwhile; a Grandstream checks at boot
	const reboot_delay = ['yealink' => 1200, 'grandstream' => 300];

	//files
	const request_file = '.firmware-sync.request';
	const rollout_lock_file = '.firmware-rollouts.lock';
	const template_cache_file = '.firmware-templates.json';
	const deleted_prefix = '.deleted-';

	//phone-facing paths (nginx: resources/apps/provision_overlay.sh)
	const url_path = '/provision-firmware/';
	const none_segment = 'none';
	const none_file = 'none.rom';

	//device settings written by the sync
	const setting_category = 'provision';
	const setting_description = 'Phone firmware (managed by Provision Templates > Firmware; disable this row to opt the device out)';

	//rollout target states (docs/design/provision_firmware.md 3.4)
	const rollout_states = ['written', 'notified', 'not_fetching', 'fetched', 'rebooting', 'rebooted', 'done', 'pending_boot', 'failed'];

	/*
		Catalog: template => variables it prints, generated from upstream's templates at the commit pinned in
		config.sh with test/firmware_catalog.php (checked by test/checks.sh). Yealink: yealink_firmware_url and
		every yealink_firmware_<var> of the dir; each gets the device's file name, so the cfg the phone fetches
		(mixed templates, upstream variable mix-ups) reads the right value. Grandstream: grandstream_firmware_path.
	*/
	const catalog = [
		//BEGIN catalog
		'yealink/ax83h' => ['yealink_firmware_ax83h', 'yealink_firmware_url'],
		'yealink/ax86r' => ['yealink_firmware_ax83h', 'yealink_firmware_url'],
		'yealink/cp860' => ['yealink_firmware_cp860', 'yealink_firmware_url'],
		'yealink/cp920' => ['yealink_firmware_cp920', 'yealink_firmware_url'],
		'yealink/cp925' => ['yealink_firmware_cp920', 'yealink_firmware_url'],
		'yealink/cp960' => ['yealink_firmware_cp960', 'yealink_firmware_url'],
		'yealink/cp965' => ['yealink_firmware_cp965', 'yealink_firmware_url'],
		'yealink/t19p' => ['yealink_firmware_t19p', 'yealink_firmware_url'],
		'yealink/t20p' => ['yealink_firmware_t20p', 'yealink_firmware_url'],
		'yealink/t21p' => ['yealink_firmware_t21p', 'yealink_firmware_url'],
		'yealink/t22p' => ['yealink_firmware_t22p', 'yealink_firmware_url'],
		'yealink/t23g' => ['yealink_firmware_t23g', 'yealink_firmware_url'],
		'yealink/t23p' => ['yealink_firmware_t23p', 'yealink_firmware_url'],
		'yealink/t26p' => ['yealink_firmware_t26p', 'yealink_firmware_url'],
		'yealink/t27g' => ['yealink_firmware_t27g', 'yealink_firmware_url'],
		'yealink/t27p' => ['yealink_firmware_t27p', 'yealink_firmware_url'],
		'yealink/t28p' => ['yealink_firmware_t28p', 'yealink_firmware_url'],
		'yealink/t29g' => ['yealink_firmware_t29g', 'yealink_firmware_url'],
		'yealink/t2x' => ['yealink_firmware_t19p', 'yealink_firmware_t21p', 'yealink_firmware_t23g', 'yealink_firmware_t27g', 'yealink_firmware_url'],
		'yealink/t31g' => ['yealink_firmware_t31g', 'yealink_firmware_url'],
		'yealink/t31w' => ['yealink_firmware_t31w', 'yealink_firmware_url'],
		'yealink/t32g' => ['yealink_firmware_t32g', 'yealink_firmware_url'],
		'yealink/t33g' => ['yealink_firmware_t33g', 'yealink_firmware_url'],
		'yealink/t34w' => ['yealink_firmware_t34w', 'yealink_firmware_url'],
		'yealink/t38g' => ['yealink_firmware_t38g', 'yealink_firmware_url'],
		'yealink/t40g' => ['yealink_firmware_t40g', 'yealink_firmware_url'],
		'yealink/t40p' => ['yealink_firmware_t40p', 'yealink_firmware_url'],
		'yealink/t41p' => ['yealink_firmware_t41p', 'yealink_firmware_url'],
		'yealink/t41s' => ['yealink_firmware_t41s', 'yealink_firmware_url'],
		'yealink/t42g' => ['yealink_firmware_t42g', 'yealink_firmware_url'],
		'yealink/t42s' => ['yealink_firmware_t42s', 'yealink_firmware_url'],
		'yealink/t42u' => ['yealink_firmware_t42g', 'yealink_firmware_url'],
		'yealink/t43u' => ['yealink_firmware_t43u', 'yealink_firmware_url'],
		'yealink/t44w' => ['yealink_firmware_t44w', 'yealink_firmware_url'],
		'yealink/t46g' => ['yealink_firmware_t46g', 'yealink_firmware_url'],
		'yealink/t46s' => ['yealink_firmware_t46s', 'yealink_firmware_url'],
		'yealink/t46u' => ['yealink_firmware_t46u', 'yealink_firmware_url'],
		'yealink/t48g' => ['yealink_firmware_t48g', 'yealink_firmware_url'],
		'yealink/t48s' => ['yealink_firmware_t48s', 'yealink_firmware_url'],
		'yealink/t48u' => ['yealink_firmware_t48u', 'yealink_firmware_url'],
		'yealink/t49g' => ['yealink_firmware_t49g', 'yealink_firmware_url'],
		'yealink/t4x' => ['yealink_firmware_t40g', 'yealink_firmware_t40p', 'yealink_firmware_t41p', 'yealink_firmware_t41s', 'yealink_firmware_t42g', 'yealink_firmware_t42s', 'yealink_firmware_t46g', 'yealink_firmware_t46s', 'yealink_firmware_t48g', 'yealink_firmware_t48s', 'yealink_firmware_url'],
		'yealink/t52s' => ['yealink_firmware_t52s', 'yealink_firmware_url'],
		'yealink/t53' => ['yealink_firmware_t53', 'yealink_firmware_url'],
		'yealink/t53w' => ['yealink_firmware_t53w', 'yealink_firmware_url'],
		'yealink/t54s' => ['yealink_firmware_t54s', 'yealink_firmware_url'],
		'yealink/t54w' => ['yealink_firmware_t54w', 'yealink_firmware_url'],
		'yealink/t56a' => ['yealink_firmware_t56a', 'yealink_firmware_url'],
		'yealink/t57w' => ['yealink_firmware_t57w', 'yealink_firmware_url'],
		'yealink/t58a' => ['yealink_firmware_t58a', 'yealink_firmware_url'],
		'yealink/t58v' => ['yealink_firmware_t58v', 'yealink_firmware_url'],
		'yealink/t58w' => ['yealink_firmware_t58w', 'yealink_firmware_url'],
		'yealink/t5x' => ['yealink_firmware_t53w', 'yealink_firmware_t54s', 'yealink_firmware_t54w', 'yealink_firmware_t57w', 'yealink_firmware_url'],
		'yealink/t73u' => ['yealink_firmware_t73u', 'yealink_firmware_url'],
		'yealink/t73w' => ['yealink_firmware_t73w', 'yealink_firmware_url'],
		'yealink/t74u' => ['yealink_firmware_t74u', 'yealink_firmware_url'],
		'yealink/t74w' => ['yealink_firmware_t74w', 'yealink_firmware_url'],
		'yealink/t77u' => ['yealink_firmware_t77u', 'yealink_firmware_url'],
		'yealink/t85w' => ['yealink_firmware_t85w', 'yealink_firmware_url'],
		'yealink/t87w' => ['yealink_firmware_t87w', 'yealink_firmware_url'],
		'yealink/t88v' => ['yealink_firmware_t88v', 'yealink_firmware_url'],
		'yealink/t88w' => ['yealink_firmware_t88w', 'yealink_firmware_url'],
		'yealink/vp530' => ['yealink_firmware_url', 'yealink_firmware_vp530'],
		'yealink/w52p' => ['yealink_firmware_url', 'yealink_firmware_w52p'],
		'yealink/w56p' => ['yealink_firmware_url', 'yealink_firmware_w56p'],
		'yealink/w60b' => ['yealink_firmware_url', 'yealink_firmware_w60b'],
		'yealink/w70b' => ['yealink_firmware_url', 'yealink_firmware_w70b'],
		'yealink/w7xp' => ['yealink_firmware_url', 'yealink_firmware_w60b'],
		'yealink/w80' => ['yealink_firmware_url', 'yealink_firmware_w80'],
		'grandstream/dp715' => ['grandstream_firmware_path'],
		'grandstream/dp715.sm' => ['grandstream_firmware_path'],
		'grandstream/dp750' => ['grandstream_firmware_path'],
		'grandstream/gac2500' => ['grandstream_firmware_path'],
		'grandstream/gds3705' => ['grandstream_firmware_path'],
		'grandstream/gds3710' => ['grandstream_firmware_path'],
		'grandstream/ghp6xx' => ['grandstream_firmware_path'],
		'grandstream/grp2612' => ['grandstream_firmware_path'],
		'grandstream/grp2612w' => ['grandstream_firmware_path'],
		'grandstream/grp2613' => ['grandstream_firmware_path'],
		'grandstream/grp2614' => ['grandstream_firmware_path'],
		'grandstream/grp2615' => ['grandstream_firmware_path'],
		'grandstream/grp2616' => ['grandstream_firmware_path'],
		'grandstream/grp261x' => ['grandstream_firmware_path'],
		'grandstream/grp26xx' => ['grandstream_firmware_path'],
		'grandstream/gsc3516' => ['grandstream_firmware_path'],
		'grandstream/gsc3518hs' => ['grandstream_firmware_path'],
		'grandstream/gxp110x' => ['grandstream_firmware_path'],
		'grandstream/gxp116x' => ['grandstream_firmware_path'],
		'grandstream/gxp140x' => ['grandstream_firmware_path'],
		'grandstream/gxp140xbk' => ['grandstream_firmware_path'],
		'grandstream/gxp1450' => ['grandstream_firmware_path'],
		'grandstream/gxp1450bk' => ['grandstream_firmware_path'],
		'grandstream/gxp16xx' => ['grandstream_firmware_path'],
		'grandstream/gxp17xx' => ['grandstream_firmware_path'],
		'grandstream/gxp20xx' => ['grandstream_firmware_path'],
		'grandstream/gxp2100' => ['grandstream_firmware_path'],
		'grandstream/gxp2124' => ['grandstream_firmware_path'],
		'grandstream/gxp2130' => ['grandstream_firmware_path'],
		'grandstream/gxp2135' => ['grandstream_firmware_path'],
		'grandstream/gxp2140' => ['grandstream_firmware_path'],
		'grandstream/gxp2160' => ['grandstream_firmware_path'],
		'grandstream/gxp2170' => ['grandstream_firmware_path'],
		'grandstream/gxp21xx' => ['grandstream_firmware_path'],
		'grandstream/gxp21xxbk' => ['grandstream_firmware_path'],
		'grandstream/gxp2200' => ['grandstream_firmware_path'],
		'grandstream/gxp3240' => ['grandstream_firmware_path'],
		'grandstream/gxv300x' => ['grandstream_firmware_path'],
		'grandstream/gxv3140' => ['grandstream_firmware_path'],
		'grandstream/gxv3175' => ['grandstream_firmware_path'],
		'grandstream/gxv3175v2' => ['grandstream_firmware_path'],
		'grandstream/gxv3240' => ['grandstream_firmware_path'],
		'grandstream/gxv3275' => ['grandstream_firmware_path'],
		'grandstream/gxv3370' => ['grandstream_firmware_path'],
		'grandstream/gxv3480' => ['grandstream_firmware_path'],
		'grandstream/gxv3504' => ['grandstream_firmware_path'],
		'grandstream/gxw4004' => ['grandstream_firmware_path'],
		'grandstream/gxw4008' => ['grandstream_firmware_path'],
		'grandstream/gxw40xx' => ['grandstream_firmware_path'],
		'grandstream/gxw410x' => ['grandstream_firmware_path'],
		'grandstream/gxw42xx' => ['grandstream_firmware_path'],
		'grandstream/ht502' => ['grandstream_firmware_path'],
		'grandstream/ht503' => ['grandstream_firmware_path'],
		'grandstream/ht701' => ['grandstream_firmware_path'],
		'grandstream/ht702' => ['grandstream_firmware_path'],
		'grandstream/ht704' => ['grandstream_firmware_path'],
		'grandstream/ht801' => ['grandstream_firmware_path'],
		'grandstream/ht802' => ['grandstream_firmware_path'],
		'grandstream/ht813' => ['grandstream_firmware_path'],
		'grandstream/ht814' => ['grandstream_firmware_path'],
		'grandstream/ht818' => ['grandstream_firmware_path'],
		'grandstream/ht8x1' => ['grandstream_firmware_path'],
		'grandstream/htx86' => ['grandstream_firmware_path'],
		'grandstream/wp810' => ['grandstream_firmware_path'],
		'grandstream/wp820' => ['grandstream_firmware_path'],
		'grandstream/wp826' => ['grandstream_firmware_path'],
		'grandstream/wp8x6' => ['grandstream_firmware_path'],
		//END catalog
	];

	/**
	 * @var database|null
	 */
	private $database;

	/**
	 * @var provision_overlay owner of the overlay lock
	 */
	private $overlay;

	/**
	 * Initializes the object. The database is required for everything but the static helpers.
	 */
	public function __construct(array $setting_array = []) {
		$this->database = $setting_array['database'] ?? null;
		$this->overlay = $setting_array['overlay'] ?? new provision_overlay(['database' => $this->database]);
	}

	/**
	 * @var resource|null rollout lock (run_rollouts, batches, abort, finish, starting a rollout, device targets)
	 */
	private $rollout_lock_handle = null;
	private $rollout_lock_depth = 0;

	/**
	 * Rollout lock, re-entrant. Separate from the overlay lock, which the syncs take inside it.
	 */
	public function rollout_lock(int $timeout = self::rollout_lock_timeout): bool {
		if ($this->rollout_lock_depth > 0) {
			$this->rollout_lock_depth++;
			return true;
		}
		if (!is_dir(provision_overlay::state_root)) {
			return false;
		}
		$handle = @fopen(provision_overlay::state_root.'/'.self::rollout_lock_file, 'c');
		if ($handle === false) {
			return false;
		}
		$deadline = time() + $timeout;
		while (!flock($handle, LOCK_EX | LOCK_NB)) {
			if (time() >= $deadline) {
				fclose($handle);
				return false;
			}
			usleep(200000);
		}
		$this->rollout_lock_handle = $handle;
		$this->rollout_lock_depth = 1;
		return true;
	}

	public function rollout_unlock(): void {
		if ($this->rollout_lock_depth <= 0) {
			return;
		}
		$this->rollout_lock_depth--;
		if ($this->rollout_lock_depth === 0 && is_resource($this->rollout_lock_handle)) {
			flock($this->rollout_lock_handle, LOCK_UN);
			fclose($this->rollout_lock_handle);
			$this->rollout_lock_handle = null;
		}
	}

	private function endpoint_manager(): ?object {
		return (is_object($this->database) && class_exists('x_endpoint_manager')) ? new x_endpoint_manager(['database' => $this->database]) : null;
	}

	//-------------------------------------------------------------------------
	// catalog, names and user agents
	//-------------------------------------------------------------------------

	public static function vendor_of(string $template): string {
		return explode('/', $template, 2)[0];
	}

	/**
	 * Model and version from a user agent (provision.php stores it in device_provisioned_agent):
	 *   Yealink SIP-T46S 66.86.0.15 805ec0000000
	 *   Grandstream Model HW GXP2170 SW 1.0.11.79 DevId c074ad000000
	 *
	 * @return array ['model' => string, 'version' => string]
	 */
	public static function parse_agent(string $vendor, string $agent): array {
		$result = ['model' => '', 'version' => ''];
		if ($vendor === 'yealink' && preg_match('/^Yealink\s+(?:SIP[- ]?)?([A-Za-z0-9-]+)\s+([0-9]+(?:\.[0-9]+){3})/i', trim($agent), $m) === 1) {
			$result = ['model' => strtoupper($m[1]), 'version' => $m[2]];
		}
		elseif ($vendor === 'grandstream') {
			//a hardware revision may follow the model: HW GXP2170 V2.3B SW 1.0.11.79
			if (preg_match('/\bHW\s+([A-Za-z0-9-]+)(?:\s+(?!SW\b)\S+){0,3}\s+SW\s+([0-9]+(?:\.[0-9]+)+)/i', $agent, $m) === 1
				|| preg_match('/^Grandstream\s+([A-Za-z]{2,}[A-Za-z0-9-]*)\s+([0-9]+(?:\.[0-9]+)+)/i', trim($agent), $m) === 1) {
				$result = ['model' => strtoupper($m[1]), 'version' => $m[2]];
			}
		}
		return $result;
	}

	/**
	 * Family and version of a Yealink ROM from its file name (T46S(T48S,T42S,T41S)-66.86.0.15.rom), or null.
	 */
	public static function parse_rom_name(string $name): ?array {
		if (preg_match('/(?:^|[^0-9.])([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,4})\.rom$/i', $name, $m) !== 1) {
			return null;
		}
		return ['family' => $m[1], 'version' => $m[1].'.'.$m[2].'.'.$m[3].'.'.$m[4]];
	}

	/**
	 * Grandstream firmware file names: lower case, ending in fw.bin (gxp2170fw.bin).
	 */
	public static function valid_grandstream_file(string $name): bool {
		return preg_match('/^[a-z0-9][a-z0-9_-]{0,40}fw\.bin$/', $name) === 1;
	}

	/**
	 * Models as stored: upper case, comma separated, unique.
	 */
	public static function normalize_models(string $models): string {
		$list = [];
		foreach (preg_split('/[\s,]+/', strtoupper($models)) as $model) {
			if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,30}$/', $model) === 1) {
				$list[$model] = true;
			}
		}
		return implode(',', array_keys($list));
	}

	public static function valid_version(string $version): bool {
		return preg_match('/^[0-9]+(?:\.[0-9]+){1,4}$/', $version) === 1;
	}

	public static function firmware_root(): string {
		return provision_overlay::state_root.'/firmware';
	}

	public static function request_path(): string {
		return provision_overlay::state_root.'/'.self::request_file;
	}

	/**
	 * Path of a release's file, built from its row only, or null when the row is not valid.
	 */
	public static function release_path(array $release): ?string {
		$uuid = (string) ($release['firmware_uuid'] ?? '');
		$file = (string) ($release['firmware_file'] ?? '');
		if (!provision_overlay::valid_uuid($uuid) || ($file !== basename($file)) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $file) !== 1) {
			return null;
		}
		return self::firmware_root().'/'.$uuid.'/'.$file;
	}

	/**
	 * Values written for a device: release row, or null for none.
	 *
	 * @return array variable => value
	 */
	public static function values(string $template, ?array $release): array {
		$values = [];
		$segment = ($release === null) ? self::none_segment : (string) $release['firmware_uuid'];
		foreach (self::catalog[$template] ?? [] as $variable) {
			if ($variable === 'yealink_firmware_url') {
				$values[$variable] = 'http://{$domain_name}'.self::url_path.$segment;
			}
			elseif ($variable === 'grandstream_firmware_path') {
				//the scheme comes from the template (P212); it appends /<dir>, /<version>, which the front ignores
				$values[$variable] = '{$domain_name}'.self::url_path.$segment;
			}
			else {
				$values[$variable] = ($release === null) ? self::none_file : (string) $release['firmware_file'];
			}
		}
		return $values;
	}

	/**
	 * Request path to the release served: /provision-firmware/<uuid>/<anything>/<file>; the segments between
	 * are what a Grandstream template appends (<dir>, <version>, an empty one). Null when it doesn't match.
	 *
	 * @return array|null ['uuid' => string, 'file' => string]
	 */
	public static function parse_request(string $path): ?array {
		if (preg_match('#^'.preg_quote(self::url_path, '#').'([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/(?:(?:[A-Za-z0-9_-][A-Za-z0-9._-]{0,63})?/){0,4}([A-Za-z0-9][A-Za-z0-9._-]{0,63})$#', $path, $m) !== 1) {
			return null;
		}
		return ['uuid' => $m[1], 'file' => $m[2]];
	}

	//-------------------------------------------------------------------------
	// template check (as wallpapers 3.3)
	//-------------------------------------------------------------------------

	private static function template_base(): array {
		$current = provision_overlay::current_id();
		if (!provision_overlay::is_disabled() && $current !== '') {
			return [provision_overlay::current_link(), $current];
		}
		return [provision_overlay::upstream_dir(), 'upstream-'.provision_overlay::upstream_commit()];
	}

	/**
	 * Catalog templates whose merged files (the domain's subdir when it has one) still print every variable of
	 * their set. Cached per overlay build id.
	 *
	 * @return array template => bool
	 */
	public static function template_support(string $domain_name): array {
		[$base, $id] = self::template_base();
		$id .= '-'.substr(hash('sha256', (string) json_encode(self::catalog)), 0, 12);
		$scope = '';
		if ($domain_name !== '' && preg_match(provision_overlay::domain_name_pattern, $domain_name) === 1 && is_dir($base.'/'.$domain_name)) {
			$scope = $domain_name;
		}
		$cache_path = provision_overlay::state_root.'/'.self::template_cache_file;
		$cache = is_file($cache_path) ? json_decode((string) file_get_contents($cache_path), true) : null;
		if (!is_array($cache) || ($cache['id'] ?? '') !== $id || !is_array($cache['scopes'] ?? null)) {
			$cache = ['id' => $id, 'scopes' => []];
		}
		if (isset($cache['scopes'][$scope]) && is_array($cache['scopes'][$scope]) && count($cache['scopes'][$scope]) === count(self::catalog)) {
			return $cache['scopes'][$scope];
		}
		$root = ($scope !== '') ? $base.'/'.$scope : $base;
		$support = [];
		foreach (self::catalog as $template => $variables) {
			$support[$template] = self::template_prints($root.'/'.$template, $variables);
		}
		$cache['scopes'][$scope] = $support;
		if (is_dir(provision_overlay::state_root)) {
			$json = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			if ($json !== false) {
				self::write_atomic($cache_path, $json."\n");
			}
		}
		return $support;
	}

	/**
	 * True when the files of a template dir print every variable ({$name}).
	 */
	private static function template_prints(string $dir, array $variables): bool {
		if (!is_dir($dir)) {
			return false;
		}
		$content = '';
		$entries = @scandir($dir);
		foreach (is_array($entries) ? $entries : [] as $entry) {
			if ($entry === '' || $entry[0] === '.' || !is_file($dir.'/'.$entry)) {
				continue;
			}
			$size = filesize($dir.'/'.$entry);
			if ($size === false || $size > self::template_max_bytes) {
				continue;
			}
			$content .= (string) file_get_contents($dir.'/'.$entry)."\n";
		}
		foreach ($variables as $variable) {
			if (strpos($content, '{$'.$variable.'}') === false) {
				return false;
			}
		}
		return true;
	}

	//-------------------------------------------------------------------------
	// helpers
	//-------------------------------------------------------------------------

	private static function bool_value($value): bool {
		return $value === true || $value === 1 || $value === 't' || $value === 'true' || $value === '1';
	}

	private static function in_list(string $prefix, array $values): array {
		$names = [];
		$parameters = [];
		foreach (array_values($values) as $i => $value) {
			$names[] = ':'.$prefix.$i;
			$parameters[$prefix.$i] = $value;
		}
		return [implode(', ', $names), $parameters];
	}

	/**
	 * database::execute() that throws on failure, so a failed read never leads to writes.
	 */
	private function query(string $sql, ?array $parameters = null): array {
		if (!is_object($this->database)) {
			throw new RuntimeException('a database connection is required');
		}
		$rows = $this->database->execute($sql, $parameters, 'all');
		if ($rows === false) {
			$message = $this->database->message ?? null;
			throw new RuntimeException('database error: '.(is_array($message) ? ($message['message'] ?? 'unknown') : 'unknown'));
		}
		return is_array($rows) ? $rows : [];
	}

	private static function write_atomic(string $path, string $content): bool {
		$temp = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(6)).'.tmp';
		if (@file_put_contents($temp, $content) !== strlen($content)) {
			@unlink($temp);
			return false;
		}
		@chmod($temp, 0640);
		if (!@rename($temp, $path)) {
			@unlink($temp);
			return false;
		}
		return true;
	}

	private static function remove_tree(string $path): bool {
		if (is_link($path) || is_file($path)) {
			return @unlink($path);
		}
		if (!is_dir($path)) {
			return true;
		}
		$entries = @scandir($path);
		foreach (is_array($entries) ? $entries : [] as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				self::remove_tree($path.'/'.$entry);
			}
		}
		return @rmdir($path);
	}

	/**
	 * Asks x-provision-firmware.path to start the sync of all domains (www-data cannot start units itself).
	 */
	public function request_sync(): bool {
		return @file_put_contents(self::request_path(), gmdate('c')."\n") !== false;
	}

	//-------------------------------------------------------------------------
	// library
	//-------------------------------------------------------------------------

	/**
	 * Every release, newest first.
	 *
	 * @return array firmware_uuid => row
	 */
	public function releases(): array {
		$sql = "select firmware_uuid, firmware_vendor, firmware_family, firmware_version, firmware_file, firmware_models, ";
		$sql .= "firmware_size, firmware_sha256, firmware_note, firmware_uploaded_by, insert_date ";
		$sql .= "from v_x_provision_overlay_firmware ";
		$sql .= "order by firmware_vendor asc, firmware_family asc, insert_date desc ";
		$releases = [];
		foreach ($this->query($sql) as $row) {
			$releases[$row['firmware_uuid']] = $row;
		}
		return $releases;
	}

	/**
	 * Release row by uuid, or null.
	 */
	public function release(string $firmware_uuid): ?array {
		if (!provision_overlay::valid_uuid($firmware_uuid)) {
			return null;
		}
		$rows = $this->query("select * from v_x_provision_overlay_firmware where firmware_uuid = :uuid ", ['uuid' => $firmware_uuid]);
		return $rows[0] ?? null;
	}

	/**
	 * Adds a release from a file on disk (an upload, or a path given to the CLI).
	 *
	 * @param string $name     original file name: decides the vendor (.rom Yealink, *fw.bin Grandstream)
	 * @param string $version  required for Grandstream (the file name doesn't carry it); checked for Yealink
	 * @param string $models   Grandstream models the file serves (default: from the file name)
	 *
	 * @return array ['error' => language key|null, 'firmware_uuid' => string]
	 */
	public function import(string $path, string $name, string $version, string $models, string $note, string $username): array {
		$result = ['error' => null, 'firmware_uuid' => ''];
		$name = basename(trim($name));
		$version = trim($version);
		if (!is_file($path) || is_link($path) || !is_readable($path) || (int) filesize($path) < 1) {
			$result['error'] = 'error-firmware_file';
			return $result;
		}
		if (preg_match('/\.zip$/i', $name) === 1) {
			$result['error'] = 'error-firmware_zip';
			return $result;
		}

		$rom = self::parse_rom_name($name);
		if ($rom !== null) {
			if ($version !== '' && $version !== $rom['version']) {
				$result['error'] = 'error-firmware_version_mismatch';
				return $result;
			}
			$row = ['firmware_vendor' => 'yealink', 'firmware_family' => $rom['family'], 'firmware_version' => $rom['version'], 'firmware_file' => $rom['version'].'.rom', 'firmware_models' => ''];
		}
		elseif (self::valid_grandstream_file(strtolower($name))) {
			if (!self::valid_version($version)) {
				$result['error'] = 'error-firmware_version';
				return $result;
			}
			$file = strtolower($name);
			$models = self::normalize_models($models !== '' ? $models : substr($file, 0, -6));
			if ($models === '') {
				$result['error'] = 'error-firmware_models';
				return $result;
			}
			$row = ['firmware_vendor' => 'grandstream', 'firmware_family' => $file, 'firmware_version' => $version, 'firmware_file' => $file, 'firmware_models' => $models];
		}
		else {
			$result['error'] = 'error-firmware_name';
			return $result;
		}

		$sha256 = hash_file('sha256', $path);
		if (!is_string($sha256)) {
			$result['error'] = 'error-firmware_file';
			return $result;
		}
		$existing = $this->query("select firmware_uuid from v_x_provision_overlay_firmware where firmware_vendor = :vendor and firmware_family = :family and firmware_version = :version ",
			['vendor' => $row['firmware_vendor'], 'family' => $row['firmware_family'], 'version' => $row['firmware_version']]);
		if (!empty($existing)) {
			$result['error'] = 'error-firmware_exists';
			return $result;
		}

		//file first, then the row: a row never points at a missing file
		$uuid = uuid();
		$row['firmware_uuid'] = $uuid;
		$target = self::release_path($row);
		$dir = dirname((string) $target);
		if ($target === null || !is_dir(self::firmware_root()) || !@mkdir($dir, 0750)) {
			$result['error'] = 'error-firmware_store';
			return $result;
		}
		$temp = $dir.'/.'.bin2hex(random_bytes(6)).'.tmp';
		if (!@copy($path, $temp) || hash_file('sha256', $temp) !== $sha256 || !@chmod($temp, 0640) || !@rename($temp, $target)) {
			@unlink($temp);
			@rmdir($dir);
			$result['error'] = 'error-firmware_store';
			return $result;
		}
		try {
			$sql = "insert into v_x_provision_overlay_firmware ";
			$sql .= "(firmware_uuid, firmware_vendor, firmware_family, firmware_version, firmware_file, firmware_models, firmware_size, firmware_sha256, firmware_note, firmware_uploaded_by, insert_date) ";
			$sql .= "values (:uuid, :vendor, :family, :version, :file, :models, :size, :sha256, :note, :uploaded_by, now()) ";
			$this->query($sql, [
				'uuid' => $uuid,
				'vendor' => $row['firmware_vendor'],
				'family' => $row['firmware_family'],
				'version' => $row['firmware_version'],
				'file' => $row['firmware_file'],
				'models' => $row['firmware_models'],
				'size' => (int) filesize($target),
				'sha256' => $sha256,
				'note' => mb_substr(trim($note), 0, 255),
				'uploaded_by' => $username,
			]);
		}
		catch (Throwable $e) {
			self::remove_tree($dir);
			self::log_error('import', $e->getMessage());
			$result['error'] = 'error-firmware_store';
			return $result;
		}
		self::log('firmware_imported', $row['firmware_vendor'].'/'.$row['firmware_family'], 'version='.$row['firmware_version'].' uuid='.$uuid.' sha256='.$sha256, $username);
		$result['firmware_uuid'] = $uuid;
		return $result;
	}

	/**
	 * Adds a release from an upload of the library page.
	 */
	public function import_upload(array $upload, string $version, string $models, string $note, string $username): array {
		if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
			$code = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
			return ['error' => ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) ? 'error-firmware_too_large' : 'error-firmware_file', 'firmware_uuid' => ''];
		}
		return $this->import((string) $upload['tmp_name'], (string) ($upload['name'] ?? ''), $version, $models, $note, $username);
	}

	/**
	 * Where a release is used: domain defaults, device targets, active rollouts, and any enabled setting that
	 * contains its uuid (copied devices, pasted values).
	 *
	 * @return array ['defaults' => int, 'targets' => int, 'rollouts' => int, 'settings' => int]
	 */
	public function usage(string $firmware_uuid): array {
		$parameters = ['uuid' => $firmware_uuid];
		$like = ['value' => '%'.$firmware_uuid.'%'];
		$count = function (string $sql, array $p) {
			$rows = $this->query($sql, $p);
			return (int) ($rows[0]['total'] ?? 0);
		};
		$settings = $count("select count(*) as total from v_device_settings where device_setting_enabled = true and device_setting_value like :value ", $like)
			+ $count("select count(*) as total from v_device_profile_settings where profile_setting_enabled = true and profile_setting_value like :value ", $like)
			+ $count("select count(*) as total from v_domain_settings where domain_setting_enabled = true and domain_setting_value like :value ", $like)
			+ $count("select count(*) as total from v_default_settings where default_setting_enabled = true and default_setting_value like :value ", $like);
		return [
			'defaults' => $count("select count(*) as total from v_x_provision_overlay_firmware_defaults where firmware_uuid = :uuid ", $parameters),
			'targets' => $count("select count(*) as total from v_x_provision_overlay_firmware_targets where firmware_uuid = :uuid ", $parameters),
			'rollouts' => $count("select count(*) as total from v_x_provision_overlay_firmware_rollouts where firmware_uuid = :uuid and rollout_state = 'active' ", $parameters),
			'settings' => $settings,
		];
	}

	/**
	 * Deletes a release that nothing uses. The file is moved aside and removed by the daily cleanup a day
	 * later, so a phone that is downloading it can finish.
	 *
	 * @return string|null language key of the error
	 */
	public function delete_release(string $firmware_uuid, string $username): ?string {
		$release = $this->release($firmware_uuid);
		if ($release === null) {
			return 'error-firmware_not_found';
		}
		if (array_sum($this->usage($firmware_uuid)) > 0) {
			return 'error-firmware_in_use';
		}
		$dir = self::firmware_root().'/'.$firmware_uuid;
		if (is_dir($dir) && !is_link($dir) && !@rename($dir, self::firmware_root().'/'.self::deleted_prefix.time().'-'.$firmware_uuid)) {
			return 'error-firmware_store';
		}
		$this->query("delete from v_x_provision_overlay_firmware where firmware_uuid = :uuid ", ['uuid' => $firmware_uuid]);
		self::log('firmware_deleted', $release['firmware_vendor'].'/'.$release['firmware_family'], 'version='.$release['firmware_version'].' uuid='.$firmware_uuid, $username);
		return null;
	}

	/**
	 * Recent downloads, newest first.
	 */
	public function downloads(int $limit = 50): array {
		$sql = "select d.insert_date, d.firmware_uuid, d.remote_addr, d.user_agent, d.request_method, f.firmware_vendor, f.firmware_family, f.firmware_version ";
		$sql .= "from v_x_provision_overlay_firmware_downloads as d ";
		$sql .= "left join v_x_provision_overlay_firmware as f on f.firmware_uuid = d.firmware_uuid ";
		$sql .= "order by d.insert_date desc limit ".max(1, min(500, $limit))." ";
		return $this->query($sql);
	}

	/**
	 * Records a download (firmware.php); failures are ignored.
	 */
	public static function log_download($database, string $firmware_uuid, string $domain_uuid, string $remote_addr, string $agent, string $method): void {
		$sql = "insert into v_x_provision_overlay_firmware_downloads ";
		$sql .= "(firmware_download_uuid, firmware_uuid, domain_uuid, remote_addr, user_agent, request_method, insert_date) ";
		$sql .= "values (:uuid, :firmware_uuid, :domain_uuid, :remote_addr, :user_agent, :method, now()) ";
		@$database->execute($sql, [
			'uuid' => uuid(),
			'firmware_uuid' => $firmware_uuid,
			'domain_uuid' => $domain_uuid,
			'remote_addr' => substr($remote_addr, 0, 64),
			'user_agent' => substr($agent, 0, 255),
			'method' => ($method === 'HEAD') ? 'HEAD' : 'GET',
		]);
	}

	//-------------------------------------------------------------------------
	// domain defaults, device targets
	//-------------------------------------------------------------------------

	/**
	 * Domain defaults, by vendor and family.
	 *
	 * @return array "vendor|family" => firmware_uuid, or '' for none
	 */
	public function defaults(string $domain_uuid): array {
		$sql = "select firmware_vendor, firmware_family, firmware_uuid from v_x_provision_overlay_firmware_defaults where domain_uuid = :domain_uuid ";
		$defaults = [];
		foreach ($this->query($sql, ['domain_uuid' => $domain_uuid]) as $row) {
			$defaults[$row['firmware_vendor'].'|'.$row['firmware_family']] = (string) ($row['firmware_uuid'] ?? '');
		}
		return $defaults;
	}

	/**
	 * Device targets of a domain.
	 *
	 * @return array device_uuid => row
	 */
	public function targets(string $domain_uuid): array {
		$sql = "select * from v_x_provision_overlay_firmware_targets where domain_uuid = :domain_uuid ";
		$targets = [];
		foreach ($this->query($sql, ['domain_uuid' => $domain_uuid]) as $row) {
			$targets[$row['device_uuid']] = $row;
		}
		return $targets;
	}

	/**
	 * A device target as the firmware page shows and posts it: follow (no target), none, a release uuid, or
	 * <release uuid>|rollout for a target of an active rollout.
	 */
	public static function target_value(?array $target): string {
		if ($target === null) {
			return 'follow';
		}
		return (empty($target['firmware_uuid']) ? 'none' : (string) $target['firmware_uuid']).(($target['target_source'] === 'rollout') ? '|rollout' : '');
	}

	/**
	 * Active rollout of a family in a domain, or null.
	 */
	public function active_rollout(string $domain_uuid, string $vendor, string $family): ?array {
		$sql = "select * from v_x_provision_overlay_firmware_rollouts ";
		$sql .= "where domain_uuid = :domain_uuid and firmware_vendor = :vendor and firmware_family = :family and rollout_state = 'active' ";
		$rows = $this->query($sql, ['domain_uuid' => $domain_uuid, 'vendor' => $vendor, 'family' => $family]);
		return $rows[0] ?? null;
	}

	/**
	 * Sets a domain default: a release uuid, 'none', or '' (unset: upstream behavior). Refused while a rollout
	 * of the family is active.
	 *
	 * @return string|null language key of the error
	 */
	public function set_default(string $domain_uuid, string $vendor, string $family, string $value, string $username, bool $from_rollout = false): ?string {
		if (!provision_overlay::valid_uuid($domain_uuid) || !in_array($vendor, ['yealink', 'grandstream'], true) || $family === '') {
			return 'error-firmware_invalid';
		}
		if (!$from_rollout && $this->active_rollout($domain_uuid, $vendor, $family) !== null) {
			return 'error-firmware_rollout_active';
		}
		$firmware_uuid = null;
		if ($value !== '' && $value !== 'none') {
			$release = $this->release($value);
			if ($release === null || $release['firmware_vendor'] !== $vendor || $release['firmware_family'] !== $family) {
				return 'error-firmware_invalid';
			}
			$firmware_uuid = $value;
		}
		$this->query("delete from v_x_provision_overlay_firmware_defaults where domain_uuid = :domain_uuid and firmware_vendor = :vendor and firmware_family = :family ",
			['domain_uuid' => $domain_uuid, 'vendor' => $vendor, 'family' => $family]);
		if ($value !== '') {
			$sql = "insert into v_x_provision_overlay_firmware_defaults (firmware_default_uuid, domain_uuid, firmware_vendor, firmware_family, firmware_uuid, insert_date) ";
			$sql .= "values (:uuid, :domain_uuid, :vendor, :family, :firmware_uuid, now()) ";
			$this->query($sql, ['uuid' => uuid(), 'domain_uuid' => $domain_uuid, 'vendor' => $vendor, 'family' => $family, 'firmware_uuid' => $firmware_uuid]);
		}
		self::log('firmware_default', $domain_uuid, $vendor.'/'.$family.'='.($value === '' ? 'unset' : $value), $username);
		return null;
	}

	/**
	 * Sets a manual device target: a release uuid, 'none', or 'follow' (remove the target). Replaces a rollout
	 * target of the device.
	 *
	 * @return string|null language key of the error
	 */
	public function set_target(string $domain_uuid, string $device_uuid, string $value, string $username): ?string {
		$devices = $this->query("select device_uuid, device_template from v_devices where domain_uuid = :domain_uuid and device_uuid = :device_uuid ",
			['domain_uuid' => $domain_uuid, 'device_uuid' => $device_uuid]);
		if (empty($devices) || !isset(self::catalog[(string) $devices[0]['device_template']])) {
			return 'error-firmware_invalid';
		}
		$vendor = self::vendor_of((string) $devices[0]['device_template']);
		$firmware_uuid = null;
		$family = '';
		if ($value !== 'follow' && $value !== 'none') {
			$release = $this->release($value);
			if ($release === null || $release['firmware_vendor'] !== $vendor) {
				return 'error-firmware_invalid';
			}
			$firmware_uuid = $value;
			$family = (string) $release['firmware_family'];
		}
		if (!$this->rollout_lock()) {
			return 'error-firmware_busy';
		}
		try {
			//a rollout target replaced: its queued reboot goes too
			$old = $this->query("select target_source, reboot_log_uuid from v_x_provision_overlay_firmware_targets where device_uuid = :device_uuid ", ['device_uuid' => $device_uuid]);
			foreach ($old as $row) {
				$endpoint_manager = $this->endpoint_manager();
				if ($row['target_source'] === 'rollout' && provision_overlay::valid_uuid((string) $row['reboot_log_uuid']) && $endpoint_manager !== null) {
					$endpoint_manager->cancel_log($domain_uuid, (string) $row['reboot_log_uuid'], 'Firmware target changed by '.$username.'.');
				}
			}
			$this->query("delete from v_x_provision_overlay_firmware_targets where device_uuid = :device_uuid ", ['device_uuid' => $device_uuid]);
			if ($value !== 'follow') {
				$sql = "insert into v_x_provision_overlay_firmware_targets ";
				$sql .= "(firmware_target_uuid, domain_uuid, device_uuid, firmware_vendor, firmware_family, firmware_uuid, target_source, insert_date) ";
				$sql .= "values (:uuid, :domain_uuid, :device_uuid, :vendor, :family, :firmware_uuid, 'manual', now()) ";
				$this->query($sql, ['uuid' => uuid(), 'domain_uuid' => $domain_uuid, 'device_uuid' => $device_uuid, 'vendor' => $vendor, 'family' => $family, 'firmware_uuid' => $firmware_uuid]);
			}
		}
		finally {
			$this->rollout_unlock();
		}
		self::log('firmware_target', $domain_uuid, 'device='.$device_uuid.' target='.$value, $username);
		return null;
	}

	//-------------------------------------------------------------------------
	// analysis and sync
	//-------------------------------------------------------------------------

	/**
	 * Family of a device: Yealink from the version it reports; Grandstream from its model (reported, else the
	 * device's model field), through the releases that list it, else <model>fw.bin. Null when unknown.
	 */
	public static function device_family(string $vendor, array $agent, string $device_model, array $releases): ?string {
		if ($vendor === 'yealink') {
			return ($agent['version'] !== '') ? explode('.', $agent['version'])[0] : null;
		}
		$model = ($agent['model'] !== '') ? $agent['model'] : strtoupper(trim($device_model));
		if (preg_match('/^[A-Z0-9][A-Z0-9-]{1,30}$/', $model) !== 1) {
			return null;
		}
		foreach ($releases as $release) {
			if ($release['firmware_vendor'] === 'grandstream' && in_array($model, explode(',', (string) $release['firmware_models']), true)) {
				return (string) $release['firmware_family'];
			}
		}
		return strtolower($model).'fw.bin';
	}

	/**
	 * Works out, for one domain, what each device on a catalog template gets and the changes needed. Read only.
	 *
	 * Device states: managed (gets a release), none (gets the none address), unset (no target: upstream behavior,
	 * no rows), own (a value is set elsewhere: sources), optout (a managed row was disabled), alternate, unsupported
	 * (template check failed), unknown_model (family not known yet: Yealink before its first fetch), invalid_target
	 * (the target's release isn't of the device's family), disabled.
	 *
	 * @return array ['devices' => [uuid => row], 'families' => ["vendor|family" => counts], 'unsupported' => [template], 'plan' => [...]]
	 */
	public function analyze(string $domain_uuid, string $domain_name): array {
		if (!is_object($this->database) || !provision_overlay::valid_uuid($domain_uuid)) {
			throw new RuntimeException('a database connection and a domain are required');
		}
		$templates = array_keys(self::catalog);
		$variables = [];
		foreach (self::catalog as $set) {
			foreach ($set as $variable) {
				$variables[$variable] = true;
			}
		}
		$variables = array_keys($variables);
		sort($variables, SORT_STRING);
		$plan = ['inserts' => [], 'updates' => [], 'deletes' => [], 'adopts' => [], 'drop_links' => [], 'move_links' => []];

		$releases = $this->releases();
		$defaults = $this->defaults($domain_uuid);
		$targets = $this->targets($domain_uuid);
		$support = self::template_support($domain_name);

		//devices on catalog templates
		[$in_templates, $parameters] = self::in_list('t', $templates);
		$sql = "select device_uuid, device_address, device_label, device_template, device_profile_uuid, device_uuid_alternate, device_enabled, ";
		$sql .= "device_model, device_provisioned_agent, device_provisioned_date ";
		$sql .= "from v_devices ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and device_template in (".$in_templates.") ";
		$sql .= "order by device_address asc ";
		$parameters['domain_uuid'] = $domain_uuid;
		$devices = [];
		foreach ($this->query($sql, $parameters) as $row) {
			$devices[$row['device_uuid']] = $row;
		}
		unset($sql, $parameters);

		//device settings of the domain's devices for any catalog variable
		[$in_variables, $parameters] = self::in_list('v', $variables);
		$sql = "select s.device_setting_uuid, s.device_uuid, s.device_setting_subcategory, s.device_setting_value, s.device_setting_enabled ";
		$sql .= "from v_device_settings as s ";
		$sql .= "inner join v_devices as d on d.device_uuid = s.device_uuid ";
		$sql .= "where d.domain_uuid = :domain_uuid ";
		$sql .= "and s.device_setting_subcategory in (".$in_variables.") ";
		$sql .= "order by s.device_setting_uuid asc ";
		$parameters['domain_uuid'] = $domain_uuid;
		$settings = [];
		foreach ($this->query($sql, $parameters) as $row) {
			$row['enabled'] = self::bool_value($row['device_setting_enabled']);
			$row['managed'] = false;
			$settings[$row['device_setting_uuid']] = $row;
		}
		unset($sql, $parameters);

		//links of earlier syncs for this domain, or for a device that is in this domain now
		$sql = "select firmware_link_uuid, domain_uuid, device_uuid, device_setting_uuid, firmware_subcategory, firmware_value, firmware_uuid ";
		$sql .= "from v_x_provision_overlay_firmware_links ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "or device_uuid in (select device_uuid from v_devices where domain_uuid = :domain_uuid) ";
		$sql .= "order by insert_date asc, firmware_link_uuid asc ";
		$links = $this->query($sql, ['domain_uuid' => $domain_uuid]);

		//rows of links the query above does not cover (device moved, variable left the catalog)
		$orphans = [];
		$missing = [];
		foreach ($links as $link) {
			if (!isset($settings[$link['device_setting_uuid']])) {
				$missing[$link['device_setting_uuid']] = true;
			}
		}
		if (!empty($missing)) {
			[$in_settings, $parameters] = self::in_list('s', array_keys($missing));
			$sql = "select device_setting_uuid, device_uuid, device_setting_subcategory, device_setting_value, device_setting_enabled ";
			$sql .= "from v_device_settings ";
			$sql .= "where device_setting_uuid in (".$in_settings.") ";
			foreach ($this->query($sql, $parameters) as $row) {
				$orphans[$row['device_setting_uuid']] = $row;
			}
			unset($sql, $parameters, $in_settings);
		}

		//enabled, non-empty domain settings
		[$in_variables, $parameters] = self::in_list('v', $variables);
		$sql = "select distinct domain_setting_subcategory ";
		$sql .= "from v_domain_settings ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and domain_setting_category = 'provision' ";
		$sql .= "and domain_setting_enabled = true ";
		$sql .= "and domain_setting_value is not null and domain_setting_value <> '' ";
		$sql .= "and domain_setting_subcategory in (".$in_variables.") ";
		$parameters['domain_uuid'] = $domain_uuid;
		$domain_settings = [];
		foreach ($this->query($sql, $parameters) as $row) {
			$domain_settings[$row['domain_setting_subcategory']] = true;
		}
		unset($sql, $parameters);

		//enabled device profile settings of the profiles the domain's devices use
		[$in_variables, $parameters] = self::in_list('v', $variables);
		$sql = "select ps.device_profile_uuid, ps.profile_setting_name, p.device_profile_name ";
		$sql .= "from v_device_profile_settings as ps ";
		$sql .= "left join v_device_profiles as p on p.device_profile_uuid = ps.device_profile_uuid ";
		$sql .= "where ps.profile_setting_enabled = true ";
		$sql .= "and ps.profile_setting_name in (".$in_variables.") ";
		$sql .= "and ps.device_profile_uuid in (select device_profile_uuid from v_devices where domain_uuid = :domain_uuid and device_profile_uuid is not null) ";
		$parameters['domain_uuid'] = $domain_uuid;
		$profile_settings = [];
		foreach ($this->query($sql, $parameters) as $row) {
			$profile_settings[$row['device_profile_uuid']]['name'] = (string) ($row['device_profile_name'] ?? '');
			$profile_settings[$row['device_profile_uuid']]['variables'][$row['profile_setting_name']] = true;
		}
		unset($sql, $parameters);

		//a link holds while its row exists with the value written
		$managed = [];
		foreach ($links as $link) {
			$orphan = $orphans[$link['device_setting_uuid']] ?? null;
			if ($orphan !== null) {
				unset($orphans[$link['device_setting_uuid']]);
				if ($orphan['device_uuid'] === $link['device_uuid']
					&& $orphan['device_setting_subcategory'] === $link['firmware_subcategory']
					&& (string) $orphan['device_setting_value'] === (string) $link['firmware_value']
					&& self::bool_value($orphan['device_setting_enabled'])) {
					$plan['deletes'][] = ['link_uuid' => $link['firmware_link_uuid'], 'setting_uuid' => $link['device_setting_uuid'], 'value' => (string) $link['firmware_value'], 'enabled' => true];
				}
				else {
					$plan['drop_links'][] = $link['firmware_link_uuid'];
				}
				continue;
			}
			$row = $settings[$link['device_setting_uuid']] ?? null;
			if ($row === null
				|| $row['device_uuid'] !== $link['device_uuid']
				|| $row['device_setting_subcategory'] !== $link['firmware_subcategory']
				|| (string) $row['device_setting_value'] !== (string) $link['firmware_value']
				|| $row['managed']) {
				$plan['drop_links'][] = $link['firmware_link_uuid'];
				continue;
			}
			$settings[$link['device_setting_uuid']]['managed'] = true;
			if ($link['domain_uuid'] !== $domain_uuid) {
				$plan['move_links'][] = $link['firmware_link_uuid'];
			}
			$managed[$link['device_uuid']][$link['firmware_subcategory']][] = [
				'link_uuid' => $link['firmware_link_uuid'],
				'setting_uuid' => $link['device_setting_uuid'],
				'value' => (string) $link['firmware_value'],
				'enabled' => $row['enabled'],
				'firmware_uuid' => (string) ($link['firmware_uuid'] ?? ''),
			];
		}

		//unmanaged enabled device rows, by device and variable
		$device_rows = [];
		foreach ($settings as $row) {
			if (!$row['managed'] && $row['enabled']) {
				$device_rows[$row['device_uuid']][$row['device_setting_subcategory']][] = $row;
			}
		}

		$unsupported = [];
		foreach ($support as $template => $supported) {
			if (!$supported) {
				$unsupported[] = $template;
			}
		}
		$result_devices = [];
		$families = [];
		$desired = [];
		foreach ($devices as $device_uuid => $device) {
			$template = $device['device_template'];
			$vendor = self::vendor_of($template);
			$agent = self::parse_agent($vendor, (string) ($device['device_provisioned_agent'] ?? ''));
			$family = self::device_family($vendor, $agent, (string) ($device['device_model'] ?? ''), $releases);
			$set = self::catalog[$template];
			$target = $targets[$device_uuid] ?? null;

			//no family from the user agent (never fetched, or a browser or curl fetch overwrote it): the family of
			//the device's target, else of the release its managed rows point at, so its rows aren't dropped
			if ($family === null) {
				if ($target !== null && $target['firmware_vendor'] === $vendor && (string) $target['firmware_family'] !== '') {
					$family = (string) $target['firmware_family'];
				}
				else {
					foreach ($managed[$device_uuid] ?? [] as $entries) {
						foreach ($entries as $m) {
							if (isset($releases[$m['firmware_uuid']]) && $releases[$m['firmware_uuid']]['firmware_vendor'] === $vendor) {
								$family = (string) $releases[$m['firmware_uuid']]['firmware_family'];
								break 2;
							}
						}
					}
				}
			}

			//target: device target, else domain default, else unset
			$source = '';
			$target_release = null;
			$target_kind = 'unset';
			if ($target !== null) {
				$source = ['rollout' => 'rollout', 'rollout_kept' => 'kept'][$target['target_source']] ?? 'device';
				if (empty($target['firmware_uuid'])) {
					$target_kind = 'none';
				}
				elseif (isset($releases[$target['firmware_uuid']])) {
					$target_kind = 'release';
					$target_release = $releases[$target['firmware_uuid']];
				}
				else {
					$target_kind = 'invalid';
				}
			}
			elseif ($family !== null && array_key_exists($vendor.'|'.$family, $defaults)) {
				$source = 'domain';
				$default = $defaults[$vendor.'|'.$family];
				if ($default === '') {
					$target_kind = 'none';
				}
				elseif (isset($releases[$default])) {
					$target_kind = 'release';
					$target_release = $releases[$default];
				}
			}
			if ($target_release !== null && ($target_release['firmware_vendor'] !== $vendor || $target_release['firmware_family'] !== $family)) {
				$target_kind = 'invalid';
			}
			$want = ($target_kind === 'release' || $target_kind === 'none') ? self::values($template, $target_release) : [];

			//values set elsewhere; an unmanaged device row that already holds what we would write is adopted
			$sources = [];
			$optout = false;
			$adopt = [];
			foreach ($managed[$device_uuid] ?? [] as $entries) {
				foreach ($entries as $m) {
					if (!$m['enabled']) {
						$optout = true;
					}
				}
			}
			$profile_uuid = (string) ($device['device_profile_uuid'] ?? '');
			foreach ($set as $variable) {
				if (isset($domain_settings[$variable])) {
					$sources['domain'] = 'domain';
				}
				if ($profile_uuid !== '' && isset($profile_settings[$profile_uuid]['variables'][$variable])) {
					$sources['profile'] = 'profile:'.$profile_settings[$profile_uuid]['name'];
				}
				foreach ($device_rows[$device_uuid][$variable] ?? [] as $row) {
					if (isset($want[$variable]) && (string) $row['device_setting_value'] === $want[$variable] && !isset($adopt[$variable]) && !isset($managed[$device_uuid][$variable])) {
						$adopt[$variable] = $row;
					}
					else {
						$sources['device'] = 'device';
					}
				}
			}

			if (!self::bool_value($device['device_enabled'])) {
				$state = 'disabled';
			}
			elseif (provision_overlay::valid_uuid((string) ($device['device_uuid_alternate'] ?? ''))) {
				$state = 'alternate';
			}
			elseif (empty($support[$template])) {
				$state = 'unsupported';
			}
			elseif ($family === null) {
				$state = 'unknown_model';
			}
			elseif ($optout) {
				$state = 'optout';
			}
			elseif (!empty($sources)) {
				$state = 'own';
			}
			elseif ($target_kind === 'invalid') {
				$state = 'invalid_target';
			}
			elseif ($target_kind === 'unset') {
				$state = 'unset';
			}
			else {
				$state = ($target_kind === 'release') ? 'managed' : 'none';
				foreach ($want as $variable => $value) {
					if (isset($adopt[$variable])) {
						$plan['adopts'][] = ['device_uuid' => $device_uuid, 'setting_uuid' => $adopt[$variable]['device_setting_uuid'], 'subcategory' => $variable, 'value' => $value, 'firmware_uuid' => $target_release['firmware_uuid'] ?? null];
						continue;
					}
					$desired[$device_uuid][$variable] = ['value' => $value, 'firmware_uuid' => $target_release['firmware_uuid'] ?? null];
				}
			}

			if ($family !== null) {
				$key = $vendor.'|'.$family;
				$families[$key] = $families[$key] ?? ['vendor' => $vendor, 'family' => $family, 'devices' => 0, 'managed' => 0, 'current' => 0];
				$families[$key]['devices']++;
				if ($state === 'managed') {
					$families[$key]['managed']++;
					if ($agent['version'] !== '' && $agent['version'] === (string) $target_release['firmware_version']) {
						$families[$key]['current']++;
					}
				}
			}
			$result_devices[$device_uuid] = [
				'device_uuid' => $device_uuid,
				'address' => (string) $device['device_address'],
				'label' => (string) ($device['device_label'] ?? ''),
				'template' => $template,
				'vendor' => $vendor,
				'model' => ($agent['model'] !== '') ? $agent['model'] : strtoupper((string) ($device['device_model'] ?? '')),
				'version' => $agent['version'],
				'family' => (string) $family,
				'provisioned_date' => (string) ($device['device_provisioned_date'] ?? ''),
				'state' => $state,
				'sources' => array_values($sources),
				'target_source' => $source,
				'target_uuid' => $target_release['firmware_uuid'] ?? '',
				'target_version' => (string) ($target_release['firmware_version'] ?? ''),
				'target_kind' => $target_kind,
				'rollout_uuid' => (string) ($target['firmware_rollout_uuid'] ?? ''),
				'manual' => ($target !== null && $target['target_source'] === 'manual'),
			];
		}

		//diff the managed rows against the desired ones
		foreach ($managed as $device_uuid => $by_variable) {
			foreach ($by_variable as $variable => $entries) {
				usort($entries, function ($a, $b) { return (int) $a['enabled'] <=> (int) $b['enabled']; });
				$keep = array_shift($entries);
				foreach ($entries as $extra) {
					if ($extra['enabled']) {
						$plan['deletes'][] = $extra;
					}
				}
				if (!$keep['enabled']) {
					unset($desired[$device_uuid][$variable]);
					continue;
				}
				if (isset($desired[$device_uuid][$variable])) {
					$want = $desired[$device_uuid][$variable];
					if ($want['value'] !== $keep['value']) {
						$plan['updates'][] = $keep + ['new_value' => $want['value'], 'firmware_uuid' => $want['firmware_uuid']];
					}
					unset($desired[$device_uuid][$variable]);
				}
				else {
					$plan['deletes'][] = $keep;
				}
			}
		}
		foreach ($desired as $device_uuid => $by_variable) {
			foreach ($by_variable as $variable => $want) {
				$plan['inserts'][] = ['device_uuid' => $device_uuid, 'subcategory' => $variable] + $want;
			}
		}

		ksort($families, SORT_STRING);
		return [
			'devices' => $result_devices,
			'families' => $families,
			'unsupported' => $unsupported,
			'plan' => $plan,
		];
	}

	/**
	 * Syncs the managed device settings of one domain, in one transaction, under the overlay lock.
	 *
	 * @return array ['success' => bool, 'inserted' => int, 'updated' => int, 'deleted' => int, 'adopted' => int, 'dropped' => int, 'error' => string]
	 */
	public function sync_domain(string $domain_uuid): array {
		$result = ['success' => false, 'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'adopted' => 0, 'dropped' => 0, 'error' => ''];
		if (!is_object($this->database) || !provision_overlay::valid_uuid($domain_uuid)) {
			$result['error'] = 'a database connection and a domain are required';
			return $result;
		}
		$domains = $this->overlay->get_domains();
		if (!isset($domains[$domain_uuid])) {
			$result['error'] = 'domain '.$domain_uuid.' not found';
			return $result;
		}
		if (!$this->overlay->lock()) {
			$result['error'] = 'cannot acquire the overlay lock';
			return $result;
		}
		try {
			$analysis = $this->analyze($domain_uuid, (string) $domains[$domain_uuid]['domain_name']);
			$plan = $analysis['plan'];
			if (empty($plan['inserts']) && empty($plan['updates']) && empty($plan['deletes']) && empty($plan['adopts']) && empty($plan['drop_links']) && empty($plan['move_links'])) {
				$result['success'] = true;
				return $result;
			}
			$this->query("select 1");
			$pdo = $this->database->db;
			if (!($pdo instanceof PDO)) {
				$result['error'] = 'database not connected';
				return $result;
			}
			$pdo->beginTransaction();
			try {
				foreach ($plan['drop_links'] as $link_uuid) {
					$this->query("delete from v_x_provision_overlay_firmware_links where firmware_link_uuid = :uuid ", ['uuid' => $link_uuid]);
					$result['dropped']++;
				}
				foreach ($plan['move_links'] as $link_uuid) {
					$this->query("update v_x_provision_overlay_firmware_links set domain_uuid = :domain_uuid, update_date = now() where firmware_link_uuid = :uuid ", ['domain_uuid' => $domain_uuid, 'uuid' => $link_uuid]);
				}

				//guarded as wallpapers: only rows that still hold the value written and are enabled
				foreach ($plan['deletes'] as $row) {
					$sql = "delete from v_device_settings ";
					$sql .= "where device_setting_uuid = :uuid and device_setting_value = :value and device_setting_enabled = true ";
					$sql .= "returning device_setting_uuid ";
					if (empty($this->query($sql, ['uuid' => $row['setting_uuid'], 'value' => $row['value']]))) {
						continue;
					}
					$this->query("delete from v_x_provision_overlay_firmware_links where firmware_link_uuid = :uuid ", ['uuid' => $row['link_uuid']]);
					$result['deleted']++;
				}
				foreach ($plan['updates'] as $row) {
					$sql = "update v_device_settings set device_setting_value = :new_value, update_date = now() ";
					$sql .= "where device_setting_uuid = :uuid and device_setting_value = :value and device_setting_enabled = true ";
					$sql .= "returning device_setting_uuid ";
					if (empty($this->query($sql, ['new_value' => $row['new_value'], 'uuid' => $row['setting_uuid'], 'value' => $row['value']]))) {
						continue;
					}
					$sql = "update v_x_provision_overlay_firmware_links set firmware_value = :value, firmware_uuid = :firmware_uuid, update_date = now() ";
					$sql .= "where firmware_link_uuid = :uuid ";
					$this->query($sql, ['value' => $row['new_value'], 'firmware_uuid' => $row['firmware_uuid'], 'uuid' => $row['link_uuid']]);
					$result['updated']++;
				}

				//rows that already hold the value (copied devices, pasted values): link them, unchanged
				foreach ($plan['adopts'] as $row) {
					$sql = "select device_setting_uuid from v_device_settings ";
					$sql .= "where device_setting_uuid = :uuid and device_setting_value = :value and device_setting_enabled = true ";
					if (empty($this->query($sql, ['uuid' => $row['setting_uuid'], 'value' => $row['value']]))) {
						continue;
					}
					$this->insert_link($domain_uuid, $row['device_uuid'], $row['setting_uuid'], $row['subcategory'], $row['value'], $row['firmware_uuid']);
					$result['adopted']++;
				}

				foreach ($plan['inserts'] as $row) {
					$setting_uuid = uuid();
					$sql = "insert into v_device_settings ";
					$sql .= "(device_setting_uuid, device_uuid, domain_uuid, device_setting_category, device_setting_subcategory, device_setting_name, device_setting_value, device_setting_enabled, device_setting_description, insert_date) ";
					$sql .= "values (:uuid, :device_uuid, :domain_uuid, :category, :subcategory, 'text', :value, true, :description, now()) ";
					$this->query($sql, [
						'uuid' => $setting_uuid,
						'device_uuid' => $row['device_uuid'],
						'domain_uuid' => $domain_uuid,
						'category' => self::setting_category,
						'subcategory' => $row['subcategory'],
						'value' => $row['value'],
						'description' => self::setting_description,
					]);
					$this->insert_link($domain_uuid, $row['device_uuid'], $setting_uuid, $row['subcategory'], $row['value'], $row['firmware_uuid']);
					$result['inserted']++;
				}
				$pdo->commit();
			}
			catch (Throwable $e) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}
				$result['inserted'] = $result['updated'] = $result['deleted'] = $result['adopted'] = $result['dropped'] = 0;
				$result['error'] = $e->getMessage();
				return $result;
			}
			$result['success'] = true;
			return $result;
		}
		catch (Throwable $e) {
			$result['error'] = $e->getMessage();
			return $result;
		}
		finally {
			$this->overlay->unlock();
		}
	}

	private function insert_link(string $domain_uuid, string $device_uuid, string $setting_uuid, string $subcategory, string $value, ?string $firmware_uuid): void {
		$sql = "insert into v_x_provision_overlay_firmware_links ";
		$sql .= "(firmware_link_uuid, domain_uuid, device_uuid, device_setting_uuid, firmware_subcategory, firmware_value, firmware_uuid, insert_date) ";
		$sql .= "values (:uuid, :domain_uuid, :device_uuid, :setting_uuid, :subcategory, :value, :firmware_uuid, now()) ";
		$this->query($sql, [
			'uuid' => uuid(),
			'domain_uuid' => $domain_uuid,
			'device_uuid' => $device_uuid,
			'setting_uuid' => $setting_uuid,
			'subcategory' => $subcategory,
			'value' => $value,
			'firmware_uuid' => $firmware_uuid,
		]);
	}

	/**
	 * Syncs every domain; gives up at once when another change holds the lock (the next timer run retries).
	 *
	 * @return array ['success' => bool, 'error' => string, 'results' => [domain_uuid => sync result]]
	 */
	public function sync_all(): array {
		$run = ['success' => false, 'error' => '', 'results' => []];
		if (!is_object($this->database)) {
			$run['error'] = 'a database connection is required';
			return $run;
		}
		$domains = $this->overlay->get_domains();
		if (!is_array($domains)) {
			$run['error'] = 'the domains could not be read';
			return $run;
		}
		if (!$this->overlay->lock(self::sync_all_lock_timeout)) {
			$run['error'] = 'the overlay lock is held by another change; the next run retries';
			return $run;
		}
		try {
			foreach (array_keys($domains) as $domain_uuid) {
				$run['results'][$domain_uuid] = $this->sync_domain((string) $domain_uuid);
			}
			$run['success'] = true;
			return $run;
		}
		finally {
			$this->overlay->unlock();
		}
	}

	//-------------------------------------------------------------------------
	// rollouts (docs/design/provision_firmware.md 3.4)
	//-------------------------------------------------------------------------

	/**
	 * Rollouts of a domain, active first, then newest.
	 */
	public function rollouts(string $domain_uuid): array {
		$sql = "select r.*, f.firmware_version, f.firmware_file from v_x_provision_overlay_firmware_rollouts as r ";
		$sql .= "left join v_x_provision_overlay_firmware as f on f.firmware_uuid = r.firmware_uuid ";
		$sql .= "where r.domain_uuid = :domain_uuid ";
		$sql .= "order by case when r.rollout_state = 'active' then 0 else 1 end, r.insert_date desc ";
		return $this->query($sql, ['domain_uuid' => $domain_uuid]);
	}

	public function rollout(string $domain_uuid, string $rollout_uuid): ?array {
		if (!provision_overlay::valid_uuid($rollout_uuid)) {
			return null;
		}
		$sql = "select r.*, f.firmware_version, f.firmware_file from v_x_provision_overlay_firmware_rollouts as r ";
		$sql .= "left join v_x_provision_overlay_firmware as f on f.firmware_uuid = r.firmware_uuid ";
		$sql .= "where r.domain_uuid = :domain_uuid and r.firmware_rollout_uuid = :uuid ";
		$rows = $this->query($sql, ['domain_uuid' => $domain_uuid, 'uuid' => $rollout_uuid]);
		return $rows[0] ?? null;
	}

	/**
	 * Targets of a rollout, by device.
	 */
	public function rollout_targets(string $rollout_uuid): array {
		$targets = [];
		foreach ($this->query("select * from v_x_provision_overlay_firmware_targets where firmware_rollout_uuid = :uuid ", ['uuid' => $rollout_uuid]) as $row) {
			$targets[$row['device_uuid']] = $row;
		}
		return $targets;
	}

	/**
	 * Starts a rollout of a release in a domain. One active rollout per family (checked under the rollout lock).
	 *
	 * @return array ['error' => language key|null, 'rollout_uuid' => string]
	 */
	public function start_rollout(string $domain_uuid, string $firmware_uuid, string $username): array {
		$release = $this->release($firmware_uuid);
		if ($release === null || !provision_overlay::valid_uuid($domain_uuid)) {
			return ['error' => 'error-firmware_invalid', 'rollout_uuid' => ''];
		}
		if (!$this->rollout_lock()) {
			return ['error' => 'error-firmware_busy', 'rollout_uuid' => ''];
		}
		try {
			if ($this->active_rollout($domain_uuid, $release['firmware_vendor'], $release['firmware_family']) !== null) {
				return ['error' => 'error-firmware_rollout_active', 'rollout_uuid' => ''];
			}
			$defaults = $this->defaults($domain_uuid);
			$from = $defaults[$release['firmware_vendor'].'|'.$release['firmware_family']] ?? null;
			$uuid = uuid();
			$sql = "insert into v_x_provision_overlay_firmware_rollouts ";
			$sql .= "(firmware_rollout_uuid, domain_uuid, firmware_vendor, firmware_family, firmware_uuid, rollout_from, rollout_state, rollout_created_by, insert_date) ";
			$sql .= "values (:uuid, :domain_uuid, :vendor, :family, :firmware_uuid, :from, 'active', :username, now()) ";
			$this->query($sql, [
				'uuid' => $uuid,
				'domain_uuid' => $domain_uuid,
				'vendor' => $release['firmware_vendor'],
				'family' => $release['firmware_family'],
				'firmware_uuid' => $firmware_uuid,
				'from' => ($from === null) ? 'unset' : (($from === '') ? 'none' : $from),
				'username' => $username,
			]);
		}
		finally {
			$this->rollout_unlock();
		}
		self::log('firmware_rollout_started', $domain_uuid, 'rollout='.$uuid.' release='.$firmware_uuid, $username);
		return ['error' => null, 'rollout_uuid' => $uuid];
	}

	/**
	 * Devices a batch of a rollout may take: of the rollout's family, linkable (not own, opted out, alternate,
	 * unsupported, unknown model or disabled), without a manual target or one from this rollout, and not
	 * reporting the release already. Targets kept by an aborted rollout (rollout_kept) are eligible.
	 *
	 * @return array device_uuid => analysis row
	 */
	public static function eligible(array $analysis, array $rollout): array {
		$eligible = [];
		foreach ($analysis['devices'] as $device_uuid => $device) {
			if ($device['vendor'] !== $rollout['firmware_vendor'] || $device['family'] !== $rollout['firmware_family']) {
				continue;
			}
			if (!in_array($device['state'], ['managed', 'none', 'unset'], true) || $device['manual'] || $device['rollout_uuid'] === $rollout['firmware_rollout_uuid']) {
				continue;
			}
			if ($device['version'] !== '' && $device['version'] === (string) $rollout['firmware_version']) {
				continue;
			}
			$eligible[$device_uuid] = $device;
		}
		return $eligible;
	}

	/**
	 * Starts a batch: the devices (picked, else the first $count eligible ones) get the release as their
	 * target, and the domain is synced so their rows are written before the runner notifies them.
	 *
	 * @return array ['error' => language key|null, 'devices' => int]
	 */
	public function start_batch(string $domain_uuid, string $domain_name, string $rollout_uuid, array $device_uuids, int $count, string $username): array {
		if (!$this->rollout_lock()) {
			return ['error' => 'error-firmware_busy', 'devices' => 0];
		}
		try {
			$rollout = $this->rollout($domain_uuid, $rollout_uuid);
			if ($rollout === null || $rollout['rollout_state'] !== 'active') {
				return ['error' => 'error-firmware_invalid', 'devices' => 0];
			}
			$eligible = self::eligible($this->analyze($domain_uuid, $domain_name), $rollout);
			$picked = [];
			if (!empty($device_uuids)) {
				foreach ($device_uuids as $device_uuid) {
					if (isset($eligible[$device_uuid])) {
						$picked[] = $device_uuid;
					}
				}
			}
			else {
				$picked = array_slice(array_keys($eligible), 0, max(0, $count));
			}
			if (empty($picked)) {
				return ['error' => 'error-firmware_batch_empty', 'devices' => 0];
			}
			$this->query("select 1");
			$pdo = $this->database->db;
			$pdo->beginTransaction();
			try {
				foreach ($picked as $device_uuid) {
					$this->query("delete from v_x_provision_overlay_firmware_targets where device_uuid = :device_uuid ", ['device_uuid' => $device_uuid]);
					$sql = "insert into v_x_provision_overlay_firmware_targets ";
					$sql .= "(firmware_target_uuid, domain_uuid, device_uuid, firmware_vendor, firmware_family, firmware_uuid, target_source, firmware_rollout_uuid, target_state, target_attempts, activate_date, state_date, insert_date) ";
					$sql .= "values (:uuid, :domain_uuid, :device_uuid, :vendor, :family, :firmware_uuid, 'rollout', :rollout_uuid, 'written', 0, now(), now(), now()) ";
					$this->query($sql, [
						'uuid' => uuid(),
						'domain_uuid' => $domain_uuid,
						'device_uuid' => $device_uuid,
						'vendor' => $rollout['firmware_vendor'],
						'family' => $rollout['firmware_family'],
						'firmware_uuid' => $rollout['firmware_uuid'],
						'rollout_uuid' => $rollout_uuid,
					]);
				}
				$pdo->commit();
			}
			catch (Throwable $e) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}
				self::log_error('batch '.$rollout_uuid, $e->getMessage());
				return ['error' => 'error-firmware_store', 'devices' => 0];
			}
			$sync = $this->sync_domain($domain_uuid);
			if (!$sync['success']) {
				//the runner syncs before it notifies, so the batch still goes out
				self::log_error('sync '.$domain_uuid, $sync['error']);
			}
		}
		finally {
			$this->rollout_unlock();
		}
		self::log('firmware_batch_started', $domain_uuid, 'rollout='.$rollout_uuid.' devices='.count($picked), $username);
		return ['error' => null, 'devices' => count($picked)];
	}

	/**
	 * Aborts a rollout. Decided on what the devices did, not on the runner's last state: a device that fetched
	 * its configuration after its batch started (device_provisioned_date after activate_date) may already run
	 * the release, so it keeps the target (source rollout_kept: a later rollout can take it); the others lose
	 * it. Queued reboots of the rollout are cancelled.
	 *
	 * @return string|null language key of the error
	 */
	public function abort_rollout(string $domain_uuid, string $rollout_uuid, string $username): ?string {
		if (!$this->rollout_lock()) {
			return 'error-firmware_busy';
		}
		try {
			$rollout = $this->rollout($domain_uuid, $rollout_uuid);
			if ($rollout === null || $rollout['rollout_state'] !== 'active') {
				return 'error-firmware_invalid';
			}
			$endpoint_manager = $this->endpoint_manager();
			if ($endpoint_manager !== null) {
				$endpoint_manager->cancel_rollout_queue($domain_uuid, $rollout_uuid, 'Firmware rollout aborted.');
			}
			$sql = "delete from v_x_provision_overlay_firmware_targets as t ";
			$sql .= "where t.firmware_rollout_uuid = :uuid ";
			$sql .= "and not exists (select 1 from v_devices as d where d.device_uuid = t.device_uuid ";
			$sql .= "and d.device_provisioned_date is not null and d.device_provisioned_date > t.activate_date) ";
			$this->query($sql, ['uuid' => $rollout_uuid]);
			$sql = "update v_x_provision_overlay_firmware_targets set target_source = 'rollout_kept', firmware_rollout_uuid = null, target_state = null, ";
			$sql .= "reboot_log_uuid = null, update_date = now() ";
			$sql .= "where firmware_rollout_uuid = :uuid ";
			$this->query($sql, ['uuid' => $rollout_uuid]);
			$this->query("update v_x_provision_overlay_firmware_rollouts set rollout_state = 'aborted', update_date = now() where firmware_rollout_uuid = :uuid ", ['uuid' => $rollout_uuid]);
			$this->sync_domain($domain_uuid);
		}
		finally {
			$this->rollout_unlock();
		}
		self::log('firmware_rollout_aborted', $domain_uuid, 'rollout='.$rollout_uuid, $username);
		return null;
	}

	/**
	 * Finishes a rollout: the release becomes the domain default of the family; the rollout's targets, and the
	 * targets kept by aborted rollouts on the same release, are removed (their devices follow the domain, which
	 * now gives them the same release). Kept targets on another release stay until a batch takes them.
	 *
	 * @return string|null language key of the error
	 */
	public function finish_rollout(string $domain_uuid, string $rollout_uuid, string $username): ?string {
		if (!$this->rollout_lock()) {
			return 'error-firmware_busy';
		}
		try {
			$rollout = $this->rollout($domain_uuid, $rollout_uuid);
			if ($rollout === null || $rollout['rollout_state'] !== 'active') {
				return 'error-firmware_invalid';
			}
			$error = $this->set_default($domain_uuid, $rollout['firmware_vendor'], $rollout['firmware_family'], $rollout['firmware_uuid'], $username, true);
			if ($error !== null) {
				return $error;
			}
			$endpoint_manager = $this->endpoint_manager();
			if ($endpoint_manager !== null) {
				$endpoint_manager->cancel_rollout_queue($domain_uuid, $rollout_uuid, 'Firmware rollout finished.');
			}
			$this->query("delete from v_x_provision_overlay_firmware_targets where firmware_rollout_uuid = :uuid ", ['uuid' => $rollout_uuid]);
			$sql = "delete from v_x_provision_overlay_firmware_targets ";
			$sql .= "where domain_uuid = :domain_uuid and target_source = 'rollout_kept' and firmware_uuid = :firmware_uuid ";
			$this->query($sql, ['domain_uuid' => $domain_uuid, 'firmware_uuid' => $rollout['firmware_uuid']]);
			$this->query("update v_x_provision_overlay_firmware_rollouts set rollout_state = 'finished', update_date = now() where firmware_rollout_uuid = :uuid ", ['uuid' => $rollout_uuid]);
			$this->sync_domain($domain_uuid);
		}
		finally {
			$this->rollout_unlock();
		}
		self::log('firmware_rollout_finished', $domain_uuid, 'rollout='.$rollout_uuid, $username);
		return null;
	}

	/**
	 * Moves the devices of every active rollout on (x-provision-firmware.timer, every 2 minutes):
	 *   written      -> check_sync sent -> notified (after 3 failed attempts: notified anyway, a reboot follows)
	 *   notified     -> fetched once device_provisioned_date is after the batch start; not_fetching after a day
	 *   fetched      -> after reboot_delay (Yealink 20, Grandstream 5 minutes): reboot queued (x_endpoint_manager,
	 *                   trigger firmware) -> rebooting
	 *   rebooting    -> rebooted when sent; busy/unregistered/expired: back to fetched, after 3 attempts pending_boot
	 *   rebooted     -> failed when it fetched again 30 minutes after the reboot and still reports another version
	 *   any          -> done once the phone reports the release's version; a reboot still queued is cancelled
	 * check_sync goes out at most at the endpoint manager's per-domain reboot rate per run. Runs under the rollout
	 * lock (gives up at once when a page action holds it; the next run retries); the overlay lock is held only by
	 * the syncs.
	 *
	 * @return array ['success' => bool, 'error' => string, 'changed' => int]
	 */
	public function run_rollouts(): array {
		$run = ['success' => false, 'error' => '', 'changed' => 0];
		if (!is_object($this->database)) {
			$run['error'] = 'a database connection is required';
			return $run;
		}
		if (!$this->rollout_lock(self::sync_all_lock_timeout)) {
			$run['success'] = true;
			$run['error'] = 'the rollout lock is held by a page action; the next run retries';
			return $run;
		}
		try {
			$rollouts = $this->query("select r.*, f.firmware_version from v_x_provision_overlay_firmware_rollouts as r left join v_x_provision_overlay_firmware as f on f.firmware_uuid = r.firmware_uuid where r.rollout_state = 'active' ");
			if (empty($rollouts)) {
				$run['success'] = true;
				return $run;
			}
			$endpoint_manager = $this->endpoint_manager();
			$synced = [];
			foreach ($rollouts as $rollout) {
				$domain_uuid = (string) $rollout['domain_uuid'];
				$targets = [];
				$sql = "select *, extract(epoch from activate_date) as activate_epoch, extract(epoch from coalesce(state_date, activate_date)) as state_epoch ";
				$sql .= "from v_x_provision_overlay_firmware_targets where firmware_rollout_uuid = :uuid ";
				foreach ($this->query($sql, ['uuid' => $rollout['firmware_rollout_uuid']]) as $row) {
					$targets[$row['device_uuid']] = $row;
				}
				if (empty($targets)) {
					continue;
				}
				//rows first: a phone notified before its rows are written would fetch the old target
				if (!isset($synced[$domain_uuid])) {
					$sync = $this->sync_domain($domain_uuid);
					$synced[$domain_uuid] = $sync['success'];
					if (!$sync['success']) {
						self::log_error('rollout sync '.$domain_uuid, $sync['error']);
					}
				}
				if (!$synced[$domain_uuid]) {
					continue;
				}
				[$in_devices, $parameters] = self::in_list('d', array_keys($targets));
				$sql = "select device_uuid, device_provisioned_agent, device_provisioned_date, ";
				$sql .= "extract(epoch from device_provisioned_date) as provisioned_epoch ";
				$sql .= "from v_devices where domain_uuid = :domain_uuid and device_uuid in (".$in_devices.") ";
				$parameters['domain_uuid'] = $domain_uuid;
				$devices = [];
				foreach ($this->query($sql, $parameters) as $row) {
					$devices[$row['device_uuid']] = $row;
				}
				$statuses = ($endpoint_manager !== null) ? $endpoint_manager->log_statuses(array_filter(array_column($targets, 'reboot_log_uuid'))) : [];
				$notify_budget = ($endpoint_manager !== null) ? max(1, $endpoint_manager->domain_limit($domain_uuid)) * 2 : 0;
				$reboot_delay = self::reboot_delay[(string) $rollout['firmware_vendor']] ?? 300;
				$now = time();
				foreach ($targets as $device_uuid => $target) {
					$state = (string) $target['target_state'];
					$attempts = (int) ($target['target_attempts'] ?? 0);
					$activate = (float) $target['activate_epoch'];
					$since = (float) $target['state_epoch'];
					$device = $devices[$device_uuid] ?? null;
					$agent = self::parse_agent((string) $rollout['firmware_vendor'], (string) ($device['device_provisioned_agent'] ?? ''));
					$fetched = $device !== null && $device['provisioned_epoch'] !== null ? (float) $device['provisioned_epoch'] : 0.0;
					$new = $state;
					$log_uuid = $target['reboot_log_uuid'] ?? null;
					$detail = null;
					if ($device === null) {
						continue;
					}
					if ($agent['version'] !== '' && $agent['version'] === (string) $rollout['firmware_version']) {
						$new = 'done';
						if ($state !== 'done' && provision_overlay::valid_uuid((string) $log_uuid) && $endpoint_manager !== null
							&& $endpoint_manager->cancel_log($domain_uuid, (string) $log_uuid, 'Device already reports firmware '.$rollout['firmware_version'].'.')) {
							$detail = 'Queued reboot cancelled: the device already reports the release.';
						}
					}
					elseif ($state === 'written') {
						if ($endpoint_manager === null || $attempts >= self::notify_attempts) {
							$new = 'notified';
						}
						elseif ($notify_budget > 0) {
							$notify_budget--;
							$sent = $endpoint_manager->check_sync($domain_uuid, $device_uuid);
							$detail = $sent['detail'] ?? '';
							if (($sent['status'] ?? '') === 'sent') {
								$new = 'notified';
							}
							else {
								$attempts++;
							}
						}
					}
					elseif ($state === 'notified' || $state === 'not_fetching') {
						if ($fetched > $activate) {
							$new = 'fetched';
							$attempts = 0;
						}
						elseif ($state === 'notified' && $now - $activate > self::not_fetching_seconds) {
							$new = 'not_fetching';
						}
					}
					elseif ($state === 'fetched') {
						//a Yealink may be flashing the ROM it just fetched: wait before rebooting it
						if ($endpoint_manager !== null && $now - $since >= $reboot_delay) {
							$queued = $endpoint_manager->enqueue_devices($domain_uuid, [$device_uuid], 'firmware', ['user_uuid' => null, 'username' => 'firmware rollout'], null, true, (string) $rollout['firmware_rollout_uuid']);
							if (isset($queued[$device_uuid])) {
								$new = 'rebooting';
								$log_uuid = $queued[$device_uuid]['log_uuid'];
							}
						}
					}
					elseif ($state === 'rebooting') {
						$status = $statuses[(string) $log_uuid]['status'] ?? 'missing';
						if ($status === 'sent') {
							$new = 'rebooted';
						}
						elseif ($status !== 'pending' && $status !== 'processing') {
							$attempts++;
							$detail = $statuses[(string) $log_uuid]['detail'] ?? $status;
							$new = ($attempts >= self::reboot_attempts) ? 'pending_boot' : 'fetched';
						}
					}
					elseif ($state === 'rebooted') {
						if ($fetched > $since && $now - $since > self::fail_after_seconds) {
							$new = 'failed';
						}
					}
					if ($new === $state && $attempts === (int) ($target['target_attempts'] ?? 0)) {
						continue;
					}
					$sql = "update v_x_provision_overlay_firmware_targets set target_state = :state, target_attempts = :attempts, reboot_log_uuid = :log_uuid, ";
					$sql .= "target_detail = :detail, ".(($new !== $state) ? "state_date = now(), " : "")."update_date = now() ";
					$sql .= "where firmware_target_uuid = :uuid and target_source = 'rollout' ";
					$this->query($sql, [
						'state' => $new,
						'attempts' => $attempts,
						'log_uuid' => provision_overlay::valid_uuid((string) $log_uuid) ? $log_uuid : null,
						'detail' => ($detail === null) ? ($target['target_detail'] ?? null) : mb_substr($detail, 0, 255),
						'uuid' => $target['firmware_target_uuid'],
					]);
					$run['changed']++;
				}
			}
			$run['success'] = true;
			return $run;
		}
		catch (Throwable $e) {
			$run['error'] = $e->getMessage();
			return $run;
		}
		finally {
			$this->rollout_unlock();
		}
	}

	//-------------------------------------------------------------------------
	// cleanup (daily overlay job)
	//-------------------------------------------------------------------------

	/**
	 * Daily cleanup: files of deleted releases after the grace period, temp files of interrupted imports,
	 * downloads older than 90 days, and the rows of deleted domains and devices.
	 *
	 * @return array ['files' => int, 'rows' => int, 'errors' => []]
	 */
	public function cleanup(int $now = 0): array {
		$result = ['files' => 0, 'rows' => 0, 'errors' => []];
		$now = ($now > 0) ? $now : time();
		$root = self::firmware_root();
		$entries = is_dir($root) ? @scandir($root) : [];
		foreach (is_array($entries) ? $entries : [] as $entry) {
			$path = $root.'/'.$entry;
			if (preg_match('/^'.preg_quote(self::deleted_prefix, '/').'([0-9]+)-/', $entry, $m) === 1 && $now - (int) $m[1] >= self::grace_seconds && !is_link($path)) {
				if (self::remove_tree($path)) {
					$result['files']++;
				}
				else {
					$result['errors'][] = 'cannot remove '.$path;
				}
				continue;
			}
			//temp files of an import that died
			if (provision_overlay::valid_uuid($entry) && is_dir($path) && !is_link($path)) {
				foreach (@scandir($path) ?: [] as $file) {
					if ($file !== '' && $file[0] === '.' && substr($file, -4) === '.tmp' && $now - (int) @filemtime($path.'/'.$file) >= 3600) {
						@unlink($path.'/'.$file);
					}
				}
			}
		}
		if (!is_object($this->database)) {
			$result['errors'][] = 'no database: rows not cleaned';
			return $result;
		}
		$queries = [
			"delete from v_x_provision_overlay_firmware_downloads where insert_date < now() - interval '".self::download_keep_days." days' returning firmware_download_uuid ",
			"delete from v_x_provision_overlay_firmware_links where domain_uuid not in (select domain_uuid from v_domains) returning firmware_link_uuid ",
			"delete from v_x_provision_overlay_firmware_targets where device_uuid not in (select device_uuid from v_devices) or domain_uuid not in (select domain_uuid from v_domains) returning firmware_target_uuid ",
			"delete from v_x_provision_overlay_firmware_defaults where domain_uuid not in (select domain_uuid from v_domains) returning firmware_default_uuid ",
			"delete from v_x_provision_overlay_firmware_rollouts where domain_uuid not in (select domain_uuid from v_domains) returning firmware_rollout_uuid ",
		];
		foreach ($queries as $sql) {
			try {
				$result['rows'] += count($this->query($sql));
			}
			catch (Throwable $e) {
				$result['errors'][] = $e->getMessage();
			}
		}
		return $result;
	}

	//-------------------------------------------------------------------------
	// logging
	//-------------------------------------------------------------------------

	public static function log_error(string $context, string $detail): void {
		openlog('FusionPBX', LOG_PID, LOG_LOCAL0);
		syslog(LOG_ERR, 'x_provision_overlay: firmware: '.$context.': '.preg_replace('/[\r\n]+/', ' ', $detail));
		closelog();
	}

	private static function log(string $action, string $scope, string $detail, string $username): void {
		$message = 'x_provision_overlay: '.$action.' scope='.$scope.' '.$detail;
		$message .= ' user='.preg_replace('/[^A-Za-z0-9@._ -]/', '_', $username);
		openlog('FusionPBX', LOG_PID, LOG_LOCAL0);
		syslog(LOG_NOTICE, $message);
		closelog();
	}

}

?>
