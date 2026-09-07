<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_FixPilot_Update_Guard {
	public static function init() {
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'before_install' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
	}

	public static function before_install( $response, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) { return $response; }
		$plugin = sanitize_text_field( $hook_extra['plugin'] );
		self::capture_baseline( $plugin );
		return $response;
	}

	public static function capture_baseline( $plugin ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$data = isset( $plugins[ $plugin ] ) ? $plugins[ $plugin ] : array();
		$baseline = array(
			'plugin' => $plugin,
			'name' => isset( $data['Name'] ) ? $data['Name'] : $plugin,
			'version' => isset( $data['Version'] ) ? $data['Version'] : '',
			'captured_at' => gmdate( 'c' ),
			'benchmark' => WP_FixPilot_Benchmark::run(),
			'issue_total' => self::issue_total_for_plugin( $plugin ),
		);
		update_option( 'wp_fixpilot_update_guard_pending', $baseline, false );
		return $baseline;
	}

	public static function after_upgrade( $upgrader, $hook_extra ) {
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugins'] ) && empty( $hook_extra['plugin'] ) ) { return; }
		$pending = get_option( 'wp_fixpilot_update_guard_pending', array() );
		if ( empty( $pending['plugin'] ) ) { return; }
		$updated = ! empty( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array( $hook_extra['plugin'] );
		if ( ! in_array( $pending['plugin'], $updated, true ) ) { return; }
		self::compare_pending();
	}

	public static function compare_pending() {
		$before = get_option( 'wp_fixpilot_update_guard_pending', array() );
		if ( empty( $before['plugin'] ) ) { return new WP_Error( 'fixpilot_no_update_baseline', __( 'No Update Guard baseline is waiting for comparison.', 'wp-fixpilot' ) ); }
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$data = isset( $plugins[ $before['plugin'] ] ) ? $plugins[ $before['plugin'] ] : array();
		$after_benchmark = WP_FixPilot_Benchmark::run();
		$after_issues = self::issue_total_for_plugin( $before['plugin'] );
		$before_home = isset( $before['benchmark']['home']['ms'] ) ? (float) $before['benchmark']['home']['ms'] : 0;
		$after_home = isset( $after_benchmark['home']['ms'] ) ? (float) $after_benchmark['home']['ms'] : 0;
		$delta = $before_home > 0 && $after_home > 0 ? round( $after_home - $before_home, 1 ) : null;
		$status = 'pass';
		$signals = array();
		if ( null !== $delta && $delta >= 300 ) { $status = 'warning'; $signals[] = sprintf( __( 'Homepage loopback became %s ms slower.', 'wp-fixpilot' ), $delta ); }
		if ( $after_issues > (int) $before['issue_total'] ) { $status = 'warning'; $signals[] = sprintf( __( 'Captured PHP issue count increased from %1$d to %2$d.', 'wp-fixpilot' ), (int) $before['issue_total'], $after_issues ); }
		foreach ( $after_benchmark as $name => $row ) { if ( 'generated_at' === $name ) { continue; } if ( isset( $row['code'] ) && (int) $row['code'] >= 500 ) { $status = 'failed'; $signals[] = sprintf( __( '%1$s returned HTTP %2$d.', 'wp-fixpilot' ), $name, (int) $row['code'] ); } }
		$result = array( 'generated_at' => gmdate( 'c' ), 'plugin' => $before['plugin'], 'name' => $before['name'], 'before_version' => $before['version'], 'after_version' => isset( $data['Version'] ) ? $data['Version'] : '', 'status' => $status, 'signals' => $signals, 'before' => $before, 'after' => array( 'benchmark' => $after_benchmark, 'issue_total' => $after_issues ), 'home_delta_ms' => $delta );
		$history = (array) get_option( 'wp_fixpilot_update_guard_history', array() );
		array_unshift( $history, $result );
		update_option( 'wp_fixpilot_update_guard_history', array_slice( $history, 0, 30 ), false );
		delete_option( 'wp_fixpilot_update_guard_pending' );
		return $result;
	}

	private static function issue_total_for_plugin( $plugin ) {
		$slug = dirname( $plugin );
		if ( '.' === $slug ) { $slug = basename( $plugin, '.php' ); }
		$counts = WP_FixPilot_Plugin_Analyzer::issue_counts_by_plugin();
		return isset( $counts[ $slug ] ) ? (int) $counts[ $slug ] : 0;
	}
}
