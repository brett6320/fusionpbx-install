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
	if (!permission_exists('x_carrier_acl_run_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//add the settings object
	$settings = new settings(["domain_uuid" => $_SESSION['domain_uuid'], "user_uuid" => $_SESSION['user_uuid']]);

	$app = new x_carrier_acl($database);

//filters
	$filter_feed = is_uuid($_GET['feed'] ?? '') ? $_GET['feed'] : '';
	$filter_status = in_array($_GET['status'] ?? '', x_carrier_acl::statuses, true) ? $_GET['status'] : '';
	$run_uuid = is_uuid($_GET['id'] ?? '') ? $_GET['id'] : '';

//feeds for the filter and names
	$feed_names = [];
	foreach ($app->feeds() as $row) {
		$feed_names[$row['x_carrier_acl_feed_uuid']] = $row['feed_name'];
	}
	//local network changes (section 12) are runs without a feed
	$run_source = function (array $run) use ($feed_names, $text): string {
		if (empty($run['x_carrier_acl_feed_uuid']) && ($run['run_trigger'] ?? '') === x_carrier_acl_local::run_trigger) {
			return $text['label-local_networks'];
		}
		return (string)($feed_names[$run['x_carrier_acl_feed_uuid']] ?? $run['x_carrier_acl_feed_uuid'] ?? '');
	};

//token for the approve page
	$object = new token;
	$approve_token = $object->create(PROJECT_PATH.'/app/x_carrier_acl/x_carrier_acl_run_approve.php');

//show the header
	$document['title'] = $text['title-x_carrier_acl_runs'];
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_acl_runs']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'link'=>$run_uuid !== '' ? 'x_carrier_acl_runs.php' : 'x_carrier_acl_feeds.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

//detail view
	if ($run_uuid !== '') {
		$sql = "select r.*, u.username as approved_by_username from v_x_carrier_acl_runs r ";
		$sql .= "left join v_users u on u.user_uuid = r.run_approved_by ";
		$sql .= "where r.x_carrier_acl_run_uuid = :uuid ";
		$run = $database->select($sql, ['uuid' => $run_uuid], 'row');
		if (!is_array($run)) {
			echo $text['message-run_not_found'];
			require_once "resources/footer.php";
			exit;
		}
		$proposal = json_decode((string)$run['run_proposal'], true);
		$proposal = is_array($proposal) ? $proposal : [];

		echo "<div class='card'>\n";
		echo "<table class='list'>\n";
		$fields = [
			'label-feed_name' => $run_source($run),
			'label-run_started' => $run['run_started'], 'label-run_trigger' => $run['run_trigger'],
			'label-status' => $run['run_status'], 'label-http_status' => $run['run_http_status'],
			'label-feed_version' => $run['run_feed_version'], 'label-bytes' => $run['run_bytes'],
			'label-added' => $run['run_added'], 'label-removed' => $run['run_removed'],
			'label-rejected' => $run['run_rejected_count'], 'label-error' => $run['run_error'],
			'label-approved_by' => trim(($run['approved_by_username'] ?? '').' '.($run['run_approved_at'] ?? '')),
			'label-firewall_generation' => $run['run_firewall_generation'], 'label-firewall_result' => $run['run_firewall_result'],
		];
		foreach ($fields as $label => $value) {
			echo "<tr class='list-row'><td width='30%'>".$text[$label]."</td><td>".escape((string)$value)."</td></tr>\n";
		}
		echo "<tr class='list-row'><td>SHA-256</td><td>".escape((string)$run['run_fetched_sha256'])."</td></tr>\n";
		if (!empty($proposal['reasons'])) {
			echo "<tr class='list-row'><td>".$text['label-needs_approval']."</td><td>".implode("<br>", array_map('escape', $proposal['reasons']))."</td></tr>\n";
		}
		echo "</table>\n";

		//approve or reject a pending run
		if ($run['run_status'] === 'pending_approval' && permission_exists('x_carrier_acl_approve')) {
			echo "<br>\n";
			echo "<form method='post' action='x_carrier_acl_run_approve.php' class='inline'>\n";
			echo "<input type='hidden' name='id' value='".escape($run_uuid)."'>\n";
			echo "<input type='hidden' name='".$approve_token['name']."' value='".$approve_token['hash']."'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-approve'],'icon'=>'check','name'=>'action','value'=>'approve','id'=>'btn_approve','onclick'=>"return confirm('".escape($text['confirm-approve'])."');"]);
			echo button::create(['type'=>'submit','label'=>$text['button-reject'],'icon'=>'xmark','name'=>'action','value'=>'reject','id'=>'btn_reject','onclick'=>"return confirm('".escape($text['confirm-reject'])."');"]);
			echo "</form>\n";
		}
		echo "</div>\n";
		echo "<br />\n";

		$entry_table = function (string $title, array $rows, string $last) use ($text) {
			echo "<div class='card'>\n";
			echo "<b>".escape($title)." (".count($rows).")</b><br><br>\n";
			echo "<table class='list'>\n";
			echo "<tr class='list-header'><th>".$text['label-cidr']."</th><th>".$text['label-role']."</th><th>".$text['label-label']."</th><th>".escape($last)."</th></tr>\n";
			foreach ($rows as $row) {
				echo "<tr class='list-row'>";
				echo "<td>".escape($row['cidr'] ?? $row['value'] ?? '')."</td>";
				echo "<td>".escape($row['role'] ?? '')."</td>";
				echo "<td>".escape($row['label'] ?? '')."</td>";
				echo "<td>".escape(isset($row['reason']) ? $row['reason'] : (!empty($row['broad']) ? $text['label-broad'] : ''))."</td>";
				echo "</tr>\n";
			}
			echo "</table>\n";
			echo "</div>\n";
			echo "<br />\n";
		};
		$entry_table($text['label-added'], $proposal['added'] ?? [], $text['label-needs_approval']);
		$entry_table($text['label-removed'], $proposal['removed'] ?? [], '');
		$entry_table($text['label-rejected'], $proposal['rejected'] ?? [], $text['label-reason']);
		if (!empty($proposal['warnings'])) {
			echo "<div class='card'><b>".$text['label-warnings']."</b><br><br>".implode("<br>\n", array_map('escape', $proposal['warnings']))."</div><br />\n";
		}
		if (!empty($proposal['entries']) && $run['run_status'] === 'pending_approval') {
			$entry_table($text['label-proposed_set'], array_values($proposal['entries']), $text['label-needs_approval']);
		}
		require_once "resources/footer.php";
		exit;
	}

//list view
	$sql = "select x_carrier_acl_run_uuid, x_carrier_acl_feed_uuid, run_started, run_trigger, run_status, run_http_status, ";
	$sql .= "run_added, run_removed, run_rejected_count, run_error, run_firewall_generation, run_firewall_result ";
	$sql .= "from v_x_carrier_acl_runs where true ";
	$parameters = [];
	if ($filter_feed !== '') {
		$sql .= "and x_carrier_acl_feed_uuid = :feed_uuid ";
		$parameters['feed_uuid'] = $filter_feed;
	}
	if ($filter_status !== '') {
		$sql .= "and run_status = :status ";
		$parameters['status'] = $filter_status;
	}
	$sql .= "order by run_started desc limit 200 ";
	$runs = $database->select($sql, $parameters, 'all');

	echo "<form method='get' class='inline'>\n";
	echo "<select class='formfld' name='feed'><option value=''>".$text['label-all_feeds']."</option>\n";
	foreach ($feed_names as $uuid => $name) {
		echo "<option value='".escape($uuid)."' ".($filter_feed === $uuid ? "selected='selected'" : "").">".escape($name)."</option>\n";
	}
	echo "</select>\n";
	echo "<select class='formfld' name='status'><option value=''>".$text['label-all_statuses']."</option>\n";
	foreach (x_carrier_acl::statuses as $status) {
		echo "<option value='".escape($status)."' ".($filter_status === $status ? "selected='selected'" : "").">".escape($status)."</option>\n";
	}
	echo "</select>\n";
	echo button::create(['type'=>'submit','label'=>$text['button-search'],'icon'=>$settings->get('theme', 'button_icon_search')]);
	echo "</form>\n";
	echo "<br /><br />\n";

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'><th>".$text['label-run_started']."</th><th>".$text['label-feed_name']."</th><th>".$text['label-run_trigger']."</th><th>".$text['label-status']."</th><th>+ / -</th><th>".$text['label-rejected']."</th><th class='hide-sm-dn'>".$text['label-firewall_result']."</th><th class='hide-sm-dn'>".$text['label-error']."</th></tr>\n";
	foreach (is_array($runs) ? $runs : [] as $row) {
		$url = 'x_carrier_acl_runs.php?id='.urlencode($row['x_carrier_acl_run_uuid']);
		$pending = ($row['run_status'] === 'pending_approval');
		echo "<tr class='list-row' href='".$url."'>";
		echo "<td><a href='".$url."'>".escape($row['run_started'])."</a></td>";
		echo "<td>".escape($run_source($row))."</td>";
		echo "<td>".escape($row['run_trigger'])."</td>";
		echo "<td>".($pending ? "<b style='color: #b00;'>".escape($row['run_status'])."</b>" : escape($row['run_status']))."</td>";
		echo "<td>".escape((string)$row['run_added'])." / ".escape((string)$row['run_removed'])."</td>";
		echo "<td>".escape((string)$row['run_rejected_count'])."</td>";
		echo "<td class='hide-sm-dn'>".escape(trim($row['run_firewall_generation'].' '.$row['run_firewall_result']))."</td>";
		echo "<td class='overflow hide-sm-dn'>".escape((string)$row['run_error'])."</td>";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
