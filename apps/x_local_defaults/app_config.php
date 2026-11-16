<?php

	//application details
		$apps[$x]['name'] = "Local Defaults";
		$apps[$x]['uuid'] = "f2b81d0f-3958-41ca-ac66-f0f70fb4e353";
		$apps[$x]['category'] = "System";
		$apps[$x]['subcategory'] = "";
		$apps[$x]['version'] = "1.0";
		$apps[$x]['license'] = "Mozilla Public License 1.1";
		$apps[$x]['url'] = "";
		$apps[$x]['description']['en-us'] = "Applies this installation's defaults once, on first install, without overriding later admin changes.";

	//default settings: the codec order of the provisioning templates, in Advanced > Default Settings > Provision.
	//Codec names: OPUS, G.722, PCMU, PCMA, G.723.1, G.729A/B, G.726-32, iLBC. A template skips codecs its phone lacks.
		$y = 0;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "40faf93b-c5dd-446b-95c7-1f105de10d43";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "provision";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "x_codec_1";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "OPUS";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Codec 1 of the phone codec order (provision templates). Empty skips the slot.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "4e2ea3d2-d295-425e-b178-279835b3184e";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "provision";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "x_codec_2";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "G.722";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Codec 2 of the phone codec order (provision templates). Empty skips the slot.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "dd212f0e-26b6-4c0b-84bd-e4e1da4a224d";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "provision";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "x_codec_3";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "PCMU";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Codec 3 of the phone codec order (provision templates). Empty skips the slot.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "ebd6583b-7bb1-4e36-9a4d-5271737e6131";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "provision";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "x_codec_4";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "PCMA";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Codec 4 of the phone codec order (provision templates). Empty skips the slot.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "6fc53d8a-a2b0-41c7-a691-b83eaa0b7023";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "provision";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "x_codec_5";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "G.723.1";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Codec 5 of the phone codec order (provision templates). Empty skips the slot.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "29fb4815-32c6-42f8-8f55-235433b80aee";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "provision";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "x_codec_6";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "G.729A/B";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Codec 6 of the phone codec order (provision templates). Empty skips the slot.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "04facaad-e21c-4615-9e51-61c6e23563cc";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "provision";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "x_codec_7";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "G.726-32";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Codec 7 of the phone codec order (provision templates). Empty skips the slot.";
		$y++;
		$apps[$x]['default_settings'][$y]['default_setting_uuid'] = "d09ce6fa-d672-446e-9685-5f68d3208bd7";
		$apps[$x]['default_settings'][$y]['default_setting_category'] = "provision";
		$apps[$x]['default_settings'][$y]['default_setting_subcategory'] = "x_codec_8";
		$apps[$x]['default_settings'][$y]['default_setting_name'] = "text";
		$apps[$x]['default_settings'][$y]['default_setting_value'] = "iLBC";
		$apps[$x]['default_settings'][$y]['default_setting_enabled'] = "true";
		$apps[$x]['default_settings'][$y]['default_setting_description'] = "Codec 8 of the phone codec order (provision templates). Empty skips the slot.";
		$y++;

?>
