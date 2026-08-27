<?php
/**
 * Merge tag replacement.
 */

use InterSoccer\PlayerBirthdays\Templates;
use PHPUnit\Framework\TestCase;

class TemplatesTest extends TestCase {

	public function test_merge_replaces_known_tags() {
		$html = Templates::merge(
			'Hi {{guardian_first_name}}, {{player_first_name}} {{player_last_name}} turns {{age_turning}} on {{birthday_date}}.',
			array(
				'player_first_name'   => 'Alex',
				'player_last_name'    => 'Müller',
				'guardian_first_name' => 'Sam',
				'age_turning'         => '8',
				'birthday_date'       => '25 August 2026',
				'opt_out_url'         => 'https://example.test/?opt=1',
			)
		);
		$this->assertSame(
			'Hi Sam, Alex Müller turns 8 on 25 August 2026.',
			$html
		);
		$this->assertStringNotContainsString('{{', $html);
	}

	public function test_tags_from_candidate_maps_fields() {
		$tags = Templates::tags_from_candidate(
			array(
				'first_name'          => 'Alex',
				'last_name'           => 'Example',
				'guardian_first_name' => 'Sam',
				'age_turning'         => 8,
				'occurrence'          => '2026-08-25',
			),
			'https://example.test/unsub'
		);
		$this->assertSame('Alex', $tags['player_first_name']);
		$this->assertSame('Example', $tags['player_last_name']);
		$this->assertSame('Sam', $tags['guardian_first_name']);
		$this->assertSame('8', $tags['age_turning']);
		$this->assertSame('2026-08-25', $tags['birthday_date']);
		$this->assertSame('https://example.test/unsub', $tags['opt_out_url']);
	}
}
