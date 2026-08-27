<?php
/**
 * Idempotent send log.
 */

use InterSoccer\PlayerBirthdays\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase {

	public function test_record_is_unique_per_player_and_year() {
		$logger = new Logger();
		$logger->enable_memory_store();
		$ok = $logger->record('player-1', 12, 2026, 'auto');
		$this->assertTrue($ok);
		$this->assertTrue($logger->already_sent('player-1', 2026));
		$this->assertFalse($logger->record('player-1', 12, 2026, 'manual'));
		$this->assertTrue($logger->record('player-1', 12, 2027, 'manual'));
		$this->assertTrue($logger->record('player-2', 12, 2026, 'auto'));
	}

	public function test_test_mode_does_not_occupy_unique_key() {
		$logger = new Logger();
		$logger->enable_memory_store();
		$this->assertFalse($logger->record('player-1', 12, 2026, 'test'));
		$this->assertFalse($logger->already_sent('player-1', 2026));
		$this->assertTrue($logger->record('player-1', 12, 2026, 'auto'));
	}
}
