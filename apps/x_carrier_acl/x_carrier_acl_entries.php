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
	if (!permission_exists('x_carrier_acl_entry_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//add the settings object
	$settings = new settings(["domain_uuid" => $_SESSION['domain_uuid'], "user_uuid" => $_SESSION['user_uuid']]);

	$app = new x_carrier_acl($database);
	$acl_state = $app->acl_state();

//filter
	$filter_feed = is_uuid($_GET['feed'] ?? '') ? $_GET['feed'] : '';
	$feed_names = [];
	foreach ($app->feeds() as $row) {
		$feed_names[$row['x_carrier_acl_feed_uuid']] = $row['feed_name'];
	}

//get the entries
	$sql = "select e.*, f.feed_name from v_x_carrier_acl_entries e ";
	$sql .= "join v_x_carrier_acl_feeds f on f.x_carrier_acl_feed_uuid = e.x_carrier_acl_feed_uuid ";
	$parameters = [];
	if ($filter_feed !== '') {
		$sql .= "where e.x_carrier_acl_feed_uuid = :feed_uuid ";
		$parameters['feed_uuid'] = $filter_feed;
	}
	$sql .= "order by f.feed_name asc, e.entry_family asc, e.entry_role asc, e.entry_cidr asc ";
	$entries = $database->select($sql, $parameters, 'all');
	$entries = is_array($entries) ? $entries : [];

//show the header
	$document['title'] = $text['title-x_carrier_acl_entries'];
	require_once "resources/header.php";

	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['title-x_carrier_acl_entries']."</b><div class='count'>".number_format(count($entries))."</div></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'link'=>'x_carrier_acl_feeds.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";
	echo $text['description-entries']."<br /><br />\n";

	echo "<form method='get' class='inline'>\n";
	echo "<select class='formfld' name='feed' onchange='this.form.submit();'><option value=''>".$text['label-all_feeds']."</option>\n";
	foreach ($feed_names as $uuid => $name) {
		echo "<option value='".escape($uuid)."' ".($filter_feed === $uuid ? "selected='selected'" : "").">".escape($name)."</option>\n";
	}
	echo "</select>\n";
	echo "</form>\n";
	echo "<br /><br />\n";

	$node_link = ($acl_state['exists'] && permission_exists('access_control_view'))
		? PROJECT_PATH.'/app/access_controls/access_control_edit.php?id='.urlencode((string)$acl_state['uuid'])
		: null;

	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'><th>".$text['label-feed_name']."</th><th>".$text['label-cidr']."</th><th>".$text['label-role']."</th><th>".$text['label-label']."</th><th class='hide-sm-dn'>".$text['label-first_seen']."</th><th class='hide-sm-dn'>".$text['label-last_seen']."</th><th>".$text['label-broad']."</th><th class='hide-sm-dn'>".$text['label-node']."</th></tr>\n";
	foreach ($entries as $row) {
		echo "<tr class='list-row'>";
		echo "<td>".escape($row['feed_name'])."</td>";
		echo "<td>".escape($row['entry_cidr'])."</td>";
		echo "<td>".escape($row['entry_role'])."</td>";
		echo "<td>".escape((string)$row['entry_label'])."</td>";
		echo "<td class='hide-sm-dn'>".escape((string)$row['entry_first_seen'])."</td>";
		echo "<td class='hide-sm-dn'>".escape((string)$row['entry_last_seen'])."</td>";
		echo "<td>".(x_carrier_acl_diff::bool($row['entry_broad_approved']) ? $text['label-approved'] : '')."</td>";
		echo "<td class='hide-sm-dn'>";
		if (!empty($row['access_control_node_uuid'])) {
			echo $node_link !== null ? "<a href='".$node_link."'>".escape($row['access_control_node_uuid'])."</a>" : escape($row['access_control_node_uuid']);
		}
		echo "</td>";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
