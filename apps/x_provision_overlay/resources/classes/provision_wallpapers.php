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
	Phone wallpapers (docs/design/provision_wallpapers.md).

	One image per size group, per domain, with a system default per group. Images are fitted to the
	group's size and stored next to the domain's branding assets:

		domain   /var/lib/fusionpbx/provision_overlay/assets/<domain_uuid>/wp-<group>-<sha8>.<ext>
		global   /var/lib/fusionpbx/provision_overlay/assets/global/wp-<group>-<sha8>.<ext>

	each directory with its own .wallpapers.json. Phones are linked to their group's image with device
	settings (v_device_settings), written for devices whose template is in the catalog below; the rows
	written are recorded in v_x_provision_overlay_wallpaper_links. Values set elsewhere (domain setting,
	device profile, a device setting of the admin) win; see analyze().
*/

//define the provision_wallpapers class
class provision_wallpapers {

	//limits
	const grace_seconds = 86400;
	const domain_grace_seconds = 604800;
	const temp_grace_seconds = 3600;
	const sync_all_lock_timeout = 5;
	const jpeg_quality_start = 90;
	const jpeg_quality_min = 40;
	const jpeg_quality_step = 5;
	const png_colors = [256, 128, 64];
	const template_max_bytes = 2097152;

	//files
	const meta_file = '.wallpapers.json';
	const global_scope = 'global';
	const request_file = '.wallpapers-sync.request';
	const template_cache_file = '.wallpapers-templates.json';
	const missing_domains_file = '.wallpapers-missing-domains.json';
	const file_pattern = provision_overlay::wallpaper_file_pattern;

	//device settings written by the sync
	const setting_category = 'provision';
	const setting_description = 'Phone wallpaper (managed by Provision Templates > Wallpapers; disable this row to opt the device out)';

	//size groups: width, height
	const groups = [
		'320x240' => [320, 240],
		'480x272' => [480, 272],
		'800x480' => [800, 480],
		'1024x600' => [1024, 600],
		'960x480' => [960, 480],
		'1280x720' => [1280, 720],
		'1280x800' => [1280, 800],
		'240x320' => [240, 320],
	];

	//mime and extension of the formats generated
	const formats = [
		'jpg' => 'image/jpeg',
		'png' => 'image/png',
	];

	/*
		Catalog, verified against the upstream templates at the commit pinned in config.sh (58ca4e9).
		template => group, format generated, size limit in bytes, and the template's variable set:
			vars   written as device settings: url (image url), filename (file name of the url)
			reads  also read by the template (fallbacks); checked for values set elsewhere, never written
		Upstream mix-ups are kept: t73w/t74w read yealink_t54w_*, t58a t56a, cp925 cp920, ax86r ax83h,
		D865 d862; ax83h/ax86r test yealink_t44w_wallpaper_filename but print the ax83h one; J179 tests
		avaya_j179_wallpaper.
		models models of a shared template that take another group (matched against the device model or the
		       user agent the phone reported); each device gets its own value of the same variables
	*/
	const catalog = [
		//320x240
		'yealink/t32g' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['yealink_t32g_wallpaper' => 'url', 'yealink_t32g_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t33g' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['yealink_t33g_wallpaper' => 'url', 'yealink_t33g_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t34w' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['yealink_t34w_wallpaper' => 'url', 'yealink_t34w_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t44w' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['yealink_t44w_wallpaper' => 'url', 'yealink_t44w_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t52s' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['yealink_t52s_wallpaper' => 'url', 'yealink_t52s_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t73u' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['yealink_t73u_wallpaper' => 'url', 'yealink_t73u_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t73w' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['yealink_t54w_wallpaper' => 'url', 'yealink_t54w_wallpaper_filename' => 'filename'], 'reads' => []],
		'snom/D735' => ['group' => '320x240', 'format' => 'png', 'limit' => 512000, 'vars' => ['snom_wallpaper_url_d735' => 'url'], 'reads' => ['snom_wallpaper_url']],
		'snom/D765' => ['group' => '320x240', 'format' => 'png', 'limit' => 512000, 'vars' => ['snom_wallpaper_url_d765' => 'url'], 'reads' => ['snom_wallpaper_url']],
		'grandstream/gxp2130' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['grandstream_wallpaper_url' => 'url'], 'reads' => []],
		'grandstream/gxp2135' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['grandstream_wallpaper_url' => 'url'], 'reads' => []],
		'grandstream/grp261x' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['grandstream_wallpaper_url' => 'url'], 'reads' => [], 'models' => ['GRP2615' => '480x272']],
		'avaya/J139' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 262144, 'vars' => ['avaya_j139_wallpaper_url' => 'url', 'avaya_j139_wallpaper_filename' => 'filename'], 'reads' => []],
		'avaya/J179' => ['group' => '320x240', 'format' => 'jpg', 'limit' => 262144, 'vars' => ['avaya_j179_wallpaper' => 'url', 'avaya_j179_wallpaper_url' => 'url', 'avaya_j179_wallpaper_filename' => 'filename'], 'reads' => []],
		//480x272
		'yealink/t29g' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t29g_wallpaper' => 'url', 'yealink_t29g_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t38g' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t38g_wallpaper' => 'url', 'yealink_t38g_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t46g' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t46g_wallpaper' => 'url', 'yealink_t46g_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t46s' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t46s_wallpaper' => 'url', 'yealink_t46s_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t46u' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t46u_wallpaper' => 'url', 'yealink_t46u_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t54s' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t54s_wallpaper' => 'url', 'yealink_t54s_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t54w' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t54w_wallpaper' => 'url', 'yealink_t54w_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t74u' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t74u_wallpaper' => 'url', 'yealink_t74u_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t74w' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t54w_wallpaper' => 'url', 'yealink_t54w_wallpaper_filename' => 'filename'], 'reads' => []],
		'snom/D375' => ['group' => '480x272', 'format' => 'png', 'limit' => 512000, 'vars' => ['snom_wallpaper_url' => 'url'], 'reads' => []],
		'snom/D385' => ['group' => '480x272', 'format' => 'png', 'limit' => 512000, 'vars' => ['snom_wallpaper_url' => 'url'], 'reads' => []],
		'snom/D785' => ['group' => '480x272', 'format' => 'png', 'limit' => 512000, 'vars' => ['snom_wallpaper_url_d785' => 'url'], 'reads' => ['snom_wallpaper_url']],
		'grandstream/gxp2140' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['grandstream_wallpaper_url' => 'url'], 'reads' => []],
		'grandstream/gxp2160' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['grandstream_wallpaper_url' => 'url'], 'reads' => []],
		'grandstream/gxp2170' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 512000, 'vars' => ['grandstream_wallpaper_url' => 'url'], 'reads' => []],
		'polycom/vvx450' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['polycom_background_url' => 'url'], 'reads' => []],
		'poly/poly-vvx-450' => ['group' => '480x272', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['poly_obi_logo_450' => 'url'], 'reads' => []],
		//800x480
		'yealink/t48g' => ['group' => '800x480', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t48g_wallpaper' => 'url', 'yealink_t48g_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t48s' => ['group' => '800x480', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t48s_wallpaper' => 'url', 'yealink_t48s_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t48u' => ['group' => '800x480', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t48u_wallpaper' => 'url', 'yealink_t48u_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t57w' => ['group' => '800x480', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t57w_wallpaper' => 'url', 'yealink_t57w_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t77u' => ['group' => '800x480', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t77u_wallpaper' => 'url', 'yealink_t77u_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t87w' => ['group' => '800x480', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t87w_wallpaper' => 'url', 'yealink_t87w_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/vp530' => ['group' => '800x480', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_vp530_wallpaper' => 'url', 'yealink_vp530_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/cp925' => ['group' => '800x480', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_cp920_wallpaper' => 'url', 'yealink_cp920_wallpaper_filename' => 'filename'], 'reads' => []],
		'avaya/J189' => ['group' => '800x480', 'format' => 'jpg', 'limit' => 262144, 'vars' => ['avaya_j189_wallpaper_url' => 'url', 'avaya_j189_wallpaper_filename' => 'filename'], 'reads' => []],
		//1024x600
		'yealink/t56a' => ['group' => '1024x600', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t56a_wallpaper' => 'url', 'yealink_t56a_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t58a' => ['group' => '1024x600', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t56a_wallpaper' => 'url', 'yealink_t56a_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t58v' => ['group' => '1024x600', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t58v_wallpaper' => 'url', 'yealink_t58v_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t58w' => ['group' => '1024x600', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t58w_wallpaper' => 'url', 'yealink_t58w_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t88v' => ['group' => '1024x600', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t88v_wallpaper' => 'url', 'yealink_t88v_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/t88w' => ['group' => '1024x600', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t88w_wallpaper' => 'url', 'yealink_t88w_wallpaper_filename' => 'filename'], 'reads' => []],
		//960x480
		'yealink/t85w' => ['group' => '960x480', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t85w_wallpaper' => 'url', 'yealink_t85w_wallpaper_filename' => 'filename'], 'reads' => []],
		//1280x720
		'yealink/cp960' => ['group' => '1280x720', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_cp960_wallpaper' => 'url', 'yealink_cp960_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/cp965' => ['group' => '1280x720', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_cp965_wallpaper' => 'url', 'yealink_cp965_wallpaper_filename' => 'filename'], 'reads' => []],
		'snom/D862' => ['group' => '1280x720', 'format' => 'png', 'limit' => 1048576, 'vars' => ['snom_wallpaper_url_d862' => 'url'], 'reads' => ['snom_wallpaper_url']],
		'snom/D865' => ['group' => '1280x720', 'format' => 'png', 'limit' => 1048576, 'vars' => ['snom_wallpaper_url_d862' => 'url'], 'reads' => ['snom_wallpaper_url']],
		//1280x800
		'yealink/t49g' => ['group' => '1280x800', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_t49g_wallpaper' => 'url', 'yealink_t49g_wallpaper_filename' => 'filename'], 'reads' => []],
		'grandstream/gxv3480' => ['group' => '1280x800', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['grandstream_wallpaper_url' => 'url'], 'reads' => []],
		//240x320
		'yealink/ax83h' => ['group' => '240x320', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_ax83h_wallpaper' => 'url', 'yealink_ax83h_wallpaper_filename' => 'filename', 'yealink_t44w_wallpaper_filename' => 'filename'], 'reads' => []],
		'yealink/ax86r' => ['group' => '240x320', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['yealink_ax83h_wallpaper' => 'url', 'yealink_ax83h_wallpaper_filename' => 'filename', 'yealink_t44w_wallpaper_filename' => 'filename'], 'reads' => []],
		'grandstream/wp820' => ['group' => '240x320', 'format' => 'jpg', 'limit' => 1048576, 'vars' => ['grandstream_wallpaper_url' => 'url'], 'reads' => []],
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
	 * Initializes the object. The database is required for the sync, not for the image functions.
	 */
	public function __construct(array $setting_array = []) {
		$this->database = $setting_array['database'] ?? null;
		$this->overlay = $setting_array['overlay'] ?? new provision_overlay(['database' => $this->database]);
	}

	//-------------------------------------------------------------------------
	// catalog
	//-------------------------------------------------------------------------

	/**
	 * The group of a device: its template's, unless the template is shared and the device's model takes another.
	 * The model comes from the device model or, as phones report it, the provisioned user agent.
	 */
	public static function device_group(array $entry, array $device): string {
		$haystack = ($device['device_model'] ?? '').' '.($device['device_provisioned_agent'] ?? '');
		foreach ($entry['models'] ?? [] as $model => $group) {
			if (preg_match('/\b'.preg_quote($model, '/').'\b/i', $haystack) === 1) {
				return $group;
			}
		}
		return $entry['group'];
	}

	/**
	 * Every variable of a template's set (written and read).
	 */
	public static function set_variables(string $template): array {
		if (!isset(self::catalog[$template])) {
			return [];
		}
		$entry = self::catalog[$template];
		return array_values(array_unique(array_merge(array_keys($entry['vars']), $entry['reads'])));
	}

	/**
	 * Every variable of every catalog template.
	 */
	public static function all_variables(): array {
		$variables = [];
		foreach (array_keys(self::catalog) as $template) {
			foreach (self::set_variables($template) as $variable) {
				$variables[$variable] = true;
			}
		}
		ksort($variables, SORT_STRING);
		return array_keys($variables);
	}

	/**
	 * Groups with their size, member templates and the formats generated (format => strictest member limit).
	 *
	 * @return array group => ['width' => int, 'height' => int, 'members' => [label => entry + template], 'formats' => [ext => limit]]
	 */
	public static function group_specs(): array {
		$specs = [];
		foreach (self::groups as $group => $size) {
			$specs[$group] = ['width' => $size[0], 'height' => $size[1], 'members' => [], 'formats' => []];
		}
		foreach (self::catalog as $template => $entry) {
			$group = $entry['group'];
			$specs[$group]['members'][$template] = $entry + ['template' => $template];
			foreach ($entry['models'] ?? [] as $model => $model_group) {
				$specs[$model_group]['members'][$template.' ('.$model.')'] = $entry + ['template' => $template];
			}
			$format = $entry['format'];
			$limit = (int) $entry['limit'];
			foreach (array_unique(array_merge([$group], array_values($entry['models'] ?? []))) as $member_group) {
				if (!isset($specs[$member_group]['formats'][$format]) || $limit < $specs[$member_group]['formats'][$format]) {
					$specs[$member_group]['formats'][$format] = $limit;
				}
			}
		}
		foreach ($specs as $group => $spec) {
			ksort($specs[$group]['formats'], SORT_STRING);
		}
		return $specs;
	}

	//-------------------------------------------------------------------------
	// paths and metadata
	//-------------------------------------------------------------------------

	/**
	 * Image directory of a scope: 'global' or a domain uuid. Returns null for anything else.
	 */
	public static function scope_dir(string $scope): ?string {
		if ($scope === self::global_scope) {
			return provision_overlay::assets_root().'/'.self::global_scope;
		}
		return provision_overlay::asset_dir($scope);
	}

	public static function request_path(): string {
		return provision_overlay::state_root.'/'.self::request_file;
	}

	public static function valid_file_name(string $name): bool {
		return preg_match(self::file_pattern, $name) === 1;
	}

	/**
	 * Decoded .wallpapers.json of a scope: ['groups' => [group => image], 'retired' => [file => time],
	 * 'unrecorded' => [file => first seen]]. Empty when the scope has no metadata file; null when the file
	 * exists but cannot be used (symlink, unreadable, not valid json), so callers can stop instead of
	 * treating every image as gone.
	 */
	public static function read_meta(string $scope): ?array {
		$meta = ['groups' => [], 'retired' => [], 'unrecorded' => []];
		$dir = self::scope_dir($scope);
		if ($dir === null) {
			return null;
		}
		$file = $dir.'/'.self::meta_file;
		if (is_link($file)) {
			return null;
		}
		if (!file_exists($file)) {
			return $meta;
		}
		$content = is_file($file) ? @file_get_contents($file) : false;
		if (!is_string($content)) {
			return null;
		}
		$data = json_decode($content, true);
		if (!is_array($data)) {
			return null;
		}
		foreach (($data['groups'] ?? []) as $group => $image) {
			if (is_string($group) && isset(self::groups[$group]) && is_array($image) && is_array($image['files'] ?? null)) {
				$meta['groups'][$group] = $image;
			}
		}
		foreach (['retired', 'unrecorded'] as $list) {
			foreach (($data[$list] ?? []) as $name => $time) {
				if (is_string($name) && self::valid_file_name($name)) {
					$meta[$list][$name] = (string) $time;
				}
			}
		}
		return $meta;
	}

	/**
	 * Metadata that must be usable: throws when read_meta() returns null.
	 */
	private static function require_meta(string $scope): array {
		$meta = self::read_meta($scope);
		if ($meta === null) {
			throw new RuntimeException('wallpaper metadata of '.$scope.' cannot be read ('.self::scope_dir($scope).'/'.self::meta_file.')');
		}
		return $meta;
	}

	private static function write_meta(string $scope, array $meta): bool {
		$dir = self::scope_dir($scope);
		if ($dir === null) {
			return false;
		}
		ksort($meta['groups'], SORT_STRING);
		ksort($meta['retired'], SORT_STRING);
		$meta['unrecorded'] = $meta['unrecorded'] ?? [];
		ksort($meta['unrecorded'], SORT_STRING);
		$json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		return $json !== false && self::write_atomic($dir.'/'.self::meta_file, $json."\n");
	}

	/**
	 * Writes a file atomically (temp file in the same directory, then rename), mode 0640.
	 */
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

	private static function make_dir(string $path): bool {
		if (is_dir($path) && !is_link($path)) {
			return true;
		}
		if (!@mkdir($path, 0750, true) && !is_dir($path)) {
			return false;
		}
		@chmod($path, 0750);
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
		$entries = @scandir($path);
		foreach (is_array($entries) ? $entries : [] as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				self::remove_tree($path.'/'.$entry);
			}
		}
		return @rmdir($path);
	}

	/**
	 * Image of a group in one scope's metadata, only when every file it lists exists.
	 *
	 * @return array|null ['files' => [ext => name], 'image' => meta row]
	 */
	private static function stored_image(string $scope, array $meta, string $group): ?array {
		$image = $meta['groups'][$group] ?? null;
		$dir = self::scope_dir($scope);
		if (!is_array($image) || $dir === null) {
			return null;
		}
		$files = [];
		foreach ($image['files'] as $ext => $file) {
			$name = (string) ($file['name'] ?? '');
			if (!isset(self::formats[$ext]) || !self::valid_file_name($name) || !is_file($dir.'/'.$name) || is_link($dir.'/'.$name)) {
				return null;
			}
			$files[$ext] = $name;
		}
		return empty($files) ? null : ['files' => $files, 'image' => $image];
	}

	/**
	 * The image a domain's phones of a group get: the domain's, else the system default, else none.
	 *
	 * @return array|null ['scope' => 'domain'|'global', 'files' => [ext => name], 'image' => meta row]
	 */
	public static function effective_image(string $domain_uuid, string $group, ?array $domain_meta = null, ?array $global_meta = null): ?array {
		$domain_meta = $domain_meta ?? self::read_meta($domain_uuid) ?? ['groups' => []];
		$image = self::stored_image($domain_uuid, $domain_meta, $group);
		if ($image !== null) {
			return ['scope' => 'domain'] + $image;
		}
		$global_meta = $global_meta ?? self::read_meta(self::global_scope) ?? ['groups' => []];
		$image = self::stored_image(self::global_scope, $global_meta, $group);
		if ($image !== null) {
			return ['scope' => 'global'] + $image;
		}
		return null;
	}

	/**
	 * Path and content type of a wallpaper file for assets.php: recorded (current or within the grace period)
	 * in the domain's .wallpapers.json, else in the global one. Realpath checked.
	 *
	 * @return array|null ['path' => string, 'mime' => string]
	 */
	public static function file_info(string $domain_uuid, string $name): ?array {
		if (!self::valid_file_name($name) || !provision_overlay::valid_uuid($domain_uuid)) {
			return null;
		}
		foreach ([$domain_uuid, self::global_scope] as $scope) {
			$meta = self::read_meta($scope);
			if ($meta === null) {
				continue;
			}
			$recorded = isset($meta['retired'][$name]);
			foreach ($meta['groups'] as $image) {
				foreach ($image['files'] as $file) {
					if (($file['name'] ?? '') === $name) {
						$recorded = true;
					}
				}
			}
			if (!$recorded) {
				continue;
			}
			$dir = self::scope_dir($scope);
			$real_dir = realpath($dir);
			$real_path = realpath($dir.'/'.$name);
			if ($real_dir === false || $real_path === false || $real_path !== $real_dir.'/'.$name || !is_file($real_path)) {
				continue;
			}
			$ext = substr($name, strrpos($name, '.') + 1);
			return ['path' => $real_path, 'mime' => self::formats[$ext]];
		}
		return null;
	}

	//-------------------------------------------------------------------------
	// images
	//-------------------------------------------------------------------------

	/**
	 * Bytes from php's memory_limit; 0 when unlimited.
	 */
	public static function memory_limit(): int {
		$value = trim((string) ini_get('memory_limit'));
		if ($value === '' || $value === '-1') {
			return 0;
		}
		$number = (int) $value;
		switch (strtolower(substr($value, -1))) {
			case 'g': $number *= 1024;
			case 'm': $number *= 1024;
			case 'k': $number *= 1024;
		}
		return $number;
	}

	/**
	 * Estimated memory to decode a source and fit it (GD keeps 4 bytes per pixel, plus working copies).
	 */
	public static function memory_needed(int $width, int $height, bool $rotated = false): int {
		$target = 1280 * 800 * 4;
		//an exif orientation other than 1 may need a second, rotated copy of the source
		$copies = $rotated ? 2 : 1;
		return (int) ($width * $height * 4 * 1.25 * $copies) + $target * 4 + 8388608;
	}

	/**
	 * Largest square source (pixels per side) the current memory_limit allows, or 0 when unlimited. Assumes
	 * the worst case (an exif orientation that needs a rotated copy).
	 */
	public static function max_source_side(): int {
		$limit = self::memory_limit();
		if ($limit === 0) {
			return 0;
		}
		$available = $limit - memory_get_usage() - 1280 * 800 * 4 * 4 - 8388608;
		if ($available <= 0) {
			return 1;
		}
		return min(provision_overlay::asset_max_dimension, (int) floor(sqrt($available / 10)));
	}

	/**
	 * EXIF orientation of a JPEG (1 when unknown).
	 */
	private static function exif_orientation(string $path): int {
		if (!function_exists('exif_read_data')) {
			return 1;
		}
		$exif = @exif_read_data($path, 'IFD0');
		$orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
		return ($orientation >= 1 && $orientation <= 8) ? $orientation : 1;
	}

	/**
	 * Applies an EXIF orientation so the image is upright.
	 */
	private static function orient($image, int $orientation) {
		switch ($orientation) {
			case 2:
				imageflip($image, IMG_FLIP_HORIZONTAL);
				return $image;
			case 3:
				return imagerotate($image, 180, 0);
			case 4:
				imageflip($image, IMG_FLIP_VERTICAL);
				return $image;
			case 5:
				imageflip($image, IMG_FLIP_VERTICAL);
				return imagerotate($image, -90, 0);
			case 6:
				return imagerotate($image, -90, 0);
			case 7:
				imageflip($image, IMG_FLIP_VERTICAL);
				return imagerotate($image, 90, 0);
			case 8:
				return imagerotate($image, 90, 0);
		}
		return $image;
	}

	/**
	 * Scales an image to cover width x height and crops the center, flattened on white.
	 */
	private static function fit($source, int $width, int $height) {
		$source_width = imagesx($source);
		$source_height = imagesy($source);
		$scale = max($width / $source_width, $height / $source_height);
		$crop_width = max(1, min($source_width, (int) round($width / $scale)));
		$crop_height = max(1, min($source_height, (int) round($height / $scale)));
		$x = (int) floor(($source_width - $crop_width) / 2);
		$y = (int) floor(($source_height - $crop_height) / 2);
		$target = imagecreatetruecolor($width, $height);
		imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
		imagealphablending($target, true);
		imagecopyresampled($target, $source, 0, 0, $x, $y, $width, $height, $crop_width, $crop_height);
		return $target;
	}

	/**
	 * Baseline JPEG within a size limit, quality stepped down from 90 to 40.
	 *
	 * @return array|null ['data' => string, 'quality' => int]
	 */
	private static function encode_jpeg($image, int $limit): ?array {
		imageinterlace($image, false);
		for ($quality = self::jpeg_quality_start; $quality >= self::jpeg_quality_min; $quality -= self::jpeg_quality_step) {
			ob_start();
			$written = imagejpeg($image, null, $quality);
			$data = (string) ob_get_clean();
			if ($written && $data !== '' && strlen($data) <= $limit) {
				return ['data' => $data, 'quality' => $quality];
			}
		}
		return null;
	}

	/**
	 * PNG within a size limit: truecolor, else palettized with fewer colors.
	 *
	 * @return array|null ['data' => string, 'colors' => int] (0 colors = truecolor)
	 */
	private static function encode_png($image, int $limit): ?array {
		imagesavealpha($image, false);
		imageinterlace($image, false);
		ob_start();
		$written = imagepng($image, null, 9);
		$data = (string) ob_get_clean();
		if ($written && $data !== '' && strlen($data) <= $limit) {
			return ['data' => $data, 'colors' => 0];
		}
		$width = imagesx($image);
		$height = imagesy($image);
		foreach (self::png_colors as $colors) {
			$copy = imagecreatetruecolor($width, $height);
			imagecopy($copy, $image, 0, 0, 0, 0, $width, $height);
			imagetruecolortopalette($copy, true, $colors);
			ob_start();
			$written = imagepng($copy, null, 9);
			$data = (string) ob_get_clean();
			imagedestroy($copy);
			if ($written && $data !== '' && strlen($data) <= $limit) {
				return ['data' => $data, 'colors' => $colors];
			}
		}
		return null;
	}

	//-------------------------------------------------------------------------
	// store / remove
	//-------------------------------------------------------------------------

	/**
	 * Validates an uploaded image and stores it for a group of a scope ('global' or a domain uuid).
	 *
	 * @return array ['error' => language key|null, 'warnings' => [language key], 'sync' => sync result|null]
	 */
	public function store_upload(string $scope, string $group, array $upload, int $max_bytes, string $username): array {
		$result = ['error' => null, 'warnings' => [], 'sync' => null];
		$dir = self::scope_dir($scope);
		if ($dir === null || !isset(self::groups[$group])) {
			$result['error'] = 'error-wallpaper_group';
			return $result;
		}
		if ($max_bytes < 1) {
			$max_bytes = provision_overlay::asset_default_max_bytes;
		}
		if (!isset($upload['error']) || is_array($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
			$too_large = isset($upload['error']) && !is_array($upload['error']) && ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE);
			$result['error'] = $too_large ? 'error-asset_too_large' : 'error-asset_upload';
			return $result;
		}
		if (empty($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
			$result['error'] = 'error-asset_upload';
			return $result;
		}
		$size = filesize($upload['tmp_name']);
		if ($size === false || $size < 1) {
			$result['error'] = 'error-asset_upload';
			return $result;
		}
		if ($size > $max_bytes) {
			$result['error'] = 'error-asset_too_large';
			return $result;
		}
		if (!self::make_dir($dir)) {
			$result['error'] = 'error-asset_store';
			return $result;
		}

		//move the upload next to its destination first, then validate it there
		$temp = $dir.'/.upload-'.bin2hex(random_bytes(8));
		if (!@move_uploaded_file($upload['tmp_name'], $temp)) {
			$result['error'] = 'error-asset_store';
			return $result;
		}
		@chmod($temp, 0640);
		try {
			return $this->store_image($scope, $group, $temp, $username);
		}
		finally {
			if (file_exists($temp)) {
				@unlink($temp);
			}
		}
	}

	/**
	 * Fits, encodes and stores a local PNG or JPEG for a group, then links the phones: the domain is synced
	 * at once, a system default change requests a background sync of all domains.
	 *
	 * @return array ['error' => language key|null, 'warnings' => [language key], 'sync' => sync result|null]
	 */
	public function store_image(string $scope, string $group, string $path, string $username): array {
		$result = ['error' => null, 'warnings' => [], 'sync' => null];
		$dir = self::scope_dir($scope);
		$specs = self::group_specs();
		if ($dir === null || !isset($specs[$group])) {
			$result['error'] = 'error-wallpaper_group';
			return $result;
		}
		$spec = $specs[$group];
		if (!function_exists('imagecreatefromjpeg') || !function_exists('imagecreatefrompng') || !function_exists('getimagesize')) {
			$result['error'] = 'error-asset_no_gd';
			return $result;
		}

		//detect the type from the content, never from the client or the extension
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($path);
		$image_types = ['image/png' => IMAGETYPE_PNG, 'image/jpeg' => IMAGETYPE_JPEG];
		if (!is_string($mime) || !isset($image_types[$mime])) {
			$result['error'] = 'error-wallpaper_type';
			return $result;
		}
		$info = @getimagesize($path);
		if (!is_array($info) || $info[2] !== $image_types[$mime]) {
			$result['error'] = 'error-wallpaper_type';
			return $result;
		}
		$source_width = (int) $info[0];
		$source_height = (int) $info[1];
		if ($source_width < 1 || $source_height < 1 || $source_width > provision_overlay::asset_max_dimension || $source_height > provision_overlay::asset_max_dimension) {
			$result['error'] = 'error-asset_dimensions';
			return $result;
		}
		//the orientation is read before decoding: a rotation needs a second copy of the source
		$orientation = ($mime === 'image/jpeg') ? self::exif_orientation($path) : 1;
		$limit = self::memory_limit();
		if ($limit > 0 && memory_get_usage() + self::memory_needed($source_width, $source_height, $orientation !== 1) > $limit) {
			$result['error'] = 'error-wallpaper_memory';
			return $result;
		}

		//decode, apply the exif orientation, fit to the group
		$source = ($mime === 'image/jpeg') ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
		if (!$source) {
			$result['error'] = 'error-asset_process';
			return $result;
		}
		$oriented = self::orient($source, $orientation);
		if ($oriented !== $source) {
			imagedestroy($source);
		}
		if (!$oriented) {
			$result['error'] = 'error-asset_process';
			return $result;
		}
		$upright_width = imagesx($oriented);
		$upright_height = imagesy($oriented);
		$upscaled = $upright_width < $spec['width'] || $upright_height < $spec['height'];
		$fitted = self::fit($oriented, $spec['width'], $spec['height']);
		imagedestroy($oriented);

		//encode every format a member needs, within the strictest member limit
		$variants = [];
		foreach ($spec['formats'] as $ext => $format_limit) {
			$encoded = ($ext === 'jpg') ? self::encode_jpeg($fitted, $format_limit) : self::encode_png($fitted, $format_limit);
			if ($encoded === null) {
				imagedestroy($fitted);
				$result['error'] = 'error-wallpaper_too_large';
				return $result;
			}
			$variants[$ext] = $encoded;
		}
		imagedestroy($fitted);
		$hash = hash('sha256', implode('', array_column($variants, 'data')));
		$stem = 'wp-'.$group.'-'.substr($hash, 0, 8);

		if (!$this->overlay->lock()) {
			$result['error'] = 'error-lock';
			return $result;
		}
		$old_umask = umask(0027);
		try {
			if (!self::make_dir($dir)) {
				$result['error'] = 'error-asset_store';
				return $result;
			}
			//never replace metadata that cannot be read: it would lose the other groups' images
			$meta = self::read_meta($scope);
			if ($meta === null) {
				$result['error'] = 'error-wallpaper_meta';
				return $result;
			}

			//files first, then the metadata, then the settings
			$files = [];
			foreach ($variants as $ext => $encoded) {
				$name = $stem.'.'.$ext;
				if (!self::write_atomic($dir.'/'.$name, $encoded['data'])) {
					$result['error'] = 'error-asset_store';
					return $result;
				}
				$files[$ext] = ['name' => $name, 'mime' => self::formats[$ext], 'size' => strlen($encoded['data'])];
				if (isset($encoded['quality'])) {
					$files[$ext]['quality'] = $encoded['quality'];
				}
				if (isset($encoded['colors'])) {
					$files[$ext]['colors'] = $encoded['colors'];
				}
			}
			$new_names = array_column($files, 'name');
			$now = gmdate('c');
			foreach (($meta['groups'][$group]['files'] ?? []) as $file) {
				$old = (string) ($file['name'] ?? '');
				if (self::valid_file_name($old) && !in_array($old, $new_names, true)) {
					$meta['retired'][$old] = $now;
				}
			}
			foreach ($new_names as $name) {
				unset($meta['retired'][$name], $meta['unrecorded'][$name]);
			}
			$meta['groups'][$group] = [
				'files' => $files,
				'width' => $spec['width'],
				'height' => $spec['height'],
				'source' => ['mime' => $mime, 'width' => $upright_width, 'height' => $upright_height, 'orientation' => $orientation],
				'upscaled' => $upscaled,
				'uploaded_by' => $username,
				'uploaded_at' => $now,
			];
			if (!self::write_meta($scope, $meta)) {
				$result['error'] = 'error-asset_store';
				return $result;
			}
			self::log('wallpaper_saved', $scope, $group.' '.implode(',', $new_names), $username);
			if ($upscaled) {
				$result['warnings'][] = 'warning-wallpaper_upscaled';
			}
			$result['sync'] = $this->after_change($scope);
			return $result;
		}
		finally {
			umask($old_umask);
			$this->overlay->unlock();
		}
	}

	/**
	 * Removes the image of a group. Its files stay downloadable for the grace period.
	 *
	 * @return array ['error' => language key|null, 'sync' => sync result|null]
	 */
	public function remove(string $scope, string $group, string $username): array {
		$result = ['error' => null, 'sync' => null];
		if (self::scope_dir($scope) === null || !isset(self::groups[$group])) {
			$result['error'] = 'error-wallpaper_group';
			return $result;
		}
		if (!$this->overlay->lock()) {
			$result['error'] = 'error-lock';
			return $result;
		}
		try {
			$meta = self::read_meta($scope);
			if ($meta === null) {
				$result['error'] = 'error-wallpaper_meta';
				return $result;
			}
			if (!isset($meta['groups'][$group])) {
				$result['error'] = 'error-not_found';
				return $result;
			}
			$now = gmdate('c');
			foreach ($meta['groups'][$group]['files'] as $file) {
				$name = (string) ($file['name'] ?? '');
				if (self::valid_file_name($name)) {
					$meta['retired'][$name] = $now;
				}
			}
			unset($meta['groups'][$group]);
			if (!self::write_meta($scope, $meta)) {
				$result['error'] = 'error-asset_store';
				return $result;
			}
			self::log('wallpaper_removed', $scope, $group, $username);
			$result['sync'] = $this->after_change($scope);
			return $result;
		}
		finally {
			$this->overlay->unlock();
		}
	}

	/**
	 * A domain is synced in the request; a system default change is synced for all domains in the background.
	 */
	private function after_change(string $scope): ?array {
		if ($scope === self::global_scope) {
			$this->request_sync();
			return null;
		}
		return $this->sync_domain($scope);
	}

	/**
	 * Asks x-provision-wallpapers.path to start the sync of all domains (www-data cannot start units itself).
	 * The 2 minute timer picks the change up as well.
	 */
	public function request_sync(): bool {
		return @file_put_contents(self::request_path(), gmdate('c')."\n") !== false;
	}

	//-------------------------------------------------------------------------
	// template check
	//-------------------------------------------------------------------------

	/**
	 * Directory provision.php renders from and the id its check results are cached under.
	 *
	 * @return array [base dir, cache id]
	 */
	private static function template_base(): array {
		$current = provision_overlay::current_id();
		if (!provision_overlay::is_disabled() && $current !== '') {
			return [provision_overlay::current_link(), $current];
		}
		return [provision_overlay::upstream_dir(), 'upstream-'.provision_overlay::upstream_commit()];
	}

	/**
	 * Catalog templates whose merged files (the domain's subdir when it has one) still reference every
	 * variable of their set. Cached per overlay build id.
	 *
	 * @return array template => bool
	 */
	public static function template_support(string $domain_name): array {
		[$base, $id] = self::template_base();
		//a changed catalog invalidates the cache as well
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
		foreach (array_keys(self::catalog) as $template) {
			$support[$template] = self::template_references($root.'/'.$template, self::set_variables($template));
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
	 * True when the files of a template dir reference every variable (as $name, not as a prefix of a longer name).
	 */
	private static function template_references(string $dir, array $variables): bool {
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
			if (preg_match('/\$'.preg_quote($variable, '/').'(?![A-Za-z0-9_])/', $content) !== 1) {
				return false;
			}
		}
		return true;
	}

	//-------------------------------------------------------------------------
	// sync
	//-------------------------------------------------------------------------

	private static function bool_value($value): bool {
		return $value === true || $value === 1 || $value === 't' || $value === 'true' || $value === '1';
	}

	/**
	 * Named placeholders for an IN list.
	 *
	 * @return array [sql fragment, parameters]
	 */
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
		$rows = $this->database->execute($sql, $parameters, 'all');
		if ($rows === false) {
			$message = $this->database->message ?? null;
			throw new RuntimeException('database error: '.(is_array($message) ? ($message['message'] ?? 'unknown') : 'unknown'));
		}
		return is_array($rows) ? $rows : [];
	}

	/**
	 * Works out, for one domain, what each device on a catalog template gets and the changes needed.
	 * Read only.
	 *
	 * Device states: managed (gets the group's image), none (no image: template default), own (a value is set
	 * elsewhere: sources lists domain / profile:<name> / device), optout (a managed row was disabled),
	 * alternate (device_uuid_alternate set), unsupported (template check failed), disabled (device disabled).
	 *
	 * @return array ['devices' => [uuid => row], 'counts' => [group => int], 'unsupported' => [template], 'images' => [group => image|null], 'plan' => [...]]
	 */
	public function analyze(string $domain_uuid, string $domain_name): array {
		if (!is_object($this->database) || !provision_overlay::valid_uuid($domain_uuid)) {
			throw new RuntimeException('a database connection and a domain are required');
		}
		$templates = array_keys(self::catalog);
		$variables = self::all_variables();
		$plan = ['inserts' => [], 'updates' => [], 'deletes' => [], 'drop_links' => [], 'move_links' => []];

		//images per group: domain, else system default; unreadable metadata stops the analysis, so a damaged
		//file never looks like "no image" and removes the phones' rows
		$domain_meta = self::require_meta($domain_uuid);
		$global_meta = self::require_meta(self::global_scope);
		$images = [];
		foreach (array_keys(self::groups) as $group) {
			$images[$group] = self::effective_image($domain_uuid, $group, $domain_meta, $global_meta);
		}
		$support = self::template_support($domain_name);

		//devices on catalog templates
		[$in_templates, $parameters] = self::in_list('t', $templates);
		$sql = "select device_uuid, device_address, device_label, device_template, device_profile_uuid, device_uuid_alternate, device_enabled, ";
		$sql .= "device_model, device_provisioned_agent ";
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

		//device settings of the domain's devices for any variable of the catalog (provision.php matches by subcategory, any category)
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

		//links recorded by earlier syncs for this domain, or for a device that is in this domain now
		$sql = "select wallpaper_link_uuid, domain_uuid, device_uuid, device_setting_uuid, wallpaper_subcategory, wallpaper_value ";
		$sql .= "from v_x_provision_overlay_wallpaper_links ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "or device_uuid in (select device_uuid from v_devices where domain_uuid = :domain_uuid) ";
		$sql .= "order by insert_date asc, wallpaper_link_uuid asc ";
		$parameters['domain_uuid'] = $domain_uuid;
		$links = $this->query($sql, $parameters);
		unset($sql, $parameters);

		//rows of links the query above does not cover: the device moved to another domain, or the variable
		//left the catalog; they are ours while they hold the value written, and are removed
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

		//enabled, non-empty domain settings (settings.php ignores empty domain values)
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

		//enabled device profile settings of the profiles the domain's devices use (provision.php 812-823)
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

		//a link holds while its row exists with the value written; otherwise the row is the admin's (or gone)
		$managed = [];
		foreach ($links as $link) {
			$orphan = $orphans[$link['device_setting_uuid']] ?? null;
			if ($orphan !== null) {
				unset($orphans[$link['device_setting_uuid']]);
				if ($orphan['device_uuid'] === $link['device_uuid']
					&& $orphan['device_setting_subcategory'] === $link['wallpaper_subcategory']
					&& (string) $orphan['device_setting_value'] === (string) $link['wallpaper_value']
					&& self::bool_value($orphan['device_setting_enabled'])) {
					$plan['deletes'][] = ['link_uuid' => $link['wallpaper_link_uuid'], 'setting_uuid' => $link['device_setting_uuid'], 'value' => (string) $link['wallpaper_value'], 'enabled' => true];
				}
				else {
					$plan['drop_links'][] = $link['wallpaper_link_uuid'];
				}
				continue;
			}
			$row = $settings[$link['device_setting_uuid']] ?? null;
			if ($row === null
				|| $row['device_uuid'] !== $link['device_uuid']
				|| $row['device_setting_subcategory'] !== $link['wallpaper_subcategory']
				|| (string) $row['device_setting_value'] !== (string) $link['wallpaper_value']
				|| $row['managed']) {
				$plan['drop_links'][] = $link['wallpaper_link_uuid'];
				continue;
			}
			$settings[$link['device_setting_uuid']]['managed'] = true;
			if ($link['domain_uuid'] !== $domain_uuid) {
				$plan['move_links'][] = $link['wallpaper_link_uuid'];
			}
			$managed[$link['device_uuid']][$link['wallpaper_subcategory']][] = [
				'link_uuid' => $link['wallpaper_link_uuid'],
				'setting_uuid' => $link['device_setting_uuid'],
				'value' => (string) $link['wallpaper_value'],
				'enabled' => $row['enabled'],
			];
		}

		//unmanaged enabled device rows, by device and variable
		$device_values = [];
		foreach ($settings as $row) {
			if (!$row['managed'] && $row['enabled']) {
				$device_values[$row['device_uuid']][$row['device_setting_subcategory']] = true;
			}
		}

		//state of each device on a catalog template and the rows it should have
		$result_devices = [];
		$counts = array_fill_keys(array_keys(self::groups), 0);
		$unsupported = [];
		foreach ($support as $template => $supported) {
			if (!$supported) {
				$unsupported[] = $template;
			}
		}
		$desired = [];
		foreach ($devices as $device_uuid => $device) {
			$template = $device['device_template'];
			$entry = self::catalog[$template];
			$group = self::device_group($entry, $device);
			$set = self::set_variables($template);
			$sources = [];
			$optout = false;
			foreach ($managed[$device_uuid] ?? [] as $entries) {
				foreach ($entries as $m) {
					if (!$m['enabled']) {
						$optout = true;
					}
				}
			}
			foreach ($set as $variable) {
				if (isset($domain_settings[$variable])) {
					$sources['domain'] = 'domain';
				}
				$profile_uuid = (string) ($device['device_profile_uuid'] ?? '');
				if ($profile_uuid !== '' && isset($profile_settings[$profile_uuid]['variables'][$variable])) {
					$sources['profile'] = 'profile:'.$profile_settings[$profile_uuid]['name'];
				}
				if (isset($device_values[$device_uuid][$variable])) {
					$sources['device'] = 'device';
				}
			}
			$image = $images[$group];

			if (!self::bool_value($device['device_enabled'])) {
				$state = 'disabled';
			}
			elseif (provision_overlay::valid_uuid((string) ($device['device_uuid_alternate'] ?? ''))) {
				$state = 'alternate';
			}
			elseif (empty($support[$template])) {
				$state = 'unsupported';
			}
			elseif ($optout) {
				$state = 'optout';
			}
			elseif (!empty($sources)) {
				$state = 'own';
			}
			elseif ($image === null || !isset($image['files'][$entry['format']])) {
				$state = 'none';
			}
			else {
				$state = 'managed';
				$file = $image['files'][$entry['format']];
				foreach ($entry['vars'] as $variable => $kind) {
					$desired[$device_uuid][$variable] = [
						'value' => ($kind === 'filename') ? $file : provision_overlay::asset_url($file),
						'group' => $group,
						'file' => $file,
					];
				}
				$counts[$group]++;
			}
			$result_devices[$device_uuid] = [
				'device_uuid' => $device_uuid,
				'address' => (string) $device['device_address'],
				'label' => (string) ($device['device_label'] ?? ''),
				'template' => $template,
				'group' => $group,
				'state' => $state,
				'sources' => array_values($sources),
				'image_scope' => ($state === 'managed') ? $image['scope'] : '',
			];
		}

		//diff the managed rows against the desired ones
		foreach ($managed as $device_uuid => $by_variable) {
			foreach ($by_variable as $variable => $entries) {
				//duplicates for one device and variable are collapsed; a disabled row (opt-out) is kept first
				usort($entries, function ($a, $b) { return (int) $a['enabled'] <=> (int) $b['enabled']; });
				$keep = array_shift($entries);
				foreach ($entries as $extra) {
					if ($extra['enabled']) {
						$plan['deletes'][] = $extra;
					}
				}
				if (!$keep['enabled']) {
					//opt-out: left alone
					unset($desired[$device_uuid][$variable]);
					continue;
				}
				if (isset($desired[$device_uuid][$variable])) {
					$want = $desired[$device_uuid][$variable];
					if ($want['value'] !== $keep['value']) {
						$plan['updates'][] = $keep + ['new_value' => $want['value'], 'group' => $want['group'], 'file' => $want['file']];
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

		return [
			'devices' => $result_devices,
			'counts' => $counts,
			'unsupported' => $unsupported,
			'images' => $images,
			'plan' => $plan,
		];
	}

	/**
	 * Syncs the managed device settings of one domain: computes the desired rows from the image metadata,
	 * diffs them against the links and writes only the changes, in one transaction, under the overlay lock.
	 *
	 * @return array ['success' => bool, 'inserted' => int, 'updated' => int, 'deleted' => int, 'dropped' => int, 'error' => string]
	 */
	public function sync_domain(string $domain_uuid): array {
		$result = ['success' => false, 'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'dropped' => 0, 'error' => ''];
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
			if (empty($plan['inserts']) && empty($plan['updates']) && empty($plan['deletes']) && empty($plan['drop_links']) && empty($plan['move_links'])) {
				$result['success'] = true;
				return $result;
			}

			//use the PDO handle of database::execute()
			$this->query("select 1");
			$pdo = $this->database->db;
			if (!($pdo instanceof PDO)) {
				$result['error'] = 'database not connected';
				return $result;
			}
			$pdo->beginTransaction();
			try {
				foreach ($plan['drop_links'] as $link_uuid) {
					$this->query("delete from v_x_provision_overlay_wallpaper_links where wallpaper_link_uuid = :uuid ", ['uuid' => $link_uuid]);
					$result['dropped']++;
				}

				//links of devices that moved into this domain
				foreach ($plan['move_links'] as $link_uuid) {
					$this->query("update v_x_provision_overlay_wallpaper_links set domain_uuid = :domain_uuid, update_date = now() where wallpaper_link_uuid = :uuid ", ['domain_uuid' => $domain_uuid, 'uuid' => $link_uuid]);
				}

				//managed rows no longer wanted; only while they still hold the value written and are enabled. When an
				//admin changed, disabled or deleted the row since the analysis, the link stays and the next analysis
				//sees what happened (a disabled row is an opt-out and must keep its link)
				foreach ($plan['deletes'] as $row) {
					$sql = "delete from v_device_settings ";
					$sql .= "where device_setting_uuid = :uuid and device_setting_value = :value and device_setting_enabled = true ";
					$sql .= "returning device_setting_uuid ";
					$deleted = $this->query($sql, ['uuid' => $row['setting_uuid'], 'value' => $row['value']]);
					if (empty($deleted)) {
						continue;
					}
					$this->query("delete from v_x_provision_overlay_wallpaper_links where wallpaper_link_uuid = :uuid ", ['uuid' => $row['link_uuid']]);
					$result['deleted']++;
				}

				//new image for a managed row
				foreach ($plan['updates'] as $row) {
					$sql = "update v_device_settings set device_setting_value = :new_value, update_date = now() ";
					$sql .= "where device_setting_uuid = :uuid and device_setting_value = :value and device_setting_enabled = true ";
					$sql .= "returning device_setting_uuid ";
					$updated = $this->query($sql, ['new_value' => $row['new_value'], 'uuid' => $row['setting_uuid'], 'value' => $row['value']]);
					if (empty($updated)) {
						//changed, disabled or deleted by an admin since the analysis: the next analysis decides
						continue;
					}
					$sql = "update v_x_provision_overlay_wallpaper_links set wallpaper_value = :value, wallpaper_group = :group, wallpaper_file = :file, update_date = now() ";
					$sql .= "where wallpaper_link_uuid = :uuid ";
					$this->query($sql, ['value' => $row['new_value'], 'group' => $row['group'], 'file' => $row['file'], 'uuid' => $row['link_uuid']]);
					$result['updated']++;
				}

				//new rows
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
					$sql = "insert into v_x_provision_overlay_wallpaper_links ";
					$sql .= "(wallpaper_link_uuid, domain_uuid, device_uuid, device_setting_uuid, wallpaper_subcategory, wallpaper_value, wallpaper_group, wallpaper_file, insert_date) ";
					$sql .= "values (:uuid, :domain_uuid, :device_uuid, :setting_uuid, :subcategory, :value, :group, :file, now()) ";
					$this->query($sql, [
						'uuid' => uuid(),
						'domain_uuid' => $domain_uuid,
						'device_uuid' => $row['device_uuid'],
						'setting_uuid' => $setting_uuid,
						'subcategory' => $row['subcategory'],
						'value' => $row['value'],
						'group' => $row['group'],
						'file' => $row['file'],
					]);
					$result['inserted']++;
				}
				$pdo->commit();
			}
			catch (Throwable $e) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}
				$result['inserted'] = $result['updated'] = $result['deleted'] = $result['dropped'] = 0;
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

	/**
	 * Syncs every domain. The lock is taken once for the whole run (it is re-entrant); when another change
	 * holds it the run gives up at once, and the next timer run tries again.
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
	// cleanup (daily overlay job)
	//-------------------------------------------------------------------------

	/**
	 * Wallpaper file names still referenced anywhere provision.php or the sync may use them: links, and enabled
	 * device, device profile, domain and default settings (e.g. rows copied by Devices > Copy, or a url pasted
	 * into a setting). Null without a database or when a query fails: then nothing may be deleted.
	 */
	private function referenced_files(): ?array {
		if (!is_object($this->database)) {
			return null;
		}
		$queries = [
			"select wallpaper_file as value from v_x_provision_overlay_wallpaper_links ",
			"select wallpaper_value as value from v_x_provision_overlay_wallpaper_links ",
			"select device_setting_value as value from v_device_settings where device_setting_enabled = true and device_setting_value like '%wp-%' ",
			"select profile_setting_value as value from v_device_profile_settings where profile_setting_enabled = true and profile_setting_value like '%wp-%' ",
			"select domain_setting_value as value from v_domain_settings where domain_setting_enabled = true and domain_setting_value like '%wp-%' ",
			"select default_setting_value as value from v_default_settings where default_setting_enabled = true and default_setting_value like '%wp-%' ",
		];
		$names = [];
		try {
			foreach ($queries as $sql) {
				foreach ($this->query($sql) as $row) {
					if (preg_match_all('/wp-[0-9]{3,4}x[0-9]{3,4}-[0-9a-f]{8}\.(?:jpg|png)/', (string) ($row['value'] ?? ''), $matches) > 0) {
						foreach ($matches[0] as $name) {
							$names[$name] = true;
						}
					}
				}
			}
		}
		catch (Throwable $e) {
			self::log_error('cleanup', $e->getMessage());
			return null;
		}
		return $names;
	}

	/**
	 * Daily cleanup:
	 *  - wallpaper files replaced or removed more than a day ago, and wp- files no metadata records (first seen
	 *    more than a day ago), unless still referenced (referenced_files()); nothing when that check fails;
	 *  - scopes whose metadata cannot be read are skipped;
	 *  - upload and temp files older than an hour (left by a request that died, e.g. out of memory);
	 *  - the asset directory of a deleted domain a week after it was first seen missing (it also holds the
	 *    domain's branding assets), and the links of deleted domains.
	 *
	 * @return array ['files' => int, 'kept' => int, 'temp' => int, 'domains' => int, 'links' => int, 'errors' => []]
	 */
	public function cleanup(int $now = 0): array {
		$result = ['files' => 0, 'kept' => 0, 'temp' => 0, 'domains' => 0, 'links' => 0, 'errors' => []];
		$now = ($now > 0) ? $now : time();
		$stamp = gmdate('c', $now);
		if (!$this->overlay->lock()) {
			$result['errors'][] = 'cannot acquire the overlay lock';
			return $result;
		}
		try {
			//deleted domains: only with a domain list that was read successfully and is not empty
			$domains = $this->overlay->has_database() ? $this->overlay->get_domains() : null;
			$domains_known = is_array($domains) && !empty($domains);
			$referenced = $this->referenced_files();
			if ($referenced === null) {
				$result['errors'][] = 'references could not be checked (no database or a query failed); no wallpaper file was deleted';
			}

			$missing_path = provision_overlay::assets_root().'/'.self::missing_domains_file;
			$missing = is_file($missing_path) && !is_link($missing_path) ? json_decode((string) @file_get_contents($missing_path), true) : [];
			$missing = is_array($missing) ? $missing : [];
			$still_missing = [];
			$scopes = [self::global_scope];
			$root_entries = @scandir(provision_overlay::assets_root());
			foreach (is_array($root_entries) ? $root_entries : [] as $entry) {
				$path = provision_overlay::assets_root().'/'.$entry;
				if (!provision_overlay::valid_uuid($entry) || is_link($path) || !is_dir($path)) {
					continue;
				}
				if ($domains_known && !isset($domains[$entry])) {
					$since = isset($missing[$entry]) ? strtotime((string) $missing[$entry]) : false;
					if ($since === false) {
						$still_missing[$entry] = $stamp;
						continue;
					}
					if ($now - $since < self::domain_grace_seconds) {
						$still_missing[$entry] = (string) $missing[$entry];
						continue;
					}
					if (self::remove_tree($path)) {
						$result['domains']++;
						self::log('assets_removed', $entry, 'domain deleted', 'cleanup');
					}
					else {
						$still_missing[$entry] = (string) $missing[$entry];
						$result['errors'][] = 'cannot remove '.$path;
					}
					continue;
				}
				$scopes[] = $entry;
			}
			if ($domains_known) {
				ksort($still_missing, SORT_STRING);
				if ($still_missing !== $missing) {
					if (empty($still_missing)) {
						@unlink($missing_path);
					}
					elseif (!self::write_atomic($missing_path, (string) json_encode($still_missing, JSON_PRETTY_PRINT)."\n")) {
						$result['errors'][] = 'cannot write '.$missing_path;
					}
				}
			}
			if ($domains_known && is_object($this->database)) {
				$sql = "delete from v_x_provision_overlay_wallpaper_links ";
				$sql .= "where domain_uuid not in (select domain_uuid from v_domains) ";
				$sql .= "returning wallpaper_link_uuid ";
				$rows = $this->database->execute($sql, null, 'all');
				$result['links'] = is_array($rows) ? count($rows) : 0;
			}

			foreach ($scopes as $scope) {
				$dir = self::scope_dir($scope);
				if ($dir === null || !is_dir($dir) || is_link($dir)) {
					continue;
				}
				$entries = @scandir($dir);
				$entries = is_array($entries) ? $entries : [];

				//uploads and temp files of requests that died
				foreach ($entries as $entry) {
					$path = $dir.'/'.$entry;
					$temporary = strpos($entry, '.upload-') === 0 || strpos($entry, '.encoded-') === 0 || ($entry !== '' && $entry[0] === '.' && substr($entry, -4) === '.tmp');
					if ($temporary && is_file($path) && !is_link($path) && $now - (int) filemtime($path) >= self::temp_grace_seconds && @unlink($path)) {
						$result['temp']++;
					}
				}

				$meta = self::read_meta($scope);
				if ($meta === null) {
					$result['errors'][] = 'skipped '.$dir.': '.self::meta_file.' cannot be read';
					continue;
				}
				$current = [];
				foreach ($meta['groups'] as $image) {
					foreach ($image['files'] as $file) {
						$current[(string) ($file['name'] ?? '')] = true;
					}
				}
				$changed = false;

				//wp- files no metadata records get a first-seen time; they are handled like retired files from then
				foreach ($entries as $entry) {
					$path = $dir.'/'.$entry;
					if (self::valid_file_name($entry) && !isset($current[$entry]) && !isset($meta['retired'][$entry]) && !isset($meta['unrecorded'][$entry]) && is_file($path) && !is_link($path)) {
						$meta['unrecorded'][$entry] = $stamp;
						$changed = true;
					}
				}

				foreach (['retired', 'unrecorded'] as $list) {
					foreach ($meta[$list] as $name => $time) {
						$path = $dir.'/'.$name;
						if (isset($current[$name]) || ($list === 'unrecorded' && isset($meta['retired'][$name])) || !file_exists($path)) {
							unset($meta[$list][$name]);
							$changed = true;
							continue;
						}
						$since = strtotime($time);
						if ($since === false) {
							//an unreadable time starts the grace period now
							$meta[$list][$name] = $stamp;
							$changed = true;
							continue;
						}
						if ($now - $since < self::grace_seconds || $referenced === null) {
							continue;
						}
						if (isset($referenced[$name])) {
							$result['kept']++;
							continue;
						}
						if (is_link($path) || !is_file($path) || !@unlink($path)) {
							$result['errors'][] = 'cannot delete '.$path;
							continue;
						}
						unset($meta[$list][$name]);
						$changed = true;
						$result['files']++;
					}
				}
				if ($changed && !self::write_meta($scope, $meta)) {
					$result['errors'][] = 'cannot write '.$dir.'/'.self::meta_file;
				}
			}
			return $result;
		}
		finally {
			$this->overlay->unlock();
		}
	}

	/**
	 * Logs an error detail to syslog; pages show a generic message instead.
	 */
	public static function log_error(string $context, string $detail): void {
		openlog('FusionPBX', LOG_PID, LOG_LOCAL0);
		syslog(LOG_ERR, 'x_provision_overlay: wallpapers: '.$context.': '.preg_replace('/[\r\n]+/', ' ', $detail));
		closelog();
	}

	/**
	 * Logs to syslog LOG_LOCAL0 like app/provision.
	 */
	private static function log(string $action, string $scope, string $detail, string $username): void {
		$message = 'x_provision_overlay: '.$action.' scope='.$scope.' '.$detail;
		$message .= ' user='.preg_replace('/[^A-Za-z0-9@._-]/', '_', $username);
		openlog('FusionPBX', LOG_PID, LOG_LOCAL0);
		syslog(LOG_NOTICE, $message);
		closelog();
	}

}

?>
