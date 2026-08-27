<?php
/**
 * Send log (no PII columns).
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Idempotent auto/manual send records keyed by player_id + occurrence year.
 */
class Logger {

	const TABLE_SUFFIX = 'intersoccer_birthday_email_log';
	const DB_VERSION = 1;
	const DB_VERSION_OPTION = 'intersoccer_player_birthdays_db_version';

	/**
	 * In-memory store for PHPUnit (null = use $wpdb).
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private $memory = null;

	/**
	 * Use an array instead of MySQL.
	 *
	 * @return void
	 */
	public function enable_memory_store() {
		$this->memory = array();
	}

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create or update the log table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			player_id varchar(36) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			occurrence_year smallint(4) unsigned NOT NULL,
			mode varchar(16) NOT NULL,
			sent_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY player_year (player_id, occurrence_year),
			KEY user_id (user_id),
			KEY sent_at (sent_at)
		) {$charset};";
		dbDelta($sql);
		update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
	}

	/**
	 * @param string $player_id UUID.
	 * @param int    $year      Occurrence year.
	 * @return string
	 */
	private static function memory_key($player_id, $year) {
		return $player_id . '|' . (int) $year;
	}

	/**
	 * Whether an auto/manual send already exists for this birthday year.
	 *
	 * @param string $player_id UUID.
	 * @param int    $year      Occurrence year.
	 * @return bool
	 */
	public function already_sent($player_id, $year) {
		$player_id = sanitize_text_field((string) $player_id);
		$year = (int) $year;
		if ($player_id === '' || $year < 1) {
			return false;
		}
		if (is_array($this->memory)) {
			return isset($this->memory[ self::memory_key($player_id, $year) ]);
		}
		global $wpdb;
		$table = self::table_name();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE player_id = %s AND occurrence_year = %d LIMIT 1",
				$player_id,
				$year
			)
		);
		return !empty($found);
	}

	/**
	 * Record a send. Test mode is rejected (does not occupy the unique key).
	 *
	 * @param string $player_id UUID.
	 * @param int    $user_id   Guardian ID.
	 * @param int    $year      Occurrence year.
	 * @param string $mode      auto|manual.
	 * @return bool False if duplicate or invalid.
	 */
	public function record($player_id, $user_id, $year, $mode) {
		$player_id = sanitize_text_field((string) $player_id);
		$user_id = (int) $user_id;
		$year = (int) $year;
		$mode = sanitize_key((string) $mode);
		if ($mode === 'test') {
			return false;
		}
		if (!in_array($mode, array('auto', 'manual'), true) || $player_id === '' || $user_id < 1 || $year < 1) {
			return false;
		}
		if ($this->already_sent($player_id, $year)) {
			return false;
		}
		$row = array(
			'player_id'        => $player_id,
			'user_id'          => $user_id,
			'occurrence_year'  => $year,
			'mode'             => $mode,
			'sent_at'          => gmdate('Y-m-d H:i:s'),
		);
		if (is_array($this->memory)) {
			$this->memory[ self::memory_key($player_id, $year) ] = $row;
			return true;
		}
		global $wpdb;
		$inserted = $wpdb->insert(self::table_name(), $row, array('%s', '%d', '%d', '%s', '%s'));
		return $inserted !== false;
	}

	/**
	 * Recent log rows for the admin list (no PII).
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent($limit = 50) {
		$limit = max(1, min(200, (int) $limit));
		if (is_array($this->memory)) {
			$rows = array_values($this->memory);
			usort(
				$rows,
				static function ($a, $b) {
					return strcmp($b['sent_at'], $a['sent_at']);
				}
			);
			return array_slice($rows, 0, $limit);
		}
		global $wpdb;
		$table = self::table_name();
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT player_id, user_id, occurrence_year, mode, sent_at FROM {$table} ORDER BY sent_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array($results) ? $results : array();
	}
}
