<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Scanner {
	public static function run_full_scan() {
		$performance = WP_FixPilot_Performance::analyze(); $plugins = WP_FixPilot_Plugin_Analyzer::analyze(); $issues = WP_FixPilot_Error_Monitor::recent_issues( 200 ); $system = self::system_info(); $woocommerce = WP_FixPilot_WooCommerce::analyze(); $database = WP_FixPilot_Database::analyze();
		return array( 'generated_at'=>gmdate('c'),'score'=>self::calculate_score($performance,$plugins,$issues,$system,$woocommerce,$database),'system'=>$system,'woocommerce'=>$woocommerce,'performance'=>$performance,'database'=>$database,'plugins'=>$plugins,'issues'=>$issues );
	}
	private static function system_info() { global $wp_version; return array( 'wordpress'=>$wp_version,'php'=>PHP_VERSION,'memory_limit'=>ini_get('memory_limit'),'wp_memory'=>WP_MEMORY_LIMIT,'debug'=>defined('WP_DEBUG')&&WP_DEBUG,'debug_log'=>defined('WP_DEBUG_LOG')&&WP_DEBUG_LOG,'https'=>is_ssl(),'multisite'=>is_multisite(),'permalink'=>(string)get_option('permalink_structure'),'environment'=>function_exists('wp_get_environment_type')?wp_get_environment_type():'production','uploads_writable'=>is_writable(wp_upload_dir()['basedir']),'plugins_writable'=>is_writable(WP_PLUGIN_DIR) ); }
	private static function calculate_score($perf,$plugins,$issues,$system,$woo,$db){ $score=100; foreach($issues as $i){ $w=in_array($i['severity'],array('Warning','User Warning'),true)?3:1; $score-=min(12,$w+(int)floor(log(max(1,(int)$i['occurrences']),10))); } $score-=min(18,count($perf['recommendations'])*3); foreach($plugins as $p){ if($p['risk_score']>=70)$score-=5; elseif($p['risk_score']>=40)$score-=2; } if(!$system['https'])$score-=10; if(!empty($woo['scheduled_actions']['failed']))$score-=min(10,(int)ceil($woo['scheduled_actions']['failed']/25)); if($db['autoload_bytes']>2*MB_IN_BYTES)$score-=8; return max(0,min(100,$score)); }
}
