<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
class WP_FixPilot_Plugin_Repair_Plan {
	public static function build( $plugins = null ) {
		if(null===$plugins)$plugins=WP_FixPilot_Plugin_Analyzer::analyze(); $out=array();
		foreach((array)$plugins as $p){ if(empty($p['active']))continue; $steps=array(); $severity='safe';
			if(!empty($p['php_issues'])){$steps[]='Repair supported PHP issues first; review remaining warnings manually.';$severity='danger';}
			if(!empty($p['runtime_cost']['score']) && $p['runtime_cost']['score']>=50){$steps[]='Run Conflict Profiler and inspect attributed SQL/HTTP before changing configuration.';$severity='danger';}
			if(in_array('Update available',(array)$p['flags'],true)){$steps[]='Update under Update Guard, then re-profile.';if('safe'===$severity)$severity='attention';}
			if(!empty($p['duplicate_capabilities'])){$steps[]='Review overlapping '.implode(', ',$p['duplicate_capabilities']).' functionality and remove duplication if confirmed.';if('safe'===$severity)$severity='attention';}
			if(!$steps)$steps[]='No repair action currently justified by evidence.';
			$out[]=array('plugin'=>$p['name'],'slug'=>$p['slug'],'risk'=>(int)$p['risk_score'],'severity'=>$severity,'steps'=>$steps);
		} usort($out,function($a,$b){return $b['risk']<=>$a['risk'];}); return $out;
	}
}
