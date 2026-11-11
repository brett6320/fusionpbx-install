<?php

	//application details
		$apps[$x]['name'] = "Fax Setup";
		$apps[$x]['uuid'] = "383e16b1-1ddb-4e8c-ac80-80b9140c00aa";
		$apps[$x]['category'] = "Switch";
		$apps[$x]['subcategory'] = "";
		$apps[$x]['version'] = "1.0";
		$apps[$x]['license'] = "Mozilla Public License 1.1";
		$apps[$x]['url'] = "http://www.fusionpbx.com";
		$apps[$x]['description']['en-us'] = "One-click fax enablement for the domain and fax cover sheet logo upload.";

	//permission details
		$y=0;
		$apps[$x]['permissions'][$y]['name'] = "x_fax_setup_view";
		$apps[$x]['permissions'][$y]['menu']['uuid'] = "416bb42f-7084-460c-a45d-1769db6fb106";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "x_fax_setup_enable";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";
		$y++;
		$apps[$x]['permissions'][$y]['name'] = "x_fax_setup_logo";
		$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
		$apps[$x]['permissions'][$y]['groups'][] = "admin";

?>
