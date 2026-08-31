<?php
/**
 * Date matching for calendar birthdays.
 */

use InterSoccer\PlayerBirthdays\Finder;
use PHPUnit\Framework\TestCase;

class FinderTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_user_meta'] = array();
	}

	private function tz() {
		return new DateTimeZone('Europe/Zurich');
	}

	private function onDate($ymd) {
		return new DateTimeImmutable($ymd . ' 12:00:00', $this->tz());
	}

	public function test_parse_dob_rejects_empty_and_na() {
		$this->assertNull(Finder::parse_dob('', $this->tz()));
		$this->assertNull(Finder::parse_dob('N/A', $this->tz()));
		$this->assertNull(Finder::parse_dob('not-a-date', $this->tz()));
	}

	public function test_parse_dob_iso_and_swiss() {
		$iso = Finder::parse_dob('2015-05-15', $this->tz());
		$this->assertSame('2015-05-15', $iso->format('Y-m-d'));
		$swiss = Finder::parse_dob('15/05/2015', $this->tz());
		$this->assertSame('2015-05-15', $swiss->format('Y-m-d'));
	}

	public function test_year_wrap_december_to_january() {
		$dob = Finder::parse_dob('2015-01-01', $this->tz());
		$now = $this->onDate('2025-12-25');
		$next = Finder::next_occurrence($dob, $now);
		$this->assertSame('2026-01-01', $next->format('Y-m-d'));
		$this->assertSame(7, Finder::days_until($next, $now));
	}

	public function test_feb_29_non_leap_year_uses_feb_28() {
		$occurrence = Finder::occurrence_for_year(2, 29, 2023, $this->tz());
		$this->assertSame('2023-02-28', $occurrence->format('Y-m-d'));
		$dob = Finder::parse_dob('2016-02-29', $this->tz());
		$now = $this->onDate('2023-01-01');
		$next = Finder::next_occurrence($dob, $now);
		$this->assertSame('2023-02-28', $next->format('Y-m-d'));
	}

	public function test_feb_29_leap_year_keeps_feb_29() {
		$occurrence = Finder::occurrence_for_year(2, 29, 2024, $this->tz());
		$this->assertSame('2024-02-29', $occurrence->format('Y-m-d'));
	}

	public function test_evaluate_player_exact_lead_and_look_ahead() {
		$now = $this->onDate('2026-08-18');
		$player = array(
			'player_id'  => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
			'first_name' => 'Alex',
			'last_name'  => 'Test',
			'dob'        => '2018-08-25',
		);
		$exact = Finder::evaluate_player($player, 9, $now, 14, 7);
		$this->assertNotNull($exact);
		$this->assertSame(7, $exact['days_until']);
		$this->assertSame(2026, $exact['occurrence_year']);
		$this->assertSame(8, $exact['age_turning']);

		$outside = Finder::evaluate_player($player, 9, $this->onDate('2026-08-01'), 14, null);
		$this->assertNull($outside);
	}

	public function test_evaluate_player_five_month_lead_and_look_ahead() {
		$now = $this->onDate('2026-08-18');
		$player = array(
			'player_id'  => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff',
			'first_name' => 'Mia',
			'last_name'  => 'Test',
			'dob'        => '2018-01-15',
		);
		$exact = Finder::evaluate_player($player, 9, $now, 90, 150);
		$this->assertNotNull($exact);
		$this->assertSame(150, $exact['days_until']);

		$in_window = Finder::evaluate_player($player, 9, $now, 153, null);
		$this->assertNotNull($in_window);
		$this->assertSame(150, $in_window['days_until']);

		$too_soon = Finder::evaluate_player($player, 9, $now, 90, null);
		$this->assertNull($too_soon);
	}

	public function test_evaluate_player_skips_missing_id_and_invalid_dob() {
		$now = $this->onDate('2026-08-18');
		$this->assertNull(Finder::evaluate_player(array('dob' => '2018-08-25'), 1, $now, 14, null));
		$this->assertNull(
			Finder::evaluate_player(
				array('player_id' => 'x', 'dob' => 'N/A'),
				1,
				$now,
				14,
				null
			)
		);
	}

	public function test_birthday_today_is_zero_days() {
		$now = $this->onDate('2026-03-10');
		$row = Finder::evaluate_player(
			array(
				'player_id' => 'p1',
				'dob'       => '2016-03-10',
				'first_name' => 'A',
				'last_name'  => 'B',
			),
			1,
			$now,
			14,
			null
		);
		$this->assertSame(0, $row['days_until']);
	}

	public function test_filter_upcoming_hides_near_days_and_searches_parent() {
		$rows = array(
			array(
				'days_until'          => 5,
				'guardian_name'       => 'Sam Example',
				'guardian_first_name' => 'Sam',
				'user_email'          => 'sam@example.test',
				'first_name'          => 'Alex',
				'last_name'           => 'Example',
			),
			array(
				'days_until'          => 14,
				'guardian_name'       => 'Jordan Lee',
				'guardian_first_name' => 'Jordan',
				'user_email'          => 'jordan@example.test',
				'first_name'          => 'Mia',
				'last_name'           => 'Lee',
			),
		);
		$notice = Finder::filter_upcoming_rows($rows, '', 14);
		$this->assertCount(1, $notice);
		$this->assertSame('Jordan Lee', $notice[0]['guardian_name']);

		$search = Finder::filter_upcoming_rows($rows, 'sam', 0);
		$this->assertCount(1, $search);
		$this->assertSame('sam@example.test', $search[0]['user_email']);
	}

	public function test_filter_upcoming_hides_under_twenty_one_days() {
		$rows = array(
			array(
				'days_until'          => 0,
				'guardian_name'       => 'Zero Day',
				'guardian_first_name' => 'Zero',
				'user_email'          => 'zero@example.test',
				'first_name'          => 'A',
				'last_name'           => 'Zero',
			),
			array(
				'days_until'          => 20,
				'guardian_name'       => 'Twenty Day',
				'guardian_first_name' => 'Twenty',
				'user_email'          => 'twenty@example.test',
				'first_name'          => 'B',
				'last_name'           => 'Twenty',
			),
			array(
				'days_until'          => 21,
				'guardian_name'       => 'Twenty One',
				'guardian_first_name' => 'Okay',
				'user_email'          => 'ok@example.test',
				'first_name'          => 'C',
				'last_name'           => 'Okay',
			),
		);
		$notice = Finder::filter_upcoming_rows($rows, '', 21);
		$this->assertCount(1, $notice);
		$this->assertSame('Twenty One', $notice[0]['guardian_name']);
	}

	public function test_is_opted_out_is_per_guardian() {
		$this->assertFalse(Finder::is_opted_out(42));
		update_user_meta(42, Finder::OPT_OUT_META, '1');
		$this->assertTrue(Finder::is_opted_out(42));
		$this->assertFalse(Finder::is_opted_out(43));
		delete_user_meta(42, Finder::OPT_OUT_META);
		$this->assertFalse(Finder::is_opted_out(42));
	}
}
