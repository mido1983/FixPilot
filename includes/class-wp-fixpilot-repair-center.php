<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_FixPilot_Repair_Center {
	public static function candidates() {
		$items = array();
		$report = get_option( 'wp_fixpilot_last_scan', array() );
		if ( empty( $report ) ) {
			$report = WP_FixPilot_Scanner::run_full_scan();
			update_option( 'wp_fixpilot_last_scan', $report, false );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'fixpilot_issues';
		$issues = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY last_seen DESC LIMIT 250", ARRAY_A );
		foreach ( (array) $issues as $issue ) {
			$fix = WP_FixPilot_Repair_Engine::classify_issue( $issue );
			if ( ! $fix ) { continue; }
			$items[] = array(
				'id' => 'issue-' . (int) $issue['id'], 'kind' => 'code',
				'label' => isset( WP_FixPilot_Repair_Engine::supported_fixes()[ $fix['type'] ]['label'] ) ? WP_FixPilot_Repair_Engine::supported_fixes()[ $fix['type'] ]['label'] : $fix['type'],
				'detail' => (string) $issue['message'], 'target' => (string) $issue['file'] . ':' . (int) $issue['line'],
				'confidence' => (int) $fix['confidence'], 'risk' => 'low', 'reversible' => true,
				'batch_safe' => (int) $fix['confidence'] >= 95, 'action' => 'repair_issue', 'fields' => array( 'issue_id' => (int) $issue['id'] ),
			);
		}

		$perf = isset( $report['performance'] ) ? $report['performance'] : WP_FixPilot_Performance::analyze();
		if ( ! empty( $perf['expired_transients'] ) ) $items[] = self::maintenance( 'expired-transients', 'Delete expired transients', (int) $perf['expired_transients'] . ' expired transients can be removed.', 'WordPress options', 100, true, 'transients' );
		if ( ! empty( $perf['cron_late'] ) ) $items[] = array('id'=>'cron-late','kind'=>'maintenance','label'=>'Run overdue WP-Cron events now','detail'=>(int)$perf['cron_late'].' overdue cron events detected.','target'=>'WP-Cron','confidence'=>90,'risk'=>'medium','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'cron-due'));

		$config = WP_FixPilot_Config::analyze();
		if ( 'production' === $config['environment'] && $config['display_errors'] && empty( $config['production_hide_errors'] ) ) {
			$items[] = array('id'=>'production-error-guard','kind'=>'configuration','label'=>'Hide PHP error output from production visitors','detail'=>'display_errors is currently enabled on a production environment. Keep monitoring/logging, but suppress raw PHP warnings/notices in visitor responses.','target'=>'PHP runtime display_errors','confidence'=>99,'risk'=>'low','reversible'=>true,'batch_safe'=>true,'action'=>'fix','fields'=>array('fix'=>'production-error-guard'));
		}
		if ( empty( $config['heartbeat_interval'] ) ) {
			$items[] = array('id'=>'heartbeat-60','kind'=>'configuration','label'=>'Throttle WordPress Heartbeat to 60 seconds','detail'=>'Reduce admin-ajax Heartbeat frequency while preserving Heartbeat functionality. WordPress supports intervals from 15 to 120 seconds.','target'=>'heartbeat_settings','confidence'=>95,'risk'=>'medium','reversible'=>true,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'heartbeat-60'));
		}
		if ( class_exists('WooCommerce') && empty($config['wc_cleanup_scheduled']) && empty($config['fixpilot_wc_cleanup_scheduled']) ) {
			$items[] = array('id'=>'wc-session-fallback','kind'=>'configuration','label'=>'Install daily WooCommerce session cleanup fallback','detail'=>'No WooCommerce session cleanup event is currently scheduled. FixPilot will schedule a daily fallback and automatically stand down if WooCommerce schedules its own cleanup.','target'=>'WooCommerce sessions cron','confidence'=>97,'risk'=>'low','reversible'=>true,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'wc-session-fallback'));
		}

		$db = WP_FixPilot_Database::analyze();
		foreach ( (array) $db['autoload_safe_candidates'] as $option ) {
			$bytes = isset($option['bytes']) ? (int)$option['bytes'] : 0;
			$items[] = array(
				'id'=>'autoload-'.md5($option['option_name']), 'kind'=>'performance', 'label'=>'Disable autoload for transient option',
				'detail'=>sprintf('%s is loaded on every request (%s). Its value remains unchanged and can still be read normally.', $option['option_name'], size_format($bytes)),
				'target'=>$option['option_name'], 'confidence'=>99, 'risk'=>'low', 'reversible'=>true, 'batch_safe'=>true,
				'action'=>'repair_autoload', 'fields'=>array('option_name'=>$option['option_name'])
			);
		}
		if ( ! empty( $db['orphan_postmeta'] ) ) $items[] = array('id'=>'orphan-postmeta','kind'=>'database','label'=>'Delete orphan postmeta','detail'=>(int)$db['orphan_postmeta'].' metadata rows reference posts that no longer exist.','target'=>$wpdb->postmeta,'confidence'=>99,'risk'=>'medium','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'orphan-postmeta'));
		if ( ! empty( $db['orphan_usermeta'] ) ) $items[] = array('id'=>'orphan-usermeta','kind'=>'database','label'=>'Delete orphan usermeta','detail'=>(int)$db['orphan_usermeta'].' metadata rows reference users that no longer exist.','target'=>$wpdb->usermeta,'confidence'=>99,'risk'=>'medium','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'orphan-usermeta'));
		if ( ! empty( $db['revisions'] ) && (int)$db['revisions'] > 50 ) $items[] = array('id'=>'revisions','kind'=>'database','label'=>'Trim old revisions','detail'=>(int)$db['revisions'].' revisions detected. Keep the newest 5 per post.','target'=>$wpdb->posts,'confidence'=>100,'risk'=>'medium','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'revisions'));

		if ( class_exists( 'WooCommerce' ) ) {
			$wc = WP_FixPilot_WooCommerce::analyze();
			if ( ! empty( $wc['sessions'] ) ) $items[] = self::maintenance( 'wc-sessions', 'Clean expired WooCommerce sessions', 'Remove WooCommerce session rows that have expired.', 'WooCommerce sessions', 100, true, 'wc-sessions' );
			$items[] = self::maintenance( 'wc-transients', 'Refresh WooCommerce product transients', 'Clear product transients so WooCommerce rebuilds derived cached data.', 'WooCommerce cache', 100, true, 'wc-transients' );
			if ( function_exists( 'wc_update_product_lookup_tables' ) && ( ! function_exists('wc_update_product_lookup_tables_is_running') || ! wc_update_product_lookup_tables_is_running() ) ) {
				$items[] = array('id'=>'wc-product-lookup','kind'=>'woocommerce','label'=>'Regenerate WooCommerce product lookup tables','detail'=>'Queue WooCommerce’s own lookup-table regeneration. Useful after imports, pricing/stock inconsistencies or stale catalog indexes.','target'=>'wc_product_meta_lookup','confidence'=>98,'risk'=>'medium','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'wc-product-lookup'));
			}
			if ( ! empty($wc['scheduled_actions']['stuck_in_progress']) ) {
				$items[] = array('id'=>'as-recover-stuck','kind'=>'woocommerce','label'=>'Recover stuck Action Scheduler jobs','detail'=>(int)$wc['scheduled_actions']['stuck_in_progress'].' jobs have remained in-progress for more than 10 minutes. Uses Action Scheduler’s own timeout reset/failure cleanup and then runs the queue.','target'=>'Action Scheduler','confidence'=>98,'risk'=>'medium','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'as-recover-stuck'));
			}
			if ( ! empty($wc['consistency']['orphan_product_lookup']) ) {
				$items[] = array('id'=>'wc-lookup-orphans','kind'=>'database','label'=>'Delete orphan WooCommerce product lookup rows','detail'=>(int)$wc['consistency']['orphan_product_lookup'].' lookup rows reference products that no longer exist. Deletes at most 5,000 confirmed orphan product IDs per run.','target'=>'wc_product_meta_lookup','confidence'=>99,'risk'=>'medium','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'wc-orphan-product-lookup'));
			}
			if ( ! empty($wc['consistency']['missing_product_lookup']) ) {
				$items[] = array('id'=>'wc-lookup-missing','kind'=>'woocommerce','label'=>'Repair missing WooCommerce product lookup rows','detail'=>(int)$wc['consistency']['missing_product_lookup'].' product/variation rows are missing from wc_product_meta_lookup. Queue WooCommerce’s official regeneration.','target'=>'wc_product_meta_lookup','confidence'=>99,'risk'=>'medium','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'wc-product-lookup'));
			}
			if ( ! empty($wc['scheduled_actions']['due_pending']) ) {
				$items[] = array('id'=>'as-run-queue','kind'=>'woocommerce','label'=>'Run Action Scheduler queue now','detail'=>(int)$wc['scheduled_actions']['due_pending'].' pending jobs are due. Runs through Action Scheduler’s queue hook; rows are not manually marked complete.','target'=>'Action Scheduler','confidence'=>97,'risk'=>'low','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'as-run-queue'));
			}
			if ( ! empty($wc['scheduled_actions']['old_history']) && (int)$wc['scheduled_actions']['old_history'] > 1000 ) {
				$items[] = array('id'=>'as-clean-history','kind'=>'database','label'=>'Clean old Action Scheduler history','detail'=>(int)$wc['scheduled_actions']['old_history'].' completed/canceled jobs older than 30 days. Deletes at most 1,000 per run, including their logs.','target'=>$wpdb->prefix.'actionscheduler_actions','confidence'=>99,'risk'=>'medium','reversible'=>false,'batch_safe'=>false,'action'=>'fix','fields'=>array('fix'=>'as-clean-history'));
			}
		}

		usort( $items, function( $a, $b ) { if ( $a['batch_safe'] !== $b['batch_safe'] ) return $a['batch_safe'] ? -1 : 1; return (int)$b['confidence'] <=> (int)$a['confidence']; } );
		return $items;
	}
	private static function maintenance( $id, $label, $detail, $target, $confidence, $batch_safe, $fix ) { return array('id'=>$id,'kind'=>'maintenance','label'=>$label,'detail'=>$detail,'target'=>$target,'confidence'=>$confidence,'risk'=>'low','reversible'=>false,'batch_safe'=>$batch_safe,'action'=>'fix','fields'=>array('fix'=>$fix)); }
	public static function apply_candidate( $c ) {
		if ( ! is_array($c) || empty($c['action']) ) return new WP_Error('fixpilot_candidate_invalid',__('Invalid repair candidate.','wp-fixpilot'));
		if ( 'code' === $c['kind'] ) return WP_FixPilot_Repair_Engine::repair_issue_by_id((int)$c['fields']['issue_id']);
		if ( 'repair_autoload' === $c['action'] ) return WP_FixPilot_Fixer::disable_autoload_for_option($c['fields']['option_name']);
		if ( 'fix' === $c['action'] && ! empty($c['fields']['fix']) ) return WP_FixPilot_Fixer::run_named_fix($c['fields']['fix']);
		return new WP_Error('fixpilot_candidate_unknown',__('Unsupported repair candidate action.','wp-fixpilot'));
	}
	public static function safe_batch() {
		$settings = get_option( 'wp_fixpilot_settings', array() ); $results = array();
		foreach ( self::candidates() as $c ) {
			if ( empty( $c['batch_safe'] ) ) continue;
			if ( 'code' === $c['kind'] && empty( $settings['allow_code_repairs'] ) ) continue;
			$r=self::apply_candidate($c);
			$results[] = array('id'=>$c['id'],'ok'=>!is_wp_error($r) && false!==$r,'message'=>is_wp_error($r)?$r->get_error_message():(is_bool($r)?($r?'success':'failed'):(is_scalar($r)?(string)$r:'applied')));
		}
		update_option('wp_fixpilot_last_batch_repair',array('time'=>time(),'results'=>$results),false); return $results;
	}
}
