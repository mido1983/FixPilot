<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Plugin_Analyzer {
	public static function analyze() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all = get_plugins(); $active = array_flip( (array) get_option( 'active_plugins', array() ) ); $updates = get_site_transient( 'update_plugins' ); $issue_counts = self::issue_counts_by_plugin(); $runtime = self::runtime_costs(); $results = array();
		foreach ( $all as $file => $data ) {
			$slug = self::slug( $file ); $score = 0; $flags = array(); $caps = self::detect_capabilities( $file );
			$is_active = isset( $active[ $file ] ) || is_plugin_active_for_network( $file );
			if ( ! $is_active ) { $score += 5; $flags[] = __( 'Inactive', 'wp-fixpilot' ); }
			if ( is_object( $updates ) && isset( $updates->response[ $file ] ) ) { $score += 15; $flags[] = __( 'Update available', 'wp-fixpilot' ); }
			$requires_php = isset( $data['RequiresPHP'] ) ? $data['RequiresPHP'] : '';
			if ( $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) { $score += 50; $flags[] = __( 'PHP requirement mismatch', 'wp-fixpilot' ); }
			$issues = isset( $issue_counts[ $slug ] ) ? (int) $issue_counts[ $slug ] : 0;
			if ( $issues > 0 ) { $score += min( 50, 10 + (int) floor( log( $issues + 1, 2 ) * 5 ) ); $flags[] = sprintf( __( '%d captured PHP issues', 'wp-fixpilot' ), $issues ); }
			$rc=isset($runtime[$slug])?$runtime[$slug]:array('score'=>0,'total_ms'=>0,'sql_ms'=>0,'http_ms'=>0,'slow_sql_count'=>0,'http_count'=>0); if($rc['score']>=35){$score+=min(35,(int)round($rc['score']/3));$flags[]=sprintf(__('Measured runtime cost: %s ms','wp-fixpilot'),$rc['total_ms']);} $results[] = array( 'file' => $file, 'slug' => $slug, 'name' => $data['Name'], 'version' => $data['Version'], 'active' => $is_active, 'risk_score' => min( 100, $score ), 'flags' => $flags, 'capabilities' => $caps, 'php_issues' => $issues, 'runtime_cost'=>$rc );
		}
		$duplicates = self::duplicate_capabilities( $results );
		foreach ( $results as &$plugin ) { $plugin['duplicate_capabilities'] = isset( $duplicates[ $plugin['file'] ] ) ? $duplicates[ $plugin['file'] ] : array(); if ( $plugin['duplicate_capabilities'] ) { $plugin['risk_score'] = min( 100, $plugin['risk_score'] + 10 ); $plugin['flags'][] = __( 'Overlapping capabilities detected', 'wp-fixpilot' ); } } unset( $plugin );
		usort( $results, function( $a, $b ){ return $b['risk_score'] <=> $a['risk_score']; } ); return $results;
	}

	private static function runtime_costs(){
		$profiles=get_option('wp_fixpilot_request_profiles',array()); $agg=WP_FixPilot_Runtime_Attribution::aggregate($profiles); $out=array();
		foreach($agg as $r){if('plugin'===$r['type'])$out[$r['slug']]=$r;} return $out;
	}
	private static function slug( $file ) { $slug = dirname( $file ); return '.' === $slug ? basename( $file, '.php' ) : $slug; }
	private static function detect_capabilities( $file ) {
		$path = WP_PLUGIN_DIR . '/' . $file; if ( ! is_readable( $path ) ) { return array(); } $code = file_get_contents( $path, false, null, 0, 300000 ); if ( false === $code ) { return array(); }
		$rules = array( 'seo' => array( 'wp_head', 'sitemap', 'seo' ), 'cache' => array( 'cache', 'page_cache', 'object-cache' ), 'smtp' => array( 'phpmailer_init', 'smtp' ), 'security' => array( 'firewall', 'security', 'login_attempt' ), 'analytics' => array( 'google_analytics', 'gtag', 'analytics' ), 'backup' => array( 'backup', 'restore' ), 'image-optimization' => array( 'webp', 'image optimization', 'image_optimizer' ), 'payment-gateway' => array( 'woocommerce_payment_gateways', 'WC_Payment_Gateway' ) );
		$out = array(); foreach ( $rules as $cap => $needles ) { foreach ( $needles as $needle ) { if ( false !== stripos( $code, $needle ) ) { $out[] = $cap; break; } } } return array_values( array_unique( $out ) );
	}
	private static function duplicate_capabilities( $plugins ) {
		$by_cap = array(); foreach ( $plugins as $p ) { if ( ! $p['active'] ) { continue; } foreach ( $p['capabilities'] as $cap ) { $by_cap[ $cap ][] = $p['file']; } }
		$out = array(); foreach ( $by_cap as $cap => $files ) { if ( count( $files ) < 2 ) { continue; } foreach ( $files as $file ) { $out[ $file ][] = $cap; } } return $out;
	}
	public static function issue_counts_by_plugin() { $issues = WP_FixPilot_Error_Monitor::recent_issues( 500 ); $counts = array(); foreach ( $issues as $issue ) { if ( 0 === strpos( $issue['source'], 'plugin:' ) ) { $slug = substr( $issue['source'], 7 ); if ( ! isset( $counts[ $slug ] ) ) { $counts[ $slug ] = 0; } $counts[ $slug ] += (int) $issue['occurrences']; } } return $counts; }
}
