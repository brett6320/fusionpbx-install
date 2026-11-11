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
 * Alerts (docs/design/carrier_acl.md section 5.4).
 *
 * Every alert is a syslog line (ident x_carrier_acl). Mail goes through the upstream email class, which queues into
 * v_email_queue when email_queue.enabled is true (drained by the stock email_queue service) and sends directly
 * otherwise. Recipients come from x_carrier_acl.alert_email, falling back to email.smtp_from; domain_uuid is the
 * first domain so the email settings resolve.
 */
class x_carrier_acl_alert {

	private $database;
	private $app;

	public function __construct(database $database, x_carrier_acl $app) {
		$this->database = $database;
		$this->app = $app;
	}

	/**
	 * @param string $event change, failure, approval or firewall (firewall is gated by "failure" in alert_on)
	 * @param string $subject subject without the "[x_carrier_acl] " prefix
	 * @param string $body plain text
	 */
	public function send(string $event, string $subject, string $body): void {
		$priority = in_array($event, ['failure', 'firewall'], true) ? LOG_ERR : LOG_NOTICE;
		x_carrier_acl::log($priority, $subject);

		$alert_on = array_map('trim', explode(',', strtolower((string)$this->app->setting('alert_on'))));
		$gate = ($event === 'firewall') ? 'failure' : $event;
		if (!in_array($gate, $alert_on, true)) {
			return;
		}

		//first domain, so domain scoped email settings resolve
		$sql = "select domain_uuid from v_domains where domain_enabled = true order by domain_name asc limit 1 ";
		$domain_uuid = (string)$this->database->select($sql, null, 'column');
		$settings = new settings(['database' => $this->database, 'domain_uuid' => $domain_uuid]);

		$recipients = trim((string)$this->app->setting('alert_email'));
		if ($recipients === '') {
			$recipients = trim((string)$settings->get('email', 'smtp_from', ''));
		}
		$valid = [];
		foreach (explode(',', $recipients) as $recipient) {
			$recipient = trim($recipient);
			if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
				$valid[] = $recipient;
			}
		}
		if (empty($valid)) {
			x_carrier_acl::log(LOG_WARNING, 'no alert recipient configured; alert not mailed');
			return;
		}

		$email = new email(['database' => $this->database, 'settings' => $settings, 'domain_uuid' => $domain_uuid, 'user_uuid' => '']);
		$email->recipients = implode(',', $valid);
		$email->subject = '[x_carrier_acl] '.$subject;
		//the email class sends HTML (MsgHTML); keep the text exactly as written
		$email->body = "<pre>".htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</pre>";
		$email->from_address = (string)$settings->get('email', 'smtp_from', '');
		$email->from_name = (string)$settings->get('email', 'smtp_from_name', 'FusionPBX');
		ob_start();
		$sent = $email->send();
		ob_end_clean();
		if (!$sent) {
			x_carrier_acl::log(LOG_WARNING, 'alert email could not be queued or sent: '.$subject);
		}
	}

	/**
	 * Text listing of a diff for a mail body.
	 */
	public static function diff_text(array $added, array $removed): string {
		$lines = [];
		if (!empty($added)) {
			$lines[] = 'Added ('.count($added).'):';
			foreach ($added as $entry) {
				$lines[] = '  + '.($entry['cidr'] ?? $entry['entry_cidr'] ?? '').' '.($entry['role'] ?? $entry['entry_role'] ?? '').' '.($entry['label'] ?? $entry['entry_label'] ?? '');
			}
		}
		if (!empty($removed)) {
			$lines[] = 'Removed ('.count($removed).'):';
			foreach ($removed as $entry) {
				$lines[] = '  - '.($entry['cidr'] ?? $entry['entry_cidr'] ?? '').' '.($entry['role'] ?? $entry['entry_role'] ?? '').' '.($entry['label'] ?? $entry['entry_label'] ?? '');
			}
		}
		return implode("\n", $lines);
	}

}

?>
