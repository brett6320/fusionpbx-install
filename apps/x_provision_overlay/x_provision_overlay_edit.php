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
	Edit one provision template file for one scope. Saving a template is code execution
	(Smarty runs without a security policy), so both edit permissions are superadmin only by default.
*/

//includes files
	require_once dirname(__DIR__, 2) . "/resources/require.php";
	require_once "resources/check_auth.php";

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get the request; the domain is always the session domain
	$scope = $_REQUEST['scope'] ?? '';
	$vendor = $_REQUEST['vendor'] ?? '';
	$model = $_REQUEST['model'] ?? '';
	$file = $_REQUEST['file'] ?? '';
	$new = !empty($_REQUEST['new']);
	$domain_uuid = $_SESSION['domain_uuid'] ?? '';

//check permissions
	if (!permission_exists('x_provision_overlay_view')
		|| !in_array($scope, ['domain', 'global'], true)
		|| ($scope === 'domain' && !permission_exists('x_provision_overlay_domain_edit'))
		|| ($scope === 'global' && !permission_exists('x_provision_overlay_global_edit'))
		|| !is_uuid($domain_uuid)) {
		echo "access denied";
		exit;
	}

//validate the path
	$overlay = new provision_overlay(['database' => $database]);
	if (provision_overlay::file_path($scope, $domain_uuid, $vendor, $model, $file) === null) {
		message::add($text['error-invalid_name'], 'negative');
		header('Location: x_provision_overlay.php');
		exit;
	}
	$template_exists = provision_overlay::template_exists($scope === 'domain' ? $domain_uuid : '', $vendor, $model);
	if (!$template_exists && !$new) {
		message::add($text['error-template_unknown'], 'negative');
		header('Location: x_provision_overlay.php');
		exit;
	}

//the redirect back to this page
	$self = 'x_provision_overlay_edit.php?scope='.urlencode($scope).'&vendor='.urlencode($vendor).'&model='.urlencode($model).'&file='.urlencode($file).($new ? '&new=1' : '');

//process the http post
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: '.$self);
				exit;
			}

		$action = $_POST['action'] ?? '';
		$username = (string) ($_SESSION['username'] ?? '');

		//save the override
			if ($action === 'save') {
				$content = (string) ($_POST['content'] ?? '');

				//browsers submit textarea line breaks as CRLF; keep the line endings of the base file
				$base = ($scope === 'domain') ? provision_overlay::read_file('global', '', $vendor, $model, $file) : null;
				if ($base === null) {
					$base = provision_overlay::read_file('upstream', '', $vendor, $model, $file);
				}
				if ($base === null || strpos($base, "\r\n") === false) {
					$content = str_replace("\r\n", "\n", $content);
				}

				$result = $overlay->save_override($scope, $domain_uuid, $vendor, $model, $file, $content, $new, $username);
				if ($result['error'] === null) {
					message::add($text['message-saved']);
					//build messages can name other domains; show them only to the build permission
					if (permission_exists('x_provision_overlay_build')) {
						foreach ($result['build']['warnings'] ?? [] as $warning) {
							message::add(escape($warning), 'alert');
						}
					}
					header('Location: x_provision_overlay_edit.php?scope='.urlencode($scope).'&vendor='.urlencode($vendor).'&model='.urlencode($model).'&file='.urlencode($file));
					exit;
				}
				message::add($text[$result['error']] ?? $text['error-write'], 'negative');
				if (permission_exists('x_provision_overlay_build')) {
					foreach ($result['build']['errors'] ?? [] as $error) {
						message::add(escape($error), 'negative');
					}
				}
				header('Location: '.$self);
				exit;
			}

		//delete the override (reset to upstream)
			if ($action === 'reset') {
				$result = $overlay->delete_override($scope, $domain_uuid, $vendor, $model, $file, $username);
				if ($result['error'] === null) {
					message::add($text['message-reset']);
				}
				else {
					message::add($text[$result['error']] ?? $text['error-write'], 'negative');
					if (permission_exists('x_provision_overlay_build')) {
						foreach ($result['build']['errors'] ?? [] as $error) {
							message::add(escape($error), 'negative');
						}
					}
				}
				header('Location: x_provision_overlay.php');
				exit;
			}

		header('Location: '.$self);
		exit;
	}

//get the layers
	$upstream_content = provision_overlay::read_file('upstream', '', $vendor, $model, $file);
	$global_content = provision_overlay::read_file('global', '', $vendor, $model, $file);
	$override_content = provision_overlay::read_file($scope, $domain_uuid, $vendor, $model, $file);
	$base_content = ($scope === 'domain' && $global_content !== null) ? $global_content : $upstream_content;
	$editor_content = $override_content ?? $base_content ?? '';

//a new vendor needs a vendor row to be selectable in the device template dropdown
	$vendor_row = $overlay->vendor_row_exists($vendor);

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_provision_overlay_edit'];
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' id='frm' action='".escape($self)."'>\n";
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-x_provision_overlay_edit']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'link'=>'x_provision_overlay.php']);
	if ($override_content !== null) {
		echo button::create(['type'=>'submit','label'=>($base_content !== null ? $text['button-reset'] : $text['button-delete']),'icon'=>$settings->get('theme', 'button_icon_reset'),'name'=>'action','value'=>'reset','onclick'=>"if (!confirm('".escape(addslashes($text['confirm-reset']))."')) { this.blur(); return false; }"]);
	}
	echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save'),'name'=>'action','value'=>'save','id'=>'btn_save']);
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	echo "<div class='card'>\n";
	echo "<b>".$text['label-scope'].":</b> ".($scope === 'domain' ? escape($text['option-scope_domain']).' ('.escape($_SESSION['domain_name'] ?? '').')' : escape($text['option-scope_global']))."<br>\n";
	echo "<b>".$text['label-template'].":</b> ".escape($vendor.'/'.$model)."<br>\n";
	echo "<b>".$text['label-file'].":</b> ".escape($file)."<br><br>\n";
	echo $text['description-code_execution']."<br>\n";
	if ($upstream_content === null && $global_content === null) {
		echo "<br><i>".$text['label-no_upstream']."</i>\n";
	}
	if (!$vendor_row) {
		echo "<br><i>".$text['warning-vendor_missing'].": ".escape($vendor)."</i>\n";
	}
	echo "</div>\n";
	echo "<br />\n";

//editor
	echo "<div class='card'>\n";
	echo "<b>".($override_content !== null ? $text['label-override'] : $text['label-new_override'])."</b><br><br>\n";
	echo "<textarea class='formfld' name='content' spellcheck='false' wrap='off' style='width: 100%; height: 520px; font-family: monospace; font-size: 12px; white-space: pre; overflow: auto;'>".escape($editor_content)."</textarea>\n";
	echo "</div>\n";
	echo "<br />\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "</form>\n";

//diff of the base (upstream, or the global override for a domain) against the saved override
	if ($override_content !== null && $base_content !== null) {
		echo "<div class='card'>\n";
		echo "<b>".($scope === 'domain' && $global_content !== null ? $text['label-diff_global'] : $text['label-diff_upstream'])."</b><br><br>\n";
		$diff = provision_overlay::diff($base_content, $override_content);
		if ($diff === null) {
			echo $text['label-diff_too_large']."\n";
		}
		elseif ($base_content === $override_content) {
			echo $text['label-diff_identical']."\n";
		}
		else {
			//show changed lines with three lines of context
			$changed = [];
			foreach ($diff as $i => $line) {
				if ($line[0] !== ' ') {
					for ($k = max(0, $i - 3); $k <= min(count($diff) - 1, $i + 3); $k++) {
						$changed[$k] = true;
					}
				}
			}
			echo "<pre style='font-size: 12px; overflow: auto; max-height: 520px;'>";
			$last = -1;
			foreach ($diff as $i => $line) {
				if (!isset($changed[$i])) {
					continue;
				}
				if ($last >= 0 && $i !== $last + 1) {
					echo "<span style='color: #888;'>...</span>\n";
				}
				$style = ($line[0] === '-') ? 'background: #fdd;' : (($line[0] === '+') ? 'background: #dfd;' : '');
				echo "<span style='".$style."'>".escape($line[0].' '.$line[1])."</span>\n";
				$last = $i;
			}
			echo "</pre>\n";
		}
		echo "</div>\n";
		echo "<br />\n";
	}

//read-only sources
	if ($scope === 'domain' && $global_content !== null) {
		echo "<div class='card'>\n";
		echo "<details><summary><b>".$text['label-global_source']."</b></summary><br>\n";
		echo "<textarea class='formfld' readonly='readonly' wrap='off' style='width: 100%; height: 360px; font-family: monospace; font-size: 12px; white-space: pre; overflow: auto;'>".escape($global_content)."</textarea>\n";
		echo "</details>\n";
		echo "</div>\n";
		echo "<br />\n";
	}
	if ($upstream_content !== null) {
		echo "<div class='card'>\n";
		echo "<details><summary><b>".$text['label-upstream_source']."</b></summary><br>\n";
		echo "<textarea class='formfld' readonly='readonly' wrap='off' style='width: 100%; height: 360px; font-family: monospace; font-size: 12px; white-space: pre; overflow: auto;'>".escape($upstream_content)."</textarea>\n";
		echo "</details>\n";
		echo "</div>\n";
		echo "<br />\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
