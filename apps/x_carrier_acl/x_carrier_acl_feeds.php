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

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//check permissions (host-global app: superadmin only, see app_config.php)
	if (!permission_exists('x_carrier_acl_feed_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//add the settings object
	$settings = new settings(["domain_uuid" => $_SESSION['domain_uuid'], "user_uuid" => $_SESSION['user_uuid']]);

	$app = new x_carrier_acl($database);

//process the http post
	if (!empty($_POST)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: x_carrier_acl_feeds.php');
				exit;
			}

		$action = $_POST['action'] ?? '';

		//run now: flag the enabled feeds and ask the path unit to start the worker
			if ($action == 'run_now' && permission_exists('x_carrier_acl_run_now')) {
				$database->execute("update v_x_carrier_acl_feeds set feed_run_requested = true where feed_enabled = true ", null);
				if (x_carrier_acl::request_run()) {
					message::add($text['message-run_requested']);
				}
				else {
					message::add($text['message-run_request_failed'],'negative');
				}
				header('Location: x_carrier_acl_feeds.php');
				exit;
			}

		//attach our list to the sip profiles; the web process never restarts a profile
			if ($action == 'attach' && permission_exists('x_carrier_acl_attach')) {
				$applier = new x_carrier_acl_apply($database, $app);
				$list = $applier->ensure_list();
				if ($list['error'] !== null) {
					message::add(escape($list['error']),'negative');
				}
				else {
					$result = $applier->attach_profiles(['internal', 'external', 'internal-ipv6', 'external-ipv6']);
					if ($result['error'] !== null) {
						message::add(escape($result['error']),'negative');
					}
					elseif (empty($result['attached'])) {
						message::add($text['message-attach_none']);
					}
					else {
						$commands = [];
						foreach ($result['attached'] as $profile) {
							$commands[] = "fs_cli -x 'sofia profile ".$profile." restart'";
						}
						message::add($text['message-attach_restart'].' '.escape(implode('; ', $commands)), 'alert', 15000);
					}
				}
				header('Location: x_carrier_acl_feeds.php');
				exit;
			}

		header('Location: x_carrier_acl_feeds.php');
		exit;
	}

//get the data
	$feeds = $app->feeds();
	$status = x_carrier_acl::firewall_status();
	$exported = x_carrier_acl::exported_generation();
	$acl_state = $app->acl_state();
	$attachment = $app->attachment_state();
	$stale_after = $app->setting_int('stale_after_days');

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_carrier_acl'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_acl']."</b><div class='count'>".number_format(count($feeds))."</div></div>\n";
	echo "	<div class='actions'>\n";
	if (permission_exists('x_carrier_acl_run_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-runs'],'icon'=>'list','link'=>'x_carrier_acl_runs.php']);
	}
	if (permission_exists('x_carrier_acl_entry_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-entries'],'icon'=>'network-wired','link'=>'x_carrier_acl_entries.php']);
	}
	if (permission_exists('x_carrier_acl_local_network_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-local_networks'],'icon'=>'house-signal','link'=>'x_carrier_acl_local_networks.php']);
	}
	if (permission_exists('x_carrier_acl_settings') && permission_exists('default_setting_view')) {
		echo button::create(['type'=>'button','label'=>$text['button-settings'],'icon'=>$settings->get('theme', 'button_icon_settings'),'link'=>PROJECT_PATH.'/core/default_settings/default_settings.php?search=x_carrier_acl','style'=>'margin-right: 15px;']);
	}
	if (permission_exists('x_carrier_acl_run_now')) {
		echo "<form method='post' class='inline'>\n";
		echo "<input type='hidden' name='action' value='run_now'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-run_now'],'icon'=>'play','id'=>'btn_run_now']);
		echo "</form>\n";
	}
	if (permission_exists('x_carrier_acl_feed_add')) {
		echo button::create(['type'=>'button','label'=>$text['button-add'],'icon'=>$settings->get('theme', 'button_icon_add'),'id'=>'btn_add','link'=>'x_carrier_acl_feed_edit.php']);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-x_carrier_acl']."\n";
	echo "<br /><br />\n";

//firewall status strip (state/status.json written by the root applier)
	echo "<div class='card'>\n";
	echo "<b>".$text['header-firewall']."</b><br><br>\n";
	if (!$app->setting_bool('firewall_enabled')) {
		echo $text['label-firewall_disabled']."<br>\n";
	}
	elseif ($status === null) {
		echo $text['label-firewall_inactive']."<br>\n";
	}
	else {
		$ok = (($status['result'] ?? '') === 'ok');
		echo "<table class='list'>\n";
		echo "<tr class='list-header'><th>".$text['label-backend']."</th><th>".$text['label-mode']."</th><th>".$text['label-generation_applied']."</th><th>".$text['label-generation_exported']."</th><th>".$text['label-entries']."</th><th>".$text['label-result']."</th><th>".$text['label-time']."</th></tr>\n";
		echo "<tr class='list-row'>";
		echo "<td>".escape($status['backend'] ?? '')."</td>";
		echo "<td>".escape($status['mode'] ?? '')."</td>";
		echo "<td>".escape($status['generation'] ?? '')."</td>";
		echo "<td>".escape($exported ?? '')."</td>";
		echo "<td>v4 ".escape($status['v4'] ?? 0)." / v6 ".escape($status['v6'] ?? 0);
		if (isset($status['local_v4'])) {
			echo " (".$text['label-local'].": v4 ".escape($status['local_v4'])." / v6 ".escape($status['local_v6'] ?? 0).")";
		}
		echo "</td>";
		echo "<td>".($ok ? escape($status['result']) : "<span style='color: #b00;'>".escape(($status['result'] ?? '').' '.($status['error'] ?? ''))."</span>")."</td>";
		echo "<td>".escape($status['ts'] ?? '')."</td>";
		echo "</tr>\n";
		echo "</table>\n";
		if ($exported !== null && (int)($status['generation'] ?? 0) < $exported) {
			echo "<br><span style='color: #b00;'>".$text['label-generation_lagging']."</span>\n";
		}
		if (($status['backend'] ?? '') === 'nft' && $app->firewall_mode() === 'allow') {
			echo "<br>".$text['label-nft_caveat']."\n";
		}
	}
	if ($app->firewall_mode() === 'restrict') {
		echo "<br><span style='color: #b00;'><b>".$text['label-restrict_warning']."</b></span>\n";
	}
	echo "</div>\n";
	echo "<br />\n";

//acl list and profile attachment
	echo "<div class='card'>\n";
	echo "<b>".$text['header-acl']."</b><br><br>\n";
	if (!$acl_state['exists']) {
		echo "<span style='color: #b00;'>".$text['label-acl_missing']."</span><br><br>\n";
	}
	elseif ($acl_state['renamed']) {
		echo "<span style='color: #b00;'>".$text['label-acl_renamed']." ".escape($acl_state['name'])."</span><br><br>\n";
	}
	else {
		echo $text['label-acl_name']." <b>".escape($acl_state['name'])."</b>";
		if (permission_exists('access_control_view')) {
			echo " (<a href='".PROJECT_PATH."/app/access_controls/access_control_edit.php?id=".urlencode($acl_state['uuid'])."'>".$text['label-view']."</a>)";
		}
		echo "<br><br>\n";
	}
	echo "<table class='list'>\n";
	echo "<tr class='list-header'><th>".$text['label-profile']."</th><th>".$text['label-attachment']."</th></tr>\n";
	foreach ($attachment as $row) {
		echo "<tr class='list-row'><td>".escape($row['profile']).(!$row['profile_enabled'] ? " (".$text['label-disabled'].")" : "")."</td><td>";
		if ($row['attached'] && $row['enabled']) {
			echo $text['label-attached'];
		}
		else {
			echo "<span style='color: #b00;'>".($row['attached'] ? $text['label-attached_disabled'] : $text['label-not_attached'])."</span>";
		}
		echo "</td></tr>\n";
	}
	echo "</table>\n";
	if (permission_exists('x_carrier_acl_attach')) {
		echo "<br>\n";
		echo "<form method='post'>\n";
		echo "<input type='hidden' name='action' value='attach'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-attach'],'icon'=>'link','id'=>'btn_attach']);
		echo "</form>\n";
	}
	echo "</div>\n";
	echo "<br />\n";

//feeds
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-feed_name']."</th>\n";
	echo "	<th class='hide-sm-dn'>".$text['label-feed_url']."</th>\n";
	echo "	<th>".$text['label-feed_parser']."</th>\n";
	echo "	<th>".$text['label-feed_enabled']."</th>\n";
	echo "	<th>".$text['label-feed_last_success']."</th>\n";
	echo "	<th>".$text['label-entries']."</th>\n";
	echo "	<th>".$text['label-feed_consecutive_failures']."</th>\n";
	echo "	<th>".$text['label-status']."</th>\n";
	echo "</tr>\n";
	foreach ($feeds as $row) {
		$url = 'x_carrier_acl_feed_edit.php?id='.urlencode($row['x_carrier_acl_feed_uuid']);
		$badges = [];
		$last_success = !empty($row['feed_last_success']) ? strtotime($row['feed_last_success']) : false;
		if ($stale_after > 0 && ($row['signaling_count'] + $row['media_count']) > 0 && ($last_success === false || $last_success < time() - $stale_after * 86400)) {
			$badges[] = "<span style='color: #b60;'>".$text['label-stale']."</span>";
		}
		if ((int)$row['pending_count'] > 0) {
			$badges[] = "<a href='x_carrier_acl_runs.php?feed=".urlencode($row['x_carrier_acl_feed_uuid'])."&status=pending_approval' style='color: #b00;'>".$text['label-pending_approval']."</a>";
		}
		echo "<tr class='list-row' href='".$url."'>\n";
		echo "	<td><a href='".$url."'>".escape($row['feed_name'])."</a></td>\n";
		echo "	<td class='overflow hide-sm-dn'>".escape($row['feed_url'])."</td>\n";
		echo "	<td>".escape($row['feed_parser']).($row['feed_parser'] === 'html_ip_scrape' ? " (".$text['label-best_effort'].")" : "")."</td>\n";
		echo "	<td>".(x_carrier_acl_diff::bool($row['feed_enabled']) ? $text['label-true'] : $text['label-false'])."</td>\n";
		echo "	<td>".escape($row['feed_last_success'] ?? '')."</td>\n";
		echo "	<td>".escape($row['signaling_count'])." / ".escape($row['media_count'])."</td>\n";
		echo "	<td>".escape($row['feed_consecutive_failures'] ?? 0)."</td>\n";
		echo "	<td>".implode(' ', $badges)."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
