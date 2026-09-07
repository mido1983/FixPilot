<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Hook_Profiler {
    private static $active=false;
    private static $rows=array();
    private static $wrapped=array();
    private static $hooks=array(
        'init','wp_loaded','parse_request','pre_get_posts','wp','template_redirect','wp_enqueue_scripts','wp_head','wp_footer',
        'woocommerce_init','woocommerce_before_shop_loop','woocommerce_after_shop_loop','woocommerce_before_single_product','woocommerce_after_single_product',
        'woocommerce_before_calculate_totals','woocommerce_cart_calculate_fees','woocommerce_checkout_process','woocommerce_checkout_update_order_review'
    );
    public static function bootstrap(){
        if(self::$active)return; self::$active=true;
        add_action('plugins_loaded',array(__CLASS__,'instrument'),PHP_INT_MAX);
        add_action('init',array(__CLASS__,'instrument'),PHP_INT_MAX);
        add_action('shutdown',array(__CLASS__,'persist'),PHP_INT_MAX-10);
    }
    public static function instrument(){
        if(!self::$active)return; global $wp_filter;
        foreach(self::$hooks as $hook){
            if(empty($wp_filter[$hook]) || !($wp_filter[$hook] instanceof WP_Hook))continue;
            foreach($wp_filter[$hook]->callbacks as $priority=>&$callbacks){
                foreach($callbacks as $id=>&$entry){
                    $key=$hook.'|'.$priority.'|'.$id; if(isset(self::$wrapped[$key]))continue;
                    if(empty($entry['function']) || self::is_ours($entry['function']))continue;
                    $original=$entry['function']; $accepted=isset($entry['accepted_args'])?(int)$entry['accepted_args']:1;
                    $meta=self::describe($original); self::$wrapped[$key]=true;
                    $entry['function']=function() use($original,$hook,$priority,$meta,$accepted){
                        $args=func_get_args(); if($accepted>=0)$args=array_slice($args,0,$accepted);
                        $started=microtime(true); $result=call_user_func_array($original,$args); $ms=(microtime(true)-$started)*1000;
                        $rowkey=$hook.'|'.$meta['callback'];
                        if(!isset(WP_FixPilot_Hook_Profiler::$rows[$rowkey]))WP_FixPilot_Hook_Profiler::$rows[$rowkey]=array('hook'=>$hook,'priority'=>(int)$priority,'callback'=>$meta['callback'],'source'=>$meta['source'],'calls'=>0,'total_ms'=>0.0,'max_ms'=>0.0);
                        WP_FixPilot_Hook_Profiler::$rows[$rowkey]['calls']++;
                        WP_FixPilot_Hook_Profiler::$rows[$rowkey]['total_ms']+=$ms;
                        WP_FixPilot_Hook_Profiler::$rows[$rowkey]['max_ms']=max(WP_FixPilot_Hook_Profiler::$rows[$rowkey]['max_ms'],$ms);
                        return $result;
                    };
                }
            }
            unset($callbacks,$entry);
        }
    }
    private static function is_ours($cb){
        if(is_array($cb)){ $c=is_object($cb[0])?get_class($cb[0]):(string)$cb[0]; return 0===strpos($c,'WP_FixPilot_'); }
        if(is_string($cb))return 0===strpos($cb,'WP_FixPilot_');
        return false;
    }
    private static function describe($cb){
        $label='Closure'; $file='';
        try{
            if(is_array($cb)){ $class=is_object($cb[0])?get_class($cb[0]):(string)$cb[0]; $label=$class.'::'.$cb[1]; $r=new ReflectionMethod($cb[0],$cb[1]); $file=(string)$r->getFileName(); }
            elseif(is_string($cb)){ $label=$cb; if(function_exists($cb)){ $r=new ReflectionFunction($cb); $file=(string)$r->getFileName(); } }
            elseif($cb instanceof Closure){ $r=new ReflectionFunction($cb); $file=(string)$r->getFileName(); $label='Closure@'.basename($file).':'.$r->getStartLine(); }
            elseif(is_object($cb) && method_exists($cb,'__invoke')){ $label=get_class($cb).'::__invoke'; $r=new ReflectionMethod($cb,'__invoke'); $file=(string)$r->getFileName(); }
        }catch(Exception $e){}
        $source=$file?WP_FixPilot_Runtime_Attribution::from_file($file):array('type'=>'unknown','slug'=>'unknown','label'=>'Unknown','file'=>$file);
        return array('callback'=>$label,'source'=>$source);
    }
    public static function persist(){
        if(!self::$active)return;
        $rows=array_values(self::$rows); foreach($rows as &$r){$r['total_ms']=round($r['total_ms'],2);$r['max_ms']=round($r['max_ms'],2);} unset($r);
        usort($rows,function($a,$b){return $b['total_ms']<=>$a['total_ms'];});
        $key=WP_FixPilot_Request_Profiler::current_key(); if($key)set_transient($key.'_hooks',array_slice($rows,0,100),5*MINUTE_IN_SECONDS);
    }
}
