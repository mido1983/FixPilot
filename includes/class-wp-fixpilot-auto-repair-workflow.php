<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_FixPilot_Auto_Repair_Workflow {
	const HISTORY_OPTION = 'wp_fixpilot_auto_repair_history';
	const LAST_OPTION = 'wp_fixpilot_auto_repair_last';
	const LOCK = 'wp_fixpilot_auto_repair_lock';

	public static function run( $max_steps = 12 ) {
		$max_steps = max( 1, min( 25, absint( $max_steps ) ) );
		if ( get_transient( self::LOCK ) ) return new WP_Error( 'fixpilot_workflow_locked', __( 'An Auto Repair Workflow is already running.', 'wp-fixpilot' ) );
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		$run = array(
			'id' => 'workflow-' . wp_generate_uuid4(), 'started_at' => gmdate('c'), 'status' => 'running',
			'baseline' => array(), 'steps' => array(), 'final' => array(), 'stop_reason' => '',
		);
		try {
			$baseline_benchmark = WP_FixPilot_Benchmark::run();
			$baseline_profile = WP_FixPilot_Request_Profiler::profile_url( home_url('/'), 'workflow-base-' . wp_generate_password(8,false,false) );
			$run['baseline'] = array( 'benchmark'=>$baseline_benchmark, 'home_profile'=>$baseline_profile, 'scan'=>WP_FixPilot_Scanner::run_full_scan() );
			$previous_benchmark = $baseline_benchmark; $previous_profile = $baseline_profile;
			$candidates = self::ordered_candidates(); $count = 0;
			foreach ( $candidates as $candidate ) {
				if ( $count >= $max_steps ) { $run['stop_reason'] = 'Maximum workflow step limit reached.'; break; }
				if ( empty($candidate['batch_safe']) ) continue;
				if ( 'code' === $candidate['kind'] ) { $settings=get_option('wp_fixpilot_settings',array()); if(empty($settings['allow_code_repairs'])) continue; }
				$count++;
				$before_token = self::capture_rollback_context( $candidate );
				$result = WP_FixPilot_Repair_Center::apply_candidate( $candidate );
				$step = array('candidate_id'=>$candidate['id'],'label'=>$candidate['label'],'kind'=>$candidate['kind'],'started_at'=>gmdate('c'),'applied'=>!is_wp_error($result)&&false!==$result,'message'=>is_wp_error($result)?$result->get_error_message():self::result_message($result),'rollback'=>'not-needed');
				if ( is_wp_error($result) || false === $result ) { $step['status']='failed'; $run['steps'][]=$step; $run['status']='stopped'; $run['stop_reason']='A repair step failed before validation.'; break; }
				$token = self::resolve_rollback_token( $candidate, $result, $before_token );
				$after_benchmark = WP_FixPilot_Benchmark::run();
				$after_profile = WP_FixPilot_Request_Profiler::profile_url( home_url('/'), 'workflow-step-' . $count . '-' . wp_generate_password(6,false,false) );
				$verdict = self::regression_verdict( $previous_benchmark, $after_benchmark, $previous_profile, $after_profile );
				$step['validation']=$verdict; $step['benchmark']=$after_benchmark; $step['home_profile']=$after_profile;
				if ( ! empty($verdict['regression']) ) {
					$rollback = self::rollback( $token );
					$step['rollback'] = is_wp_error($rollback) ? 'failed: '.$rollback->get_error_message() : ($rollback ? 'applied' : 'unavailable');
					$step['status']='rolled_back'; $run['steps'][]=$step; $run['status']='rolled_back'; $run['stop_reason']=$verdict['reason']; break;
				}
				$step['status']='accepted'; $run['steps'][]=$step; $previous_benchmark=$after_benchmark; $previous_profile=$after_profile;
			}
			if ( 'running' === $run['status'] ) $run['status']='completed';
			$final_profiles = WP_FixPilot_Request_Profiler::run_suite();
			$run['final'] = array('benchmark'=>WP_FixPilot_Benchmark::run(),'profiles'=>$final_profiles,'scan'=>WP_FixPilot_Scanner::run_full_scan());
			$run['finished_at']=gmdate('c');
			update_option( self::LAST_OPTION, $run, false );
			$history=(array)get_option(self::HISTORY_OPTION,array()); array_unshift($history,$run); update_option(self::HISTORY_OPTION,array_slice($history,0,20),false);
			update_option('wp_fixpilot_request_profiles',$final_profiles,false); update_option('wp_fixpilot_last_scan',$run['final']['scan'],false); update_option('wp_fixpilot_last_benchmark',$run['final']['benchmark'],false);
			return $run;
		} catch ( Throwable $e ) {
			$run['status']='failed';$run['stop_reason']=$e->getMessage();$run['finished_at']=gmdate('c');update_option(self::LAST_OPTION,$run,false);return new WP_Error('fixpilot_workflow_exception',$e->getMessage());
		} finally { delete_transient( self::LOCK ); }
	}

	private static function ordered_candidates() {
		$items=WP_FixPilot_Repair_Center::candidates();
		$rank=array('low'=>1,'medium'=>2,'high'=>3,'critical'=>4);
		usort($items,function($a,$b)use($rank){$ar=$rank[$a['risk']]??1;$br=$rank[$b['risk']]??1;if($ar!==$br)return $br<=>$ar;if((bool)$a['reversible']!==(bool)$b['reversible'])return !empty($a['reversible'])?-1:1;return (int)$b['confidence']<=>(int)$a['confidence'];});
		return $items;
	}
	private static function capture_rollback_context($c){return array('config_count'=>count((array)get_option('wp_fixpilot_config_repairs',array())),'autoload_count'=>count((array)get_option('wp_fixpilot_option_repairs',array())));}
	private static function resolve_rollback_token($c,$result,$before){
		if(is_array($result)&&!empty($result['backup']['id']))return array('type'=>'file','id'=>$result['backup']['id']);
		if(is_array($result)&&!empty($result['repair_id'])&&'repair_autoload'===$c['action'])return array('type'=>'autoload','id'=>$result['repair_id']);
		if('configuration'===$c['kind']){$h=(array)get_option('wp_fixpilot_config_repairs',array());if(count($h)>($before['config_count']??0)&&!empty($h[0]['id']))return array('type'=>'config','id'=>$h[0]['id']);}
		return array('type'=>'none');
	}
	private static function rollback($token){
		if(empty($token['type'])||'none'===$token['type'])return false;
		if('file'===$token['type'])return WP_FixPilot_Backup::restore($token['id']);
		if('autoload'===$token['type'])return WP_FixPilot_Fixer::restore_autoload_repair($token['id']);
		if('config'===$token['type'])return WP_FixPilot_Config::restore_change($token['id']);
		return false;
	}
	private static function regression_verdict($before,$after,$bp,$ap){
		foreach((array)$after as $name=>$row){if('generated_at'===$name||!is_array($row))continue;if(empty($row['ok']))return array('regression'=>true,'reason'=>'Smoke test failed for '.$name.'.');}
		$b=self::median_ms($before);$a=self::median_ms($after);if($b>0&&$a>$b*1.5&&($a-$b)>500)return array('regression'=>true,'reason'=>sprintf('Median request latency regressed from %.0f ms to %.0f ms.',$b,$a));
		$bt=(float)($bp['total_ms']??0);$at=(float)($ap['total_ms']??0);if($bt>0&&$at>$bt*1.5&&($at-$bt)>300)return array('regression'=>true,'reason'=>sprintf('Home server execution regressed from %.0f ms to %.0f ms.',$bt,$at));
		$bs=(float)($bp['sql_ms']??0);$as=(float)($ap['sql_ms']??0);if($bs>0&&$as>$bs*1.75&&($as-$bs)>200)return array('regression'=>true,'reason'=>sprintf('Home SQL budget regressed from %.0f ms to %.0f ms.',$bs,$as));
		return array('regression'=>false,'reason'=>'Validation passed.','median_before_ms'=>$b,'median_after_ms'=>$a,'home_before_ms'=>$bt,'home_after_ms'=>$at);
	}
	private static function median_ms($benchmark){$v=array();foreach((array)$benchmark as $k=>$r){if('generated_at'!==$k&&is_array($r)&&!empty($r['ok'])&&isset($r['ms']))$v[]=(float)$r['ms'];}if(!$v)return 0;sort($v);$n=count($v);return $n%2?$v[(int)floor($n/2)]:($v[$n/2-1]+$v[$n/2])/2;}
	private static function result_message($r){if(is_bool($r))return $r?'success':'failed';if(is_scalar($r))return (string)$r;if(is_array($r)&&!empty($r['status']))return (string)$r['status'];return 'applied';}
}
