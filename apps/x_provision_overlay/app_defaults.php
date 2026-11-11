<?php

//app_defaults.php runs on every upgrade, so the provision.template_dir default setting is inserted
//once and recorded with a marker; an admin who later changes, disables or deletes it keeps that.
//provisioning itself (provision.enabled) is enabled once by x_local_defaults, not here.
if ($domains_processed == 1) {

	//only point the setting at the merged tree once the installer has created the link; a
	//template_dir that does not exist would make every provision request 404
		$overlay_link = '/usr/share/fusionpbx/templates/provision';
		$overlay_ready = is_link($overlay_link)
			&& readlink($overlay_link) === '/var/lib/fusionpbx/provision_overlay/merged/current'
			&& is_dir($overlay_link);

	//check the marker
		$sql = "select count(*) from v_default_settings ";
		$sql .= "where default_setting_category = 'x_install' ";
		$sql .= "and default_setting_subcategory = 'provision_overlay_initialized' ";
		$template_dir_initialized = $database->select($sql, null, 'column');
		unset($sql);

	//insert provision.template_dir with the uuid owned by this project
		if (empty($template_dir_initialized) && $overlay_ready) {
			//skip when the row exists, or an admin already has a provision.template_dir row of their own
			$sql = "select count(*) from v_default_settings ";
			$sql .= "where default_setting_uuid = :uuid ";
			$sql .= "or (default_setting_category = 'provision' and default_setting_subcategory = 'template_dir') ";
			$parameters['uuid'] = 'd250396f-c95d-4632-9bfe-5c556b665c49';
			$exists = $database->select($sql, $parameters, 'column');
			unset($sql, $parameters);

			if (empty($exists)) {
				$sql = "insert into v_default_settings ";
				$sql .= "(default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
				$sql .= "values (:uuid, 'provision', 'template_dir', 'text', :value, true, :description) ";
				$parameters['uuid'] = 'd250396f-c95d-4632-9bfe-5c556b665c49';
				$parameters['value'] = $overlay_link;
				$parameters['description'] = 'Merged provision templates (Provision Templates app). Same as the first built-in search path; set so rendering keeps using the merged tree if upstream changes its search order.';
				$database->execute($sql, $parameters);
				unset($sql, $parameters);
			}

			$sql = "insert into v_default_settings ";
			$sql .= "(default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
			$sql .= "values (:uuid, 'x_install', 'provision_overlay_initialized', 'boolean', 'true', false, 'Marker: provision.template_dir was set at install. Do not remove.') ";
			$parameters['uuid'] = uuid();
			$database->execute($sql, $parameters);
			unset($sql, $parameters);
		}

		unset($overlay_link, $overlay_ready, $template_dir_initialized, $exists);

}

?>
