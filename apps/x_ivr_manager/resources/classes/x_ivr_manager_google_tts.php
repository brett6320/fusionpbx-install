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
 * Google Cloud Text-to-Speech client used to build phrases.
 *
 * The upstream speech app only accepts its own engines (openai, elevenlabs,
 * local, inworld), so phrases call the Google REST API directly. Credentials,
 * in order:
 * - a service account key file (x_ivr_manager > google_tts_credentials, default
 *   /etc/fusionpbx/google-tts.json, readable by www-data): a signed JWT is
 *   exchanged for an access token, as Google's client libraries do;
 * - an API key (x_ivr_manager > google_tts_api_key), sent in the X-Goog-Api-Key
 *   header, never in the URL, so it stays out of proxy logs.
 */
class x_ivr_manager_google_tts {

	const api_url = 'https://texttospeech.googleapis.com/v1';
	const token_url = 'https://oauth2.googleapis.com/token';
	const scope = 'https://www.googleapis.com/auth/cloud-platform';

	//the API refuses input longer than 5000 bytes
	const max_text_bytes = 5000;

	/**
	 * declare public variables
	 */
	public $message;

	/**
	 * declare private variables
	 */
	private $settings;

	/**
	 * Initialize the object
	 *
	 * @param settings $settings
	 */
	public function __construct($settings) {
		$this->settings = $settings;
	}

	/**
	 * True when credentials are set and curl is available
	 *
	 * @return bool
	 */
	public function enabled() {
		return function_exists('curl_init') && ($this->service_account() !== null || $this->api_key() !== '');
	}

	/**
	 * Default voice, e.g. en-US-Wavenet-D
	 *
	 * @return string
	 */
	public function default_voice() {
		return trim((string)$this->settings->get('x_ivr_manager', 'google_tts_voice', 'en-US-Wavenet-D'));
	}

	/**
	 * Language code of the voice list, e.g. en-US
	 *
	 * @return string
	 */
	public function language_code() {
		$code = trim((string)$this->settings->get('x_ivr_manager', 'google_tts_language_code', 'en-US'));
		return preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/', $code) ? $code : 'en-US';
	}

	/**
	 * Voices for the configured language, name => label
	 *
	 * Cached in the session for an hour, as the list rarely changes.
	 *
	 * @return array
	 */
	public function voices() {
		$language_code = $this->language_code();
		$cache = $_SESSION['x_ivr_manager']['google_voices'] ?? null;
		if (is_array($cache) && ($cache['language_code'] ?? '') === $language_code && ($cache['time'] ?? 0) > time() - 3600) {
			return $cache['voices'];
		}
		$response = $this->request('GET', '/voices?languageCode='.rawurlencode($language_code));
		if (!is_array($response) || !isset($response['voices']) || !is_array($response['voices'])) {
			return [];
		}
		$voices = [];
		foreach ($response['voices'] as $voice) {
			$name = (string)($voice['name'] ?? '');
			if (!self::valid_voice($name)) {
				continue;
			}
			$gender = ucfirst(strtolower((string)($voice['ssmlGender'] ?? '')));
			$voices[$name] = $name.($gender !== '' ? ' ('.$gender.')' : '');
		}
		ksort($voices);
		$_SESSION['x_ivr_manager']['google_voices'] = ['language_code' => $language_code, 'time' => time(), 'voices' => $voices];
		return $voices;
	}

	/**
	 * Voice names are letters, digits and dashes, e.g. en-US-Neural2-C
	 *
	 * @param string $voice
	 * @return bool
	 */
	public static function valid_voice($voice) {
		return is_string($voice) && preg_match('/^[a-zA-Z]{2,3}-[a-zA-Z0-9]{2,8}(-[a-zA-Z0-9]+){1,3}$/', $voice) === 1;
	}

	/**
	 * Language code of a voice name, e.g. en-US-Neural2-C gives en-US
	 *
	 * @param string $voice
	 * @return string
	 */
	public static function voice_language_code($voice) {
		$parts = explode('-', $voice);
		return $parts[0].'-'.($parts[1] ?? '');
	}

	/**
	 * Synthesize text to a mono 16 bit PCM WAV file
	 *
	 * @param string $message plain text
	 * @param string $voice voice name
	 * @param float $speed speaking rate, 0.25 to 2.0
	 * @param int $sample_rate 8000 or 16000
	 * @param string $path destination file, must not exist
	 * @return bool
	 */
	public function synthesize($message, $voice, $speed, $sample_rate, $path) {
		$this->message = null;
		if ($message === '' || strlen($message) > self::max_text_bytes) {
			$this->message = 'message-phrase_text_invalid';
			return false;
		}
		if (!self::valid_voice($voice)) {
			$this->message = 'message-phrase_voice_invalid';
			return false;
		}
		$speed = max(0.25, min(2.0, (float)$speed));
		$sample_rate = ((int)$sample_rate === 16000) ? 16000 : 8000;

		$body['input']['text'] = $message;
		$body['voice']['languageCode'] = self::voice_language_code($voice);
		$body['voice']['name'] = $voice;
		$body['audioConfig']['audioEncoding'] = 'LINEAR16';
		$body['audioConfig']['sampleRateHertz'] = $sample_rate;
		$body['audioConfig']['speakingRate'] = $speed;
		$response = $this->request('POST', '/text:synthesize', $body);
		if (!is_array($response) || empty($response['audioContent'])) {
			$this->message = $this->message ?? 'message-phrase_tts_failed';
			return false;
		}
		$audio = base64_decode((string)$response['audioContent'], true);
		//LINEAR16 comes back as a WAV file with its RIFF header
		if ($audio === false || strlen($audio) < 44 || substr($audio, 0, 4) !== 'RIFF' || substr($audio, 8, 4) !== 'WAVE') {
			$this->message = 'message-phrase_tts_failed';
			return false;
		}
		if (file_put_contents($path, $audio, LOCK_EX) !== strlen($audio)) {
			if (file_exists($path)) {
				unlink($path);
			}
			$this->message = 'message-phrase_tts_failed';
			return false;
		}
		return true;
	}

	/**
	 * Key from the default settings
	 *
	 * @return string
	 */
	private function api_key() {
		return trim((string)$this->settings->get('x_ivr_manager', 'google_tts_api_key', ''));
	}

	/**
	 * Service account from the key file, null when there is none
	 *
	 * @return array|null client_email, private_key
	 */
	private function service_account() {
		$path = trim((string)$this->settings->get('x_ivr_manager', 'google_tts_credentials', '/etc/fusionpbx/google-tts.json'));
		if ($path === '' || !is_file($path) || !is_readable($path)) {
			return null;
		}
		$data = json_decode((string)file_get_contents($path), true);
		if (!is_array($data) || ($data['type'] ?? '') !== 'service_account' || empty($data['client_email']) || empty($data['private_key'])) {
			error_log('x_ivr_manager: '.$path.' is not a Google service account key file');
			return null;
		}
		return ['client_email' => (string)$data['client_email'], 'private_key' => (string)$data['private_key']];
	}

	/**
	 * Access token for the service account, cached until a minute before it expires
	 *
	 * @param array $account
	 * @return string|null
	 */
	private function access_token(array $account) {
		$cache = $_SESSION['x_ivr_manager']['google_token'] ?? null;
		if (is_array($cache) && ($cache['client_email'] ?? '') === $account['client_email'] && ($cache['expires'] ?? 0) > time() + 60) {
			return $cache['token'];
		}
		$b64 = function($value) {
			return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
		};
		$now = time();
		$header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
		$claims = $b64(json_encode(['iss' => $account['client_email'], 'scope' => self::scope, 'aud' => self::token_url, 'iat' => $now, 'exp' => $now + 3600]));
		$key = openssl_pkey_get_private($account['private_key']);
		if ($key === false || !openssl_sign($header.'.'.$claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
			error_log('x_ivr_manager: cannot sign with the Google service account key');
			return null;
		}
		$assertion = $header.'.'.$claims.'.'.$b64($signature);
		//the token url is fixed, never the token_uri from the key file
		$ch = curl_init(self::token_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]));
		$result = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$data = is_string($result) ? json_decode($result, true) : null;
		if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
			error_log('x_ivr_manager: Google token request failed with HTTP '.$status.(is_array($data) && !empty($data['error']) ? ': '.(string)$data['error'] : ''));
			return null;
		}
		$expires = $now + (int)($data['expires_in'] ?? 3600);
		$_SESSION['x_ivr_manager']['google_token'] = ['client_email' => $account['client_email'], 'token' => (string)$data['access_token'], 'expires' => $expires];
		return (string)$data['access_token'];
	}

	/**
	 * Call the API
	 *
	 * @param string $method GET or POST
	 * @param string $path path below api_url
	 * @param array|null $body json body
	 * @return array|null decoded response, null on failure
	 */
	private function request($method, $path, $body = null) {
		if (!$this->enabled()) {
			$this->message = 'message-phrase_tts_disabled';
			return null;
		}
		$account = $this->service_account();
		if ($account !== null) {
			$token = $this->access_token($account);
			if ($token === null) {
				$this->message = 'message-phrase_tts_rejected';
				return null;
			}
			$headers = ['Authorization: Bearer '.$token, 'Accept: application/json'];
		}
		else {
			$headers = ['X-Goog-Api-Key: '.$this->api_key(), 'Accept: application/json'];
		}
		$ch = curl_init(self::api_url.$path);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
		if ($method === 'POST') {
			$headers[] = 'Content-Type: application/json; charset=utf-8';
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$result = curl_exec($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		if ($result === false) {
			error_log('x_ivr_manager: Google Text-to-Speech request failed: '.$error);
			return null;
		}
		$data = json_decode($result, true);
		if ($status !== 200) {
			//the error message never contains the key
			$detail = is_array($data) ? (string)($data['error']['message'] ?? '') : '';
			error_log('x_ivr_manager: Google Text-to-Speech returned HTTP '.$status.($detail !== '' ? ': '.$detail : ''));
			if ($status === 400 || $status === 403) {
				$this->message = 'message-phrase_tts_rejected';
			}
			return null;
		}
		return is_array($data) ? $data : null;
	}

}

?>
