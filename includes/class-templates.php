<?php
/**
 * EN/FR/DE greeting templates and merge tags.
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Subject/body per language plus merge replacement.
 */
class Templates {

	const OPTION_KEY = 'intersoccer_player_birthdays_templates';
	const LANGS = array('en', 'fr', 'de');

	/**
	 * Default greeting copy (no promo).
	 *
	 * @return array<string, array{subject:string,body:string}>
	 */
	public static function defaults() {
		return array(
			'en' => array(
				'subject' => 'Happy birthday to {{player_first_name}}!',
				'body'    => "<p>Hi {{guardian_first_name}},</p>\n<p>We wanted to wish <strong>{{player_first_name}} {{player_last_name}}</strong> a happy birthday. They turn {{age_turning}} on {{birthday_date}}.</p>\n<p>Best wishes,<br>The InterSoccer Team</p>\n<p><small><a href=\"{{opt_out_url}}\">Unsubscribe from birthday greetings</a></small></p>",
			),
			'fr' => array(
				'subject' => 'Joyeux anniversaire à {{player_first_name}} !',
				'body'    => "<p>Bonjour {{guardian_first_name}},</p>\n<p>Nous souhaitons un joyeux anniversaire à <strong>{{player_first_name}} {{player_last_name}}</strong>, qui fête ses {{age_turning}} ans le {{birthday_date}}.</p>\n<p>Meilleures salutations,<br>L'équipe InterSoccer</p>\n<p><small><a href=\"{{opt_out_url}}\">Se désinscrire des vœux d'anniversaire</a></small></p>",
			),
			'de' => array(
				'subject' => 'Alles Gute zum Geburtstag, {{player_first_name}}!',
				'body'    => "<p>Hallo {{guardian_first_name}},</p>\n<p>Wir möchten <strong>{{player_first_name}} {{player_last_name}}</strong> herzlich zum Geburtstag gratulieren. Am {{birthday_date}} wird sie/er {{age_turning}} Jahre alt.</p>\n<p>Herzliche Grüsse,<br>Das InterSoccer-Team</p>\n<p><small><a href=\"{{opt_out_url}}\">Geburtstagswünsche abbestellen</a></small></p>",
			),
		);
	}

	/**
	 * @return array<string, array{subject:string,body:string}>
	 */
	public static function get() {
		$stored = get_option(self::OPTION_KEY, array());
		if (!is_array($stored)) {
			$stored = array();
		}
		$out = self::defaults();
		foreach (self::LANGS as $lang) {
			if (!isset($stored[ $lang ]) || !is_array($stored[ $lang ])) {
				continue;
			}
			if (isset($stored[ $lang ]['subject'])) {
				$out[ $lang ]['subject'] = (string) $stored[ $lang ]['subject'];
			}
			if (isset($stored[ $lang ]['body'])) {
				$out[ $lang ]['body'] = (string) $stored[ $lang ]['body'];
			}
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $input Posted templates.
	 * @return array<string, array{subject:string,body:string}>
	 */
	public static function update(array $input) {
		$clean = self::defaults();
		foreach (self::LANGS as $lang) {
			if (!isset($input[ $lang ]) || !is_array($input[ $lang ])) {
				continue;
			}
			if (isset($input[ $lang ]['subject'])) {
				$clean[ $lang ]['subject'] = sanitize_text_field((string) $input[ $lang ]['subject']);
			}
			if (isset($input[ $lang ]['body'])) {
				$clean[ $lang ]['body'] = wp_kses_post((string) $input[ $lang ]['body']);
			}
		}
		update_option(self::OPTION_KEY, $clean);
		return $clean;
	}

	/**
	 * Map WPML / locale to en|fr|de.
	 *
	 * @param int $user_id Guardian ID.
	 * @return string
	 */
	public static function language_for_user($user_id) {
		$lang = '';
		if (has_filter('wpml_user_language')) {
			$lang = (string) apply_filters('wpml_user_language', null, (int) $user_id);
		}
		if ($lang === '' && function_exists('get_user_locale')) {
			$lang = (string) get_user_locale((int) $user_id);
		}
		$lang = strtolower($lang);
		if (strpos($lang, 'fr') === 0) {
			return 'fr';
		}
		if (strpos($lang, 'de') === 0) {
			return 'de';
		}
		return 'en';
	}

	/**
	 * Replace merge tags. Unknown tags are left as-is only if not in the map;
	 * known empty values become empty strings.
	 *
	 * @param string               $text Template.
	 * @param array<string, string> $tags Tag map without braces.
	 * @return string
	 */
	public static function merge($text, array $tags) {
		$search = array();
		$replace = array();
		foreach ($tags as $key => $value) {
			$search[] = '{{' . $key . '}}';
			$replace[] = (string) $value;
		}
		return str_replace($search, $replace, (string) $text);
	}

	/**
	 * Build tag map from a finder candidate.
	 *
	 * @param array<string, mixed> $candidate Finder row.
	 * @param string               $opt_out_url Opt-out URL.
	 * @return array<string, string>
	 */
	public static function tags_from_candidate(array $candidate, $opt_out_url) {
		$date = isset($candidate['occurrence']) ? (string) $candidate['occurrence'] : '';
		if ($date !== '' && function_exists('date_i18n')) {
			$ts = strtotime($date . ' 12:00:00');
			if ($ts) {
				$date = date_i18n(get_option('date_format', 'j F Y'), $ts);
			}
		}
		return array(
			'player_first_name'   => isset($candidate['first_name']) ? (string) $candidate['first_name'] : '',
			'player_last_name'    => isset($candidate['last_name']) ? (string) $candidate['last_name'] : '',
			'guardian_first_name' => isset($candidate['guardian_first_name']) ? (string) $candidate['guardian_first_name'] : '',
			'age_turning'         => isset($candidate['age_turning']) ? (string) (int) $candidate['age_turning'] : '',
			'birthday_date'       => $date,
			'opt_out_url'         => (string) $opt_out_url,
			'site_title'          => self::site_title(),
		);
	}

	/**
	 * WordPress site title (blogname).
	 *
	 * @return string
	 */
	public static function site_title() {
		if (function_exists('wp_specialchars_decode')) {
			return wp_specialchars_decode((string) get_option('blogname', ''), ENT_QUOTES);
		}
		return function_exists('get_bloginfo') ? (string) get_bloginfo('name') : '';
	}

	/**
	 * Replace WooCommerce footer / greeting site-title placeholders.
	 *
	 * Double-brace {{site_title}} is replaced first so it does not become {Title}.
	 *
	 * @param string $html Wrapped or template HTML.
	 * @return string
	 */
	public static function replace_chrome_placeholders($html) {
		$title = self::site_title();
		$out = str_replace(
			array('{{site_title}}', '{site_title}'),
			$title,
			(string) $html
		);
		return $out;
	}

	/**
	 * Turn empty Outlook/TinyMCE spacer blocks into a visible line break.
	 *
	 * Copied HTML often uses empty &lt;div&gt;&lt;/div&gt; for paragraph gaps.
	 * Those collapse to zero height in WooCommerce email CSS.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	public static function empty_blocks_to_br($html) {
		return (string) preg_replace('/<(div|p)(\s[^>]*)?>\s*<\/\1>/i', '<br />', (string) $html);
	}

	/**
	 * Turn CRLF/LF inside text nodes into &lt;br /&gt;.
	 *
	 * Pretty-printed HTML (newlines between tags) is left unchanged so layout
	 * spacing comes from the markup, not extra breaks.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	public static function newlines_in_text_to_br($html) {
		$html = str_replace(array("\r\n", "\r"), "\n", (string) $html);
		$parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (!is_array($parts)) {
			return $html;
		}
		$out = '';
		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}
			if (isset($part[0]) && $part[0] === '<') {
				$out .= $part;
				continue;
			}
			if (trim($part) === '') {
				$out .= $part;
				continue;
			}
			$out .= str_replace("\n", "<br />\n", $part);
		}
		return $out;
	}
}
