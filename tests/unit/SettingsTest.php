<?php
/**
 * Settings clamps and look-ahead bump.
 */

use InterSoccer\PlayerBirthdays\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_options'] = array();
	}

	public function test_update_persists_150_lead_days() {
		$out = Settings::update(
			array(
				'lead_days'       => 150,
				'look_ahead_days' => 153,
			)
		);
		$this->assertSame(150, $out['lead_days']);
		$this->assertSame(153, $out['look_ahead_days']);
	}

	public function test_update_clamps_lead_days_to_window_max() {
		$out = Settings::update(
			array(
				'lead_days'       => 200,
				'look_ahead_days' => 153,
			)
		);
		$this->assertSame(Settings::WINDOW_DAYS_MAX, $out['lead_days']);
		$this->assertSame(153, $out['look_ahead_days']);
	}

	public function test_update_raises_look_ahead_when_lead_is_larger() {
		$out = Settings::update(
			array(
				'lead_days'       => 150,
				'look_ahead_days' => 60,
			)
		);
		$this->assertSame(150, $out['lead_days']);
		$this->assertSame(150, $out['look_ahead_days']);
	}

	public function test_update_keeps_defaults_when_days_omitted() {
		$out = Settings::update(array());
		$this->assertSame(7, $out['lead_days']);
		$this->assertSame(60, $out['look_ahead_days']);
		$this->assertSame(14, $out['min_notice_days']);
	}
}
