<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Fixer {
	public static function run_named_fix( $fix ) {
		switch ( $fix ) {
			case 'transients': return self::cleanup_expired_transients();
			case 'rewrites': return self::flush_rewrites();
			case 'wc-transients': return self::clear_woocommerce_transients();
			case 'wc-sessions': return self::cleanup_woocommerce_sessions();
			case 'wc-product-lookup': return self::regenerate_woocommerce_product_lookup();
			case 'wc-orphan-product-lookup': return self::cleanup_orphan_product_lookup();
			case 'as-run-queue': return self::run_action_scheduler_queue();
			case 'as-clean-history': return self::cleanup_action_scheduler_history();
			case 'as-recover-stuck': return self::recover_action_scheduler_stuck();
			case 'revisions': return self::cleanup_revisions(5);
			case 'orphan-postmeta': return self::cleanup_orphan_postmeta();
			case 'orphan-usermeta': return self::cleanup_orphan_usermeta();
			case 'cron-due': return self::run_due_cron();
			case 'production-error-guard': return WP_FixPilot_Config::set_production_error_guard( true );
			case 'heartbeat-60': return WP_FixPilot_Config::set_heartbeat_interval( 60 );
			case 'wc-session-fallback': return WP_FixPilot_Config::set_wc_session_fallback( true );
			case 'cleanup_duplicate_cron': return WP_FixPilot_Performance_Repair::cleanup_duplicate_cron();
			case 'cleanup_stale_single_cron': return WP_FixPilot_Performance_Repair::cleanup_stale_single_events();
		}
		return new WP_Error('fixpilot_unknown_repair',__('Unknown repair operation.','wp-fixpilot'));
	}
	public static function cleanup_expired_transients() { global $wpdb; $prefix='_transient_timeout_'; $rows=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 5000",$wpdb->esc_like($prefix).'%',time())); $count=0; foreach($rows as $opt){ delete_transient(substr($opt,strlen($prefix))); $count++; } return $count; }
	public static function flush_rewrites(){ flush_rewrite_rules(false); return true; }
	public static function clear_woocommerce_transients(){ if(function_exists('wc_delete_product_transients')){ wc_delete_product_transients(); return true; } return new WP_Error('fixpilot_wc_missing',__('WooCommerce product transient API is unavailable.','wp-fixpilot')); }
	public static function cleanup_woocommerce_sessions(){ if(function_exists('wc_cleanup_session_data')){ wc_cleanup_session_data(); return true; } return new WP_Error('fixpilot_wc_missing',__('WooCommerce session cleanup API is unavailable.','wp-fixpilot')); }
	public static function regenerate_woocommerce_product_lookup(){
		if ( ! function_exists( 'wc_update_product_lookup_tables' ) ) return new WP_Error('fixpilot_wc_lookup_missing',__('WooCommerce product lookup regeneration API is unavailable.','wp-fixpilot'));
		if ( function_exists( 'wc_update_product_lookup_tables_is_running' ) && wc_update_product_lookup_tables_is_running() ) return new WP_Error('fixpilot_wc_lookup_running',__('WooCommerce is already regenerating product lookup tables.','wp-fixpilot'));
		wc_update_product_lookup_tables();
		return true;
	}

	public static function cleanup_orphan_product_lookup( $limit = 5000 ) {
		global $wpdb; $limit=max(1,min(5000,absint($limit))); $table=$wpdb->prefix.'wc_product_meta_lookup';
		$exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)); if($exists!==$table)return new WP_Error('fixpilot_wc_lookup_missing',__('WooCommerce product lookup table is unavailable.','wp-fixpilot'));
		$ids=$wpdb->get_col("SELECT l.product_id FROM {$table} l LEFT JOIN {$wpdb->posts} p ON p.ID=l.product_id WHERE p.ID IS NULL LIMIT {$limit}");
		if(!$ids)return 0; $ids=array_map('absint',$ids); return (int)$wpdb->query("DELETE FROM {$table} WHERE product_id IN (".implode(',',$ids).")");
	}

	public static function cleanup_revisions( $keep = 5 ) { global $wpdb; $keep=max(0,absint($keep)); $post_ids=$wpdb->get_col("SELECT post_parent FROM {$wpdb->posts} WHERE post_type='revision' GROUP BY post_parent HAVING COUNT(*) > ".($keep+1)." LIMIT 1000"); $deleted=0; foreach($post_ids as $parent){ $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='revision' AND post_parent=%d ORDER BY post_date_gmt DESC",$parent)); foreach(array_slice($ids,$keep) as $id){ if(wp_delete_post((int)$id,true)){$deleted++;} } } return $deleted; }
	public static function cleanup_orphan_postmeta(){ global $wpdb; $ids=$wpdb->get_col("SELECT pm.meta_id FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.ID IS NULL LIMIT 5000"); if(!$ids)return 0; $ids=array_map('absint',$ids); return (int)$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_id IN (".implode(',', $ids).")"); }
	public static function cleanup_orphan_usermeta(){ global $wpdb; $ids=$wpdb->get_col("SELECT um.umeta_id FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID=um.user_id WHERE u.ID IS NULL LIMIT 5000"); if(!$ids)return 0; $ids=array_map('absint',$ids); return (int)$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE umeta_id IN (".implode(',', $ids).")"); }
	public static function run_due_cron(){ if ( function_exists( 'spawn_cron' ) ) { spawn_cron( time() ); return true; } return false; }

	public static function run_action_scheduler_queue() {
		if ( ! class_exists( 'ActionScheduler_QueueRunner' ) && ! has_action( 'action_scheduler_run_queue' ) ) {
			return new WP_Error( 'fixpilot_as_missing', __( 'Action Scheduler is not available.', 'wp-fixpilot' ) );
		}
		do_action( 'action_scheduler_run_queue', 'WP FixPilot' );
		return true;
	}

	public static function cleanup_action_scheduler_history( $days = 31, $limit = 1000 ) {
		$days = max( 7, min( 365, absint( $days ) ) );
		$limit = max( 1, min( 5000, absint( $limit ) ) );
		if ( ! class_exists( 'ActionScheduler_QueueCleaner' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return new WP_Error('fixpilot_as_cleaner_missing',__('Action Scheduler cleaner API is unavailable.','wp-fixpilot'));
		}
		$retention = $days * DAY_IN_SECONDS;
		$filter = function() use ( $retention ) { return $retention; };
		add_filter( 'action_scheduler_retention_period', $filter, 9999 );
		try {
			$cleaner = new ActionScheduler_QueueCleaner( null, $limit );
			$deleted = $cleaner->delete_old_actions();
			remove_filter( 'action_scheduler_retention_period', $filter, 9999 );
			return is_array( $deleted ) ? count( $deleted ) : 0;
		} catch ( Throwable $e ) {
			remove_filter( 'action_scheduler_retention_period', $filter, 9999 );
			return new WP_Error( 'fixpilot_as_cleanup_failed', $e->getMessage() );
		}
	}

	public static function recover_action_scheduler_stuck( $time_limit = 300 ) {
		$time_limit = max( 60, min( 3600, absint( $time_limit ) ) );
		if ( ! class_exists( 'ActionScheduler_QueueCleaner' ) ) {
			return new WP_Error('fixpilot_as_cleaner_missing',__('Action Scheduler cleaner API is unavailable.','wp-fixpilot'));
		}
		try {
			$cleaner = new ActionScheduler_QueueCleaner( null, 250 );
			$cleaner->reset_timeouts( $time_limit );
			$cleaner->mark_failures( $time_limit );
			do_action( 'action_scheduler_run_queue', 'WP FixPilot recovery' );
			return true;
		} catch ( Throwable $e ) {
			return new WP_Error('fixpilot_as_recovery_failed',$e->getMessage());
		}
	}

	public static function disable_autoload_for_option( $option_name ) {
		global $wpdb;
		$option_name = sanitize_text_field( $option_name );
		if ( '' === $option_name || ! self::is_safe_autoload_option( $option_name ) ) return new WP_Error('fixpilot_autoload_unsafe',__('Only transient/cache options on the safe allowlist can be changed automatically.','wp-fixpilot'));
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name=%s LIMIT 1", $option_name ), ARRAY_A );
		if ( ! $row ) return new WP_Error('fixpilot_option_missing',__('The option no longer exists.','wp-fixpilot'));
		if ( ! in_array( $row['autoload'], array('yes','on','auto-on','auto'), true ) ) return true;
		$repair_id = 'autoload-' . wp_generate_uuid4();
		$history = (array) get_option( 'wp_fixpilot_option_repairs', array() );
		array_unshift( $history, array('id'=>$repair_id,'time'=>gmdate('c'),'option_name'=>$option_name,'old_autoload'=>$row['autoload'],'new_autoload'=>'off') );
		update_option( 'wp_fixpilot_option_repairs', array_slice( $history, 0, 100 ), false );
		if ( ! function_exists( 'wp_set_option_autoload' ) ) return new WP_Error('fixpilot_autoload_api_missing',__('WordPress autoload API is unavailable.','wp-fixpilot'));
		if ( ! wp_set_option_autoload( $option_name, false ) ) {
			$new = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", $option_name ) );
			if ( in_array( $new, array('yes','on','auto-on','auto'), true ) ) return new WP_Error('fixpilot_autoload_update_failed',__('Could not change the option autoload policy.','wp-fixpilot'));
		}
		return array('status'=>'applied','repair_id'=>$repair_id,'option_name'=>$option_name);
	}

	public static function restore_autoload_repair( $repair_id ) {
		global $wpdb;
		$repair_id = sanitize_text_field( $repair_id );
		$history = (array) get_option( 'wp_fixpilot_option_repairs', array() );
		foreach ( $history as $entry ) {
			if ( empty($entry['id']) || ! hash_equals( (string)$entry['id'], $repair_id ) ) continue;
			$name = (string) $entry['option_name'];
			if ( ! self::is_safe_autoload_option( $name ) ) return new WP_Error('fixpilot_autoload_restore_unsafe',__('Stored option is outside the safe autoload allowlist.','wp-fixpilot'));
			$old = sanitize_key( (string)$entry['old_autoload'] );
			if ( ! in_array( $old, array('yes','no','on','off','auto','auto-on','auto-off'), true ) ) $old = 'yes';
			if ( ! function_exists( 'wp_set_option_autoload' ) ) return new WP_Error('fixpilot_autoload_api_missing',__('WordPress autoload API is unavailable.','wp-fixpilot'));
			$autoload = in_array( $old, array('yes','on','auto-on','auto'), true );
			$result = wp_set_option_autoload( $name, $autoload );
			if ( ! $result ) {
				$current = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", $name ) );
				$current_bool = in_array( $current, array('yes','on','auto-on','auto'), true );
				if ( $current_bool !== $autoload ) return new WP_Error('fixpilot_autoload_restore_failed',__('Could not restore the previous autoload policy.','wp-fixpilot'));
			}
			return true;
		}
		return new WP_Error('fixpilot_autoload_repair_missing',__('Autoload repair record was not found.','wp-fixpilot'));
	}

	private static function is_safe_autoload_option( $name ) {
		return 0 === strpos( $name, '_transient_' ) || 0 === strpos( $name, '_site_transient_' );
	}
}
