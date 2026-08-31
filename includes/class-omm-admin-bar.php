<?php
/**
 * "Cache" menu in the wp-admin toolbar: a compact set of cache stats and
 * one-click clear controls, visible to administrators on the front end as
 * well as in wp-admin.
 *
 * The stat rows are deliberately limited to values that cost no I/O to
 * read on every page load — OPcache status (local), the configured server
 * count (an option), the object-cache backend (a global). Anything that
 * would touch a Memcached server (per-server stats, the page-cache size)
 * stays on the full Cache Manager screen, so a slow or unreachable server
 * can't stall every admin page.
 *
 * The action links point at the same admin-post.php handlers the settings
 * screen uses (OMM_Admin), passing an `omm_return` param so you land back
 * on the page you triggered them from.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OMM_Admin_Bar {

	const NODE_ID = 'omm-cache';

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'render' ), 100 );
	}

	public static function render( $wp_admin_bar ) {
		if ( ! current_user_can( OMM_CAPABILITY ) || ! is_admin_bar_showing() ) {
			return;
		}

		$wp_admin_bar->add_node( array(
			'id'    => self::NODE_ID,
			'title' => '<span class="ab-icon dashicons dashicons-performance" aria-hidden="true"></span>'
				. '<span class="ab-label">' . esc_html__( 'Cache', 'opcache-memcached-manager' ) . '</span>',
			'href'  => admin_url( 'admin.php?page=' . OMM_Admin::PAGE_SLUG ),
			'meta'  => array( 'title' => __( 'Open the Cache Manager screen', 'opcache-memcached-manager' ) ),
		) );

		foreach ( self::stat_lines() as $i => $line ) {
			$wp_admin_bar->add_node( array(
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-stat-' . $i,
				'title'  => esc_html( $line ),
			) );
		}

		$return = self::current_url();

		foreach ( self::actions() as $action ) {
			$url = add_query_arg(
				array(
					'action'     => $action['slug'],
					'omm_return' => $return,
					'_wpnonce'   => wp_create_nonce( $action['slug'] ),
				),
				admin_url( 'admin-post.php' )
			);

			$meta = array();
			if ( ! empty( $action['confirm'] ) ) {
				$meta['onclick'] = "return confirm('" . esc_js( $action['confirm'] ) . "');";
			}

			$wp_admin_bar->add_node( array(
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-' . $action['slug'],
				'title'  => esc_html( $action['label'] ),
				'href'   => $url,
				'meta'   => $meta,
			) );
		}
	}

	/**
	 * Cheap-to-read stats only — see the class docblock.
	 *
	 * @return string[]
	 */
	private static function stat_lines() {
		$lines = array();

		$opcache = OMM_OPcache::get_status();
		if ( is_wp_error( $opcache ) ) {
			$lines[] = __( 'OPcache: not active', 'opcache-memcached-manager' );
		} else {
			$lines[] = sprintf(
				/* translators: 1: hit-rate percentage, 2: memory-used percentage */
				__( 'OPcache: %1$s%% hits, %2$s%% memory', 'opcache-memcached-manager' ),
				$opcache['hit_rate_pct'],
				$opcache['memory_used_pct']
			);
		}

		if ( OMM_Memcached::is_available() ) {
			$server_count = count( OMM_Memcached::get_configured_servers() );
			$lines[]      = sprintf(
				/* translators: %s: number of configured Memcached servers */
				_n( 'Memcached: %s server configured', 'Memcached: %s servers configured', $server_count, 'opcache-memcached-manager' ),
				number_format_i18n( $server_count )
			);

			$lines[] = OMM_Memcached::is_wp_object_cache_backend()
				? __( 'Object cache: Memcached', 'opcache-memcached-manager' )
				: __( 'Object cache: default (per-request)', 'opcache-memcached-manager' );
		} else {
			$lines[] = __( 'Memcached: extension not installed', 'opcache-memcached-manager' );
		}

		return $lines;
	}

	/**
	 * @return array<int, array{slug:string, label:string, confirm?:string}>
	 */
	private static function actions() {
		return array(
			array(
				'slug'    => 'omm_clear_all',
				'label'   => __( 'Clear all caches', 'opcache-memcached-manager' ),
				'confirm' => __( 'Clear all caches now? This flushes the entire Memcached pool, so any other application sharing it is affected too.', 'opcache-memcached-manager' ),
			),
			array(
				'slug'  => 'omm_reset_opcache',
				'label' => __( 'Reset OPcache', 'opcache-memcached-manager' ),
			),
			array(
				'slug'  => 'omm_flush_memcached',
				'label' => __( 'Flush Memcached pool', 'opcache-memcached-manager' ),
			),
			array(
				'slug'  => 'omm_purge_pagecache',
				'label' => __( 'Purge page cache', 'opcache-memcached-manager' ),
			),
		);
	}

	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		if ( '' === $host ) {
			return admin_url( 'admin.php?page=' . OMM_Admin::PAGE_SLUG );
		}

		return esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}
}
