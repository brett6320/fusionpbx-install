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

}

?>
