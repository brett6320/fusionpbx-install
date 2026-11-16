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
	AWS prefix list feeds (docs/design/carrier_acl.md section 13). Run as www-data by resources/aws.sh.

	Usage:
		--ensure-feeds      create the two feeds once (marker x_install.aws_feeds_initialized), so a feed an admin
		                    deletes is not recreated
		--approve-initial   install only: approve a feed's first run when that is the only reason it waits, nothing
		                    was rejected, the feed has no entries yet, and the run is for the file as it is now.
		                    The security group already admits exactly these addresses at the network edge.
		                    Exit 3 if a feed with no entries has no run yet (the caller runs the worker and retries).
*/

//run from the command line only
	if (PHP_SAPI !== 'cli' || !defined('STDIN')) {
		exit;
	}
	require_once dirname(__DIR__, 4) . "/resources/require.php";

	$feeds = [
		'0f4d6b1e-5c2a-4b8e-9a37-2d1c8e6f4a01' => ['name' => 'AWS prefix list: Telnyx signalling', 'file' => 'telnyx-signalling.json', 'role' => 'signaling'],
		'5a9e3c72-1b4d-4f60-8e2a-7c3b9d1e5f02' => ['name' => 'AWS prefix list: Telnyx media', 'file' => 'telnyx-media.json', 'role' => 'media'],
	];

	$args = array_slice($argv ?? [], 1);
	if (count($args) !== 1 || !in_array($args[0], ['--ensure-feeds', '--approve-initial'], true)) {
		fwrite(STDERR, "usage: x_carrier_acl_aws.php --ensure-feeds|--approve-initial\n");
		exit(64);
	}

//create the feeds once
	if ($args[0] === '--ensure-feeds') {
		if (!$database->table_exists('v_x_carrier_acl_feeds')) {
			fwrite(STDERR, "x_carrier_acl: v_x_carrier_acl_feeds does not exist; run upgrade.php --schema first\n");
			exit(1);
		}
		$sql = "select count(*) from v_default_settings ";
		$sql .= "where default_setting_category = 'x_install' and default_setting_subcategory = 'aws_feeds_initialized' ";
		if ((int)$database->select($sql, null, 'column') > 0) {
			echo "x_carrier_acl: AWS feeds were created before; not recreating them\n";
			exit(0);
		}
		foreach ($feeds as $uuid => $feed) {
			if ((int)$database->select("select count(*) from v_x_carrier_acl_feeds where x_carrier_acl_feed_uuid = :uuid ", ['uuid' => $uuid], 'column') > 0) {
				continue;
			}
			$sql = "insert into v_x_carrier_acl_feeds ( ";
			$sql .= "x_carrier_acl_feed_uuid, feed_name, feed_url, feed_parser, feed_parser_options, ";
			$sql .= "feed_interval_minutes, feed_enabled, feed_allow_private, feed_min_entries, feed_max_entries, ";
			$sql .= "feed_consecutive_failures, feed_run_requested, feed_description, insert_date ";
			$sql .= ") values ( ";
			$sql .= ":uuid, :name, :url, 'json_path', :options, ";
			$sql .= "15, true, false, 1, 5000, ";
			$sql .= "0, true, :description, now() ";
			$sql .= ") ";
			$database->execute($sql, [
				'uuid' => $uuid,
				'name' => $feed['name'],
				'url' => 'file://'.x_carrier_acl_fetch::local_dir.'/'.$feed['file'],
				'options' => json_encode(['paths' => [['path' => 'entries.*.cidr', 'role' => $feed['role']]], 'version_path' => 'version'], JSON_UNESCAPED_SLASHES),
				'description' => 'Entries of this stack\'s security group prefix list, read by x-aws-sync (hourly) from AWS.',
			]);
			if ((int)$database->select("select count(*) from v_x_carrier_acl_feeds where x_carrier_acl_feed_uuid = :uuid ", ['uuid' => $uuid], 'column') === 0) {
				fwrite(STDERR, "x_carrier_acl: could not create feed ".$feed['name']."\n");
				exit(1);
			}
			echo "x_carrier_acl: created feed ".$feed['name']."\n";
		}
		$sql = "insert into v_default_settings ";
		$sql .= "(default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
		$sql .= "values (:uuid, 'x_install', 'aws_feeds_initialized', 'boolean', 'true', false, 'Marker: the AWS prefix list feeds were created at install. Do not remove.') ";
		$database->execute($sql, ['uuid' => uuid()]);
		exit(0);
	}

//approve first runs, only under the conditions above
	$exit = 0;
	foreach ($feeds as $uuid => $feed) {
		$entries = (int)$database->select("select count(*) from v_x_carrier_acl_entries where x_carrier_acl_feed_uuid = :uuid ", ['uuid' => $uuid], 'column');
		$sql = "select * from v_x_carrier_acl_runs where x_carrier_acl_feed_uuid = :uuid and run_status = 'pending_approval' ";
		$sql .= "order by run_started desc limit 1 ";
		$run = $database->select($sql, ['uuid' => $uuid], 'row');
		$body = @file_get_contents(x_carrier_acl_fetch::local_dir.'/'.$feed['file']);
		$proposal = is_array($run) ? json_decode((string)$run['run_proposal'], true) : null;

		$why_not = null;
		if (!is_array($run)) {
			$why_not = 'no run is waiting for approval';
			$has_run = (int)$database->select("select count(*) from v_x_carrier_acl_runs where x_carrier_acl_feed_uuid = :uuid ", ['uuid' => $uuid], 'column') > 0;
			if ($entries === 0 && !$has_run) {
				$exit = 3;
			}
		}
		elseif ($entries > 0) {
			$why_not = 'the feed already has entries';
		}
		elseif ((int)$run['run_rejected_count'] !== 0) {
			$why_not = $run['run_rejected_count'].' entries were rejected';
		}
		elseif (!is_array($proposal) || ($proposal['reasons'] ?? null) !== ['first run for this feed']) {
			$why_not = 'it waits for: '.implode('; ', is_array($proposal['reasons'] ?? null) ? $proposal['reasons'] : ['unknown']);
		}
		elseif ($body === false || hash('sha256', $body) !== $run['run_fetched_sha256']) {
			$why_not = 'the file changed since the run';
		}
		if ($why_not !== null) {
			echo "x_carrier_acl: ".$feed['name'].": not approved (".$why_not."); approve it in Advanced > Carrier ACL > Runs\n";
			continue;
		}

		$sql = "update v_x_carrier_acl_runs set run_status = 'approved', run_approved_at = now(), update_date = now() ";
		$sql .= "where x_carrier_acl_run_uuid = :uuid and run_status = 'pending_approval' ";
		$database->execute($sql, ['uuid' => $run['x_carrier_acl_run_uuid']]);
		$database->execute("update v_x_carrier_acl_feeds set feed_run_requested = true where x_carrier_acl_feed_uuid = :uuid ", ['uuid' => $uuid]);
		x_carrier_acl::log(LOG_NOTICE, 'run '.$run['x_carrier_acl_run_uuid'].' ('.$feed['name'].', '.count($proposal['entries'] ?? []).' entries) approved by the installer: first run of this stack\'s own security group prefix list');
	}
	exit($exit);

?>
