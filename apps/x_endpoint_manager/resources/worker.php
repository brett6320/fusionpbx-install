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
	Endpoint manager worker. Run once a minute by systemd/x-endpoint-manager.timer as www-data.

	1. Due schedules are turned into queued reboots (v_x_endpoint_reboot_log rows with status 'pending').
	   Runs found later than the grace window are logged as 'missed' and not fired.
	2. The queue is processed: active call check, per domain and global rate limits, reboot, result logged.
	   Rows held back by the rate limit stay pending for the next run.
*/

//run from the command line only
	if (PHP_SAPI !== 'cli') {
		exit;
	}

//includes files
	require_once dirname(__DIR__, 3) . "/resources/require.php";

//increase limits
	set_time_limit(0);

//take an exclusive lock so runs never overlap
	$runtime_directory = explode(':', (string)getenv('RUNTIME_DIRECTORY'))[0];
	$lock_directory = (!empty($runtime_directory) && is_dir($runtime_directory)) ? $runtime_directory : sys_get_temp_dir();
	$lock_file = $lock_directory.'/x_endpoint_manager.lock';
	$lock = fopen($lock_file, 'c');
	if ($lock === false) {
		fwrite(STDERR, "x_endpoint_manager: cannot open lock file ".$lock_file."\n");
		exit(1);
	}
	if (!flock($lock, LOCK_EX | LOCK_NB)) {
		echo "x_endpoint_manager: another run is in progress\n";
		exit(0);
	}

//run
	$endpoint_manager = new x_endpoint_manager(['database' => $database]);

	$schedules = $endpoint_manager->run_due_schedules();
	if ($schedules['started'] > 0 || $schedules['missed'] > 0) {
		echo "x_endpoint_manager: schedules started ".$schedules['started'].", missed ".$schedules['missed'].", reboots queued ".$schedules['queued']."\n";
	}

	$queue = $endpoint_manager->process_queue(null);
	if ($queue['locked']) {
		echo "x_endpoint_manager: queue is being processed by another request\n";
	}
	elseif ($queue['switch_unavailable']) {
		fwrite(STDERR, "x_endpoint_manager: event socket unavailable, ".$queue['deferred']." queued reboots left pending\n");
	}
	elseif ($queue['sent'] > 0 || $queue['skipped'] > 0 || $queue['failed'] > 0 || $queue['deferred'] > 0) {
		echo "x_endpoint_manager: sent ".$queue['sent'].", skipped ".$queue['skipped'].", failed ".$queue['failed'].", deferred by rate limit ".$queue['deferred']."\n";
	}

//release the lock
	flock($lock, LOCK_UN);
	fclose($lock);

?>
