<?php
/**
 * Pilote du scan : enchaîne les étapes par lots, garde un curseur pour reprendre.
 *
 *   1. index      lit toutes les sources et enregistre chaque référence trouvée
 *   2. inventory  parcourt les médias (catégories A et B)
 *   3. files      parcourt le dossier d'uploads (catégorie C et poids des dossiers)
 *
 * Strictement en lecture seule vis-à-vis du site : seules les tables de l'extension sont écrites.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_Scanner {

	const OPTION = 'bcamc_scan';

	/** Poids de chaque étape dans la barre de progression. */
	const WEIGHTS = array(
		'index'     => 0.6,
		'inventory' => 0.25,
		'files'     => 0.15,
	);

	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	public static function is_running(): bool {
		return 'running' === ( self::state()['status'] ?? '' );
	}

	/**
	 * Démarre un nouveau scan (efface les résultats précédents).
	 */
	public static function start( array $overrides = array() ): array {
		BCAMC_DB::maybe_upgrade();
		BCAMC_DB::reset_scan_data();

		$settings = array_merge( BCAMC_Settings::get(), $overrides );
		$settings = BCAMC_Settings::sanitize( $settings );

		// Noms des champs média (ACF, JetEngine) : utiles dès le comptage des colonnes de tables.
		$media_keys = BCAMC_Sources::media_field_keys();
		BCAMC_Extractor::set_media_keys( $media_keys );
		BCAMC_Sources::flush();

		$sources = BCAMC_Sources::all();
		$totals  = array();
		foreach ( $sources as $key => $source ) {
			$totals[ $key ] = max( 0, (int) call_user_func( $source['count'] ) );
		}

		$state = array(
			'status'      => 'running',
			'started_at'  => time(),
			'finished_at' => 0,
			'settings'    => $settings,
			'media_keys'  => $media_keys,
			'cutoff'      => time() - $settings['min_age_days'] * DAY_IN_SECONDS,
			'phase'       => 'index',
			'sources'     => array_keys( $sources ),
			'source_i'    => 0,
			'cursor'      => 0,
			'totals'      => array(
				'index'     => max( 1, array_sum( $totals ) ),
				'inventory' => max( 1, BCAMC_Inventory::count_attachments() ),
				'files'     => 1,
			),
			'done'        => array(
				'index'     => 0,
				'inventory' => 0,
				'files'     => 0,
			),
			'units'       => array(),
			'stats'       => array(
				'attachments'   => BCAMC_Inventory::count_attachments(),
				'media_bytes'   => 0,
				'uploads_bytes' => 0,
				'folders'       => array(),
			),
		);

		self::save( $state );
		return self::progress( $state );
	}

	/**
	 * Avance le scan jusqu'à épuisement du budget de temps ou de mémoire.
	 *
	 * @param float $budget Secondes disponibles (0 = sans limite, pour WP-CLI).
	 */
	public static function step( float $budget = 6.0 ): array {
		$state = self::state();
		if ( 'running' !== ( $state['status'] ?? '' ) ) {
			return self::progress( $state );
		}

		$start      = microtime( true );
		$mem_limit  = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$mem_guard  = $mem_limit > 0 ? (int) ( $mem_limit * 0.7 ) : PHP_INT_MAX;
		$batch_size = (int) $state['settings']['batch_size'];

		do {
			switch ( $state['phase'] ) {
				case 'index':
					$state = self::index_batch( $state, $batch_size );
					break;
				case 'inventory':
					$state = self::inventory_batch( $state, $batch_size );
					break;
				case 'files':
					$state = self::files_batch( $state );
					break;
				default:
					$state['status'] = 'done';
			}

			self::save( $state );

			if ( 'done' === $state['phase'] ) {
				$state['status']      = 'done';
				$state['finished_at'] = time();
				self::save( $state );
				break;
			}

			$elapsed = microtime( true ) - $start;
		} while ( ( $budget <= 0 || $elapsed < $budget ) && memory_get_usage() < $mem_guard );

		return self::progress( $state );
	}

	/**
	 * Scan complet sans interruption (WP-CLI).
	 *
	 * @param callable|null $tick Appelée après chaque passage avec la progression.
	 */
	public static function run( array $overrides = array(), ?callable $tick = null ): array {
		$progress = self::start( $overrides );
		while ( 'running' === $progress['status'] ) {
			$progress = self::step( 2.0 );
			if ( $tick ) {
				$tick( $progress );
			}
		}
		return $progress;
	}

	private static function index_batch( array $state, int $batch_size ): array {
		$sources = BCAMC_Sources::all();
		$keys    = $state['sources'];

		if ( ! isset( $keys[ $state['source_i'] ] ) ) {
			$state['phase']  = 'inventory';
			$state['cursor'] = 0;
			return $state;
		}

		$key = $keys[ $state['source_i'] ];
		if ( ! isset( $sources[ $key ] ) ) {
			// Source retirée entre deux passages : on passe à la suivante.
			++$state['source_i'];
			$state['cursor'] = 0;
			return $state;
		}

		BCAMC_Extractor::set_media_keys( (array) ( $state['media_keys'] ?? array() ) );

		// Les metas, options et lignes de tables sont plus légères qu'un contenu complet.
		$limit  = 'posts' === $key ? $batch_size : $batch_size * 2;
		$result = call_user_func( $sources[ $key ]['batch'], $state['cursor'], $limit );

		$refs = array();
		foreach ( (array) ( $result['entries'] ?? array() ) as $entry ) {
			$found = BCAMC_Extractor::extract( (string) ( $entry['text'] ?? '' ), (string) ( $entry['key'] ?? '' ), ! empty( $entry['strict'] ) );
			$src   = (string) ( $entry['source'] ?? $key );
			$sid   = (int) ( $entry['source_id'] ?? 0 );

			foreach ( $found['ids'] as $id ) {
				$refs[ BCAMC_DB::REF_ID . '|' . $id ] = array( 'kind' => BCAMC_DB::REF_ID, 'value' => (string) $id, 'source' => $src, 'source_id' => $sid );
			}
			foreach ( $found['paths'] as $path ) {
				$refs[ BCAMC_DB::REF_PATH . '|' . $path ]                             = array( 'kind' => BCAMC_DB::REF_PATH, 'value' => $path, 'source' => $src, 'source_id' => $sid );
				$radical                                                              = BCAMC_Extractor::radical( $path );
				$refs[ BCAMC_DB::REF_RADICAL . '|' . $radical ] ??= array( 'kind' => BCAMC_DB::REF_RADICAL, 'value' => $radical, 'source' => $src, 'source_id' => $sid );
			}
		}
		BCAMC_DB::insert_refs( array_values( $refs ) );

		$state['done']['index'] += (int) ( $result['processed'] ?? 0 );

		if ( null === ( $result['next'] ?? null ) ) {
			++$state['source_i'];
			$state['cursor'] = 0;
		} else {
			$state['cursor'] = $result['next'];
		}

		return $state;
	}

	private static function inventory_batch( array $state, int $batch_size ): array {
		$context = array(
			'registered_sizes'  => array_keys( wp_get_registered_image_subsizes() ),
			'cutoff'            => (int) $state['cutoff'],
			'include_documents' => (bool) $state['settings']['include_documents'],
		);

		$result = BCAMC_Inventory::batch( (int) $state['cursor'], $batch_size, $context );

		$state['done']['inventory']     += $result['processed'];
		$state['stats']['media_bytes'] += $result['bytes'];

		if ( null === $result['next'] ) {
			$state['phase']           = 'files';
			$state['cursor']          = 0;
			$state['units']           = BCAMC_Inventory::file_units();
			$state['totals']['files'] = max( 1, count( $state['units'] ) );
		} else {
			$state['cursor'] = $result['next'];
		}

		return $state;
	}

	private static function files_batch( array $state ): array {
		$index = (int) $state['cursor'];

		if ( ! isset( $state['units'][ $index ] ) ) {
			arsort( $state['stats']['folders'] );
			$state['phase'] = 'done';
			return $state;
		}

		$result = BCAMC_Inventory::process_unit( $state['units'][ $index ], (int) $state['cutoff'] );

		$folder = '' === $result['folder'] ? '/' : $result['folder'];
		$state['stats']['folders'][ $folder ] = ( $state['stats']['folders'][ $folder ] ?? 0 ) + $result['bytes'];
		$state['stats']['uploads_bytes']     += $result['bytes'];

		++$state['done']['files'];
		$state['cursor'] = $index + 1;

		return $state;
	}

	/**
	 * Progression lisible par l'interface et WP-CLI.
	 */
	public static function progress( ?array $state = null ): array {
		$state  = $state ?? self::state();
		$status = (string) ( $state['status'] ?? 'idle' );
		$phase  = (string) ( $state['phase'] ?? '' );

		$percent = 0.0;
		if ( 'done' === $status ) {
			$percent = 100.0;
		} elseif ( 'running' === $status ) {
			foreach ( self::WEIGHTS as $name => $weight ) {
				$total = max( 1, (int) ( $state['totals'][ $name ] ?? 1 ) );
				$done  = min( $total, (int) ( $state['done'][ $name ] ?? 0 ) );
				$ratio = $done / $total;
				if ( array_search( $name, array_keys( self::WEIGHTS ), true ) < array_search( $phase, array_keys( self::WEIGHTS ), true ) ) {
					$ratio = 1.0;
				}
				$percent += $weight * $ratio * 100;
			}
			$percent = min( 99.0, $percent );
		}

		$label = '';
		if ( 'running' === $status ) {
			if ( 'index' === $phase ) {
				$sources = BCAMC_Sources::all();
				$key     = $state['sources'][ $state['source_i'] ] ?? '';
				$label   = sprintf(
					/* translators: %s: name of the part of the site being read */
					__( 'Reading: %s', 'bca-media-cleaner' ),
					$sources[ $key ]['label'] ?? $key
				);
			} elseif ( 'inventory' === $phase ) {
				$label = sprintf(
					/* translators: 1: media checked, 2: total media */
					__( 'Checking media: %1$s of %2$s', 'bca-media-cleaner' ),
					number_format_i18n( (int) $state['done']['inventory'] ),
					number_format_i18n( (int) $state['totals']['inventory'] )
				);
			} elseif ( 'files' === $phase ) {
				$label = __( 'Measuring the uploads folder', 'bca-media-cleaner' );
			}
		}

		return array(
			'status'  => $status,
			'phase'   => $phase,
			'percent' => round( $percent, 1 ),
			'label'   => $label,
		);
	}
}
