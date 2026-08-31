<?php
/**
 * CSV import of guardian birthday-email opt-outs.
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Parse marketing Name/Email CSV and match existing WP users.
 */
class OptOutImport {

	const TRANSIENT_PREFIX = 'intersoccer_pb_opt_import_';
	const TRANSIENT_TTL = 3600;

	/**
	 * @param string $csv Raw CSV (UTF-8).
	 * @return array{ok:bool,error:string,rows:array<int, array{email:string,name:string}>}
	 */
	public static function parse_csv($csv) {
		$csv = (string) $csv;
		if (strpos($csv, "\xEF\xBB\xBF") === 0) {
			$csv = substr($csv, 3);
		}
		$csv = str_replace(array("\r\n", "\r"), "\n", $csv);
		$lines = preg_split("/\n/", $csv) ?: array();
		$header = array();
		$email_i = -1;
		$name_i = -1;
		$rows = array();
		$seen = array();
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '') {
				continue;
			}
			$cells = str_getcsv($line);
			if ($header === array()) {
				foreach ($cells as $i => $cell) {
					$key = self::normalize_header((string) $cell);
					$header[ $i ] = $key;
					if ($email_i < 0 && self::is_email_header($key)) {
						$email_i = (int) $i;
					}
					if ($name_i < 0 && self::is_name_header($key)) {
						$name_i = (int) $i;
					}
				}
				if ($email_i < 0) {
					return array(
						'ok'    => false,
						'error' => 'missing_email_column',
						'rows'  => array(),
					);
				}
				continue;
			}
			$email = isset($cells[ $email_i ]) ? strtolower(sanitize_email((string) $cells[ $email_i ])) : '';
			if ($email === '' || !is_email($email)) {
				continue;
			}
			if (isset($seen[ $email ])) {
				continue;
			}
			$seen[ $email ] = true;
			$name = '';
			if ($name_i >= 0 && isset($cells[ $name_i ])) {
				$name = sanitize_text_field((string) $cells[ $name_i ]);
			}
			$rows[] = array(
				'email' => $email,
				'name'  => $name,
			);
		}
		return array(
			'ok'    => true,
			'error' => '',
			'rows'  => $rows,
		);
	}

	/**
	 * @param array<int, array{email:string,name?:string}> $rows Parsed rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function classify_rows(array $rows) {
		$out = array();
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$email = isset($row['email']) ? strtolower(sanitize_email((string) $row['email'])) : '';
			$name = isset($row['name']) ? sanitize_text_field((string) $row['name']) : '';
			$classified = array(
				'email'          => $email,
				'name'           => $name,
				'status'         => 'unmatched',
				'user_id'        => 0,
				'user_label'     => '',
				'name_mismatch'  => false,
			);
			if ($email === '' || !is_email($email)) {
				$out[] = $classified;
				continue;
			}
			$users = self::find_users_for_email($email);
			if (count($users) > 1) {
				$classified['status'] = 'ambiguous';
				$out[] = $classified;
				continue;
			}
			if (count($users) < 1) {
				$out[] = $classified;
				continue;
			}
			$user = $users[0];
			$user_id = isset($user->ID) ? (int) $user->ID : 0;
			$classified['user_id'] = $user_id;
			$classified['user_label'] = self::user_label($user);
			if ($user_id > 0 && Finder::is_opted_out($user_id)) {
				$classified['status'] = 'already';
			} else {
				$classified['status'] = 'matched';
			}
			if ($name !== '' && !self::name_loosely_matches($name, $user)) {
				$classified['name_mismatch'] = true;
			}
			$out[] = $classified;
		}
		return $out;
	}

	/**
	 * @param array<int, int> $user_ids User IDs.
	 * @return int Applied count.
	 */
	public static function apply(array $user_ids) {
		$n = 0;
		$seen = array();
		foreach ($user_ids as $user_id) {
			$user_id = (int) $user_id;
			if ($user_id < 1 || isset($seen[ $user_id ])) {
				continue;
			}
			$seen[ $user_id ] = true;
			OptOut::set($user_id);
			$n++;
		}
		return $n;
	}

	/**
	 * User IDs eligible to apply (matched, including name mismatch).
	 *
	 * @param array<int, array<string, mixed>> $classified Classified rows.
	 * @return array<int, int>
	 */
	public static function apply_ids(array $classified) {
		$ids = array();
		foreach ($classified as $row) {
			if (!is_array($row)) {
				continue;
			}
			if (($row['status'] ?? '') !== 'matched') {
				continue;
			}
			$id = isset($row['user_id']) ? (int) $row['user_id'] : 0;
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Guardians with birthday-email opt-out (Name, Email).
	 *
	 * @return array<int, array{name:string,email:string}>
	 */
	public static function list_opted_out() {
		if (!function_exists('get_users')) {
			return array();
		}
		$users = get_users(
			array(
				'number'      => -1,
				'meta_key'    => Finder::OPT_OUT_META,
				'meta_value'  => '1',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'count_total' => false,
			)
		);
		if (!is_array($users)) {
			return array();
		}
		$rows = array();
		foreach ($users as $user) {
			if (!is_object($user)) {
				continue;
			}
			$email = isset($user->user_email) ? sanitize_email((string) $user->user_email) : '';
			if ($email === '' || !is_email($email)) {
				continue;
			}
			$id = isset($user->ID) ? (int) $user->ID : 0;
			$billing = '';
			if ($id > 0) {
				$billing = trim(
					(string) get_user_meta($id, 'billing_first_name', true) . ' ' .
					(string) get_user_meta($id, 'billing_last_name', true)
				);
			}
			$name = $billing !== '' ? $billing : (isset($user->display_name) ? trim((string) $user->display_name) : '');
			$rows[] = array(
				'name'  => $name,
				'email' => $email,
			);
		}
		return $rows;
	}

	/**
	 * @return string
	 */
	public static function transient_key() {
		return self::TRANSIENT_PREFIX . (int) get_current_user_id();
	}

	/**
	 * @param string $header Normalized header.
	 * @return bool
	 */
	private static function is_email_header($header) {
		return in_array($header, array('email', 'e-mail', 'email address', 'e mail'), true);
	}

	/**
	 * @param string $header Normalized header.
	 * @return bool
	 */
	private static function is_name_header($header) {
		return in_array($header, array('name', 'full name', 'parent name', 'parent'), true);
	}

	/**
	 * @param string $header Raw header.
	 * @return string
	 */
	private static function normalize_header($header) {
		$header = strtolower(trim($header));
		$header = preg_replace('/\s+/', ' ', $header);
		return is_string($header) ? $header : '';
	}

	/**
	 * @param string $email Email.
	 * @return array<int, object>
	 */
	private static function find_users_for_email($email) {
		if (function_exists('get_user_by')) {
			$by_login = get_user_by('email', $email);
			if ($by_login) {
				return array($by_login);
			}
		}
		if (!function_exists('get_users')) {
			return array();
		}
		$found = get_users(
			array(
				'number'     => 3,
				'meta_query' => array(
					array(
						'key'   => 'billing_email',
						'value' => $email,
					),
				),
			)
		);
		return is_array($found) ? array_values($found) : array();
	}

	/**
	 * @param object $user User.
	 * @return string
	 */
	private static function user_label($user) {
		$label = isset($user->display_name) ? trim((string) $user->display_name) : '';
		if ($label !== '') {
			return $label;
		}
		return isset($user->user_email) ? (string) $user->user_email : '';
	}

	/**
	 * @param string $csv_name Spreadsheet name.
	 * @param object $user     User.
	 * @return bool
	 */
	private static function name_loosely_matches($csv_name, $user) {
		$needle = self::normalize_name($csv_name);
		if ($needle === '') {
			return true;
		}
		$hay = self::normalize_name(self::user_label($user));
		$id = isset($user->ID) ? (int) $user->ID : 0;
		if ($id > 0) {
			$hay .= ' ' . self::normalize_name(
				trim(
					(string) get_user_meta($id, 'billing_first_name', true) . ' ' .
					(string) get_user_meta($id, 'billing_last_name', true)
				)
			);
			$hay .= ' ' . self::normalize_name(
				trim(
					(string) get_user_meta($id, 'first_name', true) . ' ' .
					(string) get_user_meta($id, 'last_name', true)
				)
			);
		}
		$hay = self::normalize_name($hay);
		if ($hay === '') {
			return false;
		}
		return strpos($hay, $needle) !== false || strpos($needle, $hay) !== false;
	}

	/**
	 * @param string $name Name.
	 * @return string
	 */
	private static function normalize_name($name) {
		$name = strtolower(trim((string) $name));
		$name = preg_replace('/\s+/', ' ', $name);
		return is_string($name) ? $name : '';
	}
}
