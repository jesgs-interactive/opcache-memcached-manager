<?php
/**
 * Plugin Name:       OPcache & Memcached Manager
 * Plugin URI:        https://example.com/opcache-memcached-manager
 * Description:       Monitor and manage OPcache and Memcached from wp-admin, with matching WP-CLI commands.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Jess G.
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        opcache-memcached-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'OMM_VERSION', '1.1.0' );
define( 'OMM_PATH', plugin_dir_path( __FILE__ ) );
define( 'OMM_URL', plugin_dir_url( __FILE__ ) );
define( 'OMM_CAPABILITY', 'manage_options' ); // Admins only.

require_once OMM_PATH . 'includes/class-omm-opcache.php';
require_once OMM_PATH . 'includes/class-omm-memcached.php';
require_once OMM_PATH . 'includes/class-omm-dropin.php';
require_once OMM_PATH . 'includes/class-omm-admin.php';

/**
 * Default plugin settings.
 */
function omm_default_settings() {
	return array(
		'memcached_servers' => array(
			array(
				'host'   => '127.0.0.1',
				'port'   => 11211,
				'weight' => 0,
			),
		),
	);
}

/**
 * Fetch current settings, merged with defaults so new keys never come back empty.
 */
function omm_get_settings() {
	$saved = get_option( 'omm_settings', array() );
	return wp_parse_args( $saved, omm_default_settings() );
}

register_activation_hook( __FILE__, function () {
	if ( false === get_option( 'omm_settings', false ) ) {
		add_option( 'omm_settings', omm_default_settings() );
	}
} );

/**
 * Boot admin UI.
 */
if ( is_admin() ) {
	OMM_Admin::init();
}

/**
 * Boot WP-CLI commands.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once OMM_PATH . 'includes/class-omm-cli.php';
}
