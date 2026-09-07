<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Callback_Analyzer {
    public static function analyze($profiles){
        $rows=array();
        if(empty($profiles['targets'])||!is_array($profiles['targets']))return $rows;
        foreach($profiles['targets'] as $target=>$p){
            $request_ms=(float)($p['total_ms']??0);
            foreach((array)($p['hook_callbacks']??array()) as $hc){
                $src=(array)($hc['source']??array());
                $key=md5(($hc['hook']??'').'|'.($hc['callback']??'').'|'.($src['type']??'').'|'.($src['slug']??''));
                if(!isset($rows[$key]))$rows[$key]=array(
                    'hook'=>(string)($hc['hook']??''),'callback'=>(string)($hc['callback']??''),'source'=>$src,
                    'calls'=>0,'total_ms'=>0.0,'max_ms'=>0.0,'targets'=>array(),'request_share_pct'=>0.0,'related_sql_ms'=>0.0,'related_http_ms'=>0.0,'related_slow_sql'=>0,'related_http_calls'=>0
                );
                $rows[$key]['calls']+=(int)($hc['calls']??0);
                $rows[$key]['total_ms']+=(float)($hc['total_ms']??0);
                $rows[$key]['max_ms']=max($rows[$key]['max_ms'],(float)($hc['max_ms']??0));
                $rows[$key]['targets'][$target]=array('callback_ms'=>(float)($hc['total_ms']??0),'request_ms'=>$request_ms);
            }
        }
        foreach($rows as &$r){
            $shares=array(); foreach($r['targets'] as $t){if($t['request_ms']>0)$shares[]=($t['callback_ms']/$t['request_ms'])*100;}
            $r['total_ms']=round($r['total_ms'],2);$r['max_ms']=round($r['max_ms'],2);$r['request_share_pct']=$shares?round(max($shares),1):0;
            $stype=$r['source']['type']??'unknown';$sslug=$r['source']['slug']??'unknown';
            foreach(array_keys($r['targets']) as $target){$p=$profiles['targets'][$target]??array();foreach((array)($p['slow_queries']??array()) as $q){$src=(array)($q['source']??array());if(($src['type']??'')===$stype&&($src['slug']??'')===$sslug){$r['related_sql_ms']+=(float)($q['ms']??0);$r['related_slow_sql']++;}}foreach((array)($p['http_calls']??array()) as $h){$src=(array)($h['source']??array());if(($src['type']??'')===$stype&&($src['slug']??'')===$sslug){$r['related_http_ms']+=(float)($h['ms']??0);$r['related_http_calls']++;}}}
            $r['related_sql_ms']=round($r['related_sql_ms'],2);$r['related_http_ms']=round($r['related_http_ms'],2);
            $r['score']=min(100,(int)round(($r['total_ms']/8)+($r['max_ms']/5)+min(25,$r['calls']/2)+min(25,$r['request_share_pct']/2)+min(15,($r['related_sql_ms']+$r['related_http_ms'])/100)));
            $r['severity']=$r['score']>=80?'critical':($r['score']>=60?'danger':($r['score']>=35?'attention':'safe'));
            $r['why']=self::why($r); $r['action']=self::action($r);
        } unset($r);
        usort($rows,function($a,$b){return $b['score']<=>$a['score'];});
        return array_values($rows);
    }
    private static function why($r){
        $parts=array();
        if($r['total_ms']>=500)$parts[]='High cumulative callback time';
        elseif($r['total_ms']>=200)$parts[]='Elevated cumulative callback time';
        if($r['max_ms']>=150)$parts[]='slow individual call';
        if($r['calls']>=20)$parts[]='high call count';
        if($r['request_share_pct']>=30)$parts[]='large share of request budget';if($r['related_sql_ms']>=100)$parts[]='same source also owns slow SQL';if($r['related_http_ms']>=150)$parts[]='same source also owns outbound HTTP latency';
        return $parts?implode('; ',$parts).'.':'No strong callback bottleneck signal.';
    }
    private static function action($r){
        $type=$r['source']['type']??'unknown';
        if('plugin'===$type){
            if($r['score']>=80)return 'Inspect plugin settings/code for this callback; profile with the plugin excluded and replace or patch if confirmed.';
            if($r['score']>=60)return 'Review plugin callback implementation and reduce repeated work, remote calls, or heavy queries.';
            return 'Leave enabled; re-check after related plugin updates.';
        }
        if('theme'===$type)return 'Review the theme callback and move expensive work out of hot frontend hooks.';
        if('core'===$type)return 'Do not patch WordPress Core. Investigate upstream plugin/theme code causing additional Core work.';
        return 'Inspect custom code responsible for this callback.';
    }
}
