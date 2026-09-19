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
		$this->assertSame(21, $out['min_notice_days']);
	}

	public function test_get_remaps_legacy_fourteen_notice_to_twenty_one() {
		$GLOBALS['wp_options'][ Settings::OPTION_KEY ] = array(
			'min_notice_days' => 14,
			'look_ahead_days' => 60,
		);
		$out = Settings::get();
		$this->assertSame(21, $out['min_notice_days']);
	}

	public function test_get_keeps_intentional_zero_notice() {
		$GLOBALS['wp_options'][ Settings::OPTION_KEY ] = array(
			'min_notice_days' => 0,
			'look_ahead_days' => 60,
		);
		$out = Settings::get();
		$this->assertSame(0, $out['min_notice_days']);
	}

	public function test_update_keeps_default_batch_size_when_omitted() {
		$out = Settings::update(array());
		$this->assertSame(Settings::BATCH_SIZE_DEFAULT, $out['email_batch_size']);
	}

	public function test_update_persists_valid_batch_size() {
		$out = Settings::update(array('email_batch_size' => 50));
		$this->assertSame(50, $out['email_batch_size']);
	}

	public function test_update_clamps_batch_size_below_min_to_min() {
		$out = Settings::update(array('email_batch_size' => 0));
		$this->assertSame(Settings::BATCH_SIZE_MIN, $out['email_batch_size']);
	}

	public function test_update_clamps_batch_size_above_max_to_max() {
		$out = Settings::update(array('email_batch_size' => 500));
		$this->assertSame(Settings::BATCH_SIZE_MAX, $out['email_batch_size']);
	}

	public function test_update_clamps_negative_batch_size_to_min() {
		$out = Settings::update(array('email_batch_size' => -10));
		$this->assertSame(Settings::BATCH_SIZE_MIN, $out['email_batch_size']);
	}

	public function test_defaults_include_email_batch_size() {
		$defaults = Settings::defaults();
		$this->assertArrayHasKey('email_batch_size', $defaults);
		$this->assertSame(25, $defaults['email_batch_size']);
	}
}
