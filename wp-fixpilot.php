<?php
/**
 * Plugin Name:       WP FixPilot
 * Description:       WordPress and WooCommerce diagnostics, PHP error monitoring, plugin risk analysis, performance profiling, Safe Mode and conservative automatic repair with backup and rollback.
 * Version:           4.0.0-rc1
 * Requires at least: 6.5
 * Tested up to:       7.1
 * Requires PHP:      7.4
 * Author:            WP FixPilot
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-fixpilot
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 */
if(!defined('ABSPATH'))exit;
define('WP_FIXPILOT_VERSION','4.0.0-rc1');define('WP_FIXPILOT_FILE',__FILE__);define('WP_FIXPILOT_DIR',plugin_dir_path(__FILE__));define('WP_FIXPILOT_URL',plugin_dir_url(__FILE__));require_once WP_FIXPILOT_DIR.'includes/class-wp-fixpilot.php';register_activation_hook(__FILE__,array('WP_FixPilot','activate'));register_deactivation_hook(__FILE__,array('WP_FixPilot','deactivate'));WP_FixPilot::instance();