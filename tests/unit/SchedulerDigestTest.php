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
}
