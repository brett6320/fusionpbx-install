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
	Branding of the FusionPBX web interface (docs/design/branding.md).

	A system default (superadmin) and a per-domain override of the logos, favicon, page title and menu brand
	text. Each item is written as theme settings: default settings for the system, domain settings for a
	domain. Images are stored per scope and served without a session (the login page needs them):

		system   /var/lib/fusionpbx/provision_overlay/branding/global/br-<item>-<sha12>.<ext>
		domain   /var/lib/fusionpbx/provision_overlay/branding/<domain_uuid>/br-<item>-<sha12>.<ext>

	each directory with its own .branding.json, served at /branding-assets/<scope>/<file> by branding.php.
	The setting rows written are recorded in v_x_provision_overlay_branding_links; a row is ours only while
	its value equals what we wrote. A value an admin set (an enabled row of the same subcategory that is not
	ours) wins; see apply().
*/

//define the provision_branding class
class provision_branding {

	//limits
	const grace_seconds = 86400;
	const retired_keep = 2;
	const lock_timeout = 30;
	const favicon_max_bytes = 262144;
	const favicon_max_dimension = 512;
	const text_max_length = 64;

	//files
	const meta_file = '.branding.json';
	const lock_file = '.branding.lock';
	const global_scope = 'global';
	const file_pattern = '/^br-([a-z_]+)-([0-9a-f]{12})\.(png|jpg|gif|webp|ico)$/';
	const url_path = '/branding-assets/';
	const nginx_conf = '/etc/nginx/sites-available/fusionpbx';

	//theme settings written
	const setting_category = 'theme';
	const setting_description = 'Branding (managed by Provision Templates > Branding; disable this row to keep the upstream value)';

	//image types: mime => [extension, getimagesize type]
	const image_types = [
		'image/png' => ['png', IMAGETYPE_PNG],
		'image/jpeg' => ['jpg', IMAGETYPE_JPEG],
		'image/gif' => ['gif', IMAGETYPE_GIF],
		'image/webp' => ['webp', IMAGETYPE_WEBP],
		'image/vnd.microsoft.icon' => ['ico', IMAGETYPE_ICO],
		'image/x-icon' => ['ico', IMAGETYPE_ICO],
	];

	/*
		Items, verified against upstream at the commit pinned in config.sh (58ca4e9):
			menu_logo   menu.php menu_horizontal() (menu_brand_image) and menu_vertical() (expanded side menu)
			menu_icon   menu.php menu_vertical(), contracted side menu
			login_logo  the authentication plugins' login views (logo; login_brand_image is read but not
			            shown) and footer.php (logo_login, else logo; also the logo of the inline menu style)
			favicon     footer.php and the login views; must be a path or url ending in an extension
			title       footer.php document title
			menu_brand_text, menu_brand_type   menu.php (text beside or instead of the menu logo)
			login_logo_width, login_logo_height   the login views, written into a style attribute
		kind: image (types allowed), text, choice, size. subcategories: the theme settings written, together.
	*/
	const items = [
		'menu_logo' => ['kind' => 'image', 'types' => ['png', 'jpg', 'gif', 'webp'], 'subcategories' => ['menu_brand_image', 'menu_side_brand_image_expanded']],
		'menu_icon' => ['kind' => 'image', 'types' => ['png', 'jpg', 'gif', 'webp'], 'subcategories' => ['menu_side_brand_image_contracted']],
		'login_logo' => ['kind' => 'image', 'types' => ['png', 'jpg', 'gif', 'webp'], 'subcategories' => ['logo', 'logo_login']],
		'favicon' => ['kind' => 'image', 'types' => ['ico', 'png'], 'subcategories' => ['favicon']],
		'title' => ['kind' => 'text', 'subcategories' => ['title']],
		'menu_brand_text' => ['kind' => 'text', 'subcategories' => ['menu_brand_text']],
		'menu_brand_type' => ['kind' => 'choice', 'choices' => ['image', 'text', 'image_text', 'none'], 'subcategories' => ['menu_brand_type']],
		'login_logo_width' => ['kind' => 'size', 'subcategories' => ['login_logo_width']],
		'login_logo_height' => ['kind' => 'size', 'subcategories' => ['login_logo_height']],
	];

	/**
	 * @var database
	 */
	private $database;

	/**
	 * @var resource|null
	 */
	private $lock_handle = null;

	public function __construct(array $setting_array = []) {
		$this->database = $setting_array['database'] ?? null;
	}

	//-------------------------------------------------------------------------
	// names, paths and metadata
	//-------------------------------------------------------------------------

	public static function root(): string {
		return provision_overlay::state_root.'/branding';
	}

	/**
	 * Directory of a scope: 'global' or a domain uuid. Returns null for anything else.
	 */
	public static function scope_dir(string $scope): ?string {
		if ($scope !== self::global_scope && !provision_overlay::valid_uuid($scope)) {
			return null;
		}
		return self::root().'/'.strtolower($scope);
	}

	public static function valid_file_name(string $name): bool {
		return preg_match(self::file_pattern, $name, $m) === 1 && isset(self::items[$m[1]]) && self::items[$m[1]]['kind'] === 'image';
	}

	/**
	 * URL path written to the settings (a path, so it works on whatever host the page is opened with).
	 */
	public static function url(string $scope, string $file): string {
		return self::url_path.strtolower($scope).'/'.$file;
	}

	/**
	 * Valid text: printable, no markup characters (upstream prints some of these values unescaped).
	 */
	public static function valid_text(string $value): bool {
		return $value !== '' && mb_strlen($value) <= self::text_max_length
			&& preg_match('/^[^<>"\'&\\\\`\p{C}]+$/u', $value) === 1;
	}

	/**
	 * Valid logo size: written into a style attribute by the login views. 'auto' is not written (save_values()
	 * treats it as empty): upstream's default 'auto; max-width: 300px' then applies, with its cap.
	 */
	public static function valid_size(string $value): bool {
		return preg_match('/^[0-9]{1,4}(px|%|em|rem|vw|vh)$/', $value) === 1;
	}

	/**
	 * Whether the installer's nginx location for /branding-assets/ is in the vhost: true, false, or null when
	 * the file cannot be read (then the page does not block).
	 */
	public static function nginx_ready(): ?bool {
		if (!is_file(self::nginx_conf) || !is_readable(self::nginx_conf)) {
			return null;
		}
		$content = @file_get_contents(self::nginx_conf);
		if (!is_string($content)) {
			return null;
		}
		return strpos($content, 'location ^~ '.self::url_path.' {') !== false;
	}

	/**
	 * Decoded .branding.json of a scope: ['images' => [item => image], 'values' => [item => value],
	 * 'retired' => [file => time]]. Empty when the scope has none; null when the file exists but cannot be
	 * used (symlink, unreadable, not valid json), so callers stop instead of removing every setting.
	 */
	public static function read_meta(string $scope): ?array {
		$meta = ['images' => [], 'values' => [], 'retired' => []];
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
		$data = is_string($content) ? json_decode($content, true) : null;
		if (!is_array($data)) {
			return null;
		}
		foreach (($data['images'] ?? []) as $item => $image) {
			if (is_string($item) && (self::items[$item]['kind'] ?? '') === 'image' && is_array($image)
				&& self::valid_file_name((string) ($image['file'] ?? '')) && isset(self::image_types[(string) ($image['mime'] ?? '')])) {
				$meta['images'][$item] = $image;
			}
		}
		foreach (($data['values'] ?? []) as $item => $value) {
			if (is_string($item) && isset(self::items[$item]) && self::items[$item]['kind'] !== 'image' && is_string($value) && self::valid_value($item, $value)) {
				$meta['values'][$item] = $value;
			}
		}
		foreach (($data['retired'] ?? []) as $name => $time) {
			if (is_string($name) && self::valid_file_name($name)) {
				$meta['retired'][$name] = (string) $time;
			}
		}
		return $meta;
	}

	private static function write_meta(string $scope, array $meta): bool {
		$dir = self::scope_dir($scope);
		if ($dir === null || !self::make_dir($dir)) {
			return false;
		}
		ksort($meta['images'], SORT_STRING);
		ksort($meta['values'], SORT_STRING);
		ksort($meta['retired'], SORT_STRING);
		$json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

	public static function valid_value(string $item, string $value): bool {
		$kind = self::items[$item]['kind'] ?? '';
		if ($kind === 'text') {
			return self::valid_text($value);
		}
		if ($kind === 'choice') {
			return in_array($value, self::items[$item]['choices'], true);
		}
		if ($kind === 'size') {
			return self::valid_size($value);
		}
		return false;
	}

	/**
	 * Path and content type of a file for branding.php: recorded in the scope's metadata, current or retired
	 * within the grace period, realpath inside the scope directory.
	 *
	 * @return array|null ['path' => string, 'mime' => string]
	 */
	public static function file_info(string $scope, string $name): ?array {
		if (!self::valid_file_name($name)) {
			return null;
		}
		$dir = self::scope_dir($scope);
		$meta = self::read_meta($scope);
		if ($dir === null || $meta === null) {
			return null;
		}
		$mime = null;
		foreach ($meta['images'] as $image) {
			if ($image['file'] === $name) {
				$mime = (string) $image['mime'];
			}
		}
		if ($mime === null && isset($meta['retired'][$name]) && time() - (int) strtotime($meta['retired'][$name]) < self::grace_seconds) {
			//the type of a retired file follows from its recorded extension
			$extension = pathinfo($name, PATHINFO_EXTENSION);
			foreach (self::image_types as $type => $info) {
				if ($info[0] === $extension) {
					$mime = $type;
					break;
				}
			}
		}
		if ($mime === null) {
			return null;
		}
		$path = $dir.'/'.$name;
		$real = realpath($path);
		$real_dir = realpath($dir);
		if ($real === false || $real_dir === false || $real !== $real_dir.'/'.$name || is_link($path) || !is_file($real)) {
			return null;
		}
		return ['path' => $real, 'mime' => $mime];
	}

	//-------------------------------------------------------------------------
	// lock
	//-------------------------------------------------------------------------

	public function lock(): bool {
		if (is_resource($this->lock_handle)) {
			return true;
		}
		if (!self::make_dir(self::root())) {
			return false;
		}
		$handle = @fopen(self::root().'/'.self::lock_file, 'c');
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
		return true;
	}

	public function unlock(): void {
		if (is_resource($this->lock_handle)) {
			flock($this->lock_handle, LOCK_UN);
			fclose($this->lock_handle);
		}
		$this->lock_handle = null;
	}

	//-------------------------------------------------------------------------
	// images and values
	//-------------------------------------------------------------------------

	/**
	 * Validates an uploaded image and stores it for an item of a scope, then writes the settings.
	 *
	 * @return array ['error' => language key|null, 'apply' => apply result|null]
	 */
	public function store_upload(string $scope, string $item, array $upload, int $max_bytes, string $username): array {
		$result = ['error' => null, 'apply' => null];
		$dir = self::scope_dir($scope);
		if ($dir === null || (self::items[$item]['kind'] ?? '') !== 'image') {
			$result['error'] = 'error-branding_item';
			return $result;
		}
		if ($item === 'favicon') {
			$max_bytes = min($max_bytes > 0 ? $max_bytes : self::favicon_max_bytes, self::favicon_max_bytes);
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
			return $this->store_image($scope, $item, $temp, $username);
		}
		finally {
			if (file_exists($temp)) {
				@unlink($temp);
			}
		}
	}

	/**
	 * Stores a local image for an item of a scope as it is (no re-encoding: GIF animation and ICO sizes are
	 * kept), named by its content, and writes the settings.
	 *
	 * @return array ['error' => language key|null, 'apply' => apply result|null]
	 */
	public function store_image(string $scope, string $item, string $path, string $username): array {
		$result = ['error' => null, 'apply' => null];
		$dir = self::scope_dir($scope);
		if ($dir === null || (self::items[$item]['kind'] ?? '') !== 'image') {
			$result['error'] = 'error-branding_item';
			return $result;
		}

		//detect the type from the content, never from the client or the extension; SVG is refused (it can
		//carry script and is served from the PBX's own origin)
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($path);
		if (!is_string($mime) || !isset(self::image_types[$mime]) || !in_array(self::image_types[$mime][0], self::items[$item]['types'], true)) {
			$result['error'] = ($item === 'favicon') ? 'error-branding_favicon_type' : 'error-branding_type';
			return $result;
		}
		[$extension, $image_type] = self::image_types[$mime];
		$info = @getimagesize($path);
		if (!is_array($info) || $info[2] !== $image_type || $info[0] < 1 || $info[1] < 1) {
			$result['error'] = ($item === 'favicon') ? 'error-branding_favicon_type' : 'error-branding_type';
			return $result;
		}
		$max_dimension = ($item === 'favicon') ? self::favicon_max_dimension : provision_overlay::asset_max_dimension;
		if ($info[0] > $max_dimension || $info[1] > $max_dimension) {
			$result['error'] = 'error-asset_dimensions';
			return $result;
		}
		$sha256 = hash_file('sha256', $path);
		if (!is_string($sha256)) {
			$result['error'] = 'error-asset_store';
			return $result;
		}
		$file = 'br-'.$item.'-'.substr($sha256, 0, 12).'.'.$extension;
		if (!self::make_dir($dir)) {
			$result['error'] = 'error-asset_store';
			return $result;
		}

		if (!$this->lock()) {
			$result['error'] = 'error-branding_busy';
			return $result;
		}
		try {
			$meta = self::read_meta($scope);
			if ($meta === null) {
				$result['error'] = 'error-branding_meta';
				return $result;
			}
			$target = $dir.'/'.$file;
			if (!is_file($target) || hash_file('sha256', $target) !== $sha256) {
				$copy = $dir.'/.'.$file.'.'.bin2hex(random_bytes(6)).'.tmp';
				if (!@copy($path, $copy) || hash_file('sha256', $copy) !== $sha256 || !@chmod($copy, 0640) || !@rename($copy, $target)) {
					@unlink($copy);
					$result['error'] = 'error-asset_store';
					return $result;
				}
			}
			$old = $meta['images'][$item]['file'] ?? null;
			$meta['images'][$item] = [
				'file' => $file,
				'mime' => $mime,
				'size' => (int) filesize($target),
				'width' => (int) $info[0],
				'height' => (int) $info[1],
				'sha256' => $sha256,
				'uploaded' => gmdate('c'),
				'uploaded_by' => $username,
			];
			unset($meta['retired'][$file]);
			if ($old !== null && $old !== $file) {
				$meta['retired'][$old] = gmdate('c');
			}
			if (!self::write_meta($scope, $meta)) {
				$result['error'] = 'error-asset_store';
				return $result;
			}
			self::log('branding_image_saved', $scope, 'item='.$item.' file='.$file, $username);
			$result['apply'] = $this->apply($scope);
			//settings may still point at the old file when they could not be written
			if ($result['apply']['success']) {
				$this->remove_retired($scope);
			}
			return $result;
		}
		finally {
			$this->unlock();
		}
	}

	/**
	 * Removes the image or value of an item of a scope, and its settings.
	 *
	 * @return array ['error' => language key|null, 'apply' => apply result|null]
	 */
	public function remove(string $scope, string $item, string $username): array {
		$result = ['error' => null, 'apply' => null];
		if (self::scope_dir($scope) === null || !isset(self::items[$item])) {
			$result['error'] = 'error-branding_item';
			return $result;
		}
		if (!$this->lock()) {
			$result['error'] = 'error-branding_busy';
			return $result;
		}
		try {
			$meta = self::read_meta($scope);
			if ($meta === null) {
				$result['error'] = 'error-branding_meta';
				return $result;
			}
			if (isset($meta['images'][$item])) {
				$meta['retired'][$meta['images'][$item]['file']] = gmdate('c');
			}
			unset($meta['images'][$item], $meta['values'][$item]);
			if (!self::write_meta($scope, $meta)) {
				$result['error'] = 'error-asset_store';
				return $result;
			}
			self::log('branding_removed', $scope, 'item='.$item, $username);
			$result['apply'] = $this->apply($scope);
			if ($result['apply']['success']) {
				$this->remove_retired($scope);
			}
			return $result;
		}
		finally {
			$this->unlock();
		}
	}

	/**
	 * Sets the text and choice values of a scope: an empty value removes the item.
	 *
	 * @return array ['error' => language key|null, 'apply' => apply result|null]
	 */
	public function save_values(string $scope, array $values, string $username): array {
		$result = ['error' => null, 'apply' => null];
		if (self::scope_dir($scope) === null) {
			$result['error'] = 'error-branding_item';
			return $result;
		}
		$clean = [];
		foreach ($values as $item => $value) {
			if (!is_string($item) || !isset(self::items[$item]) || self::items[$item]['kind'] === 'image' || !is_string($value)) {
				continue;
			}
			$value = trim($value);
			if (self::items[$item]['kind'] === 'size' && strtolower($value) === 'auto') {
				$value = '';
			}
			if ($value !== '' && !self::valid_value($item, $value)) {
				$result['error'] = (self::items[$item]['kind'] === 'size') ? 'error-branding_size' : 'error-branding_text';
				return $result;
			}
			$clean[$item] = $value;
		}
		if (!$this->lock()) {
			$result['error'] = 'error-branding_busy';
			return $result;
		}
		try {
			$meta = self::read_meta($scope);
			if ($meta === null) {
				$result['error'] = 'error-branding_meta';
				return $result;
			}
			foreach ($clean as $item => $value) {
				if ($value === '') {
					unset($meta['values'][$item]);
				}
				else {
					$meta['values'][$item] = $value;
				}
			}
			if (!self::write_meta($scope, $meta)) {
				$result['error'] = 'error-asset_store';
				return $result;
			}
			self::log('branding_values_saved', $scope, 'items='.implode(',', array_keys($clean)), $username);
			$result['apply'] = $this->apply($scope);
			return $result;
		}
		finally {
			$this->unlock();
		}
	}

	/**
	 * Deletes retired files of a scope: those retired longer than the grace period, and at once all but the
	 * newest retired_keep of each item, so repeated uploads cannot fill the disk. A file one of our settings
	 * still points at is kept. Also deletes stray temp files.
	 */
	private function remove_retired(string $scope): void {
		$dir = self::scope_dir($scope);
		$meta = self::read_meta($scope);
		if ($dir === null || $meta === null) {
			return;
		}
		$current = [];
		foreach ($meta['images'] as $image) {
			$current[$image['file']] = true;
		}
		$referenced = [];
		if (is_object($this->database)) {
			try {
				$sql = "select branding_value from v_x_provision_overlay_branding_links ";
				$sql .= ($scope === self::global_scope) ? "where domain_uuid is null " : "where domain_uuid = :domain_uuid ";
				foreach ($this->query($sql, self::table($scope)['parameters']) as $row) {
					$referenced[basename((string) $row['branding_value'])] = true;
				}
			}
			catch (Throwable $e) {
				//unknown references: delete nothing
				self::log_error('retired '.$scope, $e->getMessage());
				return;
			}
		}

		//newest first per item
		$retired = $meta['retired'];
		uasort($retired, function ($a, $b) { return strcmp($b, $a); });
		$kept = [];
		$changed = false;
		foreach ($retired as $name => $time) {
			if (isset($current[$name])) {
				unset($meta['retired'][$name]);
				$changed = true;
				continue;
			}
			preg_match(self::file_pattern, $name, $m);
			$item = $m[1] ?? '';
			$kept[$item] = ($kept[$item] ?? 0) + 1;
			$expired = (time() - (int) strtotime($time) >= self::grace_seconds) || $kept[$item] > self::retired_keep;
			if ($expired && !isset($referenced[$name])) {
				@unlink($dir.'/'.$name);
				unset($meta['retired'][$name]);
				$changed = true;
			}
		}
		if ($changed) {
			self::write_meta($scope, $meta);
		}
		foreach (glob($dir.'/.{upload-*,*.tmp}', GLOB_BRACE) ?: [] as $temp) {
			if (is_file($temp) && !is_link($temp) && time() - (int) filemtime($temp) > 3600) {
				@unlink($temp);
			}
		}
	}

	//-------------------------------------------------------------------------
	// settings
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
	 * Every theme subcategory this page manages.
	 */
	public static function all_subcategories(): array {
		$list = [];
		foreach (self::items as $item) {
			foreach ($item['subcategories'] as $subcategory) {
				$list[$subcategory] = true;
			}
		}
		return array_keys($list);
	}

	/**
	 * Table and column names of a scope's settings: default settings for the system, domain settings for a
	 * domain.
	 */
	private static function table(string $scope): array {
		if ($scope === self::global_scope) {
			return ['table' => 'v_default_settings', 'prefix' => 'default_setting', 'where' => '', 'parameters' => []];
		}
		return ['table' => 'v_domain_settings', 'prefix' => 'domain_setting', 'where' => 'and domain_uuid = :domain_uuid ', 'parameters' => ['domain_uuid' => $scope]];
	}

	/**
	 * Works out what each item of a scope gets. Read only.
	 *
	 * Item states: managed (our rows hold the value), none (nothing set here: the system default or upstream
	 * applies), own (a row an admin set wins: sources lists the subcategories), optout (one of our rows was
	 * disabled).
	 *
	 * @return array ['items' => [item => ['state' => string, 'sources' => [subcategory], 'own_rows' => [setting uuid]]], 'plan' => [...]]
	 */
	public function analyze(string $scope, ?array $meta = null): array {
		if (!is_object($this->database) || self::scope_dir($scope) === null) {
			throw new RuntimeException('a database connection and a scope are required');
		}
		$meta = $meta ?? self::read_meta($scope);
		if ($meta === null) {
			throw new RuntimeException('branding metadata of '.$scope.' cannot be read');
		}
		$t = self::table($scope);
		$p = $t['prefix'];
		$subcategories = self::all_subcategories();
		$plan = ['inserts' => [], 'updates' => [], 'deletes' => [], 'drop_links' => []];

		//rows of the scope for the managed subcategories, and our links
		[$in, $parameters] = self::in_list('s', $subcategories);
		$sql = "select ".$p."_uuid as uuid, ".$p."_subcategory as subcategory, ".$p."_value as value, ".$p."_enabled as enabled ";
		$sql .= "from ".$t['table']." ";
		$sql .= "where ".$p."_category = :category ";
		$sql .= "and ".$p."_subcategory in (".$in.") ";
		$sql .= $t['where'];
		$rows = [];
		foreach ($this->query($sql, $parameters + ['category' => self::setting_category] + $t['parameters']) as $row) {
			$rows[$row['uuid']] = $row;
		}
		$sql = "select branding_link_uuid, setting_uuid, branding_subcategory, branding_value from v_x_provision_overlay_branding_links ";
		$sql .= ($scope === self::global_scope) ? "where domain_uuid is null " : "where domain_uuid = :domain_uuid ";
		$links = [];
		foreach ($this->query($sql, $t['parameters']) as $link) {
			$row = $rows[$link['setting_uuid']] ?? null;
			//a row deleted, or changed by an admin, is no longer ours
			if ($row === null || $row['subcategory'] !== $link['branding_subcategory'] || (string) $row['value'] !== (string) $link['branding_value']) {
				$plan['drop_links'][] = $link['branding_link_uuid'];
				continue;
			}
			$links[$link['branding_subcategory']][] = $link + ['enabled' => self::bool_value($row['enabled'])];
		}

		//desired value per subcategory
		$desired = [];
		foreach (self::items as $item => $definition) {
			$value = null;
			if ($definition['kind'] === 'image' && isset($meta['images'][$item])) {
				$value = self::url($scope, $meta['images'][$item]['file']);
			}
			elseif ($definition['kind'] !== 'image' && isset($meta['values'][$item])) {
				$value = $meta['values'][$item];
			}
			foreach ($definition['subcategories'] as $subcategory) {
				$desired[$subcategory] = ['item' => $item, 'value' => $value];
			}
		}

		//per item: own if any of its subcategories has an enabled row that is not ours (written or skipped together)
		$ours = [];
		foreach ($links as $subcategory => $entries) {
			foreach ($entries as $link) {
				$ours[$link['setting_uuid']] = true;
			}
		}
		$items = [];
		foreach (self::items as $item => $definition) {
			$sources = [];
			$own_rows = [];
			$optout = false;
			foreach ($definition['subcategories'] as $subcategory) {
				foreach ($rows as $uuid => $row) {
					if ($row['subcategory'] === $subcategory && self::bool_value($row['enabled']) && !isset($ours[$uuid])) {
						$sources[$subcategory] = $subcategory;
						$own_rows[] = (string) $uuid;
					}
				}
				foreach ($links[$subcategory] ?? [] as $link) {
					if (!$link['enabled']) {
						$optout = true;
					}
				}
			}
			$has_value = ($desired[$definition['subcategories'][0]]['value'] !== null);
			if ($optout) {
				$state = 'optout';
			}
			elseif (!empty($sources)) {
				$state = 'own';
			}
			elseif (!$has_value) {
				$state = 'none';
			}
			else {
				$state = 'managed';
			}
			$items[$item] = ['state' => $state, 'sources' => array_values($sources), 'own_rows' => $own_rows];
		}

		//diff our rows against the desired ones
		foreach ($desired as $subcategory => $want) {
			$state = $items[$want['item']]['state'];
			$entries = $links[$subcategory] ?? [];
			//duplicates are collapsed; a disabled row (opt-out) is kept first
			usort($entries, function ($a, $b) { return (int) $a['enabled'] <=> (int) $b['enabled']; });
			$keep = array_shift($entries);
			foreach ($entries as $extra) {
				if ($extra['enabled']) {
					$plan['deletes'][] = $extra;
				}
			}
			if ($keep !== null && !$keep['enabled']) {
				continue;
			}
			if ($state === 'managed') {
				if ($keep === null) {
					$plan['inserts'][] = ['subcategory' => $subcategory, 'value' => $want['value']];
				}
				elseif ((string) $keep['branding_value'] !== $want['value']) {
					$plan['updates'][] = $keep + ['new_value' => $want['value']];
				}
			}
			elseif ($keep !== null) {
				$plan['deletes'][] = $keep;
			}
		}
		return ['items' => $items, 'plan' => $plan];
	}

	/**
	 * Writes the settings of a scope from its metadata. Updates and deletes are guarded by the value written
	 * and the row being enabled, so a row an admin changed or disabled meanwhile is left alone.
	 *
	 * $disable: setting uuids of rows an admin set (take_over()), disabled in the same transaction first, so
	 * either they are disabled and ours written, or nothing changes.
	 *
	 * @return array ['success' => bool, 'error' => string, 'items' => [item => state], 'disabled' => int]
	 */
	public function apply(string $scope, array $disable = []): array {
		$result = ['success' => false, 'error' => '', 'items' => [], 'disabled' => 0];
		try {
			if (!is_object($this->database) || self::scope_dir($scope) === null) {
				throw new RuntimeException('a database connection and a scope are required');
			}
			$t = self::table($scope);
			$p = $t['prefix'];
			$domain_uuid = ($scope === self::global_scope) ? null : $scope;

			//use the PDO handle of database::execute()
			$this->query("select 1");
			$pdo = $this->database->db;
			if (!($pdo instanceof PDO)) {
				throw new RuntimeException('database not connected');
			}
			$pdo->beginTransaction();
			try {
				if (!empty($disable)) {
					[$in, $parameters] = self::in_list('u', $disable);
					$sql = "update ".$t['table']." set ".$p."_enabled = false, update_date = now() ";
					$sql .= "where ".$p."_uuid in (".$in.") ";
					$sql .= "and ".$p."_category = :category ";
					$sql .= "and ".$p."_enabled = true ";
					$sql .= $t['where'];
					$sql .= "returning ".$p."_uuid ";
					$result['disabled'] = count($this->query($sql, $parameters + ['category' => self::setting_category] + $t['parameters']));
				}
				$analysis = $this->analyze($scope);
				$plan = $analysis['plan'];
				foreach ($plan['drop_links'] as $link_uuid) {
					$this->query("delete from v_x_provision_overlay_branding_links where branding_link_uuid = :uuid ", ['uuid' => $link_uuid]);
				}
				foreach ($plan['deletes'] as $link) {
					$sql = "delete from ".$t['table']." where ".$p."_uuid = :uuid and ".$p."_value = :value and ".$p."_enabled = true ";
					$this->query($sql, ['uuid' => $link['setting_uuid'], 'value' => $link['branding_value']]);
					$this->query("delete from v_x_provision_overlay_branding_links where branding_link_uuid = :uuid ", ['uuid' => $link['branding_link_uuid']]);
				}
				foreach ($plan['updates'] as $link) {
					$sql = "update ".$t['table']." set ".$p."_value = :new_value, update_date = now() ";
					$sql .= "where ".$p."_uuid = :uuid and ".$p."_value = :value and ".$p."_enabled = true ";
					$sql .= "returning ".$p."_uuid ";
					$updated = $this->query($sql, ['new_value' => $link['new_value'], 'uuid' => $link['setting_uuid'], 'value' => $link['branding_value']]);
					if (!empty($updated)) {
						$this->query("update v_x_provision_overlay_branding_links set branding_value = :value, update_date = now() where branding_link_uuid = :uuid ",
							['value' => $link['new_value'], 'uuid' => $link['branding_link_uuid']]);
					}
				}
				foreach ($plan['inserts'] as $insert) {
					$setting_uuid = uuid();
					$columns = $p."_uuid, ".($domain_uuid !== null ? "domain_uuid, " : "").$p."_category, ".$p."_subcategory, ".$p."_name, ".$p."_value, ".$p."_enabled, ".$p."_description, insert_date";
					$values = ":uuid, ".($domain_uuid !== null ? ":domain_uuid, " : "").":category, :subcategory, 'text', :value, true, :description, now()";
					$parameters = ['uuid' => $setting_uuid, 'category' => self::setting_category, 'subcategory' => $insert['subcategory'], 'value' => $insert['value'], 'description' => self::setting_description];
					if ($domain_uuid !== null) {
						$parameters['domain_uuid'] = $domain_uuid;
					}
					$this->query("insert into ".$t['table']." (".$columns.") values (".$values.") ", $parameters);
					$this->query("insert into v_x_provision_overlay_branding_links (branding_link_uuid, domain_uuid, setting_uuid, branding_subcategory, branding_value, insert_date) values (:link_uuid, :domain_uuid, :setting_uuid, :subcategory, :value, now()) ",
						['link_uuid' => uuid(), 'domain_uuid' => $domain_uuid, 'setting_uuid' => $setting_uuid, 'subcategory' => $insert['subcategory'], 'value' => $insert['value']]);
				}
				$pdo->commit();
			}
			catch (Throwable $e) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}
				throw $e;
			}

			//upstream caches settings in APCu without expiry
			if (class_exists('settings') && method_exists('settings', 'clear_cache')) {
				settings::clear_cache();
			}
			foreach ($analysis['items'] as $item => $row) {
				$result['items'][$item] = $row['state'];
			}
			$result['success'] = true;
		}
		catch (Throwable $e) {
			$result['error'] = $e->getMessage();
			self::log_error('apply '.$scope, $e->getMessage());
		}
		return $result;
	}

	/**
	 * Disables the rows an admin set for an item in a scope (state own), so ours are written. Upstream seeds
	 * an enabled default setting theme > title, so the system title needs this once. The rows are kept,
	 * disabled: enabling one again in Default or Domain Settings gives the admin's value back.
	 *
	 * @return array ['error' => language key|null, 'apply' => apply result|null]
	 */
	public function take_over(string $scope, string $item, string $username): array {
		$result = ['error' => null, 'apply' => null];
		if (self::scope_dir($scope) === null || !isset(self::items[$item])) {
			$result['error'] = 'error-branding_item';
			return $result;
		}
		if (!$this->lock()) {
			$result['error'] = 'error-branding_busy';
			return $result;
		}
		try {
			//exactly the rows that make the item own, including one of ours an admin changed (its link is
			//dropped by the same apply)
			$analysis = $this->analyze($scope);
			$rows = (($analysis['items'][$item]['state'] ?? '') === 'own') ? ($analysis['items'][$item]['own_rows'] ?? []) : [];
			$result['apply'] = $this->apply($scope, $rows);
			if (!$result['apply']['success']) {
				$result['error'] = 'error-branding_apply';
				return $result;
			}
			self::log('branding_take_over', $scope, 'item='.$item.' disabled='.$result['apply']['disabled'], $username);
			return $result;
		}
		catch (Throwable $e) {
			self::log_error('take over '.$scope, $e->getMessage());
			$result['error'] = 'error-branding_apply';
			return $result;
		}
		finally {
			$this->unlock();
		}
	}

	//-------------------------------------------------------------------------
	// logging
	//-------------------------------------------------------------------------

	/**
	 * Logs an error detail to syslog; pages show a generic message instead.
	 */
	public static function log_error(string $context, string $detail): void {
		openlog('FusionPBX', LOG_PID, LOG_LOCAL0);
		syslog(LOG_ERR, 'x_provision_overlay: branding: '.$context.': '.preg_replace('/[\r\n]+/', ' ', $detail));
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
