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
 * HTTPS fetcher (docs/design/carrier_acl.md section 2.4). Hard rules, not settings:
 * https only (also on redirects, max 3), peer and host verification, connect 10 s / total 30 s, 2 MiB body cap
 * enforced while downloading, conditional requests, optional SPKI pin.
 *
 * Redirects are followed by hand so every hop is checked. With check_destination (the web preview, which runs in
 * php-fpm without the worker unit's IPAddressDeny) every hop's host is resolved first, refused if any address is
 * private, loopback, link-local or otherwise reserved, and curl is pinned to the checked addresses (CURLOPT_RESOLVE)
 * so DNS cannot change between the check and the connect.
 */
class x_carrier_acl_fetch {

	const max_bytes = 2097152;
	const max_redirects = 3;
	const connect_timeout = 10;
	const timeout = 30;

	/**
	 * @param string $url     feed URL
	 * @param array  $options etag, last_modified, pin, check_destination (bool), certinfo (bool)
	 *
	 * @return array ok, not_modified, status, body, bytes, sha256, etag, last_modified, content_type, error,
	 *               tls_subject, url
	 */
	public function fetch(string $url, array $options = []): array {
		$result = [
			'ok' => false, 'not_modified' => false, 'status' => 0, 'body' => '', 'bytes' => 0, 'sha256' => null,
			'etag' => null, 'last_modified' => null, 'content_type' => null, 'error' => null, 'tls_subject' => null,
			'url' => $url,
		];
		if (!function_exists('curl_init')) {
			$result['error'] = 'php curl extension is not installed';
			return $result;
		}
		$pin = $options['pin'] ?? '';
		if ($pin !== '' && $pin !== null) {
			$pin = self::normalise_pin((string)$pin);
			if ($pin === null) {
				$result['error'] = 'invalid TLS pin';
				return $result;
			}
		}

		for ($hop = 0; $hop <= self::max_redirects; $hop++) {
			$error = self::check_url($url);
			if ($error !== null) {
				$result['error'] = $error;
				return $result;
			}
			$resolve = [];
			if (!empty($options['check_destination'])) {
				[$addresses, $error] = self::resolve_public(parse_url($url, PHP_URL_HOST));
				if ($error !== null) {
					$result['error'] = $error;
					return $result;
				}
				$port = parse_url($url, PHP_URL_PORT) ?: 443;
				$host = parse_url($url, PHP_URL_HOST);
				$resolve[] = $host.':'.$port.':'.implode(',', array_map(fn($a) => (strpos($a, ':') !== false ? '['.$a.']' : $a), $addresses));
			}

			$response = $this->request($url, $hop === 0 ? $options : [], $pin, $resolve, !empty($options['certinfo']));
			if ($response['error'] !== null) {
				$result['error'] = $response['error'];
				$result['status'] = $response['status'];
				return $result;
			}
			if ($response['status'] >= 300 && $response['status'] < 400 && $response['status'] !== 304) {
				if (empty($response['location'])) {
					$result['error'] = 'redirect without location';
					$result['status'] = $response['status'];
					return $result;
				}
				$url = $response['location'];
				$result['url'] = $url;
				continue;
			}

			$result['status'] = $response['status'];
			$result['content_type'] = $response['content_type'];
			$result['tls_subject'] = $response['tls_subject'];
			if ($response['status'] === 304) {
				$result['ok'] = true;
				$result['not_modified'] = true;
				return $result;
			}
			if ($response['status'] !== 200) {
				$result['error'] = 'HTTP status '.$response['status'];
				return $result;
			}
			if ($response['body'] === '') {
				$result['error'] = 'empty body';
				return $result;
			}
			$result['ok'] = true;
			$result['body'] = $response['body'];
			$result['bytes'] = strlen($response['body']);
			$result['sha256'] = hash('sha256', $response['body']);
			$result['etag'] = $response['etag'];
			$result['last_modified'] = $response['last_modified'];
			return $result;
		}
		$result['error'] = 'too many redirects';
		return $result;
	}

	/**
	 * One request without following redirects.
	 */
	private function request(string $url, array $options, ?string $pin, array $resolve, bool $certinfo): array {
		$response = ['status' => 0, 'body' => '', 'error' => null, 'location' => null, 'etag' => null, 'last_modified' => null, 'content_type' => null, 'tls_subject' => null];
		$body = '';
		$too_large = false;
		$headers = [];

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		if (defined('CURLOPT_PROTOCOLS_STR')) {
			curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
			curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
		}
		else {
			curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
			curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
		}
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_MAXREDIRS, self::max_redirects);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::connect_timeout);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::timeout);
		curl_setopt($ch, CURLOPT_USERAGENT, 'x_carrier_acl/1.0 (FusionPBX)');
		curl_setopt($ch, CURLOPT_NOSIGNAL, true);
		if ($pin !== null && $pin !== '') {
			curl_setopt($ch, CURLOPT_PINNEDPUBLICKEY, $pin);
		}
		if (!empty($resolve)) {
			curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
		}
		if ($certinfo) {
			curl_setopt($ch, CURLOPT_CERTINFO, true);
		}
		$request_headers = ['Accept: */*'];
		if (!empty($options['etag'])) {
			$request_headers[] = 'If-None-Match: '.self::header_value($options['etag']);
		}
		if (!empty($options['last_modified'])) {
			$request_headers[] = 'If-Modified-Since: '.self::header_value($options['last_modified']);
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $request_headers);

		//headers of the final response only (reset on each status line)
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$headers) {
			if (preg_match('#^HTTP/\S+\s+\d+#', $line)) {
				$headers = [];
			}
			elseif (strpos($line, ':') !== false) {
				[$name, $value] = explode(':', $line, 2);
				$headers[strtolower(trim($name))] = trim($value);
			}
			return strlen($line);
		});

		//body cap enforced while downloading; Content-Length is not trusted
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use (&$body, &$too_large) {
			if (strlen($body) + strlen($chunk) > self::max_bytes) {
				$too_large = true;
				return 0;
			}
			$body .= $chunk;
			return strlen($chunk);
		});
		curl_setopt($ch, CURLOPT_NOPROGRESS, false);
		curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $download_total, $downloaded) use (&$too_large) {
			if ($downloaded > self::max_bytes) {
				$too_large = true;
				return 1;
			}
			return 0;
		});

		$ok = curl_exec($ch);
		$response['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		if ($too_large) {
			$response['error'] = 'response larger than 2 MiB';
		}
		elseif ($ok === false) {
			$response['error'] = 'curl error '.curl_errno($ch).': '.curl_error($ch);
		}
		else {
			$response['body'] = $body;
			$response['location'] = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
			$response['content_type'] = substr((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), 0, 128) ?: null;
			$response['etag'] = isset($headers['etag']) ? substr($headers['etag'], 0, 256) : null;
			$response['last_modified'] = isset($headers['last-modified']) ? substr($headers['last-modified'], 0, 64) : null;
			if ($certinfo) {
				$info = curl_getinfo($ch, CURLINFO_CERTINFO);
				if (is_array($info) && isset($info[0]['Subject'])) {
					$response['tls_subject'] = substr((string)$info[0]['Subject'], 0, 256);
				}
			}
		}
		curl_close($ch);
		return $response;
	}

	/**
	 * URL rules applied before any network call. Returns an error or null.
	 */
	public static function check_url(string $url): ?string {
		if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
			return 'invalid URL';
		}
		$parts = parse_url($url);
		if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
			return 'URL scheme must be https';
		}
		if (empty($parts['host'])) {
			return 'URL has no host';
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			return 'credentials in the URL are not allowed';
		}
		return null;
	}

	/**
	 * Resolve a host and refuse private/loopback/link-local/reserved destinations. Returns [addresses, error].
	 */
	public static function resolve_public(?string $host): array {
		if (empty($host)) {
			return [[], 'URL has no host'];
		}
		$host = trim($host, '[]');
		if (@inet_pton($host) !== false) {
			$addresses = [$host];
		}
		else {
			$addresses = [];
			$records = @dns_get_record($host, DNS_A | DNS_AAAA);
			if (is_array($records)) {
				foreach ($records as $record) {
					if (!empty($record['ip'])) {
						$addresses[] = $record['ip'];
					}
					if (!empty($record['ipv6'])) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
			if (empty($addresses)) {
				$v4 = @gethostbynamel($host);
				if (is_array($v4)) {
					$addresses = $v4;
				}
			}
		}
		if (empty($addresses)) {
			return [[], 'host does not resolve'];
		}
		foreach ($addresses as $address) {
			$n = x_carrier_acl_validate::normalise($address);
			if (!is_array($n) || x_carrier_acl_validate::is_reserved($n)) {
				return [[], 'destination address '.$address.' is private or reserved'];
			}
		}
		return [array_values(array_unique($addresses)), null];
	}

	/**
	 * Accept "sha256//<base64>" or bare base64 of a 32 byte digest; returns the curl form or null.
	 */
	public static function normalise_pin(string $pin): ?string {
		$pin = trim($pin);
		if (str_starts_with($pin, 'sha256//')) {
			$pin = substr($pin, 8);
		}
		$raw = base64_decode($pin, true);
		if ($raw === false || strlen($raw) !== 32) {
			return null;
		}
		return 'sha256//'.base64_encode($raw);
	}

	/**
	 * Strip characters that could break a request header.
	 */
	private static function header_value(string $value): string {
		return substr(preg_replace('/[\x00-\x1f\x7f]/', '', $value), 0, 256);
	}

}

?>
