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

//check permissions
	if (!(permission_exists('x_carrier_acl_feed_add') || permission_exists('x_carrier_acl_feed_edit'))) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//add the settings object
	$settings = new settings(["domain_uuid" => $_SESSION['domain_uuid'], "user_uuid" => $_SESSION['user_uuid']]);

	$app = new x_carrier_acl($database);

//action add or update
	$feed_uuid = $_REQUEST['id'] ?? '';
	if (is_uuid($feed_uuid)) {
		$action = 'update';
		if (!permission_exists('x_carrier_acl_feed_edit')) {
			echo "access denied";
			exit;
		}
	}
	else {
		$action = 'add';
		$feed_uuid = '';
		if (!permission_exists('x_carrier_acl_feed_add')) {
			echo "access denied";
			exit;
		}
	}

//defaults
	$feed = [
		'feed_name' => '', 'feed_url' => 'https://', 'feed_parser' => 'cidr_list', 'feed_parser_options' => '{}',
		'feed_interval_minutes' => 360, 'feed_enabled' => 'false', 'feed_allow_private' => 'false',
		'feed_min_entries' => 1, 'feed_max_entries' => 5000,
		'feed_max_remove_pct' => '', 'feed_max_remove_abs' => '', 'feed_max_add_pct' => '', 'feed_max_add_abs' => '',
		'feed_tls_pin_sha256' => '', 'feed_description' => '',
	];
	if ($action == 'update') {
		$row = $app->feed($feed_uuid);
		if ($row === null) {
			message::add($text['message-feed_not_found'],'negative');
			header('Location: x_carrier_acl_feeds.php');
			exit;
		}
		$feed = array_merge($feed, $row);
		$feed['feed_enabled'] = x_carrier_acl_diff::bool($row['feed_enabled']) ? 'true' : 'false';
		$feed['feed_allow_private'] = x_carrier_acl_diff::bool($row['feed_allow_private']) ? 'true' : 'false';
	}

//process the http post
	if (!empty($_POST)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: x_carrier_acl_feeds.php');
				exit;
			}

		$post_action = $_POST['action'] ?? 'save';

		//delete: purges the feed's entries and nodes first so no orphan stays in the list (section 5.3)
			if (($post_action == 'delete' || $post_action == 'purge') && $action == 'update') {
				$delete = ($post_action == 'delete');
				if (!permission_exists($delete ? 'x_carrier_acl_feed_delete' : 'x_carrier_acl_purge')) {
					message::add($text['error-permission'],'negative');
					header('Location: x_carrier_acl_feed_edit.php?id='.urlencode($feed_uuid));
					exit;
				}
				$applier = new x_carrier_acl_apply($database, $app);
				$result = $applier->purge_feed($feed_uuid, $delete);
				if (!$result['ok']) {
					message::add(escape($result['error']),'negative');
					header('Location: x_carrier_acl_feed_edit.php?id='.urlencode($feed_uuid));
					exit;
				}
				$applier->reload_acl(null);
				//the worker regenerates the firewall snapshot because the database no longer matches it
				x_carrier_acl::request_run();
				x_carrier_acl::log(LOG_NOTICE, ($delete ? 'feed deleted' : 'feed entries purged').': '.$feed['feed_name'].' by '.($_SESSION['username'] ?? ''));
				message::add($delete ? $text['message-delete'] : $text['message-purged']);
				header('Location: '.($delete ? 'x_carrier_acl_feeds.php' : 'x_carrier_acl_feed_edit.php?id='.urlencode($feed_uuid)));
				exit;
			}

		//get the posted values
			$feed['feed_name'] = substr(trim($_POST['feed_name'] ?? ''), 0, 128);
			$feed['feed_url'] = trim($_POST['feed_url'] ?? '');
			$feed['feed_parser'] = $_POST['feed_parser'] ?? '';
			$feed['feed_parser_options'] = trim($_POST['feed_parser_options'] ?? '');
			$feed['feed_interval_minutes'] = trim($_POST['feed_interval_minutes'] ?? '');
			$feed['feed_enabled'] = (($_POST['feed_enabled'] ?? '') === 'true') ? 'true' : 'false';
			$feed['feed_allow_private'] = (($_POST['feed_allow_private'] ?? '') === 'true') ? 'true' : 'false';
			$feed['feed_min_entries'] = trim($_POST['feed_min_entries'] ?? '');
			$feed['feed_max_entries'] = trim($_POST['feed_max_entries'] ?? '');
			foreach (['feed_max_remove_pct', 'feed_max_remove_abs', 'feed_max_add_pct', 'feed_max_add_abs'] as $name) {
				$feed[$name] = trim($_POST[$name] ?? '');
			}
			$feed['feed_tls_pin_sha256'] = trim($_POST['feed_tls_pin_sha256'] ?? '');
			$feed['feed_description'] = substr(trim($_POST['feed_description'] ?? ''), 0, 1024);

		//validate
			$msg = [];
			if ($feed['feed_name'] === '') {
				$msg[] = $text['error-feed_name'];
			}
			$url_error = x_carrier_acl_fetch::check_url($feed['feed_url']);
			if ($url_error !== null) {
				$msg[] = $text['error-feed_url'].' ('.$url_error.')';
			}
			$parser = x_carrier_acl::parser((string)$feed['feed_parser']);
			if ($parser === null) {
				$msg[] = $text['error-feed_parser'];
			}
			else {
				[$options, $option_error] = x_carrier_acl::decode_options($feed['feed_parser_options']);
				if ($option_error !== null) {
					$msg[] = $text['error-feed_parser_options'].' ('.$option_error.')';
				}
				else {
					foreach ($parser->option_errors($options) as $error) {
						$msg[] = $text['error-feed_parser_options'].' ('.$error.')';
					}
					$feed['feed_parser_options'] = json_encode($options, JSON_UNESCAPED_SLASHES);
				}
			}
			if (!ctype_digit((string)$feed['feed_interval_minutes']) || (int)$feed['feed_interval_minutes'] < 15 || (int)$feed['feed_interval_minutes'] > 10080) {
				$msg[] = $text['error-feed_interval'];
			}
			if (!ctype_digit((string)$feed['feed_min_entries']) || !ctype_digit((string)$feed['feed_max_entries']) || (int)$feed['feed_max_entries'] < 1 || (int)$feed['feed_min_entries'] > (int)$feed['feed_max_entries'] || (int)$feed['feed_max_entries'] > 9990) {
				$msg[] = $text['error-feed_bounds'];
			}
			foreach (['feed_max_remove_pct', 'feed_max_remove_abs', 'feed_max_add_pct', 'feed_max_add_abs'] as $name) {
				if ($feed[$name] !== '' && (!ctype_digit($feed[$name]) || (int)$feed[$name] > 100000)) {
					$msg[] = $text['error-feed_threshold'];
					break;
				}
			}
			if ($feed['feed_tls_pin_sha256'] !== '') {
				$pin = x_carrier_acl_fetch::normalise_pin($feed['feed_tls_pin_sha256']);
				if ($pin === null) {
					$msg[] = $text['error-feed_pin'];
				}
				else {
					$feed['feed_tls_pin_sha256'] = $pin;
				}
			}

		//save
			if (empty($msg)) {
				if ($action == 'add') {
					$feed_uuid = uuid();
				}
				$array['x_carrier_acl_feeds'][0]['x_carrier_acl_feed_uuid'] = $feed_uuid;
				$array['x_carrier_acl_feeds'][0]['feed_name'] = $feed['feed_name'];
				$array['x_carrier_acl_feeds'][0]['feed_url'] = $feed['feed_url'];
				$array['x_carrier_acl_feeds'][0]['feed_parser'] = $feed['feed_parser'];
				$array['x_carrier_acl_feeds'][0]['feed_parser_options'] = $feed['feed_parser_options'];
				$array['x_carrier_acl_feeds'][0]['feed_interval_minutes'] = (int)$feed['feed_interval_minutes'];
				$array['x_carrier_acl_feeds'][0]['feed_enabled'] = $feed['feed_enabled'];
				$array['x_carrier_acl_feeds'][0]['feed_allow_private'] = $feed['feed_allow_private'];
				$array['x_carrier_acl_feeds'][0]['feed_min_entries'] = (int)$feed['feed_min_entries'];
				$array['x_carrier_acl_feeds'][0]['feed_max_entries'] = (int)$feed['feed_max_entries'];
				foreach (['feed_max_remove_pct', 'feed_max_remove_abs', 'feed_max_add_pct', 'feed_max_add_abs'] as $name) {
					$array['x_carrier_acl_feeds'][0][$name] = ($feed[$name] === '') ? null : (int)$feed[$name];
				}
				$array['x_carrier_acl_feeds'][0]['feed_tls_pin_sha256'] = $feed['feed_tls_pin_sha256'];
				$array['x_carrier_acl_feeds'][0]['feed_description'] = $feed['feed_description'];
				if ($action == 'add') {
					$array['x_carrier_acl_feeds'][0]['feed_consecutive_failures'] = 0;
					$array['x_carrier_acl_feeds'][0]['feed_run_requested'] = 'false';
				}
				$database->app_name = x_carrier_acl::app_name;
				$database->app_uuid = x_carrier_acl::app_uuid;
				$saved = $database->save($array);
				unset($array);
				if ($saved) {
					message::add($action == 'add' ? $text['message-add'] : $text['message-update']);
					header('Location: x_carrier_acl_feed_edit.php?id='.urlencode($feed_uuid));
					exit;
				}
				$msg[] = $text['error-save'];
			}
			foreach ($msg as $m) {
				message::add(escape($m),'negative');
			}
	}

//create tokens: one for this page, one for the preview page (the preview button posts the unsaved form there)
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);
	$preview_token = $object->create(PROJECT_PATH.'/app/x_carrier_acl/x_carrier_acl_preview.php');

//show the header
	$document['title'] = $text['title-x_carrier_acl_feed'];
	require_once "resources/header.php";

	echo "<form method='post' name='frm' id='frm'>\n";
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_acl_feed']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'x_carrier_acl_feeds.php']);
	//button::create has no formaction; the preview posts the unsaved form to the preview page in a new tab
	echo "<button type='submit' class='btn btn-default' id='btn_preview' formaction='x_carrier_acl_preview.php' formtarget='_blank'><span class='fa-solid fa-eye fa-fw'></span><span class='button-label pad'>".$text['button-preview']."</span></button>\n";
	if ($action == 'update' && permission_exists('x_carrier_acl_purge')) {
		echo button::create(['type'=>'submit','label'=>$text['button-purge'],'icon'=>'eraser','id'=>'btn_purge','name'=>'action','value'=>'purge','onclick'=>"return confirm('".escape($text['confirm-purge'])."');"]);
	}
	if ($action == 'update' && permission_exists('x_carrier_acl_feed_delete')) {
		echo button::create(['type'=>'submit','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_delete','name'=>'action','value'=>'delete','onclick'=>"return confirm('".escape($text['confirm-delete_feed'])."');"]);
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_save','name'=>'action','value'=>'save','style'=>'margin-left: 15px;']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	$text_row = function ($name, $required, $value, $extra = '') use ($text) {
		echo "<tr>\n";
		echo "<td width='30%' class='".($required ? 'vncellreq' : 'vncell')."' valign='top' align='left' nowrap='nowrap'>".$text['label-'.$name]."</td>\n";
		echo "<td width='70%' class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='".$name."' value=\"".escape((string)$value)."\" ".$extra.">\n";
		echo "<br />".$text['description-'.$name]."\n";
		echo "</td>\n";
		echo "</tr>\n";
	};
	$bool_row = function ($name, $value) use ($text) {
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-'.$name]."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<select class='formfld' name='".$name."'>\n";
		echo "		<option value='true' ".($value === 'true' ? "selected='selected'" : "").">".$text['label-true']."</option>\n";
		echo "		<option value='false' ".($value !== 'true' ? "selected='selected'" : "").">".$text['label-false']."</option>\n";
		echo "	</select>\n";
		echo "<br />".$text['description-'.$name]."\n";
		echo "</td>\n";
		echo "</tr>\n";
	};

	$text_row('feed_name', true, $feed['feed_name'], "maxlength='128' required='required'");
	$text_row('feed_url', true, $feed['feed_url'], "maxlength='2048' required='required' style='width: 90%;'");

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-feed_parser']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='feed_parser'>\n";
	foreach (array_keys(x_carrier_acl::parsers) as $name) {
		echo "		<option value='".escape($name)."' ".($feed['feed_parser'] === $name ? "selected='selected'" : "").">".escape($name).($name === 'html_ip_scrape' ? ' ('.$text['label-best_effort'].')' : '')."</option>\n";
	}
	echo "	</select>\n";
	echo "<br />".$text['description-feed_parser']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-feed_parser_options']."</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<textarea class='formfld' name='feed_parser_options' rows='8' style='width: 90%; font-family: monospace;'>".escape((string)$feed['feed_parser_options'])."</textarea>\n";
	echo "<br />".$text['description-feed_parser_options']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	$text_row('feed_interval_minutes', true, $feed['feed_interval_minutes'], "inputmode='numeric'");
	$bool_row('feed_enabled', $feed['feed_enabled']);
	$bool_row('feed_allow_private', $feed['feed_allow_private']);
	$text_row('feed_min_entries', true, $feed['feed_min_entries'], "inputmode='numeric'");
	$text_row('feed_max_entries', true, $feed['feed_max_entries'], "inputmode='numeric'");
	$text_row('feed_max_remove_pct', false, $feed['feed_max_remove_pct'], "inputmode='numeric'");
	$text_row('feed_max_remove_abs', false, $feed['feed_max_remove_abs'], "inputmode='numeric'");
	$text_row('feed_max_add_pct', false, $feed['feed_max_add_pct'], "inputmode='numeric'");
	$text_row('feed_max_add_abs', false, $feed['feed_max_add_abs'], "inputmode='numeric'");
	$text_row('feed_tls_pin_sha256', false, $feed['feed_tls_pin_sha256'], "maxlength='80'");
	$text_row('feed_description', false, $feed['feed_description'], "maxlength='1024'");

	if ($action == 'update') {
		echo "<tr><td class='vncell'>".$text['label-feed_last_checked']."</td><td class='vtable'>".escape($feed['feed_last_checked'] ?? '')."</td></tr>\n";
		echo "<tr><td class='vncell'>".$text['label-feed_last_success']."</td><td class='vtable'>".escape($feed['feed_last_success'] ?? '')."</td></tr>\n";
		echo "<tr><td class='vncell'>".$text['label-feed_last_applied']."</td><td class='vtable'>".escape($feed['feed_last_applied'] ?? '')."</td></tr>\n";
		echo "<tr><td class='vncell'>".$text['label-feed_consecutive_failures']."</td><td class='vtable'>".escape($feed['feed_consecutive_failures'] ?? 0)."</td></tr>\n";
	}

	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	if ($action == 'update') {
		echo "<input type='hidden' name='id' value='".escape($feed_uuid)."'>\n";
	}
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "<input type='hidden' name='".$preview_token['name']."' value='".$preview_token['hash']."'>\n";
	echo "</form>\n";

//include the footer
	require_once "resources/footer.php";

?>
