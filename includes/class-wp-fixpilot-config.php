<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_FixPilot_Config {
	const OPTION = 'wp_fixpilot_runtime_config';

	public static function bootstrap() {
		$config = self::get();
		if ( ! empty( $config['production_hide_errors'] ) && 'production' === wp_get_environment_type() ) {
			@ini_set( 'display_errors', '0' );
		}
		if ( ! empty( $config['heartbeat_interval'] ) ) {
			add_filter( 'heartbeat_settings', array( __CLASS__, 'heartbeat_settings' ), 50 );
		}
		add_action( 'wp_fixpilot_daily_wc_session_cleanup', array( __CLASS__, 'daily_wc_session_cleanup' ) );
	}

	public static function get() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), array(
			'production_hide_errors' => 0,
			'heartbeat_interval' => 0,
			'wc_session_fallback' => 0,
		) );
	}

	public static function heartbeat_settings( $settings ) {
		$config = self::get();
		$interval = absint( $config['heartbeat_interval'] );
		if ( $interval >= 15 && $interval <= 120 ) {
			$settings['interval'] = $interval;
		}
		return $settings;
	}

	public static function analyze() {
		$config = self::get();
		$display_errors = strtolower( (string) ini_get( 'display_errors' ) );
		$display_on = in_array( $display_errors, array( '1', 'on', 'yes', 'true' ), true );
		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$debug_display = defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : null;
		$disable_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$alt_cron = defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON;
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		$object_dropin = file_exists( WP_CONTENT_DIR . '/object-cache.php' );
		$advanced_cache = file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );
		$wc_cleanup_scheduled = wp_next_scheduled( 'woocommerce_cleanup_sessions' );
		$our_wc_cleanup_scheduled = wp_next_scheduled( 'wp_fixpilot_daily_wc_session_cleanup' );
		return array(
			'environment' => $env,
			'wp_debug' => $debug,
			'wp_debug_display' => $debug_display,
			'display_errors' => $display_on,
			'disable_wp_cron' => $disable_cron,
			'alternate_wp_cron' => $alt_cron,
			'object_cache_runtime' => wp_using_ext_object_cache(),
			'object_cache_dropin' => $object_dropin,
			'advanced_cache_dropin' => $advanced_cache,
			'heartbeat_interval' => (int) $config['heartbeat_interval'],
			'production_hide_errors' => ! empty( $config['production_hide_errors'] ),
			'wc_session_fallback' => ! empty( $config['wc_session_fallback'] ),
			'wc_cleanup_scheduled' => $wc_cleanup_scheduled ? (int) $wc_cleanup_scheduled : 0,
			'fixpilot_wc_cleanup_scheduled' => $our_wc_cleanup_scheduled ? (int) $our_wc_cleanup_scheduled : 0,
		);
	}

	public static function set_production_error_guard( $enabled ) {
		$config = self::get();
		$before = ! empty( $config['production_hide_errors'] );
		$config['production_hide_errors'] = $enabled ? 1 : 0;
		update_option( self::OPTION, $config, false );
		self::log_change( 'production_hide_errors', $before ? 1 : 0, $enabled ? 1 : 0 );
		if ( $enabled && 'production' === wp_get_environment_type() ) @ini_set( 'display_errors', '0' );
		return true;
	}

	public static function set_heartbeat_interval( $interval ) {
		$interval = absint( $interval );
		if ( 0 !== $interval && ( $interval < 15 || $interval > 120 ) ) {
			return new WP_Error( 'fixpilot_heartbeat_invalid', __( 'Heartbeat interval must be 0 or between 15 and 120 seconds.', 'wp-fixpilot' ) );
		}
		$config = self::get();
		$before = (int) $config['heartbeat_interval'];
		$config['heartbeat_interval'] = $interval;
		update_option( self::OPTION, $config, false );
		self::log_change( 'heartbeat_interval', $before, $interval );
		return true;
	}

	public static function set_wc_session_fallback( $enabled ) {
		$config = self::get();
		$before = ! empty( $config['wc_session_fallback'] );
		$config['wc_session_fallback'] = $enabled ? 1 : 0;
		update_option( self::OPTION, $config, false );
		if ( $enabled ) {
			if ( ! wp_next_scheduled( 'woocommerce_cleanup_sessions' ) && ! wp_next_scheduled( 'wp_fixpilot_daily_wc_session_cleanup' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_fixpilot_daily_wc_session_cleanup' );
			}
		} else {
			wp_clear_scheduled_hook( 'wp_fixpilot_daily_wc_session_cleanup' );
		}
		self::log_change( 'wc_session_fallback', $before ? 1 : 0, $enabled ? 1 : 0 );
		return true;
	}

	public static function daily_wc_session_cleanup() {
		$config = self::get();
		if ( empty( $config['wc_session_fallback'] ) ) return;
		if ( wp_next_scheduled( 'woocommerce_cleanup_sessions' ) ) return;
		if ( function_exists( 'wc_cleanup_session_data' ) ) wc_cleanup_session_data();
	}

	public static function restore_change( $id ) {
		$history = (array) get_option( 'wp_fixpilot_config_repairs', array() );
		foreach ( $history as $entry ) {
			if ( empty( $entry['id'] ) || ! hash_equals( (string) $entry['id'], (string) $id ) ) continue;
			switch ( $entry['key'] ) {
				case 'production_hide_errors': return self::set_production_error_guard( ! empty( $entry['before'] ) );
				case 'heartbeat_interval': return self::set_heartbeat_interval( (int) $entry['before'] );
				case 'wc_session_fallback': return self::set_wc_session_fallback( ! empty( $entry['before'] ) );
			}
		}
		return new WP_Error( 'fixpilot_config_repair_missing', __( 'Config repair record was not found.', 'wp-fixpilot' ) );
	}

	private static function log_change( $key, $before, $after ) {
		$history = (array) get_option( 'wp_fixpilot_config_repairs', array() );
		array_unshift( $history, array(
			'id' => 'config-' . wp_generate_uuid4(),
			'time' => gmdate( 'c' ),
			'key' => $key,
			'before' => $before,
			'after' => $after,
		) );
		update_option( 'wp_fixpilot_config_repairs', array_slice( $history, 0, 100 ), false );
	}
}
