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
	if (!permission_exists('x_fax_setup_view')) {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//set the defaults
	$fax_extension = '';
	$fax_name = '';
	$fax_email = '';

//create the object, always scoped to the session domain
	$x_fax = new x_fax_setup;

//process the http post
	if (!empty($_POST)) {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'],'negative');
				header('Location: x_fax_setup.php');
				exit;
			}

		$action = $_POST['action'] ?? '';

		//enable fax for the domain
			if ($action == 'enable_fax') {
				if (!permission_exists('x_fax_setup_enable')) {
					message::add($text['error-permission'],'negative');
					header('Location: x_fax_setup.php');
					exit;
				}

				//get and limit the user data, as fax_edit.php does
					$fax_extension = substr(preg_replace('#[^0-9]#', '', $_POST['fax_extension'] ?? ''), 0, 15);
					$fax_name = substr(trim($_POST['fax_name'] ?? ''), 0, 30);
					$fax_email = str_replace(" ", "", trim($_POST['fax_email'] ?? ''));

				//validate
					$msg = [];
					if (empty($fax_extension)) {
						$msg[] = $text['error-fax_extension_required'];
					}
					if (empty($fax_name)) {
						$msg[] = $text['error-fax_name_required'];
					}
					if (!empty($fax_email)) {
						foreach (explode(',', $fax_email) as $email) {
							if (!valid_email($email)) {
								$msg[] = $text['error-fax_email_invalid'];
								break;
							}
						}
					}
					if (empty($msg) && count($x_fax->get_faxes()) > 0) {
						$msg[] = $text['message-fax_exists'];
					}
					if (empty($msg) && $x_fax->number_in_use($fax_extension)) {
						$msg[] = $text['error-fax_extension_in_use'];
					}

				//create the fax server
					if (empty($msg)) {
						$result = $x_fax->create_fax($fax_extension, $fax_name, $fax_email);
						if ($result === null) {
							message::add($text['message-fax_enabled']);
							header('Location: x_fax_setup.php');
							exit;
						}
						$msg[] = $text[$result];
					}

				//show the errors and keep the form values
					foreach ($msg as $m) {
						message::add($m,'negative');
					}
			}

		//upload the logo
			if ($action == 'upload_logo') {
				if (!permission_exists('x_fax_setup_logo')) {
					message::add($text['error-permission'],'negative');
					header('Location: x_fax_setup.php');
					exit;
				}
				$result = $x_fax->save_logo($_FILES['logo'] ?? []);
				if ($result === null) {
					message::add($text['message-logo_saved']);
				}
				else {
					message::add($text[$result],'negative');
				}
				header('Location: x_fax_setup.php');
				exit;
			}

		//remove the logo
			if ($action == 'remove_logo') {
				if (!permission_exists('x_fax_setup_logo')) {
					message::add($text['error-permission'],'negative');
					header('Location: x_fax_setup.php');
					exit;
				}
				$result = $x_fax->remove_logo();
				if ($result === null) {
					message::add($text['message-logo_removed']);
				}
				else {
					message::add($text[$result],'negative');
				}
				header('Location: x_fax_setup.php');
				exit;
			}
	}

//get the current state
	$faxes = $x_fax->get_faxes();
	$logo_rows = $x_fax->get_logo_setting();
	$logo_managed = false;
	$logo_foreign = false;
	if (is_array($logo_rows)) {
		$logo_managed = true;
		foreach ($logo_rows as $row) {
			if ($row['domain_setting_value'] !== $x_fax->logo_setting_value()) {
				$logo_managed = false;
				$logo_foreign = true;
			}
		}
	}
	$logo_exists = $logo_managed && $x_fax->has_logo();

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_fax_setup'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-x_fax_setup']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

//fax status and enable
	echo "<div class='card'>\n";
	echo "<b>".$text['header-fax_status']."</b><br><br>\n";
	if (!empty($faxes)) {
		echo $text['description-fax_enabled']."<br><br>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-fax_name']."</th>\n";
		echo "	<th>".$text['label-fax_extension']."</th>\n";
		echo "	<th>".$text['label-fax_email']."</th>\n";
		echo "</tr>\n";
		foreach ($faxes as $row) {
			echo "<tr class='list-row'>\n";
			echo "	<td>";
			if (permission_exists('fax_extension_edit')) {
				echo "<a href='".PROJECT_PATH."/app/fax/fax_edit.php?id=".urlencode($row['fax_uuid'])."'>".escape(str_replace('-', ' ', $row['fax_name']))."</a>";
			}
			else {
				echo escape(str_replace('-', ' ', $row['fax_name']));
			}
			echo "</td>\n";
			echo "	<td>".escape($row['fax_extension'])."</td>\n";
			echo "	<td>".escape($row['fax_email'])."</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		if (permission_exists('fax_extension_view')) {
			echo "<br>\n";
			echo button::create(['type'=>'button','label'=>$text['button-open_fax'],'icon'=>'fax','link'=>PROJECT_PATH.'/app/fax/fax.php']);
		}
	}
	elseif (permission_exists('x_fax_setup_enable')) {
		echo $text['description-enable_fax']."<br><br>\n";
		echo "<form method='post' name='frm_enable' id='frm_enable'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-fax_extension']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='fax_extension' maxlength='15' value=\"".escape($fax_extension)."\" required='required' inputmode='numeric' pattern='[0-9]+'>\n";
		echo "<br />".$text['description-fax_extension']."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-fax_name']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='fax_name' maxlength='30' value=\"".escape($fax_name)."\" required='required'>\n";
		echo "<br />".$text['description-fax_name']."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "<tr>\n";
		echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>".$text['label-fax_email']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input class='formfld' type='text' name='fax_email' maxlength='255' value=\"".escape($fax_email)."\">\n";
		echo "<br />".$text['description-fax_email']."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "<br>\n";
		echo "<input type='hidden' name='action' value='enable_fax'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-enable_fax'],'icon'=>$settings->get('theme', 'button_icon_save'),'id'=>'btn_enable']);
		echo "</form>\n";
	}
	echo "</div>\n";
	echo "<br />\n";

//cover sheet logo
	if (permission_exists('x_fax_setup_logo')) {
		echo "<div class='card'>\n";
		echo "<b>".$text['header-cover_logo']."</b><br><br>\n";
		echo $text['description-cover_logo']."<br><br>\n";
		if ($logo_foreign) {
			echo "<i>".$text['label-foreign_logo']."</i><br><br>\n";
		}
		echo "<b>".$text['label-current_logo']."</b><br><br>\n";
		if ($logo_exists) {
			echo "<img src='x_fax_setup_logo.php?t=".urlencode((string) filemtime($x_fax->logo_file()))."' alt='' style='max-width: 300px; max-height: 120px; border: 1px solid #ccc; background: #fff;'><br><br>\n";
		}
		else {
			echo $text['label-no_logo']."<br><br>\n";
		}
		echo "<form method='post' name='frm_logo' id='frm_logo' enctype='multipart/form-data'>\n";
		echo "<input type='hidden' name='MAX_FILE_SIZE' value='1048576'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr>\n";
		echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>".$text['label-logo_file']."</td>\n";
		echo "<td class='vtable' align='left'>\n";
		echo "	<input type='file' name='logo' accept='image/png,image/jpeg' required='required'>\n";
		echo "<br />".$text['description-logo_file']."\n";
		echo "</td>\n";
		echo "</tr>\n";
		echo "</table>\n";
		echo "<br>\n";
		echo "<input type='hidden' name='action' value='upload_logo'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo button::create(['type'=>'submit','label'=>$text['button-upload_logo'],'icon'=>$settings->get('theme', 'button_icon_upload'),'id'=>'btn_upload']);
		echo "</form>\n";
		if ($logo_managed) {
			echo "<br>\n";
			echo "<form method='post' name='frm_remove' id='frm_remove'>\n";
			echo "<input type='hidden' name='action' value='remove_logo'>\n";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-remove_logo'],'icon'=>$settings->get('theme', 'button_icon_delete'),'id'=>'btn_remove']);
			echo "</form>\n";
		}
		echo "</div>\n";
		echo "<br />\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
