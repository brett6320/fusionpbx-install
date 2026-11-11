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
 * Parser "html_ip_scrape" (docs/design/carrier_acl.md section 2.3). Best effort by nature.
 *
 * Options: role (default signaling), section_regex (only scan text after a heading h1-h6 matching this, until the
 * next heading that does not match), exclude_regex (skip text lines matching this).
 * DOMDocument with LIBXML_NONET; script, style and template elements are removed; text tokens that parse as an
 * IPv4/IPv6 address with an optional /prefix are returned with the current heading as label.
 */
class x_carrier_acl_parser_html_ip_scrape {

	public function option_errors(array $options): array {
		$errors = [];
		foreach (array_keys($options) as $key) {
			if (!in_array($key, ['role', 'section_regex', 'exclude_regex'], true)) {
				$errors[] = 'unknown option: '.$key;
			}
		}
		if (isset($options['role']) && !in_array($options['role'], ['signaling', 'media'], true)) {
			$errors[] = 'role must be signaling or media';
		}
		foreach (['section_regex', 'exclude_regex'] as $key) {
			if (isset($options[$key]) && (!is_string($options[$key]) || strlen($options[$key]) > 256 || self::regex($options[$key]) === null)) {
				$errors[] = $key.' is not a valid regular expression';
			}
		}
		return $errors;
	}

	public function parse(string $body, array $options): array {
		if (!class_exists('DOMDocument')) {
			throw new RuntimeException('php xml extension is not installed');
		}
		$role = $options['role'] ?? 'signaling';
		$section = isset($options['section_regex']) && $options['section_regex'] !== '' ? self::regex($options['section_regex']) : null;
		$exclude = isset($options['exclude_regex']) && $options['exclude_regex'] !== '' ? self::regex($options['exclude_regex']) : null;

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			throw new RuntimeException('HTML could not be parsed');
		}

		//remove elements that never carry visible text
		foreach (['script', 'style', 'template', 'noscript'] as $tag) {
			$nodes = [];
			foreach ($document->getElementsByTagName($tag) as $node) {
				$nodes[] = $node;
			}
			foreach ($nodes as $node) {
				$node->parentNode->removeChild($node);
			}
		}

		$state = ['heading' => '', 'active' => ($section === null)];
		$entries = [];
		$this->walk($document, $state, $section, $exclude, $role, $entries);
		return ['entries' => $entries, 'version' => null];
	}

	/**
	 * Depth first walk in document order.
	 */
	private function walk(DOMNode $node, array &$state, ?string $section, ?string $exclude, string $role, array &$entries): void {
		foreach ($node->childNodes as $child) {
			if ($child instanceof DOMElement && preg_match('/^h[1-6]$/i', $child->nodeName)) {
				$state['heading'] = trim(preg_replace('/\s+/', ' ', $child->textContent));
				if ($section !== null) {
					$state['active'] = (preg_match($section, $state['heading']) === 1);
				}
				continue;
			}
			if ($child instanceof DOMText) {
				if ($state['active']) {
					$this->scan($child->nodeValue, $state['heading'], $exclude, $role, $entries);
				}
				continue;
			}
			if ($child->hasChildNodes()) {
				$this->walk($child, $state, $section, $exclude, $role, $entries);
			}
		}
	}

	/**
	 * Extract address tokens from a text node.
	 */
	private function scan(string $text, string $heading, ?string $exclude, string $role, array &$entries): void {
		foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
			if ($exclude !== null && preg_match($exclude, $line) === 1) {
				continue;
			}
			foreach (preg_split('/[\s,;()"\'<>\[\]{}|]+/', $line, -1, PREG_SPLIT_NO_EMPTY) as $token) {
				$token = rtrim($token, '.:');
				if (!preg_match('/^([0-9a-fA-F:.]+)(\/\d{1,3})?$/', $token, $m)) {
					continue;
				}
				//ipv4 needs four octets, ipv6 at least two colons; inet_pton has the final word
				if (strpos($m[1], ':') === false && substr_count($m[1], '.') !== 3) {
					continue;
				}
				if (strpos($m[1], ':') !== false && substr_count($m[1], ':') < 2) {
					continue;
				}
				if (@inet_pton($m[1]) === false) {
					continue;
				}
				$entries[] = ['cidr' => $token, 'role' => $role, 'label' => mb_strcut(mb_scrub($heading !== '' ? $heading : 'page', 'UTF-8'), 0, 64, 'UTF-8')];
			}
		}
	}

	/**
	 * Build a case-insensitive PCRE from an admin supplied pattern. Returns null if it does not compile.
	 */
	public static function regex(string $pattern): ?string {
		$regex = '#'.str_replace('#', '\#', $pattern).'#iu';
		if (@preg_match($regex, '') === false) {
			return null;
		}
		return $regex;
	}

}

?>
