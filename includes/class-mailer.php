<?php
/**
 * Greeting and digest mailer.
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Sends HTML greetings and the office digest.
 */
class Mailer {

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @param Logger $logger Send log.
	 */
	public function __construct(Logger $logger) {
		$this->logger = $logger;
	}

	/**
	 * Sample candidate for test sends (never a live parent).
	 *
	 * @param int $user_id Current admin user.
	 * @return array<string, mixed>
	 */
	public static function sample_candidate($user_id) {
		$now = Settings::now();
		$occurrence = $now->modify('+7 days');
		return array(
			'player_id'           => '00000000-0000-4000-8000-000000000000',
			'user_id'             => (int) $user_id,
			'first_name'          => 'Alex',
			'last_name'           => 'Example',
			'days_until'          => 7,
			'occurrence'          => $occurrence->format('Y-m-d'),
			'occurrence_year'     => (int) $occurrence->format('Y'),
			'age_turning'         => 8,
			'user_email'          => '',
			'guardian_first_name' => 'Sam',
		);
	}

	/**
	 * Send one greeting. Test mode never writes the unique log row.
	 *
	 * @param array<string, mixed> $candidate Finder row.
	 * @param string               $mode      auto|manual|test.
	 * @param string               $override_to Optional recipient (test).
	 * @return bool
	 */
	public function send_greeting(array $candidate, $mode, $override_to = '') {
		$mode = sanitize_key($mode);
		$player_id = isset($candidate['player_id']) ? sanitize_text_field((string) $candidate['player_id']) : '';
		$user_id = isset($candidate['user_id']) ? (int) $candidate['user_id'] : 0;
		$year = isset($candidate['occurrence_year']) ? (int) $candidate['occurrence_year'] : 0;

		if ($mode !== 'test') {
			if ($player_id === '' || $user_id < 1 || $year < 1) {
				return false;
			}
			if (Finder::is_opted_out($user_id)) {
				return false;
			}
			if ($this->logger->already_sent($player_id, $year)) {
				return false;
			}
		}

		$to = $override_to !== '' ? sanitize_email($override_to) : '';
		if ($to === '' && $mode !== 'test') {
			$to = isset($candidate['user_email']) ? sanitize_email((string) $candidate['user_email']) : '';
		}
		if ($to === '' || !is_email($to)) {
			return false;
		}

		$lang = $mode === 'test' ? 'en' : Templates::language_for_user($user_id);
		$templates = Templates::get();
		$tpl = isset($templates[ $lang ]) ? $templates[ $lang ] : $templates['en'];
		$opt_out_user = $mode === 'test' ? (int) get_current_user_id() : $user_id;
		$tags = Templates::tags_from_candidate($candidate, OptOut::url($opt_out_user > 0 ? $opt_out_user : $user_id));
		$subject = Templates::merge($tpl['subject'], $tags);
		$body = Templates::merge($tpl['body'], $tags);
		$html = $this->wrap_html($subject, $body);

		$sent = $this->mail($to, $subject, $html);
		if (!$sent) {
			return false;
		}
		if ($mode === 'auto' || $mode === 'manual') {
			$this->logger->record($player_id, $user_id, $year, $mode);
		}
		return true;
	}

	/**
	 * Office digest of upcoming birthdays.
	 *
	 * @param array<int, array<string, mixed>> $rows Upcoming rows.
	 * @return bool
	 */
	public function send_digest(array $rows) {
		$recipients = $this->digest_recipients();
		if (empty($recipients)) {
			return false;
		}
		$subject = sprintf(
			/* translators: %d: count of upcoming birthdays */
			__('InterSoccer upcoming birthdays (%d)', 'intersoccer-player-birthdays'),
			count($rows)
		);
		$lines = array();
		$lines[] = '<p>' . esc_html__('Upcoming player birthdays (calendar dates, not birthday-party bookings):', 'intersoccer-player-birthdays') . '</p>';
		if (empty($rows)) {
			$lines[] = '<p>' . esc_html__('None in the current look-ahead window.', 'intersoccer-player-birthdays') . '</p>';
		} else {
			$lines[] = '<table border="1" cellpadding="6" cellspacing="0"><thead><tr>';
			$lines[] = '<th>' . esc_html__('Player', 'intersoccer-player-birthdays') . '</th>';
			$lines[] = '<th>' . esc_html__('Guardian', 'intersoccer-player-birthdays') . '</th>';
			$lines[] = '<th>' . esc_html__('Date', 'intersoccer-player-birthdays') . '</th>';
			$lines[] = '<th>' . esc_html__('Days', 'intersoccer-player-birthdays') . '</th>';
			$lines[] = '<th>' . esc_html__('Turning', 'intersoccer-player-birthdays') . '</th>';
			$lines[] = '</tr></thead><tbody>';
			foreach ($rows as $row) {
				$name = trim((isset($row['first_name']) ? $row['first_name'] : '') . ' ' . (isset($row['last_name']) ? $row['last_name'] : ''));
				$guardian = isset($row['user_email']) ? (string) $row['user_email'] : '';
				if (!empty($row['opted_out'])) {
					$guardian .= ' (' . __('opted out', 'intersoccer-player-birthdays') . ')';
				}
				$lines[] = '<tr>';
				$lines[] = '<td>' . esc_html($name) . '</td>';
				$lines[] = '<td>' . esc_html($guardian) . '</td>';
				$lines[] = '<td>' . esc_html(isset($row['occurrence']) ? (string) $row['occurrence'] : '') . '</td>';
				$lines[] = '<td>' . esc_html((string) (int) ($row['days_until'] ?? 0)) . '</td>';
				$lines[] = '<td>' . esc_html((string) (int) ($row['age_turning'] ?? 0)) . '</td>';
				$lines[] = '</tr>';
			}
			$lines[] = '</tbody></table>';
		}
		$html = $this->wrap_html($subject, implode("\n", $lines));
		$ok = true;
		foreach ($recipients as $to) {
			if (!$this->mail($to, $subject, $html)) {
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * @return string[]
	 */
	public function digest_recipients() {
		$settings = Settings::get();
		$list = array();
		$admin = sanitize_email((string) get_option('admin_email'));
		if (is_email($admin)) {
			$list[] = $admin;
		}
		$extra = Settings::sanitize_email_list($settings['digest_extra_recipients'] ?? '');
		if ($extra !== '') {
			foreach (explode(',', $extra) as $email) {
				$email = sanitize_email(trim($email));
				if (is_email($email)) {
					$list[] = $email;
				}
			}
		}
		return array_values(array_unique($list));
	}

	/**
	 * @param string $heading Heading.
	 * @param string $body    HTML body.
	 * @return string
	 */
	private function wrap_html($heading, $body) {
		if (function_exists('wc_get_template')) {
			ob_start();
			wc_get_template('emails/email-header.php', array('email_heading' => $heading));
			echo wp_kses_post($body);
			wc_get_template('emails/email-footer.php');
			$wrapped = ob_get_clean();
			if (is_string($wrapped) && $wrapped !== '') {
				return $wrapped;
			}
		}
		return '<html><body>' . wp_kses_post($body) . '</body></html>';
	}

	/**
	 * @param string $to      Recipient.
	 * @param string $subject Subject.
	 * @param string $html    HTML body.
	 * @return bool
	 */
	private function mail($to, $subject, $html) {
		$from = $this->from_headers();
		add_filter('wp_mail_content_type', array($this, 'html_content_type'));
		$sent = wp_mail($to, $subject, $html, $from);
		remove_filter('wp_mail_content_type', array($this, 'html_content_type'));
		return (bool) $sent;
	}

	/**
	 * @return string
	 */
	public function html_content_type() {
		return 'text/html';
	}

	/**
	 * @return string[]
	 */
	private function from_headers() {
		$name = '';
		$address = '';
		if (function_exists('get_option')) {
			$name = (string) get_option('woocommerce_email_from_name', '');
			$address = sanitize_email((string) get_option('woocommerce_email_from_address', ''));
		}
		if (!is_email($address)) {
			$address = sanitize_email((string) get_option('admin_email'));
		}
		if ($name === '') {
			$name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'InterSoccer';
		}
		if (!is_email($address)) {
			return array();
		}
		return array(
			sprintf('From: %s <%s>', $name, $address),
		);
	}
}
