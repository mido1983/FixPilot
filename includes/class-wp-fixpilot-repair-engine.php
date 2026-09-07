<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_FixPilot_Repair_Engine {
	public static function supported_fixes() {
		return array(
			'dynamic_property' => array('label'=>__('Declare dynamic class property','wp-fixpilot'),'confidence'=>98,'description'=>__('Adds an explicit public property to the affected class.','wp-fixpilot')),
			'return_type_will_change' => array('label'=>__('Add ReturnTypeWillChange compatibility attribute','wp-fixpilot'),'confidence'=>96,'description'=>__('Adds the temporary PHP compatibility attribute to the affected legacy interface method.','wp-fixpilot')),
			'optional_before_required' => array('label'=>__('Remove obsolete optional default','wp-fixpilot'),'confidence'=>92,'description'=>__('Removes a simple default from a parameter PHP already treats as required.','wp-fixpilot')),
			'curly_string_interpolation' => array('label'=>__('Modernize deprecated string interpolation','wp-fixpilot'),'confidence'=>97,'description'=>__('Rewrites a simple deprecated ${var} interpolation to {$var} on the reported line.','wp-fixpilot')),
			'undefined_array_key_simple' => array('label'=>__('Guard simple undefined array key read','wp-fixpilot'),'confidence'=>94,'description'=>__('Adds a null-coalescing fallback to a simple assignment/return expression for the reported key.','wp-fixpilot')),
		);
	}
	public static function classify_issue( $issue ) {
		$message = isset($issue['message']) ? (string)$issue['message'] : '';
		if ( preg_match('/Creation of dynamic property\s+([^:]+)::\$([A-Za-z_][A-Za-z0-9_]*)\s+is deprecated/i',$message,$m) ) return array('type'=>'dynamic_property','class'=>trim($m[1]),'property'=>$m[2],'confidence'=>98);
		if ( preg_match('/Return type of\s+([^:]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/i',$message,$m) && false !== stripos($message,'ReturnTypeWillChange') ) return array('type'=>'return_type_will_change','class'=>trim($m[1]),'method'=>$m[2],'confidence'=>96);
		if ( preg_match('/Optional parameter \$([A-Za-z_][A-Za-z0-9_]*) declared before required parameter \$([A-Za-z_][A-Za-z0-9_]*)/i',$message,$m) ) return array('type'=>'optional_before_required','optional'=>$m[1],'required'=>$m[2],'confidence'=>92);
		if ( preg_match('/Using \${([A-Za-z_][A-Za-z0-9_]*)} in strings is deprecated/i',$message,$m) ) return array('type'=>'curly_string_interpolation','variable'=>$m[1],'confidence'=>97);
		if ( preg_match('/Undefined array key [\"\']([^\"\']+)[\"\']/i',$message,$m) ) return array('type'=>'undefined_array_key_simple','key'=>$m[1],'confidence'=>94);
		$extra=class_exists('WP_FixPilot_Repair_Rule_Library')?WP_FixPilot_Repair_Rule_Library::classify($issue):false; if($extra)return $extra; return false;
	}
	public static function repair_issue_by_id( $id ) {
		global $wpdb; $table=$wpdb->prefix.'fixpilot_issues'; $issue=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",absint($id)),ARRAY_A);
		if(!$issue)return new WP_Error('fixpilot_issue_missing',__('Issue not found.','wp-fixpilot'));
		$fix=self::classify_issue($issue); if(!$fix)return new WP_Error('fixpilot_no_safe_fix',__('No high-confidence automatic fix exists for this issue.','wp-fixpilot'));
		if('dynamic_property'===$fix['type'])return self::fix_dynamic_property($issue,$fix);
		if('return_type_will_change'===$fix['type'])return self::fix_return_type($issue,$fix);
		if('optional_before_required'===$fix['type'])return self::fix_optional_before_required($issue,$fix);
		if('curly_string_interpolation'===$fix['type'])return self::fix_curly_string_interpolation($issue,$fix);
		if('undefined_array_key_simple'===$fix['type'])return self::fix_undefined_array_key_simple($issue,$fix);
		if(class_exists('WP_FixPilot_Repair_Rule_Library')&&isset(WP_FixPilot_Repair_Rule_Library::rules()[$fix['type']]))return WP_FixPilot_Repair_Rule_Library::repair($issue,$fix); return new WP_Error('fixpilot_unknown_fix',__('Unsupported repair type.','wp-fixpilot'));
	}
	public static function read_target( $issue ) {
		$file=wp_normalize_path($issue['file']); $safe=WP_FixPilot_Safety::can_edit_file($file); if(is_wp_error($safe))return $safe; if(!self::is_editable_extension_file($file))return new WP_Error('fixpilot_unsafe_path',__('FixPilot only edits writable plugin or theme PHP files.','wp-fixpilot'));
		if(!is_writable($file))return new WP_Error('fixpilot_not_writable',__('The affected PHP file is not writable.','wp-fixpilot'));
		$code=file_get_contents($file); if(false===$code)return new WP_Error('fixpilot_read_failed',__('Could not read the affected file.','wp-fixpilot'));
		return array('file'=>$file,'code'=>$code);
	}
	public static function commit( $issue, $fix, $file, $code, $new_code ) {
		if($new_code===$code)return new WP_Error('fixpilot_no_change',__('Repair produced no change.','wp-fixpilot'));
		$pre=WP_FixPilot_Safety::preflight(true,strlen($new_code)); if(empty($pre['ok']))return new WP_Error('fixpilot_preflight',__('Safety preflight failed: ','wp-fixpilot').implode(', ',$pre['issues'])); $backup=WP_FixPilot_Backup::create_file_backup($file,array('issue_id'=>(int)$issue['id'],'fix'=>$fix)); if(is_wp_error($backup))return $backup; if(!empty($backup['path']))WP_FixPilot_Safety::arm_file_recovery($file,$backup['path']);
		if(false===file_put_contents($file,$new_code,LOCK_EX))return new WP_Error('fixpilot_write_failed',__('Could not write the repaired file.','wp-fixpilot'));
		$syntax=self::php_lint($file); if(is_wp_error($syntax)){WP_FixPilot_Backup::restore($backup);WP_FixPilot_Safety::disarm_file_recovery();self::log_repair($issue,$fix,'rolled_back',$syntax->get_error_message(),$backup);return $syntax;}
		$validation=self::validate_site(); if(is_wp_error($validation)){WP_FixPilot_Backup::restore($backup);WP_FixPilot_Safety::disarm_file_recovery();self::log_repair($issue,$fix,'rolled_back',$validation->get_error_message(),$backup);return new WP_Error('fixpilot_validation_failed',sprintf(__('Repair was rolled back: %s','wp-fixpilot'),$validation->get_error_message()));}
		WP_FixPilot_Safety::disarm_file_recovery(); self::log_repair($issue,$fix,'applied',__('Syntax and loopback validation passed.','wp-fixpilot'),$backup); global $wpdb; $wpdb->delete($wpdb->prefix.'fixpilot_issues',array('id'=>(int)$issue['id']),array('%d'));
		return array('status'=>'applied','fix'=>$fix,'backup'=>$backup,'validation'=>$validation);
	}
	private static function fix_dynamic_property( $issue, $fix ) {
		$t=self::read_target($issue); if(is_wp_error($t))return $t; $code=$t['code']; $property=$fix['property'];
		if(preg_match('/\b(public|protected|private|var)\s+(?:static\s+)?\$'.preg_quote($property,'/').'\b/',$code))return new WP_Error('fixpilot_already_declared',__('The property is already declared; the stored issue may be stale.','wp-fixpilot'));
		$short=basename(str_replace('\\','/',ltrim($fix['class'],'\\'))); $pattern='/(\bclass\s+'.preg_quote($short,'/').'\b[^\{]*\{)/i';
		if(!preg_match($pattern,$code,$match,PREG_OFFSET_CAPTURE))return new WP_Error('fixpilot_class_missing',__('Could not locate the affected class declaration safely.','wp-fixpilot'));
		$at=$match[0][1]+strlen($match[0][0]); $decl="\n\t/** @var mixed Declared by WP FixPilot for PHP 8.2+ compatibility. */\n\tpublic $".$property.";\n"; $new=substr($code,0,$at).$decl.substr($code,$at);
		return self::commit($issue,$fix,$t['file'],$code,$new);
	}
	private static function fix_return_type( $issue, $fix ) {
		$t=self::read_target($issue); if(is_wp_error($t))return $t; $code=$t['code']; $method=$fix['method'];
		$pattern='/^(\s*)((?:public|protected|private|final|abstract|static|\s)+function\s+&?\s*'.preg_quote($method,'/').'\s*\()/mi';
		if(!preg_match($pattern,$code,$m,PREG_OFFSET_CAPTURE))return new WP_Error('fixpilot_method_missing',__('Could not locate the affected method declaration safely.','wp-fixpilot'));
		$start=$m[0][1]; $prefix=substr($code,max(0,$start-160),min(160,$start)); if(false!==strpos($prefix,'#[\\ReturnTypeWillChange]'))return new WP_Error('fixpilot_attribute_exists',__('ReturnTypeWillChange is already present; the stored issue may be stale.','wp-fixpilot'));
		$indent=$m[1][0]; $new=substr($code,0,$start).$indent."#[\\ReturnTypeWillChange]\n".substr($code,$start);
		return self::commit($issue,$fix,$t['file'],$code,$new);
	}
	private static function fix_optional_before_required( $issue, $fix ) {
		$t=self::read_target($issue); if(is_wp_error($t))return $t; $code=$t['code']; $line=max(1,(int)$issue['line']); $lines=preg_split('/\R/',$code); $idx=$line-1; $from=max(0,$idx-4); $to=min(count($lines)-1,$idx+4); $chunk=implode("\n",array_slice($lines,$from,$to-$from+1));
		$param='\$'.preg_quote($fix['optional'],'/'); $pattern='/('.$param.'\s*)=\s*(null|true|false|[-+]?\d+(?:\.\d+)?|[\'\"][^\'\"]*[\'\"]|array\s*\(\s*\)|\[\s*\])\s*(,|\))/i';
		if(!preg_match($pattern,$chunk))return new WP_Error('fixpilot_complex_default',__('The optional parameter default is not a simple literal, so FixPilot will not rewrite it automatically.','wp-fixpilot'));
		$new_chunk=preg_replace($pattern,'$1$3',$chunk,1); if($new_chunk===$chunk)return new WP_Error('fixpilot_parameter_missing',__('Could not locate the optional parameter safely.','wp-fixpilot'));
		$before=array_slice($lines,0,$from); $after=array_slice($lines,$to+1); $new=implode("\n",array_merge($before,explode("\n",$new_chunk),$after));
		if(substr($code,-1)==="\n")$new.="\n"; return self::commit($issue,$fix,$t['file'],$code,$new);
	}
	private static function fix_curly_string_interpolation( $issue, $fix ) {
		$t=self::read_target($issue); if(is_wp_error($t))return $t; $code=$t['code'];
		$line=max(1,(int)$issue['line']); $lines=preg_split('/\R/',$code); $idx=$line-1;
		if(!isset($lines[$idx]))return new WP_Error('fixpilot_line_missing',__('The reported line no longer exists.','wp-fixpilot'));
		$old='${'.$fix['variable'].'}'; $new='{$'.$fix['variable'].'}';
		if(false===strpos($lines[$idx],$old) || (false===strpos($lines[$idx],'"') && false===strpos($lines[$idx],"'") && false===strpos($lines[$idx],'<<<')))
			return new WP_Error('fixpilot_interpolation_missing',__('Could not locate the deprecated interpolation safely on the reported line.','wp-fixpilot'));
		$lines[$idx]=preg_replace('/\\$\\{'.preg_quote($fix['variable'],'/').'\\}/',$new,$lines[$idx],1);
		$new_code=implode("\n",$lines); if(substr($code,-1)==="\n")$new_code.="\n";
		return self::commit($issue,$fix,$t['file'],$code,$new_code);
	}
	private static function fix_undefined_array_key_simple( $issue, $fix ) {
		$t=self::read_target($issue); if(is_wp_error($t))return $t; $code=$t['code'];
		$line=max(1,(int)$issue['line']); $lines=preg_split('/\R/',$code); $idx=$line-1;
		if(!isset($lines[$idx]))return new WP_Error('fixpilot_line_missing',__('The reported line no longer exists.','wp-fixpilot'));
		$key=preg_quote($fix['key'],'/'); $old=$lines[$idx]; $new=$old;
		$read='(\$[A-Za-z_][A-Za-z0-9_]*(?:->?[A-Za-z_][A-Za-z0-9_]*)?\[[\"\']'.$key.'[\"\']\])';
		if(preg_match('/^(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*)'.$read.'(\s*;\s*(?:\/\/.*)?)$/',$old,$m)) {
			$new=$m[1].$m[2].' ?? null'.$m[3];
		} elseif(preg_match('/^(\s*return\s+)'.$read.'(\s*;\s*(?:\/\/.*)?)$/',$old,$m)) {
			$new=$m[1].$m[2].' ?? null'.$m[3];
		} else {
			return new WP_Error('fixpilot_warning_complex',__('Undefined array key repair is only automatic for a simple assignment or return expression.','wp-fixpilot'));
		}
		if($new===$old)return new WP_Error('fixpilot_warning_nochange',__('No safe change could be generated.','wp-fixpilot'));
		$lines[$idx]=$new; $new_code=implode("\n",$lines); if(substr($code,-1)==="\n")$new_code.="\n";
		return self::commit($issue,$fix,$t['file'],$code,$new_code);
	}
	private static function php_lint( $file ) {
		if(!function_exists('exec'))return true; $disabled=array_map('trim',explode(',',(string)ini_get('disable_functions'))); if(in_array('exec',$disabled,true))return true;
		$php=defined('PHP_BINARY')&&PHP_BINARY?PHP_BINARY:'php'; $out=array();$status=0; @exec(escapeshellarg($php).' -l '.escapeshellarg($file).' 2>&1',$out,$status); return 0===$status?true:new WP_Error('fixpilot_php_lint',__('PHP syntax validation failed; repair was rolled back.','wp-fixpilot').' '.implode(' ',$out));
	}
	private static function is_editable_extension_file( $file ) { $file=wp_normalize_path($file);$roots=array(wp_normalize_path(WP_PLUGIN_DIR).'/',wp_normalize_path(get_theme_root()).'/');foreach($roots as $root){if(0===strpos($file,$root)&&'.php'===strtolower(substr($file,-4)))return true;}return false; }
	public static function validate_site() { $urls=array(home_url('/'));if(class_exists('WooCommerce')){$shop=wc_get_page_permalink('shop');if($shop)$urls[]=$shop;$cart=wc_get_cart_url();if($cart)$urls[]=$cart;$checkout=wc_get_checkout_url();if($checkout)$urls[]=$checkout;} $results=array();foreach(array_unique($urls) as $url){$response=wp_remote_get($url,array('timeout'=>12,'redirection'=>3,'user-agent'=>'WP FixPilot/'.WP_FIXPILOT_VERSION,'headers'=>array('Cache-Control'=>'no-cache')));if(is_wp_error($response))return $response;$c=(int)wp_remote_retrieve_response_code($response);$results[]=array('url'=>$url,'code'=>$c);if($c>=500||0===$c)return new WP_Error('fixpilot_http_failure',sprintf(__('HTTP %d returned by %s','wp-fixpilot'),$c,$url));}return $results; }
	private static function log_repair($issue,$fix,$status,$message,$backup){$log=(array)get_option('wp_fixpilot_repair_log',array());array_unshift($log,array('time'=>gmdate('c'),'issue_id'=>(int)$issue['id'],'source'=>$issue['source'],'file'=>$issue['file'],'fix'=>$fix['type'],'status'=>$status,'message'=>$message,'backup_id'=>isset($backup['id'])?$backup['id']:''));update_option('wp_fixpilot_repair_log',array_slice($log,0,100),false);}
}
