<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Recommendation_Engine {
    public static function build($report,$profiles=array()){
        $runtime=WP_FixPilot_Runtime_Attribution::aggregate($profiles);$out=array();
        foreach((array)$report['plugins'] as $p){ if(empty($p['active']))continue; $slug=$p['slug']??'';$risk=(int)($p['risk_score']??0);$cost=(int)($p['runtime_cost']['score']??0);$flags=(array)($p['flags']??array());
            $action='leave';$level='safe';$why='No strong regression signal.';
            if($risk>=80||$cost>=85){$action='replace_or_disable';$level='critical';$why='High risk/runtime cost measured.';}
            elseif(in_array('update_available',$flags,true)){$action='update_then_profile';$level='warning';$why='Update available; capture before/after profile.';}
            elseif($cost>=60){$action='configure_or_patch';$level='warning';$why='Measured runtime cost is elevated.';}
            elseif($risk>=50){$action='review';$level='attention';$why='Risk signals require review.';}
            $out[]=array('plugin'=>$p['name'],'slug'=>$slug,'action'=>$action,'level'=>$level,'why'=>$why,'risk'=>$risk,'runtime'=>$cost);
        }
        usort($out,function($a,$b){return max($b['risk'],$b['runtime'])<=>max($a['risk'],$a['runtime']);});return $out;
    }
}
