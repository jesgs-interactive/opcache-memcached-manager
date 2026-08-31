<?php
/**
 * Runs when the plugin is deleted from the Plugins screen (not on
 * deactivation). Removes everything the plugin created: its two option
 * rows, and the drop-in files it copied into wp-content/ along with their
 * generated config files.
 *
 * Deactivation stays non-destructive by design — troubleshooting by
 * toggling the plugin shouldn't wipe settings or tear out the object cache.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Delete the plugin's options. On multisite these are per-site, and
 * uninstall.php only runs once, so loop the network ourselves.
 */
function omm_uninstall_delete_options() {
	$option_keys = array( 'omm_settings', 'omm_pagecache_settings' );

	if ( is_multisite() ) {
		$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			foreach ( $option_keys as $key ) {
				delete_option( $key );
			}
			restore_current_blog();
		}
		return;
	}

	foreach ( $option_keys as $key ) {
		delete_option( $key );
	}
}

/**
 * Remove a wp-content drop-in, but only if it still carries this plugin's
 * marker — never touch one another plugin installed in the meantime.
 */
function omm_uninstall_remove_dropin( $filename, $marker ) {
	$path = WP_CONTENT_DIR . '/' . $filename;

	if ( ! file_exists( $path ) || ! is_writable( $path ) ) {
		return;
	}

	$head = (string) file_get_contents( $path, false, null, 0, 4096 );
	if ( false !== strpos( $head, $marker ) ) {
		unlink( $path );
	}
}

omm_uninstall_delete_options();

omm_uninstall_remove_dropin( 'object-cache.php', 'OMM_MEMCACHED_DROPIN_MARKER' );
omm_uninstall_remove_dropin( 'advanced-cache.php', 'OMM_PAGECACHE_DROPIN_MARKER' );

foreach ( array( 'omm-memcached-servers.php', 'omm-pagecache-config.php' ) as $config_file ) {
	$config_path = WP_CONTENT_DIR . '/' . $config_file;
	if ( file_exists( $config_path ) && is_writable( $config_path ) ) {
		unlink( $config_path );
	}
}
