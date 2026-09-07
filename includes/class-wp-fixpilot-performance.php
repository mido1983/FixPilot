<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Performance {
	public static function analyze() {
		global $wpdb;
		$autoload_bytes = (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')" );
		$expired_transients = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) );
		$cron = _get_cron_array(); $overdue = 0; $cron_events = 0; if ( is_array( $cron ) ) { foreach ( $cron as $timestamp => $hooks ) { $cron_events += count( $hooks ); if ( (int) $timestamp < time() - HOUR_IN_SECONDS ) { $overdue += count( $hooks ); } } }
		$active_plugins = (array) get_option( 'active_plugins', array() ); $object_cache = wp_using_ext_object_cache();
		return array( 'autoload_bytes' => $autoload_bytes, 'autoload_mb' => round( $autoload_bytes / 1048576, 2 ), 'expired_transients' => $expired_transients, 'overdue_cron_events' => $overdue, 'cron_events' => $cron_events, 'active_plugins' => count( $active_plugins ), 'object_cache' => $object_cache, 'memory_limit' => WP_MEMORY_LIMIT, 'recommendations' => self::recommendations( $autoload_bytes, $expired_transients, $overdue, count( $active_plugins ), $object_cache ) );
	}
	private static function recommendations( $autoload_bytes, $expired, $overdue, $plugin_count, $object_cache ) {
		$items = array();
		if ( $autoload_bytes > 2 * MB_IN_BYTES ) { $items[] = array( 'priority'=>'critical','code'=>'autoload','message'=>__( 'Autoloaded options exceed 2 MB and can materially increase every uncached request.', 'wp-fixpilot' ) ); } elseif ( $autoload_bytes > MB_IN_BYTES ) { $items[] = array( 'priority'=>'high','code'=>'autoload','message'=>__( 'Autoloaded options exceed 1 MB. Review the largest rows.', 'wp-fixpilot' ) ); }
		if ( $expired > 100 ) { $items[] = array( 'priority'=>'medium','code'=>'transients','message'=>__( 'Many expired transients can be safely cleaned.', 'wp-fixpilot' ) ); }
		if ( $overdue > 10 ) { $items[] = array( 'priority'=>'high','code'=>'cron','message'=>__( 'Overdue WP-Cron events indicate delayed background work.', 'wp-fixpilot' ) ); }
		if ( $plugin_count > 35 ) { $items[] = array( 'priority'=>'medium','code'=>'plugins','message'=>__( 'The active plugin count is high. Review overlapping capabilities and runtime issues.', 'wp-fixpilot' ) ); }
		if ( ! $object_cache ) { $items[] = array( 'priority'=>'low','code'=>'object-cache','message'=>__( 'Persistent object cache is not detected. Dynamic WooCommerce sites often benefit from Redis/Memcached.', 'wp-fixpilot' ) ); }
		return $items;
	}
}
