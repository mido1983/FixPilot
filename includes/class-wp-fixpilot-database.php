<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Database {
	public static function analyze() {
		global $wpdb;
		$autoload_states = "'yes','on','auto-on','auto'";
		$tables = $wpdb->get_results( "SELECT table_name AS name, table_rows AS rows_count, data_length, index_length, data_free FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length+index_length) DESC LIMIT 50", ARRAY_A );
		$top = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS bytes, autoload FROM {$wpdb->options} WHERE autoload IN ({$autoload_states}) ORDER BY LENGTH(option_value) DESC LIMIT 50", ARRAY_A );
		$autoload_bytes = (int) $wpdb->get_var( "SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options} WHERE autoload IN ({$autoload_states})" );
		$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision'" );
		$orphan_postmeta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.ID IS NULL" );
		$orphan_usermeta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID=um.user_id WHERE u.ID IS NULL" );
		return array(
			'tables' => is_array( $tables ) ? $tables : array(),
			'autoload_top' => is_array( $top ) ? $top : array(),
			'autoload_bytes' => $autoload_bytes,
			'autoload_safe_candidates' => self::safe_autoload_candidates(),
			'option_ownership' => self::option_ownership( $top ),
			'revisions' => $revisions,
			'orphan_postmeta' => $orphan_postmeta,
			'orphan_usermeta' => $orphan_usermeta,
		);
	}


	public static function option_ownership( $rows ) {
		$plugins = get_option('active_plugins',array()); $slugs=array();
		foreach((array)$plugins as $file){$dir=dirname($file);$slug='.'===$dir?basename($file,'.php'):$dir;$slugs[]=$slug;}
		$out=array(); foreach((array)$rows as $row){$name=(string)$row['option_name'];$owner='unknown';$confidence='low';
			foreach($slugs as $slug){$token=str_replace(array('-','.'),'_',strtolower($slug));$n=strtolower($name);if(false!==strpos($n,$token)||false!==strpos($n,str_replace('_','-', $token))){$owner='plugin:'.$slug;$confidence='medium';break;}}
			if(0===strpos($name,'woocommerce_')||0===strpos($name,'_wc_')){$owner='plugin:woocommerce';$confidence='high';}
			if(0===strpos($name,'_transient_')||0===strpos($name,'_site_transient_')){$confidence='high';}
			$out[$name]=array('owner'=>$owner,'confidence'=>$confidence);
		} return $out;
	}

	/**
	 * Return only options where changing autoload to false is semantically safe:
	 * WordPress transient values/timeouts. The stored value is not altered.
	 */
	public static function safe_autoload_candidates( $limit = 100 ) {
		global $wpdb;
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$states = "'yes','on','auto-on','auto'";
		$sql = "SELECT option_name, LENGTH(option_value) AS bytes, autoload
			FROM {$wpdb->options}
			WHERE autoload IN ({$states})
			AND (option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%')
			ORDER BY LENGTH(option_value) DESC
			LIMIT {$limit}";
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
