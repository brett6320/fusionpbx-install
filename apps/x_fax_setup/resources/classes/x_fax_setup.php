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

//define the x_fax_setup class
class x_fax_setup {

	//upstream fax app uuid, used by the fax class for database logging
	const fax_app_uuid = '24108154-4ac3-1db6-1551-4731703a4440';

	//storage base directory, outside the webroot
	const storage_base = '/var/lib/fusionpbx/x_fax_setup';

	//logo limits
	const logo_max_bytes = 1048576;
	const logo_max_width = 2000;
	const logo_max_height = 2000;
	const logo_max_pixels = 4000000;

	/**
	 * @var string domain uuid, always the session domain
	 */
	private $domain_uuid;

	/**
	 * @var string domain name
	 */
	private $domain_name;

	/**
	 * @var database
	 */
	private $database;

	/**
	 * @var settings
	 */
	private $settings;

	/**
	 * Initializes the object. The domain is always taken from the session.
	 */
	public function __construct(array $setting_array = []) {
		$this->domain_uuid = $setting_array['domain_uuid'] ?? $_SESSION['domain_uuid'] ?? '';
		$this->domain_name = $setting_array['domain_name'] ?? $_SESSION['domain_name'] ?? '';
		$this->database = $setting_array['database'] ?? database::new();
		$this->settings = $setting_array['settings'] ?? $GLOBALS['settings'];
	}

	/**
	 * Returns the fax servers of the domain.
	 */
	public function get_faxes() {
		$sql = "select fax_uuid, fax_name, fax_extension, fax_email from v_fax ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "order by fax_extension asc ";
		$parameters['domain_uuid'] = $this->domain_uuid;
		$result = $this->database->select($sql, $parameters, 'all');
		return is_array($result) ? $result : [];
	}

	/**
	 * Checks whether a number collides with something in the domain.
	 */
	public function number_in_use(string $number): bool {
		$checks = [];
		$checks[] = "select count(*) from v_extensions where domain_uuid = :domain_uuid and (extension = :number or number_alias = :number) ";
		$checks[] = "select count(*) from v_destinations where domain_uuid = :domain_uuid and destination_number = :number ";
		$checks[] = "select count(*) from v_fax where domain_uuid = :domain_uuid and (fax_extension = :number or fax_destination_number = :number) ";
		$checks[] = "select count(*) from v_dialplans where domain_uuid = :domain_uuid and dialplan_number = :number ";
		foreach ($checks as $sql) {
			$parameters['domain_uuid'] = $this->domain_uuid;
			$parameters['number'] = $number;
			if ($this->database->select($sql, $parameters, 'column') > 0) {
				return true;
			}
			unset($parameters);
		}
		return false;
	}

	/**
	 * Creates a fax server using the same save path as app/fax/fax_edit.php (action = add).
	 *
	 * @return string|null null on success, otherwise the language key of the error
	 */
	public function create_fax(string $fax_extension, string $fax_name, string $fax_email) {

		//sanitize as fax_edit.php does
		$fax_extension = substr(preg_replace('#[^0-9]#', '', $fax_extension), 0, 15);
		$fax_name = str_replace(" ", "-", substr($fax_name, 0, 30));
		$fax_email = str_replace(" ", "", $fax_email);
		$fax_destination_number = $fax_extension;

		//a domain with a fax server is not set up again
		if (count($this->get_faxes()) > 0) {
			return 'message-fax_exists';
		}

		//set the fax directories, same as fax_edit.php
		$fax_dir = $this->settings->get('switch', 'storage').'/fax/'.$this->domain_name;
		$dirs = [
			$this->settings->get('switch', 'storage'),
			$fax_dir.'/'.$fax_extension,
			$fax_dir.'/'.$fax_extension.'/inbox',
			$fax_dir.'/'.$fax_extension.'/sent',
			$fax_dir.'/'.$fax_extension.'/temp',
		];
		foreach ($dirs as $dir) {
			if (!is_dir($dir)) {
				mkdir($dir, 0770, true);
			}
			if (!is_dir($dir)) {
				return 'error-fax_dir';
			}
		}

		//prepare the unique identifiers
		$fax_uuid = uuid();
		$dialplan_uuid = uuid();

		//build the fax array, fields as fax_edit.php writes them on add
		$array['fax'][0]['fax_uuid'] = $fax_uuid;
		$array['fax'][0]['dialplan_uuid'] = $dialplan_uuid;
		$array['fax'][0]['domain_uuid'] = $this->domain_uuid;
		$array['fax'][0]['fax_extension'] = $fax_extension;
		$array['fax'][0]['accountcode'] = get_accountcode();
		$array['fax'][0]['fax_destination_number'] = $fax_destination_number;
		$array['fax'][0]['fax_prefix'] = '';
		$array['fax'][0]['fax_name'] = $fax_name;
		$array['fax'][0]['fax_email'] = $fax_email;
		$array['fax'][0]['fax_file'] = ''; //form default (attach)
		$array['fax'][0]['fax_email_confirmation'] = '';
		$array['fax'][0]['fax_caller_id_name'] = '';
		$array['fax'][0]['fax_caller_id_number'] = '';
		$array['fax'][0]['fax_toll_allow'] = '';
		$array['fax'][0]['fax_send_channels'] = 10; //form default for a new fax server
		$array['fax'][0]['fax_description'] = '';

		//assign temp permission
		$p = permissions::new();
		$p->add('fax_add', 'temp');

		//execute
		$saved = $this->database->save($array);
		unset($array);

		//revoke temp permissions
		$p->delete('fax_add', 'temp');

		//clear the destinations session array
		if (isset($_SESSION['destinations']['array'])) {
			unset($_SESSION['destinations']['array']);
		}

		//the row must exist before the dialplan is created
		$sql = "select dialplan_uuid from v_fax ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and fax_uuid = :fax_uuid ";
		$parameters['domain_uuid'] = $this->domain_uuid;
		$parameters['fax_uuid'] = $fax_uuid;
		$dialplan_uuid = $this->database->select($sql, $parameters, 'column');
		unset($sql, $parameters);
		if ($saved === false || !is_uuid($dialplan_uuid)) {
			return 'error-fax_save';
		}

		//dialplan add, the fax class also clears the dialplan cache
		$c = new fax;
		$c->domain_uuid = $this->domain_uuid;
		$c->dialplan_uuid = $dialplan_uuid;
		$c->fax_name = $fax_name;
		$c->fax_uuid = $fax_uuid;
		$c->fax_extension = $fax_extension;
		$c->fax_forward_number = '';
		$c->destination_number = $fax_destination_number;
		$c->fax_description = '';
		$c->dialplan();

		return null;
	}

	/**
	 * Logo directory for the domain.
	 */
	public function logo_dir(): string {
		return self::storage_base.'/'.$this->domain_uuid;
	}

	/**
	 * Logo file path on the filesystem.
	 */
	public function logo_file(): string {
		return $this->logo_dir().'/cover_logo.png';
	}

	/**
	 * Value stored in the fax/cover_logo domain setting.
	 *
	 * fax_send.php prefixes any path starting with "/" that is not already under the
	 * webroot (dirname(__DIR__, 2)) with the webroot. To reach a directory outside the
	 * webroot the value therefore starts with the webroot and climbs out with "..".
	 * file_exists() and TCPDF resolve this to the real file.
	 */
	public function logo_setting_value(): string {
		$webroot = dirname(__DIR__, 4);
		$depth = count(array_filter(explode('/', $webroot), 'strlen'));
		return $webroot.str_repeat('/..', $depth).$this->logo_file();
	}

	/**
	 * Returns the domain setting row for fax/cover_logo, or null.
	 */
	public function get_logo_setting() {
		$sql = "select domain_setting_uuid, domain_setting_value from v_domain_settings ";
		$sql .= "where domain_uuid = :domain_uuid ";
		$sql .= "and domain_setting_category = 'fax' ";
		$sql .= "and domain_setting_subcategory = 'cover_logo' ";
		$sql .= "order by domain_setting_uuid asc ";
		$parameters['domain_uuid'] = $this->domain_uuid;
		$rows = $this->database->select($sql, $parameters, 'all');
		return (is_array($rows) && count($rows) > 0) ? $rows : null;
	}

	/**
	 * True when the domain has a stored logo file.
	 */
	public function has_logo(): bool {
		return is_file($this->logo_file());
	}

	/**
	 * Validates, re-encodes and stores an uploaded logo, then saves the domain setting.
	 *
	 * @return string|null null on success, otherwise the language key of the error
	 */
	public function save_logo(array $file) {

		//GD is required
		if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
			return 'error-logo_no_gd';
		}

		//upload checks
		if (!isset($file['error']) || is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
			if (isset($file['error']) && !is_array($file['error']) && ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)) {
				return 'error-logo_too_large';
			}
			return 'error-logo_upload';
		}
		if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
			return 'error-logo_upload';
		}
		$size = filesize($file['tmp_name']);
		if ($size === false || $size < 1) {
			return 'error-logo_upload';
		}
		if ($size > self::logo_max_bytes) {
			return 'error-logo_too_large';
		}

		//detect the type from the content, never from the client
		$finfo = new finfo(FILEINFO_MIME_TYPE);
		$mime = $finfo->file($file['tmp_name']);
		if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
			return 'error-logo_type';
		}

		//check the dimensions before decoding
		$info = @getimagesize($file['tmp_name']);
		if (!is_array($info) || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
			return 'error-logo_type';
		}
		$width = (int) $info[0];
		$height = (int) $info[1];
		if ($width < 1 || $height < 1 || $width > self::logo_max_width || $height > self::logo_max_height || ($width * $height) > self::logo_max_pixels) {
			return 'error-logo_dimensions';
		}

		//decode with GD
		$data = file_get_contents($file['tmp_name']);
		$source = ($data !== false) ? @imagecreatefromstring($data) : false;
		unset($data);
		if (!$source) {
			return 'error-logo_process';
		}

		//re-encode as a flat truecolor PNG on a white background (cover sheets are white, avoids alpha handling in the PDF library)
		$image = imagecreatetruecolor($width, $height);
		if (!$image) {
			imagedestroy($source);
			return 'error-logo_process';
		}
		imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
		imagecopy($image, $source, 0, 0, 0, 0, $width, $height);
		imagedestroy($source);

		//make sure the domain directory exists
		$dir = $this->logo_dir();
		if (!is_dir($dir)) {
			if (!is_dir(self::storage_base)) {
				@mkdir(self::storage_base, 0750, true);
			}
			@mkdir($dir, 0750, true);
		}
		if (!is_dir($dir) || !is_writable($dir)) {
			imagedestroy($image);
			return 'error-logo_store';
		}

		//write to a temp file then rename into place
		$temp = $dir.'/.cover_logo.'.bin2hex(random_bytes(6)).'.tmp';
		$written = imagepng($image, $temp, 6);
		imagedestroy($image);
		if (!$written || !@chmod($temp, 0640) || !@rename($temp, $this->logo_file())) {
			@unlink($temp);
			return 'error-logo_store';
		}
		clearstatcache();

		//insert or update the domain setting
		$rows = $this->get_logo_setting();
		if (is_array($rows)) {
			$array['domain_settings'][0]['domain_setting_uuid'] = $rows[0]['domain_setting_uuid'];
			$permission = 'domain_setting_edit';
		}
		else {
			$array['domain_settings'][0]['domain_setting_uuid'] = uuid();
			$permission = 'domain_setting_add';
		}
		$array['domain_settings'][0]['domain_uuid'] = $this->domain_uuid;
		$array['domain_settings'][0]['domain_setting_category'] = 'fax';
		$array['domain_settings'][0]['domain_setting_subcategory'] = 'cover_logo';
		$array['domain_settings'][0]['domain_setting_name'] = 'text';
		$array['domain_settings'][0]['domain_setting_value'] = $this->logo_setting_value();
		$array['domain_settings'][0]['domain_setting_order'] = 0;
		$array['domain_settings'][0]['domain_setting_enabled'] = true;
		$array['domain_settings'][0]['domain_setting_description'] = 'Fax cover sheet logo (managed by Fax Setup)';

		$p = permissions::new();
		$p->add($permission, 'temp');
		$saved = $this->database->save($array);
		unset($array);
		$p->delete($permission, 'temp');

		//clear the settings cache, as domain_setting_edit.php does
		settings::clear_cache();

		return ($saved === false) ? 'error-logo_setting' : null;
	}

	/**
	 * Removes the logo file and the domain setting, if the setting is the one managed here.
	 *
	 * @return string|null null on success, otherwise the language key of the error
	 */
	public function remove_logo() {

		$rows = $this->get_logo_setting();
		if (is_array($rows)) {
			foreach ($rows as $row) {
				if ($row['domain_setting_value'] !== $this->logo_setting_value()) {
					return 'message-logo_not_removed';
				}
			}
			$x = 0;
			foreach ($rows as $row) {
				$array['domain_settings'][$x]['domain_setting_uuid'] = $row['domain_setting_uuid'];
				$array['domain_settings'][$x]['domain_uuid'] = $this->domain_uuid;
				$x++;
			}
			$p = permissions::new();
			$p->add('domain_setting_delete', 'temp');
			$this->database->delete($array);
			unset($array);
			$p->delete('domain_setting_delete', 'temp');
			settings::clear_cache();
		}

		//delete the file
		if (is_file($this->logo_file())) {
			@unlink($this->logo_file());
		}
		return null;
	}

}

?>
