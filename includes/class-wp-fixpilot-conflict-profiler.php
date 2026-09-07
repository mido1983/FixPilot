<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_FixPilot_Conflict_Profiler {
	const MAX_PLUGINS = 20;

	public static function run() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active = array_values( (array) get_option( 'active_plugins', array() ) );
		$self = plugin_basename( WP_FIXPILOT_FILE );
		$active = array_values( array_filter( $active, function( $plugin ) use ( $self ) { return $plugin !== $self; } ) );
		$active = array_slice( $active, 0, self::MAX_PLUGINS );
		$baseline = self::request( home_url( '/' ) );
		if ( is_wp_error( $baseline ) ) { return $baseline; }

		$all = get_plugins();
		$rows = array();
		foreach ( $active as $plugin ) {
			$token = WP_FixPilot_Safe_Mode::create_probe_session( array( $plugin ) );
			if ( is_wp_error( $token ) ) { continue; }
			$probe_url = add_query_arg( 'wp_fixpilot_probe', rawurlencode( $token ), home_url( '/' ) );
			$probe = self::request( $probe_url );
			WP_FixPilot_Safe_Mode::destroy_session( $token );
			if ( is_wp_error( $probe ) ) {
				$rows[] = array( 'file' => $plugin, 'name' => isset( $all[ $plugin ]['Name'] ) ? $all[ $plugin ]['Name'] : $plugin, 'status' => 'error', 'error' => $probe->get_error_message(), 'baseline_ms' => $baseline['ms'], 'without_ms' => null, 'delta_ms' => null, 'impact' => 'unknown' );
				continue;
			}
			$delta = round( $baseline['ms'] - $probe['ms'], 1 );
			$impact = 'neutral';
			if ( $delta >= 300 ) { $impact = 'high'; }
			elseif ( $delta >= 120 ) { $impact = 'medium'; }
			elseif ( $delta >= 50 ) { $impact = 'low'; }
			elseif ( $delta <= -150 ) { $impact = 'negative-signal'; }
			$rows[] = array(
				'file' => $plugin,
				'name' => isset( $all[ $plugin ]['Name'] ) ? $all[ $plugin ]['Name'] : $plugin,
				'status' => 'ok',
				'baseline_ms' => $baseline['ms'],
				'without_ms' => $probe['ms'],
				'delta_ms' => $delta,
				'impact' => $impact,
				'http_code' => $probe['code'],
			);
		}
		usort( $rows, function( $a, $b ) { return (float) ( $b['delta_ms'] ?? -999999 ) <=> (float) ( $a['delta_ms'] ?? -999999 ); } );
		$result = array( 'generated_at' => gmdate( 'c' ), 'baseline' => $baseline, 'tested' => count( $rows ), 'total_active' => count( (array) get_option( 'active_plugins', array() ) ), 'rows' => $rows );
		update_option( 'wp_fixpilot_conflict_profile', $result, false );
		return $result;
	}

	private static function request( $url ) {
		$start = microtime( true );
		$r = wp_remote_get( $url, array( 'timeout' => 12, 'redirection' => 3, 'user-agent' => 'WP FixPilot Conflict Profiler/' . WP_FIXPILOT_VERSION, 'headers' => array( 'Cache-Control' => 'no-cache' ) ) );
		$ms = round( ( microtime( true ) - $start ) * 1000, 1 );
		if ( is_wp_error( $r ) ) { return $r; }
		return array( 'ms' => $ms, 'code' => (int) wp_remote_retrieve_response_code( $r ), 'bytes' => strlen( (string) wp_remote_retrieve_body( $r ) ) );
	}
}
