<?php
/**
 * wp-admin UI: a single "Cache Manager" page with status for OPcache and
 * Memcached, action buttons, and a simple settings form for the
 * Memcached server pool.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OMM_Admin {

	const PAGE_SLUG = 'omm-cache-manager';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_omm_reset_opcache', array( __CLASS__, 'handle_reset_opcache' ) );
		add_action( 'admin_post_omm_invalidate_file', array( __CLASS__, 'handle_invalidate_file' ) );
		add_action( 'admin_post_omm_flush_memcached', array( __CLASS__, 'handle_flush_memcached' ) );
		add_action( 'admin_post_omm_flush_object_cache', array( __CLASS__, 'handle_flush_object_cache' ) );
		add_action( 'admin_post_omm_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_omm_install_dropin', array( __CLASS__, 'handle_install_dropin' ) );
		add_action( 'admin_post_omm_remove_dropin', array( __CLASS__, 'handle_remove_dropin' ) );
		add_action( 'admin_post_omm_install_pagecache_dropin', array( __CLASS__, 'handle_install_pagecache_dropin' ) );
		add_action( 'admin_post_omm_remove_pagecache_dropin', array( __CLASS__, 'handle_remove_pagecache_dropin' ) );
		add_action( 'admin_post_omm_save_pagecache_settings', array( __CLASS__, 'handle_save_pagecache_settings' ) );
		add_action( 'admin_post_omm_purge_pagecache', array( __CLASS__, 'handle_purge_pagecache' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	public static function add_menu() {
		add_menu_page(
			__( 'Cache Manager', 'opcache-memcached-manager' ),
			__( 'Cache Manager', 'opcache-memcached-manager' ),
			OMM_CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-performance',
			76
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'omm-admin', OMM_URL . 'assets/admin.css', array(), OMM_VERSION );
	}

	private static function verify_capability() {
		if ( ! current_user_can( OMM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'opcache-memcached-manager' ) );
		}
	}

	private static function redirect_back( $args = array() ) {
		$url = add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/* -----------------------------------------------------------------
	 * Action handlers
	 * ------------------------------------------------------------- */

	public static function handle_reset_opcache() {
		self::verify_capability();
		check_admin_referer( 'omm_reset_opcache' );

		$result = OMM_OPcache::reset();

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'omm_notice' => 'opcache_error', 'omm_msg' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'omm_notice' => 'opcache_reset' ) );
	}

	public static function handle_invalidate_file() {
		self::verify_capability();
		check_admin_referer( 'omm_invalidate_file' );

		$path = isset( $_POST['omm_file_path'] ) ? wp_unslash( $_POST['omm_file_path'] ) : '';
		$result = OMM_OPcache::invalidate_file( $path );

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'omm_notice' => 'opcache_error', 'omm_msg' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'omm_notice' => 'opcache_invalidated' ) );
	}

	public static function handle_flush_memcached() {
		self::verify_capability();
		check_admin_referer( 'omm_flush_memcached' );

		$result = OMM_Memcached::flush();

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'omm_notice' => 'memcached_error', 'omm_msg' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'omm_notice' => 'memcached_flushed' ) );
	}

	public static function handle_flush_object_cache() {
		self::verify_capability();
		check_admin_referer( 'omm_flush_object_cache' );

		OMM_Memcached::flush_wp_object_cache();

		self::redirect_back( array( 'omm_notice' => 'object_cache_flushed' ) );
	}

	public static function handle_save_settings() {
		self::verify_capability();
		check_admin_referer( 'omm_save_settings' );

		$raw_lines = isset( $_POST['omm_memcached_servers'] ) ? wp_unslash( $_POST['omm_memcached_servers'] ) : '';
		$lines     = preg_split( '/[\r\n]+/', trim( $raw_lines ) );

		$servers = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// Accept "host:port" or "host:port:weight".
			$parts = array_map( 'trim', explode( ':', $line ) );
			$host  = isset( $parts[0] ) ? $parts[0] : '';
			$port  = isset( $parts[1] ) ? (int) $parts[1] : 11211;
			$weight = isset( $parts[2] ) ? (int) $parts[2] : 0;

			if ( '' === $host ) {
				continue;
			}

			$servers[] = array(
				'host'   => sanitize_text_field( $host ),
				'port'   => $port > 0 ? $port : 11211,
				'weight' => max( 0, $weight ),
			);
		}

		if ( empty( $servers ) ) {
			$servers = omm_default_settings()['memcached_servers'];
		}

		update_option( 'omm_settings', array( 'memcached_servers' => $servers ) );
		OMM_Dropin::sync_config();

		self::redirect_back( array( 'omm_notice' => 'settings_saved' ) );
	}

	public static function handle_install_dropin() {
		self::verify_capability();
		check_admin_referer( 'omm_install_dropin' );

		$overwrite = ! empty( $_POST['omm_overwrite_foreign'] );
		$result    = OMM_Dropin::install( $overwrite );

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'omm_notice' => 'dropin_error', 'omm_msg' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'omm_notice' => 'dropin_installed' ) );
	}

	public static function handle_remove_dropin() {
		self::verify_capability();
		check_admin_referer( 'omm_remove_dropin' );

		$result = OMM_Dropin::remove();

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'omm_notice' => 'dropin_error', 'omm_msg' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'omm_notice' => 'dropin_removed' ) );
	}

	public static function handle_install_pagecache_dropin() {
		self::verify_capability();
		check_admin_referer( 'omm_install_pagecache_dropin' );

		$overwrite = ! empty( $_POST['omm_overwrite_foreign'] );
		$result    = OMM_PageCache_Dropin::install( $overwrite );

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'omm_notice' => 'pagecache_dropin_error', 'omm_msg' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'omm_notice' => 'pagecache_dropin_installed' ) );
	}

	public static function handle_remove_pagecache_dropin() {
		self::verify_capability();
		check_admin_referer( 'omm_remove_pagecache_dropin' );

		$result = OMM_PageCache_Dropin::remove();

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'omm_notice' => 'pagecache_dropin_error', 'omm_msg' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'omm_notice' => 'pagecache_dropin_removed' ) );
	}

	public static function handle_save_pagecache_settings() {
		self::verify_capability();
		check_admin_referer( 'omm_save_pagecache_settings' );

		$settings = OMM_PageCache::get_settings();

		$settings['enabled'] = ! empty( $_POST['omm_pagecache_enabled'] );
		$settings['ttl']     = isset( $_POST['omm_pagecache_ttl'] ) ? max( 60, (int) $_POST['omm_pagecache_ttl'] ) : $settings['ttl'];

		$raw_patterns = isset( $_POST['omm_pagecache_excluded'] ) ? wp_unslash( $_POST['omm_pagecache_excluded'] ) : '';
		$patterns     = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', trim( $raw_patterns ) ) ) ) );

		// Validate each is a usable regex before saving; drop any that aren't.
		$valid_patterns = array();
		foreach ( $patterns as $pattern ) {
			if ( false !== @preg_match( $pattern, '' ) ) {
				$valid_patterns[] = $pattern;
			}
		}
		if ( ! empty( $valid_patterns ) ) {
			$settings['excluded_patterns'] = $valid_patterns;
		}

		$result = OMM_PageCache::save_settings( $settings );

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'omm_notice' => 'pagecache_dropin_error', 'omm_msg' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'omm_notice' => 'pagecache_settings_saved' ) );
	}

	public static function handle_purge_pagecache() {
		self::verify_capability();
		check_admin_referer( 'omm_purge_pagecache' );

		$result = OMM_PageCache::purge_all();

		if ( is_wp_error( $result ) ) {
			self::redirect_back( array( 'omm_notice' => 'pagecache_dropin_error', 'omm_msg' => rawurlencode( $result->get_error_message() ) ) );
		}

		self::redirect_back( array( 'omm_notice' => 'pagecache_purged' ) );
	}

	/* -----------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------- */

	public static function render_notices() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] || ! isset( $_GET['omm_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( $_GET['omm_notice'] );
		$msg    = isset( $_GET['omm_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['omm_msg'] ) ) : '';

		$map = array(
			'opcache_reset'        => array( 'success', __( 'OPcache was reset successfully.', 'opcache-memcached-manager' ) ),
			'opcache_invalidated'  => array( 'success', __( 'File invalidated in OPcache.', 'opcache-memcached-manager' ) ),
			'opcache_error'        => array( 'error', $msg ?: __( 'An OPcache error occurred.', 'opcache-memcached-manager' ) ),
			'memcached_flushed'    => array( 'success', __( 'Memcached pool was flushed successfully.', 'opcache-memcached-manager' ) ),
			'memcached_error'      => array( 'error', $msg ?: __( 'A Memcached error occurred.', 'opcache-memcached-manager' ) ),
			'object_cache_flushed' => array( 'success', __( 'WordPress object cache was flushed.', 'opcache-memcached-manager' ) ),
			'settings_saved'       => array( 'success', __( 'Settings saved.', 'opcache-memcached-manager' ) ),
			'dropin_installed'     => array( 'success', __( 'object-cache.php drop-in installed. WordPress will use Memcached as its object cache from the next request onward.', 'opcache-memcached-manager' ) ),
			'dropin_removed'       => array( 'success', __( 'object-cache.php drop-in removed.', 'opcache-memcached-manager' ) ),
			'dropin_error'         => array( 'error', $msg ?: __( 'A drop-in error occurred.', 'opcache-memcached-manager' ) ),
			'pagecache_dropin_installed' => array( 'success', __( 'Page cache drop-in installed.', 'opcache-memcached-manager' ) ),
			'pagecache_dropin_removed'   => array( 'success', __( 'Page cache drop-in removed.', 'opcache-memcached-manager' ) ),
			'pagecache_dropin_error'     => array( 'error', $msg ?: __( 'A page cache error occurred.', 'opcache-memcached-manager' ) ),
			'pagecache_settings_saved'   => array( 'success', __( 'Page cache settings saved.', 'opcache-memcached-manager' ) ),
			'pagecache_purged'           => array( 'success', __( 'Page cache purged.', 'opcache-memcached-manager' ) ),
		);

		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		list( $type, $text ) = $map[ $notice ];
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	/* -----------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------- */

	public static function render_page() {
		self::verify_capability();

		$opcache_status = OMM_OPcache::get_status();
		$memcached_stats = OMM_Memcached::get_stats();
		$servers = OMM_Memcached::get_configured_servers();
		$reachability = OMM_Memcached::get_server_reachability();

		echo '<div class="wrap omm-wrap">';
		echo '<h1>' . esc_html__( 'Cache Manager', 'opcache-memcached-manager' ) . '</h1>';

		self::render_opcache_section( $opcache_status );
		self::render_memcached_section( $memcached_stats, $reachability );
		self::render_dropin_section();
		self::render_pagecache_section();
		self::render_settings_section( $servers );

		echo '</div>';
	}

	private static function render_opcache_section( $status ) {
		echo '<h2>' . esc_html__( 'OPcache', 'opcache-memcached-manager' ) . '</h2>';
		echo '<div class="card omm-card">';

		if ( is_wp_error( $status ) ) {
			echo '<p>' . esc_html( $status->get_error_message() ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped omm-stat-table">';
		self::stat_row( __( 'Status', 'opcache-memcached-manager' ), $status['enabled'] ? __( 'Enabled', 'opcache-memcached-manager' ) : __( 'Disabled', 'opcache-memcached-manager' ) );
		self::stat_row( __( 'Version', 'opcache-memcached-manager' ), $status['version'] );
		self::stat_row( __( 'Memory used', 'opcache-memcached-manager' ), sprintf(
			'%s / %s (%s%%)',
			OMM_OPcache::format_bytes( $status['memory_used_bytes'] ),
			OMM_OPcache::format_bytes( $status['memory_total_bytes'] ),
			$status['memory_used_pct']
		) );
		self::stat_row( __( 'Wasted memory', 'opcache-memcached-manager' ), OMM_OPcache::format_bytes( $status['memory_wasted_bytes'] ) );
		self::stat_row( __( 'Cached scripts', 'opcache-memcached-manager' ), sprintf( '%d', $status['num_cached_scripts'] ) );
		self::stat_row( __( 'Cached keys', 'opcache-memcached-manager' ), sprintf( '%d / %d', $status['num_cached_keys'], $status['max_cached_keys'] ) );
		self::stat_row( __( 'Hit rate', 'opcache-memcached-manager' ), sprintf( '%s%% (%d hits / %d misses)', $status['hit_rate_pct'], $status['hits'], $status['misses'] ) );
		self::stat_row( __( 'Cache full', 'opcache-memcached-manager' ), $status['cache_full'] ? __( 'Yes — consider increasing opcache.memory_consumption', 'opcache-memcached-manager' ) : __( 'No', 'opcache-memcached-manager' ) );
		self::stat_row( __( 'Validate timestamps', 'opcache-memcached-manager' ), $status['validate_timestamps'] ? __( 'On (files checked for changes)', 'opcache-memcached-manager' ) : __( 'Off (production mode — changed files need manual invalidation)', 'opcache-memcached-manager' ) );
		self::stat_row( __( 'Last restart', 'opcache-memcached-manager' ), $status['last_restart_time'] ? human_time_diff( $status['last_restart_time'] ) . ' ' . __( 'ago', 'opcache-memcached-manager' ) : __( 'Never', 'opcache-memcached-manager' ) );
		echo '</table>';

		echo '<p class="omm-actions">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		echo '<input type="hidden" name="action" value="omm_reset_opcache" />';
		wp_nonce_field( 'omm_reset_opcache' );
		submit_button( __( 'Reset entire OPcache', 'opcache-memcached-manager' ), 'primary', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Reset the entire OPcache now? This will cause a brief compilation spike on next requests.', 'opcache-memcached-manager' ) ) . "');" ) );
		echo '</form>';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Invalidate a single file', 'opcache-memcached-manager' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="omm_invalidate_file" />';
		wp_nonce_field( 'omm_invalidate_file' );
		echo '<input type="text" name="omm_file_path" class="regular-text" placeholder="' . esc_attr( ABSPATH . 'wp-content/plugins/example/example.php' ) . '" style="width:480px;" />';
		submit_button( __( 'Invalidate file', 'opcache-memcached-manager' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '</div>';
	}

	private static function render_memcached_section( $stats, $reachability ) {
		echo '<h2>' . esc_html__( 'Memcached', 'opcache-memcached-manager' ) . '</h2>';
		echo '<div class="card omm-card">';

		if ( ! OMM_Memcached::is_available() ) {
			echo '<p>' . esc_html__( 'The Memcached PHP extension is not installed on this server.', 'opcache-memcached-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<h3>' . esc_html__( 'Configured servers', 'opcache-memcached-manager' ) . '</h3>';
		echo '<table class="widefat striped omm-stat-table">';
		echo '<thead><tr><th>' . esc_html__( 'Server', 'opcache-memcached-manager' ) . '</th><th>' . esc_html__( 'Reachable', 'opcache-memcached-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $reachability as $server ) {
			printf(
				'<tr><td>%1$s:%2$d</td><td>%3$s</td></tr>',
				esc_html( $server['host'] ),
				(int) $server['port'],
				$server['reachable'] ? '✅ ' . esc_html__( 'Yes', 'opcache-memcached-manager' ) : '❌ ' . esc_html__( 'No', 'opcache-memcached-manager' )
			);
		}
		echo '</tbody></table>';

		if ( is_wp_error( $stats ) ) {
			echo '<p style="margin-top:12px;">' . esc_html( $stats->get_error_message() ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( 'Aggregate stats', 'opcache-memcached-manager' ) . '</h3>';
			echo '<table class="widefat striped omm-stat-table">';
			self::stat_row( __( 'Hit rate', 'opcache-memcached-manager' ), $stats['total_hit_rate_pct'] . '%' );
			self::stat_row( __( 'Items stored', 'opcache-memcached-manager' ), number_format_i18n( $stats['total_items'] ) );
			self::stat_row( __( 'Memory used', 'opcache-memcached-manager' ), OMM_OPcache::format_bytes( $stats['total_bytes'] ) . ( $stats['total_limit_bytes'] ? ' / ' . OMM_OPcache::format_bytes( $stats['total_limit_bytes'] ) : '' ) );
			self::stat_row( __( 'Backing WP object cache?', 'opcache-memcached-manager' ), $stats['is_object_cache'] ? __( 'Yes — this pool serves as the WordPress object cache', 'opcache-memcached-manager' ) : __( 'No — WordPress is not currently using Memcached as its object cache', 'opcache-memcached-manager' ) );
			echo '</table>';

			echo '<h3>' . esc_html__( 'Per-server detail', 'opcache-memcached-manager' ) . '</h3>';
			echo '<table class="widefat striped omm-stat-table">';
			echo '<thead><tr>';
			foreach ( array( 'Server', 'Uptime', 'Items', 'Bytes', 'Hit rate', 'Connections', 'Evictions' ) as $h ) {
				echo '<th>' . esc_html__( $h, 'opcache-memcached-manager' ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $stats['servers'] as $s ) {
				if ( empty( $s['reachable'] ) ) {
					printf( '<tr><td>%s</td><td colspan="6">%s</td></tr>', esc_html( $s['server'] ), esc_html__( 'Unreachable', 'opcache-memcached-manager' ) );
					continue;
				}
				printf(
					'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s%%</td><td>%6$d</td><td>%7$d</td></tr>',
					esc_html( $s['server'] ),
					esc_html( human_time_diff( time() - $s['uptime'] ) ),
					esc_html( number_format_i18n( $s['curr_items'] ) ),
					esc_html( OMM_OPcache::format_bytes( $s['bytes'] ) ),
					esc_html( $s['hit_rate_pct'] ),
					(int) $s['curr_connections'],
					(int) $s['evictions']
				);
			}
			echo '</tbody></table>';
		}

		echo '<p class="omm-actions" style="margin-top:12px;">';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		echo '<input type="hidden" name="action" value="omm_flush_memcached" />';
		wp_nonce_field( 'omm_flush_memcached' );
		submit_button( __( 'Flush Memcached pool', 'opcache-memcached-manager' ), 'primary', 'submit', false, array( 'onclick' => "return confirm('" . esc_js( __( 'Flush ALL keys on the configured Memcached server(s)? This cannot be undone.', 'opcache-memcached-manager' ) ) . "');" ) );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
		echo '<input type="hidden" name="action" value="omm_flush_object_cache" />';
		wp_nonce_field( 'omm_flush_object_cache' );
		submit_button( __( 'Flush WP object cache', 'opcache-memcached-manager' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '</p>';
		echo '<p class="description">' . esc_html__( '"Flush Memcached pool" talks to the servers configured below directly. "Flush WP object cache" calls wp_cache_flush(), which only clears Memcached if WordPress is currently using it as the object cache backend (see above).', 'opcache-memcached-manager' ) . '</p>';

		echo '</div>';
	}

	private static function render_dropin_section() {
		echo '<h2>' . esc_html__( 'Object Cache Drop-in', 'opcache-memcached-manager' ) . '</h2>';
		echo '<div class="card omm-card">';

		if ( ! OMM_Memcached::is_available() ) {
			echo '<p>' . esc_html__( 'The Memcached PHP extension is not installed, so a drop-in cannot be installed.', 'opcache-memcached-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		$status = OMM_Dropin::get_status();

		$status_labels = array(
			'not_installed' => __( 'Not installed — WordPress is not using Memcached as its object cache.', 'opcache-memcached-manager' ),
			'ours'           => __( 'Installed and up to date. WordPress is using Memcached (via this plugin\'s drop-in) as its object cache.', 'opcache-memcached-manager' ),
			'outdated'       => __( 'Installed, but an older version. Reinstall to update.', 'opcache-memcached-manager' ),
			'foreign'        => __( 'A different object-cache.php is already present — it was not created by this plugin.', 'opcache-memcached-manager' ),
		);

		echo '<p>' . esc_html( $status_labels[ $status ] ?? '' ) . '</p>';

		if ( in_array( $status, array( 'not_installed', 'outdated' ), true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
			echo '<input type="hidden" name="action" value="omm_install_dropin" />';
			wp_nonce_field( 'omm_install_dropin' );
			submit_button( 'outdated' === $status ? __( 'Update drop-in', 'opcache-memcached-manager' ) : __( 'Install drop-in', 'opcache-memcached-manager' ), 'primary', 'submit', false );
			echo '</form>';
		}

		if ( 'foreign' === $status ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
			echo '<input type="hidden" name="action" value="omm_install_dropin" />';
			echo '<input type="hidden" name="omm_overwrite_foreign" value="1" />';
			wp_nonce_field( 'omm_install_dropin' );
			submit_button(
				__( 'Overwrite existing drop-in', 'opcache-memcached-manager' ),
				'primary',
				'submit',
				false,
				array( 'onclick' => "return confirm('" . esc_js( __( 'This will replace the existing object-cache.php with this plugin\'s version. Continue?', 'opcache-memcached-manager' ) ) . "');" )
			);
			echo '</form>';
		}

		if ( in_array( $status, array( 'ours', 'outdated' ), true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
			echo '<input type="hidden" name="action" value="omm_remove_dropin" />';
			wp_nonce_field( 'omm_remove_dropin' );
			submit_button(
				__( 'Remove drop-in', 'opcache-memcached-manager' ),
				'secondary',
				'submit',
				false,
				array( 'onclick' => "return confirm('" . esc_js( __( 'Remove object-cache.php? WordPress will fall back to its default (non-persistent) object cache.', 'opcache-memcached-manager' ) ) . "');" )
			);
			echo '</form>';
		}

		echo '<p class="description" style="margin-top:12px;">' . esc_html__( 'The drop-in reads the Memcached server list from plugin settings below, kept in sync automatically each time you save.', 'opcache-memcached-manager' ) . '</p>';

		echo '</div>';
	}

	private static function render_pagecache_section() {
		echo '<h2>' . esc_html__( 'Page Cache', 'opcache-memcached-manager' ) . '</h2>';
		echo '<div class="card omm-card">';

		if ( ! OMM_Memcached::is_available() ) {
			echo '<p>' . esc_html__( 'The Memcached PHP extension is not installed, so page caching is unavailable.', 'opcache-memcached-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		$status         = OMM_PageCache_Dropin::get_status();
		$wp_cache_on    = OMM_PageCache_Dropin::is_wp_cache_constant_enabled();
		$settings       = OMM_PageCache::get_settings();
		$cached_count   = OMM_PageCache::get_cached_count();

		$status_labels = array(
			'not_installed' => __( 'Drop-in not installed.', 'opcache-memcached-manager' ),
			'ours'           => __( 'Drop-in installed and up to date.', 'opcache-memcached-manager' ),
			'outdated'       => __( 'Drop-in installed, but an older version. Reinstall to update.', 'opcache-memcached-manager' ),
			'foreign'        => __( 'A different advanced-cache.php is already present — it was not created by this plugin.', 'opcache-memcached-manager' ),
		);

		echo '<p>' . esc_html( $status_labels[ $status ] ?? '' ) . '</p>';

		if ( ! $wp_cache_on ) {
			echo '<div class="notice notice-warning inline"><p>';
			printf(
				/* translators: %s: PHP code snippet */
				esc_html__( 'WP_CACHE is not enabled, so advanced-cache.php will not be loaded even once installed. Add this line near the top of wp-config.php (right after the opening %1$s tag), before the "That\'s all, stop editing!" comment:', 'opcache-memcached-manager' ),
				'<code>&lt;?php</code>'
			);
			echo '</p><p><code>define( \'WP_CACHE\', true );</code></p></div>';
		}

		if ( in_array( $status, array( 'not_installed', 'outdated' ), true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
			echo '<input type="hidden" name="action" value="omm_install_pagecache_dropin" />';
			wp_nonce_field( 'omm_install_pagecache_dropin' );
			submit_button( 'outdated' === $status ? __( 'Update drop-in', 'opcache-memcached-manager' ) : __( 'Install drop-in', 'opcache-memcached-manager' ), 'primary', 'submit', false );
			echo '</form>';
		}

		if ( 'foreign' === $status ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
			echo '<input type="hidden" name="action" value="omm_install_pagecache_dropin" />';
			echo '<input type="hidden" name="omm_overwrite_foreign" value="1" />';
			wp_nonce_field( 'omm_install_pagecache_dropin' );
			submit_button(
				__( 'Overwrite existing drop-in', 'opcache-memcached-manager' ),
				'primary',
				'submit',
				false,
				array( 'onclick' => "return confirm('" . esc_js( __( 'This will replace the existing advanced-cache.php with this plugin\'s version. Continue?', 'opcache-memcached-manager' ) ) . "');" )
			);
			echo '</form>';
		}

		if ( in_array( $status, array( 'ours', 'outdated' ), true ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;">';
			echo '<input type="hidden" name="action" value="omm_remove_pagecache_dropin" />';
			wp_nonce_field( 'omm_remove_pagecache_dropin' );
			submit_button(
				__( 'Remove drop-in', 'opcache-memcached-manager' ),
				'secondary',
				'submit',
				false,
				array( 'onclick' => "return confirm('" . esc_js( __( 'Remove advanced-cache.php? Pages will no longer be served from cache.', 'opcache-memcached-manager' ) ) . "');" )
			);
			echo '</form>';
		}

		if ( in_array( $status, array( 'ours', 'outdated' ), true ) ) {
			echo '<h3 style="margin-top:20px;">' . esc_html__( 'Settings', 'opcache-memcached-manager' ) . '</h3>';
			echo '<p>' . sprintf( esc_html__( 'Currently tracking %d cached page(s).', 'opcache-memcached-manager' ), (int) $cached_count ) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="omm_save_pagecache_settings" />';
			wp_nonce_field( 'omm_save_pagecache_settings' );

			echo '<table class="form-table"><tbody>';
			echo '<tr><th>' . esc_html__( 'Enabled', 'opcache-memcached-manager' ) . '</th><td><label><input type="checkbox" name="omm_pagecache_enabled" value="1"' . checked( ! empty( $settings['enabled'] ), true, false ) . ' /> ' . esc_html__( 'Serve pages from cache', 'opcache-memcached-manager' ) . '</label></td></tr>';
			echo '<tr><th>' . esc_html__( 'TTL (seconds)', 'opcache-memcached-manager' ) . '</th><td><input type="number" min="60" name="omm_pagecache_ttl" value="' . esc_attr( (int) $settings['ttl'] ) . '" class="small-text" /></td></tr>';
			echo '<tr><th>' . esc_html__( 'Excluded path patterns', 'opcache-memcached-manager' ) . '</th><td><textarea name="omm_pagecache_excluded" rows="6" cols="50" class="large-text code">' . esc_textarea( implode( "\n", (array) $settings['excluded_patterns'] ) ) . '</textarea><p class="description">' . esc_html__( 'One PHP regex per line, matched against the request path (e.g. #^/wp-admin#). Requests with a query string, non-GET requests, and logged-in/commenter visitors are always excluded.', 'opcache-memcached-manager' ) . '</p></td></tr>';
			echo '</tbody></table>';

			submit_button( __( 'Save page cache settings', 'opcache-memcached-manager' ) );
			echo '</form>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;">';
			echo '<input type="hidden" name="action" value="omm_purge_pagecache" />';
			wp_nonce_field( 'omm_purge_pagecache' );
			submit_button( __( 'Purge entire page cache', 'opcache-memcached-manager' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		echo '<p class="description" style="margin-top:12px;">' . esc_html__( 'Content changes (publishing/editing/deleting posts, approved comments) automatically purge just the affected URLs — the post itself plus its home page, author archive, date archive, and taxonomy archives. Theme switches and plugin updates purge the entire page cache.', 'opcache-memcached-manager' ) . '</p>';

		echo '</div>';
	}

	private static function render_settings_section( $servers ) {
		echo '<h2>' . esc_html__( 'Memcached server settings', 'opcache-memcached-manager' ) . '</h2>';
		echo '<div class="card omm-card">';

		$lines = array();
		foreach ( $servers as $s ) {
			$lines[] = $s['host'] . ':' . $s['port'] . ( $s['weight'] ? ':' . $s['weight'] : '' );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="omm_save_settings" />';
		wp_nonce_field( 'omm_save_settings' );
		echo '<p>' . esc_html__( 'One server per line, as host:port or host:port:weight.', 'opcache-memcached-manager' ) . '</p>';
		echo '<textarea name="omm_memcached_servers" rows="5" cols="50" class="large-text code">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea>';
		submit_button( __( 'Save servers', 'opcache-memcached-manager' ) );
		echo '</form>';

		echo '</div>';
	}

	private static function stat_row( $label, $value ) {
		printf(
			'<tr><th style="width:260px;text-align:left;">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}
}
