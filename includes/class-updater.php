<?php
/**
 * Mises à jour automatiques via GitHub Releases.
 *
 * Utilise le mécanisme natif « Update URI » de WordPress (filtre update_plugins_github.com) :
 * le client voit une mise à jour classique dans Extensions, sans aucune mention de GitHub.
 *
 * Dépôt public : aucune configuration.
 * Dépôt privé : définir BCA_GITHUB_TOKEN (ou BCAMC_GITHUB_TOKEN) dans wp-config.php,
 * avec un token « fine-grained » en lecture seule limité à ce dépôt.
 *
 * Publication d'une version : passer la version dans bca-media-folders.php, puis
 * git tag vX.Y.Z && git push --tags. Le workflow GitHub construit le zip et la release.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_Updater {

	const SLUG       = 'bca-media-cleaner';
	const HOMEPAGE   = 'https://beclicked.agency';
	const ASSET_NAME = 'bca-media-cleaner.zip';
	const CACHE_KEY  = 'bcamc_release';
	const CACHE_TTL  = HOUR_IN_SECONDS;
	const FAIL_TTL   = 15 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 2 );
	}

	private static function token(): string {
		foreach ( array( 'BCAMC_GITHUB_TOKEN', 'BCA_GITHUB_TOKEN' ) as $constant ) {
			if ( defined( $constant ) && constant( $constant ) ) {
				return (string) constant( $constant );
			}
		}
		return '';
	}

	private static function api_base(): string {
		return 'https://api.github.com/repos/' . BCAMC_REPO;
	}

	/**
	 * Dernière release publiée (mise en cache).
	 *
	 * @return array{version:string,package:string,changelog:string,published:string}|null
	 */
	private static function release(): ?array {
		// « Vérifier à nouveau » (Tableau de bord > Mises à jour) ignore le cache.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$forced = is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
		// Ouvrir Tableau de bord > Mises à jour interroge aussi le dépôt en direct.
		$forced = $forced || doing_action( 'load-update-core.php' );

		$cached = $forced ? false : get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached ) ? null : $cached;
		}

		$response = wp_remote_get(
			self::api_base() . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Échec mémorisé une heure pour ne pas interroger GitHub à chaque page.
			set_site_transient( self::CACHE_KEY, array(), self::FAIL_TTL );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::CACHE_KEY, array(), self::FAIL_TTL );
			return null;
		}

		$package = '';
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'] ) && self::ASSET_NAME === $asset['name'] ) {
				// Dépôt privé : URL API de l'asset (avec token). Dépôt public : lien direct.
				$package = self::token() ? (string) $asset['url'] : (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package && ! empty( $body['zipball_url'] ) ) {
			$package = (string) $body['zipball_url'];
		}

		$release = array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'package'   => $package,
			'changelog' => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
		);

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		return $release;
	}

	public static function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( plugin_basename( BCAMC_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::release();
		if ( ! $release || '' === $release['package'] ) {
			return $update;
		}

		return array(
			'id'           => $plugin_data['UpdateURI'] ?? '',
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => self::HOMEPAGE,
			'package'      => $release['package'],
			'requires_php' => '8.1',
			'tested'       => '7.2',
			'icons'        => self::icons(),
		);
	}

	/**
	 * Logo affiché dans Tableau de bord > Mises à jour (au lieu de l'icône vide par défaut).
	 */
	private static function icons(): array {
		return array(
			'1x'  => BCAMC_URL . 'assets/icon-128x128.png',
			'2x'  => BCAMC_URL . 'assets/icon-256x256.png',
			'svg' => BCAMC_URL . 'assets/icon.svg',
		);
	}

	/**
	 * Fenêtre « Afficher les détails » dans Extensions.
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release   = self::release();
		$changelog = ( $release && '' !== $release['changelog'] )
			? wpautop( esc_html( $release['changelog'] ) )
			: '<p>' . esc_html__( 'Improvements and fixes.', 'bca-media-cleaner' ) . '</p>';

		return (object) array(
			'name'          => 'BCA Media Cleaner',
			'slug'          => self::SLUG,
			'version'       => $release ? $release['version'] : BCAMC_VERSION,
			'author'        => '<a href="https://beclicked.agency">Be Clicked Agency</a>',
			'homepage'      => self::HOMEPAGE,
			'requires'      => '6.4',
			'requires_php'  => '8.1',
			'last_updated'  => $release ? $release['published'] : '',
			'download_link' => $release ? $release['package'] : '',
			'icons'         => self::icons(),
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Find the media and files your site no longer uses, and see how much disk space you can recover.', 'bca-media-cleaner' ) . '</p>',
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * Requêtes vers ce dépôt uniquement (vérification et téléchargement des mises à jour) :
	 * identification neutre, et token si le dépôt est privé.
	 */
	public static function auth( $args, $url ) {
		if ( ! is_string( $url ) ) {
			return $args;
		}

		$is_api      = 0 === strpos( $url, self::api_base() . '/' );
		$is_download = 0 === strpos( $url, 'https://github.com/' . BCAMC_REPO . '/' );
		if ( ! $is_api && ! $is_download ) {
			return $args;
		}

		// Par défaut, WordPress envoie l'adresse du site dans son identification.
		// On la remplace par une identification neutre : GitHub ne sait pas quel site demande.
		$args['user-agent'] = 'BCA-Media-Cleaner/' . BCAMC_VERSION;

		$token = self::token();
		if ( '' === $token || ! $is_api ) {
			return $args;
		}

		$args['headers']                  = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
		$args['headers']['Authorization'] = 'Bearer ' . $token;

		// Téléchargement d'un asset de release privée : le binaire, pas sa description JSON.
		if ( false !== strpos( $url, '/releases/assets/' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}

		return $args;
	}

	/**
	 * Garantit que le dossier installé s'appelle bca-media-folders,
	 * même si le zip vient de l'archive automatique de GitHub.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( BCAMC_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $desired ) {
			return $source;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}

		return new WP_Error( 'bcamc_update', __( 'The BCA Media Cleaner update could not be prepared.', 'bca-media-cleaner' ) );
	}

	public static function flush( $upgrader, $options ): void {
		if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}
}
