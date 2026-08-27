<?php
/**
 * Admin AJAX capability and nonce gates.
 */

use InterSoccer\PlayerBirthdays\Admin;
use PHPUnit\Framework\TestCase;

class AjaxCapTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_nonce_ok'] = true;
		$GLOBALS['wp_can_manage'] = true;
	}

	public function test_verify_request_ok() {
		$this->assertTrue(Admin::verify_request(false));
	}

	public function test_verify_request_rejects_bad_nonce() {
		$GLOBALS['wp_nonce_ok'] = false;
		$this->assertFalse(Admin::verify_request(false));
	}

	public function test_verify_request_rejects_missing_cap() {
		$GLOBALS['wp_can_manage'] = false;
		$this->assertFalse(Admin::verify_request(false));
	}
}
