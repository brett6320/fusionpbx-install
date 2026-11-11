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
	Carrier ACL worker (docs/design/carrier_acl.md section 5). A oneshot run as www-data by
	x-carrier-acl-worker.service, started by x-carrier-acl-worker.timer (every 15 minutes) and by
	x-carrier-acl-worker.path when the UI writes /var/lib/x_carrier_acl/export/run.request.

	Usage:
		php x_carrier_acl_worker.php                         normal tick
		php x_carrier_acl_worker.php --write-empty-snapshot  installer smoke test: write an empty allow-mode snapshot
		                                                     only if no snapshot exists yet (needs no app tables)
*/

//run from the command line only (bootstrap as app/email_queue/resources/jobs/email_queue.php does)
	if (PHP_SAPI !== 'cli' || !defined('STDIN')) {
		exit;
	}

//the request file is removed first thing (section 7.2), before anything that can fail, so a worker that dies
//(e.g. database down) does not make x-carrier-acl-worker.path re-trigger it in a loop
	$manual = false;
	if (!in_array('--write-empty-snapshot', $argv ?? [], true) && file_exists('/var/lib/x_carrier_acl/export/run.request')) {
		@unlink('/var/lib/x_carrier_acl/export/run.request');
		$manual = true;
	}

	require_once dirname(__DIR__, 4) . "/resources/require.php";

//increase limits
	set_time_limit(0);
	ini_set('max_execution_time', 0);
	ini_set('memory_limit', '256M');

//arguments
	$write_empty_snapshot = in_array('--write-empty-snapshot', $argv ?? [], true);

//take an exclusive lock so a hand-run copy never overlaps the unit (section 5.1)
	$runtime_directory = explode(':', (string)getenv('RUNTIME_DIRECTORY'))[0];
	if (empty($runtime_directory) || !is_dir($runtime_directory)) {
		$runtime_directory = is_dir('/run/x_carrier_acl') && is_writable('/run/x_carrier_acl') ? '/run/x_carrier_acl' : sys_get_temp_dir();
	}
	$lock = fopen($runtime_directory.'/worker.lock', 'c');
	if ($lock === false) {
		fwrite(STDERR, "x_carrier_acl: cannot open lock file ".$runtime_directory."/worker.lock\n");
		exit(1);
	}
	if (!flock($lock, LOCK_EX | LOCK_NB)) {
		echo "x_carrier_acl: another worker run is in progress\n";
		exit(0);
	}

	$app = new x_carrier_acl($database);
	$applier = new x_carrier_acl_apply($database, $app);
	$alert = new x_carrier_acl_alert($database, $app);

//installer smoke test
	if ($write_empty_snapshot) {
		$result = $applier->write_empty_snapshot_if_missing();
		if ($result['error'] !== null) {
			fwrite(STDERR, "x_carrier_acl: ".$result['error']."\n");
			exit(1);
		}
		echo "x_carrier_acl: ".($result['written'] ? "empty snapshot generation ".$result['generation']." written" : $result['skipped'])."\n";
		exit(0);
	}


/**
 * Insert a run row; returns its uuid.
 */
	function x_carrier_acl_record_run(database $database, array $run): string {
		$run['x_carrier_acl_run_uuid'] = $run['x_carrier_acl_run_uuid'] ?? uuid();
		$columns = ['x_carrier_acl_run_uuid', 'x_carrier_acl_feed_uuid', 'run_started', 'run_trigger', 'run_status',
			'run_http_status', 'run_fetched_sha256', 'run_feed_version', 'run_bytes', 'run_added', 'run_removed',
			'run_rejected_count', 'run_proposal', 'run_error'];
		$names = [];
		$values = [];
		$parameters = [];
		foreach ($columns as $column) {
			if (array_key_exists($column, $run) && $run[$column] !== null) {
				$names[] = $column;
				$values[] = ':'.$column;
				$parameters[$column] = is_bool($run[$column]) ? ($run[$column] ? 'true' : 'false') : $run[$column];
			}
		}
		$sql = "insert into v_x_carrier_acl_runs (".implode(', ', $names).", run_finished, insert_date) ";
		$sql .= "values (".implode(', ', $values).", now(), now()) ";
		if ($database->execute($sql, $parameters) === false) {
			x_carrier_acl::log(LOG_ERR, 'run could not be recorded: '.json_encode($database->message['message'] ?? ''));
		}
		return $run['x_carrier_acl_run_uuid'];
	}

/**
 * Update feed columns.
 */
	function x_carrier_acl_update_feed(database $database, string $feed_uuid, array $set, array $parameters = []): void {
		$sql = "update v_x_carrier_acl_feeds set ".implode(', ', $set).", update_date = now() where x_carrier_acl_feed_uuid = :feed_uuid ";
		$parameters['feed_uuid'] = $feed_uuid;
		$database->execute($sql, $parameters);
	}

/**
 * Record a failed fetch/parse/validate and alert once the consecutive failure count reaches the threshold.
 */
	function x_carrier_acl_fail(database $database, x_carrier_acl $app, x_carrier_acl_alert $alert, array $feed, array $run, string $error): void {
		$failures = (int)$feed['feed_consecutive_failures'] + 1;
		x_carrier_acl_update_feed($database, $feed['x_carrier_acl_feed_uuid'], ['feed_consecutive_failures = :failures', 'feed_run_requested = false'], ['failures' => $failures]);
		$run['run_status'] = 'failed';
		$run['run_error'] = substr($error, 0, 2000);
		x_carrier_acl_record_run($database, $run);
		x_carrier_acl::log(LOG_WARNING, $feed['feed_name'].': '.$error.' (consecutive failures '.$failures.'); last-known-good kept');
		if ($failures === $app->setting_int('failure_alert_after')) {
			$alert->send('failure', $feed['feed_name'].' FAILED ('.$failures.' consecutive)', "Feed: ".$feed['feed_name']."\nURL: ".$feed['feed_url']."\nError: ".$error."\n\nThe last-known-good entries are still in place.");
		}
	}

/**
 * Process one feed (section 5.2). Returns true when entries were applied.
 */
	function x_carrier_acl_process_feed(database $database, x_carrier_acl $app, x_carrier_acl_apply $applier, x_carrier_acl_alert $alert, array $feed, array $own_addresses, string $trigger, ?array $approved_run): bool {
		$feed_uuid = $feed['x_carrier_acl_feed_uuid'];
		$run = ['x_carrier_acl_feed_uuid' => $feed_uuid, 'run_started' => date('c'), 'run_trigger' => $trigger];
		x_carrier_acl_update_feed($database, $feed_uuid, ['feed_last_checked = now()']);

		//1. fetch; no conditional request when an approval or a manual run needs the body
		$conditional = ($approved_run === null && $trigger !== 'manual');
		$fetcher = new x_carrier_acl_fetch();
		$response = $fetcher->fetch((string)$feed['feed_url'], [
			'etag' => $conditional ? ($feed['feed_etag'] ?? '') : '',
			'last_modified' => $conditional ? ($feed['feed_last_modified'] ?? '') : '',
			'pin' => $feed['feed_tls_pin_sha256'] ?? '',
			//the unit allows loopback (database, event socket); refuse loopback/private feed destinations here
			'check_destination' => true,
		]);
		$run['run_http_status'] = $response['status'];
		if (!$response['ok']) {
			x_carrier_acl_fail($database, $app, $alert, $feed, $run, 'fetch failed: '.$response['error']);
			return false;
		}
		if ($response['not_modified']) {
			x_carrier_acl_update_feed($database, $feed_uuid, ['feed_consecutive_failures = 0', 'feed_last_success = now()', 'feed_run_requested = false']);
			$run['run_status'] = 'unchanged';
			x_carrier_acl_record_run($database, $run);
			return false;
		}
		$run['run_fetched_sha256'] = $response['sha256'];
		$run['run_bytes'] = $response['bytes'];

		//2. parse and validate
		$parser = x_carrier_acl::parser((string)$feed['feed_parser']);
		[$options, $option_error] = x_carrier_acl::decode_options($feed['feed_parser_options'] ?? '');
		if ($parser === null || $option_error !== null) {
			x_carrier_acl_fail($database, $app, $alert, $feed, $run, $parser === null ? 'unknown parser' : 'parser options: '.$option_error);
			return false;
		}
		$option_errors = $parser->option_errors($options);
		if (!empty($option_errors)) {
			x_carrier_acl_fail($database, $app, $alert, $feed, $run, 'parser options: '.implode('; ', $option_errors));
			return false;
		}
		try {
			$parsed = $parser->parse($response['body'], $options);
		}
		catch (Throwable $e) {
			x_carrier_acl_fail($database, $app, $alert, $feed, $run, 'parse failed: '.$e->getMessage());
			return false;
		}
		$run['run_feed_version'] = $parsed['version'] ?? null;
		$validator = x_carrier_acl_validate::for_feed($app, $feed, $own_addresses);
		$validated = $validator->validate($parsed['entries']);
		$run['run_rejected_count'] = count($validated['rejected']);
		if ($validated['error'] !== null) {
			$run['run_proposal'] = json_encode(['rejected' => array_slice($validated['rejected'], 0, 500), 'warnings' => array_slice($validated['warnings'], 0, 200)], JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
			x_carrier_acl_fail($database, $app, $alert, $feed, $run, 'validation: '.$validated['error']);
			return false;
		}

		//3. diff
		$current = x_carrier_acl_diff::current($database, $feed_uuid);
		$proposed = $validated['accepted'];
		$diff = x_carrier_acl_diff::compute($current, $proposed);

		//4. gate
		$reasons = [];
		if ($approved_run !== null) {
			if ($approved_run['run_fetched_sha256'] === $response['sha256']) {
				//apply exactly what was approved
				$approved_proposal = json_decode((string)$approved_run['run_proposal'], true);
				if (!is_array($approved_proposal) || !isset($approved_proposal['entries']) || !is_array($approved_proposal['entries'])) {
					x_carrier_acl_fail($database, $app, $alert, $feed, $run, 'approved proposal is unreadable');
					return false;
				}
				$proposed = $approved_proposal['entries'];
				$diff = x_carrier_acl_diff::compute($current, $proposed);
			}
			else {
				$database->execute("update v_x_carrier_acl_runs set run_status = 'superseded', update_date = now() where x_carrier_acl_run_uuid = :uuid ", ['uuid' => $approved_run['x_carrier_acl_run_uuid']]);
				$reasons[] = 'feed changed after approval';
				$reasons = array_merge($reasons, x_carrier_acl_diff::gate($current, $proposed, $diff, $app->thresholds($feed)));
				$approved_run = null;
			}
		}
		else {
			$reasons = x_carrier_acl_diff::gate($current, $proposed, $diff, $app->thresholds($feed));
		}

		$run['run_added'] = count($diff['added']);
		$run['run_removed'] = count($diff['removed']);
		$proposal = [
			'entries' => $proposed,
			'added' => array_values($diff['added']),
			'removed' => array_values(array_map(fn($row) => ['cidr' => $row['entry_cidr'], 'role' => $row['entry_role'], 'label' => $row['entry_label']], $diff['removed'])),
			'rejected' => array_slice($validated['rejected'], 0, 500),
			'warnings' => array_slice($validated['warnings'], 0, 200),
			'reasons' => $reasons,
			'version' => $parsed['version'] ?? null,
		];
		$run['run_proposal'] = json_encode($proposal, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($run['run_proposal'] === false) {
			x_carrier_acl_fail($database, $app, $alert, $feed, $run, 'proposal could not be encoded: '.json_last_error_msg());
			return false;
		}

		if (!empty($reasons)) {
			//only one open question per feed; the same content already waiting is not asked again
			$sql = "select x_carrier_acl_run_uuid, run_fetched_sha256 from v_x_carrier_acl_runs ";
			$sql .= "where x_carrier_acl_feed_uuid = :feed_uuid and run_status = 'pending_approval' ";
			$pending = $database->select($sql, ['feed_uuid' => $feed_uuid], 'all');
			$same_pending = false;
			//content an admin already rejected is not asked about again until the feed changes
			$sql = "select count(*) from v_x_carrier_acl_runs where x_carrier_acl_feed_uuid = :feed_uuid ";
			$sql .= "and run_status = 'rejected' and run_fetched_sha256 = :sha256 ";
			if ((int)$database->select($sql, ['feed_uuid' => $feed_uuid, 'sha256' => $response['sha256']], 'column') > 0) {
				$same_pending = true;
			}
			foreach (is_array($pending) ? $pending : [] as $row) {
				if ($row['run_fetched_sha256'] === $response['sha256']) {
					$same_pending = true;
				}
				else {
					$database->execute("update v_x_carrier_acl_runs set run_status = 'superseded', update_date = now() where x_carrier_acl_run_uuid = :uuid ", ['uuid' => $row['x_carrier_acl_run_uuid']]);
				}
			}
			x_carrier_acl_update_feed($database, $feed_uuid, ['feed_consecutive_failures = 0', 'feed_last_success = now()', 'feed_run_requested = false', 'feed_etag = :etag', 'feed_last_modified = :last_modified'], ['etag' => $response['etag'], 'last_modified' => $response['last_modified']]);
			if ($same_pending) {
				return false;
			}
			$run['run_status'] = 'pending_approval';
			x_carrier_acl_record_run($database, $run);
			$body = "Feed: ".$feed['feed_name']."\nURL: ".$feed['feed_url']."\nReasons:\n  ".implode("\n  ", $reasons)."\n\n";
			$body .= x_carrier_acl_alert::diff_text($proposal['added'], $proposal['removed']);
			$body .= "\n\nApprove or reject it in Advanced > Carrier ACL > Runs.";
			$alert->send('approval', $feed['feed_name'].' approval required (+'.$run['run_added'].'/-'.$run['run_removed'].')', $body);
			return false;
		}

		//5. apply in one transaction, then reloadacl and verify
		$result = $applier->apply_feed($feed_uuid, $proposed, $diff);
		if (!$result['ok']) {
			$run['run_status'] = 'failed';
			$run['run_error'] = 'apply failed: '.$result['error'];
			x_carrier_acl_record_run($database, $run);
			$alert->send('failure', $feed['feed_name'].' apply FAILED', "Feed: ".$feed['feed_name']."\nError: ".$result['error']."\n\nThe transaction was rolled back; the last-known-good entries are still in place.");
			return false;
		}
		x_carrier_acl_update_feed($database, $feed_uuid, ['feed_consecutive_failures = 0', 'feed_last_success = now()', 'feed_last_applied = now()', 'feed_run_requested = false', 'feed_etag = :etag', 'feed_last_modified = :last_modified'], ['etag' => $response['etag'], 'last_modified' => $response['last_modified']]);
		if ($approved_run !== null) {
			$database->execute("update v_x_carrier_acl_runs set run_status = 'applied', update_date = now() where x_carrier_acl_run_uuid = :uuid ", ['uuid' => $approved_run['x_carrier_acl_run_uuid']]);
		}

		$changed = !empty($diff['added']) || !empty($diff['removed']);
		if ($changed) {
			$verify = null;
			foreach (array_merge($diff['added'], $diff['unchanged']) as $entry) {
				if ($entry['role'] === 'signaling') {
					$verify = $entry['cidr'];
					break;
				}
			}
			$reload_error = $applier->reload_acl($verify);
			if ($reload_error !== null) {
				$run['run_error'] = $reload_error;
				$alert->send('failure', $feed['feed_name'].' '.$reload_error, "Feed: ".$feed['feed_name']."\nThe database was updated; the next reloadacl will pick it up.");
			}
		}
		$run['run_status'] = $changed ? 'applied' : 'unchanged';
		$run_uuid = x_carrier_acl_record_run($database, $run);
		$GLOBALS['x_carrier_acl_applied_runs'][] = $run_uuid;

		//6. change alert
		if ($changed) {
			$alert->send('change', $feed['feed_name'].' applied (+'.$run['run_added'].'/-'.$run['run_removed'].')', "Feed: ".$feed['feed_name']."\n\n".x_carrier_acl_alert::diff_text($proposal['added'], $proposal['removed']));
		}
		return $changed;
	}

//record the root applier's outcome for runs that exported a generation, and alert once per failed generation
	$status = x_carrier_acl::firewall_status();
	$exported = x_carrier_acl::exported_generation();
	$sql = "select x_carrier_acl_run_uuid, run_firewall_generation, run_finished from v_x_carrier_acl_runs ";
	$sql .= "where run_firewall_generation is not null and run_firewall_result is null ";
	$open_runs = $database->select($sql, null, 'all');
	foreach (is_array($open_runs) ? $open_runs : [] as $row) {
		$generation = (int)$row['run_firewall_generation'];
		$result = null;
		if (is_array($status) && isset($status['generation'])) {
			$applied = (int)$status['generation'];
			if ($applied === $generation) {
				$result = in_array($status['result'] ?? '', ['ok', 'inactive'], true) ? (string)$status['result'] : 'failed: '.substr((string)($status['error'] ?? 'unknown'), 0, 500);
			}
			elseif ($applied > $generation) {
				$result = 'superseded by generation '.$applied;
			}
		}
		if ($result === null && strtotime((string)$row['run_finished']) < time() - 300) {
			$result = 'failed: not applied by the root applier';
		}
		if ($result !== null) {
			$database->execute("update v_x_carrier_acl_runs set run_firewall_result = :result, update_date = now() where x_carrier_acl_run_uuid = :uuid ", ['result' => $result, 'uuid' => $row['x_carrier_acl_run_uuid']]);
		}
	}
	if ($exported !== null) {
		$alerted_file = x_carrier_acl::export_dir.'/alerted.gen';
		$alerted = (int)trim((string)@file_get_contents($alerted_file));
		$failed_error = null;
		if (is_array($status) && (int)($status['generation'] ?? 0) === $exported && !in_array($status['result'] ?? '', ['ok', 'inactive'], true)) {
			$failed_error = (string)($status['error'] ?? 'unknown error');
		}
		elseif ((!is_array($status) || (int)($status['generation'] ?? 0) < $exported) && @filemtime(x_carrier_acl::generation_file) < time() - 300) {
			$failed_error = 'generation '.$exported.' was not applied (applier status: '.(is_array($status) ? ($status['result'] ?? 'unknown').' generation '.($status['generation'] ?? '?') : 'missing').')';
		}
		if ($failed_error !== null && $alerted !== $exported) {
			$alert->send('firewall', 'firewall apply FAILED', "Generation: ".$exported."\nError: ".$failed_error."\n\nCheck: journalctl -u x-carrier-acl-apply.service");
			@file_put_contents($alerted_file, $exported."\n");
		}
	}

//boot detection for the run trigger
	$uptime = (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];

//process the feeds (section 5.2)
	$GLOBALS['x_carrier_acl_applied_runs'] = [];
	$own_addresses = null;
	$any_applied = false;
	$sql = "select * from v_x_carrier_acl_feeds where feed_enabled = true order by feed_name asc ";
	$feeds = $database->select($sql, null, 'all');
	foreach (is_array($feeds) ? $feeds : [] as $feed) {
		//latest approved run for the feed
		$sql = "select * from v_x_carrier_acl_runs where x_carrier_acl_feed_uuid = :feed_uuid and run_status = 'approved' ";
		$sql .= "order by run_approved_at desc limit 1 ";
		$approved_run = $database->select($sql, ['feed_uuid' => $feed['x_carrier_acl_feed_uuid']], 'row');
		$approved_run = is_array($approved_run) ? $approved_run : null;

		$requested = x_carrier_acl_diff::bool($feed['feed_run_requested'] ?? false);
		$interval = max(1, (int)($feed['feed_interval_minutes'] ?: 360));
		$last_checked = !empty($feed['feed_last_checked']) ? strtotime((string)$feed['feed_last_checked']) : false;
		$due = $requested || $approved_run !== null || $last_checked === false || time() >= $last_checked + ($interval * 60);
		if (!$due) {
			continue;
		}

		if ($approved_run !== null) {
			$trigger = 'approval';
		}
		elseif ($requested) {
			$trigger = 'manual';
		}
		elseif ($uptime > 0 && $uptime < 900) {
			$trigger = 'boot';
		}
		else {
			$trigger = 'timer';
		}

		if ($own_addresses === null) {
			$own_addresses = $app->own_addresses();
		}
		try {
			if (x_carrier_acl_process_feed($database, $app, $applier, $alert, $feed, $own_addresses, $trigger, $approved_run)) {
				$any_applied = true;
			}
		}
		catch (Throwable $e) {
			x_carrier_acl::log(LOG_ERR, $feed['feed_name'].': unexpected error: '.$e->getMessage());
		}
	}

//regenerate the firewall snapshot once if anything applied or the snapshot on disk does not match the database
	//the body comparison covers both cases: an apply that changed the firewall set changes the body. Local network
	//edits (section 12) reach the firewall the same way: the UI saves, audits and requests a run; the body differs.
	//A version 1 snapshot left by an older worker never matches, so it is rewritten as version 2 here.
	$snapshot_cutoff = $database->select("select now()", null, 'column');
	$snapshot = $applier->write_snapshot(false);
	if ($any_applied && $snapshot['skipped'] === 'unchanged') {
		x_carrier_acl::log(LOG_INFO, 'entries applied; firewall set unchanged');
	}
	if ($snapshot['error'] !== null) {
		$alert->send('firewall', 'firewall snapshot FAILED', $snapshot['error']);
	}
	elseif ($snapshot['written']) {
		foreach ($GLOBALS['x_carrier_acl_applied_runs'] as $run_uuid) {
			$database->execute("update v_x_carrier_acl_runs set run_firewall_generation = :generation, update_date = now() where x_carrier_acl_run_uuid = :uuid ", ['generation' => $snapshot['generation'], 'uuid' => $run_uuid]);
		}
	}

//local network audit runs saved before this snapshot was built: link them to the generation that carries them, or
//record why none was exported (unchanged body, firewall disabled, error)
	if (is_string($snapshot_cutoff) && $snapshot_cutoff !== '') {
		$sql = "update v_x_carrier_acl_runs set ";
		if ($snapshot['written']) {
			$sql .= "run_firewall_generation = :generation, ";
			$parameters = ['generation' => $snapshot['generation']];
		}
		else {
			$sql .= "run_firewall_result = :result, ";
			$parameters = ['result' => substr($snapshot['error'] !== null ? 'failed: '.$snapshot['error'] : 'not exported: '.$snapshot['skipped'], 0, 500)];
		}
		$sql .= "update_date = now() where run_trigger = :trigger and x_carrier_acl_feed_uuid is null ";
		$sql .= "and run_firewall_generation is null and run_firewall_result is null and run_started <= :cutoff ";
		$parameters['trigger'] = x_carrier_acl_local::run_trigger;
		$parameters['cutoff'] = $snapshot_cutoff;
		$database->execute($sql, $parameters);
	}

//release the lock
	flock($lock, LOCK_UN);
	fclose($lock);

?>
