<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Profile_Comparison {
    public static function compare($before,$after){
        $out=array('generated_at'=>gmdate('c'),'targets'=>array());
        $bt=!empty($before['targets'])?(array)$before['targets']:array(); $at=!empty($after['targets'])?(array)$after['targets']:array();
        foreach($at as $name=>$a){ if(empty($bt[$name])||!is_array($a)||!is_array($bt[$name]))continue; $b=$bt[$name];
            $row=array('target'=>$name);
            foreach(array('total_ms','outer_ms','sql_ms','query_count','http_count','peak_memory') as $k){$bv=(float)($b[$k]??0);$av=(float)($a[$k]??0);$row[$k.'_before']=$bv;$row[$k.'_after']=$av;$row[$k.'_delta']=round($av-$bv,2);$row[$k.'_pct']=$bv>0?round((($av-$bv)/$bv)*100,1):null;}
            $row['status']=self::status($row);$out['targets'][$name]=$row;
        }
        return $out;
    }
    private static function status($r){$pct=$r['total_ms_pct'];$sql=$r['sql_ms_pct'];if(null!==$pct&&$pct>=30)return 'critical';if(null!==$pct&&$pct>=15)return 'warning';if(null!==$sql&&$sql>=25)return 'warning';if(null!==$pct&&$pct<=-15)return 'improved';return 'stable';}
}
