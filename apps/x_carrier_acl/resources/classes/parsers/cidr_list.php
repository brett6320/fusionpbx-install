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

/**
 * Parser "cidr_list": text, one entry per line (docs/design/carrier_acl.md section 2.3).
 *
 * Options: role (signaling|media, default signaling), comment_chars (default "#;").
 * A bare address is passed through; x_carrier_acl_validate turns it into /32 or /128.
 * Returns raw candidates only; every entry is validated afterwards regardless of parser.
 */
class x_carrier_acl_parser_cidr_list {

	const max_lines = 100000;

	/**
	 * Option validation for the feed editor. Returns a list of error strings.
	 */
	public function option_errors(array $options): array {
		$errors = [];
		foreach (array_keys($options) as $key) {
			if (!in_array($key, ['role', 'comment_chars'], true)) {
				$errors[] = 'unknown option: '.$key;
			}
		}
		if (isset($options['role']) && !in_array($options['role'], ['signaling', 'media'], true)) {
			$errors[] = 'role must be signaling or media';
		}
		if (isset($options['comment_chars']) && (!is_string($options['comment_chars']) || strlen($options['comment_chars']) > 8 || preg_match('/[0-9a-fA-F:.\/\s]/', $options['comment_chars']))) {
			$errors[] = 'comment_chars must be up to 8 characters that cannot appear in an address';
		}
		return $errors;
	}

	/**
	 * @return array ['entries' => [['cidr', 'role', 'label']], 'version' => null]
	 */
	public function parse(string $body, array $options): array {
		$role = $options['role'] ?? 'signaling';
		$comment_chars = $options['comment_chars'] ?? '#;';
		$lines = preg_split('/\r\n|\r|\n/', $body);
		if (count($lines) > self::max_lines) {
			throw new RuntimeException('more than '.self::max_lines.' lines');
		}
		$entries = [];
		foreach ($lines as $number => $line) {
			if ($comment_chars !== '') {
				$position = strcspn($line, $comment_chars);
				$line = substr($line, 0, $position);
			}
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			//one entry per line; anything after the first whitespace is ignored
			$token = preg_split('/\s+/', $line)[0];
			$entries[] = ['cidr' => $token, 'role' => $role, 'label' => 'line '.($number + 1)];
		}
		return ['entries' => $entries, 'version' => null];
	}

}

?>
