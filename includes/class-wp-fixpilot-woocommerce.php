<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_WooCommerce {
	public static function analyze() {
		global $wpdb;
		if ( ! class_exists( 'WooCommerce' ) ) { return array( 'active' => false ); }
		$out = array( 'active' => true, 'version' => defined( 'WC_VERSION' ) ? WC_VERSION : '' );
		$out['hpos_enabled'] = class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' ) ? (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() : false;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		if ( self::table_exists( $actions ) ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
			$now = gmdate( 'Y-m-d H:i:s' );
			$out['scheduled_actions'] = array(
				'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$actions} WHERE status='pending'" ),
				'due_pending' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$actions} WHERE status='pending' AND scheduled_date_gmt <= %s", $now ) ),
				'failed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$actions} WHERE status='failed'" ),
				'in_progress' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$actions} WHERE status='in-progress'" ),
				'stuck_in_progress' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$actions} WHERE status='in-progress' AND last_attempt_gmt < %s", gmdate('Y-m-d H:i:s', time()-10*MINUTE_IN_SECONDS) ) ),
				'old_history' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$actions} WHERE status IN ('complete','canceled') AND scheduled_date_gmt < %s", $cutoff ) ),
			);
		}
		$out['sessions'] = self::table_exists( $wpdb->prefix . 'woocommerce_sessions' ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions" ) : 0;
		$out['consistency'] = self::consistency_checks();
		$out['failed_webhooks'] = self::failed_webhooks();
		$out['recommendations'] = array();
		if ( ! empty( $out['scheduled_actions']['failed'] ) ) { $out['recommendations'][] = array( 'priority' => 'high', 'message' => sprintf( __( '%d failed Action Scheduler jobs require review.', 'wp-fixpilot' ), $out['scheduled_actions']['failed'] ) ); }
		if ( ! empty( $out['scheduled_actions']['pending'] ) && $out['scheduled_actions']['pending'] > 1000 ) { $out['recommendations'][] = array( 'priority' => 'high', 'message' => __( 'Action Scheduler has more than 1,000 pending jobs.', 'wp-fixpilot' ) ); }
		if ( ! empty( $out['scheduled_actions']['stuck_in_progress'] ) ) { $out['recommendations'][] = array( 'priority' => 'high', 'message' => sprintf( __( '%d Action Scheduler jobs appear stuck in progress.', 'wp-fixpilot' ), $out['scheduled_actions']['stuck_in_progress'] ) ); }
		if ( ! empty( $out['scheduled_actions']['due_pending'] ) && $out['scheduled_actions']['due_pending'] > 50 ) { $out['recommendations'][] = array( 'priority' => 'high', 'message' => sprintf( __( '%d Action Scheduler jobs are already due.', 'wp-fixpilot' ), $out['scheduled_actions']['due_pending'] ) ); }
		if ( ! empty( $out['scheduled_actions']['old_history'] ) && $out['scheduled_actions']['old_history'] > 5000 ) { $out['recommendations'][] = array( 'priority' => 'medium', 'message' => sprintf( __( '%d old completed/canceled Action Scheduler rows can be cleaned in batches.', 'wp-fixpilot' ), $out['scheduled_actions']['old_history'] ) ); }
		if ( $out['sessions'] > 10000 ) { $out['recommendations'][] = array( 'priority' => 'medium', 'message' => __( 'WooCommerce session table is large; review session cleanup.', 'wp-fixpilot' ) ); }
		return $out;
	}
	private static function consistency_checks() {
		global $wpdb; $out=array('missing_product_lookup'=>0,'orphan_product_lookup'=>0,'lookup_generation_running'=>false);
		$table=$wpdb->prefix.'wc_product_meta_lookup';
		if(self::table_exists($table)){
			$out['missing_product_lookup']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$table} l ON l.product_id=p.ID WHERE p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') AND l.product_id IS NULL");
			$out['orphan_product_lookup']=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} l LEFT JOIN {$wpdb->posts} p ON p.ID=l.product_id WHERE p.ID IS NULL");
		}
		$out['lookup_generation_running']=function_exists('wc_update_product_lookup_tables_is_running')?(bool)wc_update_product_lookup_tables_is_running():false;
		return $out;
	}

	private static function table_exists( $table ) { global $wpdb; return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); }
	private static function failed_webhooks() {
		$ids = get_posts( array( 'post_type' => 'shop_webhook', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
		return is_array( $ids ) ? count( $ids ) : 0;
	}
}
