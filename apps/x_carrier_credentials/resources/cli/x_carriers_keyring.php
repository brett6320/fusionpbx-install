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
	Carrier credential key management.

	Usage: php x_carriers_keyring.php <command> [--all]

	  generate               (root) create the first key and the active link if no active key exists
	  rotate                 (root) create a new key and make it active; then run reencrypt as www-data
	  reencrypt              (www-data) re-seal every stored secret under the active key, in one transaction
	  check                  (www-data) verify the active key and every key referenced by stored secrets; exit 1 on failure
	  prune                  (root) remove key files no stored secret references (never the active key)
	  reseal-from-gateways   (www-data) rebuild sip_digest secrets that cannot be decrypted from v_gateways;
	                         --all re-imports every active sip_digest credential that has a gateway
	  maintenance            (www-data, timer) erase retired secrets past x_carriers.retired_retention_days and
	                         re-push credentials whose validity window opened or closed since their last push

	Restore: restore the database, restore the key directory (from
	/var/backups/x_carriers-keys/), then run "check". A database restored
	without its keys loses API tokens and pending or retired secrets; active SIP
	credentials can be rebuilt from v_gateways with reseal-from-gateways.

	Output never contains key material or secrets.
*/

//command line only
	if (!defined('STDIN')) {
		exit;
	}

//get the command
	$command = $argv[1] ?? 'help';
	$option_all = in_array('--all', $argv, true);

//commands that must work before FusionPBX is configured or without the database
	$standalone = ['help', 'generate', 'rotate'];

	if (in_array($command, $standalone, true)) {
		require_once dirname(__DIR__) . "/classes/x_carrier_secret.php";
		require_once dirname(__DIR__) . "/classes/x_carrier_keyring.php";
		//read x_carriers.key_dir from config.conf without bootstrapping FusionPBX
		$key_dir = '';
		foreach (['/etc/fusionpbx/config.conf', '/usr/local/etc/fusionpbx/config.conf'] as $config_file) {
			if (is_readable($config_file)) {
				foreach (file($config_file, FILE_IGNORE_NEW_LINES) as $line) {
					if (preg_match('/^\s*x_carriers\.key_dir\s*=\s*(.*?)\s*$/', $line, $matches)) {
						$key_dir = trim($matches[1], " \t\"'");
					}
				}
				break;
			}
		}
		x_carrier_keyring::set_key_dir($key_dir);
	}
	else {
		require_once dirname(__DIR__, 4) . "/resources/require.php";
	}

//helpers
	function x_out(string $message): void {
		fwrite(STDOUT, $message."\n");
	}

	function x_fail(string $message, int $code = 1): void {
		fwrite(STDERR, "error: ".$message."\n");
		exit($code);
	}

	function x_is_root(): bool {
		return function_exists('posix_geteuid') ? posix_geteuid() === 0 : false;
	}

	function x_require_root(string $command): void {
		if (!x_is_root()) {
			x_fail($command." must run as root.");
		}
	}

	function x_require_web_user(string $command): void {
		if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
			$user = posix_getpwuid(posix_geteuid());
			if (($user['name'] ?? '') === 'root') {
				x_fail($command." must run as www-data (sudo -u www-data php ...), so files it touches keep their owner.");
			}
		}
	}

	function x_table_ready(): bool {
		global $database;
		return $database->table_exists('v_x_carrier_credentials');
	}

//sodium is required for every command except help
	if ($command !== 'help') {
		try {
			x_carrier_keyring::require_sodium();
		}
		catch (Throwable $e) {
			x_fail($e->getMessage());
		}
	}

//run the command
	switch ($command) {

		case 'generate':
			x_require_root($command);
			try {
				x_carrier_keyring::active_key_id();
				x_out("active key already present, nothing to do");
				exit(0);
			}
			catch (Throwable $e) {
				//no active key yet
			}
			try {
				$key_id = x_carrier_keyring::create_key();
				x_carrier_keyring::activate($key_id);
				x_out("created and activated key ".$key_id);
			}
			catch (Throwable $e) {
				x_fail($e->getMessage());
			}
			break;

		case 'rotate':
			x_require_root($command);
			try {
				$old_key_id = x_carrier_keyring::active_key_id();
			}
			catch (Throwable $e) {
				x_fail("no active key; run generate first");
			}
			try {
				$key_id = x_carrier_keyring::create_key();
				x_carrier_keyring::activate($key_id);
				x_out("active key is now ".$key_id." (was ".$old_key_id.")");
				x_out("next: sudo -u www-data php ".__FILE__." reencrypt");
			}
			catch (Throwable $e) {
				x_fail($e->getMessage());
			}
			break;

		case 'reencrypt':
			x_require_web_user($command);
			if (!x_table_ready()) {
				x_fail("the carrier credential tables do not exist; run upgrade.php --schema");
			}
			try {
				$active = x_carrier_keyring::active_key_id();
				$store = new x_carriers(['all' => true]);
				$sql = "select x_carrier_credential_uuid, domain_uuid, x_carrier_credential_secret, x_carrier_credential_key_id ";
				$sql .= "from v_x_carrier_credentials ";
				$sql .= "where x_carrier_credential_secret is not null and x_carrier_credential_secret <> '' ";
				$rows = $database->select($sql, null, 'all');
				$array = [];
				$counts = [];
				$x = 0;
				foreach ((array) $rows as $row) {
					if (x_carrier_keyring::key_id_of($row['x_carrier_credential_secret']) === $active) {
						continue;
					}
					$secret = x_carrier_keyring::open($row['x_carrier_credential_secret'], $row['x_carrier_credential_uuid']);
					$array['x_carrier_credentials'][$x]['x_carrier_credential_uuid'] = $row['x_carrier_credential_uuid'];
					$array['x_carrier_credentials'][$x]['x_carrier_credential_secret'] = x_carrier_keyring::seal($secret, $row['x_carrier_credential_uuid'], $active);
					$array['x_carrier_credentials'][$x]['x_carrier_credential_key_id'] = $active;
					$secret->wipe();
					$counts[$row['domain_uuid']] = ($counts[$row['domain_uuid']] ?? 0) + 1;
					$x++;
				}
				if ($x > 0) {
					//one save call is one database transaction
					if (!$store->save_rows($array, ['x_carrier_credential_edit'])) {
						//database::save commits the rows that succeeded, so count what is left
						$sql = "select count(*) from v_x_carrier_credentials where x_carrier_credential_secret is not null and x_carrier_credential_secret <> '' and x_carrier_credential_key_id <> :active ";
						$left = (int) $database->select($sql, ['active' => $active], 'column');
						foreach ($counts as $domain_uuid => $count) {
							$store->audit('key_rotate', null, null, ['key_id' => $active, 'attempted' => $count, 'outcome' => 'incomplete'], $domain_uuid);
						}
						x_fail("re-encryption incomplete: ".$left." secret(s) still under an old key; rerun reencrypt");
					}
				}
				unset($array);
				foreach ($counts as $domain_uuid => $count) {
					$store->audit('key_rotate', null, null, ['key_id' => $active, 'reencrypted' => $count], $domain_uuid);
				}
				x_out("re-encrypted ".$x." secret(s) under ".$active);
			}
			catch (Throwable $e) {
				x_fail($e->getMessage());
			}
			break;

		case 'check':
			$failed = false;
			try {
				$active = x_carrier_keyring::active_key_id();
				x_carrier_keyring::key($active);
				x_out("active key ".$active.": ok");
				//round trip
				$probe = new x_carrier_secret(bin2hex(random_bytes(8)));
				$row_uuid = uuid();
				$opened = x_carrier_keyring::open(x_carrier_keyring::seal($probe, $row_uuid, $active), $row_uuid);
				if (!$opened->equals($probe)) {
					throw new RuntimeException('seal/open round trip failed');
				}
				$probe->wipe();
				$opened->wipe();
			}
			catch (Throwable $e) {
				fwrite(STDERR, "error: ".$e->getMessage()."\n");
				$failed = true;
			}
			if (x_table_ready()) {
				$sql = "select x_carrier_credential_key_id, count(*) as total, min(cast(x_carrier_credential_uuid as text)) as sample ";
				$sql .= "from v_x_carrier_credentials ";
				$sql .= "where x_carrier_credential_secret is not null and x_carrier_credential_secret <> '' ";
				$sql .= "group by x_carrier_credential_key_id ";
				foreach ((array) $database->select($sql, null, 'all') as $row) {
					$key_id = (string) $row['x_carrier_credential_key_id'];
					try {
						x_carrier_keyring::key($key_id);
						//decrypt one row per key to prove the key matches
						$sql = "select x_carrier_credential_uuid, x_carrier_credential_secret from v_x_carrier_credentials where x_carrier_credential_uuid = :uuid ";
						$sample = $database->select($sql, ['uuid' => $row['sample']], 'row');
						if (!empty($sample['x_carrier_credential_secret'])) {
							x_carrier_keyring::open($sample['x_carrier_credential_secret'], $sample['x_carrier_credential_uuid'])->wipe();
						}
						x_out("key ".$key_id.": ok (".$row['total']." secret(s))");
					}
					catch (Throwable $e) {
						fwrite(STDERR, "error: key ".($key_id !== '' ? $key_id : '(none)')." referenced by ".$row['total']." secret(s): ".$e->getMessage()."\n");
						$failed = true;
					}
				}
			}
			else {
				x_out("carrier credential tables not created yet; skipped stored secret check");
			}
			exit($failed ? 1 : 0);

		case 'prune':
			x_require_root($command);
			if (!x_table_ready()) {
				x_fail("the carrier credential tables do not exist");
			}
			try {
				$active = x_carrier_keyring::active_key_id();
				$sql = "select distinct x_carrier_credential_key_id from v_x_carrier_credentials ";
				$sql .= "where x_carrier_credential_secret is not null and x_carrier_credential_secret <> '' ";
				$referenced = [];
				foreach ((array) $database->select($sql, null, 'all') as $row) {
					$referenced[] = (string) $row['x_carrier_credential_key_id'];
				}
				$removed = 0;
				foreach (x_carrier_keyring::key_ids() as $key_id) {
					if ($key_id === $active || in_array($key_id, $referenced, true)) {
						continue;
					}
					x_carrier_keyring::remove_key($key_id);
					x_out("removed unreferenced key ".$key_id);
					$removed++;
				}
				x_out("pruned ".$removed." key(s); copies remain in the key backup directory");
			}
			catch (Throwable $e) {
				x_fail($e->getMessage());
			}
			break;

		case 'reseal-from-gateways':
			x_require_web_user($command);
			if (!x_table_ready()) {
				x_fail("the carrier credential tables do not exist");
			}
			try {
				x_carrier_keyring::active_key_id();
				$store = new x_carriers(['all' => true]);
				$sql = "select x_carrier_credential_uuid from v_x_carrier_credentials ";
				$sql .= "where x_carrier_credential_type = 'sip_digest' and x_carrier_credential_status = 'active' ";
				$resealed = 0;
				$skipped = 0;
				foreach ((array) $database->select($sql, null, 'all') as $row) {
					$credential = $store->credential($row['x_carrier_credential_uuid'], true);
					$readable = true;
					try {
						$store->open_secret($credential)->wipe();
					}
					catch (Throwable $e) {
						$readable = false;
					}
					if ($readable && !$option_all) {
						continue;
					}
					//the first gateway binding holds the copy FreeSWITCH uses
					$password = null;
					foreach ($store->bindings($credential['x_carrier_credential_uuid']) as $binding) {
						if ($binding['x_carrier_binding_type'] === 'gateway' && is_uuid($binding['gateway_uuid'])) {
							$sql = "select password from v_gateways where gateway_uuid = :uuid and domain_uuid = :domain_uuid ";
							$password = $database->select($sql, ['uuid' => $binding['gateway_uuid'], 'domain_uuid' => $credential['domain_uuid']], 'column');
							$gateway_uuid = $binding['gateway_uuid'];
							break;
						}
					}
					if ($password === null || $password === false) {
						$skipped++;
						continue;
					}
					$secret = new x_carrier_secret((string) $password);
					unset($password);
					$store->set_secret($credential, $secret, ['reimported_from_gateway' => $gateway_uuid, 'previous_readable' => $readable]);
					$secret->wipe();
					$resealed++;
				}
				x_out("resealed ".$resealed." credential(s) from v_gateways; ".$skipped." without a gateway skipped");
			}
			catch (Throwable $e) {
				x_fail($e->getMessage());
			}
			break;

		case 'maintenance':
			x_require_web_user($command);
			if (!x_table_ready()) {
				x_out("carrier credential tables not created yet; nothing to do");
				exit(0);
			}
			//never overlap with another run
			$lock = fopen('/run/lock/x_carriers_maintenance.lock', 'c');
			if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
				x_out("another maintenance run is in progress");
				exit(0);
			}
			try {
				$store = new x_carriers(['all' => true]);
				$push = new x_carrier_push($store);

				//erase retired secrets past the retention period
				$days = (int) $settings->get('x_carriers', 'retired_retention_days', '30');
				if ($days < 0) {
					$days = 30;
				}
				$sql = "select x_carrier_credential_uuid, x_carrier_account_uuid, domain_uuid, x_carrier_credential_version from v_x_carrier_credentials ";
				$sql .= "where x_carrier_credential_status = 'retired' ";
				$sql .= "and x_carrier_credential_secret is not null and x_carrier_credential_secret <> '' ";
				$sql .= "and coalesce(x_carrier_credential_retired_date, update_date, insert_date) < now() - interval '".$days." days' ";
				//never erase a secret that a gateway or route still depends on
				$sql .= "and not exists (select 1 from v_x_carrier_bindings as b where b.x_carrier_credential_uuid = v_x_carrier_credentials.x_carrier_credential_uuid) ";
				$erased = 0;
				foreach ((array) $database->select($sql, null, 'all') as $row) {
					$store->erase_secret($row['x_carrier_credential_uuid']);
					$store->audit('retire', $row['x_carrier_account_uuid'], $row['x_carrier_credential_uuid'], [
						'version' => (int) $row['x_carrier_credential_version'], 'secret_erased' => true, 'retention_days' => $days,
					], $row['domain_uuid']);
					$erased++;
				}

				//re-push credentials whose validity window opened or closed after their last push
				$sql = "select x_carrier_credential_uuid from v_x_carrier_credentials ";
				$sql .= "where x_carrier_credential_status in ('active', 'pending') and x_carrier_credential_type <> 'api_token' and ( ";
				$sql .= "(x_carrier_credential_not_after is not null and x_carrier_credential_not_after <= now() ";
				$sql .= "and (x_carrier_credential_last_pushed is null or x_carrier_credential_last_pushed < x_carrier_credential_not_after)) ";
				$sql .= "or (x_carrier_credential_not_before is not null and x_carrier_credential_not_before <= now() ";
				$sql .= "and (x_carrier_credential_last_pushed is null or x_carrier_credential_last_pushed < x_carrier_credential_not_before)) ";
				$sql .= ") ";
				$pushed = 0;
				$failed = 0;
				foreach ((array) $database->select($sql, null, 'all') as $row) {
					//one bad row (for example a missing key) must not block the others
					try {
						$push->push($row['x_carrier_credential_uuid']);
						$pushed++;
					}
					catch (Throwable $e) {
						fwrite(STDERR, "error: credential ".$row['x_carrier_credential_uuid'].": ".$e->getMessage()."\n");
						$failed++;
					}
				}
				x_out("erased ".$erased." retired secret(s); re-pushed ".$pushed." credential(s) for validity windows; ".$failed." failed");
			}
			catch (Throwable $e) {
				x_fail($e->getMessage());
			}
			finally {
				flock($lock, LOCK_UN);
				fclose($lock);
			}
			break;

		case 'help':
		default:
			x_out("usage: php ".basename(__FILE__)." generate|rotate|reencrypt|check|prune|reseal-from-gateways [--all]|maintenance");
			exit($command === 'help' ? 0 : 1);
	}

?>
