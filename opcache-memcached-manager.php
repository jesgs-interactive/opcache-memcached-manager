<?php
/**
 * Plugin Name:       OPcache & Memcached Manager
 * Plugin URI:        https://github.com/jesgs-interactive/opcache-memcached-manager
 * Description:       Monitor and manage OPcache and Memcached from wp-admin, with matching WP-CLI commands.
 * Version:           {{VERSION}}
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Jess G.
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       opcache-memcached-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'OMM_VERSION', '{{VERSION}}' );
define( 'OMM_PLUGIN_FILE', __FILE__ );
define( 'OMM_PATH', plugin_dir_path( __FILE__ ) );
define( 'OMM_URL', plugin_dir_url( __FILE__ ) );
define( 'OMM_CAPABILITY', 'manage_options' ); // Admins only.

require_once OMM_PATH . 'includes/class-omm-opcache.php';
require_once OMM_PATH . 'includes/class-omm-memcached.php';
require_once OMM_PATH . 'includes/class-omm-dropin.php';
require_once OMM_PATH . 'includes/class-omm-pagecache-dropin.php';
require_once OMM_PATH . 'includes/class-omm-pagecache.php';
require_once OMM_PATH . 'includes/class-omm-update-checker.php';
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
 * Load translations. Needed for a self-hosted plugin — WordPress only
 * auto-loads language packs for plugins hosted on WordPress.org.
 */
add_action( 'init', function () {
	load_plugin_textdomain(
		'opcache-memcached-manager',
		false,
		dirname( plugin_basename( OMM_PLUGIN_FILE ) ) . '/languages'
	);
} );

/**
 * "Settings" link next to Deactivate on the plugins list screen — where
 * people look for it first.
 */
add_filter( 'plugin_action_links_' . plugin_basename( OMM_PLUGIN_FILE ), function ( $links ) {
	if ( ! current_user_can( OMM_CAPABILITY ) ) {
		return $links;
	}

	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=' . OMM_Admin::PAGE_SLUG ) ),
		esc_html__( 'Settings', 'opcache-memcached-manager' )
	);

	array_unshift( $links, $settings_link );

	return $links;
} );

/**
 * Wire the plugin into the standard Plugins → Installed Plugins update UI,
 * pointed at this repo's GitHub Releases instead of WordPress.org.
 */
add_action( 'plugins_loaded', function () {
	$updater = new OMM_Update_Checker( plugin_basename( OMM_PLUGIN_FILE ), OMM_VERSION );
	$updater->init();
} );

/**
 * Boot admin UI.
 */
if ( is_admin() ) {
	OMM_Admin::init();
}

/**
 * Boot page cache purge hooks (needs to run everywhere, not just admin,
 * since e.g. comment_post fires on the front end).
 */
OMM_PageCache::init();

/**
 * Boot WP-CLI commands.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once OMM_PATH . 'includes/class-omm-cli.php';
}
