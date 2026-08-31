<?php
/**
 * Minimal WordPress stubs for unit tests.
 */

if (!defined('HOUR_IN_SECONDS')) {
	define('HOUR_IN_SECONDS', 3600);
}
if (!defined('ARRAY_A')) {
	define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['wp_nonce_ok'] = true;
$GLOBALS['wp_can_manage'] = true;
$GLOBALS['wp_options'] = array();
$GLOBALS['wp_user_meta'] = array();
$GLOBALS['wp_mail_sent'] = array();
$GLOBALS['wp_users'] = array();
$GLOBALS['wp_transients'] = array();

if (!function_exists('__')) {
	function __($text, $domain = 'default') {
		return $text;
	}
}
if (!function_exists('esc_html__')) {
	function esc_html__($text, $domain = 'default') {
		return $text;
	}
}
if (!function_exists('esc_html')) {
	function esc_html($text) {
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}
if (!function_exists('esc_attr')) {
	function esc_attr($text) {
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}
if (!function_exists('esc_url')) {
	function esc_url($url) {
		return $url;
	}
}
if (!function_exists('wp_kses_post')) {
	function wp_kses_post($text) {
		return $text;
	}
}
if (!function_exists('sanitize_text_field')) {
	function sanitize_text_field($str) {
		return trim(strip_tags((string) $str));
	}
}
if (!function_exists('sanitize_key')) {
	function sanitize_key($key) {
		return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
	}
}
if (!function_exists('sanitize_email')) {
	function sanitize_email($email) {
		return filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL);
	}
}
if (!function_exists('is_email')) {
	function is_email($email) {
		return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
	}
}
if (!function_exists('wp_unslash')) {
	function wp_unslash($value) {
		return $value;
	}
}
if (!function_exists('absint')) {
	function absint($maybeint) {
		return abs((int) $maybeint);
	}
}
if (!function_exists('checked')) {
	function checked($checked, $current = true, $echo = true) {
		return '';
	}
}
if (!function_exists('selected')) {
	function selected($selected, $current = true, $echo = true) {
		return '';
	}
}
if (!function_exists('get_option')) {
	function get_option($key, $default = false) {
		return array_key_exists($key, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][ $key ] : $default;
	}
}
if (!function_exists('update_option')) {
	function update_option($key, $value) {
		$GLOBALS['wp_options'][ $key ] = $value;
		return true;
	}
}
if (!function_exists('get_user_meta')) {
	function get_user_meta($user_id, $key, $single = false) {
		return $GLOBALS['wp_user_meta'][ (int) $user_id ][ $key ] ?? '';
	}
}
if (!function_exists('update_user_meta')) {
	function update_user_meta($user_id, $key, $value) {
		$GLOBALS['wp_user_meta'][ (int) $user_id ][ $key ] = $value;
		return true;
	}
}
if (!function_exists('delete_user_meta')) {
	function delete_user_meta($user_id, $key) {
		unset($GLOBALS['wp_user_meta'][ (int) $user_id ][ $key ]);
		return true;
	}
}
if (!function_exists('wp_salt')) {
	function wp_salt($scheme = 'auth') {
		return 'test-salt-' . $scheme;
	}
}
if (!function_exists('home_url')) {
	function home_url($path = '/') {
		return 'https://example.test' . $path;
	}
}
if (!function_exists('add_query_arg')) {
	function add_query_arg($args, $url) {
		return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
	}
}
if (!function_exists('check_ajax_referer')) {
	function check_ajax_referer($action = '', $query_arg = false, $die = true) {
		$ok = !empty($GLOBALS['wp_nonce_ok']);
		if (!$ok && $die) {
			throw new RuntimeException('nonce');
		}
		return $ok;
	}
}
if (!function_exists('current_user_can')) {
	function current_user_can($cap) {
		return !empty($GLOBALS['wp_can_manage']);
	}
}
if (!function_exists('wp_send_json_error')) {
	function wp_send_json_error($data = null, $status = 400) {
		throw new RuntimeException('json_error:' . $status);
	}
}
if (!function_exists('wp_send_json_success')) {
	function wp_send_json_success($data = null) {
		throw new RuntimeException('json_success');
	}
}
if (!function_exists('get_current_user_id')) {
	function get_current_user_id() {
		return 1;
	}
}
if (!function_exists('wp_get_current_user')) {
	function wp_get_current_user() {
		$user = new stdClass();
		$user->user_email = 'admin@example.test';
		return $user;
	}
}
if (!function_exists('wp_mail')) {
	function wp_mail($to, $subject, $message, $headers = '') {
		$GLOBALS['wp_mail_sent'][] = compact('to', 'subject', 'message', 'headers');
		return true;
	}
}
if (!function_exists('add_filter')) {
	function add_filter($tag, $fn, $priority = 10, $accepted = 1) {
	}
}
if (!function_exists('remove_filter')) {
	function remove_filter($tag, $fn, $priority = 10) {
	}
}
if (!function_exists('has_filter')) {
	function has_filter($tag) {
		return false;
	}
}
if (!function_exists('apply_filters')) {
	function apply_filters($tag, $value) {
		return $value;
	}
}
if (!function_exists('add_action')) {
	function add_action($tag, $fn, $priority = 10, $accepted = 1) {
	}
}
if (!function_exists('wp_specialchars_decode')) {
	function wp_specialchars_decode($text, $quote_style = ENT_NOQUOTES) {
		return htmlspecialchars_decode((string) $text, $quote_style);
	}
}
if (!function_exists('get_bloginfo')) {
	function get_bloginfo($show = '') {
		return 'InterSoccer Test';
	}
}
if (!function_exists('get_user_locale')) {
	function get_user_locale($user_id) {
		return 'en_US';
	}
}
if (!function_exists('get_user_by')) {
	function get_user_by($field, $value) {
		if ($field !== 'email') {
			return false;
		}
		$value = strtolower(trim((string) $value));
		foreach ($GLOBALS['wp_users'] as $user) {
			if (!is_object($user)) {
				continue;
			}
			$email = isset($user->user_email) ? strtolower((string) $user->user_email) : '';
			if ($email === $value) {
				return $user;
			}
		}
		return false;
	}
}
if (!function_exists('get_users')) {
	function get_users($args = array()) {
		$args = is_array($args) ? $args : array();
		$key = isset($args['meta_key']) ? (string) $args['meta_key'] : '';
		$value = isset($args['meta_value']) ? (string) $args['meta_value'] : '';
		if (isset($args['meta_query']) && is_array($args['meta_query'])) {
			foreach ($args['meta_query'] as $query) {
				if (!is_array($query) || !isset($query['key'])) {
					continue;
				}
				if (($query['compare'] ?? '') === 'EXISTS') {
					continue;
				}
				$key = (string) $query['key'];
				$value = isset($query['value']) ? (string) $query['value'] : '';
			}
		}
		if ($key === '') {
			return array();
		}
		$value = strtolower(trim((string) $value));
		$out = array();
		foreach ($GLOBALS['wp_users'] as $id => $user) {
			$meta = strtolower(trim((string) get_user_meta((int) $id, $key, true)));
			if ($meta === $value) {
				$out[] = $user;
			}
		}
		return $out;
	}
}
if (!function_exists('set_transient')) {
	function set_transient($transient, $value, $expiration = 0) {
		$GLOBALS['wp_transients'][ (string) $transient ] = $value;
		return true;
	}
}
if (!function_exists('get_transient')) {
	function get_transient($transient) {
		$key = (string) $transient;
		return array_key_exists($key, $GLOBALS['wp_transients']) ? $GLOBALS['wp_transients'][ $key ] : false;
	}
}
if (!function_exists('wp_verify_nonce')) {
	function wp_verify_nonce($nonce, $action = -1) {
		return !empty($GLOBALS['wp_nonce_ok']);
	}
}
if (!function_exists('wp_nonce_url')) {
	function wp_nonce_url($url, $action = -1, $name = '_wpnonce') {
		$sep = strpos($url, '?') === false ? '?' : '&';
		return $url . $sep . $name . '=test-nonce';
	}
}
if (!function_exists('nocache_headers')) {
	function nocache_headers() {
	}
}
