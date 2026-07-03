<?php
/**
 * WP-CLI commands for OPcache and Memcached management.
 *
 *   wp cache-manager opcache status
 *   wp cache-manager opcache clear
 *   wp cache-manager opcache invalidate <file>
 *
 *   wp cache-manager memcached status
 *   wp cache-manager memcached flush
 *   wp cache-manager memcached flush-object-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage OPcache from the command line.
 */
class OMM_OPcache_CLI_Command {

	/**
	 * Show OPcache status and stats.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cache-manager opcache status
	 *     wp cache-manager opcache status --format=json
	 */
	public function status( $args, $assoc_args ) {
		$status = OMM_OPcache::get_status();

		if ( is_wp_error( $status ) ) {
			WP_CLI::error( $status->get_error_message() );
		}

		$rows = array();
		foreach ( $status as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}
			$rows[] = array( 'field' => $key, 'value' => $value );
		}

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Reset (clear) the entire OPcache.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cache-manager opcache clear
	 *     wp cache-manager opcache clear --yes
	 */
	public function clear( $args, $assoc_args ) {
		WP_CLI::confirm( 'Reset the entire OPcache now?', $assoc_args );

		$result = OMM_OPcache::reset();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'OPcache reset.' );
	}

	/**
	 * Invalidate a single cached file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Absolute (or ABSPATH-relative) path to the file to invalidate.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cache-manager opcache invalidate wp-content/plugins/my-plugin/my-plugin.php
	 */
	public function invalidate( $args, $assoc_args ) {
		$path = $args[0];

		if ( ! path_is_absolute( $path ) ) {
			$path = trailingslashit( ABSPATH ) . ltrim( $path, '/' );
		}

		$result = OMM_OPcache::invalidate_file( $path );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Invalidated: {$path}" );
	}
}

/**
 * Manage Memcached from the command line.
 */
class OMM_Memcached_CLI_Command {

	/**
	 * Show Memcached status and stats.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp cache-manager memcached status
	 *     wp cache-manager memcached status --format=json
	 */
	public function status( $args, $assoc_args ) {
		if ( ! OMM_Memcached::is_available() ) {
			WP_CLI::error( 'The Memcached PHP extension is not installed on this server.' );
		}

		$reachability = OMM_Memcached::get_server_reachability();
		WP_CLI::log( 'Configured servers:' );
		WP_CLI\Utils\format_items(
			'table',
			array_map( function ( $s ) {
				return array(
					'server'    => $s['host'] . ':' . $s['port'],
					'reachable' => $s['reachable'] ? 'yes' : 'no',
				);
			}, $reachability ),
			array( 'server', 'reachable' )
		);

		$stats = OMM_Memcached::get_stats();

		if ( is_wp_error( $stats ) ) {
			WP_CLI::error( $stats->get_error_message() );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Backing WordPress object cache: ' . ( $stats['is_object_cache'] ? 'yes' : 'no' ) );

		$rows = array();
		foreach ( $stats['servers'] as $s ) {
			if ( empty( $s['reachable'] ) ) {
				$rows[] = array( 'server' => $s['server'], 'reachable' => 'no' );
				continue;
			}
			$rows[] = array(
				'server'      => $s['server'],
				'reachable'   => 'yes',
				'items'       => $s['curr_items'],
				'bytes'       => size_format( $s['bytes'], 2 ),
				'hit_rate'    => $s['hit_rate_pct'] . '%',
				'connections' => $s['curr_connections'],
				'evictions'   => $s['evictions'],
			);
		}

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'server', 'reachable', 'items', 'bytes', 'hit_rate', 'connections', 'evictions' ) );
	}

	/**
	 * Flush the configured Memcached pool directly (all keys, all servers).
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cache-manager memcached flush
	 *     wp cache-manager memcached flush --yes
	 */
	public function flush( $args, $assoc_args ) {
		WP_CLI::confirm( 'Flush ALL keys on the configured Memcached server(s)? This cannot be undone.', $assoc_args );

		$result = OMM_Memcached::flush();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Memcached pool flushed.' );
	}

	/**
	 * Flush the WordPress object cache (wp_cache_flush()).
	 *
	 * Only actually clears Memcached data if WordPress is currently using
	 * Memcached as its object cache backend; otherwise it flushes whatever
	 * backend is active (e.g. in-memory, or another persistent cache).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cache-manager memcached flush-object-cache
	 */
	public function flush_object_cache( $args, $assoc_args ) {
		$is_object_cache = OMM_Memcached::is_wp_object_cache_backend();

		if ( ! $is_object_cache ) {
			WP_CLI::warning( 'WordPress is not currently using Memcached as its object cache backend. Flushing anyway.' );
		}

		OMM_Memcached::flush_wp_object_cache();
		WP_CLI::success( 'WordPress object cache flushed.' );
	}

	/**
	 * Install the Memcached object-cache.php drop-in.
	 *
	 * ## OPTIONS
	 *
	 * [--overwrite]
	 * : Overwrite an existing object-cache.php that wasn't created by this plugin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cache-manager memcached install-dropin
	 *     wp cache-manager memcached install-dropin --overwrite
	 */
	public function install_dropin( $args, $assoc_args ) {
		$overwrite = WP_CLI\Utils\get_flag_value( $assoc_args, 'overwrite', false );
		$result    = OMM_Dropin::install( (bool) $overwrite );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'object-cache.php drop-in installed.' );
	}

	/**
	 * Remove the Memcached object-cache.php drop-in, if it was installed by this plugin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cache-manager memcached remove-dropin
	 */
	public function remove_dropin( $args, $assoc_args ) {
		$result = OMM_Dropin::remove();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'object-cache.php drop-in removed.' );
	}
}

WP_CLI::add_command( 'cache-manager opcache', 'OMM_OPcache_CLI_Command' );
WP_CLI::add_command( 'cache-manager memcached', 'OMM_Memcached_CLI_Command' );
