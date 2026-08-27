<?php
/**
 * Plugin bootstrap.
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Loads hooks after dependency check.
 */
class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var Scheduler
	 */
	private $scheduler;

	/**
	 * @var Admin
	 */
	private $admin;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if (!(self::$instance instanceof self)) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Activation: table + defaults + cron.
	 *
	 * @return void
	 */
	public static function activate() {
		try {
			Logger::create_table();
			if (false === get_option(Settings::OPTION_KEY, false)) {
				update_option(Settings::OPTION_KEY, Settings::defaults());
			}
			if (false === get_option(Templates::OPTION_KEY, false)) {
				update_option(Templates::OPTION_KEY, Templates::defaults());
			}
			Scheduler::schedule_cron();
		} catch (\Throwable $e) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('InterSoccer Player Birthdays activate failed: ' . $e->getMessage());
			}
		}
	}

	/**
	 * Deactivation: unschedule only (keep log table).
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::unschedule();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
		$this->scheduler = new Scheduler($this->logger);
		$this->admin = new Admin($this->logger);
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if (!$this->dependencies_ok()) {
			add_action('admin_notices', array($this, 'dependency_notice'));
			return;
		}

		load_plugin_textdomain(
			'intersoccer-player-birthdays',
			false,
			dirname(plugin_basename(INTERSOCCER_PLAYER_BIRTHDAYS_FILE)) . '/languages'
		);

		$this->scheduler->register();
		$this->admin->register();
		add_action('init', array(OptOut::class, 'handle_public_request'), 5);
	}

	/**
	 * WooCommerce + Player Management getter.
	 *
	 * @return bool
	 */
	public function dependencies_ok() {
		if (!function_exists('is_plugin_active')) {
			$path = defined('ABSPATH') ? ABSPATH . 'wp-admin/includes/plugin.php' : '';
			if ($path !== '' && is_readable($path)) {
				require_once $path;
			}
		}
		$woo = function_exists('is_plugin_active') && (is_plugin_active('woocommerce/woocommerce.php') || class_exists('WooCommerce'));
		$pm = function_exists('intersoccer_get_user_players');
		return $woo && $pm;
	}

	/**
	 * Admin notice when PM or Woo is missing.
	 *
	 * @return void
	 */
	public function dependency_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__('InterSoccer Player Birthdays requires WooCommerce and Player Management (intersoccer_get_user_players).', 'intersoccer-player-birthdays');
		echo '</p></div>';
	}

	/**
	 * @return Logger
	 */
	public function logger() {
		return $this->logger;
	}
}
