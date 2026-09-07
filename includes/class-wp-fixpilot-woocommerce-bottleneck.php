<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_WooCommerce_Bottleneck {
	public static function analyze( $profiles = array() ) {
		$out=array('active'=>class_exists('WooCommerce'),'products'=>0,'variations'=>0,'variable_products'=>0,'top_variable_products'=>array(),'request_bottlenecks'=>array(),'recommendations'=>array());
		if(!$out['active']) return $out;
		global $wpdb;
		$out['products']=(int)$wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish'");
		$out['variations']=(int)$wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='product_variation' AND post_status IN ('publish','private')");
		$out['variable_products']=(int)$wpdb->get_var("SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id INNER JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE tt.taxonomy='product_type' AND t.slug='variable' AND p.post_status='publish'");
		$rows=$wpdb->get_results("SELECT p.post_parent product_id, COUNT(*) variation_count FROM {$wpdb->posts} p WHERE p.post_type='product_variation' AND p.post_status IN ('publish','private') GROUP BY p.post_parent ORDER BY variation_count DESC LIMIT 10",ARRAY_A);
		foreach((array)$rows as $r){$out['top_variable_products'][]=array('product_id'=>(int)$r['product_id'],'title'=>get_the_title((int)$r['product_id']),'variation_count'=>(int)$r['variation_count']);}
		if(!empty($profiles['targets'])) foreach($profiles['targets'] as $name=>$p){if(0!==strpos($name,'wc_'))continue;$server=(float)($p['total_ms']??0);$sql=(float)($p['sql_ms']??0);$http=(float)array_sum(array_map(function($x){return (float)($x['ms']??0);},(array)($p['http_calls']??array())));$out['request_bottlenecks'][]=array('target'=>$name,'server_ms'=>$server,'sql_ms'=>$sql,'external_http_ms'=>round($http,2),'query_count'=>(int)($p['query_count']??0),'slow_queries'=>count((array)($p['slow_queries']??array())),'repeated_patterns'=>count((array)($p['repeated_queries']??array())));}
		usort($out['request_bottlenecks'],function($a,$b){return $b['server_ms'] <=> $a['server_ms'];});
		if($out['variations']>5000)$out['recommendations'][]=array('priority'=>'high','message'=>'Large variation catalog ('.number_format_i18n($out['variations']).'). Review variation-heavy products and catalog query patterns.');
		if(!empty($out['top_variable_products'][0])&&$out['top_variable_products'][0]['variation_count']>200)$out['recommendations'][]=array('priority'=>'high','message'=>'Product #'.$out['top_variable_products'][0]['product_id'].' has '.$out['top_variable_products'][0]['variation_count'].' variations; test product-page and variation AJAX latency.');
		foreach($out['request_bottlenecks'] as $b){if($b['server_ms']>1200)$out['recommendations'][]=array('priority'=>'high','message'=>$b['target'].' server execution is '.$b['server_ms'].' ms.');if($b['sql_ms']>400)$out['recommendations'][]=array('priority'=>'high','message'=>$b['target'].' spends '.$b['sql_ms'].' ms in SQL; database work is a primary bottleneck.');if($b['external_http_ms']>400)$out['recommendations'][]=array('priority'=>'high','message'=>$b['target'].' spends '.$b['external_http_ms'].' ms on outbound HTTP; inspect payment/shipping/marketing integrations.');}
		return $out;
	}
}
