<?php
/**
 * Minimal GitHub Releases update checker. Dependency-free, for the public
 * repo at github.com/jesgs-interactive/opcache-memcached-manager.
 *
 * Adapted from the wordpress-plugin-scaffold skill's canonical UpdateChecker.
 * The only structural change is dropping the namespace, to match the rest of
 * this plugin. Plugin-specific values live in the constants below — keep them
 * there rather than scattered through the methods, so this stays close to the
 * canonical source if it's ever re-synced.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OMM_Update_Checker {

	/** @var string Plugin basename, e.g. "opcache-memcached-manager/opcache-memcached-manager.php". */
	protected $plugin_basename;

	/** @var string Installed version, from the plugin header constant. */
	protected $current_version;

	const GITHUB_REPO         = 'jesgs-interactive/opcache-memcached-manager';
	const GITHUB_RELEASES_URL = 'https://api.github.com/repos/jesgs-interactive/opcache-memcached-manager/releases/latest';
	const PLUGIN_NAME         = 'OPcache & Memcached Manager';
	const PLUGIN_AUTHOR       = 'Jess G.';

	public function __construct( $plugin_basename, $current_version ) {
		$this->plugin_basename = $plugin_basename;
		$this->current_version = (string) $current_version;
	}

	public function init() {
		add_filter( 'site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
	}

	protected function get_slug() {
		return basename( $this->plugin_basename, '.php' );
	}

	/**
	 * Whether the installed version string is a real release, or still the
	 * unreplaced build placeholder from a raw git checkout.
	 */
	protected function is_real_version() {
		if ( '' === $this->current_version || false !== strpos( $this->current_version, '{{' ) ) {
			return defined( 'WP_DEBUG' ) && WP_DEBUG;
		}
		return true;
	}

	public function check_update( $transient ) {
		if ( ! $this->is_real_version() || ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return $transient;
		}

		$remote_version = ltrim( (string) $release['tag_name'], 'vV' );
		if ( ! version_compare( $remote_version, $this->current_version, '>' ) ) {
			return $transient;
		}

		$package = $this->find_release_asset_url( $release );
		if ( '' === $package ) {
			return $transient; // No properly named asset — don't offer an update that would install to the wrong directory.
		}

		$update              = new stdClass();
		$update->slug        = $this->get_slug();
		$update->plugin      = $this->plugin_basename;
		$update->new_version = $remote_version;
		$update->url         = $release['html_url'] ?? 'https://github.com/' . self::GITHUB_REPO;
		$update->package     = $package;

		$transient->response[ $this->plugin_basename ] = $update;

		return $transient;
	}

	public function plugins_api( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->get_slug() ) {
			return $res;
		}

		$release = $this->get_latest_release();
		if ( ! is_array( $release ) ) {
			return $res;
		}

		$download_link = $this->find_release_asset_url( $release );
		if ( '' === $download_link ) {
			return $res;
		}

		$info                = new stdClass();
		$info->name          = self::PLUGIN_NAME;
		$info->slug          = $this->get_slug();
		$info->version       = ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' ) ?: $this->current_version;
		$info->author        = self::PLUGIN_AUTHOR;
		$info->homepage      = $release['html_url'] ?? 'https://github.com/' . self::GITHUB_REPO;
		$info->download_link = $download_link;
		$info->sections      = array(
			'description' => esc_html__( 'Monitor and manage OPcache and Memcached from wp-admin, with matching WP-CLI commands.', 'opcache-memcached-manager' ),
			'changelog'   => ! empty( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : '',
		);

		return $info;
	}

	protected function find_release_asset_url( array $release ) {
		$slug = $this->get_slug();
		foreach ( $release['assets'] ?? array() as $asset ) {
			$name = $asset['name'] ?? '';
			$url  = $asset['browser_download_url'] ?? '';
			if ( '' === $name || '' === $url ) {
				continue;
			}
			if ( 0 === strcasecmp( $name, $slug . '.zip' )
				|| ( false !== stripos( $name, $slug ) && '.zip' === strtolower( substr( $name, -4 ) ) )
			) {
				return $url;
			}
		}
		return '';
	}

	protected function get_latest_release() {
		$transient_key = 'omm_github_release_' . md5( self::GITHUB_REPO );
		$cached        = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( self::GITHUB_RELEASES_URL, array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => self::PLUGIN_NAME . ' Updater',
			),
		) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the miss briefly so a flaky API or an exhausted rate limit
			// doesn't mean an uncached outbound request on every admin page load.
			set_transient( $transient_key, array(), 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			set_transient( $transient_key, array(), 15 * MINUTE_IN_SECONDS );
			return null;
		}

		set_transient( $transient_key, $decoded, 6 * HOUR_IN_SECONDS );

		return $decoded;
	}
}
