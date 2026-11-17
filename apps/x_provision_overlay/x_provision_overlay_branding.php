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
	require_once __DIR__ . "/resources/classes/provision_branding.php";

//check permissions
	if (!permission_exists('x_provision_overlay_branding_view')) {
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

//scope: this domain, or the system defaults (superadmin)
	$can_global = permission_exists('x_provision_overlay_branding_global');
	$scope_global = (($_REQUEST['scope'] ?? '') === 'global');
	if ($scope_global && !$can_global) {
		echo "access denied";
		exit;
	}
	$scope = $scope_global ? provision_branding::global_scope : strtolower($domain_uuid);
	$can_edit = $scope_global ? $can_global : permission_exists('x_provision_overlay_branding_edit');
	//take over disables default or domain settings, which upstream lets only superadmins edit
	$can_take_over = $can_edit && $can_global;

//the nginx location the image urls need (null: the vhost cannot be read, then nothing is blocked)
	$nginx_ready = provision_branding::nginx_ready();
	$page = 'x_provision_overlay_branding.php'.($scope_global ? '?scope=global' : '');

//get the upload limit
	$asset_max_bytes = (int) $settings->get('x_provision_overlay', 'asset_max_bytes', provision_overlay::asset_default_max_bytes);
	if ($asset_max_bytes < 1) {
		$asset_max_bytes = provision_overlay::asset_default_max_bytes;
	}

//create the object
	$branding = new provision_branding(['database' => $database]);

//process the http post
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: '.$page);
				exit;
			}

		//every action changes the scope's branding
			if (!$can_edit) {
				message::add($text['error-permission'], 'negative');
				header('Location: '.$page);
				exit;
			}

		$action = $_POST['action'] ?? '';
		$item = (string) ($_POST['item'] ?? '');
		$username = (string) ($_SESSION['username'] ?? '');

		//upload or remove an image, save the texts, or take over an item an admin set
			$result = null;
			if (($action === 'upload' || $action === 'take_over') && $nginx_ready === false) {
				message::add($text['warning-branding_nginx'], 'negative');
				header('Location: '.$page);
				exit;
			}
			if ($action === 'take_over' && !$can_take_over) {
				message::add($text['error-permission'], 'negative');
				header('Location: '.$page);
				exit;
			}
			if ($action === 'upload') {
				$result = $branding->store_upload($scope, $item, $_FILES['image'] ?? [], $asset_max_bytes, $username);
			}
			if ($action === 'remove') {
				$result = $branding->remove($scope, $item, $username);
			}
			if ($action === 'save') {
				$values = [];
				foreach (provision_branding::items as $name => $definition) {
					if ($definition['kind'] !== 'image' && isset($_POST[$name]) && is_string($_POST[$name])) {
						$values[$name] = $_POST[$name];
					}
				}
				$result = $branding->save_values($scope, $values, $username);
			}
			if ($action === 'take_over') {
				$result = $branding->take_over($scope, $item, $username);
			}
			if (is_array($result)) {
				if ($result['error'] !== null) {
					message::add($text[$result['error']] ?? $text['error-asset_store'], 'negative');
				}
				elseif (is_array($result['apply']) && !$result['apply']['success']) {
					message::add($text['error-branding_apply'], 'negative');
				}
				else {
					message::add($scope_global ? $text['message-branding_saved_global'] : $text['message-branding_saved']);
				}
			}

		header('Location: '.$page);
		exit;
	}

//get the data
	$meta = provision_branding::read_meta($scope);
	$meta_error = ($meta === null);
	$meta = $meta ?? ['images' => [], 'values' => [], 'retired' => []];
	$global_meta = $scope_global ? $meta : (provision_branding::read_meta(provision_branding::global_scope) ?? ['images' => [], 'values' => [], 'retired' => []]);
	$analysis = null;
	if (!$meta_error) {
		try {
			$analysis = $branding->analyze($scope, $meta);
		}
		catch (Throwable $e) {
			provision_branding::log_error('analysis '.$scope, $e->getMessage());
		}
	}

//on the domain tab, the system default of an item as it applies: 'managed' (shown), 'unapplied' (set on the
//system tab, but a default setting set by hand wins or ours was disabled), or 'none'
	$global_state = [];
	if (!$scope_global) {
		$global_analysis = null;
		try {
			$global_analysis = $branding->analyze(provision_branding::global_scope, $global_meta);
		}
		catch (Throwable $e) {
			provision_branding::log_error('analysis global', $e->getMessage());
		}
		foreach (provision_branding::items as $item => $definition) {
			$set = isset($global_meta['images'][$item]) || isset($global_meta['values'][$item]);
			$state = $global_analysis['items'][$item]['state'] ?? '';
			$global_state[$item] = !$set ? 'none' : ($state === 'managed' ? 'managed' : 'unapplied');
		}
	}

//files of the removed phone assets page, still served read only
	$old_assets = $scope_global ? [] : provision_overlay::list_assets($domain_uuid);

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_provision_overlay_branding'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-x_provision_overlay_branding']."</b></div>\n";
	echo "	<div class='actions'>\n";
	if ($can_global) {
		echo button::create(['type'=>'button','label'=>$text['button-wallpapers_domain'],'icon'=>'building','link'=>'x_provision_overlay_branding.php','style'=>($scope_global ? '' : 'font-weight: bold;')]);
		echo button::create(['type'=>'button','label'=>$text['button-branding_global'],'icon'=>'globe','link'=>'x_provision_overlay_branding.php?scope=global','style'=>($scope_global ? 'font-weight: bold;' : '')]);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo ($scope_global ? $text['description-branding_global'] : $text['description-x_provision_overlay_branding'])."\n";
	echo "<br /><br />\n";

//warnings
	if ($meta_error) {
		provision_branding::log_error('metadata '.$scope, 'cannot read '.provision_branding::scope_dir($scope).'/'.provision_branding::meta_file);
		echo "<div class='card'>".$text['error-branding_meta']."</div>\n";
		echo "<br />\n";
	}
	elseif ($analysis === null) {
		echo "<div class='card'>".$text['error-branding_analysis']."</div>\n";
		echo "<br />\n";
	}
	if ($nginx_ready === false) {
		echo "<div class='card'><b>".$text['label-warning']."</b>: ".$text['warning-branding_nginx']."</div>\n";
		echo "<br />\n";
	}

//state of an item and, when an admin's own value wins, the take over button
	function x_provision_overlay_branding_state(string $item, ?array $analysis, bool $scope_global, string $global_state, bool $can_take_over, array $text): string {
		$state = $analysis['items'][$item]['state'] ?? '';
		if ($state === 'managed') {
			return $text['state-branding_managed'];
		}
		if ($state === 'own') {
			$key = $scope_global ? 'state-branding_own_global' : ($can_take_over ? 'state-branding_own' : 'state-branding_own_superadmin');
			return str_replace('{settings}', escape(implode(', ', $analysis['items'][$item]['sources'])), $text[$key]);
		}
		if ($state === 'optout') {
			return $scope_global ? $text['state-branding_optout_global'] : $text['state-branding_optout'];
		}
		if ($scope_global || $global_state === 'none') {
			return $text['state-branding_none'];
		}
		return ($global_state === 'managed') ? $text['state-branding_inherit'] : $text['state-branding_inherit_unapplied'];
	}

	function x_provision_overlay_branding_take_over(string $item, ?array $analysis, bool $can_take_over, string $page, array $token, array $text): void {
		if (!$can_take_over || ($analysis['items'][$item]['state'] ?? '') !== 'own') {
			return;
		}
		echo "<form method='post' class='inline' action='".escape($page)."'>\n";
		echo "<input type='hidden' name='action' value='take_over'>\n";
		echo "<input type='hidden' name='item' value='".escape($item)."'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-branding_take_over'],'icon'=>'check','onclick'=>"if (!confirm('".escape(addslashes($text['confirm-branding_take_over']))."')) { this.blur(); return false; }"]);
		echo "</form>\n";
	}

//one card per image
	foreach (provision_branding::items as $item => $definition) {
		if ($definition['kind'] !== 'image') {
			continue;
		}
		$image = $meta['images'][$item] ?? null;
		//the system default image is previewed on the domain tab only while it applies
		$global_image = (!$scope_global && ($global_state[$item] ?? '') === 'managed') ? ($global_meta['images'][$item] ?? null) : null;
		$is_favicon = ($item === 'favicon');

		echo "<div class='card'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td valign='top' style='width: 260px; padding-right: 15px;'>\n";
		if ($image !== null || $global_image !== null) {
			$shown_scope = ($image !== null) ? $scope : provision_branding::global_scope;
			$shown = $image ?? $global_image;
			echo "	<img src='".escape(provision_branding::url($shown_scope, $shown['file']))."' alt='' style='max-width: 240px; max-height: 120px; border: 1px solid #ccc;".($image === null ? ' opacity: 0.5;' : '')."'>\n";
		}
		else {
			echo "	<div style='width: 240px; height: 80px; border: 1px dashed #ccc; display: flex; align-items: center; justify-content: center;'>".$text['label-branding_upstream']."</div>\n";
		}
		echo "</td>\n";
		echo "<td valign='top'>\n";
		echo "<b>".$text['label-branding_'.$item]."</b><br />\n";
		echo $text['description-branding_'.$item]."<br /><br />\n";
		echo $text['label-status'].": ".x_provision_overlay_branding_state($item, $analysis, $scope_global, $global_state[$item] ?? 'none', $can_take_over, $text)."<br />\n";
		if ($image !== null) {
			echo "<small>".escape($image['width'].' x '.$image['height'].', '.number_format($image['size'] / 1024, 1).' KiB, '.$image['uploaded_by'].' '.$image['uploaded'])."</small><br />\n";
		}

		//upload, remove and take over
		if ($can_edit) {
			echo "<br />\n";
			echo "<form method='post' enctype='multipart/form-data' class='inline' action='".escape($page)."'>\n";
			echo "<input type='hidden' name='MAX_FILE_SIZE' value='".escape((string) ($is_favicon ? min($asset_max_bytes, provision_branding::favicon_max_bytes) : $asset_max_bytes))."'>\n";
			echo "<input type='hidden' name='action' value='upload'>\n";
			echo "<input type='hidden' name='item' value='".escape($item)."'>\n";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo "<input type='file' name='image' accept='".($is_favicon ? 'image/x-icon,image/vnd.microsoft.icon,image/png,.ico' : 'image/png,image/jpeg,image/gif,image/webp')."' required='required'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-upload'],'icon'=>$settings->get('theme', 'button_icon_upload')]);
			echo "</form>\n";
			if ($image !== null) {
				echo "<form method='post' class='inline' action='".escape($page)."'>\n";
				echo "<input type='hidden' name='action' value='remove'>\n";
				echo "<input type='hidden' name='item' value='".escape($item)."'>\n";
				echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
				echo button::create(['type'=>'submit','label'=>$text['button-remove'],'icon'=>$settings->get('theme', 'button_icon_delete'),'onclick'=>"if (!confirm('".escape(addslashes($text['confirm-branding_remove']))."')) { this.blur(); return false; }"]);
				echo "</form>\n";
			}
			x_provision_overlay_branding_take_over($item, $analysis, $can_take_over, $page, $token, $text);
		}
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

	if ($can_edit) {
		echo str_replace(['{max}', '{favicon}'], [escape(number_format($asset_max_bytes / 1048576, 1)), escape((string) (provision_branding::favicon_max_bytes / 1024))], $text['description-branding_upload'])."<br /><br />\n";
	}

//texts and sizes
	echo "<div class='card'>\n";
	echo "<b>".$text['header-branding_text']."</b><br /><br />\n";
	echo "<form method='post' action='".escape($page)."'>\n";
	echo "<input type='hidden' name='action' value='save'>\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	foreach (provision_branding::items as $item => $definition) {
		if ($definition['kind'] === 'image') {
			continue;
		}
		$value = $meta['values'][$item] ?? '';
		$global_value = (!$scope_global && ($global_state[$item] ?? '') === 'managed') ? ($global_meta['values'][$item] ?? null) : null;
		echo "<tr>\n";
		echo "<td width='30%' class='vncell' valign='top'>".$text['label-branding_'.$item]."</td>\n";
		echo "<td width='70%' class='vtable' valign='top'>\n";
		if ($definition['kind'] === 'choice') {
			echo "	<select class='formfld' name='".escape($item)."'".($can_edit ? '' : " disabled='disabled'").">\n";
			echo "		<option value=''></option>\n";
			foreach ($definition['choices'] as $choice) {
				echo "		<option value='".escape($choice)."'".($value === $choice ? " selected='selected'" : '').">".$text['option-branding_'.$choice]."</option>\n";
			}
			echo "	</select>\n";
		}
		else {
			$max = ($definition['kind'] === 'size') ? 8 : provision_branding::text_max_length;
			echo "	<input class='formfld' type='text' name='".escape($item)."' maxlength='".$max."' value='".escape($value)."'".($can_edit ? '' : " disabled='disabled'").">\n";
		}
		echo "	<br />".$text['description-branding_'.$item]."<br />\n";
		echo "	".$text['label-status'].": ".x_provision_overlay_branding_state($item, $analysis, $scope_global, $global_state[$item] ?? 'none', $can_take_over, $text)."\n";
		if (!$scope_global && $value === '' && $global_value !== null) {
			echo " (".escape($global_value).")";
		}
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	if ($can_edit) {
		echo "<br />\n";
		echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save')]);
	}
	echo "</form>\n";

	//forms cannot nest: the take over buttons of the texts follow the form
	foreach (provision_branding::items as $item => $definition) {
		if ($definition['kind'] !== 'image' && $can_take_over && ($analysis['items'][$item]['state'] ?? '') === 'own') {
			echo "<br />".$text['label-branding_'.$item].": \n";
			x_provision_overlay_branding_take_over($item, $analysis, $can_take_over, $page, $token, $text);
		}
	}
	echo "</div>\n";
	echo "<br />\n";

//files of the removed phone assets page: still served, no longer managed here
	if (!empty($old_assets)) {
		echo "<div class='card'>\n";
		echo "<b>".$text['header-branding_old_assets']."</b><br /><br />\n";
		echo $text['description-branding_old_assets']."<br /><br />\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-asset_name']."</th>\n";
		echo "	<th>".$text['label-asset_url']."</th>\n";
		echo "</tr>\n";
		foreach ($old_assets as $name => $row) {
			echo "<tr class='list-row'>\n";
			echo "	<td>".escape($name)."</td>\n";
			echo "	<td><code>".escape(provision_overlay::asset_url($name))."</code></td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
