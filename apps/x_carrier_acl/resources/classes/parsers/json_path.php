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
 * Parser "json_path" (docs/design/carrier_acl.md section 2.3).
 *
 * Options: paths (list of {path, role}), optional version_path.
 * Path grammar: dotted keys, "*" for any object key, "[*]" for any array element, nothing else
 * (e.g. "media.globalCidrs[*]", "signaling.*.primary"). Max depth 16, max document 2 MiB.
 * Only string leaves are returned; the label is the concrete path that produced the value.
 */
class x_carrier_acl_parser_json_path {

	const max_depth = 16;
	const max_bytes = 2097152;

	public function option_errors(array $options): array {
		$errors = [];
		foreach (array_keys($options) as $key) {
			if (!in_array($key, ['paths', 'version_path'], true)) {
				$errors[] = 'unknown option: '.$key;
			}
		}
		if (empty($options['paths']) || !is_array($options['paths']) || !array_is_list($options['paths'])) {
			$errors[] = 'paths must be a non-empty list of {"path": "...", "role": "signaling|media"}';
		}
		else {
			foreach ($options['paths'] as $i => $item) {
				if (!is_array($item) || !isset($item['path']) || !is_string($item['path']) || self::compile($item['path']) === null) {
					$errors[] = 'paths['.$i.'].path is not a valid path';
				}
				if (!is_array($item) || !in_array($item['role'] ?? null, ['signaling', 'media'], true)) {
					$errors[] = 'paths['.$i.'].role must be signaling or media';
				}
			}
		}
		if (isset($options['version_path']) && (!is_string($options['version_path']) || self::compile($options['version_path']) === null)) {
			$errors[] = 'version_path is not a valid path';
		}
		return $errors;
	}

	public function parse(string $body, array $options): array {
		if (strlen($body) > self::max_bytes) {
			throw new RuntimeException('document larger than 2 MiB');
		}
		$document = json_decode($body, true, self::max_depth);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException('invalid JSON: '.json_last_error_msg());
		}
		$entries = [];
		foreach ($options['paths'] ?? [] as $item) {
			$tokens = self::compile((string)($item['path'] ?? ''));
			if ($tokens === null) {
				throw new RuntimeException('invalid path: '.($item['path'] ?? ''));
			}
			foreach (self::walk($document, $tokens, '') as [$label, $value]) {
				if (is_string($value)) {
					$entries[] = ['cidr' => trim($value), 'role' => $item['role'], 'label' => $label];
				}
			}
		}
		$version = null;
		if (!empty($options['version_path'])) {
			$tokens = self::compile((string)$options['version_path']);
			if ($tokens !== null) {
				foreach (self::walk($document, $tokens, '') as [$label, $value]) {
					if (is_scalar($value)) {
						$version = substr((string)$value, 0, 128);
						break;
					}
				}
			}
		}
		return ['entries' => $entries, 'version' => $version];
	}

	/**
	 * Compile a path into tokens: ['key', name], ['any'] or ['each']. Returns null on a grammar error.
	 */
	public static function compile(string $path): ?array {
		if ($path === '' || strlen($path) > 512) {
			return null;
		}
		$tokens = [];
		foreach (explode('.', $path) as $segment) {
			if (!preg_match('/^(\*|[^\[\]\.\*]+)?((?:\[\*\])*)$/', $segment, $m) || ($m[1] ?? '') === '' && ($m[2] ?? '') === '') {
				return null;
			}
			if (($m[1] ?? '') === '*') {
				$tokens[] = ['any'];
			}
			elseif (($m[1] ?? '') !== '') {
				$tokens[] = ['key', $m[1]];
			}
			for ($i = 0; $i < substr_count($m[2] ?? '', '[*]'); $i++) {
				$tokens[] = ['each'];
			}
		}
		if (count($tokens) > self::max_depth) {
			return null;
		}
		return $tokens;
	}

	/**
	 * Walk the decoded document, returning [concrete_path, value] pairs.
	 */
	private static function walk($node, array $tokens, string $label, int $depth = 0): array {
		if ($depth > self::max_depth) {
			return [];
		}
		if (empty($tokens)) {
			return [[$label, $node]];
		}
		$token = array_shift($tokens);
		$results = [];
		if ($token[0] === 'key') {
			if (is_array($node) && !array_is_list($node) && array_key_exists($token[1], $node)) {
				$results = self::walk($node[$token[1]], $tokens, ($label === '' ? '' : $label.'.').$token[1], $depth + 1);
			}
		}
		elseif ($token[0] === 'any') {
			if (is_array($node) && !array_is_list($node)) {
				foreach ($node as $key => $child) {
					$results = array_merge($results, self::walk($child, $tokens, ($label === '' ? '' : $label.'.').$key, $depth + 1));
				}
			}
		}
		elseif ($token[0] === 'each') {
			if (is_array($node) && array_is_list($node)) {
				foreach ($node as $index => $child) {
					$results = array_merge($results, self::walk($child, $tokens, $label.'['.$index.']', $depth + 1));
				}
			}
		}
		return $results;
	}

}

?>
