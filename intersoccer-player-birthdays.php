<?php
/**
 * Plugin Name: InterSoccer Player Birthdays
 * Plugin URI: https://plugins.underdogunlimited.com
 * Description: Emails guardians before a child's calendar birthday and sends office a digest of upcoming birthdays. Not birthday-party products.
 * Version: 1.8.25
 * Author: Jeremy Lee
 * Author URI: https://underdogunlimited.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: intersoccer-player-birthdays
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 4.0
 * Update URI: https://plugins.underdogunlimited.com
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('INTERSOCCER_PLAYER_BIRTHDAYS_LOADED')) {
	define('INTERSOCCER_PLAYER_BIRTHDAYS_LOADED', true);
	define('INTERSOCCER_PLAYER_BIRTHDAYS_VERSION', '1.8.25');
	define('INTERSOCCER_PLAYER_BIRTHDAYS_FILE', __FILE__);
	define('INTERSOCCER_PLAYER_BIRTHDAYS_PATH', plugin_dir_path(__FILE__));
	define('INTERSOCCER_PLAYER_BIRTHDAYS_URL', plugin_dir_url(__FILE__));
	define('INTERSOCCER_PLAYER_BIRTHDAYS_TEXT_DOMAIN', 'intersoccer-player-birthdays');

	require_once INTERSOCCER_PLAYER_BIRTHDAYS_PATH . 'includes/class-settings.php';
	require_once INTERSOCCER_PLAYER_BIRTHDAYS_PATH . 'includes/class-finder.php';
	require_once INTERSOCCER_PLAYER_BIRTHDAYS_PATH . 'includes/class-templates.php';
	require_once INTERSOCCER_PLAYER_BIRTHDAYS_PATH . 'includes/class-logger.php';
	require_once INTERSOCCER_PLAYER_BIRTHDAYS_PATH . 'includes/class-opt-out.php';
	require_once INTERSOCCER_PLAYER_BIRTHDAYS_PATH . 'includes/class-mailer.php';
	require_once INTERSOCCER_PLAYER_BIRTHDAYS_PATH . 'includes/class-scheduler.php';
	require_once INTERSOCCER_PLAYER_BIRTHDAYS_PATH . 'includes/admin/class-admin.php';
	require_once INTERSOCCER_PLAYER_BIRTHDAYS_PATH . 'includes/class-plugin.php';

	register_activation_hook(__FILE__, array('InterSoccer\\PlayerBirthdays\\Plugin', 'activate'));
	register_deactivation_hook(__FILE__, array('InterSoccer\\PlayerBirthdays\\Plugin', 'deactivate'));

	add_action(
		'plugins_loaded',
		static function () {
			InterSoccer\PlayerBirthdays\Plugin::instance()->boot();
		},
		20
	);
} else {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__('InterSoccer Player Birthdays appears to be loaded twice. Deactivate the older versioned folder.', 'intersoccer-player-birthdays');
			echo '</p></div>';
		}
	);
}
