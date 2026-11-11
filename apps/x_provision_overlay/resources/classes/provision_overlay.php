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
	Provision template overlay (docs/design/provision_overlay.md).

	Layers (all outside the webroot and the upstream git tree):
		upstream  <project>/resources/templates/provision/<vendor>/<model>/<file>
		global    /var/lib/fusionpbx/provision_overlay/global/<vendor>/<model>/<file>
		domain    /var/lib/fusionpbx/provision_overlay/domains/<domain_uuid>/<vendor>/<model>/<file>
		assets    /var/lib/fusionpbx/provision_overlay/assets/<domain_uuid>/<file>
		merged    /var/lib/fusionpbx/provision_overlay/merged/<build-id>/   (generated)
		link      /usr/share/fusionpbx/templates/provision -> .../merged/current

	The merged tree symlinks upstream files and copies overlay files. Domains with at least one
	override get a full <domain_name>/ subtree because upstream treats that subdir as all-or-nothing
	(app/provision/resources/classes/provision.php render(), "if the domain name directory exists").

	The generator is plain PHP. It needs a database only to map domain uuids to names, which is
	only required when a domain has overrides, and for disable/enable.
*/

//define the provision_overlay class
class provision_overlay {

	//state root
	const state_root = '/var/lib/fusionpbx/provision_overlay';

	//first entry of every hard-coded upstream search list (provision.php constructor, device.php get_template_dir())
	const link_path = '/usr/share/fusionpbx/templates/provision';

	//default setting row provision.template_dir owned by this project (inserted once by app_defaults.php)
	const template_dir_setting_uuid = 'd250396f-c95d-4632-9bfe-5c556b665c49';

	//limits
	const template_max_bytes = 1048576;
	const asset_default_max_bytes = 2097152;
	const asset_max_files = 50;
	const asset_max_dimension = 4096;
	const lock_timeout = 120;

	//patterns
	const name_pattern = '/^[A-Za-z0-9._{}$-]{1,64}$/';
	const domain_name_pattern = '/^[a-z0-9][a-z0-9.-]{0,252}$/';
	const asset_name_pattern = '/^[a-z0-9][a-z0-9._-]{0,63}$/';
	const build_id_pattern = '/^[0-9]{8}T[0-9]{6}-[0-9a-f]{6}$/';
	const uuid_pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	//accepted asset types (sniffed mime => stored extension)
	const asset_types = [
		'image/png' => 'png',
		'image/jpeg' => 'jpg',
		'image/bmp' => 'bmp',
		'image/gif' => 'gif',
	];

	//vendor settings that take an image url and may be written per domain by "apply to vendor settings"
	const vendor_setting_patterns = [
		'/^polycom_background_url$/',
		'/^grandstream_wallpaper_url$/',
		'/^poly_obi_logo_[a-z0-9]+$/',
		'/^yealink_[a-z0-9]+_wallpaper$/',
	];

	/**
	 * @var database|null
	 */
	private $database;

	/**
	 * @var array|null domain_uuid => row, loaded on demand
	 */
	private $domains;

	/**
	 * @var resource|null
	 */
	private $lock_handle;

	/**
	 * @var int re-entrant lock depth
	 */
	private $lock_depth = 0;

	/**
	 * Initializes the object. The database is optional; it is only needed for domain overrides,
	 * vendor checks, settings and disable/enable.
	 */
	public function __construct(array $setting_array = []) {
		$this->database = $setting_array['database'] ?? null;
	}

	/**
	 * Sets the database object after construction (the CLI connects only when needed).
	 */
	public function set_database($database): void {
		$this->database = $database;
		$this->domains = null;
	}

	/**
	 * True when a database object is available.
	 */
	public function has_database(): bool {
		return is_object($this->database);
	}

	//-------------------------------------------------------------------------
	// paths
	//-------------------------------------------------------------------------

	/**
	 * Project root (/var/www/fusionpbx).
	 */
	public static function project_root(): string {
		return dirname(__DIR__, 4);
	}

	/**
	 * Upstream template directory inside the git tree.
	 */
	public static function upstream_dir(): string {
		return self::project_root().'/resources/templates/provision';
	}

	public static function global_dir(): string {
		return self::state_root.'/global';
	}

	public static function domains_dir(): string {
		return self::state_root.'/domains';
	}

	public static function assets_root(): string {
		return self::state_root.'/assets';
	}

	public static function merged_dir(): string {
		return self::state_root.'/merged';
	}

	public static function current_link(): string {
		return self::state_root.'/merged/current';
	}

	public static function disabled_flag(): string {
		return self::state_root.'/.disabled';
	}

	/**
	 * Overlay root of a scope. Returns null for an invalid scope or domain uuid.
	 */
	public static function scope_dir(string $scope, string $domain_uuid = ''): ?string {
		if ($scope === 'global') {
			return self::global_dir();
		}
		if ($scope === 'domain' && self::valid_uuid($domain_uuid)) {
			return self::domains_dir().'/'.$domain_uuid;
		}
		return null;
	}

	/**
	 * Asset directory of a domain. Returns null for an invalid uuid.
	 */
	public static function asset_dir(string $domain_uuid): ?string {
		if (!self::valid_uuid($domain_uuid)) {
			return null;
		}
		return self::assets_root().'/'.$domain_uuid;
	}

	//-------------------------------------------------------------------------
	// validation
	//-------------------------------------------------------------------------

	public static function valid_uuid(string $uuid): bool {
		return preg_match(self::uuid_pattern, $uuid) === 1;
	}

	/**
	 * Path component of a vendor, model or file name. Upstream names contain {$mac} and {$address}.
	 */
	public static function valid_name(string $name): bool {
		return preg_match(self::name_pattern, $name) === 1
			&& $name[0] !== '.'
			&& strpos($name, '..') === false;
	}

	/**
	 * Names never copied or linked: dotfiles plus the ignore list of provision::write().
	 */
	public static function ignored(string $name): bool {
		return $name === ''
			|| $name[0] === '.'
			|| substr($name, -3) === '.db'
			|| substr($name, -4) === '.svn'
			|| substr($name, -4) === '.git';
	}

	public static function valid_asset_name(string $name): bool {
		return preg_match(self::asset_name_pattern, $name) === 1 && strpos($name, '..') === false;
	}

	public static function valid_vendor_setting(string $subcategory): bool {
		foreach (self::vendor_setting_patterns as $pattern) {
			if (preg_match($pattern, $subcategory) === 1) {
				return true;
			}
		}
		return false;
	}

	//-------------------------------------------------------------------------
	// locking
	//-------------------------------------------------------------------------

	/**
	 * Takes the exclusive overlay lock. Re-entrant within the process.
	 */
	public function lock(): bool {
		if ($this->lock_depth > 0) {
			$this->lock_depth++;
			return true;
		}
		if (!is_dir(self::state_root)) {
			return false;
		}
		$handle = @fopen(self::state_root.'/.lock', 'c');
		if ($handle === false) {
			return false;
		}
		$deadline = time() + self::lock_timeout;
		while (!flock($handle, LOCK_EX | LOCK_NB)) {
			if (time() >= $deadline) {
				fclose($handle);
				return false;
			}
			usleep(200000);
		}
		$this->lock_handle = $handle;
		$this->lock_depth = 1;
		return true;
	}

	public function unlock(): void {
		if ($this->lock_depth <= 0) {
			return;
		}
		$this->lock_depth--;
		if ($this->lock_depth === 0 && is_resource($this->lock_handle)) {
			flock($this->lock_handle, LOCK_UN);
			fclose($this->lock_handle);
			$this->lock_handle = null;
		}
	}

	//-------------------------------------------------------------------------
	// filesystem helpers (throw on failure so a build never half-succeeds)
	//-------------------------------------------------------------------------

	private static function list_dir(string $path): array {
		$entries = @scandir($path);
		if (!is_array($entries)) {
			return [];
		}
		return array_values(array_filter($entries, function ($e) { return $e !== '.' && $e !== '..'; }));
	}

	private static function fs_mkdir(string $path): void {
		if (is_dir($path) && !is_link($path)) {
			return;
		}
		if (!@mkdir($path, 0750, true) && !is_dir($path)) {
			throw new RuntimeException('cannot create directory '.$path);
		}
		@chmod($path, 0750);
	}

	private static function fs_symlink(string $target, string $link): void {
		if (is_link($link) || file_exists($link)) {
			if (!@unlink($link)) {
				throw new RuntimeException('cannot replace '.$link);
			}
		}
		if (!@symlink($target, $link)) {
			throw new RuntimeException('cannot create symlink '.$link);
		}
	}

	private static function fs_copy(string $source, string $destination): void {
		if (is_link($destination) || file_exists($destination)) {
			if (!@unlink($destination)) {
				throw new RuntimeException('cannot replace '.$destination);
			}
		}
		if (!@copy($source, $destination)) {
			throw new RuntimeException('cannot copy '.$source);
		}
		@chmod($destination, 0640);
	}

	/**
	 * Writes a file atomically (temp file in the same directory, then rename).
	 */
	private static function fs_write_atomic(string $path, string $content): bool {
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

	/**
	 * Removes a tree without following symlinks.
	 */
	private static function remove_tree(string $path): bool {
		if (is_link($path) || is_file($path)) {
			return @unlink($path);
		}
		if (!is_dir($path)) {
			return true;
		}
		foreach (self::list_dir($path) as $entry) {
			self::remove_tree($path.'/'.$entry);
		}
		return @rmdir($path);
	}

	/**
	 * Removes empty parent directories up to (not including) the stop directory.
	 */
	private static function prune_empty_dirs(string $path, string $stop): void {
		$stop = rtrim($stop, '/');
		while ($path !== $stop && strpos($path, $stop.'/') === 0) {
			if (!is_dir($path) || count(self::list_dir($path)) > 0 || !@rmdir($path)) {
				return;
			}
			$path = dirname($path);
		}
	}

	//-------------------------------------------------------------------------
	// scanning
	//-------------------------------------------------------------------------

	/**
	 * Scans a <vendor>/<model>/<file> tree.
	 *
	 * @param bool $overlay overlay trees are untrusted: symlinks, invalid names and nested dirs are skipped with a warning
	 * @return array ['templates' => ['vendor/model' => [file => path]], 'vendor_files' => [vendor => [file => path]]]
	 */
	private static function scan_tree(string $root, bool $overlay, array &$warnings): array {
		$result = ['templates' => [], 'vendor_files' => []];
		if (!is_dir($root)) {
			return $result;
		}
		foreach (self::list_dir($root) as $vendor) {
			$vendor_path = $root.'/'.$vendor;
			if (self::ignored($vendor)) {
				continue;
			}
			if (!self::valid_name($vendor) || ($overlay && is_link($vendor_path))) {
				if ($overlay) {
					$warnings[] = 'skipped invalid overlay entry '.$vendor_path;
				}
				continue;
			}
			if (!is_dir($vendor_path)) {
				continue;
			}
			foreach (self::list_dir($vendor_path) as $model) {
				$model_path = $vendor_path.'/'.$model;
				if (self::ignored($model)) {
					continue;
				}
				if (!self::valid_name($model) || ($overlay && is_link($model_path))) {
					if ($overlay) {
						$warnings[] = 'skipped invalid overlay entry '.$model_path;
					}
					continue;
				}
				if (is_dir($model_path)) {
					$files = [];
					foreach (self::list_dir($model_path) as $file) {
						$file_path = $model_path.'/'.$file;
						if (self::ignored($file)) {
							continue;
						}
						if (!self::valid_name($file) || ($overlay && is_link($file_path)) || !is_file($file_path)) {
							$warnings[] = 'skipped '.($overlay ? 'overlay' : 'upstream').' entry '.$file_path;
							continue;
						}
						$files[$file] = $file_path;
					}
					ksort($files, SORT_STRING);
					//upstream template dirs are listed even when empty (the device dropdown lists dirs)
					if (!$overlay || !empty($files)) {
						$result['templates'][$vendor.'/'.$model] = $files;
					}
				}
				elseif (!$overlay && is_file($model_path)) {
					//vendor level files exist upstream (README.md, empty.txt); they are linked as they are
					$result['vendor_files'][$vendor][$model] = $model_path;
				}
			}
		}
		ksort($result['templates'], SORT_STRING);
		return $result;
	}

	/**
	 * Domain override roots that contain a uuid named directory.
	 *
	 * @return array domain_uuid => path
	 */
	private static function domain_override_dirs(array &$warnings): array {
		$dirs = [];
		foreach (self::list_dir(self::domains_dir()) as $entry) {
			$path = self::domains_dir().'/'.$entry;
			if (!self::valid_uuid($entry) || is_link($path) || !is_dir($path)) {
				$warnings[] = 'skipped invalid domain override directory '.$path;
				continue;
			}
			$dirs[$entry] = $path;
		}
		return $dirs;
	}

	/**
	 * True when at least one domain has an override file (the build then needs the database).
	 */
	public static function has_domain_overrides(): bool {
		$warnings = [];
		foreach (self::domain_override_dirs($warnings) as $path) {
			$tree = self::scan_tree($path, true, $warnings);
			if (!empty($tree['templates'])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when any override exists (global or domain).
	 */
	public static function has_overrides(): bool {
		$warnings = [];
		$tree = self::scan_tree(self::global_dir(), true, $warnings);
		return !empty($tree['templates']) || self::has_domain_overrides();
	}

	//-------------------------------------------------------------------------
	// database lookups
	//-------------------------------------------------------------------------

	/**
	 * Domains keyed by uuid, or null without a database.
	 */
	public function get_domains(): ?array {
		if (!$this->has_database()) {
			return null;
		}
		if ($this->domains === null) {
			$sql = "select domain_uuid, domain_name, domain_enabled from v_domains ";
			$rows = $this->database->select($sql, null, 'all');
			$this->domains = [];
			if (is_array($rows)) {
				foreach ($rows as $row) {
					$this->domains[$row['domain_uuid']] = $row;
				}
			}
		}
		return $this->domains;
	}

	/**
	 * Vendor names in v_device_vendors (the device template dropdown lists only these), or null without a database.
	 */
	public function get_device_vendors(): ?array {
		if (!$this->has_database()) {
			return null;
		}
		$sql = "select name from v_device_vendors where enabled = 'true' ";
		$rows = $this->database->select($sql, null, 'all');
		$vendors = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$vendors[$row['name']] = true;
			}
		}
		return $vendors;
	}

	//-------------------------------------------------------------------------
	// build state
	//-------------------------------------------------------------------------

	/**
	 * State of the upstream search path: ours, missing or foreign.
	 */
	public static function link_state(): string {
		if (is_link(self::link_path)) {
			return (readlink(self::link_path) === self::current_link()) ? 'ours' : 'foreign';
		}
		return file_exists(self::link_path) ? 'foreign' : 'missing';
	}

	public static function is_disabled(): bool {
		return file_exists(self::disabled_flag());
	}

	/**
	 * Build id the current link points to, or ''.
	 */
	public static function current_id(): string {
		$link = self::current_link();
		if (!is_link($link)) {
			return '';
		}
		$target = readlink($link);
		if (!is_string($target) || preg_match(self::build_id_pattern, $target) !== 1 || !is_dir(self::merged_dir().'/'.$target)) {
			return '';
		}
		return $target;
	}

	/**
	 * Build ids on disk, oldest first.
	 */
	public static function build_ids(): array {
		$ids = [];
		foreach (self::list_dir(self::merged_dir()) as $entry) {
			$path = self::merged_dir().'/'.$entry;
			if (preg_match(self::build_id_pattern, $entry) === 1 && is_dir($path) && !is_link($path)) {
				$ids[] = $entry;
			}
		}
		sort($ids, SORT_STRING);
		return $ids;
	}

	/**
	 * Decoded .build.json of a build, or null.
	 */
	public static function build_info(string $id): ?array {
		if (preg_match(self::build_id_pattern, $id) !== 1) {
			return null;
		}
		$file = self::merged_dir().'/'.$id.'/.build.json';
		if (!is_file($file)) {
			return null;
		}
		$data = json_decode((string) file_get_contents($file), true);
		return is_array($data) ? $data : null;
	}

	/**
	 * Commit of the upstream git checkout, read from .git without running git.
	 */
	public static function upstream_commit(): string {
		$git = self::project_root().'/.git';
		$head = @file_get_contents($git.'/HEAD');
		if (!is_string($head)) {
			return '';
		}
		$head = trim($head);
		if (preg_match('/^[0-9a-f]{40}$/', $head) === 1) {
			return $head;
		}
		if (preg_match('#^ref: (refs/[A-Za-z0-9._/-]+)$#', $head, $matches) !== 1 || strpos($matches[1], '..') !== false) {
			return '';
		}
		$ref = @file_get_contents($git.'/'.$matches[1]);
		if (is_string($ref) && preg_match('/^[0-9a-f]{40}/', trim($ref), $m) === 1) {
			return $m[0];
		}
		$packed = @file($git.'/packed-refs', FILE_IGNORE_NEW_LINES);
		if (is_array($packed)) {
			foreach ($packed as $line) {
				if (preg_match('/^([0-9a-f]{40}) (.+)$/', $line, $m) === 1 && $m[2] === $matches[1]) {
					return $m[1];
				}
			}
		}
		return '';
	}

	/**
	 * Status summary for the CLI and the build page.
	 */
	public function status(): array {
		$current = self::current_id();
		return [
			'link_state' => self::link_state(),
			'disabled' => self::is_disabled(),
			'current_id' => $current,
			'build' => ($current !== '') ? self::build_info($current) : null,
			'builds' => self::build_ids(),
			'upstream_commit' => self::upstream_commit(),
		];
	}

	//-------------------------------------------------------------------------
	// build
	//-------------------------------------------------------------------------

	/**
	 * Regenerates the merged tree and swaps merged/current to it.
	 *
	 * @return array ['success' => bool, 'id' => string, 'errors' => [], 'warnings' => [], 'counts' => []]
	 */
	public function build(): array {
		if (!$this->lock()) {
			return ['success' => false, 'id' => '', 'errors' => ['cannot acquire the overlay lock '.self::state_root.'/.lock'], 'warnings' => [], 'counts' => []];
		}
		try {
			return $this->build_locked();
		}
		finally {
			$this->unlock();
		}
	}

	private function build_locked(): array {
		$result = ['success' => false, 'id' => '', 'errors' => [], 'warnings' => [], 'counts' => []];
		$warnings = [];

		//refuse when something else owns the upstream search path (e.g. a packaged template dir)
		if (self::link_state() === 'foreign') {
			$result['errors'][] = self::link_path.' exists and is not a symlink to '.self::current_link().'; refusing to build';
			return $result;
		}
		$upstream_dir = self::upstream_dir();
		if (!is_dir($upstream_dir)) {
			$result['errors'][] = 'upstream template directory not found: '.$upstream_dir;
			return $result;
		}
		$merged = self::merged_dir();
		try {
			self::fs_mkdir($merged);
		}
		catch (RuntimeException $e) {
			$result['errors'][] = $e->getMessage();
			return $result;
		}

		//remove leftovers of interrupted builds; the lock is held so no build is running
		foreach (self::list_dir($merged) as $entry) {
			if (strpos($entry, '.tmp-') === 0 || strpos($entry, '.current-') === 0) {
				self::remove_tree($merged.'/'.$entry);
			}
		}

		//scan the layers
		$upstream = self::scan_tree($upstream_dir, false, $warnings);
		$global = self::scan_tree(self::global_dir(), true, $warnings);
		$vendors = [];
		foreach (array_merge(array_keys($upstream['templates']), array_keys($global['templates'])) as $t) {
			$vendors[explode('/', $t, 2)[0]] = true;
		}
		foreach (array_keys($upstream['vendor_files']) as $vendor) {
			$vendors[$vendor] = true;
		}

		//domains with overrides, keyed by uuid, mapped to the current domain name
		$domain_trees = [];
		foreach (self::domain_override_dirs($warnings) as $domain_uuid => $path) {
			$tree = self::scan_tree($path, true, $warnings);
			if (!empty($tree['templates'])) {
				$domain_trees[$domain_uuid] = $tree;
			}
		}
		$domain_names = [];
		if (!empty($domain_trees)) {
			$domains = $this->get_domains();
			if ($domains === null) {
				$result['errors'][] = 'domain overrides exist but no database connection is available to map domain uuids to names';
				return $result;
			}
			foreach ($domain_trees as $domain_uuid => $tree) {
				if (!isset($domains[$domain_uuid])) {
					$warnings[] = 'domain '.$domain_uuid.' no longer exists; its overrides are kept but not built';
					continue;
				}
				$name = (string) $domains[$domain_uuid]['domain_name'];
				if (preg_match(self::domain_name_pattern, $name) !== 1) {
					$warnings[] = 'domain name "'.$name.'" ('.$domain_uuid.') is not usable as a template subdirectory; only global overrides apply to it';
					continue;
				}
				if (isset($vendors[$name])) {
					$warnings[] = 'domain name "'.$name.'" ('.$domain_uuid.') collides with a vendor directory; only global overrides apply to it';
					continue;
				}
				$domain_names[$domain_uuid] = $name;
			}
		}

		//build into a temp dir, rename into place when complete
		$id = gmdate('Ymd\THis').'-'.bin2hex(random_bytes(3));
		$work = $merged.'/.tmp-'.$id;
		$final = $merged.'/'.$id;
		$counts = ['templates' => 0, 'upstream_files' => 0, 'global_overrides' => 0, 'domains' => 0, 'domain_overrides' => 0];
		$overrides = [];
		$old_umask = umask(0027);
		try {
			self::fs_mkdir($work);

			//global merged view: upstream links, global copies replace them
			$templates = array_keys($upstream['templates'] + $global['templates']);
			sort($templates, SORT_STRING);
			$merged_files = [];
			foreach ($templates as $t) {
				self::fs_mkdir($work.'/'.$t);
				foreach ($upstream['templates'][$t] ?? [] as $file => $source) {
					self::fs_symlink($source, $work.'/'.$t.'/'.$file);
					$merged_files[$t][$file] = $source;
					$counts['upstream_files']++;
				}
				foreach ($global['templates'][$t] ?? [] as $file => $source) {
					self::fs_copy($source, $work.'/'.$t.'/'.$file);
					//domain views link to the copy in the final build dir
					$merged_files[$t][$file] = $final.'/'.$t.'/'.$file;
					$overrides[] = $t.'/'.$file;
					$counts['global_overrides']++;
				}
				$counts['templates']++;
			}
			foreach ($upstream['vendor_files'] as $vendor => $files) {
				self::fs_mkdir($work.'/'.$vendor);
				foreach ($files as $file => $source) {
					self::fs_symlink($source, $work.'/'.$vendor.'/'.$file);
				}
			}

			//domain views: every template (the domain subdir is all-or-nothing), domain copies replace links
			foreach ($domain_names as $domain_uuid => $name) {
				$tree = $domain_trees[$domain_uuid];
				$domain_templates = array_unique(array_merge($templates, array_keys($tree['templates'])));
				sort($domain_templates, SORT_STRING);
				foreach ($domain_templates as $t) {
					self::fs_mkdir($work.'/'.$name.'/'.$t);
					foreach ($merged_files[$t] ?? [] as $file => $target) {
						self::fs_symlink($target, $work.'/'.$name.'/'.$t.'/'.$file);
					}
					foreach ($tree['templates'][$t] ?? [] as $file => $source) {
						self::fs_copy($source, $work.'/'.$name.'/'.$t.'/'.$file);
						$overrides[] = $name.'/'.$t.'/'.$file;
						$counts['domain_overrides']++;
					}
				}
				foreach ($upstream['vendor_files'] as $vendor => $files) {
					self::fs_mkdir($work.'/'.$name.'/'.$vendor);
					foreach ($files as $file => $source) {
						self::fs_symlink($source, $work.'/'.$name.'/'.$vendor.'/'.$file);
					}
				}
				$counts['domains']++;
			}

			//record the build
			$info = [
				'id' => $id,
				'upstream_commit' => self::upstream_commit(),
				'generated_at' => gmdate('c'),
				'counts' => $counts,
				'domains' => $domain_names,
				'overrides' => $overrides,
				'warnings' => $warnings,
			];
			$json = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			if ($json === false || @file_put_contents($work.'/.build.json', $json."\n") === false) {
				throw new RuntimeException('cannot write .build.json');
			}
			@chmod($work.'/.build.json', 0640);

			if (!@rename($work, $final)) {
				throw new RuntimeException('cannot rename '.$work.' to '.$final);
			}
		}
		catch (Throwable $e) {
			umask($old_umask);
			self::remove_tree($work);
			$result['errors'][] = $e->getMessage();
			$result['warnings'] = $warnings;
			return $result;
		}
		umask($old_umask);

		//swap current atomically: new symlink, then rename over the old one
		$previous = self::current_id();
		$error = self::point_current($id);
		if ($error !== null) {
			self::remove_tree($final);
			$result['errors'][] = $error;
			$result['warnings'] = $warnings;
			return $result;
		}

		//keep the new build and the one before it (for rollback)
		foreach (self::build_ids() as $old) {
			if ($old !== $id && $old !== $previous) {
				self::remove_tree($merged.'/'.$old);
			}
		}

		//compiled templates are keyed by path, not by content; see purge_compiled_templates()
		$this->after_switch($previous, $id, $warnings);

		$result['success'] = true;
		$result['id'] = $id;
		$result['counts'] = $counts;
		$result['warnings'] = $warnings;
		return $result;
	}

	/**
	 * Points merged/current at a build id.
	 *
	 * @return string|null null on success, otherwise the error
	 */
	private static function point_current(string $id): ?string {
		$temp_link = self::merged_dir().'/.current-'.$id;
		@unlink($temp_link);
		if (!@symlink($id, $temp_link)) {
			return 'cannot create '.$temp_link;
		}
		if (!@rename($temp_link, self::current_link())) {
			@unlink($temp_link);
			return 'cannot replace '.self::current_link();
		}
		return null;
	}

	/**
	 * Smarty compiles into sys_get_temp_dir() and keys compiled files by template path, recompiling
	 * only when the source mtime is newer (template.php init(), Smarty_Template_Compiled). A path that
	 * switches from an overlay copy back to an upstream symlink resolves to an older mtime, so the stale
	 * compiled overlay would keep being served. Compiled files are deleted after every switch; this
	 * only reaches php-fpm's (private) temp dir when the switch runs inside php-fpm, so CLI switches that
	 * removed an override warn that php-fpm must be restarted.
	 */
	private function after_switch(string $previous, string $id, array &$warnings): void {
		self::purge_compiled_templates();
		if (PHP_SAPI !== 'cli') {
			return;
		}
		$old = ($previous !== '') ? self::build_info($previous) : null;
		$new = self::build_info($id);
		$removed = array_diff($old['overrides'] ?? [], $new['overrides'] ?? []);
		if (!empty($removed)) {
			$warnings[] = count($removed).' override(s) were removed in this switch; restart php-fpm so compiled copies of the old templates are discarded';
		}
	}

	/**
	 * Deletes Smarty compiled templates ("<sha1>_<n>.file.<name>.php") in this process' temp dir.
	 * They are recompiled on the next render.
	 */
	public static function purge_compiled_templates(): int {
		$count = 0;
		$files = glob(sys_get_temp_dir().'/*.file.*.php');
		if (!is_array($files)) {
			return 0;
		}
		foreach ($files as $file) {
			if (preg_match('/^[0-9a-f]{40}_[0-9]+\.file\..+\.php$/', basename($file)) === 1 && is_file($file) && !is_link($file)) {
				if (@unlink($file)) {
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Points current at the previous build.
	 */
	public function rollback(): array {
		$result = ['success' => false, 'id' => '', 'errors' => [], 'warnings' => []];
		if (!$this->lock()) {
			$result['errors'][] = 'cannot acquire the overlay lock';
			return $result;
		}
		try {
			$current = self::current_id();
			$candidates = array_values(array_filter(self::build_ids(), function ($id) use ($current) { return $id !== $current; }));
			if (empty($candidates)) {
				$result['errors'][] = 'no previous build to roll back to';
				return $result;
			}
			$target = end($candidates);
			$error = self::point_current($target);
			if ($error !== null) {
				$result['errors'][] = $error;
				return $result;
			}
			self::purge_compiled_templates();
			if (PHP_SAPI === 'cli') {
				$result['warnings'][] = 'restart php-fpm so compiled copies of the rolled back templates are discarded';
			}
			$result['success'] = true;
			$result['id'] = $target;
			return $result;
		}
		finally {
			$this->unlock();
		}
	}

	//-------------------------------------------------------------------------
	// verify
	//-------------------------------------------------------------------------

	/**
	 * Reports problems (link, dangling symlinks, bad or orphaned domain dirs, invalid overlay entries)
	 * and warnings (overrides without an upstream counterpart, overlay-only vendors without a vendor row).
	 *
	 * @return array ['problems' => [], 'warnings' => []]
	 */
	public function verify(): array {
		$problems = [];
		$warnings = [];

		//link and current build
		$link_state = self::link_state();
		if (self::is_disabled()) {
			$warnings[] = 'overlay is disabled ('.self::disabled_flag().' exists)';
		}
		elseif ($link_state !== 'ours') {
			$problems[] = self::link_path.' is '.$link_state.' (expected a symlink to '.self::current_link().')';
		}
		$current = self::current_id();
		if ($current === '') {
			$problems[] = 'no current build ('.self::current_link().')';
		}
		else {
			$dangling = [];
			self::find_dangling(self::merged_dir().'/'.$current, $dangling);
			if (!empty($dangling)) {
				$problems[] = count($dangling).' dangling symlink(s) in build '.$current.', e.g. '.implode(', ', array_slice($dangling, 0, 10)).' (rebuild to fix)';
			}
			$info = self::build_info($current);
			if ($info === null) {
				$problems[] = 'build '.$current.' has no readable .build.json';
			}
		}

		//overlay scan; invalid entries are problems
		$scan_warnings = [];
		$upstream = self::scan_tree(self::upstream_dir(), false, $scan_warnings);
		$scan_warnings = [];
		$global = self::scan_tree(self::global_dir(), true, $scan_warnings);
		$domain_trees = [];
		foreach (self::domain_override_dirs($scan_warnings) as $domain_uuid => $path) {
			$tree = self::scan_tree($path, true, $scan_warnings);
			if (!empty($tree['templates'])) {
				$domain_trees[$domain_uuid] = $tree;
			}
		}
		foreach ($scan_warnings as $w) {
			$problems[] = $w;
		}

		//overrides without an upstream counterpart
		foreach ($global['templates'] as $t => $files) {
			if (!isset($upstream['templates'][$t])) {
				$warnings[] = 'global: upstream template missing for '.$t.' (overlay-only template)';
				continue;
			}
			foreach ($files as $file => $path) {
				if (!isset($upstream['templates'][$t][$file])) {
					$warnings[] = 'global: no upstream counterpart for '.$t.'/'.$file;
				}
			}
		}
		$domains = $this->get_domains();
		foreach ($domain_trees as $domain_uuid => $tree) {
			$label = $domain_uuid;
			if ($domains !== null) {
				if (!isset($domains[$domain_uuid])) {
					$problems[] = 'orphaned overrides: domain '.$domain_uuid.' no longer exists ('.self::domains_dir().'/'.$domain_uuid.')';
					continue;
				}
				$label = $domains[$domain_uuid]['domain_name'];
				if (preg_match(self::domain_name_pattern, (string) $label) !== 1) {
					$problems[] = 'domain name "'.$label.'" ('.$domain_uuid.') cannot have a template subdirectory; its overrides are not applied';
				}
			}
			foreach ($tree['templates'] as $t => $files) {
				if (!isset($upstream['templates'][$t]) && !isset($global['templates'][$t])) {
					$warnings[] = $label.': upstream template missing for '.$t.' (overlay-only template)';
					continue;
				}
				foreach ($files as $file => $path) {
					if (!isset($upstream['templates'][$t][$file]) && !isset($global['templates'][$t][$file])) {
						$warnings[] = $label.': no upstream counterpart for '.$t.'/'.$file;
					}
				}
			}
		}

		//overlay-only vendors need a v_device_vendors row to appear in the device template dropdown
		$device_vendors = $this->get_device_vendors();
		if ($device_vendors !== null) {
			$overlay_templates = array_keys($global['templates']);
			foreach ($domain_trees as $tree) {
				$overlay_templates = array_merge($overlay_templates, array_keys($tree['templates']));
			}
			$checked = [];
			foreach ($overlay_templates as $t) {
				$vendor = explode('/', $t, 2)[0];
				if (!isset($checked[$vendor]) && !isset($device_vendors[$vendor])) {
					$warnings[] = 'vendor "'.$vendor.'" has no enabled row in v_device_vendors; its templates are not listed in the device template dropdown';
				}
				$checked[$vendor] = true;
			}
		}
		elseif (!empty($domain_trees)) {
			$warnings[] = 'no database connection: domain and vendor checks skipped';
		}

		return ['problems' => $problems, 'warnings' => $warnings];
	}

	private static function find_dangling(string $path, array &$dangling): void {
		foreach (self::list_dir($path) as $entry) {
			$full = $path.'/'.$entry;
			if (is_link($full)) {
				if (!file_exists($full)) {
					$dangling[] = substr($full, strlen(self::merged_dir()) + 1);
				}
			}
			elseif (is_dir($full)) {
				self::find_dangling($full, $dangling);
			}
		}
	}

	//-------------------------------------------------------------------------
	// disable / enable
	//-------------------------------------------------------------------------

	/**
	 * Removes the upstream search path link and disables the provision.template_dir row.
	 * Overlay state is kept. Needs a database.
	 */
	public function disable(): array {
		$result = ['success' => false, 'errors' => [], 'warnings' => []];
		if (!$this->has_database()) {
			$result['errors'][] = 'a database connection is required';
			return $result;
		}
		if (!$this->lock()) {
			$result['errors'][] = 'cannot acquire the overlay lock';
			return $result;
		}
		try {
			if (!@touch(self::disabled_flag())) {
				$result['errors'][] = 'cannot create '.self::disabled_flag();
				return $result;
			}
			$state = self::link_state();
			if ($state === 'ours') {
				if (!@unlink(self::link_path)) {
					$result['errors'][] = 'cannot remove '.self::link_path;
					return $result;
				}
			}
			elseif ($state === 'foreign') {
				$result['warnings'][] = self::link_path.' is not managed by this app and was left alone';
			}

			$sql = "update v_default_settings set default_setting_enabled = false ";
			$sql .= "where default_setting_uuid = :uuid ";
			$parameters['uuid'] = self::template_dir_setting_uuid;
			$this->database->execute($sql, $parameters);
			unset($sql, $parameters);

			$count = $this->count_other_template_dir_settings();
			if ($count > 0) {
				$result['warnings'][] = $count.' domain or device setting(s) still set provision.template_dir to '.self::link_path.'; phones using them get 404 until those are removed';
			}
			$result['warnings'][] = 'restart php-fpm to clear the settings cache (APCu)';
			$result['success'] = true;
			return $result;
		}
		finally {
			$this->unlock();
		}
	}

	/**
	 * Reverses disable(): rebuilds, recreates the link and enables the provision.template_dir row.
	 */
	public function enable(): array {
		$result = ['success' => false, 'errors' => [], 'warnings' => []];
		if (!$this->has_database()) {
			$result['errors'][] = 'a database connection is required';
			return $result;
		}
		if (!$this->lock()) {
			$result['errors'][] = 'cannot acquire the overlay lock';
			return $result;
		}
		try {
			$build = $this->build();
			if (!$build['success']) {
				$result['errors'] = $build['errors'];
				return $result;
			}
			$result['warnings'] = $build['warnings'];
			$state = self::link_state();
			if ($state === 'foreign') {
				$result['errors'][] = self::link_path.' exists and is not ours';
				return $result;
			}
			if ($state === 'missing') {
				if (!is_dir(dirname(self::link_path)) || !@symlink(self::current_link(), self::link_path)) {
					$result['errors'][] = 'cannot create '.self::link_path.'; rerun the installer (it creates '.dirname(self::link_path).' owned by www-data)';
					return $result;
				}
			}
			@unlink(self::disabled_flag());

			$sql = "update v_default_settings set default_setting_enabled = true ";
			$sql .= "where default_setting_uuid = :uuid ";
			$parameters['uuid'] = self::template_dir_setting_uuid;
			$this->database->execute($sql, $parameters);
			unset($sql, $parameters);

			$result['warnings'][] = 'restart php-fpm to clear the settings cache (APCu)';
			$result['success'] = true;
			return $result;
		}
		finally {
			$this->unlock();
		}
	}

	private function count_other_template_dir_settings(): int {
		$total = 0;
		$queries = [
			"select count(*) from v_domain_settings where domain_setting_category = 'provision' and domain_setting_subcategory = 'template_dir' and domain_setting_value = :path and domain_setting_enabled = true ",
			"select count(*) from v_device_settings where device_setting_category = 'provision' and device_setting_subcategory = 'template_dir' and device_setting_value = :path and device_setting_enabled = true ",
		];
		foreach ($queries as $sql) {
			$parameters['path'] = self::link_path;
			$count = $this->database->select($sql, $parameters, 'column');
			$total += is_numeric($count) ? (int) $count : 0;
			unset($parameters);
		}
		return $total;
	}

	//-------------------------------------------------------------------------
	// template overrides (admin UI)
	//-------------------------------------------------------------------------

	/**
	 * Every <vendor>/<model> (upstream, global, domain) with per-file status for a domain.
	 *
	 * @return array 'vendor/model' => ['upstream' => bool, 'global' => bool, 'domain' => bool, 'files' => [file => ['upstream' => bool, 'global' => bool, 'domain' => bool]]]
	 */
	public function template_index(string $domain_uuid): array {
		$warnings = [];
		$layers = [
			'upstream' => self::scan_tree(self::upstream_dir(), false, $warnings),
			'global' => self::scan_tree(self::global_dir(), true, $warnings),
			'domain' => self::valid_uuid($domain_uuid) ? self::scan_tree(self::domains_dir().'/'.$domain_uuid, true, $warnings) : ['templates' => [], 'vendor_files' => []],
		];
		$index = [];
		foreach ($layers as $layer => $tree) {
			foreach ($tree['templates'] as $t => $files) {
				if (!isset($index[$t])) {
					$index[$t] = ['upstream' => false, 'global' => false, 'domain' => false, 'files' => []];
				}
				$index[$t][$layer] = true;
				foreach ($files as $file => $path) {
					if (!isset($index[$t]['files'][$file])) {
						$index[$t]['files'][$file] = ['upstream' => false, 'global' => false, 'domain' => false];
					}
					$index[$t]['files'][$file][$layer] = true;
				}
			}
		}
		ksort($index, SORT_STRING);
		foreach ($index as $t => $row) {
			ksort($index[$t]['files'], SORT_STRING);
		}
		return $index;
	}

	/**
	 * Path of a template file in a layer, or null when a component is invalid.
	 */
	public static function file_path(string $layer, string $domain_uuid, string $vendor, string $model, string $file): ?string {
		if (!self::valid_name($vendor) || !self::valid_name($model) || !self::valid_name($file) || self::ignored($file)) {
			return null;
		}
		$root = ($layer === 'upstream') ? self::upstream_dir() : self::scope_dir($layer, $domain_uuid);
		if ($root === null) {
			return null;
		}
		return $root.'/'.$vendor.'/'.$model.'/'.$file;
	}

	/**
	 * Content of a template file in a layer, or null when absent.
	 */
	public static function read_file(string $layer, string $domain_uuid, string $vendor, string $model, string $file): ?string {
		$path = self::file_path($layer, $domain_uuid, $vendor, $model, $file);
		if ($path === null || is_link($path) || !is_file($path)) {
			return null;
		}
		$content = file_get_contents($path);
		return is_string($content) ? $content : null;
	}

	/**
	 * True when <vendor>/<model> exists upstream or as an override in either scope.
	 */
	public static function template_exists(string $domain_uuid, string $vendor, string $model): bool {
		if (!self::valid_name($vendor) || !self::valid_name($model)) {
			return false;
		}
		$roots = [self::upstream_dir(), self::global_dir()];
		if (self::valid_uuid($domain_uuid)) {
			$roots[] = self::domains_dir().'/'.$domain_uuid;
		}
		foreach ($roots as $root) {
			if (is_dir($root.'/'.$vendor.'/'.$model) && !is_link($root.'/'.$vendor.'/'.$model)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when the vendor has an enabled row in v_device_vendors.
	 */
	public function vendor_row_exists(string $vendor): bool {
		if (!$this->has_database()) {
			return false;
		}
		$sql = "select count(*) from v_device_vendors where name = :name and enabled = 'true' ";
		$parameters['name'] = $vendor;
		return (int) $this->database->select($sql, $parameters, 'column') > 0;
	}

	/**
	 * Validates template content: UTF-8 text, no NUL bytes, size limit.
	 *
	 * @return string|null null when valid, otherwise the language key of the error
	 */
	public static function validate_content(string $content): ?string {
		if (strlen($content) > self::template_max_bytes) {
			return 'error-content_too_large';
		}
		if (strpos($content, "\0") !== false || !mb_check_encoding($content, 'UTF-8')) {
			return 'error-content_invalid';
		}
		return null;
	}

	/**
	 * Writes an override file and rebuilds.
	 *
	 * @param bool $allow_new allow a <vendor>/<model> that does not exist yet (requires a v_device_vendors row)
	 * @return array ['error' => language key|null, 'build' => build result|null]
	 */
	public function save_override(string $scope, string $domain_uuid, string $vendor, string $model, string $file, string $content, bool $allow_new, string $username): array {
		$path = self::file_path($scope, $domain_uuid, $vendor, $model, $file);
		if ($path === null || self::scope_dir($scope, $domain_uuid) === null) {
			return ['error' => 'error-invalid_name', 'build' => null];
		}
		$error = self::validate_content($content);
		if ($error !== null) {
			return ['error' => $error, 'build' => null];
		}
		if (!self::template_exists($scope === 'domain' ? $domain_uuid : '', $vendor, $model)) {
			if (!$allow_new) {
				return ['error' => 'error-template_unknown', 'build' => null];
			}
			if (!$this->vendor_row_exists($vendor)) {
				return ['error' => 'error-vendor_unknown', 'build' => null];
			}
		}
		if (!$this->lock()) {
			return ['error' => 'error-lock', 'build' => null];
		}
		try {
			$old_umask = umask(0027);
			try {
				self::fs_mkdir(dirname($path));
				$written = self::fs_write_atomic($path, $content);
			}
			catch (RuntimeException $e) {
				$written = false;
			}
			umask($old_umask);
			if (!$written) {
				return ['error' => 'error-write', 'build' => null];
			}
			self::log('saved', $scope, $domain_uuid, $vendor.'/'.$model.'/'.$file, hash('sha256', $content), $username);
			$build = $this->build();
			return ['error' => $build['success'] ? null : 'error-build', 'build' => $build];
		}
		finally {
			$this->unlock();
		}
	}

	/**
	 * Deletes an override file (reset to upstream) and rebuilds.
	 */
	public function delete_override(string $scope, string $domain_uuid, string $vendor, string $model, string $file, string $username): array {
		$path = self::file_path($scope, $domain_uuid, $vendor, $model, $file);
		$root = self::scope_dir($scope, $domain_uuid);
		if ($path === null || $root === null) {
			return ['error' => 'error-invalid_name', 'build' => null];
		}
		if (!$this->lock()) {
			return ['error' => 'error-lock', 'build' => null];
		}
		try {
			if (is_link($path) || !is_file($path)) {
				return ['error' => 'error-not_found', 'build' => null];
			}
			if (!@unlink($path)) {
				return ['error' => 'error-write', 'build' => null];
			}
			self::prune_empty_dirs(dirname($path), $root);
			self::log('deleted', $scope, $domain_uuid, $vendor.'/'.$model.'/'.$file, '', $username);
			$build = $this->build();
			return ['error' => $build['success'] ? null : 'error-build', 'build' => $build];
		}
		finally {
			$this->unlock();
		}
	}

	/**
	 * Logs to syslog LOG_LOCAL0 like app/provision.
	 */
	private static function log(string $action, string $scope, string $domain_uuid, string $path, string $sha256, string $username): void {
		$message = 'x_provision_overlay: '.$action.' scope='.$scope;
		if ($scope === 'domain') {
			$message .= ' domain_uuid='.$domain_uuid;
		}
		$message .= ' path='.$path;
		if ($sha256 !== '') {
			$message .= ' sha256='.$sha256;
		}
		$message .= ' user='.preg_replace('/[^A-Za-z0-9@._-]/', '_', $username);
		openlog('FusionPBX', LOG_PID, LOG_LOCAL0);
		syslog(LOG_NOTICE, $message);
		closelog();
	}

	//-------------------------------------------------------------------------
	// line diff (UI)
	//-------------------------------------------------------------------------

	/**
	 * Line diff of two texts.
	 *
	 * @return array|null list of [op, line] with op ' ', '-' or '+'; null when the changed region is too large
	 */
	public static function diff(string $old, string $new, int $max_cells = 1000000): ?array {
		$a = preg_split('/\r\n|\n|\r/', $old);
		$b = preg_split('/\r\n|\n|\r/', $new);

		//common prefix and suffix
		$prefix = 0;
		$a_count = count($a);
		$b_count = count($b);
		while ($prefix < $a_count && $prefix < $b_count && $a[$prefix] === $b[$prefix]) {
			$prefix++;
		}
		$suffix = 0;
		while ($suffix < $a_count - $prefix && $suffix < $b_count - $prefix && $a[$a_count - 1 - $suffix] === $b[$b_count - 1 - $suffix]) {
			$suffix++;
		}
		$a_mid = array_slice($a, $prefix, $a_count - $prefix - $suffix);
		$b_mid = array_slice($b, $prefix, $b_count - $prefix - $suffix);
		$n = count($a_mid);
		$m = count($b_mid);
		if (($n + 1) * ($m + 1) > $max_cells) {
			return null;
		}

		//longest common subsequence on the changed region
		$lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
		for ($i = $n - 1; $i >= 0; $i--) {
			for ($j = $m - 1; $j >= 0; $j--) {
				$lcs[$i][$j] = ($a_mid[$i] === $b_mid[$j]) ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
			}
		}
		$result = [];
		for ($k = 0; $k < $prefix; $k++) {
			$result[] = [' ', $a[$k]];
		}
		$i = 0;
		$j = 0;
		while ($i < $n && $j < $m) {
			if ($a_mid[$i] === $b_mid[$j]) {
				$result[] = [' ', $a_mid[$i]];
				$i++;
				$j++;
			}
			elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
				$result[] = ['-', $a_mid[$i]];
				$i++;
			}
			else {
				$result[] = ['+', $b_mid[$j]];
				$j++;
			}
		}
		for (; $i < $n; $i++) {
			$result[] = ['-', $a_mid[$i]];
		}
		for (; $j < $m; $j++) {
			$result[] = ['+', $b_mid[$j]];
		}
		for ($k = $a_count - $suffix; $k < $a_count; $k++) {
			$result[] = [' ', $a[$k]];
		}
		return $result;
	}

	//-------------------------------------------------------------------------
	// branding assets
	//-------------------------------------------------------------------------

	/**
	 * Asset metadata of a domain (name => meta), only for files that exist.
	 */
	public static function list_assets(string $domain_uuid): array {
		$dir = self::asset_dir($domain_uuid);
		if ($dir === null || !is_dir($dir)) {
			return [];
		}
		$meta = self::read_asset_meta($dir);
		$assets = [];
		foreach ($meta as $name => $row) {
			$path = $dir.'/'.$name;
			if (is_string($name) && self::valid_asset_name($name) && is_array($row) && isset(self::asset_types[$row['mime'] ?? '']) && is_file($path) && !is_link($path)) {
				$assets[$name] = $row;
			}
		}
		ksort($assets, SORT_STRING);
		return $assets;
	}

	/**
	 * Path and recorded content type of one asset, or null.
	 *
	 * @return array|null ['path' => string, 'mime' => string]
	 */
	public static function asset_info(string $domain_uuid, string $name): ?array {
		if (!self::valid_asset_name($name)) {
			return null;
		}
		$dir = self::asset_dir($domain_uuid);
		if ($dir === null) {
			return null;
		}
		$assets = self::list_assets($domain_uuid);
		if (!isset($assets[$name])) {
			return null;
		}
		$real_dir = realpath($dir);
		$real_path = realpath($dir.'/'.$name);
		if ($real_dir === false || $real_path === false || $real_path !== $real_dir.'/'.$name) {
			return null;
		}
		return ['path' => $real_path, 'mime' => $assets[$name]['mime']];
	}

	private static function read_asset_meta(string $dir): array {
		$file = $dir.'/.meta.json';
		if (!is_file($file)) {
			return [];
		}
		$data = json_decode((string) file_get_contents($file), true);
		return is_array($data) ? $data : [];
	}

	/**
	 * Phone-facing url of an asset; {$domain_name} is substituted at render time (provision.php).
	 */
	public static function asset_url(string $name): string {
		return 'http://{$domain_name}/provision-assets/'.$name;
	}

	/**
	 * Builds a stored name from a requested name and the sniffed extension. Dots in the stem become
	 * underscores, so "a.php.png" is stored as "a_php.png".
	 */
	public static function asset_name(string $requested, string $extension): string {
		$stem = strtolower(trim($requested));
		$stem = basename(str_replace('\\', '/', $stem));
		$dot = strrpos($stem, '.');
		if ($dot !== false && $dot > 0) {
			$stem = substr($stem, 0, $dot);
		}
		$stem = preg_replace('/[^a-z0-9_-]+/', '_', $stem);
		$stem = ltrim($stem, '_-');
		$stem = substr($stem, 0, 59);
		if ($stem === '') {
			$stem = 'asset';
		}
		return $stem.'.'.$extension;
	}

	/**
	 * Validates, re-encodes and stores an uploaded image for a domain.
	 *
	 * @return array ['error' => language key|null, 'name' => stored name]
	 */
	public function save_asset(string $domain_uuid, array $upload, string $requested_name, int $max_bytes, string $username): array {
		$dir = self::asset_dir($domain_uuid);
		if ($dir === null) {
			return ['error' => 'error-asset_store', 'name' => ''];
		}
		if ($max_bytes < 1) {
			$max_bytes = self::asset_default_max_bytes;
		}

		//GD is required to re-encode
		if (!function_exists('imagecreatefromstring') || !function_exists('getimagesize')) {
			return ['error' => 'error-asset_no_gd', 'name' => ''];
		}

		//upload checks
		if (!isset($upload['error']) || is_array($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
			if (isset($upload['error']) && !is_array($upload['error']) && ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE)) {
				return ['error' => 'error-asset_too_large', 'name' => ''];
			}
			return ['error' => 'error-asset_upload', 'name' => ''];
		}
		if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
			return ['error' => 'error-asset_upload', 'name' => ''];
		}
		$size = filesize($upload['tmp_name']);
		if ($size === false || $size < 1) {
			return ['error' => 'error-asset_upload', 'name' => ''];
		}
		if ($size > $max_bytes) {
			return ['error' => 'error-asset_too_large', 'name' => ''];
		}

		if (!$this->lock()) {
			return ['error' => 'error-lock', 'name' => ''];
		}
		$old_umask = umask(0027);
		$temp = '';
		$encoded = '';
		try {
			try {
				self::fs_mkdir($dir);
			}
			catch (RuntimeException $e) {
				return ['error' => 'error-asset_store', 'name' => ''];
			}

			//move the upload next to its destination first, then validate it there
			$temp = $dir.'/.upload-'.bin2hex(random_bytes(8));
			if (!@move_uploaded_file($upload['tmp_name'], $temp)) {
				return ['error' => 'error-asset_store', 'name' => ''];
			}
			@chmod($temp, 0640);

			//detect the type from the content, never from the client or the extension
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$mime = $finfo->file($temp);
			if ($mime === 'image/x-ms-bmp') {
				$mime = 'image/bmp';
			}
			if (!is_string($mime) || !isset(self::asset_types[$mime])) {
				return ['error' => 'error-asset_type', 'name' => ''];
			}
			$info = @getimagesize($temp);
			$image_types = ['image/png' => IMAGETYPE_PNG, 'image/jpeg' => IMAGETYPE_JPEG, 'image/bmp' => IMAGETYPE_BMP, 'image/gif' => IMAGETYPE_GIF];
			if (!is_array($info) || $info[2] !== $image_types[$mime]) {
				return ['error' => 'error-asset_type', 'name' => ''];
			}
			$width = (int) $info[0];
			$height = (int) $info[1];
			if ($width < 1 || $height < 1 || $width > self::asset_max_dimension || $height > self::asset_max_dimension) {
				return ['error' => 'error-asset_dimensions', 'name' => ''];
			}

			//the stored name and the file count limit
			$extension = self::asset_types[$mime];
			$name = self::asset_name($requested_name, $extension);
			if (!self::valid_asset_name($name)) {
				return ['error' => 'error-asset_name', 'name' => ''];
			}
			$assets = self::list_assets($domain_uuid);
			if (!isset($assets[$name]) && count($assets) >= self::asset_max_files) {
				return ['error' => 'error-asset_count', 'name' => ''];
			}

			//re-encode with GD in the same format (drops metadata and trailing payloads)
			$image = false;
			switch ($mime) {
				case 'image/png': $image = @imagecreatefrompng($temp); break;
				case 'image/jpeg': $image = @imagecreatefromjpeg($temp); break;
				case 'image/gif': $image = @imagecreatefromgif($temp); break;
				case 'image/bmp': $image = function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($temp) : false; break;
			}
			if (!$image) {
				return ['error' => 'error-asset_process', 'name' => ''];
			}
			$encoded = $dir.'/.encoded-'.bin2hex(random_bytes(8));
			$written = false;
			switch ($mime) {
				case 'image/png':
					imagesavealpha($image, true);
					$written = imagepng($image, $encoded, 6);
					break;
				case 'image/jpeg':
					$written = imagejpeg($image, $encoded, 90);
					break;
				case 'image/gif':
					$written = imagegif($image, $encoded);
					break;
				case 'image/bmp':
					//uncompressed: phones do not reliably decode RLE bitmaps
					$written = function_exists('imagebmp') ? imagebmp($image, $encoded, false) : false;
					break;
			}
			imagedestroy($image);
			if (!$written || !is_file($encoded)) {
				return ['error' => 'error-asset_process', 'name' => ''];
			}
			@chmod($encoded, 0640);
			if (!@rename($encoded, $dir.'/'.$name)) {
				return ['error' => 'error-asset_store', 'name' => ''];
			}
			$encoded = '';

			//record the sniffed type; assets.php serves this type, never one derived from the name
			$meta = self::read_asset_meta($dir);
			$meta[$name] = [
				'mime' => $mime,
				'size' => filesize($dir.'/'.$name),
				'width' => $width,
				'height' => $height,
				'sha256' => hash_file('sha256', $dir.'/'.$name),
				'uploaded_by' => $username,
				'uploaded_at' => gmdate('c'),
			];
			if (!self::fs_write_atomic($dir.'/.meta.json', (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
				return ['error' => 'error-asset_store', 'name' => ''];
			}
			self::log('asset_saved', 'domain', $domain_uuid, 'assets/'.$name, $meta[$name]['sha256'], $username);
			return ['error' => null, 'name' => $name];
		}
		finally {
			if ($temp !== '' && file_exists($temp)) {
				@unlink($temp);
			}
			if ($encoded !== '' && file_exists($encoded)) {
				@unlink($encoded);
			}
			umask($old_umask);
			$this->unlock();
		}
	}

	/**
	 * Deletes an asset of a domain.
	 *
	 * @return string|null null on success, otherwise the language key of the error
	 */
	public function delete_asset(string $domain_uuid, string $name, string $username): ?string {
		$dir = self::asset_dir($domain_uuid);
		if ($dir === null || !self::valid_asset_name($name)) {
			return 'error-asset_name';
		}
		if (!$this->lock()) {
			return 'error-lock';
		}
		try {
			$meta = self::read_asset_meta($dir);
			$path = $dir.'/'.$name;
			if (!isset($meta[$name]) && !is_file($path)) {
				return 'error-not_found';
			}
			if ((is_file($path) || is_link($path)) && !@unlink($path)) {
				return 'error-asset_store';
			}
			unset($meta[$name]);
			if (!self::fs_write_atomic($dir.'/.meta.json', (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
				return 'error-asset_store';
			}
			self::log('asset_deleted', 'domain', $domain_uuid, 'assets/'.$name, '', $username);
			return null;
		}
		finally {
			$this->unlock();
		}
	}

	//-------------------------------------------------------------------------
	// vendor settings (apply an asset url per domain)
	//-------------------------------------------------------------------------

	/**
	 * Vendor image url settings that exist as default settings, with the domain's current value.
	 *
	 * @return array subcategory => ['description' => string, 'domain_value' => string|null]
	 */
	public function vendor_settings(string $domain_uuid): array {
		if (!$this->has_database() || !self::valid_uuid($domain_uuid)) {
			return [];
		}
		$sql = "select default_setting_subcategory, default_setting_description from v_default_settings ";
		$sql .= "where default_setting_category = 'provision' ";
		$sql .= "order by default_setting_subcategory asc ";
		$rows = $this->database->select($sql, null, 'all');
		$settings = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (self::valid_vendor_setting((string) $row['default_setting_subcategory'])) {
					$settings[$row['default_setting_subcategory']] = ['description' => (string) $row['default_setting_description'], 'domain_value' => null];
				}
			}
		}
		if (empty($settings)) {
			return [];
		}
		$sql = "select domain_setting_subcategory, domain_setting_value from v_domain_settings ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and domain_setting_category = 'provision' ";
		$sql .= "and domain_setting_enabled = true ";
		$parameters['domain_uuid'] = $domain_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if (isset($settings[$row['domain_setting_subcategory']])) {
					$settings[$row['domain_setting_subcategory']]['domain_value'] = (string) $row['domain_setting_value'];
				}
			}
		}
		return $settings;
	}

	/**
	 * Writes the url of an asset into a vendor image setting of the domain.
	 *
	 * @return string|null null on success, otherwise the language key of the error
	 */
	public function apply_vendor_setting(string $domain_uuid, string $subcategory, string $asset_name): ?string {
		if (!$this->has_database() || !self::valid_uuid($domain_uuid)) {
			return 'error-setting';
		}
		$available = $this->vendor_settings($domain_uuid);
		if (!isset($available[$subcategory])) {
			return 'error-setting_unknown';
		}
		if (self::asset_info($domain_uuid, $asset_name) === null) {
			return 'error-not_found';
		}

		//existing domain setting rows for this subcategory
		$sql = "select domain_setting_uuid from v_domain_settings ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and domain_setting_category = 'provision' ";
		$sql .= "and domain_setting_subcategory = :subcategory ";
		$sql .= "order by domain_setting_uuid asc ";
		$parameters['domain_uuid'] = $domain_uuid;
		$parameters['subcategory'] = $subcategory;
		$rows = $this->database->select($sql, $parameters, 'all');
		unset($sql, $parameters);

		if (is_array($rows) && count($rows) > 0) {
			$array['domain_settings'][0]['domain_setting_uuid'] = $rows[0]['domain_setting_uuid'];
			$permission = 'domain_setting_edit';
		}
		else {
			$array['domain_settings'][0]['domain_setting_uuid'] = uuid();
			$permission = 'domain_setting_add';
		}
		$array['domain_settings'][0]['domain_uuid'] = $domain_uuid;
		$array['domain_settings'][0]['domain_setting_category'] = 'provision';
		$array['domain_settings'][0]['domain_setting_subcategory'] = $subcategory;
		$array['domain_settings'][0]['domain_setting_name'] = 'text';
		$array['domain_settings'][0]['domain_setting_value'] = self::asset_url($asset_name);
		$array['domain_settings'][0]['domain_setting_enabled'] = true;
		$array['domain_settings'][0]['domain_setting_description'] = 'Provision branding asset (managed by Provision Templates)';

		//save with a temporary permission, as x_fax_setup does
		$p = permissions::new();
		$p->add($permission, 'temp');
		$saved = $this->database->save($array);
		unset($array);
		$p->delete($permission, 'temp');

		//clear the settings cache, as domain_setting_edit.php does
		settings::clear_cache();

		return ($saved === false) ? 'error-setting' : null;
	}

}

?>
