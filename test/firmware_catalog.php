<?php
/*
	Builds the firmware catalog of provision_firmware (docs/design/provision_firmware.md 3.2) from a provision
	template tree, or checks the catalog in the class against one.

		php test/firmware_catalog.php <templates dir>           print the catalog entries
		php test/firmware_catalog.php --check <templates dir>   compare with provision_firmware::catalog

	<templates dir> is upstream's resources/templates/provision at the commit pinned in config.sh (in the test
	container: /var/www/fusionpbx/resources/templates/provision). The output is pasted between the catalog
	markers of apps/x_provision_overlay/resources/classes/provision_firmware.php.

	Yealink: every {$yealink_firmware_<var>} the template dir prints, with yealink_firmware_url; dirs that print
	no file variable (vp59 writes an empty url) are left out. Grandstream: dirs that print
	{$grandstream_firmware_path}.
*/

if (PHP_SAPI !== 'cli') {
	exit;
}

$check = ($argv[1] ?? '') === '--check';
$root = rtrim((string) ($check ? ($argv[2] ?? '') : ($argv[1] ?? '')), '/');
if ($root === '' || !is_dir($root.'/yealink') || !is_dir($root.'/grandstream')) {
	fwrite(STDERR, "usage: php firmware_catalog.php [--check] <provision templates dir>\n");
	exit(2);
}

//template dir => sorted variables
function firmware_catalog_build(string $root): array {
	$catalog = [];
	foreach (['yealink', 'grandstream'] as $vendor) {
		$dirs = scandir($root.'/'.$vendor);
		sort($dirs, SORT_STRING);
		foreach ($dirs as $dir) {
			$path = $root.'/'.$vendor.'/'.$dir;
			if ($dir === '' || $dir[0] === '.' || !is_dir($path)) {
				continue;
			}
			$content = '';
			foreach (scandir($path) as $file) {
				if ($file !== '' && $file[0] !== '.' && is_file($path.'/'.$file)) {
					$content .= file_get_contents($path.'/'.$file)."\n";
				}
			}
			if ($vendor === 'yealink') {
				preg_match_all('/\{\$(yealink_firmware_[a-z0-9_]+)\}/', $content, $matches);
				$vars = array_values(array_unique($matches[1]));
				$files = array_values(array_diff($vars, ['yealink_firmware_url']));
				if (empty($files) || !in_array('yealink_firmware_url', $vars, true)) {
					continue;
				}
			}
			else {
				if (preg_match('/\{\$grandstream_firmware_path\}/', $content) !== 1) {
					continue;
				}
				$vars = ['grandstream_firmware_path'];
			}
			sort($vars, SORT_STRING);
			$catalog[$vendor.'/'.$dir] = $vars;
		}
	}
	return $catalog;
}

$catalog = firmware_catalog_build($root);

if (!$check) {
	foreach ($catalog as $template => $vars) {
		echo "\t\t'".$template."' => ['".implode("', '", $vars)."'],\n";
	}
	exit(0);
}

//compare with the class (the class file is parsed, not included: it needs the framework)
$source = (string) file_get_contents(dirname(__DIR__).'/apps/x_provision_overlay/resources/classes/provision_firmware.php');
if (preg_match('/\/\/BEGIN catalog\n(.*?)\t*\/\/END catalog/s', $source, $block) !== 1) {
	fwrite(STDERR, "catalog markers not found in provision_firmware.php\n");
	exit(1);
}
$class = [];
preg_match_all("/'([a-z]+\/[A-Za-z0-9._-]+)' => \[([^\]]*)\]/", $block[1], $rows, PREG_SET_ORDER);
foreach ($rows as $row) {
	preg_match_all("/'([a-z0-9_]+)'/", $row[2], $vars);
	$class[$row[1]] = $vars[1];
}
$status = 0;
foreach ($catalog as $template => $vars) {
	if (!isset($class[$template])) {
		echo "missing in the class: ".$template."\n";
		$status = 1;
	}
	elseif ($class[$template] !== $vars) {
		echo "variables differ: ".$template." (templates: ".implode(' ', $vars)."; class: ".implode(' ', $class[$template]).")\n";
		$status = 1;
	}
}
foreach (array_keys($class) as $template) {
	if (!isset($catalog[$template])) {
		echo "not in the templates: ".$template."\n";
		$status = 1;
	}
}
echo ($status === 0 ? "firmware catalog matches (".count($catalog)." templates)\n" : "firmware catalog differs\n");
exit($status);
