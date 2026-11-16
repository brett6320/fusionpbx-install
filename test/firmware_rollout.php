<?php
/*
	Firmware rollout, end to end as far as it goes without phones (run by test/checks.sh inside the test
	container, as www-data, with x-endpoint-manager.timer and x-provision-firmware.timer stopped so nothing else
	moves the rows):

		runuser -u www-data -- php /usr/src/x-install/test/firmware_rollout.php

	Fetches are simulated by setting device_provisioned_date and device_provisioned_agent, as provision.php
	does. Prints PASS/FAIL lines; exits 1 on any failure. Cleans up after itself.
*/

if (PHP_SAPI !== 'cli') {
	exit;
}

$project_root = '/var/www/fusionpbx';
chdir($project_root);
require_once $project_root.'/resources/require.php';
require_once $project_root.'/app/x_provision_overlay/resources/classes/provision_overlay.php';
require_once $project_root.'/app/x_provision_overlay/resources/classes/provision_firmware.php';

$fail = 0;
function check(string $description, bool $ok, string $extra = ''): void {
	global $fail;
	printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $description, ($ok || $extra === '') ? '' : ' ('.$extra.')');
	if (!$ok) {
		$fail = 1;
	}
}
function q(string $sql, array $parameters = []): array {
	global $database;
	$rows = $database->execute($sql, $parameters, 'all');
	return is_array($rows) ? $rows : [];
}
function target(string $device_uuid): ?array {
	return q("select * from v_x_provision_overlay_firmware_targets where device_uuid = :d", ['d' => $device_uuid])[0] ?? null;
}
function log_status(?string $log_uuid): string {
	return (string) (q("select reboot_status from v_x_endpoint_reboot_log where x_endpoint_reboot_log_uuid = :u", ['u' => (string) $log_uuid])[0]['reboot_status'] ?? '');
}
function fetch(string $device_uuid, string $version): void {
	q("update v_devices set device_provisioned_date = now(), device_provisioned_agent = :a where device_uuid = :d",
		['a' => 'Yealink SIP-T46S '.$version.' 001122334455', 'd' => $device_uuid]);
}

$overlay = new provision_overlay(['database' => $database]);
$firmware = new provision_firmware(['database' => $database, 'overlay' => $overlay]);
$domains = $overlay->get_domains() ?? [];
uasort($domains, function ($a, $b) { return strcmp($a['domain_name'], $b['domain_name']); });
$domain_uuid = (string) array_key_first($domains);
$domain_name = (string) $domains[$domain_uuid]['domain_name'];

//a release and three devices of family 66 on 66.86.0.15, last fetched an hour ago
$dir = sys_get_temp_dir().'/fw-rollout-'.bin2hex(random_bytes(4));
mkdir($dir, 0700);
$rom = $dir.'/T46S-66.86.0.251.rom';
file_put_contents($rom, random_bytes(2048));
$import = $firmware->import($rom, basename($rom), '', '', 'rollout test', 'checks');
check('rollout: release imported', $import['error'] === null, (string) $import['error']);
$release_uuid = $import['firmware_uuid'];
$devices = [];
foreach (['a' => '0011223344c1', 'b' => '0011223344c2', 'c' => '0011223344c3'] as $name => $mac) {
	$devices[$name] = uuid();
	$sql = "insert into v_devices (device_uuid, domain_uuid, device_address, device_vendor, device_template, device_enabled, device_provisioned_agent, device_provisioned_date) ";
	$sql .= "values (:u, :domain_uuid, :mac, 'yealink', 'yealink/t46s', true, 'Yealink SIP-T46S 66.86.0.15 001122334455', now() - interval '1 hour')";
	q($sql, ['u' => $devices[$name], 'domain_uuid' => $domain_uuid, 'mac' => $mac]);
}
[$a, $b, $c] = [$devices['a'], $devices['b'], $devices['c']];
$rollouts = [];

try {
	//start; a second active rollout of the family is refused
	$start = $firmware->start_rollout($domain_uuid, $release_uuid, 'checks');
	$rollouts[] = $r1 = $start['rollout_uuid'];
	check('rollout: started', $start['error'] === null, (string) $start['error']);
	check('rollout: second active rollout of the family refused', $firmware->start_rollout($domain_uuid, $release_uuid, 'checks')['error'] === 'error-firmware_rollout_active');

	//batch: targets written, rows synced
	$batch = $firmware->start_batch($domain_uuid, $domain_name, $r1, [$a, $b, $c], 0, 'checks');
	check('rollout: batch of 3 started', $batch['error'] === null && $batch['devices'] === 3, json_encode($batch));
	check('rollout: target written', (target($a)['target_state'] ?? '') === 'written');
	$rows = q("select count(*) as n from v_device_settings where device_uuid = :d and device_setting_enabled and device_setting_value in ('66.86.0.251.rom', :url)",
		['d' => $a, 'url' => 'http://{$domain_name}/provision-firmware/'.$release_uuid]);
	check('rollout: device rows written', (int) ($rows[0]['n'] ?? 0) >= 2);

	//check_sync without lines is skipped and retried; after 3 attempts the device goes on to notified
	$firmware->run_rollouts();
	$t = target($a);
	check('rollout: check_sync attempt counted', ($t['target_state'] ?? '') === 'written' && (int) $t['target_attempts'] === 1, json_encode($t));
	check('rollout: check_sync detail recorded', stripos((string) ($t['target_detail'] ?? ''), 'no enabled lines') !== false, (string) ($t['target_detail'] ?? ''));
	q("update v_x_provision_overlay_firmware_targets set target_attempts = 3 where firmware_rollout_uuid = :r", ['r' => $r1]);
	$firmware->run_rollouts();
	check('rollout: notified after the attempts', (target($a)['target_state'] ?? '') === 'notified' && (target($b)['target_state'] ?? '') === 'notified');

	//A fetches: fetched, but no reboot before the Yealink delay
	fetch($a, '66.86.0.15');
	$firmware->run_rollouts();
	check('rollout: fetched after a fetch', (target($a)['target_state'] ?? '') === 'fetched');
	$firmware->run_rollouts();
	check('rollout: no reboot within the Yealink delay', (target($a)['target_state'] ?? '') === 'fetched' && empty(target($a)['reboot_log_uuid']));
	q("update v_x_provision_overlay_firmware_targets set state_date = now() - interval '21 minutes' where device_uuid = :d", ['d' => $a]);
	$firmware->run_rollouts();
	$t = target($a);
	check('rollout: reboot queued after the delay', ($t['target_state'] ?? '') === 'rebooting' && log_status($t['reboot_log_uuid'] ?? null) === 'pending', json_encode($t));
	$log = q("select reboot_trigger, firmware_rollout_uuid from v_x_endpoint_reboot_log where x_endpoint_reboot_log_uuid = :u", ['u' => (string) $t['reboot_log_uuid']])[0] ?? [];
	check('rollout: reboot queued with trigger firmware and the rollout', ($log['reboot_trigger'] ?? '') === 'firmware' && ($log['firmware_rollout_uuid'] ?? '') === $r1);

	//A upgraded on its own: done, the queued reboot is cancelled
	$a_log = (string) $t['reboot_log_uuid'];
	fetch($a, '66.86.0.251');
	$firmware->run_rollouts();
	check('rollout: done when the device reports the release', (target($a)['target_state'] ?? '') === 'done');
	check('rollout: done cancels the queued reboot', log_status($a_log) === 'expired', log_status($a_log));

	//C fetches but the runner hasn't seen it (state still notified); B never fetches. Abort decides on the fetch
	fetch($c, '66.86.0.15');
	check('rollout: C fetched, state not updated yet', (target($c)['target_state'] ?? '') === 'notified');
	check('rollout: aborted', $firmware->abort_rollout($domain_uuid, $r1, 'checks') === null);
	check('rollout: abort keeps a device that is done', (target($a)['target_source'] ?? '') === 'rollout_kept');
	check('rollout: abort keeps a device that fetched (stale state)', (target($c)['target_source'] ?? '') === 'rollout_kept' && (target($c)['firmware_uuid'] ?? '') === $release_uuid);
	check('rollout: abort drops a device that did not fetch', target($b) === null);

	//a kept target is eligible for a later rollout; replacing a rollout target cancels its queued reboot
	$start = $firmware->start_rollout($domain_uuid, $release_uuid, 'checks');
	$rollouts[] = $r2 = $start['rollout_uuid'];
	$r2_row = $firmware->rollout($domain_uuid, $r2);
	$eligible = provision_firmware::eligible($firmware->analyze($domain_uuid, $domain_name), $r2_row);
	check('rollout: kept target eligible for a later rollout', isset($eligible[$c]) && isset($eligible[$b]) && !isset($eligible[$a]), implode(',', array_keys($eligible)));
	$batch = $firmware->start_batch($domain_uuid, $domain_name, $r2, [$c], 0, 'checks');
	check('rollout: kept device taken by a batch', $batch['devices'] === 1 && (target($c)['firmware_rollout_uuid'] ?? '') === $r2);
	q("update v_x_provision_overlay_firmware_targets set target_state = 'fetched', state_date = now() - interval '21 minutes' where device_uuid = :d", ['d' => $c]);
	$firmware->run_rollouts();
	$c_log = (string) (target($c)['reboot_log_uuid'] ?? '');
	check('rollout: C reboot queued', log_status($c_log) === 'pending');
	check('rollout: manual target replaces the rollout target', $firmware->set_target($domain_uuid, $c, 'follow', 'checks') === null && target($c) === null);
	check('rollout: replacing the target cancels its queued reboot', log_status($c_log) === 'expired', log_status($c_log));

	//finish: domain default, the rollout's and kept targets on the release removed
	check('rollout: finished', $firmware->finish_rollout($domain_uuid, $r2, 'checks') === null);
	check('rollout: finish sets the domain default', ($firmware->defaults($domain_uuid)['yealink|66'] ?? '') === $release_uuid);
	check('rollout: finish removes kept targets on the release', target($a) === null);
}
catch (Throwable $e) {
	check('rollout: no exception', false, $e->getMessage());
}
finally {
	foreach ($devices as $device_uuid) {
		q("delete from v_device_settings where device_uuid = :d", ['d' => $device_uuid]);
		q("delete from v_x_provision_overlay_firmware_links where device_uuid = :d", ['d' => $device_uuid]);
		q("delete from v_x_provision_overlay_firmware_targets where device_uuid = :d", ['d' => $device_uuid]);
		q("delete from v_x_endpoint_reboot_log where device_uuid = :d", ['d' => $device_uuid]);
		q("delete from v_devices where device_uuid = :d", ['d' => $device_uuid]);
	}
	q("delete from v_x_provision_overlay_firmware_rollouts where firmware_uuid = :u", ['u' => (string) $release_uuid]);
	q("delete from v_x_provision_overlay_firmware_defaults where firmware_uuid = :u", ['u' => (string) $release_uuid]);
	if ($release_uuid !== '' && $firmware->delete_release($release_uuid, 'checks') !== null) {
		check('rollout: test release deleted', false);
	}
	@unlink($rom);
	@rmdir($dir);
}

exit($fail);
