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
	require_once __DIR__ . "/resources/classes/provision_firmware.php";

//check permissions
	if (!permission_exists('x_provision_overlay_firmware_view')) {
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

//view: the domain's families and devices, the library (superadmin), or one rollout
	$can_edit = permission_exists('x_provision_overlay_firmware_edit');
	$can_library = permission_exists('x_provision_overlay_firmware_library');
	$tab = (($_REQUEST['tab'] ?? '') === 'library') ? 'library' : 'domain';
	if ($tab === 'library' && !$can_library) {
		echo "access denied";
		exit;
	}
	$rollout_uuid = (string) ($_REQUEST['rollout'] ?? '');
	$rollout_uuid = is_uuid($rollout_uuid) ? $rollout_uuid : '';
	$page = 'x_provision_overlay_firmware.php'.($tab === 'library' ? '?tab=library' : ($rollout_uuid !== '' ? '?rollout='.urlencode($rollout_uuid) : ''));

//create the objects
	$overlay = new provision_overlay(['database' => $database]);
	$firmware = new provision_firmware(['database' => $database, 'overlay' => $overlay]);

//process the http post
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {

		//validate the token
			$token = new token;
			if (!$token->validate($_SERVER['PHP_SELF'])) {
				message::add($text['message-invalid_token'], 'negative');
				header('Location: '.$page);
				exit;
			}

		$action = (string) ($_POST['action'] ?? '');
		$username = (string) ($_SESSION['username'] ?? '');
		$library_actions = ['import', 'delete_release'];
		if (in_array($action, $library_actions, true) ? !$can_library : !$can_edit) {
			message::add($text['error-permission'], 'negative');
			header('Location: '.$page);
			exit;
		}

		$error = null;
		$done = null;
		$sync = false;
		try {
			switch ($action) {
				//library
				case 'import':
					//one model per field; a field may also hold a comma separated list
					$models = $_POST['models'] ?? [];
					$models = is_array($models) ? implode(',', array_map('strval', $models)) : (string) $models;
					$result = $firmware->import_upload($_FILES['firmware'] ?? [], (string) ($_POST['version'] ?? ''), $models, (string) ($_POST['note'] ?? ''), $username, (string) ($_POST['vendor'] ?? ''));
					$error = $result['error'];
					$done = $text['message-firmware_imported'];
					if ($error === null) {
						//a Grandstream release can move devices to its family
						$firmware->request_sync();
					}
					break;
				case 'delete_release':
					$error = $firmware->delete_release((string) ($_POST['firmware_uuid'] ?? ''), $username);
					$done = $text['message-firmware_deleted'];
					break;

				//domain defaults and device targets: only entries the admin changed (posted value differs from the
				//value the page was rendered with); an entry changed by someone else since then is not applied
				case 'save':
					$conflicts = 0;
					$defaults_orig = (array) ($_POST['defaults_orig'] ?? []);
					$current_defaults = $firmware->defaults($domain_uuid);
					foreach ((array) ($_POST['defaults'] ?? []) as $key => $value) {
						$key = (string) $key;
						$value = (string) $value;
						if (!array_key_exists($key, $defaults_orig) || $value === (string) $defaults_orig[$key]) {
							continue;
						}
						$current = array_key_exists($key, $current_defaults) ? ($current_defaults[$key] === '' ? 'none' : $current_defaults[$key]) : '';
						if ($current !== (string) $defaults_orig[$key]) {
							$conflicts++;
							continue;
						}
						[$vendor, $family] = array_pad(explode('|', $key, 2), 2, '');
						$error = $firmware->set_default($domain_uuid, $vendor, $family, $value, $username) ?? $error;
					}
					$targets_orig = (array) ($_POST['targets_orig'] ?? []);
					$current_targets = $firmware->targets($domain_uuid);
					foreach ((array) ($_POST['targets'] ?? []) as $device_uuid => $value) {
						$device_uuid = (string) $device_uuid;
						$value = (string) $value;
						if (!is_uuid($device_uuid) || $value === '' || !array_key_exists($device_uuid, $targets_orig) || $value === (string) $targets_orig[$device_uuid]) {
							continue;
						}
						if (provision_firmware::target_value($current_targets[$device_uuid] ?? null) !== (string) $targets_orig[$device_uuid]) {
							$conflicts++;
							continue;
						}
						$error = $firmware->set_target($domain_uuid, $device_uuid, $value, $username) ?? $error;
					}
					if ($conflicts > 0) {
						message::add(str_replace('{count}', (string) $conflicts, $text['error-firmware_changed']), 'negative');
					}
					$done = $text['message-firmware_saved'];
					$sync = true;
					break;

				//rollouts
				case 'start_rollout':
					$result = $firmware->start_rollout($domain_uuid, (string) ($_POST['firmware_uuid'] ?? ''), $username);
					$error = $result['error'];
					$done = $text['message-firmware_rollout_started'];
					if ($error === null) {
						$page = 'x_provision_overlay_firmware.php?rollout='.urlencode($result['rollout_uuid']);
					}
					break;
				case 'start_batch':
					$devices = array_values(array_filter((array) ($_POST['devices'] ?? []), 'is_uuid'));
					$result = $firmware->start_batch($domain_uuid, $domain_name, $rollout_uuid, $devices, (int) ($_POST['count'] ?? 0), $username);
					$error = $result['error'];
					$done = str_replace('{count}', (string) $result['devices'], $text['message-firmware_batch_started']);
					break;
				case 'abort':
					$error = $firmware->abort_rollout($domain_uuid, $rollout_uuid, $username);
					$done = $text['message-firmware_rollout_aborted'];
					break;
				case 'finish':
					$error = $firmware->finish_rollout($domain_uuid, $rollout_uuid, $username);
					$done = $text['message-firmware_rollout_finished'];
					break;
				default:
					$error = 'error-firmware_invalid';
			}
		}
		catch (Throwable $e) {
			provision_firmware::log_error($action.' '.$domain_uuid, $e->getMessage());
			$error = 'error-firmware_store';
		}

		if ($error === null) {
			if ($sync) {
				$result = $firmware->sync_domain($domain_uuid);
				if (!$result['success']) {
					provision_firmware::log_error('sync '.$domain_uuid, $result['error']);
					message::add($text['error-firmware_sync'], 'negative');
				}
			}
			message::add($done);
		}
		else {
			message::add($text[$error] ?? $text['error-firmware_store'], 'negative');
		}
		header('Location: '.$page);
		exit;
	}

//get the data
	$load_error = '';
	$releases = [];
	$analysis = null;
	$defaults = [];
	$current_targets = [];
	$rollouts = [];
	try {
		$releases = $firmware->releases();
		if ($tab === 'domain') {
			$analysis = $firmware->analyze($domain_uuid, $domain_name);
			$defaults = $firmware->defaults($domain_uuid);
			$current_targets = $firmware->targets($domain_uuid);
			$rollouts = $firmware->rollouts($domain_uuid);
		}
	}
	catch (Throwable $e) {
		provision_firmware::log_error('load '.$domain_uuid, $e->getMessage());
		$load_error = $text['error-firmware_load'];
	}
	$rollout = null;
	if ($rollout_uuid !== '' && $load_error === '') {
		$rollout = $firmware->rollout($domain_uuid, $rollout_uuid);
		if ($rollout === null) {
			$rollout_uuid = '';
		}
	}

//releases by family, for the selects
	$family_releases = [];
	foreach ($releases as $release) {
		$family_releases[$release['firmware_vendor'].'|'.$release['firmware_family']][$release['firmware_uuid']] = $release;
	}
	$release_label = function (array $release): string {
		return $release['firmware_version'].($release['firmware_note'] !== '' && $release['firmware_note'] !== null ? ' ('.$release['firmware_note'].')' : '');
	};
	$family_label = function (string $vendor, string $family) use ($text): string {
		return ucfirst($vendor).' '.($vendor === 'yealink' ? str_replace('{family}', $family, $text['label-firmware_rom_family']) : $family);
	};

//create token
	$object = new token;
	$token = $object->create($_SERVER['PHP_SELF']);

//show the header
	$document['title'] = $text['title-x_provision_overlay_firmware'];
	require_once "resources/header.php";

//show the content
	echo "<div class='action_bar' id='action_bar'>\n";
	echo "	<div class='heading'><b>".$text['header-x_provision_overlay_firmware']."</b></div>\n";
	echo "	<div class='actions'>\n";
	echo button::create(['type'=>'button','label'=>$text['button-back'],'icon'=>$settings->get('theme', 'button_icon_back'),'link'=>($rollout_uuid !== '' ? 'x_provision_overlay_firmware.php' : 'x_provision_overlay.php')]);
	if ($can_library) {
		echo button::create(['type'=>'button','label'=>$text['button-firmware_domain'],'icon'=>'microchip','link'=>'x_provision_overlay_firmware.php','style'=>($tab === 'domain' ? 'font-weight: bold;' : '')]);
		echo button::create(['type'=>'button','label'=>$text['button-firmware_library'],'icon'=>'book','link'=>'x_provision_overlay_firmware.php?tab=library','style'=>($tab === 'library' ? 'font-weight: bold;' : '')]);
	}
	echo "	</div>\n";
	echo "	<div style='clear: both;'></div>\n";
	echo "</div>\n";

	if ($load_error !== '') {
		echo "<div class='card'>".$load_error."</div>\n";
		require_once "resources/footer.php";
		exit;
	}

//library
	if ($tab === 'library') {
		echo $text['description-firmware_library']."<br /><br />\n";

		echo "<div class='card'>\n";
		echo "<b>".$text['header-firmware_import']."</b><br /><br />\n";
		echo "<form method='post' enctype='multipart/form-data' action='".escape($page)."'>\n";
		echo "<input type='hidden' name='action' value='import'>\n";
		echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
		echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
		echo "<tr><td class='vncellreq' width='20%'>".$text['label-firmware_file']."</td><td class='vtable'><input type='file' name='firmware' accept='.rom,.bin' required='required'><br />".str_replace('{max}', escape((string) ini_get('upload_max_filesize')), $text['description-firmware_file'])."</td></tr>\n";
		//manufacturer and models: free entry with suggestions
		$suggestions = $firmware->model_suggestions();
		echo "<datalist id='firmware_vendors'>";
		foreach (provision_firmware::vendors as $vendor_label) {
			echo "<option value='".escape($vendor_label)."'>";
		}
		echo "</datalist>\n";
		foreach ($suggestions as $vendor => $models) {
			echo "<datalist id='firmware_models_".escape($vendor)."'>";
			foreach ($models as $model) {
				echo "<option value='".escape($model)."'>";
			}
			echo "</datalist>\n";
		}
		echo "<datalist id='firmware_models_all'>";
		foreach (array_unique(array_merge(...array_values($suggestions))) as $model) {
			echo "<option value='".escape($model)."'>";
		}
		echo "</datalist>\n";
		echo "<tr><td class='vncell'>".$text['label-firmware_vendor']."</td><td class='vtable'><input class='formfld' type='text' name='vendor' id='firmware_vendor' list='firmware_vendors' maxlength='32' autocomplete='off'><br />".$text['description-firmware_vendor']."</td></tr>\n";
		echo "<tr><td class='vncell'>".$text['label-firmware_version']."</td><td class='vtable'><input class='formfld' type='text' name='version' maxlength='32'><br />".$text['description-firmware_version']."</td></tr>\n";
		echo "<tr><td class='vncell'>".$text['label-firmware_models']."</td><td class='vtable'><div id='firmware_models'><input class='formfld firmware-model' type='text' name='models[]' list='firmware_models_all' maxlength='32' autocomplete='off'></div>";
		echo button::create(['type'=>'button','label'=>$text['button-firmware_add_model'],'icon'=>$settings->get('theme', 'button_icon_add'),'onclick'=>'firmware_add_model();']);
		echo "<br />".$text['description-firmware_models']."</td></tr>\n";
		echo "<tr><td class='vncell'>".$text['label-firmware_note']."</td><td class='vtable'><input class='formfld' type='text' name='note' maxlength='255'></td></tr>\n";
		echo "</table><br />\n";
		echo button::create(['type'=>'submit','label'=>$text['button-upload'],'icon'=>$settings->get('theme', 'button_icon_upload')]);
		echo "</form>\n";
		echo "</div>\n";
		echo "<br />\n";

		//model suggestions follow the manufacturer typed; another model adds a field
		echo "<script>\n";
		echo "function firmware_model_list() {\n";
		echo "	var vendor = document.getElementById('firmware_vendor').value.trim().toLowerCase();\n";
		echo "	var list = document.getElementById('firmware_models_' + vendor) ? 'firmware_models_' + vendor : 'firmware_models_all';\n";
		echo "	document.querySelectorAll('#firmware_models .firmware-model').forEach(function (input) { input.setAttribute('list', list); });\n";
		echo "	return list;\n";
		echo "}\n";
		echo "function firmware_add_model() {\n";
		echo "	var input = document.createElement('input');\n";
		echo "	input.className = 'formfld firmware-model';\n";
		echo "	input.type = 'text';\n";
		echo "	input.name = 'models[]';\n";
		echo "	input.maxLength = 32;\n";
		echo "	input.autocomplete = 'off';\n";
		echo "	input.style.display = 'block';\n";
		echo "	input.style.marginTop = '4px';\n";
		echo "	document.getElementById('firmware_models').appendChild(input);\n";
		echo "	firmware_model_list();\n";
		echo "	input.focus();\n";
		echo "}\n";
		echo "document.getElementById('firmware_vendor').addEventListener('input', firmware_model_list);\n";
		echo "</script>\n";

		echo "<div class='card'>\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'>\n";
		echo "	<th>".$text['label-firmware_family']."</th>\n";
		echo "	<th>".$text['label-firmware_version']."</th>\n";
		echo "	<th>".$text['label-firmware_file']."</th>\n";
		echo "	<th>".$text['label-firmware_models']."</th>\n";
		echo "	<th>".$text['label-firmware_size']."</th>\n";
		echo "	<th>SHA-256</th>\n";
		echo "	<th>".$text['label-firmware_note']."</th>\n";
		echo "	<th>".$text['label-firmware_uploaded']."</th>\n";
		echo "	<th></th>\n";
		echo "</tr>\n";
		if (empty($releases)) {
			echo "<tr class='list-row'><td colspan='9'>".$text['label-firmware_none_imported']."</td></tr>\n";
		}
		foreach ($releases as $release) {
			echo "<tr class='list-row'>\n";
			echo "	<td>".escape($family_label($release['firmware_vendor'], $release['firmware_family']))."</td>\n";
			echo "	<td>".escape($release['firmware_version'])."</td>\n";
			echo "	<td><code>".escape($release['firmware_file'])."</code></td>\n";
			echo "	<td>".escape(str_replace(',', ', ', (string) $release['firmware_models']))."</td>\n";
			echo "	<td>".escape(number_format(((int) $release['firmware_size']) / 1048576, 1))." MB</td>\n";
			echo "	<td><code title='".escape($release['firmware_sha256'])."'>".escape(substr((string) $release['firmware_sha256'], 0, 12))."</code></td>\n";
			echo "	<td>".escape((string) $release['firmware_note'])."</td>\n";
			echo "	<td>".escape((string) $release['firmware_uploaded_by'])."<br /><small>".escape((string) $release['insert_date'])."</small></td>\n";
			echo "	<td>";
			echo "<form method='post' class='inline' action='".escape($page)."'>";
			echo "<input type='hidden' name='action' value='delete_release'>";
			echo "<input type='hidden' name='firmware_uuid' value='".escape($release['firmware_uuid'])."'>";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
			echo button::create(['type'=>'submit','label'=>$text['button-delete'],'icon'=>$settings->get('theme', 'button_icon_delete'),'onclick'=>"if (!confirm('".escape(addslashes($text['confirm-delete_firmware']))."')) { this.blur(); return false; }"]);
			echo "</form>";
			echo "</td>\n";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";

		//recent downloads
		echo "<div class='card'>\n";
		echo "<b>".$text['header-firmware_downloads']."</b><br /><br />\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'><th>".$text['label-firmware_time']."</th><th>".$text['label-firmware_family']."</th><th>".$text['label-firmware_version']."</th><th>".$text['label-firmware_address']."</th><th>".$text['label-firmware_agent']."</th></tr>\n";
		foreach ($firmware->downloads(50) as $download) {
			echo "<tr class='list-row'>";
			echo "<td>".escape((string) $download['insert_date']).($download['request_method'] === 'HEAD' ? ' (HEAD)' : '')."</td>";
			echo "<td>".escape($download['firmware_vendor'] !== null ? $family_label((string) $download['firmware_vendor'], (string) $download['firmware_family']) : (string) $download['firmware_uuid'])."</td>";
			echo "<td>".escape((string) $download['firmware_version'])."</td>";
			echo "<td>".escape((string) $download['remote_addr'])."</td>";
			echo "<td>".escape((string) $download['user_agent'])."</td>";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";

		require_once "resources/footer.php";
		exit;
	}

//one rollout
	if ($rollout !== null) {
		$targets = $firmware->rollout_targets($rollout_uuid);
		$eligible = ($rollout['rollout_state'] === 'active') ? provision_firmware::eligible($analysis, $rollout) : [];
		$state_counts = [];
		foreach ($targets as $target) {
			$state_counts[(string) $target['target_state']] = ($state_counts[(string) $target['target_state']] ?? 0) + 1;
		}

		echo "<div class='card'>\n";
		echo "<b>".escape($family_label($rollout['firmware_vendor'], $rollout['firmware_family']))." &rarr; ".escape((string) $rollout['firmware_version'])."</b><br /><br />\n";
		echo $text['label-status'].": ".escape($text['option-rollout_'.$rollout['rollout_state']] ?? $rollout['rollout_state'])."<br />\n";
		echo $text['label-firmware_rollout_from'].": ".escape(isset($releases[$rollout['rollout_from']]) ? $releases[$rollout['rollout_from']]['firmware_version'] : ($text['option-firmware_'.$rollout['rollout_from']] ?? $rollout['rollout_from']))."<br />\n";
		echo $text['label-firmware_rollout_by'].": ".escape((string) $rollout['rollout_created_by']).", ".escape((string) $rollout['insert_date'])."<br />\n";
		$counts = [];
		foreach (provision_firmware::rollout_states as $state) {
			if (!empty($state_counts[$state])) {
				$counts[] = escape($text['state-firmware_'.$state] ?? $state).": ".$state_counts[$state];
			}
		}
		echo $text['label-firmware_devices'].": ".count($targets).(empty($counts) ? '' : " (".implode(', ', $counts).")").", ".$text['label-firmware_remaining'].": ".count($eligible)."<br />\n";
		if ($can_edit && $rollout['rollout_state'] === 'active') {
			echo "<br />\n";
			echo "<form method='post' class='inline' action='".escape($page)."'>\n";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo "<input type='hidden' name='action' value='finish'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-firmware_finish'],'icon'=>'check','onclick'=>"if (!confirm('".escape(addslashes(str_replace('{remaining}', (string) count($eligible), $text['confirm-firmware_finish'])))."')) { this.blur(); return false; }"]);
			echo "</form>\n";
			echo "<form method='post' class='inline' action='".escape($page)."'>\n";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo "<input type='hidden' name='action' value='abort'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-firmware_abort'],'icon'=>'ban','onclick'=>"if (!confirm('".escape(addslashes($text['confirm-firmware_abort']))."')) { this.blur(); return false; }"]);
			echo "</form>\n";
		}
		echo "<br /><br />".$text['description-firmware_rollout']."\n";
		echo "</div>\n";
		echo "<br />\n";

		//devices of the rollout
		echo "<div class='card'>\n";
		echo "<b>".$text['header-firmware_rollout_devices']."</b><br /><br />\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'><th>".$text['label-firmware_device']."</th><th>".$text['label-firmware_reported']."</th><th>".$text['label-status']."</th><th>".$text['label-firmware_detail']."</th><th>".$text['label-firmware_since']."</th></tr>\n";
		foreach ($targets as $device_uuid => $target) {
			$device = $analysis['devices'][$device_uuid] ?? ['address' => $device_uuid, 'label' => '', 'version' => ''];
			echo "<tr class='list-row'>";
			echo "<td><a href='/app/devices/device_edit.php?id=".urlencode($device_uuid)."'>".escape($device['address'])."</a>".($device['label'] !== '' ? ' '.escape($device['label']) : '')."</td>";
			echo "<td>".escape($device['version'])."</td>";
			echo "<td>".escape($text['state-firmware_'.$target['target_state']] ?? (string) $target['target_state'])."</td>";
			echo "<td><small>".escape((string) ($target['target_detail'] ?? ''))."</small></td>";
			echo "<td><small>".escape((string) ($target['state_date'] ?? ''))."</small></td>";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
		echo "<br />\n";

		//next batch
		if ($can_edit && $rollout['rollout_state'] === 'active') {
			echo "<div class='card'>\n";
			echo "<b>".$text['header-firmware_batch']."</b><br /><br />\n";
			echo $text['description-firmware_batch']."<br /><br />\n";
			echo "<form method='post' action='".escape($page)."'>\n";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
			echo "<input type='hidden' name='action' value='start_batch'>\n";
			echo "<table class='list'>\n";
			echo "<tr class='list-header'><th></th><th>".$text['label-firmware_device']."</th><th>".$text['label-template']."</th><th>".$text['label-firmware_model']."</th><th>".$text['label-firmware_reported']."</th></tr>\n";
			if (empty($eligible)) {
				echo "<tr class='list-row'><td colspan='5'>".$text['label-firmware_none_remaining']."</td></tr>\n";
			}
			foreach ($eligible as $device_uuid => $device) {
				echo "<tr class='list-row'>";
				echo "<td><input type='checkbox' name='devices[]' value='".escape($device_uuid)."'></td>";
				echo "<td>".escape($device['address']).($device['label'] !== '' ? ' '.escape($device['label']) : '')."</td>";
				echo "<td>".escape($device['template'])."</td>";
				echo "<td>".escape($device['model'])."</td>";
				echo "<td>".escape($device['version'])."</td>";
				echo "</tr>\n";
			}
			echo "</table><br />\n";
			echo $text['label-firmware_batch_count']." <input class='formfld' type='number' name='count' min='1' max='".max(1, count($eligible))."' value='".min(5, max(1, count($eligible)))."' style='width: 80px;'>\n";
			echo button::create(['type'=>'submit','label'=>$text['button-firmware_start_batch'],'icon'=>'play']);
			echo "</form>\n";
			echo "</div>\n";
		}

		require_once "resources/footer.php";
		exit;
	}

//domain: families, then devices
	echo $text['description-x_provision_overlay_firmware']."<br /><br />\n";
	if (!empty($analysis['unsupported'])) {
		echo "<div class='card'><b>".$text['label-warning']."</b>: ".$text['warning-firmware_unsupported']."<br />\n";
		echo escape(implode(', ', $analysis['unsupported']))."</div>\n";
		echo "<br />\n";
	}

	//families of the domain's devices and of the library
	$families = $analysis['families'];
	foreach ($family_releases as $key => $list) {
		if (!isset($families[$key])) {
			$first = reset($list);
			$families[$key] = ['vendor' => $first['firmware_vendor'], 'family' => $first['firmware_family'], 'devices' => 0, 'managed' => 0, 'current' => 0];
		}
	}
	ksort($families, SORT_STRING);
	$active_rollouts = [];
	foreach ($rollouts as $row) {
		if ($row['rollout_state'] === 'active') {
			$active_rollouts[$row['firmware_vendor'].'|'.$row['firmware_family']] = $row;
		}
	}

	//a domain default changed outside a rollout reaches every phone of the family at its next fetch
	echo "<form method='post' action='".escape($page)."' onsubmit=\"var changed = false; this.querySelectorAll('select[data-orig]').forEach(function (s) { if (s.value !== s.getAttribute('data-orig') && s.getAttribute('data-devices') !== '0') { changed = true; } }); return !changed || confirm('".escape(addslashes($text['confirm-firmware_default']))."');\">\n";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>\n";
	echo "<input type='hidden' name='action' value='save'>\n";
	echo "<div class='card'>\n";
	echo "<b>".$text['header-firmware_families']."</b><br /><br />\n";
	echo $text['description-firmware_defaults']."<br /><br />\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'><th>".$text['label-firmware_family']."</th><th>".$text['label-firmware_devices']."</th><th>".$text['label-firmware_default']."</th><th>".$text['label-firmware_rollout']."</th></tr>\n";
	if (empty($families)) {
		echo "<tr class='list-row'><td colspan='4'>".$text['label-firmware_no_families']."</td></tr>\n";
	}
	foreach ($families as $key => $info) {
		$active = $active_rollouts[$key] ?? null;
		$current = array_key_exists($key, $defaults) ? ($defaults[$key] === '' ? 'none' : $defaults[$key]) : '';
		echo "<tr class='list-row'>\n";
		echo "	<td>".escape($family_label($info['vendor'], $info['family']))."</td>\n";
		echo "	<td>".escape((string) $info['devices']).($info['managed'] > 0 ? " <small>(".escape(str_replace(['{managed}', '{current}'], [(string) $info['managed'], (string) $info['current']], $text['label-firmware_managed_current'])).")</small>" : '')."</td>\n";
		echo "	<td>";
		if ($can_edit && $active === null) {
			echo "<input type='hidden' name='defaults_orig[".escape($key)."]' value='".escape($current)."'>";
			echo "<select class='formfld' name='defaults[".escape($key)."]' data-orig='".escape($current)."' data-devices='".(int) $info['devices']."'>";
			echo "<option value=''".($current === '' ? " selected='selected'" : '').">".$text['option-firmware_unset']."</option>";
			echo "<option value='none'".($current === 'none' ? " selected='selected'" : '').">".$text['option-firmware_none']."</option>";
			//the current value is always an option, so saving never changes it unless the admin does
			if ($current !== '' && $current !== 'none' && !isset($family_releases[$key][$current])) {
				echo "<option value='".escape($current)."' selected='selected'>".escape(isset($releases[$current]) ? $release_label($releases[$current]) : $current)."</option>";
			}
			foreach ($family_releases[$key] ?? [] as $uuid => $release) {
				echo "<option value='".escape($uuid)."'".($current === $uuid ? " selected='selected'" : '').">".escape($release_label($release))."</option>";
			}
			echo "</select>";
		}
		else {
			echo escape($current === '' ? $text['option-firmware_unset'] : ($current === 'none' ? $text['option-firmware_none'] : $release_label($family_releases[$key][$current] ?? ['firmware_version' => $current, 'firmware_note' => ''])));
		}
		echo "</td>\n";
		echo "	<td>";
		if ($active !== null) {
			echo "<a href='x_provision_overlay_firmware.php?rollout=".urlencode($active['firmware_rollout_uuid'])."'>".escape(str_replace('{version}', (string) $active['firmware_version'], $text['label-firmware_rollout_active']))."</a>";
		}
		elseif ($can_edit && !empty($family_releases[$key])) {
			echo "<select class='formfld' name='rollout_release[".escape($key)."]' data-form='rollout-".escape(md5($key))."'>";
			foreach ($family_releases[$key] as $uuid => $release) {
				echo "<option value='".escape($uuid)."'>".escape($release_label($release))."</option>";
			}
			echo "</select> ";
			echo button::create(['type'=>'button','label'=>$text['button-firmware_start_rollout'],'icon'=>'play','onclick'=>"document.getElementById('rollout-".md5($key)."').submit();"]);
		}
		echo "</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";

	//devices
	echo "<div class='card'>\n";
	echo "<b>".$text['header-firmware_devices']."</b><br /><br />\n";
	echo $text['description-firmware_devices']."<br /><br />\n";
	echo "<table class='list'>\n";
	echo "<tr class='list-header'><th>".$text['label-firmware_device']."</th><th>".$text['label-template']."</th><th>".$text['label-firmware_model']."</th><th>".$text['label-firmware_reported']."</th><th>".$text['label-firmware_target']."</th><th>".$text['label-status']."</th></tr>\n";
	if (empty($analysis['devices'])) {
		echo "<tr class='list-row'><td colspan='6'>".$text['label-firmware_no_devices']."</td></tr>\n";
	}
	foreach ($analysis['devices'] as $device_uuid => $device) {
		$key = $device['vendor'].'|'.$device['family'];
		$target = provision_firmware::target_value($current_targets[$device_uuid] ?? null);
		$state = $text['state-firmware_'.$device['state']] ?? escape($device['state']);
		if ($device['state'] === 'own') {
			$sources = [];
			foreach ($device['sources'] as $source) {
				$sources[] = (strpos($source, 'profile:') === 0) ? $text['label-firmware_source_profile'].' '.escape(substr($source, 8)) : ($text['label-firmware_source_'.$source] ?? escape($source));
			}
			$state .= ' ('.implode(' / ', $sources).')';
		}
		elseif ($device['state'] === 'managed' || $device['state'] === 'none') {
			$state .= ' <small>('.escape($text['label-firmware_from_'.$device['target_source']] ?? $device['target_source']).')</small>';
		}
		echo "<tr class='list-row'>\n";
		echo "	<td><a href='/app/devices/device_edit.php?id=".urlencode($device_uuid)."'>".escape($device['address'])."</a>".($device['label'] !== '' ? ' '.escape($device['label']) : '')."</td>\n";
		echo "	<td>".escape($device['template'])."</td>\n";
		echo "	<td>".escape($device['model'])."</td>\n";
		echo "	<td>".escape($device['version'])."</td>\n";
		echo "	<td>";
		if ($can_edit && $device['family'] !== '') {
			echo "<input type='hidden' name='targets_orig[".escape($device_uuid)."]' value='".escape($target)."'>";
			echo "<select class='formfld' name='targets[".escape($device_uuid)."]'>";
			if (str_ends_with($target, '|rollout')) {
				echo "<option value='".escape($target)."' selected='selected'>".escape(str_replace('{version}', $device['target_version'], $text['option-firmware_rollout']))."</option>";
			}
			echo "<option value='follow'".($target === 'follow' ? " selected='selected'" : '').">".$text['option-firmware_follow']."</option>";
			echo "<option value='none'".($target === 'none' ? " selected='selected'" : '').">".$text['option-firmware_none']."</option>";
			//the current target is always an option (also a release of another family: invalid_target)
			if (is_uuid($target) && !isset($family_releases[$key][$target])) {
				$other = $releases[$target] ?? null;
				echo "<option value='".escape($target)."' selected='selected'>".escape($other !== null ? $family_label($other['firmware_vendor'], $other['firmware_family']).' '.$release_label($other) : $target)."</option>";
			}
			foreach ($family_releases[$key] ?? [] as $uuid => $release) {
				echo "<option value='".escape($uuid)."'".($target === $uuid ? " selected='selected'" : '').">".escape($release_label($release))."</option>";
			}
			echo "</select>";
		}
		else {
			echo escape($device['target_version'] !== '' ? $device['target_version'] : ($device['target_kind'] === 'none' ? $text['option-firmware_none'] : ''));
		}
		echo "</td>\n";
		echo "	<td>".$state."</td>\n";
		echo "</tr>\n";
	}
	echo "</table>\n";
	echo "</div>\n";
	echo "<br />\n";
	if ($can_edit) {
		echo button::create(['type'=>'submit','label'=>$text['button-save'],'icon'=>$settings->get('theme', 'button_icon_save')]);
	}
	echo "</form>\n";

	//one form per family to start a rollout, submitted by the buttons above
	foreach ($families as $key => $info) {
		if ($can_edit && !isset($active_rollouts[$key]) && !empty($family_releases[$key])) {
			echo "<form method='post' id='rollout-".escape(md5($key))."' action='x_provision_overlay_firmware.php'>";
			echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
			echo "<input type='hidden' name='action' value='start_rollout'>";
			echo "<input type='hidden' name='firmware_uuid' value=''>";
			echo "</form>\n";
		}
	}
	//copy the chosen release into its rollout form
	echo "<script>\n";
	echo "document.querySelectorAll(\"select[name^='rollout_release']\").forEach(function (select) {\n";
	echo "	var form = document.getElementById(select.getAttribute('data-form'));\n";
	echo "	var sync = function () { form.elements['firmware_uuid'].value = select.value; };\n";
	echo "	select.addEventListener('change', sync);\n";
	echo "	sync();\n";
	echo "});\n";
	echo "</script>\n";

	//past rollouts
	if (!empty($rollouts)) {
		echo "<br />\n";
		echo "<div class='card'>\n";
		echo "<b>".$text['header-firmware_rollouts']."</b><br /><br />\n";
		echo "<table class='list'>\n";
		echo "<tr class='list-header'><th>".$text['label-firmware_family']."</th><th>".$text['label-firmware_version']."</th><th>".$text['label-status']."</th><th>".$text['label-firmware_rollout_by']."</th></tr>\n";
		foreach ($rollouts as $row) {
			echo "<tr class='list-row'>";
			echo "<td><a href='x_provision_overlay_firmware.php?rollout=".urlencode($row['firmware_rollout_uuid'])."'>".escape($family_label($row['firmware_vendor'], $row['firmware_family']))."</a></td>";
			echo "<td>".escape((string) $row['firmware_version'])."</td>";
			echo "<td>".escape($text['option-rollout_'.$row['rollout_state']] ?? $row['rollout_state'])."</td>";
			echo "<td>".escape((string) $row['rollout_created_by']).", ".escape((string) $row['insert_date'])."</td>";
			echo "</tr>\n";
		}
		echo "</table>\n";
		echo "</div>\n";
	}

//include the footer
	require_once "resources/footer.php";

?>
