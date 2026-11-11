<?php

	$y=0;
	$apps[$x]['menu'][$y]['title']['en-us'] = "IVR Manager";
	$apps[$x]['menu'][$y]['uuid'] = "c5f9f711-d4e4-4b33-ac0f-1207361b5590";
	//parent is the Applications menu (core/menu/app_menu.php), same parent as app/ivr_menus/app_menu.php
	$apps[$x]['menu'][$y]['parent_uuid'] = "fd29e39c-c936-f5fc-8e2b-611681b266b5";
	$apps[$x]['menu'][$y]['category'] = "internal";
	$apps[$x]['menu'][$y]['icon'] = "";
	$apps[$x]['menu'][$y]['path'] = "/app/x_ivr_manager/ivr_manager.php";
	$apps[$x]['menu'][$y]['order'] = "";
	$apps[$x]['menu'][$y]['groups'][] = "admin";
	$apps[$x]['menu'][$y]['groups'][] = "superadmin";
	$y++;

?>
