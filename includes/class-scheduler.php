<?php
/**
 * Daily cron + Action Scheduler batches.
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Schedules digest and auto-sends.
 */
class Scheduler {

	const CRON_HOOK = 'intersoccer_player_birthdays_daily';
	const AS_HOOK = 'intersoccer_player_birthdays_send_batch';
	const AS_GROUP = 'intersoccer-player-birthdays';
	const BATCH_SIZE = 25;

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
	 * Register cron/AS hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action(self::CRON_HOOK, array($this, 'run_daily'));
		add_action(self::AS_HOOK, array($this, 'send_batch'), 10, 1);
	}

	/**
	 * Schedule daily event if missing.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
			return;
		}
		if (!wp_next_scheduled(self::CRON_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
		}
	}

	/**
	 * Clear cron and Action Scheduler jobs.
	 *
	 * @return void
	 */
	public static function unschedule() {
		if (function_exists('wp_clear_scheduled_hook')) {
			wp_clear_scheduled_hook(self::CRON_HOOK);
		}
		if (function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(self::AS_HOOK, array(), self::AS_GROUP);
		}
	}

	/**
	 * Daily runner: digest then auto-send queue.
	 *
	 * @return void
	 */
	public function run_daily() {
		$settings = Settings::get();
		$now = Settings::now();
		$mailer = new Mailer($this->logger);

		if (!empty($settings['digest_enabled']) && $this->should_send_digest($now, $settings)) {
			$upcoming = Finder::scan($now, (int) $settings['look_ahead_days'], null);
			$mailer->send_digest($upcoming);
			update_option(Settings::LAST_DIGEST_OPTION, $now->format('Y-m-d'));
		}

		if (empty($settings['automation_enabled'])) {
			return;
		}

		$due = Finder::scan($now, (int) $settings['look_ahead_days'], (int) $settings['lead_days']);
		$payloads = array();
		foreach ($due as $row) {
			if (!empty($row['opted_out'])) {
				continue;
			}
			if ($this->logger->already_sent($row['player_id'], (int) $row['occurrence_year'])) {
				continue;
			}
			$payloads[] = array(
				'user_id'   => (int) $row['user_id'],
				'player_id' => $row['player_id'],
			);
		}
		$chunks = array_chunk($payloads, self::BATCH_SIZE);
		foreach ($chunks as $chunk) {
			$this->enqueue_or_send($chunk);
		}
	}

	/**
	 * @param \DateTimeImmutable   $now      Today.
	 * @param array<string, mixed> $settings Settings.
	 * @return bool
	 */
	public function should_send_digest(\DateTimeImmutable $now, array $settings) {
		$today = $now->format('Y-m-d');
		$last = (string) get_option(Settings::LAST_DIGEST_OPTION, '');
		if ($last === $today) {
			return false;
		}
		$cadence = isset($settings['digest_cadence']) ? (string) $settings['digest_cadence'] : 'daily';
		if ($cadence !== 'weekly') {
			return true;
		}
		if ($last === '') {
			return true;
		}
		try {
			$last_dt = new \DateTimeImmutable($last, $now->getTimezone() ?: Settings::timezone());
		} catch (\Exception $e) {
			return true;
		}
		$diff = (int) $last_dt->diff($now->setTime(0, 0, 0))->format('%a');
		return $diff >= 7;
	}

	/**
	 * @param array<int, array{user_id:int,player_id:string}> $chunk Batch.
	 * @return void
	 */
	public function enqueue_or_send(array $chunk) {
		if (function_exists('as_enqueue_async_action')) {
			as_enqueue_async_action(self::AS_HOOK, array($chunk), self::AS_GROUP);
			return;
		}
		$this->send_batch($chunk);
	}

	/**
	 * Action Scheduler / inline worker.
	 *
	 * @param array<int, array<string, mixed>> $chunk Batch.
	 * @return void
	 */
	public function send_batch($chunk) {
		if (!is_array($chunk) || $chunk === array()) {
			return;
		}
		$mailer = new Mailer($this->logger);
		$now = Settings::now();
		$settings = Settings::get();
		$lead = (int) $settings['lead_days'];
		foreach ($chunk as $item) {
			if (!is_array($item)) {
				continue;
			}
			$user_id = isset($item['user_id']) ? (int) $item['user_id'] : 0;
			$player_id = isset($item['player_id']) ? sanitize_text_field((string) $item['player_id']) : '';
			if ($user_id < 1 || $player_id === '') {
				continue;
			}
			$resolved = $this->resolve_due_player($user_id, $player_id, $now, $lead);
			if ($resolved === null) {
				continue;
			}
			$mailer->send_greeting($resolved, 'auto');
		}
	}

	/**
	 * Re-evaluate a queued player before sending.
	 *
	 * @param int                $user_id   Guardian.
	 * @param string             $player_id UUID.
	 * @param \DateTimeImmutable $now       Today.
	 * @param int                $lead      Lead days.
	 * @return array<string, mixed>|null
	 */
	private function resolve_due_player($user_id, $player_id, \DateTimeImmutable $now, $lead) {
		if (Finder::is_opted_out($user_id)) {
			return null;
		}
		$match = null;
		if (function_exists('intersoccer_get_player_by_id')) {
			$found = intersoccer_get_player_by_id($user_id, $player_id);
			if (is_array($found) && isset($found['player']) && is_array($found['player'])) {
				$match = $found['player'];
			}
		}
		if ($match === null && function_exists('intersoccer_get_user_players')) {
			$players = intersoccer_get_user_players($user_id);
			if (is_array($players)) {
				foreach ($players as $player) {
					if (is_array($player) && isset($player['player_id']) && (string) $player['player_id'] === $player_id) {
						$match = $player;
						break;
					}
				}
			}
		}
		if (!is_array($match)) {
			return null;
		}
		$row = Finder::evaluate_player($match, $user_id, $now, $lead, $lead);
		if ($row === null) {
			return null;
		}
		$user = get_userdata($user_id);
		if (!$user) {
			return null;
		}
		$row['user_email'] = is_email($user->user_email) ? $user->user_email : (string) get_user_meta($user_id, 'billing_email', true);
		$first = get_user_meta($user_id, 'billing_first_name', true);
		$row['guardian_first_name'] = is_string($first) && $first !== '' ? $first : (string) $user->display_name;
		return $row;
	}
}
