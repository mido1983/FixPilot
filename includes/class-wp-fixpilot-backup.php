<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WP_FixPilot_Backup {
	public static function base_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'wp-fixpilot/backups/';
	}
	public static function ensure_dir() {
		$dir = self::base_dir();
		if ( ! wp_mkdir_p( $dir ) ) { return new WP_Error( 'fixpilot_backup_dir', __( 'Could not create the FixPilot backup directory.', 'wp-fixpilot' ) ); }
		$parent = dirname( untrailingslashit( $dir ) );
		@file_put_contents( trailingslashit( $parent ) . 'index.php', "<?php\n// Silence is golden.\n" );
		@file_put_contents( trailingslashit( $parent ) . '.htaccess', "Deny from all\n" );
		@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		return $dir;
	}
	public static function create_file_backup( $file, $context = array() ) {
		$file = wp_normalize_path( $file );
		if ( ! is_file( $file ) || ! is_readable( $file ) ) { return new WP_Error( 'fixpilot_backup_source', __( 'Source file is not readable.', 'wp-fixpilot' ) ); }
		$dir = self::ensure_dir(); if ( is_wp_error( $dir ) ) { return $dir; }
		$id = gmdate( 'Ymd-His' ) . '-' . substr( sha1( $file . microtime( true ) . wp_rand() ), 0, 10 );
		$item_dir = trailingslashit( $dir ) . $id . '/';
		if ( ! wp_mkdir_p( $item_dir ) ) { return new WP_Error( 'fixpilot_backup_item', __( 'Could not create backup item directory.', 'wp-fixpilot' ) ); }
		$backup_file = $item_dir . 'original.php';
		if ( ! copy( $file, $backup_file ) ) { return new WP_Error( 'fixpilot_backup_copy', __( 'Could not copy the original file.', 'wp-fixpilot' ) ); }
		$manifest = array( 'id'=>$id,'created_at'=>gmdate('c'),'original_file'=>$file,'backup_file'=>$backup_file,'sha1'=>sha1_file($file),'context'=>$context );
		file_put_contents( $item_dir . 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		return $manifest;
	}
	public static function restore( $backup ) {
		if ( ! is_array( $backup ) || empty( $backup['original_file'] ) || empty( $backup['backup_file'] ) ) { return new WP_Error( 'fixpilot_restore_manifest', __( 'Invalid backup manifest.', 'wp-fixpilot' ) ); }
		$original = wp_normalize_path( $backup['original_file'] ); $backup_file = wp_normalize_path( $backup['backup_file'] );
		if ( ! self::allowed_original_path( $original ) ) { return new WP_Error( 'fixpilot_restore_path', __( 'Backup target is outside plugin/theme directories.', 'wp-fixpilot' ) ); }
		if ( 0 !== strpos( $backup_file, wp_normalize_path( self::base_dir() ) ) ) { return new WP_Error( 'fixpilot_restore_backup_path', __( 'Backup file is outside the FixPilot backup directory.', 'wp-fixpilot' ) ); }
		if ( ! is_file( $backup_file ) || ! is_writable( dirname( $original ) ) ) { return new WP_Error( 'fixpilot_restore_access', __( 'Backup cannot be restored because the file is unavailable or not writable.', 'wp-fixpilot' ) ); }
		return copy( $backup_file, $original ) ? true : new WP_Error( 'fixpilot_restore_failed', __( 'Restore failed.', 'wp-fixpilot' ) );
	}
	public static function restore_by_id( $id ) { $manifest = self::manifest( $id ); return is_wp_error( $manifest ) ? $manifest : self::restore( $manifest ); }
	public static function manifest( $id ) {
		$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $id ); if ( ! $id ) { return new WP_Error( 'fixpilot_backup_id', __( 'Invalid backup ID.', 'wp-fixpilot' ) ); }
		$file = trailingslashit( self::base_dir() ) . $id . '/manifest.json';
		if ( ! is_file( $file ) ) { return new WP_Error( 'fixpilot_backup_missing', __( 'Backup manifest not found.', 'wp-fixpilot' ) ); }
		$data = json_decode( (string) file_get_contents( $file ), true ); return is_array( $data ) ? $data : new WP_Error( 'fixpilot_backup_invalid', __( 'Backup manifest is invalid.', 'wp-fixpilot' ) );
	}
	public static function list_backups( $limit = 50 ) {
		$dir = self::base_dir(); if ( ! is_dir( $dir ) ) { return array(); }
		$items = array(); foreach ( glob( trailingslashit( $dir ) . '*/manifest.json' ) as $file ) { $data = json_decode( (string) file_get_contents( $file ), true ); if ( is_array( $data ) && ! empty( $data['id'] ) ) { $items[] = $data; } }
		usort( $items, function( $a, $b ) { return strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' ); } ); return array_slice( $items, 0, max(1,min(200,(int)$limit)) );
	}
	public static function cleanup_old( $days = 30 ) {
		$dir = self::base_dir(); if ( ! is_dir( $dir ) ) { return 0; }
		$count = 0; $cutoff = time() - absint( $days ) * DAY_IN_SECONDS;
		foreach ( glob( trailingslashit( $dir ) . '*' ) as $path ) { if ( is_dir( $path ) && filemtime( $path ) < $cutoff ) { self::rrmdir( $path ); $count++; } }
		return $count;
	}
	private static function allowed_original_path( $file ) { $roots=array(wp_normalize_path(WP_PLUGIN_DIR).'/',wp_normalize_path(get_theme_root()).'/'); foreach($roots as $root){if(0===strpos($file,$root)&&'.php'===strtolower(substr($file,-4)))return true;} return false; }
	private static function rrmdir( $dir ) { foreach ( array_diff( scandir( $dir ), array( '.', '..' ) ) as $item ) { $path=$dir.DIRECTORY_SEPARATOR.$item; is_dir($path)?self::rrmdir($path):@unlink($path); } @rmdir($dir); }
}
