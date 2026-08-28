<?php
/**
 * Merge tag replacement.
 */

use InterSoccer\PlayerBirthdays\Templates;
use PHPUnit\Framework\TestCase;

class TemplatesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_options']['blogname'] = 'InterSoccer';
	}

	public function test_merge_replaces_known_tags() {
		$html = Templates::merge(
			'Hi {{guardian_first_name}}, {{player_first_name}} {{player_last_name}} turns {{age_turning}} on {{birthday_date}} from {{site_title}}.',
			array(
				'player_first_name'   => 'Alex',
				'player_last_name'    => 'Müller',
				'guardian_first_name' => 'Sam',
				'age_turning'         => '8',
				'birthday_date'       => '25 August 2026',
				'opt_out_url'         => 'https://example.test/?opt=1',
				'site_title'          => 'InterSoccer',
			)
		);
		$this->assertSame(
			'Hi Sam, Alex Müller turns 8 on 25 August 2026 from InterSoccer.',
			$html
		);
		$this->assertStringNotContainsString('{{', $html);
	}

	public function test_merge_leaves_unknown_tags() {
		$html = Templates::merge('Hello {{not_a_tag}}', array('site_title' => 'InterSoccer'));
		$this->assertSame('Hello {{not_a_tag}}', $html);
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
		$this->assertSame('InterSoccer', $tags['site_title']);
	}

	public function test_replace_chrome_placeholders_handles_single_and_double_braces() {
		$html = 'Footer {{site_title}} and {site_title}.';
		$out = Templates::replace_chrome_placeholders($html);
		$this->assertSame('Footer InterSoccer and InterSoccer.', $out);
		$this->assertStringNotContainsString('{site_title}', $out);
		$this->assertStringNotContainsString('{{site_title}}', $out);
	}

	public function test_empty_blocks_to_br_expands_outlook_spacers_only() {
		$html = "<div>Dear Parents,</div>\n<div data-ogsc=\"black\"></div>\n<div>Give your child...</div>";
		$out = Templates::empty_blocks_to_br($html);
		$this->assertSame("<div>Dear Parents,</div>\n<br />\n<div>Give your child...</div>", $out);

		$consecutive = Templates::empty_blocks_to_br("<div>A</div>\n<div>B</div>");
		$this->assertSame("<div>A</div>\n<div>B</div>", $consecutive);

		$list = Templates::empty_blocks_to_br("<div>What's included?</div>\n<ul><li>One</li>\n<li>Two</li></ul>");
		$this->assertSame("<div>What's included?</div>\n<ul><li>One</li>\n<li>Two</li></ul>", $list);
	}

	public function test_newlines_in_text_to_br_converts_text_not_inter_tag_gaps() {
		$plain = Templates::newlines_in_text_to_br("Hello\r\nWorld");
		$this->assertSame("Hello<br />\nWorld", $plain);

		$html = Templates::newlines_in_text_to_br("<div>Hi</div>\n<div>There</div>");
		$this->assertSame("<div>Hi</div>\n<div>There</div>", $html);

		$mixed = Templates::newlines_in_text_to_br("<img src=\"https://example.test/b.png\" alt=\"b\" />\nBest wishes,\nThe Team");
		$this->assertStringContainsString('<br />', $mixed);
		$this->assertStringContainsString('Best wishes,<br />', $mixed);
	}
}
