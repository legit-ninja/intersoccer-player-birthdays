<?php
/**
 * Plugin settings.
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Stored options for automation, digest, and lead window.
 */
class Settings {

	const OPTION_KEY = 'intersoccer_player_birthdays_settings';
	const LAST_DIGEST_OPTION = 'intersoccer_bday_last_digest_ymd';
	const TIMEZONE = 'Europe/Zurich';

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'automation_enabled'      => false,
			'digest_enabled'          => true,
			'digest_cadence'          => 'daily',
			'lead_days'               => 7,
			'look_ahead_days'         => 60,
			'min_notice_days'         => 14,
			'digest_extra_recipients' => '',
			'test_email'              => '',
		);
	}

	/**
	 * Merged settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$stored = get_option(self::OPTION_KEY, array());
		if (!is_array($stored)) {
			$stored = array();
		}
		$merged = array_merge(self::defaults(), $stored);
		if (!array_key_exists('min_notice_days', $stored)) {
			$merged['min_notice_days'] = 14;
			if ((int) $merged['look_ahead_days'] <= 14) {
				$merged['look_ahead_days'] = 60;
			}
		}
		return $merged;
	}

	/**
	 * Persist settings after sanitizing.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function update(array $input) {
		$clean = self::defaults();
		$clean['automation_enabled'] = !empty($input['automation_enabled']);
		$clean['digest_enabled'] = !empty($input['digest_enabled']);
		$cadence = isset($input['digest_cadence']) ? sanitize_key((string) $input['digest_cadence']) : 'daily';
		$clean['digest_cadence'] = in_array($cadence, array('daily', 'weekly'), true) ? $cadence : 'daily';
		$clean['lead_days'] = self::clamp_int($input['lead_days'] ?? 7, 1, 30, 7);
		$clean['look_ahead_days'] = self::clamp_int($input['look_ahead_days'] ?? 60, 1, 90, 60);
		$clean['min_notice_days'] = self::clamp_int($input['min_notice_days'] ?? 14, 0, 90, 14);
		if ($clean['min_notice_days'] > $clean['look_ahead_days']) {
			$clean['min_notice_days'] = $clean['look_ahead_days'];
		}
		$clean['digest_extra_recipients'] = self::sanitize_email_list($input['digest_extra_recipients'] ?? '');
		$test = isset($input['test_email']) ? sanitize_email((string) $input['test_email']) : '';
		$clean['test_email'] = is_email($test) ? $test : '';
		update_option(self::OPTION_KEY, $clean);
		return $clean;
	}

	/**
	 * @param mixed $value Raw.
	 * @param int   $min   Min.
	 * @param int   $max   Max.
	 * @param int   $fallback Default.
	 * @return int
	 */
	public static function clamp_int($value, $min, $max, $fallback) {
		$n = is_numeric($value) ? (int) $value : $fallback;
		return max($min, min($max, $n));
	}

	/**
	 * Comma/newline separated emails.
	 *
	 * @param string $raw Raw list.
	 * @return string
	 */
	public static function sanitize_email_list($raw) {
		$parts = preg_split('/[\s,;]+/', (string) $raw) ?: array();
		$valid = array();
		foreach ($parts as $part) {
			$email = sanitize_email($part);
			if (is_email($email)) {
				$valid[] = $email;
			}
		}
		return implode(', ', array_unique($valid));
	}

	/**
	 * Europe/Zurich timezone.
	 *
	 * @return \DateTimeZone
	 */
	public static function timezone() {
		return new \DateTimeZone(self::TIMEZONE);
	}

	/**
	 * “Now” in Zurich (start of current second).
	 *
	 * @return \DateTimeImmutable
	 */
	public static function now() {
		return new \DateTimeImmutable('now', self::timezone());
	}
}
