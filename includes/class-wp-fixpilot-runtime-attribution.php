<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Runtime_Attribution {
	public static function from_trace( $trace = null ) {
		if ( null === $trace ) { $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ); }
		$best = array( 'type'=>'core', 'slug'=>'wordpress-core', 'label'=>'WordPress Core', 'file'=>'' );
		foreach ( (array) $trace as $frame ) {
			$file = ! empty( $frame['file'] ) ? wp_normalize_path( $frame['file'] ) : '';
			if ( ! $file ) { continue; }
			$src = self::from_file( $file );
			if ( 'plugin' === $src['type'] && 'wp-fixpilot' === $src['slug'] ) { continue; }
			if ( 'plugin' === $src['type'] || 'theme' === $src['type'] || 'mu-plugin' === $src['type'] ) { return $src; }
			if ( 'core' !== $src['type'] ) { $best = $src; }
		}
		return $best;
	}
	public static function from_file( $file ) {
		$file = wp_normalize_path( $file );
		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$mu_dir = defined('WPMU_PLUGIN_DIR') ? wp_normalize_path( WPMU_PLUGIN_DIR ) . '/' : '';
		$theme_root = wp_normalize_path( get_theme_root() ) . '/';
		if ( 0 === strpos( $file, $plugin_dir ) ) {
			$rel = substr( $file, strlen( $plugin_dir ) ); $parts = explode( '/', $rel ); $slug = $parts[0];
			return array( 'type'=>'plugin', 'slug'=>$slug, 'label'=>$slug, 'file'=>$rel );
		}
		if ( $mu_dir && 0 === strpos( $file, $mu_dir ) ) {
			$rel = substr( $file, strlen( $mu_dir ) ); $parts = explode( '/', $rel ); $slug = $parts[0];
			return array( 'type'=>'mu-plugin', 'slug'=>$slug, 'label'=>$slug, 'file'=>$rel );
		}
		if ( 0 === strpos( $file, $theme_root ) ) {
			$rel = substr( $file, strlen( $theme_root ) ); $parts = explode( '/', $rel ); $slug = $parts[0];
			return array( 'type'=>'theme', 'slug'=>$slug, 'label'=>$slug, 'file'=>$rel );
		}
		if ( 0 === strpos( $file, wp_normalize_path( ABSPATH ) ) ) {
			return array( 'type'=>'core', 'slug'=>'wordpress-core', 'label'=>'WordPress Core', 'file'=>ltrim(substr($file,strlen(wp_normalize_path(ABSPATH))),'/') );
		}
		return array( 'type'=>'custom', 'slug'=>'custom-code', 'label'=>'Custom/server code', 'file'=>$file );
	}
	public static function aggregate( $profiles ) {
		$rows = array();
		if ( empty( $profiles['targets'] ) || ! is_array( $profiles['targets'] ) ) { return $rows; }
		foreach ( $profiles['targets'] as $target => $p ) {
			foreach ( (array) ( isset($p['slow_queries']) ? $p['slow_queries'] : array() ) as $q ) {
				$src = ! empty($q['source']) && is_array($q['source']) ? $q['source'] : array('type'=>'unknown','slug'=>'unknown','label'=>'Unknown');
				self::add_cost( $rows, $src, (float)$q['ms'], 'sql', $target );
			}
			foreach ( (array) ( isset($p['http_calls']) ? $p['http_calls'] : array() ) as $h ) {
				$src = ! empty($h['source']) && is_array($h['source']) ? $h['source'] : array('type'=>'unknown','slug'=>'unknown','label'=>'Unknown');
				self::add_cost( $rows, $src, (float)(isset($h['ms'])?$h['ms']:0), 'http', $target );
			}
		}
		foreach ( $rows as &$r ) {
			$r['total_ms'] = round($r['sql_ms']+$r['http_ms'],2);
			$r['score'] = min(100, (int) round( ($r['total_ms']/10) + ($r['slow_sql_count']*3) + ($r['http_count']*2) ));
			$r['severity'] = $r['score'] >= 70 ? 'high' : ($r['score'] >= 35 ? 'medium' : 'low');
		}
		unset($r);
		usort($rows,function($a,$b){return $b['score'] <=> $a['score'];});
		return array_values($rows);
	}
	private static function add_cost( &$rows, $src, $ms, $kind, $target ) {
		$key = sanitize_key(($src['type']??'unknown').'-'.($src['slug']??'unknown'));
		if ( ! isset($rows[$key]) ) $rows[$key]=array('type'=>$src['type']??'unknown','slug'=>$src['slug']??'unknown','label'=>$src['label']??($src['slug']??'Unknown'),'sql_ms'=>0,'http_ms'=>0,'slow_sql_count'=>0,'http_count'=>0,'targets'=>array());
		if('sql'===$kind){$rows[$key]['sql_ms']+=$ms;$rows[$key]['slow_sql_count']++;}else{$rows[$key]['http_ms']+=$ms;$rows[$key]['http_count']++;}
		$rows[$key]['targets'][$target]=1;
	}
}
