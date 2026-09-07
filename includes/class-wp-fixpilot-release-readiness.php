<?php
if(!defined('ABSPATH'))exit;
class WP_FixPilot_Release_Readiness {
 public static function checks(){ $files=array('readme.txt','LICENSE','uninstall.php','wp-fixpilot.php');$out=array();foreach($files as $f)$out['file_'.$f]=is_file(WP_FIXPILOT_DIR.$f);$out['php']=PHP_VERSION;$out['wordpress']=get_bloginfo('version');$out['woocommerce']=defined('WC_VERSION')?WC_VERSION:'inactive';$out['hpos_declared']=class_exists('WooCommerce')?true:null;$out['multisite']=is_multisite();$out['recovery_bridge']=WP_FixPilot_Safety::bridge_status();$out['filesystem']=WP_FixPilot_Safety::preflight(false);return $out;}
}
