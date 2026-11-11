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

if ($domains_processed == 1) {

	//seed the carrier catalogue once, behind a first-install marker, so a
	//catalogue row an admin edits or deletes is never restored on upgrade
		$sql = "select count(*) from v_default_settings ";
		$sql .= "where default_setting_category = 'x_install' ";
		$sql .= "and default_setting_subcategory = 'carrier_credentials_initialized' ";
		$carrier_credentials_initialized = $database->select($sql, null, 'column');
		unset($sql);

		if (empty($carrier_credentials_initialized) && $database->table_exists('v_x_carriers')) {

			//seed values are placeholders until confirmed against each carrier's documentation
				$carrier_seeds = [];
				$carrier_seeds[] = [
					'x_carrier_uuid' => '6ae909a5-f96b-43c6-86d0-6252d79cdb72',
					'x_carrier_name' => 'Telnyx',
					'x_carrier_default_proxy' => 'sip.telnyx.com',
					'x_carrier_default_register_proxy' => '',
					'x_carrier_default_outbound_proxy' => '',
					'x_carrier_default_transport' => 'udp',
					'x_carrier_default_codec_prefs' => '',
					'x_carrier_default_profile' => 'external',
					'x_carrier_supports_registration' => 'true',
					'x_carrier_supports_ip_auth' => 'true',
					'x_carrier_supports_api' => 'true',
					'x_carrier_api_base_url' => 'https://api.telnyx.com/v2',
					'x_carrier_enabled' => 'true',
					'x_carrier_description' => 'Seed values. Confirm proxy and transport against the carrier documentation before use.',
				];

				foreach ($carrier_seeds as $carrier_seed) {
					$sql = "select count(*) from v_x_carriers where x_carrier_uuid = :x_carrier_uuid ";
					$parameters['x_carrier_uuid'] = $carrier_seed['x_carrier_uuid'];
					$carrier_exists = $database->select($sql, $parameters, 'column');
					unset($sql, $parameters);
					if (empty($carrier_exists)) {
						$array['x_carriers'][0] = $carrier_seed;
						$p = permissions::new();
						$p->add('x_carrier_add', 'temp');
						$database->save($array, false);
						unset($array);
						$p->delete('x_carrier_add', 'temp');
					}
				}
				unset($carrier_seeds, $carrier_seed, $carrier_exists);

			//write the marker
				$sql = "insert into v_default_settings ";
				$sql .= "(default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
				$sql .= "values (:uuid, 'x_install', 'carrier_credentials_initialized', 'boolean', 'true', false, 'Marker: the carrier catalogue was seeded. Do not remove.') ";
				$parameters['uuid'] = uuid();
				$database->execute($sql, $parameters);
				unset($sql, $parameters);
		}
		unset($carrier_credentials_initialized);

}

?>
