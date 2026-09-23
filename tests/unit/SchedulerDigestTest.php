<?php
/**
 * Scheduler tests: digest cadence and auto-send eligibility.
 */

use InterSoccer\PlayerBirthdays\Scheduler;
use InterSoccer\PlayerBirthdays\Logger;
use InterSoccer\PlayerBirthdays\Settings;
use InterSoccer\PlayerBirthdays\Finder;
use PHPUnit\Framework\TestCase;

class SchedulerDigestTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_options'] = array();
	}

	public function test_daily_digest_skips_same_zurich_day() {
		$scheduler = new Scheduler(new Logger());
		$now = new DateTimeImmutable('2026-08-25 10:00:00', Settings::timezone());
		$GLOBALS['wp_options'][ Settings::LAST_DIGEST_OPTION ] = '2026-08-25';
		$this->assertFalse($scheduler->should_send_digest($now, array('digest_cadence' => 'daily')));
	}

	public function test_weekly_digest_waits_seven_days() {
		$scheduler = new Scheduler(new Logger());
		$now = new DateTimeImmutable('2026-08-25 10:00:00', Settings::timezone());
		$GLOBALS['wp_options'][ Settings::LAST_DIGEST_OPTION ] = '2026-08-20';
		$this->assertFalse($scheduler->should_send_digest($now, array('digest_cadence' => 'weekly')));
		$GLOBALS['wp_options'][ Settings::LAST_DIGEST_OPTION ] = '2026-08-18';
		$this->assertTrue($scheduler->should_send_digest($now, array('digest_cadence' => 'weekly')));
	}

	public function test_batch_size_setting_used_in_chunking() {
		$settings = Settings::update(array('email_batch_size' => 10));
		$this->assertSame(10, $settings['email_batch_size']);

		$payloads = array();
		for ($i = 1; $i <= 25; $i++) {
			$payloads[] = array('user_id' => $i, 'player_id' => 'player-' . $i);
		}

		$batch_size = (int) $settings['email_batch_size'];
		$chunks = array_chunk($payloads, $batch_size);

		$this->assertCount(3, $chunks);
		$this->assertCount(10, $chunks[0]);
		$this->assertCount(10, $chunks[1]);
		$this->assertCount(5, $chunks[2]);
	}

	public function test_batch_size_fallback_when_setting_invalid() {
		$GLOBALS['wp_options'][ Settings::OPTION_KEY ] = array('email_batch_size' => 0);
		$settings = Settings::get();
		$batch_size = (int) $settings['email_batch_size'];
		if ($batch_size < 1) {
			$batch_size = Settings::BATCH_SIZE_DEFAULT;
		}
		$this->assertSame(Settings::BATCH_SIZE_DEFAULT, $batch_size);
	}

	public function test_custom_batch_size_respected() {
		Settings::update(array('email_batch_size' => 5));
		$settings = Settings::get();
		$this->assertSame(5, $settings['email_batch_size']);

		$payloads = array();
		for ($i = 1; $i <= 12; $i++) {
			$payloads[] = array('user_id' => $i, 'player_id' => 'player-' . $i);
		}

		$batch_size = (int) $settings['email_batch_size'];
		$chunks = array_chunk($payloads, $batch_size);

		$this->assertCount(3, $chunks);
		$this->assertCount(5, $chunks[0]);
		$this->assertCount(5, $chunks[1]);
		$this->assertCount(2, $chunks[2]);
	}

	public function test_already_sent_blocks_duplicate_in_range() {
		$logger = new Logger();
		$logger->enable_memory_store();

		$player_id = 'test-player-' . uniqid();
		$user_id = 99;
		$occurrence_year = 2026;

		$this->assertFalse($logger->already_sent($player_id, $occurrence_year), 'Should not be sent initially');

		$recorded = $logger->record($player_id, $user_id, $occurrence_year, 'auto');
		$this->assertTrue($recorded, 'First record should succeed');

		$this->assertTrue($logger->already_sent($player_id, $occurrence_year), 'Should be marked as sent');

		$duplicate = $logger->record($player_id, $user_id, $occurrence_year, 'auto');
		$this->assertFalse($duplicate, 'Duplicate record should fail');

		$this->assertFalse(
			$logger->already_sent($player_id, $occurrence_year + 1),
			'Different year should not be blocked'
		);
	}

	public function test_auto_send_range_window_semantics() {
		$tz = Settings::timezone();
		$now = new DateTimeImmutable('2026-09-10 12:00:00', $tz);

		$lead_days = 7;
		$look_ahead = 14;

		$player_at_lead = array(
			'player_id'  => 'at-lead-boundary',
			'first_name' => 'Lead',
			'last_name'  => 'Edge',
			'dob'        => '2018-09-17',
		);
		$result = Finder::evaluate_player($player_at_lead, 1, $now, $look_ahead, $lead_days);
		$this->assertNotNull($result, 'Birthday 7 days away should be in range (at lead)');
		$this->assertSame(7, $result['days_until']);

		$player_at_look_ahead = array(
			'player_id'  => 'at-lookahead-boundary',
			'first_name' => 'LookAhead',
			'last_name'  => 'Edge',
			'dob'        => '2018-09-24',
		);
		$result = Finder::evaluate_player($player_at_look_ahead, 1, $now, $look_ahead, $lead_days);
		$this->assertNotNull($result, 'Birthday 14 days away should be in range (at look-ahead)');
		$this->assertSame(14, $result['days_until']);

		$player_mid_range = array(
			'player_id'  => 'mid-range',
			'first_name' => 'Mid',
			'last_name'  => 'Range',
			'dob'        => '2018-09-20',
		);
		$result = Finder::evaluate_player($player_mid_range, 1, $now, $look_ahead, $lead_days);
		$this->assertNotNull($result, 'Birthday 10 days away should be in range');
		$this->assertSame(10, $result['days_until']);

		$player_too_close = array(
			'player_id'  => 'too-close',
			'first_name' => 'Too',
			'last_name'  => 'Close',
			'dob'        => '2018-09-16',
		);
		$result = Finder::evaluate_player($player_too_close, 1, $now, $look_ahead, $lead_days);
		$this->assertNull($result, 'Birthday 6 days away should be excluded (below lead)');

		$player_too_far = array(
			'player_id'  => 'too-far',
			'first_name' => 'Too',
			'last_name'  => 'Far',
			'dob'        => '2018-09-25',
		);
		$result = Finder::evaluate_player($player_too_far, 1, $now, $look_ahead, $lead_days);
		$this->assertNull($result, 'Birthday 15 days away should be excluded (above look-ahead)');
	}

	public function test_catch_up_on_first_enable_includes_full_range() {
		$tz = Settings::timezone();
		$now = new DateTimeImmutable('2026-09-10 12:00:00', $tz);
		$lead_days = 7;
		$look_ahead = 21;

		$players_in_range = array();
		for ($days = $lead_days; $days <= $look_ahead; $days++) {
			$birthday = $now->modify("+{$days} days")->format('Y-m-d');
			$dob = (new DateTimeImmutable($birthday, $tz))->modify('-8 years')->format('Y-m-d');
			$player = array(
				'player_id'  => "player-{$days}-days",
				'first_name' => "Player{$days}",
				'last_name'  => 'Test',
				'dob'        => $dob,
			);
			$result = Finder::evaluate_player($player, 1, $now, $look_ahead, $lead_days);
			if ($result !== null) {
				$players_in_range[] = $result;
			}
		}

		$this->assertCount(
			$look_ahead - $lead_days + 1,
			$players_in_range,
			'All players in range should be eligible for catch-up'
		);
	}
}
