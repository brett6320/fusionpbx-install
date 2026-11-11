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

	//create the base directory for cover sheet logos, outside the webroot
	//no database values are written here, so admin changes are never overwritten
		$logo_base_dir = '/var/lib/fusionpbx/x_fax_setup';
		if (!is_dir($logo_base_dir)) {
			@mkdir($logo_base_dir, 0750, true);
			//upgrade.php normally runs as root, hand the directory to the web server user
			if (function_exists('posix_geteuid') && posix_geteuid() === 0 && is_dir($logo_base_dir)) {
				@chown($logo_base_dir, 'www-data');
				@chgrp($logo_base_dir, 'www-data');
			}
		}
		unset($logo_base_dir);

}

?>
