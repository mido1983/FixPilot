<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Action_Center {
    public static function build($report,$profiles=array()){
        $items=array();
        foreach((array)($report['issues']??array()) as $i){
            $sev=(string)($i['severity']??'');$occ=(int)($i['occurrences']??1);
            $level='attention';$score=45;
            if('Fatal'===$sev){$level='critical';$score=100;}elseif(in_array($sev,array('Warning','User Warning'),true)){$level=$occ>=20?'danger':'attention';$score=min(90,60+$occ);}elseif(false!==stripos($sev,'Deprecated')){$level=$occ>=100?'danger':'attention';$score=min(80,40+(int)log(max(1,$occ),2)*4);}
            self::add($items,$level,$score,'PHP',($i['source']??'Unknown'),$sev.': '.self::short($i['message']??'',140),'Open PHP Issues, inspect the source and apply only supported high-confidence repair rules.','wp-fixpilot-issues');
        }
        foreach((array)($report['plugins']??array()) as $p){if(empty($p['active']))continue;$risk=(int)($p['risk_score']??0);$runtime=(int)($p['runtime_cost']['score']??0);$m=max($risk,$runtime);if($m<50)continue;$level=$m>=80?'critical':($m>=65?'danger':'attention');$why='Risk '.$risk.'/100; runtime '.$runtime.'/100.'; if(!empty($p['flags']))$why.=' Signals: '.implode(', ',$p['flags']).'.';self::add($items,$level,$m,'Plugin',$p['name']??$p['slug'],$why,'Run Conflict Profiler, review attribution, then update/configure/patch/replace based on evidence.','wp-fixpilot-plugins');}
        $perf=(array)($report['performance']??array());
        if(($perf['autoload_mb']??0)>=1){$mb=(float)$perf['autoload_mb'];self::add($items,$mb>=5?'critical':($mb>=2?'danger':'attention'),min(100,(int)round($mb*18)),'Performance','Autoload',round($mb,2).' MB of autoloaded options.','Review top autoload options and remove obsolete/plugin-owned autoload data carefully.','wp-fixpilot-database');}
        if(($perf['overdue_cron_events']??0)>0){$n=(int)$perf['overdue_cron_events'];self::add($items,$n>=50?'critical':($n>=10?'danger':'attention'),min(95,40+$n),'Performance','WP-Cron',$n.' overdue cron events.','Inspect delayed cron hooks and hosting cron configuration.','wp-fixpilot-performance');}
        $woo=(array)($report['woocommerce']??array()); if(!empty($woo['active'])){
            $failed=(int)($woo['scheduled_actions']['failed']??0);if($failed)self::add($items,$failed>=50?'critical':($failed>=10?'danger':'attention'),min(100,55+$failed),'WooCommerce','Action Scheduler',$failed.' failed scheduled actions.','Open WooCommerce diagnostics and identify the failing hook/source before cleanup.','wp-fixpilot-woocommerce');
            $pending=(int)($woo['scheduled_actions']['pending']??0);if($pending>1000)self::add($items,$pending>5000?'critical':'danger',min(100,65+(int)($pending/500)),'WooCommerce','Action Scheduler',$pending.' pending actions.','Find the producer of the backlog and fix scheduling/worker throughput.','wp-fixpilot-woocommerce');
        }
        foreach(WP_FixPilot_Runtime_Attribution::aggregate($profiles) as $r){if(($r['score']??0)<35)continue;$s=(int)$r['score'];self::add($items,$s>=80?'critical':($s>=60?'danger':'attention'),$s,'Runtime',$r['label']??'Unknown',round((float)$r['total_ms'],2).' ms attributed to slow SQL/outbound HTTP across profiled requests.','Inspect the attributed SQL/HTTP rows and confirm with Conflict Profiler before replacing or patching.','wp-fixpilot-profiler');}
        foreach(WP_FixPilot_Callback_Analyzer::analyze($profiles) as $c){if(($c['score']??0)<35)continue;$s=(int)$c['score'];self::add($items,$c['severity'],$s,'Callback',($c['source']['label']??'Unknown').' → '.$c['callback'],round((float)$c['total_ms'],2).' ms total on '.$c['hook'].'; max '.round((float)$c['max_ms'],2).' ms; '.$c['request_share_pct'].'% of request budget at peak.',$c['action'],'wp-fixpilot-profiler');}
        usort($items,function($a,$b){return $b['score']<=>$a['score'];});
        $counts=array('critical'=>0,'danger'=>0,'attention'=>0,'safe'=>0);foreach($items as $i){if(isset($counts[$i['level']]))$counts[$i['level']]++;}
        return array('generated_at'=>gmdate('c'),'counts'=>$counts,'items'=>array_slice($items,0,100));
    }
    private static function add(&$items,$level,$score,$area,$source,$why,$action,$page){$items[]=array('level'=>$level,'score'=>(int)$score,'area'=>$area,'source'=>$source,'why'=>$why,'action'=>$action,'page'=>$page);}
    private static function short($s,$n){$s=wp_strip_all_tags((string)$s);return strlen($s)>$n?substr($s,0,$n-3).'...':$s;}
}
