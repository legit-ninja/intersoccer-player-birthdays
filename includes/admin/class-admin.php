<?php
/**
 * Admin UI and AJAX.
 *
 * @package InterSoccer_Player_Birthdays
 */

namespace InterSoccer\PlayerBirthdays;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Player Birthdays screens and privileged AJAX.
 */
class Admin {

	const PAGE_SLUG = 'intersoccer-player-birthdays';
	const NONCE_ACTION = 'intersoccer_player_birthdays';
	const CAPABILITY = 'manage_options';

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @param Logger $logger Send log.
	 */
	public function __construct(Logger $logger) {
		$this->logger = $logger;
	}

	/**
	 * @return void
	 */
	public function register() {
		add_action('admin_menu', array($this, 'register_menu'));
		add_action('admin_init', array($this, 'maybe_export'), 5);
		add_action('admin_init', array($this, 'handle_posts'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue'));
		add_action('wp_ajax_intersoccer_pb_test_send', array($this, 'ajax_test_send'));
		add_action('wp_ajax_intersoccer_pb_manual_send', array($this, 'ajax_manual_send'));
		add_action('wp_ajax_intersoccer_pb_opt_out', array($this, 'ajax_opt_out'));
		add_action('wp_ajax_intersoccer_pb_opt_in', array($this, 'ajax_opt_in'));
		add_action('wp_ajax_intersoccer_pb_set_opt_out', array($this, 'ajax_set_opt_out'));
	}

	/**
	 * Verify nonce + capability. Returns false when $die is false.
	 *
	 * @param bool $die Whether to emit JSON and exit on failure.
	 * @return bool
	 */
	public static function verify_request($die = true) {
		$ok = check_ajax_referer(self::NONCE_ACTION, 'nonce', $die);
		if (!$ok) {
			return false;
		}
		if (!current_user_can(self::CAPABILITY)) {
			if ($die) {
				wp_send_json_error(
					array('message' => __('Insufficient permissions.', 'intersoccer-player-birthdays')),
					403
				);
			}
			return false;
		}
		return true;
	}

	/**
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__('Player Birthdays', 'intersoccer-player-birthdays'),
			__('Player Birthdays', 'intersoccer-player-birthdays'),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array($this, 'render_page'),
			'dashicons-buddicons-pm',
			31
		);
	}

	/**
	 * @param string $hook Current admin hook.
	 * @return void
	 */
	public function enqueue($hook) {
		if (strpos((string) $hook, self::PAGE_SLUG) === false) {
			return;
		}
		wp_enqueue_style(
			'intersoccer-player-birthdays-admin',
			INTERSOCCER_PLAYER_BIRTHDAYS_URL . 'assets/css/admin.css',
			array(),
			INTERSOCCER_PLAYER_BIRTHDAYS_VERSION
		);
		wp_enqueue_script(
			'intersoccer-player-birthdays-admin',
			INTERSOCCER_PLAYER_BIRTHDAYS_URL . 'assets/js/admin.js',
			array('jquery'),
			INTERSOCCER_PLAYER_BIRTHDAYS_VERSION,
			true
		);
		wp_localize_script(
			'intersoccer-player-birthdays-admin',
			'intersoccerPlayerBirthdays',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce(self::NONCE_ACTION),
				'i18n'    => array(
					'optedOut'     => __('Opted out', 'intersoccer-player-birthdays'),
					'sentThisYear' => __('Sent this year', 'intersoccer-player-birthdays'),
					'notSent'      => __('Not sent', 'intersoccer-player-birthdays'),
				),
			)
		);
	}

	/**
	 * Settings / templates POST.
	 *
	 * @return void
	 */
	public function handle_posts() {
		if (!isset($_POST['intersoccer_pb_action']) || !current_user_can(self::CAPABILITY)) {
			return;
		}
		check_admin_referer(self::NONCE_ACTION);
		$action = sanitize_key(wp_unslash($_POST['intersoccer_pb_action']));
		if ($action === 'save_settings') {
			Settings::update(isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : array());
			wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'tab' => 'settings', 'updated' => '1'), admin_url('admin.php')));
			exit;
		}
		if ($action === 'save_templates') {
			Templates::update(isset($_POST['templates']) && is_array($_POST['templates']) ? wp_unslash($_POST['templates']) : array());
			wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'tab' => 'templates', 'updated' => '1'), admin_url('admin.php')));
			exit;
		}
		if ($action === 'import_opt_out_preview') {
			$this->handle_opt_out_preview();
		}
		if ($action === 'import_opt_out_apply') {
			$this->handle_opt_out_apply();
		}
	}

	/**
	 * Stream opted-out guardians as CSV (no admin chrome).
	 *
	 * @return void
	 */
	public function maybe_export() {
		if (!isset($_GET['page'], $_GET['intersoccer_pb_action'])) {
			return;
		}
		if (sanitize_key(wp_unslash($_GET['page'])) !== self::PAGE_SLUG) {
			return;
		}
		if (sanitize_key(wp_unslash($_GET['intersoccer_pb_action'])) !== 'export_opt_out') {
			return;
		}
		if (!current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-player-birthdays'));
		}
		$nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
		if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			wp_die(esc_html__('Invalid export link. Open Opt out and try again.', 'intersoccer-player-birthdays'));
		}
		if (function_exists('nocache_headers')) {
			nocache_headers();
		}
		$filename = 'intersoccer-birthday-opt-outs.csv';
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		$out = fopen('php://output', 'w');
		if ($out === false) {
			exit;
		}
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, array('Name', 'Email'));
		foreach (OptOutImport::list_opted_out() as $row) {
			fputcsv($out, array($row['name'], $row['email']));
		}
		fclose($out);
		exit;
	}

	/**
	 * @return void
	 */
	public function render_page() {
		if (!current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'intersoccer-player-birthdays'));
		}
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'upcoming';
		if ($tab === 'import') {
			$tab = 'opt-out';
		}
		if (!in_array($tab, array('upcoming', 'templates', 'settings', 'log', 'opt-out'), true)) {
			$tab = 'upcoming';
		}
		echo '<div class="wrap intersoccer-player-birthdays">';
		echo '<h1>' . esc_html__('Player Birthdays', 'intersoccer-player-birthdays') . '</h1>';
		echo '<p class="description">' . esc_html__('Calendar birthdays of registered players — not birthday-party product bookings.', 'intersoccer-player-birthdays') . '</p>';
		if (isset($_GET['updated'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Saved.', 'intersoccer-player-birthdays') . '</p></div>';
		}
		$this->render_tabs($tab);
		if ($tab === 'templates') {
			$this->render_templates();
		} elseif ($tab === 'settings') {
			$this->render_settings();
		} elseif ($tab === 'log') {
			$this->render_log();
		} elseif ($tab === 'opt-out') {
			$this->render_import();
		} else {
			$this->render_upcoming();
		}
		echo '</div>';
	}

	/**
	 * @param string $current Current tab.
	 * @return void
	 */
	private function render_tabs($current) {
		$tabs = array(
			'upcoming'  => __('Upcoming', 'intersoccer-player-birthdays'),
			'opt-out'   => __('Opt out', 'intersoccer-player-birthdays'),
			'templates' => __('Templates', 'intersoccer-player-birthdays'),
			'settings'  => __('Settings', 'intersoccer-player-birthdays'),
			'log'       => __('Log', 'intersoccer-player-birthdays'),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ($tabs as $slug => $label) {
			$url = add_query_arg(array('page' => self::PAGE_SLUG, 'tab' => $slug), admin_url('admin.php'));
			$class = $slug === $current ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * @param array<string, string> $args Query args.
	 * @return void
	 */
	private function redirect_import(array $args) {
		$args = array_merge(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => 'opt-out',
			),
			$args
		);
		wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
		exit;
	}

	/**
	 * @return void
	 */
	private function handle_opt_out_preview() {
		$file = isset($_FILES['opt_out_csv']) && is_array($_FILES['opt_out_csv']) ? $_FILES['opt_out_csv'] : array();
		$tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
		$error = isset($file['error']) ? (int) $file['error'] : 4;
		if ($error !== 0 || $tmp === '' || !is_uploaded_file($tmp) || !is_readable($tmp)) {
			$this->redirect_import(array('import_error' => 'upload'));
		}
		$raw = file_get_contents($tmp);
		if (!is_string($raw) || $raw === '') {
			$this->redirect_import(array('import_error' => 'empty'));
		}
		$parsed = OptOutImport::parse_csv($raw);
		if (empty($parsed['ok'])) {
			$this->redirect_import(array('import_error' => isset($parsed['error']) ? (string) $parsed['error'] : 'parse'));
		}
		$classified = OptOutImport::classify_rows($parsed['rows']);
		set_transient(OptOutImport::transient_key(), $classified, OptOutImport::TRANSIENT_TTL);
		$this->redirect_import(array('preview' => '1'));
	}

	/**
	 * @return void
	 */
	private function handle_opt_out_apply() {
		$classified = get_transient(OptOutImport::transient_key());
		if (!is_array($classified)) {
			$this->redirect_import(array('import_error' => 'expired'));
		}
		$n = OptOutImport::apply(OptOutImport::apply_ids($classified));
		delete_transient(OptOutImport::transient_key());
		$this->redirect_import(array('applied' => (string) $n));
	}

	/**
	 * @return void
	 */
	private function render_import() {
		if (isset($_GET['import_error'])) {
			$key = sanitize_key(wp_unslash($_GET['import_error']));
			$messages = array(
				'upload'                 => __('Could not read the uploaded file.', 'intersoccer-player-birthdays'),
				'empty'                  => __('The CSV file was empty.', 'intersoccer-player-birthdays'),
				'missing_email_column'   => __('The CSV needs an Email column. Save the spreadsheet as a UTF-8 CSV.', 'intersoccer-player-birthdays'),
				'parse'                  => __('Could not parse the CSV.', 'intersoccer-player-birthdays'),
				'expired'                => __('The preview expired. Upload the CSV again.', 'intersoccer-player-birthdays'),
			);
			$msg = isset($messages[ $key ]) ? $messages[ $key ] : $messages['parse'];
			echo '<div class="notice notice-error"><p>' . esc_html($msg) . '</p></div>';
		}
		if (isset($_GET['applied'])) {
			$n = absint($_GET['applied']);
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: number of guardians opted out */
					_n('%d guardian opted out of birthday greetings.', '%d guardians opted out of birthday greetings.', $n, 'intersoccer-player-birthdays'),
					$n
				)
			);
			echo '</p></div>';
		}

		echo '<p>' . esc_html__('This only opts guardians out of calendar birthday greeting emails — not other InterSoccer marketing.', 'intersoccer-player-birthdays') . '</p>';
		echo '<p class="description">' . esc_html__('Save the marketing spreadsheet as a UTF-8 CSV with an Email column. Name is optional.', 'intersoccer-player-birthdays') . '</p>';

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'                 => self::PAGE_SLUG,
					'intersoccer_pb_action' => 'export_opt_out',
				),
				admin_url('admin.php')
			),
			self::NONCE_ACTION
		);
		echo '<p><a class="button" href="' . esc_url($export_url) . '">' . esc_html__('Export opted-out customers', 'intersoccer-player-birthdays') . '</a></p>';

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field(self::NONCE_ACTION);
		echo '<input type="hidden" name="intersoccer_pb_action" value="import_opt_out_preview" />';
		echo '<p><input type="file" name="opt_out_csv" accept=".csv,text/csv" required /></p>';
		submit_button(__('Preview matches', 'intersoccer-player-birthdays'), 'secondary', 'submit', false);
		echo '</form>';

		$classified = array();
		if (isset($_GET['preview'])) {
			$stored = get_transient(OptOutImport::transient_key());
			if (is_array($stored)) {
				$classified = $stored;
			} else {
				echo '<div class="notice notice-warning"><p>' . esc_html__('The preview expired. Upload the CSV again.', 'intersoccer-player-birthdays') . '</p></div>';
			}
		}
		if ($classified === array()) {
			return;
		}

		$counts = array(
			'matched'    => 0,
			'already'    => 0,
			'unmatched'  => 0,
			'ambiguous'  => 0,
			'name_warn'  => 0,
		);
		foreach ($classified as $row) {
			$status = isset($row['status']) ? (string) $row['status'] : '';
			if (isset($counts[ $status ])) {
				$counts[ $status ]++;
			}
			if (!empty($row['name_mismatch'])) {
				$counts['name_warn']++;
			}
		}

		echo '<p><strong>' . esc_html__('Preview (nothing is saved until you apply)', 'intersoccer-player-birthdays') . '</strong></p>';
		echo '<ul>';
		echo '<li>' . esc_html(sprintf(/* translators: %d: count */ __('Matched: %d', 'intersoccer-player-birthdays'), $counts['matched'])) . '</li>';
		echo '<li>' . esc_html(sprintf(/* translators: %d: count */ __('Already opted out: %d', 'intersoccer-player-birthdays'), $counts['already'])) . '</li>';
		echo '<li>' . esc_html(sprintf(/* translators: %d: count */ __('Unmatched: %d', 'intersoccer-player-birthdays'), $counts['unmatched'])) . '</li>';
		echo '<li>' . esc_html(sprintf(/* translators: %d: count */ __('Ambiguous (same billing email on more than one account): %d', 'intersoccer-player-birthdays'), $counts['ambiguous'])) . '</li>';
		echo '<li>' . esc_html(sprintf(/* translators: %d: count */ __('Name does not match account: %d', 'intersoccer-player-birthdays'), $counts['name_warn'])) . '</li>';
		echo '</ul>';

		$this->render_import_table(__('Will opt out', 'intersoccer-player-birthdays'), $classified, 'matched');
		$this->render_import_table(__('Already opted out', 'intersoccer-player-birthdays'), $classified, 'already');
		$this->render_import_table(__('Not found', 'intersoccer-player-birthdays'), $classified, 'unmatched');
		$this->render_import_table(__('Ambiguous', 'intersoccer-player-birthdays'), $classified, 'ambiguous');

		if ($counts['matched'] > 0) {
			echo '<form method="post">';
			wp_nonce_field(self::NONCE_ACTION);
			echo '<input type="hidden" name="intersoccer_pb_action" value="import_opt_out_apply" />';
			submit_button(__('Apply opt-out to matched guardians', 'intersoccer-player-birthdays'));
			echo '</form>';
		}
	}

	/**
	 * @param string                           $title      Heading.
	 * @param array<int, array<string, mixed>> $classified Rows.
	 * @param string                           $status     Status key.
	 * @return void
	 */
	private function render_import_table($title, array $classified, $status) {
		$rows = array();
		foreach ($classified as $row) {
			if (is_array($row) && ($row['status'] ?? '') === $status) {
				$rows[] = $row;
			}
		}
		if ($rows === array()) {
			return;
		}
		echo '<h2>' . esc_html($title) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__('Email', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Spreadsheet name', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Account', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Note', 'intersoccer-player-birthdays') . '</th>';
		echo '</tr></thead><tbody>';
		foreach ($rows as $row) {
			$note = '';
			if (!empty($row['name_mismatch'])) {
				$note = __('Name does not match this account (email still matches).', 'intersoccer-player-birthdays');
			}
			echo '<tr>';
			echo '<td>' . esc_html(isset($row['email']) ? (string) $row['email'] : '') . '</td>';
			echo '<td>' . esc_html(isset($row['name']) ? (string) $row['name'] : '') . '</td>';
			echo '<td>' . esc_html(isset($row['user_label']) ? (string) $row['user_label'] : '') . '</td>';
			echo '<td>' . esc_html($note) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @return void
	 */
	private function render_upcoming() {
		$settings = Settings::get();
		$search = isset($_GET['pb_search']) ? sanitize_text_field(wp_unslash($_GET['pb_search'])) : '';
		$min_days = isset($_GET['pb_min_days'])
			? Settings::clamp_int($_GET['pb_min_days'], 0, Settings::WINDOW_DAYS_MAX, (int) $settings['min_notice_days'])
			: (int) $settings['min_notice_days'];
		$all_rows = Finder::scan(Settings::now(), (int) $settings['look_ahead_days'], null);
		$visible_count = count(Finder::filter_upcoming_rows($all_rows, $search, $min_days));
		echo '<p>';
		echo '<button type="button" class="button button-primary" id="intersoccer-pb-test-send">' . esc_html__('Send test email', 'intersoccer-player-birthdays') . '</button> ';
		echo '<span class="description">' . esc_html__('Sends a sample greeting to you (or the test address in Settings), never to a parent.', 'intersoccer-player-birthdays') . '</span>';
		echo '</p>';
		echo '<p id="intersoccer-pb-ajax-status" class="intersoccer-pb-status" aria-live="polite"></p>';

		echo '<form class="intersoccer-pb-filters" method="get" action="' . esc_url(admin_url('admin.php')) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '" />';
		echo '<input type="hidden" name="tab" value="upcoming" />';
		echo '<p>';
		echo '<label for="intersoccer-pb-search">' . esc_html__('Search parent', 'intersoccer-player-birthdays') . '</label> ';
		echo '<input type="search" id="intersoccer-pb-search" name="pb_search" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('Parent name or email', 'intersoccer-player-birthdays') . '" /> ';
		echo '<label for="intersoccer-pb-min-days">' . esc_html__('Hide if fewer than', 'intersoccer-player-birthdays') . '</label> ';
		echo '<input type="number" id="intersoccer-pb-min-days" name="pb_min_days" min="0" max="' . esc_attr((string) Settings::WINDOW_DAYS_MAX) . '" value="' . esc_attr((string) $min_days) . '" /> ';
		echo esc_html__('days away', 'intersoccer-player-birthdays') . ' ';
		submit_button(__('Apply', 'intersoccer-player-birthdays'), 'secondary', 'pb_filter', false);
		echo '</p>';
		echo '<p class="description">' . esc_html__('Default hide is 21 days so staff can reach parents with about three weeks of notice to book. Set to 0 to include nearer birthdays. Search matches parent name, email, and player name as you type.', 'intersoccer-player-birthdays') . '</p>';
		echo '</form>';

		if (empty($all_rows)) {
			echo '<p>' . esc_html__('No upcoming birthdays in the look-ahead window.', 'intersoccer-player-birthdays') . '</p>';
			return;
		}

		$empty_class = $visible_count > 0 ? 'intersoccer-pb-empty-hidden' : '';
		$table_style = $visible_count > 0 ? '' : ' style="display:none"';
		echo '<p id="intersoccer-pb-empty" class="' . esc_attr($empty_class) . '">' . esc_html__('No birthdays match the current search and notice filter.', 'intersoccer-player-birthdays') . '</p>';
		echo '<table class="widefat striped" id="intersoccer-pb-upcoming-table"' . $table_style . '><thead><tr>';
		echo '<th>' . esc_html__('Player', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Parent', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Email', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Birthday', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Days', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Turning', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Status', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Opt out', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Actions', 'intersoccer-player-birthdays') . '</th>';
		echo '</tr></thead><tbody>';
		foreach ($all_rows as $row) {
			$sent = $this->logger->already_sent($row['player_id'], (int) $row['occurrence_year']);
			$opted = !empty($row['opted_out']) || Finder::is_opted_out((int) $row['user_id']);
			$player_name = trim($row['first_name'] . ' ' . $row['last_name']);
			$parent_name = isset($row['guardian_name']) ? (string) $row['guardian_name'] : '';
			$search_blob = strtolower(trim($parent_name . ' ' . $row['user_email'] . ' ' . $player_name));
			$row_hidden = empty(Finder::filter_upcoming_rows(array($row), $search, $min_days));
			echo '<tr data-user-id="' . esc_attr((string) (int) $row['user_id']) . '" data-days="' . esc_attr((string) (int) $row['days_until']) . '" data-search="' . esc_attr($search_blob) . '" data-sent="' . ($sent ? '1' : '0') . '" data-opted="' . ($opted ? '1' : '0') . '"' . ($row_hidden ? ' style="display:none"' : '') . '">';
			echo '<td>' . esc_html($player_name) . '</td>';
			echo '<td>' . esc_html($parent_name) . '</td>';
			echo '<td>' . esc_html($row['user_email']) . '</td>';
			echo '<td>' . esc_html($row['occurrence']) . '</td>';
			echo '<td>' . esc_html((string) (int) $row['days_until']) . '</td>';
			echo '<td>' . esc_html((string) (int) $row['age_turning']) . '</td>';
			echo '<td class="intersoccer-pb-row-status">';
			if ($opted) {
				echo esc_html__('Opted out', 'intersoccer-player-birthdays');
			} elseif ($sent) {
				echo esc_html__('Sent this year', 'intersoccer-player-birthdays');
			} else {
				echo esc_html__('Not sent', 'intersoccer-player-birthdays');
			}
			echo '</td>';
			echo '<td>';
			printf(
				'<label class="intersoccer-pb-opt-out-wrap"><input type="checkbox" class="intersoccer-pb-opt-out" data-user-id="%d" value="1" %s /> <span class="screen-reader-text">%s</span></label>',
				(int) $row['user_id'],
				checked($opted, true, false),
				esc_html__('Opt out of birthday emails for this parent', 'intersoccer-player-birthdays')
			);
			echo '</td>';
			echo '<td>';
			if (!$sent) {
				printf(
					'<button type="button" class="button intersoccer-pb-manual-send" data-user-id="%d" data-player-id="%s"%s>%s</button>',
					(int) $row['user_id'],
					esc_attr($row['player_id']),
					$opted ? ' disabled="disabled"' : '',
					esc_html__('Send now', 'intersoccer-player-birthdays')
				);
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @return void
	 */
	private function render_templates() {
		$templates = Templates::get();
		echo '<form method="post">';
		wp_nonce_field(self::NONCE_ACTION);
		echo '<input type="hidden" name="intersoccer_pb_action" value="save_templates" />';
		echo '<p class="description">' . esc_html__('Merge tags: {{player_first_name}}, {{player_last_name}}, {{guardian_first_name}}, {{age_turning}}, {{birthday_date}}, {{opt_out_url}}, {{site_title}}', 'intersoccer-player-birthdays') . '</p>';
		echo '<p class="description">' . esc_html__('Add Media can insert a banner or signature. Use absolute https image URLs (not relative paths). For a store-wide email banner, set WooCommerce → Settings → Emails → Header image.', 'intersoccer-player-birthdays') . '</p>';
		$labels = array(
			'en' => __('English', 'intersoccer-player-birthdays'),
			'fr' => __('French', 'intersoccer-player-birthdays'),
			'de' => __('German', 'intersoccer-player-birthdays'),
		);
		foreach (Templates::LANGS as $lang) {
			echo '<h2>' . esc_html($labels[ $lang ]) . '</h2>';
			echo '<p><label>' . esc_html__('Subject', 'intersoccer-player-birthdays') . '<br />';
			echo '<input type="text" class="large-text" name="templates[' . esc_attr($lang) . '][subject]" value="' . esc_attr($templates[ $lang ]['subject']) . '" /></label></p>';
			$editor_id = 'intersoccer_pb_body_' . $lang;
			wp_editor(
				$templates[ $lang ]['body'],
				$editor_id,
				array(
					'textarea_name' => 'templates[' . $lang . '][body]',
					'textarea_rows' => 10,
					'media_buttons' => true,
				)
			);
		}
		submit_button(__('Save templates', 'intersoccer-player-birthdays'));
		echo '</form>';
	}

	/**
	 * @return void
	 */
	private function render_settings() {
		$s = Settings::get();
		echo '<form method="post">';
		wp_nonce_field(self::NONCE_ACTION);
		echo '<input type="hidden" name="intersoccer_pb_action" value="save_settings" />';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>' . esc_html__('Automated greetings', 'intersoccer-player-birthdays') . '</th><td>';
		echo '<label><input type="checkbox" name="settings[automation_enabled]" value="1" ' . checked(!empty($s['automation_enabled']), true, false) . ' /> ';
		echo esc_html__('Send parent emails automatically (off until you enable it).', 'intersoccer-player-birthdays') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Lead days', 'intersoccer-player-birthdays') . '</th><td>';
		echo '<input type="number" min="1" max="' . esc_attr((string) Settings::WINDOW_DAYS_MAX) . '" name="settings[lead_days]" value="' . esc_attr((string) (int) $s['lead_days']) . '" /> ';
		echo '<span class="description">' . esc_html(
			sprintf(
				/* translators: %d: maximum lead days */
				__('Days before the birthday to send the greeting (1–%d).', 'intersoccer-player-birthdays'),
				Settings::WINDOW_DAYS_MAX
			)
		) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__('Admin digest', 'intersoccer-player-birthdays') . '</th><td>';
		echo '<label><input type="checkbox" name="settings[digest_enabled]" value="1" ' . checked(!empty($s['digest_enabled']), true, false) . ' /> ';
		echo esc_html__('Email WordPress admin a list of upcoming birthdays.', 'intersoccer-player-birthdays') . '</label></td></tr>';
		echo '<tr><th>' . esc_html__('Digest cadence', 'intersoccer-player-birthdays') . '</th><td>';
		echo '<select name="settings[digest_cadence]">';
		echo '<option value="daily" ' . selected($s['digest_cadence'], 'daily', false) . '>' . esc_html__('Daily', 'intersoccer-player-birthdays') . '</option>';
		echo '<option value="weekly" ' . selected($s['digest_cadence'], 'weekly', false) . '>' . esc_html__('Weekly', 'intersoccer-player-birthdays') . '</option>';
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__('Look-ahead days', 'intersoccer-player-birthdays') . '</th><td>';
		echo '<input type="number" min="1" max="' . esc_attr((string) Settings::WINDOW_DAYS_MAX) . '" name="settings[look_ahead_days]" value="' . esc_attr((string) (int) $s['look_ahead_days']) . '" />';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: maximum look-ahead days */
				__('How far ahead to list and include in the admin digest (1–%d). Keep this larger than the hide-below value.', 'intersoccer-player-birthdays'),
				Settings::WINDOW_DAYS_MAX
			)
		) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Hide nearer than (days)', 'intersoccer-player-birthdays') . '</th><td>';
		echo '<input type="number" min="0" max="' . esc_attr((string) Settings::WINDOW_DAYS_MAX) . '" name="settings[min_notice_days]" value="' . esc_attr((string) (int) $s['min_notice_days']) . '" />';
		echo '<p class="description">' . esc_html__('Upcoming list and admin digest hide birthdays fewer than this many days away (default 21, so office can contact parents about three weeks before). 0 shows every birthday in the look-ahead window.', 'intersoccer-player-birthdays') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Extra digest recipients', 'intersoccer-player-birthdays') . '</th><td>';
		echo '<input type="text" class="large-text" name="settings[digest_extra_recipients]" value="' . esc_attr($s['digest_extra_recipients']) . '" />';
		echo '<p class="description">' . esc_html__('Optional comma-separated emails in addition to the WordPress admin email.', 'intersoccer-player-birthdays') . '</p></td></tr>';
		echo '<tr><th>' . esc_html__('Test email address', 'intersoccer-player-birthdays') . '</th><td>';
		echo '<input type="email" class="regular-text" name="settings[test_email]" value="' . esc_attr($s['test_email']) . '" />';
		echo '<p class="description">' . esc_html__('If empty, test mail goes to the logged-in admin.', 'intersoccer-player-birthdays') . '</p></td></tr>';
		echo '</table>';
		submit_button(__('Save settings', 'intersoccer-player-birthdays'));
		echo '</form>';
	}

	/**
	 * @return void
	 */
	private function render_log() {
		$rows = $this->logger->recent(100);
		echo '<p class="description">' . esc_html__('Send log stores player UUID, user ID, year, and mode only — no names, emails, or dates of birth.', 'intersoccer-player-birthdays') . '</p>';
		if (empty($rows)) {
			echo '<p>' . esc_html__('No sends recorded yet.', 'intersoccer-player-birthdays') . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__('Sent at (UTC)', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Mode', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('User ID', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Player ID', 'intersoccer-player-birthdays') . '</th>';
		echo '<th>' . esc_html__('Year', 'intersoccer-player-birthdays') . '</th>';
		echo '</tr></thead><tbody>';
		foreach ($rows as $row) {
			echo '<tr>';
			echo '<td>' . esc_html($row['sent_at']) . '</td>';
			echo '<td>' . esc_html($row['mode']) . '</td>';
			echo '<td>' . esc_html((string) (int) $row['user_id']) . '</td>';
			echo '<td><code>' . esc_html($row['player_id']) . '</code></td>';
			echo '<td>' . esc_html((string) (int) $row['occurrence_year']) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @return void
	 */
	public function ajax_test_send() {
		if (!self::verify_request(true)) {
			return;
		}
		$settings = Settings::get();
		$to = sanitize_email((string) $settings['test_email']);
		if (!is_email($to)) {
			$user = wp_get_current_user();
			$to = $user && is_email($user->user_email) ? $user->user_email : '';
		}
		if (!is_email($to)) {
			wp_send_json_error(array('message' => __('No test recipient email available.', 'intersoccer-player-birthdays')));
		}
		$mailer = new Mailer($this->logger);
		$ok = $mailer->send_greeting(Mailer::sample_candidate(get_current_user_id()), 'test', $to);
		if ($ok) {
			wp_send_json_success(array('message' => __('Test email sent.', 'intersoccer-player-birthdays')));
		}
		wp_send_json_error(array('message' => __('Test email could not be sent.', 'intersoccer-player-birthdays')));
	}

	/**
	 * @return void
	 */
	public function ajax_manual_send() {
		if (!self::verify_request(true)) {
			return;
		}
		$user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
		$player_id = isset($_POST['player_id']) ? sanitize_text_field(wp_unslash($_POST['player_id'])) : '';
		if ($user_id < 1 || $player_id === '') {
			wp_send_json_error(array('message' => __('Missing player.', 'intersoccer-player-birthdays')));
		}
		if (Finder::is_opted_out($user_id)) {
			wp_send_json_error(array('message' => __('This parent is opted out of birthday emails.', 'intersoccer-player-birthdays')));
		}
		$settings = Settings::get();
		$now = Settings::now();
		$match = null;
		if (function_exists('intersoccer_get_player_by_id')) {
			$found = intersoccer_get_player_by_id($user_id, $player_id);
			if (is_array($found) && isset($found['player']) && is_array($found['player'])) {
				$match = $found['player'];
			}
		}
		if (!is_array($match) && function_exists('intersoccer_get_user_players')) {
			$players = intersoccer_get_user_players($user_id);
			if (is_array($players)) {
				foreach ($players as $player) {
					if (is_array($player) && isset($player['player_id']) && (string) $player['player_id'] === $player_id) {
						$match = $player;
						break;
					}
				}
			}
		}
		if (!is_array($match)) {
			wp_send_json_error(array('message' => __('Player not found.', 'intersoccer-player-birthdays')));
		}
		$row = Finder::evaluate_player($match, $user_id, $now, (int) $settings['look_ahead_days'], null);
		if ($row === null) {
			wp_send_json_error(array('message' => __('Player is not in the upcoming window.', 'intersoccer-player-birthdays')));
		}
		$user = get_userdata($user_id);
		if (!$user) {
			wp_send_json_error(array('message' => __('Guardian not found.', 'intersoccer-player-birthdays')));
		}
		$row['user_email'] = is_email($user->user_email) ? $user->user_email : (string) get_user_meta($user_id, 'billing_email', true);
		$first = get_user_meta($user_id, 'billing_first_name', true);
		if (!is_string($first) || $first === '') {
			$first = get_user_meta($user_id, 'first_name', true);
		}
		$row['guardian_first_name'] = is_string($first) && $first !== '' ? $first : (string) $user->display_name;
		$mailer = new Mailer($this->logger);
		$ok = $mailer->send_greeting($row, 'manual');
		if ($ok) {
			wp_send_json_success(array('message' => __('Greeting sent.', 'intersoccer-player-birthdays')));
		}
		wp_send_json_error(array('message' => __('Could not send (already sent this year, opted out, or mail failed).', 'intersoccer-player-birthdays')));
	}

	/**
	 * @return void
	 */
	public function ajax_opt_out() {
		$this->persist_opt_out_flag(true);
	}

	/**
	 * @return void
	 */
	public function ajax_opt_in() {
		$this->persist_opt_out_flag(false);
	}

	/**
	 * Checkbox toggle: opted=1 sets, opted=0 clears.
	 *
	 * @return void
	 */
	public function ajax_set_opt_out() {
		$opted = isset($_POST['opted']) ? (bool) absint($_POST['opted']) : false;
		$this->persist_opt_out_flag($opted);
	}

	/**
	 * @param bool $opted Whether the guardian is opted out.
	 * @return void
	 */
	private function persist_opt_out_flag($opted) {
		if (!self::verify_request(true)) {
			return;
		}
		$user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
		if ($user_id < 1) {
			wp_send_json_error(array('message' => __('Missing user.', 'intersoccer-player-birthdays')));
		}
		if ($opted) {
			OptOut::set($user_id);
			wp_send_json_success(
				array(
					'message' => __('Guardian opted out.', 'intersoccer-player-birthdays'),
					'opted'   => true,
					'user_id' => $user_id,
				)
			);
		}
		OptOut::clear($user_id);
		wp_send_json_success(
			array(
				'message' => __('Opt-out cleared.', 'intersoccer-player-birthdays'),
				'opted'   => false,
				'user_id' => $user_id,
			)
		);
	}
}
