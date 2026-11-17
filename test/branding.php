<?php
/*
	Web interface branding (docs/design/branding.md), run by test/checks.sh inside the test container, as
	www-data:

		runuser -u www-data -- php /usr/src/x-install/test/branding.php           settings logic, cleans up
		runuser -u www-data -- php /usr/src/x-install/test/branding.php --setup   leaves a system and a domain
		                                                                           login logo; prints both paths
		runuser -u www-data -- php /usr/src/x-install/test/branding.php --cleanup removes all branding

	Images are stored with store_image() (the page's store_upload() only adds the PHP upload checks).
	Prints PASS/FAIL lines; exits 1 on any failure.
*/

if (PHP_SAPI !== 'cli') {
	exit;
}

$project_root = '/var/www/fusionpbx';
chdir($project_root);
require_once $project_root.'/resources/require.php';
require_once $project_root.'/app/x_provision_overlay/resources/classes/provision_overlay.php';
require_once $project_root.'/app/x_provision_overlay/resources/classes/provision_branding.php';

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
//enabled values of a theme subcategory in a scope
function values(string $scope, string $subcategory): array {
	if ($scope === provision_branding::global_scope) {
		$rows = q("select default_setting_value as v from v_default_settings where default_setting_category = 'theme' and default_setting_subcategory = :s and default_setting_enabled = true order by 1", ['s' => $subcategory]);
	}
	else {
		$rows = q("select domain_setting_value as v from v_domain_settings where domain_uuid = :d and domain_setting_category = 'theme' and domain_setting_subcategory = :s and domain_setting_enabled = true order by 1", ['d' => $scope, 's' => $subcategory]);
	}
	return array_column($rows, 'v');
}
function image(string $path, int $width, int $height, int $red): string {
	$image = imagecreatetruecolor($width, $height);
	imagefill($image, 0, 0, imagecolorallocate($image, $red, 40, 90));
	imagepng($image, $path);
	return $path;
}
//an ICO holding one PNG (GD cannot write ICO)
function ico(string $path): string {
	$png = image($path.'.png', 32, 32, 120);
	$data = file_get_contents($png);
	unlink($png);
	file_put_contents($path, pack('vvv', 0, 1, 1).pack('CCCCvvVV', 32, 32, 0, 0, 1, 32, strlen($data), 22).$data);
	return $path;
}
function gif(string $path): string {
	$image = imagecreatetruecolor(120, 40);
	imagefill($image, 0, 0, imagecolorallocate($image, 30, 160, 60));
	imagegif($image, $path);
	return $path;
}
function setting(string $domain_uuid, string $subcategory): string {
	global $database;
	$settings = new settings(['database' => $database, 'domain_uuid' => $domain_uuid, 'allow_caching' => false]);
	return (string) $settings->get('theme', $subcategory, '');
}

$overlay = new provision_overlay(['database' => $database]);
$branding = new provision_branding(['database' => $database]);
$domains = $overlay->get_domains() ?? [];
uasort($domains, function ($a, $b) { return strcmp($a['domain_name'], $b['domain_name']); });
$domain_uuid = strtolower((string) array_key_first($domains));
$global = provision_branding::global_scope;
$dir = sys_get_temp_dir().'/branding-test-'.bin2hex(random_bytes(4));
mkdir($dir, 0700);

$mode = $argv[1] ?? '';
if ($mode === '--cleanup') {
	foreach ([$global, $domain_uuid] as $scope) {
		foreach (array_keys(provision_branding::items) as $item) {
			$branding->remove($scope, $item, 'checks');
		}
	}
	check('branding: cleanup leaves no rows', count(q("select 1 from v_x_provision_overlay_branding_links")) === 0);
	exit($fail);
}
if ($mode === '--setup') {
	$stored = [
		$branding->store_image($global, 'login_logo', image($dir.'/g.png', 300, 90, 10), 'checks'),
		$branding->store_image($domain_uuid, 'login_logo', image($dir.'/d.png', 300, 90, 200), 'checks'),
		$branding->store_image($global, 'favicon', ico($dir.'/f.ico'), 'checks'),
		$branding->store_image($global, 'menu_logo', gif($dir.'/m.gif'), 'checks'),
	];
	foreach ($stored as $result) {
		if ($result['error'] !== null || !($result['apply']['success'] ?? false)) {
			fwrite(STDERR, "setup failed: ".($result['error'] ?? $result['apply']['error'] ?? '')."\n");
			exit(1);
		}
	}
	$global_meta = provision_branding::read_meta($global);
	echo provision_branding::url($global, $global_meta['images']['login_logo']['file'])."\n";
	echo provision_branding::url($domain_uuid, provision_branding::read_meta($domain_uuid)['images']['login_logo']['file'])."\n";
	echo provision_branding::url($global, $global_meta['images']['favicon']['file'])."\n";
	echo provision_branding::url($global, $global_meta['images']['menu_logo']['file'])."\n";
	exit(0);
}

check('branding: nginx location installed', provision_branding::nginx_ready() === true);

//system default menu logo: both menu settings written as default settings, recorded as ours
$result = $branding->store_image($global, 'menu_logo', image($dir.'/global.png', 400, 100, 10), 'checks');
check('branding: system logo stored', $result['error'] === null && ($result['apply']['success'] ?? false), (string) ($result['error'] ?? $result['apply']['error'] ?? ''));
$global_meta = provision_branding::read_meta($global);
$global_url = provision_branding::url($global, (string) ($global_meta['images']['menu_logo']['file'] ?? ''));
check('branding: content-hashed name', preg_match('#^/branding-assets/global/br-menu_logo-[0-9a-f]{12}\.png$#', $global_url) === 1, $global_url);
check('branding: default settings written', values($global, 'menu_brand_image') === [$global_url] && values($global, 'menu_side_brand_image_expanded') === [$global_url]);
check('branding: system default applies to the domain', setting($domain_uuid, 'menu_brand_image') === $global_url);
check('branding: file info from metadata', (provision_branding::file_info($global, basename($global_url))['mime'] ?? '') === 'image/png');
check('branding: unrecorded name refused', provision_branding::file_info($global, 'br-menu_logo-000000000000.png') === null);

//domain override wins over the system default
$result = $branding->store_image($domain_uuid, 'menu_logo', image($dir.'/domain.png', 400, 100, 200), 'checks');
check('branding: domain logo stored', $result['error'] === null && ($result['apply']['success'] ?? false));
$domain_url = provision_branding::url($domain_uuid, (string) (provision_branding::read_meta($domain_uuid)['images']['menu_logo']['file'] ?? ''));
check('branding: domain settings written', values($domain_uuid, 'menu_brand_image') === [$domain_url]);
check('branding: domain override over system default', setting($domain_uuid, 'menu_brand_image') === $domain_url, setting($domain_uuid, 'menu_brand_image'));

//a new image replaces the value in the same row; the old file stays servable for the grace period
$row_before = q("select domain_setting_uuid from v_domain_settings where domain_uuid = :d and domain_setting_subcategory = 'menu_brand_image'", ['d' => $domain_uuid]);
$branding->store_image($domain_uuid, 'menu_logo', image($dir.'/domain2.png', 400, 100, 150), 'checks');
$new_url = provision_branding::url($domain_uuid, (string) (provision_branding::read_meta($domain_uuid)['images']['menu_logo']['file'] ?? ''));
$row_after = q("select domain_setting_uuid from v_domain_settings where domain_uuid = :d and domain_setting_subcategory = 'menu_brand_image'", ['d' => $domain_uuid]);
check('branding: replaced image updates the row', $new_url !== $domain_url && values($domain_uuid, 'menu_brand_image') === [$new_url] && $row_before == $row_after);
check('branding: replaced file served during grace', provision_branding::file_info($domain_uuid, basename($domain_url)) !== null);

//repeated uploads keep at most two retired files per item
for ($i = 0; $i < 4; $i++) {
	$branding->store_image($domain_uuid, 'menu_logo', image($dir.'/r'.$i.'.png', 400, 100, 20 + $i), 'checks');
}
$retired = array_filter(array_keys(provision_branding::read_meta($domain_uuid)['retired']), function ($name) { return strpos($name, 'br-menu_logo-') === 0; });
$on_disk = glob(provision_branding::scope_dir($domain_uuid).'/br-menu_logo-*.png') ?: [];
check('branding: retired files capped', count($retired) === provision_branding::retired_keep && count($on_disk) === provision_branding::retired_keep + 1, count($retired).' retired, '.count($on_disk).' on disk');

//removing the domain image removes our rows; the system default applies again
$result = $branding->remove($domain_uuid, 'menu_logo', 'checks');
check('branding: domain image removed', $result['error'] === null && values($domain_uuid, 'menu_brand_image') === [] && values($domain_uuid, 'menu_side_brand_image_expanded') === []);
check('branding: system default after removal', setting($domain_uuid, 'menu_brand_image') === $global_url);

//refused uploads
file_put_contents($dir.'/x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
check('branding: svg refused', $branding->store_image($global, 'menu_logo', $dir.'/x.svg', 'checks')['error'] === 'error-branding_type');
file_put_contents($dir.'/x.png', "\x89PNG\r\n\x1a\n<?php echo 1; ?>");
check('branding: broken png refused', $branding->store_image($global, 'menu_logo', $dir.'/x.png', 'checks')['error'] === 'error-branding_type');
image($dir.'/big.png', 600, 600, 50);
check('branding: oversized favicon refused', $branding->store_image($global, 'favicon', $dir.'/big.png', 'checks')['error'] === 'error-asset_dimensions');
$jpeg = imagecreatetruecolor(32, 32);
imagejpeg($jpeg, $dir.'/f.jpg');
check('branding: jpeg favicon refused', $branding->store_image($global, 'favicon', $dir.'/f.jpg', 'checks')['error'] === 'error-branding_favicon_type');
check('branding: markup in text refused', $branding->save_values($domain_uuid, ['menu_brand_text' => 'Acme <b>'], 'checks')['error'] === 'error-branding_text');
check('branding: css in size refused', $branding->save_values($domain_uuid, ['login_logo_width' => '10px; background: red'], 'checks')['error'] === 'error-branding_size');
$result = $branding->save_values($domain_uuid, ['login_logo_width' => 'auto'], 'checks');
check('branding: auto size is not written (upstream cap applies)', $result['error'] === null && values($domain_uuid, 'login_logo_width') === []);

//texts: written, changed in place, removed when emptied
$result = $branding->save_values($domain_uuid, ['menu_brand_text' => 'Acme Voice', 'menu_brand_type' => 'image_text', 'login_logo_width' => '240px'], 'checks');
check('branding: texts saved', $result['error'] === null && values($domain_uuid, 'menu_brand_text') === ['Acme Voice'] && values($domain_uuid, 'menu_brand_type') === ['image_text'] && values($domain_uuid, 'login_logo_width') === ['240px']);
$branding->save_values($domain_uuid, ['menu_brand_text' => '', 'menu_brand_type' => '', 'login_logo_width' => ''], 'checks');
check('branding: emptied texts removed', values($domain_uuid, 'menu_brand_text') === [] && values($domain_uuid, 'menu_brand_type') === [] && values($domain_uuid, 'login_logo_width') === []);

//an admin's own value wins and is never overwritten; take over disables it
$own_uuid = uuid();
q("insert into v_domain_settings (domain_setting_uuid, domain_uuid, domain_setting_category, domain_setting_subcategory, domain_setting_name, domain_setting_value, domain_setting_enabled) values (:u, :d, 'theme', 'favicon', 'text', '/mine.ico', true)", ['u' => $own_uuid, 'd' => $domain_uuid]);
$ico = $dir.'/f.png';
image($ico, 32, 32, 90);
$result = $branding->store_image($domain_uuid, 'favicon', $ico, 'checks');
check('branding: own value kept', $result['error'] === null && ($result['apply']['items']['favicon'] ?? '') === 'own' && values($domain_uuid, 'favicon') === ['/mine.ico']);
$result = $branding->take_over($domain_uuid, 'favicon', 'checks');
$favicon_url = provision_branding::url($domain_uuid, (string) (provision_branding::read_meta($domain_uuid)['images']['favicon']['file'] ?? ''));
check('branding: take over', $result['error'] === null && values($domain_uuid, 'favicon') === [$favicon_url]
	&& count(q("select 1 from v_domain_settings where domain_setting_uuid = :u and domain_setting_enabled = false", ['u' => $own_uuid])) === 1);

//take over after an admin changed one of our rows: that row is disabled and ours written at the first press
q("update v_domain_settings set domain_setting_value = '/edited.ico' where domain_uuid = :d and domain_setting_subcategory = 'favicon' and domain_setting_value = :v", ['d' => $domain_uuid, 'v' => $favicon_url]);
$result = $branding->take_over($domain_uuid, 'favicon', 'checks');
check('branding: take over of an edited row', $result['error'] === null && values($domain_uuid, 'favicon') === [$favicon_url]
	&& count(q("select 1 from v_domain_settings where domain_uuid = :d and domain_setting_value = '/edited.ico' and domain_setting_enabled = false", ['d' => $domain_uuid])) === 1);
q("delete from v_domain_settings where domain_uuid = :d and domain_setting_value = '/edited.ico'", ['d' => $domain_uuid]);

//a row of ours disabled by an admin is an opt-out: not written again
q("update v_domain_settings set domain_setting_enabled = false where domain_uuid = :d and domain_setting_subcategory = 'favicon' and domain_setting_value = :v", ['d' => $domain_uuid, 'v' => $favicon_url]);
$result = $branding->apply($domain_uuid);
check('branding: disabled row is an opt-out', ($result['items']['favicon'] ?? '') === 'optout' && values($domain_uuid, 'favicon') === []);
$branding->remove($domain_uuid, 'favicon', 'checks');
q("delete from v_domain_settings where domain_uuid = :d and domain_setting_subcategory = 'favicon'", ['d' => $domain_uuid]);
//the link of a row deleted by hand is dropped at the next apply
$branding->apply($domain_uuid);
check('branding: link of a deleted row dropped', count(q("select 1 from v_x_provision_overlay_branding_links where domain_uuid = :d", ['d' => $domain_uuid])) === 0);

//the system title: upstream seeds an enabled theme > title, so it is own until taken over
$seed = q("select default_setting_uuid, default_setting_enabled from v_default_settings where default_setting_category = 'theme' and default_setting_subcategory = 'title' and default_setting_value = 'FusionPBX'");
$result = $branding->save_values($global, ['title' => 'Acme PBX'], 'checks');
check('branding: seeded system title is own', !empty($seed) && ($result['apply']['items']['title'] ?? '') === 'own' && !in_array('Acme PBX', values($global, 'title'), true));
$branding->take_over($global, 'title', 'checks');
check('branding: system title taken over', values($global, 'title') === ['Acme PBX']);
$branding->remove($global, 'title', 'checks');
foreach ($seed as $row) {
	q("update v_default_settings set default_setting_enabled = :e where default_setting_uuid = :u", ['e' => $row['default_setting_enabled'] ? 'true' : 'false', 'u' => $row['default_setting_uuid']]);
}
check('branding: seeded title restored', values($global, 'title') === ['FusionPBX']);

//cleanup
$branding->remove($global, 'menu_logo', 'checks');
check('branding: system rows removed', values($global, 'menu_brand_image') === [] && count(q("select 1 from v_x_provision_overlay_branding_links")) === 0);
array_map('unlink', glob($dir.'/*') ?: []);
rmdir($dir);
exit($fail);
