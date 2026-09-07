<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Safe_Mode {
	const COOKIE = 'wp_fixpilot_safe_mode';
	public static function install_loader() {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		if ( ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) { return new WP_Error( 'fixpilot_mu_unwritable', __( 'mu-plugins directory is not writable.', 'wp-fixpilot' ) ); }
		$file = trailingslashit( $dir ) . 'wp-fixpilot-safe-mode.php';
		$code = <<<'PHPLOADER'
<?php
/** WP FixPilot per-browser Safe Mode and signed loopback probe loader. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
function wp_fixpilot_safe_mode_config() {
    $token = '';
    if ( isset( $_GET['wp_fixpilot_probe'] ) ) {
        $token = sanitize_text_field( wp_unslash( $_GET['wp_fixpilot_probe'] ) );
    } elseif ( isset( $_COOKIE['wp_fixpilot_safe_mode'] ) ) {
        $token = sanitize_text_field( wp_unslash( $_COOKIE['wp_fixpilot_safe_mode'] ) );
    }
    if ( ! $token ) { return false; }
    $sessions = get_option( 'wp_fixpilot_safe_mode_sessions', array() );
    if ( empty( $sessions[ $token ] ) || empty( $sessions[ $token ]['expires'] ) || $sessions[ $token ]['expires'] < time() ) { return false; }
    return $sessions[ $token ];
}
function wp_fixpilot_safe_mode_filter_plugins( $plugins ) {
    $cfg = wp_fixpilot_safe_mode_config(); if ( ! $cfg ) { return $plugins; }
    $mode = isset( $cfg['mode'] ) ? $cfg['mode'] : 'keep';
    $items = isset( $cfg['items'] ) ? (array) $cfg['items'] : ( isset( $cfg['keep'] ) ? (array) $cfg['keep'] : array() );
    return array_values( array_filter( (array) $plugins, function( $plugin ) use ( $mode, $items ) {
        if ( false !== strpos( $plugin, 'wp-fixpilot/' ) ) { return true; }
        if ( 'exclude' === $mode ) { return ! in_array( $plugin, $items, true ); }
        return in_array( $plugin, $items, true );
    } ) );
}
add_filter( 'option_active_plugins', 'wp_fixpilot_safe_mode_filter_plugins', 1 );
add_filter( 'site_option_active_sitewide_plugins', function( $plugins ) {
    $cfg = wp_fixpilot_safe_mode_config(); if ( ! $cfg ) { return $plugins; }
    $mode = isset( $cfg['mode'] ) ? $cfg['mode'] : 'keep';
    $items = isset( $cfg['items'] ) ? (array) $cfg['items'] : ( isset( $cfg['keep'] ) ? (array) $cfg['keep'] : array() );
    foreach ( array_keys( (array) $plugins ) as $plugin ) {
        if ( false !== strpos( $plugin, 'wp-fixpilot/' ) ) { continue; }
        if ( 'exclude' === $mode && in_array( $plugin, $items, true ) ) { unset( $plugins[ $plugin ] ); }
        elseif ( 'exclude' !== $mode && ! in_array( $plugin, $items, true ) ) { unset( $plugins[ $plugin ] ); }
    }
    return $plugins;
}, 1 );
PHPLOADER;
		if ( false === file_put_contents( $file, $code, LOCK_EX ) ) { return new WP_Error( 'fixpilot_mu_write', __( 'Could not install Safe Mode loader.', 'wp-fixpilot' ) ); }
		return true;
	}
	public static function start( $keep = array() ) {
		$token = self::create_session( 'keep', $keep, 2 * HOUR_IN_SECONDS, get_current_user_id() );
		if ( is_wp_error( $token ) ) { return $token; }
		setcookie( self::COOKIE, $token, time() + 2 * HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ self::COOKIE ] = $token;
		return $token;
	}
	public static function create_probe_session( $exclude = array() ) { return self::create_session( 'exclude', $exclude, 5 * MINUTE_IN_SECONDS, get_current_user_id() ); }
	private static function create_session( $mode, $items, $ttl, $user_id ) {
		$installed = self::install_loader(); if ( is_wp_error( $installed ) ) { return $installed; }
		$token = wp_generate_password( 40, false, false );
		$sessions = (array) get_option( 'wp_fixpilot_safe_mode_sessions', array() );
		$sessions[ $token ] = array( 'created' => time(), 'expires' => time() + absint( $ttl ), 'user_id' => (int) $user_id, 'mode' => in_array( $mode, array( 'keep', 'exclude' ), true ) ? $mode : 'keep', 'items' => array_values( array_map( 'sanitize_text_field', (array) $items ) ) );
		foreach ( $sessions as $key => $session ) { if ( empty( $session['expires'] ) || $session['expires'] < time() ) { unset( $sessions[ $key ] ); } }
		update_option( 'wp_fixpilot_safe_mode_sessions', $sessions, false );
		return $token;
	}
	public static function destroy_session( $token ) { $sessions = (array) get_option( 'wp_fixpilot_safe_mode_sessions', array() ); if ( isset( $sessions[ $token ] ) ) { unset( $sessions[ $token ] ); update_option( 'wp_fixpilot_safe_mode_sessions', $sessions, false ); } return true; }
	public static function stop() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( $token ) { self::destroy_session( $token ); }
		setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true ); unset( $_COOKIE[ self::COOKIE ] ); return true;
	}
	public static function active() {
		$token = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$sessions = (array) get_option( 'wp_fixpilot_safe_mode_sessions', array() );
		return $token && ! empty( $sessions[ $token ] ) && ! empty( $sessions[ $token ]['expires'] ) && $sessions[ $token ]['expires'] >= time();
	}
}
