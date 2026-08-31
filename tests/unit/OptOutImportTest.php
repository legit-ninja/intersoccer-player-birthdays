<?php
/**
 * CSV opt-out import matching.
 */

use InterSoccer\PlayerBirthdays\Finder;
use InterSoccer\PlayerBirthdays\OptOutImport;
use PHPUnit\Framework\TestCase;

class OptOutImportTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_users'] = array();
		$GLOBALS['wp_user_meta'] = array();
		$GLOBALS['wp_transients'] = array();
	}

	private function add_user($id, $email, $display, $billing_email = '', $billing_name = '') {
		$user = (object) array(
			'ID'           => (int) $id,
			'user_email'   => $email,
			'display_name' => $display,
		);
		$GLOBALS['wp_users'][ (int) $id ] = $user;
		if ($billing_email !== '') {
			$GLOBALS['wp_user_meta'][ (int) $id ]['billing_email'] = $billing_email;
		}
		if ($billing_name !== '') {
			$parts = explode(' ', $billing_name, 2);
			$GLOBALS['wp_user_meta'][ (int) $id ]['billing_first_name'] = $parts[0];
			$GLOBALS['wp_user_meta'][ (int) $id ]['billing_last_name'] = isset($parts[1]) ? $parts[1] : '';
		}
		return $user;
	}

	public function test_parse_csv_accepts_email_and_e_mail_headers() {
		$csv = "E-mail,Name\nparent@example.test,Sam Example\nparent@example.test,Duplicate\n";
		$parsed = OptOutImport::parse_csv("\xEF\xBB\xBF" . $csv);
		$this->assertTrue($parsed['ok']);
		$this->assertCount(1, $parsed['rows']);
		$this->assertSame('parent@example.test', $parsed['rows'][0]['email']);
		$this->assertSame('Sam Example', $parsed['rows'][0]['name']);
	}

	public function test_parse_csv_requires_email_column() {
		$parsed = OptOutImport::parse_csv("Name\nSam\n");
		$this->assertFalse($parsed['ok']);
		$this->assertSame('missing_email_column', $parsed['error']);
	}

	public function test_classify_matches_user_email_and_billing_email() {
		$this->add_user(9, 'login@example.test', 'Sam Example');
		$this->add_user(10, 'other@example.test', 'Jordan Lee', 'bill@example.test', 'Jordan Lee');

		$classified = OptOutImport::classify_rows(
			array(
				array('email' => 'login@example.test', 'name' => 'Sam Example'),
				array('email' => 'bill@example.test', 'name' => 'Jordan Lee'),
				array('email' => 'missing@example.test', 'name' => 'Nobody'),
			)
		);
		$this->assertSame('matched', $classified[0]['status']);
		$this->assertSame(9, $classified[0]['user_id']);
		$this->assertFalse($classified[0]['name_mismatch']);
		$this->assertSame('matched', $classified[1]['status']);
		$this->assertSame(10, $classified[1]['user_id']);
		$this->assertSame('unmatched', $classified[2]['status']);
	}

	public function test_classify_skips_already_opted_out() {
		$this->add_user(11, 'out@example.test', 'Out Parent');
		update_user_meta(11, Finder::OPT_OUT_META, '1');
		$classified = OptOutImport::classify_rows(
			array(array('email' => 'out@example.test', 'name' => 'Out Parent'))
		);
		$this->assertSame('already', $classified[0]['status']);
		$this->assertSame(array(), OptOutImport::apply_ids($classified));
	}

	public function test_classify_does_not_write_opt_out() {
		$this->add_user(12, 'keep@example.test', 'Keep Parent');
		OptOutImport::classify_rows(
			array(array('email' => 'keep@example.test', 'name' => 'Keep Parent'))
		);
		$this->assertFalse(Finder::is_opted_out(12));
	}

	public function test_apply_sets_opt_out_meta() {
		$this->add_user(13, 'apply@example.test', 'Apply Parent');
		$this->assertFalse(Finder::is_opted_out(13));
		$n = OptOutImport::apply(array(13, 13, 0));
		$this->assertSame(1, $n);
		$this->assertTrue(Finder::is_opted_out(13));
	}

	public function test_name_mismatch_still_matched() {
		$this->add_user(14, 'match@example.test', 'Alex Parent');
		$classified = OptOutImport::classify_rows(
			array(array('email' => 'match@example.test', 'name' => 'Totally Different'))
		);
		$this->assertSame('matched', $classified[0]['status']);
		$this->assertTrue($classified[0]['name_mismatch']);
		$this->assertSame(array(14), OptOutImport::apply_ids($classified));
	}

	public function test_list_opted_out_prefers_billing_name_and_skips_others() {
		$this->add_user(20, 'out@example.test', 'Display Name', '', 'Sam Billing');
		$this->add_user(21, 'keep@example.test', 'Keep Me');
		update_user_meta(20, Finder::OPT_OUT_META, '1');
		$rows = OptOutImport::list_opted_out();
		$this->assertCount(1, $rows);
		$this->assertSame('Sam Billing', $rows[0]['name']);
		$this->assertSame('out@example.test', $rows[0]['email']);
	}

	public function test_list_opted_out_falls_back_to_display_name() {
		$this->add_user(22, 'disp@example.test', 'Display Only');
		update_user_meta(22, Finder::OPT_OUT_META, '1');
		$rows = OptOutImport::list_opted_out();
		$this->assertCount(1, $rows);
		$this->assertSame('Display Only', $rows[0]['name']);
		$this->assertSame('disp@example.test', $rows[0]['email']);
	}
}
