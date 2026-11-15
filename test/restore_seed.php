<?php
/*
	Test data for test/restore-test.sh. Run as www-data from /var/www/fusionpbx:
	  php restore_seed.php <marker>

	Adds a default setting carrying the marker and a sealed carrier credential, so a
	restore can be checked for both the data and the carrier key path.
*/

//command line only
	if (!defined('STDIN')) {
		exit;
	}

	require_once "/var/www/fusionpbx/resources/require.php";
	require_once "/var/www/fusionpbx/app/x_carrier_credentials/resources/classes/x_carrier_secret.php";
	require_once "/var/www/fusionpbx/app/x_carrier_credentials/resources/classes/x_carrier_keyring.php";

//the database class returns false on errors instead of throwing; check each insert by reading it back
	function seed_check(string $sql, array $parameters, string $what): void {
		global $database;
		if (empty($database->select($sql, $parameters, 'column'))) {
			fwrite(STDERR, "seed failed: ".$what."\n");
			exit(1);
		}
	}

	$marker = $argv[1] ?? '';
	if (!preg_match('/^[a-z0-9_-]+$/', $marker)) {
		fwrite(STDERR, "usage: php restore_seed.php <marker>\n");
		exit(1);
	}

//marker setting
	$sql = "insert into v_default_settings (default_setting_uuid, default_setting_category, default_setting_subcategory, ";
	$sql .= "default_setting_name, default_setting_value, default_setting_enabled) ";
	$sql .= "values (:uuid, 'x_restore_test', :marker, 'text', :marker, true) ";
	$setting_uuid = uuid();
	$database->execute($sql, ['uuid' => $setting_uuid, 'marker' => $marker]);
	seed_check("select default_setting_uuid from v_default_settings where default_setting_uuid = :uuid", ['uuid' => $setting_uuid], 'marker setting');

//sealed credential under the active key
	$credential_uuid = uuid();
	$secret = new x_carrier_secret('restore-test-'.$marker);
	$sealed = x_carrier_keyring::seal($secret, $credential_uuid);
	$domain_uuid = $database->select("select domain_uuid from v_domains order by domain_name limit 1", null, 'column');
	$sql = "insert into v_x_carrier_credentials (x_carrier_credential_uuid, domain_uuid, x_carrier_credential_type, x_carrier_credential_label, ";
	$sql .= "x_carrier_credential_secret, x_carrier_credential_key_id, x_carrier_credential_version, x_carrier_credential_status) ";
	$sql .= "values (:uuid, :domain_uuid, 'api_token', :label, :secret, :key_id, 1, 'active') ";
	$database->execute($sql, [
		'uuid' => $credential_uuid,
		'domain_uuid' => $domain_uuid,
		'label' => 'restore-test-'.$marker,
		'secret' => $sealed,
		'key_id' => x_carrier_keyring::key_id_of($sealed),
	]);
	seed_check("select x_carrier_credential_uuid from v_x_carrier_credentials where x_carrier_credential_uuid = :uuid", ['uuid' => $credential_uuid], 'carrier credential');

	echo "seeded ".$marker."\n";
