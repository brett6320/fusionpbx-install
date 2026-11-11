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
	if (!permission_exists('x_provision_overlay_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//the domain is always the session domain
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';
	if (!is_uuid($domain_uuid)) {
		echo "access denied";
		exit;
	}

//get the filter: '' = overrides only, '_all' = everything, otherwise a vendor
	$vendor_filter = $_GET['vendor'] ?? '';
	if ($vendor_filter !== '' && $vendor_filter !== '_all' && !provision_overlay::valid_name($vendor_filter)) {
		$vendor_filter = '';
	}

//get the data
	$overlay = new provision_overlay(['database' => $database]);
	$index = $overlay->template_index($domain_uuid);
	$status = $overlay->status();
	$device_vendors = $overlay->get_device_vendors() ?? [];
	$can_edit_domain = permission_exists('x_provision_overlay_domain_edit');
	$can_edit_global = permission_exists('x_provision_overlay_global_edit');

//vendors for the filter, and overlay-only vendors without a vendor row
	$vendors = [];
	$vendors_missing = [];
	foreach ($index as $t => $row) {
		$vendor = explode('/', $t, 2)[0];
		$vendors[$vendor] = true;
		if (!$row['upstream'] && ($row['global'] || $row['domain']) && !isset($device_vendors[$vendor])) {
			$vendors_missing[$vendor] = true;
		}
	}
	ksort($vendors, SORT_STRING);

//show the header
	$document['title'] = $text['title-x_provision_overlay'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-x_provision_overlay']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-assets'],'icon'=>'image','link'=>'x_provision_overlay_assets.php']);
	echo button::create(['type'=>'button','label'=>$text['button-build_status'],'icon'=>'cogs','link'=>'x_provision_overlay_build.php']);
	echo "		<form id='form_filter' class='inline' method='get'>\n";
	echo "		<select class='formfld' name='vendor' onchange=\"document.getElementById('form_filter').submit();\">\n";
	echo "			<option value=''".($vendor_filter === '' ? " selected='selected'" : '').">".$text['option-overrides_only']."</option>\n";
	echo "			<option value='_all'".($vendor_filter === '_all' ? " selected='selected'" : '').">".$text['option-all_templates']."</option>\n";
	foreach ($vendors as $vendor => $unused) {
		echo "			<option value='".escape($vendor)."'".($vendor_filter === $vendor ? " selected='selected'" : '').">".escape($vendor)."</option>\n";
	}
	echo "		</select>\n";
	echo "		</form>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-x_provision_overlay']."\n";
	echo "<br /><br />\n";

//build summary
	echo "<div class='card'>\n";
	if ($status['disabled']) {
		echo "<b>".$text['label-overlay_disabled']."</b><br>\n";
	}
	elseif ($status['link_state'] !== 'ours') {
		echo "<b>".$text['label-link_problem']."</b><br>\n";
	}
	if (is_array($status['build'])) {
		echo $text['label-build'].": ".escape($status['current_id'])." &nbsp; ";
		echo $text['label-upstream_commit'].": ".escape(substr((string) ($status['build']['upstream_commit'] ?? ''), 0, 12))." &nbsp; ";
		echo $text['label-generated_at'].": ".escape((string) ($status['build']['generated_at'] ?? ''))."\n";
		if (($status['build']['upstream_commit'] ?? '') !== $status['upstream_commit']) {
			echo "<br><i>".$text['label-upstream_changed']."</i>\n";
		}
	}
	else {
		echo $text['label-no_build']."\n";
	}
	echo "</div>\n";
	echo "<br />\n";

//overlay-only vendors that the device template dropdown will not list
	if (!empty($vendors_missing)) {
		echo "<div class='card'>\n";
		echo $text['warning-vendor_missing'].": ".escape(implode(', ', array_keys($vendors_missing)))."\n";
		echo "</div>\n";
		echo "<br />\n";
	}

//new template file
	if ($can_edit_domain || $can_edit_global) {
		echo "<div class='card'>\n";
		echo "<b>".$text['header-new_file']."</b><br><br>\n";
		echo $text['description-new_file']."<br><br>\n";
		echo "<form method='get' action='x_provision_overlay_edit.php'>\n";
		echo "<input type='hidden' name='new' value='1'>\n";
		echo "<select class='formfld' name='scope'>\n";
		if ($can_edit_domain) {
			echo "	<option value='domain'>".$text['option-scope_domain']."</option>\n";
		}
		if ($can_edit_global) {
			echo "	<option value='global'>".$text['option-scope_global']."</option>\n";
		}
		echo "</select>\n";
		echo "<input class='formfld' type='text' name='vendor' maxlength='64' placeholder='".escape($text['label-vendor'])."' pattern='[A-Za-z0-9._{}$-]{1,64}' required='required' style='width: 140px;'>\n";
		echo "<input class='formfld' type='text' name='model' maxlength='64' placeholder='".escape($text['label-model'])."' pattern='[A-Za-z0-9._{}$-]{1,64}' required='required' style='width: 140px;'>\n";
		echo "<input class='formfld' type='text' name='file' maxlength='64' placeholder='".escape($text['label-file'])."' pattern='[A-Za-z0-9._{}$-]{1,64}' required='required' style='width: 180px;'>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-open_editor'],'icon'=>$settings->get('theme', 'button_icon_add')]);
		echo "</form>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

//template list
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-template']."</th>\n";
	echo "	<th>".$text['label-file']."</th>\n";
	echo "	<th>".$text['label-status']."</th>\n";
	if ($can_edit_domain || $can_edit_global) {
		echo "	<th class='right'>".$text['label-actions']."</th>\n";
	}
	echo "</tr>\n";
	$rows_shown = 0;
	foreach ($index as $t => $row) {
		[$vendor, $model] = explode('/', $t, 2);
		$overridden = $row['global'] || $row['domain'];
		if ($vendor_filter === '' && !$overridden) {
			continue;
		}
		if ($vendor_filter !== '' && $vendor_filter !== '_all' && $vendor_filter !== $vendor) {
			continue;
		}
		foreach ($row['files'] as $file => $layers) {
			if ($vendor_filter === '' && !$layers['global'] && !$layers['domain']) {
				continue;
			}

			//status for the current domain: the most specific layer wins
			$labels = [];
			if ($layers['domain']) {
				$labels[] = $text['status-domain_override'];
			}
			if ($layers['global']) {
				$labels[] = $text['status-global_override'];
			}
			if (empty($labels)) {
				$labels[] = $text['status-upstream'];
			}
			if (!$row['upstream'] && ($layers['global'] || $layers['domain'])) {
				$labels[] = $text['status-template_missing'];
			}
			elseif (!$layers['upstream'] && ($layers['global'] || $layers['domain'])) {
				//an override whose file no longer exists upstream (a domain override of a global-only file counts too)
				$labels[] = $text['status-orphaned'];
			}

			$query = 'vendor='.urlencode($vendor).'&model='.urlencode($model).'&file='.urlencode($file);
			echo "<tr class='list-row'>\n";
			echo "	<td>".escape($t)."</td>\n";
			echo "	<td>".escape($file)."</td>\n";
			echo "	<td>".escape(implode(', ', $labels))."</td>\n";
			if ($can_edit_domain || $can_edit_global) {
				echo "	<td class='right no-wrap'>";
				if ($can_edit_domain) {
					echo button::create(['type'=>'button','label'=>$text['button-edit_domain'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>'x_provision_overlay_edit.php?scope=domain&'.$query]);
				}
				if ($can_edit_global) {
					echo button::create(['type'=>'button','label'=>$text['button-edit_global'],'icon'=>$settings->get('theme', 'button_icon_edit'),'link'=>'x_provision_overlay_edit.php?scope=global&'.$query]);
				}
				echo "</td>\n";
			}
			echo "</tr>\n";
			$rows_shown++;
		}
	}
	if ($rows_shown === 0) {
		echo "<tr class='list-row'><td colspan='4'>".($vendor_filter === '' ? $text['label-no_overrides'] : $text['label-no_templates'])."</td></tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//include the footer
	require_once "resources/footer.php";

?>
