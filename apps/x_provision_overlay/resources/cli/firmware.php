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
	Phone firmware CLI (docs/design/provision_firmware.md). Run as www-data:

		runuser -u www-data -- php firmware.php --import <file> [--version <v>] [--models <list>] [--note <text>]
		runuser -u www-data -- php firmware.php --sync                  link the phones of all domains
		runuser -u www-data -- php firmware.php --sync --domain <uuid>  one domain
		runuser -u www-data -- php firmware.php --run-rollouts          move the active rollouts on
		runuser -u www-data -- php firmware.php --cleanup               files of deleted releases, old downloads

	--import takes files of any size (the library page is limited by PHP's upload limits). www-data must be
	able to read the file. x-provision-firmware.timer runs --sync and --run-rollouts every 2 minutes,
	x-provision-firmware.path --sync when the firmware page asks for it; x-provision-overlay.service runs
	--cleanup daily.
*/

//run from the command line only
	if (PHP_SAPI !== 'cli') {
		exit;
	}

//never as root: files would become unreadable to php-fpm and nginx
	if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
		fwrite(STDERR, "x_provision_overlay: do not run as root; use: runuser -u www-data -- php ".__FILE__." --sync\n");
		exit(2);
	}

//parse the command
	$command = $argv[1] ?? '';
	$options = [];
	$valid = in_array($command, ['--import', '--sync', '--run-rollouts', '--cleanup'], true);
	$i = 2;
	if ($command === '--import') {
		$options['file'] = (string) ($argv[2] ?? '');
		$valid = $valid && $options['file'] !== '' && $options['file'][0] !== '-';
		$i = 3;
	}
	while ($valid && $i < count($argv)) {
		$name = $argv[$i];
		$allowed = ($command === '--import') ? ['--version', '--models', '--note'] : (($command === '--sync') ? ['--domain'] : []);
		if (!in_array($name, $allowed, true) || !isset($argv[$i + 1]) || isset($options[substr($name, 2)])) {
			$valid = false;
			break;
		}
		$options[substr($name, 2)] = (string) $argv[$i + 1];
		$i += 2;
	}
	if (!$valid) {
		fwrite(STDERR, "usage: php firmware.php --import <file> [--version <v>] [--models <list>] [--note <text>] | --sync [--domain <uuid>] | --run-rollouts | --cleanup\n");
		exit(2);
	}

//a request from the firmware page is removed first, before anything that can fail, so a failing run is not
//restarted in a loop by x-provision-firmware.path
	$project_root = dirname(__DIR__, 4);
	require_once dirname(__DIR__) . "/classes/provision_overlay.php";
	require_once dirname(__DIR__) . "/classes/provision_firmware.php";
	if ($command === '--sync' && !isset($options['domain']) && file_exists(provision_firmware::request_path())) {
		if (!@unlink(provision_firmware::request_path()) && file_exists(provision_firmware::request_path())) {
			fwrite(STDERR, "x_provision_overlay: firmware: cannot remove ".provision_firmware::request_path()."; remove it by hand (x-provision-firmware.path restarts this run while it exists)\n");
			exit(1);
		}
	}

//resolve the import path before changing directory
	if ($command === '--import') {
		$real = realpath($options['file']);
		if ($real === false) {
			fwrite(STDERR, "x_provision_overlay: firmware: ".$options['file']." not found or not readable by ".(function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'this user') : 'this user')."\n");
			exit(1);
		}
		$options['file'] = $real;
	}

//the framework and the database
	chdir($project_root);
	if (!is_dir(provision_overlay::state_root)) {
		fwrite(STDERR, "x_provision_overlay: ".provision_overlay::state_root." does not exist; run the installer\n");
		exit(1);
	}
	if (!is_readable('/etc/fusionpbx/config.conf') && !is_readable('/usr/local/etc/fusionpbx/config.conf')) {
		fwrite(STDERR, "x_provision_overlay: the FusionPBX config file is not readable; a database connection is required\n");
		exit(1);
	}
	require_once $project_root . "/resources/require.php";
	$overlay = new provision_overlay(['database' => $database]);
	$firmware = new provision_firmware(['database' => $database, 'overlay' => $overlay]);

//run
	switch ($command) {
		case '--import':
			$result = $firmware->import($options['file'], basename($options['file']), $options['version'] ?? '', $options['models'] ?? '', $options['note'] ?? '', 'cli');
			if ($result['error'] !== null) {
				$messages = [
					'error-firmware_file' => 'the file is empty or cannot be read',
					'error-firmware_zip' => 'unpack the vendor .zip and import the .rom or .bin inside',
					'error-firmware_name' => 'not a Yealink ROM (<...>-<family>.<a>.<b>.<c>.rom) or a Grandstream file (<model>fw.bin)',
					'error-firmware_version' => 'a Grandstream file needs --version (e.g. 1.0.11.79)',
					'error-firmware_version_mismatch' => '--version differs from the version in the file name',
					'error-firmware_models' => 'no valid model in --models',
					'error-firmware_exists' => 'this vendor, family and version is already in the library',
					'error-firmware_store' => 'the file could not be stored (see syslog)',
				];
				fwrite(STDERR, "x_provision_overlay: firmware: import failed: ".($messages[$result['error']] ?? $result['error'])."\n");
				exit(1);
			}
			$release = $firmware->release($result['firmware_uuid']);
			echo "x_provision_overlay: firmware: imported ".$release['firmware_vendor']." family ".$release['firmware_family']." version ".$release['firmware_version']." as ".$result['firmware_uuid']."\n";
			exit(0);

		case '--sync':
			if (isset($options['domain'])) {
				$domain_uuid = strtolower($options['domain']);
				if (!provision_overlay::valid_uuid($domain_uuid)) {
					fwrite(STDERR, "x_provision_overlay: invalid domain uuid\n");
					exit(2);
				}
				$results = [$domain_uuid => $firmware->sync_domain($domain_uuid)];
			}
			else {
				$run = $firmware->sync_all();
				if (!$run['success']) {
					fwrite(STDERR, "x_provision_overlay: firmware: ".$run['error']."\n");
					exit(str_contains($run['error'], 'lock') ? 0 : 1);
				}
				$results = $run['results'];
			}
			$failed = 0;
			$domains = $overlay->get_domains() ?? [];
			foreach ($results as $uuid => $result) {
				$name = $domains[$uuid]['domain_name'] ?? $uuid;
				if (!$result['success']) {
					$failed++;
					fwrite(STDERR, "x_provision_overlay: firmware: ".$name.": error: ".$result['error']."\n");
					continue;
				}
				if ($result['inserted'] + $result['updated'] + $result['deleted'] + $result['adopted'] + $result['dropped'] > 0) {
					echo "x_provision_overlay: firmware: ".$name.": inserted ".$result['inserted'].", updated ".$result['updated'].", deleted ".$result['deleted'].", adopted ".$result['adopted'].", links dropped ".$result['dropped']."\n";
				}
			}
			exit(($failed > 0 && $failed === count($results)) ? 1 : 0);

		case '--run-rollouts':
			$run = $firmware->run_rollouts();
			if (!$run['success']) {
				fwrite(STDERR, "x_provision_overlay: firmware: rollouts: ".$run['error']."\n");
				exit(1);
			}
			if ($run['changed'] > 0) {
				echo "x_provision_overlay: firmware: rollouts: ".$run['changed']." device(s) moved on\n";
			}
			exit(0);

		case '--cleanup':
			$result = $firmware->cleanup();
			foreach ($result['errors'] as $error) {
				fwrite(STDERR, "x_provision_overlay: firmware: error: ".$error."\n");
			}
			echo "x_provision_overlay: firmware: cleanup removed ".$result['files']." deleted release dir(s), ".$result['rows']." row(s)\n";
			exit(empty($result['errors']) ? 0 : 1);
	}

?>
