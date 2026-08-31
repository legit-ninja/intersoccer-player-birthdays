<?php
/**
 * PHPUnit bootstrap for InterSoccer Player Birthdays.
 */

define('ABSPATH', __DIR__ . '/fixtures/');

require_once __DIR__ . '/helpers/wp-stubs.php';

$plugin_root = dirname(__DIR__);
require_once $plugin_root . '/includes/class-settings.php';
require_once $plugin_root . '/includes/class-finder.php';
require_once $plugin_root . '/includes/class-templates.php';
require_once $plugin_root . '/includes/class-logger.php';
require_once $plugin_root . '/includes/class-opt-out.php';
require_once $plugin_root . '/includes/class-opt-out-import.php';
require_once $plugin_root . '/includes/class-mailer.php';
require_once $plugin_root . '/includes/class-scheduler.php';
require_once $plugin_root . '/includes/admin/class-admin.php';
