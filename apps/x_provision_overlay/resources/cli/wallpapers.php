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
	Phone wallpaper CLI (docs/design/provision_wallpapers.md). Run as www-data:

		runuser -u www-data -- php wallpapers.php --sync                  link the phones of all domains
		runuser -u www-data -- php wallpapers.php --sync --domain <uuid>  one domain
		runuser -u www-data -- php wallpapers.php --cleanup               delete replaced files after the grace period
		                                                                  and the assets of deleted domains

	x-provision-wallpapers.timer runs --sync every 2 minutes, x-provision-wallpapers.path when the
	wallpapers page asks for it; x-provision-overlay.service runs --cleanup daily.
*/

//run from the command line only
	if (PHP_SAPI !== 'cli') {
		exit;
	}

//never as root: files would become unreadable to php-fpm
	if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
		fwrite(STDERR, "x_provision_overlay: do not run as root; use: runuser -u www-data -- php ".__FILE__." --sync\n");
		exit(2);
	}

//parse the command
	$command = $argv[1] ?? '';
	$domain_uuid = '';
	if ($command === '--sync' && ($argv[2] ?? '') === '--domain') {
		$domain_uuid = strtolower((string) ($argv[3] ?? ''));
	}
	$valid = ($command === '--sync' && (count($argv) === 2 || (count($argv) === 4 && $domain_uuid !== '')))
		|| ($command === '--cleanup' && count($argv) === 2);
	if (!$valid) {
		fwrite(STDERR, "usage: php wallpapers.php --sync [--domain <uuid>] | --cleanup\n");
		exit(2);
	}

//a request from the wallpapers page is removed first, before anything that can fail, so a failing run
//is not restarted in a loop by x-provision-wallpapers.path
	$project_root = dirname(__DIR__, 4);
	require_once dirname(__DIR__) . "/classes/provision_overlay.php";
	require_once dirname(__DIR__) . "/classes/provision_wallpapers.php";
	if ($command === '--sync' && $domain_uuid === '' && file_exists(provision_wallpapers::request_path())) {
		@unlink(provision_wallpapers::request_path());
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
	$wallpapers = new provision_wallpapers(['database' => $database, 'overlay' => $overlay]);

//run
	switch ($command) {
		case '--sync':
			if ($domain_uuid !== '') {
				if (!provision_overlay::valid_uuid($domain_uuid)) {
					fwrite(STDERR, "x_provision_overlay: invalid domain uuid\n");
					exit(2);
				}
				$results = [$domain_uuid => $wallpapers->sync_domain($domain_uuid)];
			}
			else {
				$results = $wallpapers->sync_all();
			}
			$failed = 0;
			$domains = $overlay->get_domains() ?? [];
			foreach ($results as $uuid => $result) {
				$name = $domains[$uuid]['domain_name'] ?? $uuid;
				if (!$result['success']) {
					$failed++;
					fwrite(STDERR, "x_provision_overlay: wallpapers: ".$name.": error: ".$result['error']."\n");
					continue;
				}
				//quiet when nothing changed: the timer runs every 2 minutes
				if ($result['inserted'] + $result['updated'] + $result['deleted'] + $result['dropped'] > 0) {
					echo "x_provision_overlay: wallpapers: ".$name.": inserted ".$result['inserted'].", updated ".$result['updated'].", deleted ".$result['deleted'].", links dropped ".$result['dropped']."\n";
				}
			}
			exit($failed > 0 ? 1 : 0);

		case '--cleanup':
			$result = $wallpapers->cleanup();
			foreach ($result['errors'] as $error) {
				fwrite(STDERR, "x_provision_overlay: wallpapers: error: ".$error."\n");
			}
			echo "x_provision_overlay: wallpapers: cleanup removed ".$result['files']." file(s), ".$result['domains']." deleted domain asset dir(s), ".$result['links']." link(s)\n";
			exit(empty($result['errors']) ? 0 : 1);
	}

?>
