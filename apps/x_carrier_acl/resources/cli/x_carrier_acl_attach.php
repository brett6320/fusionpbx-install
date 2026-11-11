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
	Access control list and SIP profile attachment (docs/design/carrier_acl.md sections 6.1, 6.3, 10).
	Run as www-data by resources/apps/carrier_acl.sh. Uses only upstream tables (v_access_controls,
	v_sip_profiles, v_sip_profile_settings, v_default_settings), so it works before upgrade.php --schema.

	Usage:
		--ensure-list                     create our list if missing (never adopts a list we did not create)
		--attach internal,external,...    insert-if-missing the apply-inbound-acl rows; prints "restart <profile>"
		                                  for each profile that got a new row
		--check                           exit 2 if the list is missing or renamed, or a managed profile is not
		                                  attached (enabled); prints the problems, fixes nothing
*/

//run from the command line only
	if (PHP_SAPI !== 'cli' || !defined('STDIN')) {
		exit;
	}
	require_once dirname(__DIR__, 4) . "/resources/require.php";

//arguments
	$ensure_list = false;
	$check = false;
	$attach = [];
	$args = array_slice($argv ?? [], 1);
	for ($i = 0; $i < count($args); $i++) {
		switch ($args[$i]) {
			case '--ensure-list':
				$ensure_list = true;
				break;
			case '--check':
				$check = true;
				break;
			case '--attach':
				$attach = array_filter(array_map('trim', explode(',', (string)($args[++$i] ?? ''))));
				break;
			default:
				fwrite(STDERR, "unknown argument: ".$args[$i]."\n");
				exit(64);
		}
	}

	$app = new x_carrier_acl($database);
	$applier = new x_carrier_acl_apply($database, $app);
	$exit = 0;

//list
	if ($ensure_list) {
		$list = $applier->ensure_list();
		if ($list['error'] !== null) {
			fwrite(STDERR, "x_carrier_acl: ".$list['error']."\n");
			exit(1);
		}
		echo "x_carrier_acl: access control list ".$app->acl_name()." ".($list['created'] ? "created" : "present")." (".$list['uuid'].")\n";
	}

//attach
	if (!empty($attach)) {
		$result = $applier->attach_profiles($attach);
		foreach ($result['attached'] as $profile) {
			echo "restart ".$profile."\n";
		}
		if ($result['error'] !== null) {
			fwrite(STDERR, "x_carrier_acl: ".$result['error']."\n");
			exit(1);
		}
	}

//check
	if ($check) {
		$state = $app->acl_state();
		if (!$state['exists']) {
			echo "x_carrier_acl: access control list is missing\n";
			$exit = 2;
		}
		elseif ($state['renamed']) {
			echo "x_carrier_acl: access control list was renamed to \"".$state['name']."\" (setting acl_name is \"".$app->acl_name()."\")\n";
			$exit = 2;
		}
		foreach ($app->attachment_state() as $profile) {
			if (!$profile['profile_enabled']) {
				continue;
			}
			if (!$profile['attached']) {
				echo "x_carrier_acl: profile ".$profile['profile']." has no apply-inbound-acl ".$app->acl_name()." row\n";
				$exit = 2;
			}
			elseif (!$profile['enabled']) {
				echo "x_carrier_acl: profile ".$profile['profile']." apply-inbound-acl ".$app->acl_name()." row is disabled\n";
				$exit = 2;
			}
		}
	}

	exit($exit);

?>
