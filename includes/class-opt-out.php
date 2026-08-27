<?php
/**
 * Guardian opt-out (usermeta + signed public link).
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Opt-out of calendar-birthday greetings.
 */
class OptOut {

	/**
	 * HMAC token for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function token($user_id) {
		$user_id = (int) $user_id;
		$salt = function_exists('wp_salt') ? wp_salt('nonce') : 'intersoccer-player-birthdays';
		return hash_hmac('sha256', (string) $user_id, $salt);
	}

	/**
	 * Public unsubscribe URL.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function url($user_id) {
		$user_id = (int) $user_id;
		$home = function_exists('home_url') ? home_url('/') : '/';
		$args = array(
			'intersoccer_bday_opt_out' => self::token($user_id),
			'uid'                      => $user_id,
		);
		if (function_exists('add_query_arg')) {
			return add_query_arg($args, $home);
		}
		return $home . '?' . http_build_query($args);
	}

	/**
	 * Persist opt-out flag.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function set($user_id) {
		update_user_meta((int) $user_id, Finder::OPT_OUT_META, '1');
	}

	/**
	 * Clear opt-out flag.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function clear($user_id) {
		delete_user_meta((int) $user_id, Finder::OPT_OUT_META);
	}

	/**
	 * Handle public GET unsubscribe (no login).
	 *
	 * @return void
	 */
	public static function handle_public_request() {
		if (!isset($_GET['intersoccer_bday_opt_out'], $_GET['uid'])) {
			return;
		}
		$user_id = absint(wp_unslash($_GET['uid']));
		$token = sanitize_text_field(wp_unslash($_GET['intersoccer_bday_opt_out']));
		if ($user_id < 1 || $token === '' || !hash_equals(self::token($user_id), $token)) {
			wp_die(
				esc_html__('This unsubscribe link is invalid.', 'intersoccer-player-birthdays'),
				esc_html__('Unsubscribe', 'intersoccer-player-birthdays'),
				array('response' => 400)
			);
		}
		self::set($user_id);
		wp_die(
			esc_html__('You have been unsubscribed from InterSoccer birthday greeting emails.', 'intersoccer-player-birthdays'),
			esc_html__('Unsubscribed', 'intersoccer-player-birthdays'),
			array('response' => 200)
		);
	}
}
