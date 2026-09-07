<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Request_Profiler {
	private static $active = false;
	private static $started = 0.0;
	private static $http = array();
	private static $query_sources = array();
	private static $key = '';
	public static function bootstrap() {
		if ( empty( $_GET['wp_fixpilot_profile'] ) || empty( $_GET['wp_fixpilot_ts'] ) || empty( $_GET['wp_fixpilot_sig'] ) ) { return; }
		$profile = sanitize_key( wp_unslash( $_GET['wp_fixpilot_profile'] ) );
		$ts = absint( $_GET['wp_fixpilot_ts'] );
		$sig = sanitize_text_field( wp_unslash( $_GET['wp_fixpilot_sig'] ) );
		if ( ! $profile || abs( time() - $ts ) > 180 ) { return; }
		$expected = hash_hmac( 'sha256', $profile . '|' . $ts, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $sig ) ) { return; }
		self::$active = true; self::$started = microtime( true ); self::$key = 'wp_fixpilot_profile_' . md5( $profile . '|' . $ts ); if(class_exists('WP_FixPilot_Hook_Profiler')) WP_FixPilot_Hook_Profiler::bootstrap();
		if ( ! defined( 'SAVEQUERIES' ) ) { define( 'SAVEQUERIES', true ); }
		add_filter( 'http_request_args', array( __CLASS__, 'http_args' ), 999, 2 );
		add_filter( 'query', array( __CLASS__, 'capture_query_source' ), 999 );
		add_action( 'http_api_debug', array( __CLASS__, 'http_debug' ), 10, 5 );
		add_action( 'shutdown', array( __CLASS__, 'finish' ), PHP_INT_MAX );
	}
	public static function is_active(){ return self::$active; }
	public static function current_key(){ return self::$key; }
	public static function capture_query_source( $sql ) {
		if ( self::$active ) { $hash=md5((string)$sql); if(!isset(self::$query_sources[$hash])) self::$query_sources[$hash]=WP_FixPilot_Runtime_Attribution::from_trace(); }
		return $sql;
	}
	public static function http_args( $args, $url ) { if ( self::$active ) { $args['wp_fixpilot_started'] = microtime( true ); $args['wp_fixpilot_source']=WP_FixPilot_Runtime_Attribution::from_trace(); } return $args; }
	public static function http_debug( $response, $context, $class, $parsed_args, $url ) {
		if ( ! self::$active || 'response' !== $context ) { return; }
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$ms = ! empty( $parsed_args['wp_fixpilot_started'] ) ? round( ( microtime( true ) - (float) $parsed_args['wp_fixpilot_started'] ) * 1000, 2 ) : null;
		self::$http[] = array( 'host' => (string) wp_parse_url( $url, PHP_URL_HOST ), 'url' => esc_url_raw( $url ), 'code' => $code, 'ms' => $ms, 'error' => is_wp_error( $response ) ? $response->get_error_message() : '', 'source'=>!empty($parsed_args['wp_fixpilot_source'])?$parsed_args['wp_fixpilot_source']:array('type'=>'unknown','slug'=>'unknown','label'=>'Unknown') );
	}
	public static function finish() {
		if ( ! self::$active ) { return; }
		global $wpdb;
		$queries = array(); $total_sql = 0.0; $slow = array(); $normalized = array();
		if ( ! empty( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
			foreach ( $wpdb->queries as $row ) {
				$sql = isset( $row[0] ) ? (string) $row[0] : ''; $sec = isset( $row[1] ) ? (float) $row[1] : 0; $caller = isset( $row[2] ) ? (string) $row[2] : '';
				$total_sql += $sec; $ms = round( $sec * 1000, 2 );
				$norm = self::normalize_sql( $sql ); $qsrc=isset(self::$query_sources[md5($sql)])?self::$query_sources[md5($sql)]:array('type'=>'unknown','slug'=>'unknown','label'=>'Unknown'); if ( ! isset( $normalized[$norm] ) ) { $normalized[$norm] = array( 'count'=>0, 'total_ms'=>0, 'source'=>$qsrc ); } $normalized[$norm]['count']++; $normalized[$norm]['total_ms'] += $ms;
				if ( $ms >= 25 ) { $slow[] = array( 'ms'=>$ms, 'sql'=>self::shorten( $sql, 500 ), 'caller'=>self::shorten( $caller, 250 ), 'source'=>isset(self::$query_sources[md5($sql)])?self::$query_sources[md5($sql)]:array('type'=>'unknown','slug'=>'unknown','label'=>'Unknown') ); }
			}
		}
		$n1 = array(); foreach ( $normalized as $pattern=>$x ) { if ( $x['count'] >= 8 ) { $n1[] = array( 'count'=>$x['count'], 'total_ms'=>round($x['total_ms'],2), 'pattern'=>self::shorten($pattern,350), 'source'=>$x['source'] ); } }
		usort( $slow, function($a,$b){return $b['ms'] <=> $a['ms'];} ); usort( $n1, function($a,$b){return $b['count'] <=> $a['count'];} );
		$data = array(
			'generated_at'=>gmdate('c'), 'total_ms'=>round((microtime(true)-self::$started)*1000,2), 'peak_memory'=>memory_get_peak_usage(true),
			'query_count'=>is_array($wpdb->queries)?count($wpdb->queries):0, 'sql_ms'=>round($total_sql*1000,2), 'slow_queries'=>array_slice($slow,0,20), 'repeated_queries'=>array_slice($n1,0,20),
			'http_count'=>count(self::$http), 'http_calls'=>array_slice(self::$http,0,30),
		);
		set_transient( self::$key, $data, 5 * MINUTE_IN_SECONDS );
	}
	private static function normalize_sql( $sql ) {
		$sql = preg_replace( "/'(?:''|[^'])*'/", '?', $sql ); $sql = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql ); $sql = preg_replace( '/\s+/', ' ', trim( $sql ) ); return $sql;
	}
	private static function shorten($s,$n){return strlen($s)>$n?substr($s,0,$n-3).'...':$s;}
	public static function profile_url( $url, $profile ) {
		$profile = sanitize_key( $profile ); $ts = time(); $sig = hash_hmac( 'sha256', $profile . '|' . $ts, wp_salt( 'auth' ) );
		$target = add_query_arg( array( 'wp_fixpilot_profile'=>$profile, 'wp_fixpilot_ts'=>$ts, 'wp_fixpilot_sig'=>$sig ), $url );
		$started=microtime(true); $response=wp_remote_get($target,array('timeout'=>25,'redirection'=>3,'sslverify'=>apply_filters('https_local_ssl_verify',false),'headers'=>array('X-WP-FixPilot'=>'request-profiler'))); $outer=round((microtime(true)-$started)*1000,2);
		$key='wp_fixpilot_profile_'.md5($profile.'|'.$ts); $data=get_transient($key); $hooks=get_transient($key.'_hooks'); delete_transient($key); delete_transient($key.'_hooks');
		if(is_wp_error($response)){return array('error'=>$response->get_error_message(),'outer_ms'=>$outer);}
		if(!is_array($data)){$data=array();} $data['hook_callbacks']=is_array($hooks)?$hooks:array(); $data['http_code']=(int)wp_remote_retrieve_response_code($response); $data['outer_ms']=$outer; $data['response_bytes']=strlen((string)wp_remote_retrieve_body($response)); return $data;
	}
	public static function run_suite() {
		$targets=array('home'=>home_url('/'),'rest'=>rest_url(),'ajax'=>admin_url('admin-ajax.php?action=wp_fixpilot_probe'));
		if(class_exists('WooCommerce')){if(function_exists('wc_get_page_permalink')){foreach(array('shop','cart','checkout') as $p){$u=wc_get_page_permalink($p);if($u&&'about:blank'!==$u)$targets['wc_'.$p]=$u;}} $pid=self::sample_product_id(); if($pid){$targets['wc_product']=get_permalink($pid);$terms=wp_get_post_terms($pid,'product_cat');if(!is_wp_error($terms)&&!empty($terms[0])){$tu=get_term_link($terms[0]);if(!is_wp_error($tu))$targets['wc_category']=$tu;}}}
		$out=array('generated_at'=>gmdate('c'),'targets'=>array()); foreach($targets as $name=>$url){$out['targets'][$name]=self::profile_url($url,$name.'-'.wp_generate_password(8,false,false));} return $out;
	}

	private static function sample_product_id(){
		$q=new WP_Query(array('post_type'=>'product','post_status'=>'publish','posts_per_page'=>1,'fields'=>'ids','orderby'=>'date','order'=>'DESC','no_found_rows'=>true,'update_post_meta_cache'=>false,'update_post_term_cache'=>false));
		return !empty($q->posts[0])?(int)$q->posts[0]:0;
	}
	public static function runtime_registry() {
		global $wp_filter, $wp_rewrite;
		$ajax=array('priv'=>0,'nopriv'=>0); if(is_array($wp_filter)){foreach(array_keys($wp_filter) as $hook){if(0===strpos($hook,'wp_ajax_nopriv_'))$ajax['nopriv']++;elseif(0===strpos($hook,'wp_ajax_'))$ajax['priv']++;}}
		$routes=0; if(function_exists('rest_get_server')){$server=rest_get_server();$r=$server->get_routes();$routes=is_array($r)?count($r):0;}
		return array('ajax_private_actions'=>$ajax['priv'],'ajax_public_actions'=>$ajax['nopriv'],'rest_routes'=>$routes);
	}
}
