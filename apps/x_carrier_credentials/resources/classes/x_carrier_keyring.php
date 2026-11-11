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
 * Master key access and sealing for the carrier credential store.
 *
 * Key files: <key_dir>/<key_id>.key, 32 random bytes base64 on one line,
 * root:www-data 0640. <key_dir>/active is a symlink to the active key file.
 * Cipher: sodium_crypto_secretbox (XSalsa20-Poly1305), fresh 24-byte nonce,
 * stored as v1:<key_id>:base64(nonce || ciphertext). The row uuid is prepended
 * to the plaintext before sealing and checked on open, binding a ciphertext to
 * its row.
 *
 * All key reads go through key(), so a privileged helper can replace it later.
 * Error messages never contain key or secret material.
 */
class x_carrier_keyring {

	const default_key_dir = '/etc/fusionpbx/x_carriers/keys';
	const format_version = 'v1';
	const key_id_pattern = '/^k[0-9]{8}T[0-9]{4}([0-9]{2})?Z$/';

	/**
	 * @var array key_id => raw key, per request
	 */
	private static $keys = [];

	/**
	 * @var string|null key directory set by the CLI when FusionPBX is not bootstrapped
	 */
	private static $key_dir_override = null;

	public static function set_key_dir(string $dir): void {
		$dir = rtrim(trim($dir), '/');
		self::$key_dir_override = $dir !== '' ? $dir : self::default_key_dir;
		self::$keys = [];
	}

	/**
	 * Fails closed when the sodium extension is missing.
	 */
	public static function require_sodium(): void {
		if (!function_exists('sodium_crypto_secretbox') || !defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')) {
			throw new RuntimeException('The PHP sodium extension is not available.');
		}
	}

	/**
	 * Key directory: config.conf key x_carriers.key_dir, else the default.
	 */
	public static function key_dir(): string {
		if (self::$key_dir_override !== null) {
			return self::$key_dir_override;
		}
		$dir = '';
		if (class_exists('config')) {
			try {
				$config = config::load();
				$dir = (string) $config->get('x_carriers.key_dir', '');
			}
			catch (Throwable $e) {
				$dir = '';
			}
		}
		$dir = rtrim(trim($dir), '/');
		return $dir !== '' ? $dir : self::default_key_dir;
	}

	public static function valid_key_id(string $key_id): bool {
		return preg_match(self::key_id_pattern, $key_id) === 1;
	}

	/**
	 * Returns the active key id from the active symlink.
	 */
	public static function active_key_id(): string {
		$link = self::key_dir().'/active';
		if (!is_link($link)) {
			throw new RuntimeException('The active carrier key link is missing.');
		}
		$target = readlink($link);
		if ($target === false) {
			throw new RuntimeException('The active carrier key link cannot be read.');
		}
		$key_id = basename($target, '.key');
		if (!self::valid_key_id($key_id) || basename($target) !== $key_id.'.key') {
			throw new RuntimeException('The active carrier key link points to an invalid file.');
		}
		return $key_id;
	}

	/**
	 * Returns the raw 32-byte key for a key id. The only function that reads key files.
	 */
	public static function key(string $key_id): string {
		self::require_sodium();
		if (!self::valid_key_id($key_id)) {
			throw new RuntimeException('Invalid carrier key id.');
		}
		if (isset(self::$keys[$key_id])) {
			return self::$keys[$key_id];
		}
		$file = self::key_dir().'/'.$key_id.'.key';
		if (!is_file($file) || !is_readable($file)) {
			throw new RuntimeException('Carrier key '.$key_id.' is missing or unreadable.');
		}
		$encoded = trim((string) file_get_contents($file));
		$raw = base64_decode($encoded, true);
		if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
			throw new RuntimeException('Carrier key '.$key_id.' is malformed.');
		}
		self::$keys[$key_id] = $raw;
		return $raw;
	}

	/**
	 * Returns the key id a sealed value was sealed with, or null for an empty or foreign value.
	 */
	public static function key_id_of(?string $sealed): ?string {
		if (empty($sealed)) {
			return null;
		}
		$parts = explode(':', $sealed, 3);
		if (count($parts) !== 3 || $parts[0] !== self::format_version || !self::valid_key_id($parts[1])) {
			return null;
		}
		return $parts[1];
	}

	/**
	 * Seals a secret for a row with the active key (or the given key).
	 */
	public static function seal(x_carrier_secret $secret, string $row_uuid, ?string $key_id = null): string {
		self::require_sodium();
		if (!is_uuid($row_uuid)) {
			throw new InvalidArgumentException('Invalid row uuid.');
		}
		$key_id = $key_id ?? self::active_key_id();
		$key = self::key($key_id);
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$plaintext = strtolower($row_uuid).$secret->reveal();
		$cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
		sodium_memzero($plaintext);
		return self::format_version.':'.$key_id.':'.base64_encode($nonce.$cipher);
	}

	/**
	 * Opens a sealed value for a row. Throws if the key is missing, the value
	 * was tampered with, or it was sealed for a different row.
	 */
	public static function open(string $sealed, string $row_uuid): x_carrier_secret {
		self::require_sodium();
		$key_id = self::key_id_of($sealed);
		if ($key_id === null) {
			throw new RuntimeException('Unrecognised sealed value format.');
		}
		$payload = base64_decode(explode(':', $sealed, 3)[2], true);
		if ($payload === false || strlen($payload) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
			throw new RuntimeException('Sealed value is malformed.');
		}
		$nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$cipher = substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$plaintext = sodium_crypto_secretbox_open($cipher, $nonce, self::key($key_id));
		if ($plaintext === false) {
			throw new RuntimeException('Sealed value failed authentication.');
		}
		$prefix = strtolower($row_uuid);
		if (strlen($plaintext) < strlen($prefix) || !hash_equals($prefix, substr($plaintext, 0, strlen($prefix)))) {
			sodium_memzero($plaintext);
			throw new RuntimeException('Sealed value belongs to a different row.');
		}
		$secret = new x_carrier_secret(substr($plaintext, strlen($prefix)));
		sodium_memzero($plaintext);
		return $secret;
	}

	/**
	 * Short fingerprint so two events can be compared without storing the value.
	 * Keyed BLAKE2b with a subkey derived from the given master key, so a short
	 * secret (a tech prefix) cannot be recovered by brute force from the audit log.
	 * Fingerprints are comparable only under the same key id.
	 */
	public static function fingerprint(x_carrier_secret $secret, ?string $key_id = null): string {
		self::require_sodium();
		$key_id = $key_id ?? self::active_key_id();
		$subkey = sodium_crypto_generichash('x_carriers fingerprint v1', self::key($key_id), SODIUM_CRYPTO_GENERICHASH_KEYBYTES);
		$hash = sodium_crypto_generichash($secret->reveal(), $subkey, 16);
		sodium_memzero($subkey);
		return substr(bin2hex($hash), 0, 8);
	}

	/**
	 * Lists key ids present in the key directory.
	 */
	public static function key_ids(): array {
		$ids = [];
		foreach ((array) glob(self::key_dir().'/k*.key') as $file) {
			$id = basename($file, '.key');
			if (self::valid_key_id($id)) {
				$ids[] = $id;
			}
		}
		sort($ids);
		return $ids;
	}

	/**
	 * Creates a new key file and returns its id. Root only (the key directory
	 * is root:www-data 0750). Does not change the active link.
	 */
	public static function create_key(): string {
		self::require_sodium();
		$dir = self::key_dir();
		if (!is_dir($dir) || !is_writable($dir)) {
			throw new RuntimeException('The carrier key directory is missing or not writable (run as root).');
		}
		$key_id = 'k'.gmdate('Ymd\THi').'Z';
		if (file_exists($dir.'/'.$key_id.'.key')) {
			$key_id = 'k'.gmdate('Ymd\THis').'Z';
		}
		$file = $dir.'/'.$key_id.'.key';
		if (file_exists($file)) {
			throw new RuntimeException('A key with id '.$key_id.' already exists; retry in a second.');
		}
		$key = sodium_crypto_secretbox_keygen();
		$old_umask = umask(0027);
		$handle = fopen($file, 'x');
		if ($handle === false) {
			umask($old_umask);
			throw new RuntimeException('The carrier key file could not be created.');
		}
		fwrite($handle, base64_encode($key));
		fclose($handle);
		umask($old_umask);
		sodium_memzero($key);
		@chown($file, 'root');
		@chgrp($file, 'www-data');
		chmod($file, 0640);
		return $key_id;
	}

	/**
	 * Points the active link at a key id, atomically.
	 */
	public static function activate(string $key_id): void {
		if (!self::valid_key_id($key_id)) {
			throw new InvalidArgumentException('Invalid carrier key id.');
		}
		$dir = self::key_dir();
		if (!is_file($dir.'/'.$key_id.'.key')) {
			throw new RuntimeException('Carrier key '.$key_id.' does not exist.');
		}
		$tmp = $dir.'/.active.'.bin2hex(random_bytes(4));
		if (!symlink($key_id.'.key', $tmp)) {
			throw new RuntimeException('The active key link could not be created.');
		}
		if (!rename($tmp, $dir.'/active')) {
			@unlink($tmp);
			throw new RuntimeException('The active key link could not be replaced.');
		}
	}

	/**
	 * Removes a key file. Never the active key.
	 */
	public static function remove_key(string $key_id): void {
		if (!self::valid_key_id($key_id)) {
			throw new InvalidArgumentException('Invalid carrier key id.');
		}
		if ($key_id === self::active_key_id()) {
			throw new RuntimeException('The active carrier key cannot be removed.');
		}
		$file = self::key_dir().'/'.$key_id.'.key';
		if (is_file($file) && !unlink($file)) {
			throw new RuntimeException('Carrier key '.$key_id.' could not be removed.');
		}
		unset(self::$keys[$key_id]);
	}

}

?>
