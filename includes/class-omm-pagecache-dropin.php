<?php
/**
 * Handles copying the bundled advanced-cache.php template into wp-content/,
 * detecting whether an existing drop-in belongs to this plugin, and
 * removing it again. Mirrors OMM_Dropin's pattern for object-cache.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OMM_PageCache_Dropin {

	const MARKER  = 'OMM_PAGECACHE_DROPIN_MARKER';
	const VERSION = '1.0.0';

	public static function template_path() {
		return OMM_PATH . 'dropins/advanced-cache-dropin.php';
	}

	public static function dest_path() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/**
	 * @return string One of: not_installed, ours, outdated, foreign
	 */
	public static function get_status() {
		$dest = self::dest_path();

		if ( ! file_exists( $dest ) ) {
			return 'not_installed';
		}

		$contents = (string) file_get_contents( $dest, false, null, 0, 4096 );

		if ( false === strpos( $contents, self::MARKER ) ) {
			return 'foreign';
		}

		if ( preg_match( '/Version:\s*([0-9.]+)/', $contents, $m ) && $m[1] !== self::VERSION ) {
			return 'outdated';
		}

		return 'ours';
	}

	/**
	 * Whether wp-config.php has WP_CACHE enabled — required for
	 * advanced-cache.php to be loaded at all. We only read this constant;
	 * we never edit wp-config.php automatically.
	 */
	public static function is_wp_cache_constant_enabled() {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}

	private static function get_filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( empty( $wp_filesystem ) ) {
			WP_Filesystem();
		}

		if ( empty( $wp_filesystem ) ) {
			return new WP_Error(
				'omm_pagecache_dropin_no_filesystem',
				__( 'Could not initialize the WordPress filesystem API. You may need to set FS_METHOD to "direct" in wp-config.php, or install the drop-in manually.', 'opcache-memcached-manager' )
			);
		}

		return $wp_filesystem;
	}

	/**
	 * @param bool $overwrite_foreign Allow replacing a drop-in that isn't ours.
	 * @return true|WP_Error
	 */
	public static function install( $overwrite_foreign = false ) {
		$status = self::get_status();

		if ( 'foreign' === $status && ! $overwrite_foreign ) {
			return new WP_Error(
				'omm_pagecache_dropin_foreign_exists',
				__( 'A different advanced-cache.php is already installed. Remove or back it up first, then confirm overwrite.', 'opcache-memcached-manager' )
			);
		}

		$fs = self::get_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( ! $fs->is_writable( WP_CONTENT_DIR ) ) {
			return new WP_Error(
				'omm_pagecache_dropin_not_writable',
				sprintf( __( '%s is not writable by the web server.', 'opcache-memcached-manager' ), WP_CONTENT_DIR )
			);
		}

		$template = $fs->get_contents( self::template_path() );

		if ( false === $template ) {
			return new WP_Error( 'omm_pagecache_dropin_template_missing', __( 'Could not read the bundled drop-in template.', 'opcache-memcached-manager' ) );
		}

		if ( ! $fs->put_contents( self::dest_path(), $template, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'omm_pagecache_dropin_write_failed', __( 'Could not write advanced-cache.php to wp-content/.', 'opcache-memcached-manager' ) );
		}

		$sync = OMM_PageCache::sync_config();
		if ( is_wp_error( $sync ) ) {
			return $sync;
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function remove( $force = false ) {
		$status = self::get_status();

		if ( 'not_installed' === $status ) {
			return true;
		}

		if ( 'foreign' === $status && ! $force ) {
			return new WP_Error(
				'omm_pagecache_dropin_foreign_exists',
				__( 'The installed advanced-cache.php was not created by this plugin, so it will not be removed automatically.', 'opcache-memcached-manager' )
			);
		}

		$fs = self::get_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		if ( $fs->exists( self::dest_path() ) && ! $fs->delete( self::dest_path() ) ) {
			return new WP_Error( 'omm_pagecache_dropin_delete_failed', __( 'Could not delete advanced-cache.php.', 'opcache-memcached-manager' ) );
		}

		$config_path = WP_CONTENT_DIR . '/omm-pagecache-config.php';
		if ( $fs->exists( $config_path ) ) {
			$fs->delete( $config_path );
		}

		return true;
	}
}
