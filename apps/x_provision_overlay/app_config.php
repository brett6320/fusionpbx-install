<?php

	//application details
		$apps[$x]['name'] = "Provision Templates";
		$apps[$x]['uuid'] = "ef9315e3-2985-45fa-a6d1-5e58268fc914";
		$apps[$x]['category'] = "Switch";
		$apps[$x]['subcategory'] = "";
		$apps[$x]['version'] = "1.0";
		$apps[$x]['license'] = "Mozilla Public License 1.1";
		$apps[$x]['url'] = "http://www.fusionpbx.com";
		$apps[$x]['description']['en-us'] = "Global and per-domain provision template overrides and branding assets, merged over the upstream templates without editing them.";

	//default settings
		$y=0;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "8805990d-d3cf-470b-8000-8b83cbbf08d3";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "x_provision_overlay";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "asset_max_bytes";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "numeric";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "2097152";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Maximum size in bytes of an uploaded provisioning branding image (logo, wallpaper).";

	//permission details
	//template editing is code execution (Smarty runs without a security policy), so the edit
	//permissions are superadmin only; grant x_provision_overlay_domain_edit to other groups per deployment only
		$y=0;
		$apps[$x]['permissions'][$y]['name'] = "x_provision_overlay_view";
		$apps[$x]['permissions'][$y]['menu']['uuid'] = "63c28b82-b095-4079-88ae-ec4292c32cb5";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "x_provision_overlay_domain_edit";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "x_provision_overlay_global_edit";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "x_provision_overlay_asset_edit";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "x_provision_overlay_build";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";

?>
