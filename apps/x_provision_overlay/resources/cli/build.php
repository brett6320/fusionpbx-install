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
	Provision template overlay CLI. Run as www-data:

		runuser -u www-data -- php build.php --build      regenerate the merged tree, swap current
		runuser -u www-data -- php build.php --status     current build id, upstream commit, counts
		runuser -u www-data -- php build.php --verify     dangling links, orphaned overrides, bad domain names; exit 1 on problems
		runuser -u www-data -- php build.php --rollback   point current at the previous build
		runuser -u www-data -- php build.php --disable    remove the symlink, disable the template_dir setting
		runuser -u www-data -- php build.php --enable     undo --disable

	The FusionPBX database is opened only when it is needed: --build and --verify need it only when
	overrides exist (domain uuid to name mapping, vendor rows); --disable and --enable always need it.
*/

//run from the command line only
	if (PHP_SAPI !== 'cli') {
		exit;
	}

//never as root: files would become unreadable to php-fpm
	if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
		fwrite(STDERR, "x_provision_overlay: do not run as root; use: runuser -u www-data -- php ".__FILE__." --build\n");
		exit(2);
	}

//parse the command
	$commands = ['--build', '--status', '--verify', '--rollback', '--disable', '--enable'];
	$command = $argv[1] ?? '';
	if (!in_array($command, $commands, true)) {
		fwrite(STDERR, "usage: php build.php ".implode('|', $commands)."\n");
		exit(2);
	}

//load the generator without the framework
	$project_root = dirname(__DIR__, 4);
	chdir($project_root);
	require_once dirname(__DIR__) . "/classes/provision_overlay.php";
	if (!is_dir(provision_overlay::state_root)) {
		fwrite(STDERR, "x_provision_overlay: ".provision_overlay::state_root." does not exist; run the installer\n");
		exit(1);
	}
	$overlay = new provision_overlay();

//connect to the database only when needed
	$needs_database = false;
	switch ($command) {
		case '--build':
			$needs_database = provision_overlay::has_domain_overrides();
			break;
		case '--verify':
			$needs_database = provision_overlay::has_overrides();
			break;
		case '--disable':
		case '--enable':
			$needs_database = true;
			break;
	}
	if ($needs_database) {
		if (!is_readable('/etc/fusionpbx/config.conf') && !is_readable('/usr/local/etc/fusionpbx/config.conf')) {
			fwrite(STDERR, "x_provision_overlay: the FusionPBX config file is not readable; a database connection is required for ".$command."\n");
			exit(1);
		}
		require_once $project_root . "/resources/require.php";
		$overlay->set_database($database);
	}

//print helpers
	function overlay_print_messages(array $result): void {
		foreach ($result['warnings'] ?? [] as $warning) {
			echo "x_provision_overlay: warning: ".$warning."\n";
		}
		foreach ($result['errors'] ?? [] as $error) {
			fwrite(STDERR, "x_provision_overlay: error: ".$error."\n");
		}
	}

//run
	switch ($command) {
		case '--build':
			$result = $overlay->build();
			//a domain override saved after the check above needs the database: connect and retry once
			if (!$result['success'] && !$overlay->has_database() && provision_overlay::has_domain_overrides()
				&& (is_readable('/etc/fusionpbx/config.conf') || is_readable('/usr/local/etc/fusionpbx/config.conf'))) {
				require_once $project_root . "/resources/require.php";
				$overlay->set_database($database);
				$result = $overlay->build();
			}
			overlay_print_messages($result);
			if (!$result['success']) {
				exit(1);
			}
			$c = $result['counts'];
			echo "x_provision_overlay: build ".$result['id']." templates ".$c['templates'].", upstream files ".$c['upstream_files'].", global overrides ".$c['global_overrides'].", domains ".$c['domains'].", domain overrides ".$c['domain_overrides']."\n";
			if (provision_overlay::link_state() !== 'ours' && !provision_overlay::is_disabled()) {
				echo "x_provision_overlay: warning: ".provision_overlay::link_path." is ".provision_overlay::link_state()."; the installer creates it\n";
			}
			exit(0);

		case '--status':
			$status = $overlay->status();
			echo "link:            ".provision_overlay::link_path." (".$status['link_state'].")\n";
			echo "disabled:        ".($status['disabled'] ? 'yes' : 'no')."\n";
			echo "current build:   ".($status['current_id'] !== '' ? $status['current_id'] : '(none)')."\n";
			echo "builds on disk:  ".implode(' ', $status['builds'])."\n";
			echo "upstream commit: ".($status['upstream_commit'] !== '' ? $status['upstream_commit'] : '(unknown)')."\n";
			if (is_array($status['build'])) {
				$b = $status['build'];
				echo "built from:      ".($b['upstream_commit'] ?? '')."\n";
				echo "generated at:    ".($b['generated_at'] ?? '')."\n";
				foreach (($b['counts'] ?? []) as $key => $value) {
					echo str_pad($key.':', 17).$value."\n";
				}
				if (($b['upstream_commit'] ?? '') !== $status['upstream_commit']) {
					echo "note: the upstream checkout changed since this build; run --build\n";
				}
			}
			exit(0);

		case '--verify':
			$report = $overlay->verify();
			foreach ($report['problems'] as $problem) {
				echo "problem: ".$problem."\n";
			}
			foreach ($report['warnings'] as $warning) {
				echo "warning: ".$warning."\n";
			}
			echo "x_provision_overlay: verify ".count($report['problems'])." problem(s), ".count($report['warnings'])." warning(s)\n";
			exit(empty($report['problems']) ? 0 : 1);

		case '--rollback':
			$result = $overlay->rollback();
			overlay_print_messages($result);
			if (!$result['success']) {
				exit(1);
			}
			echo "x_provision_overlay: current build is now ".$result['id']."\n";
			exit(0);

		case '--disable':
			$result = $overlay->disable();
			overlay_print_messages($result);
			if (!$result['success']) {
				exit(1);
			}
			echo "x_provision_overlay: disabled; overlay state in ".provision_overlay::state_root." is kept\n";
			exit(0);

		case '--enable':
			$result = $overlay->enable();
			overlay_print_messages($result);
			if (!$result['success']) {
				exit(1);
			}
			echo "x_provision_overlay: enabled\n";
			exit(0);
	}

?>
