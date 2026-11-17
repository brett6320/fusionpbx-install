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
	require_once __DIR__ . "/resources/classes/provision_wallpapers.php";

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
	$domain_name = (string) ($_SESSION['domain_name'] ?? '');
	if (!is_uuid($domain_uuid)) {
		echo "access denied";
		exit;
	}

//scope: this domain, or the system defaults (superadmin)
	$can_global = permission_exists('x_provision_overlay_wallpaper_global');
	$scope_global = (($_REQUEST['scope'] ?? '') === 'global');
	if ($scope_global && !$can_global) {
		echo "access denied";
		exit;
	}
	$scope = $scope_global ? provision_wallpapers::global_scope : $domain_uuid;
	$can_edit = $scope_global ? $can_global : permission_exists('x_provision_overlay_asset_edit');
	$page = 'x_provision_overlay_wallpapers.php'.($scope_global ? '?scope=global' : '');

//preview of a stored image for the admin's browser (the phone url uses the domain name as host)
	if (isset($_GET['preview'])) {
		$asset = provision_wallpapers::file_info($domain_uuid, (string) $_GET['preview']);
		if ($asset === null) {
			http_response_code(404);
			exit;
		}
		header('Content-Type: '.$asset['mime']);
		header('Content-Length: '.filesize($asset['path']));
		header('X-Content-Type-Options: nosniff');
		header("Content-Security-Policy: default-src 'none'");
		header('Cache-Control: private, max-age=86400');
		readfile($asset['path']);
		exit;
	}

//get the upload limit
	$asset_max_bytes = (int) $settings->get('x_provision_overlay', 'asset_max_bytes', provision_overlay::asset_default_max_bytes);
	if ($asset_max_bytes < 1) {
		$asset_max_bytes = provision_overlay::asset_default_max_bytes;
	}

//create the objects
	$overlay = new provision_overlay(['database' => $database]);
	$wallpapers = new provision_wallpapers(['database' => $database, 'overlay' => $overlay]);

//process the http post
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: '.$page);
				exit;
			}

		//every action changes the scope's wallpapers
			if (!$can_edit) {
				message::add($text['error-permission'], 'negative');
				header('Location: '.$page);
				exit;
			}

		$action = $_POST['action'] ?? '';
		$group = (string) ($_POST['group'] ?? '');
		$username = (string) ($_SESSION['username'] ?? '');

		//upload or remove the image of a group
			$result = null;
			if ($action === 'upload') {
				$result = $wallpapers->store_upload($scope, $group, $_FILES['wallpaper'] ?? [], $asset_max_bytes, $username);
				$done = $scope_global ? $text['message-wallpaper_saved_global'] : $text['message-wallpaper_saved'];
			}
			if ($action === 'remove') {
				$result = $wallpapers->remove($scope, $group, $username);
				$done = $scope_global ? $text['message-wallpaper_removed_global'] : $text['message-wallpaper_removed'];
			}
			if (is_array($result)) {
				if ($result['error'] === null) {
					message::add($done);
					foreach ($result['warnings'] ?? [] as $warning) {
						message::add($text[$warning] ?? escape($warning), 'alert');
					}
					if (is_array($result['sync']) && !$result['sync']['success']) {
						provision_wallpapers::log_error('sync '.$domain_uuid, $result['sync']['error']);
						message::add($text['error-wallpaper_sync'], 'negative');
					}
				}
				else {
					message::add($text[$result['error']] ?? $text['error-asset_store'], 'negative');
				}
			}

		header('Location: '.$page);
		exit;
	}

//get the data
	$specs = provision_wallpapers::group_specs();
	$meta = provision_wallpapers::read_meta($scope);
	$meta_error = ($meta === null);
	$meta = $meta ?? ['groups' => [], 'retired' => [], 'unrecorded' => []];
	$analysis = null;
	$analysis_error = '';
	if (!$scope_global) {
		try {
			$analysis = $wallpapers->analyze($domain_uuid, $domain_name);
		}
		catch (Throwable $e) {
			provision_wallpapers::log_error('analyze '.$domain_uuid, $e->getMessage());
			$analysis_error = $text['error-wallpaper_analysis'];
		}
	}

//devices per template and group, devices that are not linked
	$template_devices = [];
	$not_linked = [];
	if (is_array($analysis)) {
		foreach ($analysis['devices'] as $device) {
			$template_devices[$device['group']][$device['template']] = ($template_devices[$device['group']][$device['template']] ?? 0) + 1;
			if ($device['state'] !== 'managed' && $device['state'] !== 'none') {
				$not_linked[] = $device;
			}
		}
	}

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_provision_overlay_wallpapers'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-x_provision_overlay_wallpapers']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'link'=>'x_provision_overlay.php']);
	if ($can_global) {
		echo button::create(['type'=>'button','label'=>$text['button-wallpapers_domain'],'icon'=>'image','link'=>'x_provision_overlay_wallpapers.php','style'=>($scope_global ? '' : 'font-weight: bold;')]);
		echo button::create(['type'=>'button','label'=>$text['button-wallpapers_global'],'icon'=>'globe','link'=>'x_provision_overlay_wallpapers.php?scope=global','style'=>($scope_global ? 'font-weight: bold;' : '')]);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo ($scope_global ? $text['description-wallpapers_global'] : $text['description-x_provision_overlay_wallpapers'])."\n";
	echo "<br /><br />\n";

//warnings
	$max_side = provision_wallpapers::max_source_side();
	if ($can_edit && $max_side > 0 && $max_side < provision_overlay::asset_max_dimension) {
		echo "<div class='card'>".str_replace(['{max}', '{limit}'], [escape((string) $max_side), escape((string) ini_get('memory_limit'))], $text['warning-wallpaper_memory'])."</div>\n";
		echo "<br />\n";
	}
	if ($meta_error) {
		provision_wallpapers::log_error('metadata '.$scope, 'cannot read '.provision_wallpapers::scope_dir($scope).'/'.provision_wallpapers::meta_file);
		echo "<div class='card'>".$text['error-wallpaper_meta']."</div>\n";
		echo "<br />\n";
	}
	if ($analysis_error !== '') {
		echo "<div class='card'>".$analysis_error."</div>\n";
		echo "<br />\n";
	}
	if (is_array($analysis) && !empty($analysis['unsupported'])) {
		echo "<div class='card'><b>".$text['label-warning']."</b>: ".$text['warning-wallpaper_unsupported']."<br />\n";
		echo escape(implode(', ', $analysis['unsupported']))."</div>\n";
		echo "<br />\n";
	}

//one card per size group
	foreach ($specs as $group => $spec) {
		//the image shown: on the domain tab the one the phones get, on the global tab the system default
		if ($scope_global) {
			$image = provision_wallpapers::effective_image($domain_uuid, $group, ['groups' => [], 'retired' => []], $meta);
		}
		else {
			$image = $analysis['images'][$group] ?? provision_wallpapers::effective_image($domain_uuid, $group, $meta);
		}
		$own_image = isset($meta['groups'][$group]);

		echo "<div class='card'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td valign='top' style='width: 260px; padding-right: 15px;'>\n";
		if ($image !== null) {
			$preview = $image['files']['jpg'] ?? reset($image['files']);
			echo "	<img src='x_provision_overlay_wallpapers.php?preview=".urlencode($preview)."' alt='' style='max-width: 240px; max-height: 240px; border: 1px solid #ccc;'>\n";
		}
		else {
			echo "	<div style='width: 240px; height: 120px; border: 1px dashed #ccc; display: flex; align-items: center; justify-content: center;'>".$text['label-wallpaper_factory']."</div>\n";
		}
		echo "</td>\n";
		echo "<td valign='top'>\n";
		echo "<b>".escape($spec['width'].' x '.$spec['height'])."</b><br /><br />\n";

		//current image
		echo $text['label-wallpaper_current'].": ";
		if ($image === null) {
			echo $text['label-wallpaper_factory'];
		}
		elseif ($image['scope'] === 'domain') {
			echo $text['label-wallpaper_domain'];
		}
		else {
			echo $text['label-wallpaper_global'];
		}
		if ($image !== null) {
			$row = $image['image'];
			echo " <small>(".escape((string) ($row['uploaded_by'] ?? '')).", ".escape((string) ($row['uploaded_at'] ?? '')).")</small>";
			if (!empty($row['upscaled'])) {
				echo "<br /><small>".$text['warning-wallpaper_upscaled']."</small>";
			}
			foreach ($image['files'] as $file) {
				echo "<br /><small><code>".escape(provision_overlay::asset_url($file))."</code></small>";
			}
		}
		echo "<br /><br />\n";

		//formats and size limits
		$formats = [];
		foreach ($spec['formats'] as $ext => $limit) {
			$formats[] = strtoupper($ext === 'jpg' ? 'jpeg' : $ext).' &le; '.escape(number_format($limit / 1024, 0)).' KB';
		}
		echo $text['label-wallpaper_formats'].": ".implode(', ', $formats)."<br />\n";

		//member models, the domain's devices marked
		$members = [];
		foreach ($spec['members'] as $label => $member) {
			$count = $template_devices[$group][$member['template']] ?? 0;
			$members[] = ($count > 0) ? "<b>".escape($label)." (".$count.")</b>" : escape($label);
		}
		echo $text['label-wallpaper_models'].": ".implode(', ', $members)."<br />\n";
		if (is_array($analysis)) {
			echo $text['label-wallpaper_devices'].": ".escape((string) ($analysis['counts'][$group] ?? 0))."<br />\n";
		}

		//upload and remove
		if ($can_edit) {
			echo "<br />\n";
			echo "<form method='post' enctype='multipart/form-data' class='inline' action='".escape($page)."'>\n";
			echo "<input type='hidden' name='MAX_FILE_SIZE' value='".escape((string) $asset_max_bytes)."'>\n";
			echo "<input type='hidden' name='action' value='upload'>\n";
			echo "<input type='hidden' name='group' value='".escape($group)."'>\n";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo "<input type='file' name='wallpaper' accept='image/png,image/jpeg' required='required'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-upload'],'icon'=>$settings->get('theme', 'button_icon_upload')]);
			echo "</form>\n";
			if ($own_image) {
				echo "<form method='post' class='inline' action='".escape($page)."'>\n";
				echo "<input type='hidden' name='action' value='remove'>\n";
				echo "<input type='hidden' name='group' value='".escape($group)."'>\n";
				echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
				echo button::create(['type'=>'submit','label'=>$text['button-remove'],'icon'=>$settings->get('theme', 'button_icon_delete'),'onclick'=>"if (!confirm('".escape(addslashes($text['confirm-remove_wallpaper']))."')) { this.blur(); return false; }"]);
				echo "</form>\n";
			}
		}
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

	if ($can_edit) {
		echo str_replace('{max}', escape(number_format($asset_max_bytes / 1048576, 1)), $text['description-wallpaper_upload'])."<br /><br />\n";
	}

//devices of the domain that do not get the group's image
	if (is_array($analysis)) {
		echo "<div class='card'>\n";
		echo "<b>".$text['header-wallpaper_not_linked']."</b><br /><br />\n";
		echo $text['description-wallpaper_not_linked']."<br /><br />\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-wallpaper_device']."</th>\n";
		echo "	<th>".$text['label-template']."</th>\n";
		echo "	<th>".$text['label-status']."</th>\n";
		echo "</tr>\n";
		if (empty($not_linked)) {
			echo "<tr class='list-row'><td colspan='3'>".$text['label-wallpaper_all_linked']."</td></tr>\n";
		}
		foreach ($not_linked as $device) {
			$reason = $text['state-wallpaper_'.$device['state']] ?? escape($device['state']);
			if ($device['state'] === 'own') {
				$sources = [];
				foreach ($device['sources'] as $source) {
					if (strpos($source, 'profile:') === 0) {
						$sources[] = $text['label-wallpaper_source_profile'].' '.escape(substr($source, 8));
					}
					else {
						$sources[] = $text['label-wallpaper_source_'.$source] ?? escape($source);
					}
				}
				$reason .= ' ('.implode(' / ', $sources).')';
			}
			echo "<tr class='list-row'>\n";
			echo "	<td><a href='/app/devices/device_edit.php?id=".urlencode($device['device_uuid'])."'>".escape($device['address'])."</a>".($device['label'] !== '' ? ' '.escape($device['label']) : '')."</td>\n";
			echo "	<td>".escape($device['template'])."</td>\n";
			echo "	<td>".$reason."</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
