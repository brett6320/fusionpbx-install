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

/*
	Set FreeSWITCH's external_sip_ip and external_rtp_ip to a public address (the Elastic IP on AWS, which uses
	1:1 NAT). Run as www-data from /var/www/fusionpbx by resources/aws.sh.

	The variables live in v_vars; vars.xml is generated from them (upstream app/vars/app_defaults.php rewrites it on
	every upgrade), so this updates v_vars, regenerates vars.xml with upstream's save_var_xml() and then reads the
	file back. A variable is changed only while it still has upstream's value ($${local_ip_v4}) or the address
	this script set last time (marker x_install.external_ip); an admin's own value is kept.

	Usage: php x_external_ip.php <ipv4>
	Prints "managed: <variables>" (the ones set to the address, possibly none), then "changed" or "unchanged" last;
	exit 1 if vars.xml does not end up with the address.
*/

//run from the command line only
	if (PHP_SAPI !== 'cli' || !defined('STDIN')) {
		exit;
	}
	require_once dirname(__DIR__, 4) . "/resources/require.php";

	$address = $argv[1] ?? '';
	if (count($argv) !== 2 || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
		fwrite(STDERR, "usage: php x_external_ip.php <ipv4>\n");
		exit(64);
	}

//the address set last time
	$sql = "select default_setting_uuid, default_setting_value from v_default_settings ";
	$sql .= "where default_setting_category = 'x_install' and default_setting_subcategory = 'external_ip' ";
	$marker = $database->select($sql, null, 'row');
	$previous = is_array($marker) ? (string)$marker['default_setting_value'] : '';

//the rows save_var_xml() writes for this host
	$sql = "select var_uuid, var_name, var_value from v_vars ";
	$sql .= "where var_name in ('external_sip_ip', 'external_rtp_ip') and var_enabled = true ";
	$sql .= "and (var_hostname is null or var_hostname = :hostname) ";
	$rows = $database->select($sql, ['hostname' => gethostname()], 'all');
	if (empty($rows)) {
		fwrite(STDERR, "x_external_ip: no enabled external_sip_ip or external_rtp_ip variable in v_vars\n");
		exit(1);
	}

	$changed = false;
	$managed = [];
	foreach ($rows as $row) {
		$value = (string)$row['var_value'];
		if ($value === $address) {
			$managed[$row['var_name']] = true;
			continue;
		}
		if ($value !== '$${local_ip_v4}' && ($previous === '' || $value !== $previous)) {
			echo "x_external_ip: ".$row['var_name']." is ".$value." (set by an admin); keeping it\n";
			continue;
		}
		$database->execute("update v_vars set var_value = :value, update_date = now() where var_uuid = :uuid ", ['value' => $address, 'uuid' => $row['var_uuid']]);
		echo "x_external_ip: ".$row['var_name'].": ".$value." -> ".$address."\n";
		$managed[$row['var_name']] = true;
		$changed = true;
	}

	echo "managed: ".implode(' ', array_keys($managed))."\n";
	if (empty($managed)) {
		echo "unchanged\n";
		exit(0);
	}

//remember the address so a later run may move it again
	if (is_array($marker)) {
		$database->execute("update v_default_settings set default_setting_value = :value where default_setting_uuid = :uuid ", ['value' => $address, 'uuid' => $marker['default_setting_uuid']]);
	}
	else {
		$sql = "insert into v_default_settings ";
		$sql .= "(default_setting_uuid, default_setting_category, default_setting_subcategory, default_setting_name, default_setting_value, default_setting_enabled, default_setting_description) ";
		$sql .= "values (:uuid, 'x_install', 'external_ip', 'text', :value, false, 'Marker: the external SIP/RTP address the installer set. Do not remove.') ";
		$database->execute($sql, ['uuid' => uuid(), 'value' => $address]);
	}

//regenerate vars.xml every time (also repairs a stale file), and drop the cached sofia config after a change
	save_var_xml();
	if ($changed) {
		$cache = new cache;
		$cache->delete(gethostname().":configuration:sofia.conf");
	}

//save_var_xml() returns silently when the conf dir setting is missing and only echoes on a write error, so check
//the file itself for each variable this script manages
	$conf_dir = $settings->get('switch', 'conf', $config->get('switch.conf.dir', ''));
	$xml = ($conf_dir !== '') ? @file_get_contents($conf_dir.'/vars.xml') : false;
	if ($xml === false) {
		fwrite(STDERR, "x_external_ip: cannot read ".($conf_dir !== '' ? $conf_dir.'/vars.xml' : 'vars.xml (switch conf dir is not set)')."\n");
		exit(1);
	}
	foreach (array_keys($managed) as $name) {
		if (strpos($xml, 'data="'.$name.'='.$address.'"') === false) {
			fwrite(STDERR, "x_external_ip: ".$conf_dir."/vars.xml does not set ".$name."=".$address."\n");
			exit(1);
		}
	}

	echo ($changed ? "changed" : "unchanged")."\n";

?>
