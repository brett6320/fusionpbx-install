<?php

//app_defaults.php runs on every upgrade, so each change here is applied once and
//recorded with a marker; an admin who later changes the setting keeps their value
if ($domains_processed == 1) {

	//check the marker
		$sql = "select count(*) from v_default_settings ";
		$sql .= "where default_setting_category = 'x_install' ";
		$sql .= "and default_setting_subcategory = 'provision_initialized' ";
		$provision_initialized = $database->select($sql, null, 'column');
		unset($sql);

	//enable provisioning; upstream ships the row with value true but disabled
		if (empty($provision_initialized)) {
			$sql = "update v_default_settings ";
			$sql .= "set default_setting_enabled = true ";
			$sql .= "where default_setting_category = 'provision' ";
			$sql .= "and default_setting_subcategory = 'enabled' ";
			$sql .= "and default_setting_name = 'boolean' ";
			$database->execute($sql, null);
			unset($sql);

			$sql = "insert into v_default_settings ";
			$sql .= "(default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
			$sql .= "values (:uuid, 'x_install', 'provision_initialized', 'boolean', 'true', false, 'Marker: provisioning was enabled at install. Do not remove.') ";
			$parameters['uuid'] = uuid();
			$database->execute($sql, $parameters);
			unset($sql, $parameters);
		}

	//check the provisioning auth marker
		$sql = "select count(*) from v_default_settings ";
		$sql .= "where default_setting_category = 'x_install' ";
		$sql .= "and default_setting_subcategory = 'provision_auth_initialized' ";
		$provision_auth_initialized = $database->select($sql, null, 'column');
		unset($sql);

	//protect provisioning with random digest credentials; upstream ships the
	//http_auth rows empty and disabled, which serves configs (with SIP passwords)
	//to anyone who guesses a MAC address. rows an admin already filled are kept.
		if (empty($provision_auth_initialized)) {
			$alphanumeric = function ($length) {
				$characters = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
				$value = '';
				for ($i = 0; $i < $length; $i++) {
					$value .= $characters[random_int(0, strlen($characters) - 1)];
				}
				return $value;
			};
			$provision_auth = [
				'http_auth_username' => 'prov'.$alphanumeric(8),
				'http_auth_password' => $alphanumeric(24),
				'http_auth_type' => 'digest',
			];
			foreach ($provision_auth as $subcategory => $value) {
				$sql = "update v_default_settings ";
				$sql .= "set default_setting_value = :value, default_setting_enabled = true ";
				$sql .= "where default_setting_category = 'provision' ";
				$sql .= "and default_setting_subcategory = :subcategory ";
				if ($subcategory !== 'http_auth_type') {
					$sql .= "and (default_setting_value is null or default_setting_value = '') ";
				}
				$sql .= "and default_setting_enabled = false ";
				$parameters['value'] = $value;
				$parameters['subcategory'] = $subcategory;
				$database->execute($sql, $parameters);
				unset($sql, $parameters);
			}
			unset($alphanumeric, $provision_auth, $subcategory, $value);

			$sql = "insert into v_default_settings ";
			$sql .= "(default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
			$sql .= "values (:uuid, 'x_install', 'provision_auth_initialized', 'boolean', 'true', false, 'Marker: provisioning HTTP auth was set at install. Do not remove.') ";
			$parameters['uuid'] = uuid();
			$database->execute($sql, $parameters);
			unset($sql, $parameters);
		}

	//check the theme marker
		$sql = "select count(*) from v_default_settings ";
		$sql .= "where default_setting_category = 'x_install' ";
		$sql .= "and default_setting_subcategory = 'theme_initialized' ";
		$theme_initialized = $database->select($sql, null, 'column');
		unset($sql);

	//make upstream's dark theme (Cobalt Blue, core/themes/app_defaults.php) the default; upstream ships
	//domain > theme > uuid empty, which is the light default. a theme an admin already chose is kept, and
	//users and domains can still pick their own.
		if (empty($theme_initialized)) {
			//the row is upstream's (core/themes/app_config.php, same uuid); create it if this runs first,
			//upstream then skips it because the uuid exists
			$sql = "insert into v_default_settings ";
			$sql .= "(default_setting_uuid, app_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
			$sql .= "select 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a', '26b2a370-1769-4275-9ed7-a2e1a2b058bf', 'domain', 'theme', 'uuid', '', true, '' ";
			$sql .= "where not exists (select 1 from v_default_settings where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a') ";
			$database->execute($sql, null);
			unset($sql);

			$sql = "update v_default_settings ";
			$sql .= "set default_setting_value = 'eda762b3-e356-4225-a394-38b5f092fd44', default_setting_enabled = true ";
			$sql .= "where default_setting_uuid = 'b1a99e57-8d4a-4bbf-910f-1f5a86d6532a' ";
			$sql .= "and (default_setting_value is null or default_setting_value = '') ";
			$database->execute($sql, null);
			unset($sql);

			$sql = "insert into v_default_settings ";
			$sql .= "(default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
			$sql .= "values (:uuid, 'x_install', 'theme_initialized', 'boolean', 'true', false, 'Marker: the dark theme was made the default at install. Do not remove.') ";
			$parameters['uuid'] = uuid();
			$database->execute($sql, $parameters);
			unset($sql, $parameters);
		}

	//reword upstream's English email templates (resources/email_templates.php). A template is replaced only while
	//it still has upstream's exact subject and body (trimmed: database::save trims what upstream inserts), so an
	//admin's edits are kept. Upstream's installer has inserted them before this layer runs; a missing row is left
	//to core/email_templates, which inserts it (with upstream's enabled flag) and the next upgrade rewords it
		$email_templates = require __DIR__.'/resources/email_templates.php';
		foreach ($email_templates as $email_template_uuid => $email_template) {
			$sql = "select template_subject, template_body from v_email_templates ";
			$sql .= "where email_template_uuid = :email_template_uuid ";
			$parameters['email_template_uuid'] = $email_template_uuid;
			$row = $database->select($sql, $parameters, 'row');
			unset($sql, $parameters);
			if (empty($row) || trim((string)$row['template_subject']) !== trim($email_template['old_subject']) || trim((string)$row['template_body']) !== trim($email_template['old_body'])) {
				continue;
			}
			$sql = "update v_email_templates ";
			$sql .= "set template_subject = :template_subject, template_body = :template_body, update_date = now() ";
			$sql .= "where email_template_uuid = :email_template_uuid ";
			$parameters['email_template_uuid'] = $email_template_uuid;
			$parameters['template_subject'] = trim($email_template['subject']);
			$parameters['template_body'] = trim($email_template['body']);
			$database->execute($sql, $parameters);
			unset($sql, $parameters);
		}
		unset($email_templates, $email_template, $email_template_uuid, $row);

	//check the outbound codec marker
		$sql = "select count(*) from v_default_settings ";
		$sql .= "where default_setting_category = 'x_install' ";
		$sql .= "and default_setting_subcategory = 'outbound_codecs_initialized' ";
		$outbound_codecs_initialized = $database->select($sql, null, 'column');
		unset($sql);

	//offer G.722 first on calls out to gateways (the sofia profiles' outbound-codec-prefs); upstream ships
	//outbound_codec_prefs=PCMU,PCMA. The variable is changed only while it still has upstream's value, so an
	//admin's choice is kept. app/vars (alphabetically earlier) creates the row and writes vars.xml before this
	//runs; the marker is only set once the row exists, and vars.xml is written again after a change.
		if (empty($outbound_codecs_initialized)) {
			$sql = "select var_uuid, var_value from v_vars where var_name = 'outbound_codec_prefs' ";
			$outbound_codecs = $database->select($sql, null, 'all');
			unset($sql);
			if (is_array($outbound_codecs) && count($outbound_codecs) > 0) {
				$changed = false;
				foreach ($outbound_codecs as $row) {
					if (str_replace(' ', '', (string) $row['var_value']) === 'PCMU,PCMA') {
						$sql = "update v_vars set var_value = 'G722,PCMU,PCMA', update_date = now() where var_uuid = :var_uuid ";
						$database->execute($sql, ['var_uuid' => $row['var_uuid']]);
						unset($sql);
						$changed = true;
					}
				}
				if ($changed && function_exists('save_var_xml')) {
					save_var_xml();
				}

				$sql = "insert into v_default_settings ";
				$sql .= "(default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
				$sql .= "values (:uuid, 'x_install', 'outbound_codecs_initialized', 'boolean', 'true', false, 'Marker: outbound_codec_prefs was set to G722,PCMU,PCMA at install. Do not remove.') ";
				$parameters['uuid'] = uuid();
				$database->execute($sql, $parameters);
				unset($sql, $parameters, $changed);
			}
			unset($outbound_codecs, $row);
		}

}

?>
