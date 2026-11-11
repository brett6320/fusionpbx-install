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
	Feed preview (docs/design/carrier_acl.md section 8). POST only, token validated. Fetches the (unsaved) feed in
	the web request with the same fetcher and validator the worker uses and renders the would-be diff. Writes
	nothing. php-fpm is not covered by the worker unit's IPAddressDeny, so the fetch refuses private destinations.
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

//post only, with a valid token
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: x_carrier_acl_feeds.php');
		exit;
	}
	$token = new token;
	if (!$token->validate($_SERVER['PHP_SELF'])) {
		message::add($text['message-invalid_token'],'negative');
		header('Location: x_carrier_acl_feeds.php');
		exit;
	}

	$app = new x_carrier_acl($database);

//posted feed
	$feed = [
		'x_carrier_acl_feed_uuid' => is_uuid($_POST['id'] ?? '') ? $_POST['id'] : null,
		'feed_url' => trim($_POST['feed_url'] ?? ''),
		'feed_parser' => (string)($_POST['feed_parser'] ?? ''),
		'feed_parser_options' => (string)($_POST['feed_parser_options'] ?? ''),
		'feed_allow_private' => (($_POST['feed_allow_private'] ?? '') === 'true'),
		'feed_min_entries' => ctype_digit((string)($_POST['feed_min_entries'] ?? '')) ? (int)$_POST['feed_min_entries'] : 1,
		'feed_max_entries' => ctype_digit((string)($_POST['feed_max_entries'] ?? '')) ? (int)$_POST['feed_max_entries'] : 5000,
		'feed_tls_pin_sha256' => trim($_POST['feed_tls_pin_sha256'] ?? ''),
	];
	foreach (['feed_max_remove_pct', 'feed_max_remove_abs', 'feed_max_add_pct', 'feed_max_add_abs'] as $name) {
		$feed[$name] = ctype_digit((string)($_POST[$name] ?? '')) ? (int)$_POST[$name] : null;
	}

//run the pipeline
	$error = null;
	$response = null;
	$parsed = null;
	$validated = null;
	$diff = null;
	$reasons = [];
	$parser = x_carrier_acl::parser($feed['feed_parser']);
	[$options, $option_error] = x_carrier_acl::decode_options($feed['feed_parser_options']);
	if ($parser === null) {
		$error = $text['error-feed_parser'];
	}
	elseif ($option_error !== null) {
		$error = $text['error-feed_parser_options'].' ('.$option_error.')';
	}
	elseif (!empty($option_errors = $parser->option_errors($options))) {
		$error = $text['error-feed_parser_options'].' ('.implode('; ', $option_errors).')';
	}
	else {
		$fetcher = new x_carrier_acl_fetch();
		$response = $fetcher->fetch($feed['feed_url'], ['pin' => $feed['feed_tls_pin_sha256'], 'check_destination' => true, 'certinfo' => true]);
		if (!$response['ok']) {
			$error = $text['label-fetch_failed'].' '.$response['error'];
		}
		else {
			try {
				$parsed = $parser->parse($response['body'], $options);
				$validator = x_carrier_acl_validate::for_feed($app, $feed, $app->own_addresses());
				$validated = $validator->validate($parsed['entries']);
				$current = $feed['x_carrier_acl_feed_uuid'] !== null ? x_carrier_acl_diff::current($database, $feed['x_carrier_acl_feed_uuid']) : [];
				$diff = x_carrier_acl_diff::compute($current, $validated['accepted']);
				$reasons = x_carrier_acl_diff::gate($current, $validated['accepted'], $diff, $app->thresholds($feed));
				if ($validated['error'] !== null) {
					$error = $text['label-validation_failed'].' '.$validated['error'];
				}
			}
			catch (Throwable $e) {
				$error = $text['label-parse_failed'].' '.$e->getMessage();
			}
		}
	}

//show the header
	$document['title'] = $text['title-x_carrier_acl_preview'];
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_acl_preview']."</b></div>\n";
	echo "	<div class='actions'></div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";
	echo $text['description-preview']."<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-row'><td>".$text['label-feed_url']."</td><td>".escape($feed['feed_url'])."</td></tr>\n";
	if (is_array($response)) {
		echo "<tr class='list-row'><td>".$text['label-http_status']."</td><td>".escape($response['status'])."</td></tr>\n";
		echo "<tr class='list-row'><td>".$text['label-content_type']."</td><td>".escape($response['content_type'] ?? '')."</td></tr>\n";
		echo "<tr class='list-row'><td>".$text['label-tls_subject']."</td><td>".escape($response['tls_subject'] ?? '')."</td></tr>\n";
		echo "<tr class='list-row'><td>".$text['label-bytes']."</td><td>".escape($response['bytes'])."</td></tr>\n";
		echo "<tr class='list-row'><td>SHA-256</td><td>".escape($response['sha256'] ?? '')."</td></tr>\n";
	}
	if (is_array($parsed)) {
		echo "<tr class='list-row'><td>".$text['label-feed_version']."</td><td>".escape($parsed['version'] ?? '')."</td></tr>\n";
	}
	if ($error !== null) {
		echo "<tr class='list-row'><td>".$text['label-error']."</td><td style='color: #b00;'>".escape($error)."</td></tr>\n";
	}
	if (!empty($reasons)) {
		echo "<tr class='list-row'><td>".$text['label-needs_approval']."</td><td>".escape(implode('; ', $reasons))."</td></tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//diff tables
	$entry_table = function (string $title, array $rows, bool $show_reason = false) use ($text) {
		echo "<div class='card'>\n";
		echo "<b>".escape($title)." (".count($rows).")</b><br><br>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'><th>".$text['label-cidr']."</th><th>".$text['label-role']."</th><th>".$text['label-label']."</th><th>".($show_reason ? $text['label-reason'] : $text['label-needs_approval'])."</th></tr>\n";
		foreach ($rows as $row) {
			echo "<tr class='list-row'>";
			echo "<td>".escape($row['cidr'] ?? $row['value'] ?? $row['entry_cidr'] ?? '')."</td>";
			echo "<td>".escape($row['role'] ?? $row['entry_role'] ?? '')."</td>";
			echo "<td>".escape($row['label'] ?? $row['entry_label'] ?? '')."</td>";
			echo "<td>".escape($show_reason ? ($row['reason'] ?? '') : (!empty($row['broad']) ? $text['label-broad'] : ''))."</td>";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
	};
	if (is_array($diff)) {
		$entry_table($text['label-added'], $diff['added']);
		$entry_table($text['label-removed'], $diff['removed']);
		$entry_table($text['label-unchanged'], $diff['unchanged']);
	}
	if (is_array($validated)) {
		$entry_table($text['label-rejected'], $validated['rejected'], true);
		if (!empty($validated['warnings'])) {
			echo "<div class='card'><b>".$text['label-warnings']."</b><br><br>".implode("<br>\n", array_map('escape', $validated['warnings']))."</div><br />\n";
		}
	}

//raw body, first 2 KB
	if (is_array($response) && $response['ok'] && $response['body'] !== '') {
		echo "<div class='card'>\n";
		echo "<b>".$text['label-raw_body']."</b><br><br>\n";
		echo "<pre style='white-space: pre-wrap; word-break: break-all;'>".escape(substr($response['body'], 0, 2048))."</pre>\n";
		echo "</div>\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
