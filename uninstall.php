<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
global $wpdb;
wp_clear_scheduled_hook( 'wp_fixpilot_weekly_scan' );
wp_clear_scheduled_hook( 'wp_fixpilot_daily_wc_session_cleanup' );
wp_clear_scheduled_hook( 'wp_fixpilot_safety_watchdog' );
$options = array(
 'wp_fixpilot_settings','wp_fixpilot_last_scan','wp_fixpilot_last_repair','wp_fixpilot_last_benchmark','wp_fixpilot_repair_log',
 'wp_fixpilot_safe_mode_sessions','wp_fixpilot_conflict_profile','wp_fixpilot_update_guard_pending','wp_fixpilot_update_guard_history',
 'wp_fixpilot_request_profiles','wp_fixpilot_last_batch_repair','wp_fixpilot_profile_comparison','wp_fixpilot_option_repairs',
 'wp_fixpilot_plugin_repair_plans','wp_fixpilot_runtime_config','wp_fixpilot_auto_repair_last','wp_fixpilot_auto_repair_history',
 'wp_fixpilot_config_repairs','wp_fixpilot_repair_lock','wp_fixpilot_quarantine','wp_fixpilot_update_guard_pro_pending',
 'wp_fixpilot_update_guard_pro_history','wp_fixpilot_ai_settings','wp_fixpilot_ai_proposal'
);
foreach ( $options as $option ) { delete_option( $option ); }
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fixpilot_issues" );
$mu = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
foreach ( array( 'wp-fixpilot-safe-mode.php', 'wp-fixpilot-recovery.php' ) as $file ) {
 $path = trailingslashit( $mu ) . $file;
 if ( is_file( $path ) ) { @unlink( $path ); }
}
$manifest = WP_CONTENT_DIR . '/fixpilot-recovery.json';
if ( is_file( $manifest ) ) { @unlink( $manifest ); }
