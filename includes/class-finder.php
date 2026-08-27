<?php
/**
 * Upcoming calendar-birthday finder.
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Date math plus chunked scan of Player Management usermeta.
 */
class Finder {

	const CHUNK_SIZE = 200;
	const OPT_OUT_META = 'intersoccer_birthday_emails_opt_out';

	/**
	 * Parse a player DOB string. Empty / N/A / invalid → null.
	 *
	 * Prefers Y-m-d, then Swiss day-first d/m/Y, then m/d/Y.
	 *
	 * @param string             $raw Raw DOB.
	 * @param \DateTimeZone|null $tz  Timezone.
	 * @return \DateTimeImmutable|null
	 */
	public static function parse_dob($raw, \DateTimeZone $tz = null) {
		$raw = trim((string) $raw);
		if ($raw === '' || strcasecmp($raw, 'N/A') === 0) {
			return null;
		}
		$tz = $tz ?: Settings::timezone();
		$formats = array('Y-m-d', 'd/m/Y', 'm/d/Y', 'Y-m-d H:i:s');
		foreach ($formats as $format) {
			$parsed = \DateTimeImmutable::createFromFormat('!' . $format, $raw, $tz);
			if (!$parsed instanceof \DateTimeImmutable) {
				continue;
			}
			$errors = \DateTimeImmutable::getLastErrors();
			if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
				continue;
			}
			if ($format === 'Y-m-d H:i:s') {
				return $parsed->setTime(0, 0, 0);
			}
			if ($parsed->format($format) === $raw) {
				return $parsed->setTime(0, 0, 0);
			}
		}
		return null;
	}

	/**
	 * Next birthday occurrence on or after $now's date (Zurich).
	 * 29 Feb in a non-leap year is treated as 28 Feb.
	 *
	 * @param \DateTimeImmutable $dob Birth date.
	 * @param \DateTimeImmutable $now Today.
	 * @return \DateTimeImmutable
	 */
	public static function next_occurrence(\DateTimeImmutable $dob, \DateTimeImmutable $now) {
		$tz = $now->getTimezone() ?: Settings::timezone();
		$today = $now->setTime(0, 0, 0);
		$month = (int) $dob->format('n');
		$day = (int) $dob->format('j');
		$year = (int) $today->format('Y');
		$candidate = self::occurrence_for_year($month, $day, $year, $tz);
		if ($candidate < $today) {
			$candidate = self::occurrence_for_year($month, $day, $year + 1, $tz);
		}
		return $candidate;
	}

	/**
	 * @param int            $month Calendar month.
	 * @param int            $day   Calendar day.
	 * @param int            $year  Year.
	 * @param \DateTimeZone  $tz    Timezone.
	 * @return \DateTimeImmutable
	 */
	public static function occurrence_for_year($month, $day, $year, \DateTimeZone $tz) {
		if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
			$day = 28;
		}
		return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), $tz);
	}

	/**
	 * Whole days from $now's date to $occurrence's date.
	 *
	 * @param \DateTimeImmutable $occurrence Next birthday.
	 * @param \DateTimeImmutable $now        Today.
	 * @return int
	 */
	public static function days_until(\DateTimeImmutable $occurrence, \DateTimeImmutable $now) {
		$start = $now->setTime(0, 0, 0);
		$end = $occurrence->setTime(0, 0, 0);
		return (int) $start->diff($end)->format('%r%a');
	}

	/**
	 * Age the child will turn on the occurrence date.
	 *
	 * @param \DateTimeImmutable $dob        Birth date.
	 * @param \DateTimeImmutable $occurrence Next birthday date.
	 * @return int
	 */
	public static function age_turning(\DateTimeImmutable $dob, \DateTimeImmutable $occurrence) {
		return (int) $occurrence->format('Y') - (int) $dob->format('Y');
	}

	/**
	 * Evaluate one player row (no WP I/O).
	 *
	 * @param array<string, mixed> $player     Player row.
	 * @param int                  $user_id    Guardian user ID.
	 * @param \DateTimeImmutable   $now        Today in Zurich.
	 * @param int                  $look_ahead Max days for upcoming (inclusive).
	 * @param int|null             $exact_lead If set, require days_until === this.
	 * @return array<string, mixed>|null
	 */
	public static function evaluate_player(array $player, $user_id, \DateTimeImmutable $now, $look_ahead, $exact_lead = null) {
		$player_id = isset($player['player_id']) ? sanitize_text_field((string) $player['player_id']) : '';
		if ($player_id === '') {
			return null;
		}
		$tz = $now->getTimezone() ?: Settings::timezone();
		$dob = self::parse_dob(isset($player['dob']) ? (string) $player['dob'] : '', $tz instanceof \DateTimeZone ? $tz : Settings::timezone());
		if (!$dob) {
			return null;
		}
		$occurrence = self::next_occurrence($dob, $now);
		$days = self::days_until($occurrence, $now);
		if ($exact_lead !== null) {
			if ($days !== (int) $exact_lead) {
				return null;
			}
		} elseif ($days < 0 || $days > (int) $look_ahead) {
			return null;
		}
		return array(
			'player_id'        => $player_id,
			'user_id'          => (int) $user_id,
			'first_name'       => isset($player['first_name']) ? (string) $player['first_name'] : '',
			'last_name'        => isset($player['last_name']) ? (string) $player['last_name'] : '',
			'days_until'       => $days,
			'occurrence'       => $occurrence->format('Y-m-d'),
			'occurrence_year'  => (int) $occurrence->format('Y'),
			'age_turning'      => self::age_turning($dob, $occurrence),
		);
	}

	/**
	 * Scan guardians with players. Opted-out guardians stay in the list.
	 *
	 * @param \DateTimeImmutable $now        Today.
	 * @param int                $look_ahead Upcoming window.
	 * @param int|null           $exact_lead Auto-send exact match.
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan(\DateTimeImmutable $now, $look_ahead, $exact_lead = null) {
		$results = array();
		$offset = 0;
		while (true) {
			$users = self::users_chunk(self::CHUNK_SIZE, $offset);
			if (empty($users)) {
				break;
			}
			foreach ($users as $user) {
				$user_id = (int) $user->ID;
				$opted_out = self::is_opted_out($user_id);
				$players = self::load_players($user_id);
				foreach ($players as $player) {
					if (!is_array($player)) {
						continue;
					}
					$row = self::evaluate_player($player, $user_id, $now, $look_ahead, $exact_lead);
					if ($row === null) {
						continue;
					}
					$row['user_email'] = self::guardian_email($user);
					$row['guardian_first_name'] = self::guardian_first_name($user_id);
					$row['guardian_last_name'] = self::guardian_last_name($user_id);
					$row['guardian_name'] = self::guardian_display_name($user_id, $user);
					$row['opted_out'] = $opted_out;
					$results[] = $row;
				}
			}
			$offset += self::CHUNK_SIZE;
			if (count($users) < self::CHUNK_SIZE) {
				break;
			}
		}
		usort(
			$results,
			static function ($a, $b) {
				if ($a['days_until'] === $b['days_until']) {
					return strcasecmp($a['last_name'], $b['last_name']);
				}
				return $a['days_until'] <=> $b['days_until'];
			}
		);
		return $results;
	}

	/**
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_opted_out($user_id) {
		$flag = get_user_meta((int) $user_id, self::OPT_OUT_META, true);
		return $flag === '1' || $flag === 1 || $flag === true;
	}

	/**
	 * @param int $number Page size.
	 * @param int $offset Offset.
	 * @return array<int, \WP_User>
	 */
	private static function users_chunk($number, $offset) {
		return get_users(
			array(
				'role__in'   => array('customer', 'subscriber'),
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'number'     => (int) $number,
				'offset'     => (int) $offset,
				'fields'     => array('ID', 'user_email', 'display_name'),
				'meta_query' => array(
					array(
						'key'     => 'intersoccer_players',
						'compare' => 'EXISTS',
					),
				),
			)
		);
	}

	/**
	 * @param int $user_id User ID.
	 * @return array<int|string, mixed>
	 */
	private static function load_players($user_id) {
		if (function_exists('intersoccer_get_user_players')) {
			$players = intersoccer_get_user_players((int) $user_id);
			return is_array($players) ? $players : array();
		}
		$players = get_user_meta((int) $user_id, 'intersoccer_players', true);
		if (is_string($players)) {
			$players = maybe_unserialize($players);
		}
		return is_array($players) ? $players : array();
	}

	/**
	 * @param \WP_User $user User.
	 * @return string
	 */
	private static function guardian_email($user) {
		$email = isset($user->user_email) ? (string) $user->user_email : '';
		if (is_email($email)) {
			return $email;
		}
		$billing = get_user_meta((int) $user->ID, 'billing_email', true);
		return is_email($billing) ? (string) $billing : $email;
	}

	/**
	 * Filter upcoming rows by parent search and minimum notice days.
	 *
	 * @param array<int, array<string, mixed>> $rows     Scan results.
	 * @param string                           $search   Parent/player needle.
	 * @param int                              $min_days Hide rows with days_until below this.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter_upcoming_rows(array $rows, $search, $min_days) {
		$search = strtolower(trim((string) $search));
		$min_days = max(0, (int) $min_days);
		$out = array();
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			if ((int) ($row['days_until'] ?? 0) < $min_days) {
				continue;
			}
			if ($search !== '' && !self::row_matches_search($row, $search)) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $row    Upcoming row.
	 * @param string               $search Lowercased needle.
	 * @return bool
	 */
	public static function row_matches_search(array $row, $search) {
		$hay = strtolower(
			trim(
				implode(
					' ',
					array(
						isset($row['guardian_name']) ? (string) $row['guardian_name'] : '',
						isset($row['guardian_first_name']) ? (string) $row['guardian_first_name'] : '',
						isset($row['guardian_last_name']) ? (string) $row['guardian_last_name'] : '',
						isset($row['user_email']) ? (string) $row['user_email'] : '',
						isset($row['first_name']) ? (string) $row['first_name'] : '',
						isset($row['last_name']) ? (string) $row['last_name'] : '',
					)
				)
			)
		);
		return $hay !== '' && strpos($hay, $search) !== false;
	}

	/**
	 * @param int      $user_id User ID.
	 * @param \WP_User $user    User.
	 * @return string
	 */
	public static function guardian_display_name($user_id, $user) {
		$full = trim(self::guardian_first_name($user_id) . ' ' . self::guardian_last_name($user_id));
		if ($full !== '') {
			return $full;
		}
		return isset($user->display_name) ? (string) $user->display_name : '';
	}

	/**
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function guardian_first_name($user_id) {
		return self::user_meta_name((int) $user_id, 'billing_first_name', 'first_name');
	}

	/**
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function guardian_last_name($user_id) {
		return self::user_meta_name((int) $user_id, 'billing_last_name', 'last_name');
	}

	/**
	 * @param int    $user_id     User ID.
	 * @param string $billing_key Billing usermeta.
	 * @param string $user_key    WP usermeta.
	 * @return string
	 */
	private static function user_meta_name($user_id, $billing_key, $user_key) {
		$billing = get_user_meta($user_id, $billing_key, true);
		if (is_string($billing) && $billing !== '') {
			return $billing;
		}
		$user_meta = get_user_meta($user_id, $user_key, true);
		return is_string($user_meta) ? $user_meta : '';
	}
}
