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

//seed the carrier feed presets once; insert-if-missing by uuid so an admin's edits (or deletion of the
//other preset) are never overwritten. Presets are disabled until an admin enables them.
//default settings are declared in app_config.php; upstream only inserts the missing ones.
	if ($domains_processed == 1) {

		//make sure the table exists (schema runs before defaults, but stay safe)
			if ($database->table_exists('v_x_carrier_acl_feeds')) {

				$presets = [];
				$presets[] = [
					'x_carrier_acl_feed_uuid' => '6861342a-b859-4667-bb7f-a0341a4d3466',
					'feed_name' => 'Telnyx (voice.json)',
					'feed_url' => 'https://sip.telnyx.com/voice.json',
					'feed_parser' => 'json_path',
					'feed_parser_options' => json_encode([
						'paths' => [
							['path' => 'signaling.*.primary', 'role' => 'signaling'],
							['path' => 'signaling.*.secondary', 'role' => 'signaling'],
							['path' => 'uac.*.ip', 'role' => 'signaling'],
							['path' => 'media.globalCidrs[*]', 'role' => 'media'],
						],
						'version_path' => 'version',
					], JSON_UNESCAPED_SLASHES),
					'feed_description' => 'Authoritative Telnyx feed: signaling, UAC and media ranges.',
				];
				$presets[] = [
					'x_carrier_acl_feed_uuid' => '57df4216-03e3-430e-8762-b8f5255b857a',
					'feed_name' => 'Telnyx (HTML fallback)',
					'feed_url' => 'https://sip.telnyx.com/',
					'feed_parser' => 'html_ip_scrape',
					'feed_parser_options' => json_encode([
						'role' => 'signaling',
						'section_regex' => '^(SIP Signaling Addresses|UAC|Media|TURN)',
					], JSON_UNESCAPED_SLASHES),
					'feed_description' => 'Best effort scrape of https://sip.telnyx.com/. Use only if voice.json is unavailable.',
				];

				foreach ($presets as $preset) {
					$sql = "select count(*) from v_x_carrier_acl_feeds ";
					$sql .= "where x_carrier_acl_feed_uuid = :x_carrier_acl_feed_uuid ";
					$parameters['x_carrier_acl_feed_uuid'] = $preset['x_carrier_acl_feed_uuid'];
					$num_rows = $database->select($sql, $parameters, 'column');
					unset($sql, $parameters);
					if ($num_rows == 0) {
						$sql = "insert into v_x_carrier_acl_feeds ( ";
						$sql .= "x_carrier_acl_feed_uuid, feed_name, feed_url, feed_parser, feed_parser_options, ";
						$sql .= "feed_interval_minutes, feed_enabled, feed_allow_private, feed_min_entries, feed_max_entries, ";
						$sql .= "feed_consecutive_failures, feed_run_requested, feed_description, insert_date ";
						$sql .= ") values ( ";
						$sql .= ":x_carrier_acl_feed_uuid, :feed_name, :feed_url, :feed_parser, :feed_parser_options, ";
						$sql .= "360, false, false, 1, 5000, ";
						$sql .= "0, false, :feed_description, now() ";
						$sql .= ") ";
						$database->execute($sql, $preset);
						unset($sql);
					}
				}
				unset($presets, $preset, $num_rows);
			}

	}

?>
