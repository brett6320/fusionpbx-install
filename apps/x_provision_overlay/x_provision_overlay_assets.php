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

//get the upload limit
	$asset_max_bytes = (int) $settings->get('x_provision_overlay', 'asset_max_bytes', provision_overlay::asset_default_max_bytes);
	if ($asset_max_bytes < 1) {
		$asset_max_bytes = provision_overlay::asset_default_max_bytes;
	}

//create the object
	$overlay = new provision_overlay(['database' => $database]);

//process the http post
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: x_provision_overlay_assets.php');
				exit;
			}

		//every action changes the domain's assets or settings
			if (!permission_exists('x_provision_overlay_asset_edit')) {
				message::add($text['error-permission'], 'negative');
				header('Location: x_provision_overlay_assets.php');
				exit;
			}

		$action = $_POST['action'] ?? '';
		$username = (string) ($_SESSION['username'] ?? '');

		//upload an asset
			if ($action === 'upload') {
				$requested = trim((string) ($_POST['asset_name'] ?? ''));
				if ($requested === '') {
					$requested = (string) ($_FILES['asset']['name'] ?? '');
				}
				$result = $overlay->save_asset($domain_uuid, $_FILES['asset'] ?? [], $requested, $asset_max_bytes, $username);
				if ($result['error'] === null) {
					message::add($text['message-asset_saved'].': '.escape($result['name']));
				}
				else {
					message::add($text[$result['error']] ?? $text['error-asset_store'], 'negative');
				}
			}

		//delete an asset
			if ($action === 'delete') {
				$error = $overlay->delete_asset($domain_uuid, (string) ($_POST['asset_name'] ?? ''), $username);
				if ($error === null) {
					message::add($text['message-asset_deleted']);
				}
				else {
					message::add($text[$error] ?? $text['error-asset_store'], 'negative');
				}
			}

		//write an asset url into a vendor setting of the domain
			if ($action === 'apply') {
				$error = $overlay->apply_vendor_setting($domain_uuid, (string) ($_POST['setting'] ?? ''), (string) ($_POST['asset_name'] ?? ''));
				if ($error === null) {
					message::add($text['message-setting_applied']);
				}
				else {
					message::add($text[$error] ?? $text['error-setting'], 'negative');
				}
			}

		header('Location: x_provision_overlay_assets.php');
		exit;
	}

//get the data
	$assets = provision_overlay::list_assets($domain_uuid);
	$vendor_settings = permission_exists('x_provision_overlay_asset_edit') ? $overlay->vendor_settings($domain_uuid) : [];
	$can_edit = permission_exists('x_provision_overlay_asset_edit');

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_provision_overlay_assets'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-x_provision_overlay_assets']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'link'=>'x_provision_overlay.php']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo $text['description-x_provision_overlay_assets']."\n";
	echo "<br /><br />\n";

//upload
	if ($can_edit) {
		echo "<div class='card'>\n";
		echo "<b>".$text['header-upload_asset']."</b><br><br>\n";
		echo "<form method='post' name='frm_upload' id='frm_upload' enctype='multipart/form-data'>\n";
		echo "<input type='hidden' name='MAX_FILE_SIZE' value='".escape((string) $asset_max_bytes)."'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-asset_file']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input type='file' name='asset' accept='image/png,image/jpeg,image/bmp,image/gif' required='required'>\n";
		echo "<br />".str_replace('{max}', escape(number_format($asset_max_bytes / 1048576, 1)), $text['description-asset_file'])."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-asset_name']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='asset_name' maxlength='64' pattern='[A-Za-z0-9._-]{0,64}'>\n";
		echo "<br />".$text['description-asset_name']."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "<br>\n";
		echo "<input type='hidden' name='action' value='upload'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-upload'],'icon'=>$settings->get('theme', 'button_icon_upload'),'id'=>'btn_upload']);
		echo "</form>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

//asset list
	echo "<div class='card'>\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'>\n";
	echo "	<th>".$text['label-asset_name']."</th>\n";
	echo "	<th>".$text['label-asset_type']."</th>\n";
	echo "	<th>".$text['label-asset_dimensions']."</th>\n";
	echo "	<th>".$text['label-asset_size']."</th>\n";
	echo "	<th>".$text['label-asset_url']."</th>\n";
	echo "	<th>".$text['label-uploaded']."</th>\n";
	if ($can_edit) {
		echo "	<th class='right'>".$text['label-actions']."</th>\n";
	}
	echo "</tr>\n";
	if (empty($assets)) {
		echo "<tr class='list-row'><td colspan='7'>".$text['label-no_assets']."</td></tr>\n";
	}
	foreach ($assets as $name => $meta) {
		echo "<tr class='list-row'>\n";
		echo "	<td>".escape($name)."</td>\n";
		echo "	<td>".escape((string) $meta['mime'])."</td>\n";
		echo "	<td>".escape(($meta['width'] ?? '?').' x '.($meta['height'] ?? '?'))."</td>\n";
		echo "	<td>".escape(number_format(((int) ($meta['size'] ?? 0)) / 1024, 1))." KB</td>\n";
		echo "	<td><code>".escape(provision_overlay::asset_url($name))."</code><br>";
		echo "<small>".escape('http://'.($_SESSION['domain_name'] ?? '').'/provision-assets/'.$name)."</small></td>\n";
		echo "	<td>".escape((string) ($meta['uploaded_by'] ?? ''))."<br><small>".escape((string) ($meta['uploaded_at'] ?? ''))."</small></td>\n";
		if ($can_edit) {
			echo "	<td class='right no-wrap'>\n";
			echo "<form method='post' class='inline'>\n";
			echo "<input type='hidden' name='action' value='delete'>\n";
			echo "<input type='hidden' name='asset_name' value='".escape($name)."'>\n";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'onclick'=>"if (!confirm('".escape(addslashes($text['confirm-delete_asset']))."')) { this.blur(); return false; }"]);
			echo "</form>\n";
			echo "	</td>\n";
		}
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

//apply to vendor settings
	if ($can_edit && !empty($assets)) {
		echo "<div class='card'>\n";
		echo "<b>".$text['header-apply_setting']."</b><br><br>\n";
		echo $text['description-apply_setting']."<br><br>\n";
		if (empty($vendor_settings)) {
			echo $text['label-no_vendor_settings']."\n";
		}
		else {
			echo "<form method='post' name='frm_apply' id='frm_apply'>\n";
			echo "<select class='formfld' name='asset_name' required='required'>\n";
			foreach ($assets as $name => $meta) {
				echo "	<option value='".escape($name)."'>".escape($name)."</option>\n";
			}
			echo "</select>\n";
			echo "<select class='formfld' name='setting' required='required'>\n";
			foreach ($vendor_settings as $subcategory => $row) {
				$label = 'provision.'.$subcategory;
				if ($row['domain_value'] !== null) {
					$label .= ' = '.$row['domain_value'];
				}
				echo "	<option value='".escape($subcategory)."' title='".escape($row['description'])."'>".escape($label)."</option>\n";
			}
			echo "</select>\n";
			echo "<input type='hidden' name='action' value='apply'>\n";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-apply'],'icon'=>$settings->get('theme', 'button_icon_save')]);
			echo "</form>\n";
		}
		echo "<br><br>".$text['description-template_snippet']."\n";
		echo "<pre style='font-size: 12px;'>".escape("wallpaper_upload.url = http://{\$domain_name}/provision-assets/<name>")."</pre>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
