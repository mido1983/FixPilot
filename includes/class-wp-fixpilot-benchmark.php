<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Benchmark {
	public static function run() {
		$targets = array( 'home' => home_url( '/' ) );
		if ( class_exists( 'WooCommerce' ) ) {
			$targets['shop'] = wc_get_page_permalink( 'shop' );
			$targets['cart'] = wc_get_cart_url();
			$targets['checkout'] = wc_get_checkout_url();
		}
		$results = array();
		foreach ( array_filter( $targets ) as $name => $url ) {
			$start = microtime( true );
			$r = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 3, 'user-agent' => 'WP FixPilot Benchmark/' . WP_FIXPILOT_VERSION ) );
			$elapsed = round( ( microtime( true ) - $start ) * 1000, 1 );
			$results[ $name ] = is_wp_error( $r ) ? array( 'url' => $url, 'ok' => false, 'error' => $r->get_error_message(), 'ms' => $elapsed ) : array( 'url' => $url, 'ok' => (int) wp_remote_retrieve_response_code( $r ) < 500, 'code' => (int) wp_remote_retrieve_response_code( $r ), 'ms' => $elapsed, 'bytes' => strlen( (string) wp_remote_retrieve_body( $r ) ) );
		}
		$results['generated_at'] = gmdate( 'c' );
		return $results;
	}
}
