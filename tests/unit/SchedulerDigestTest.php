<?php
/**
 * Digest cadence helper.
 */

use InterSoccer\PlayerBirthdays\Scheduler;
use InterSoccer\PlayerBirthdays\Logger;
use InterSoccer\PlayerBirthdays\Settings;
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
}
