<?php
/**
 * Commandes WP-CLI.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

/**
 * Find the media and files your site no longer uses (read-only analysis).
 */
final class BCAMC_CLI {

	/**
	 * Runs a full analysis. Read-only: no file is moved or deleted.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<number>]
	 * : Items read at a time. Defaults to the plugin setting (200).
	 *
	 * [--min-age=<days>]
	 * : Ignore media added in the last N days. Defaults to the plugin setting (30).
	 *
	 * [--include-documents]
	 * : Also analyze documents (PDF, audio, office files).
	 *
	 * ## EXAMPLES
	 *
	 *     wp bca-media-cleaner scan
	 *     wp bca-media-cleaner scan --batch-size=500 --min-age=60
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function scan( $args, $assoc_args ) {
		$overrides = array();
		if ( isset( $assoc_args['batch-size'] ) ) {
			$overrides['batch_size'] = (int) $assoc_args['batch-size'];
		}
		if ( isset( $assoc_args['min-age'] ) ) {
			$overrides['min_age_days'] = (int) $assoc_args['min-age'];
		}
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'include-documents', false ) ) {
			$overrides['include_documents'] = true;
		}

		$start = microtime( true );
		$last  = '';
		BCAMC_Scanner::run(
			$overrides,
			static function ( array $progress ) use ( &$last ) {
				$line = sprintf( '%5.1f %%  %s', $progress['percent'], $progress['label'] );
				if ( $line !== $last ) {
					WP_CLI::log( $line );
					$last = $line;
				}
			}
		);

		WP_CLI::success(
			sprintf(
				/* translators: 1: duration in seconds, 2: peak memory */
				__( 'Analysis complete in %1$s s (peak memory: %2$s).', 'bca-media-cleaner' ),
				number_format_i18n( microtime( true ) - $start, 1 ),
				size_format( memory_get_peak_usage( true ) )
			)
		);
		$this->report( array(), array() );
	}

	/**
	 * Shows the result of the last analysis.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function report( $args, $assoc_args ) {
		$summary = BCAMC_Report::summary();
		if ( 'done' !== $summary['scan']['status'] ) {
			WP_CLI::error( __( 'No complete analysis yet. Run: wp bca-media-cleaner scan', 'bca-media-cleaner' ) );
		}

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $summary, JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = array();
		foreach ( $summary['categories'] as $category => $data ) {
			$rows[] = array(
				'category' => $category,
				'label'    => $data['label'],
				'items'    => $data['count'],
				'size'     => size_format( $data['bytes'], 1 ) ?: '0 B',
				'bytes'    => $data['bytes'],
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'category', 'label', 'items', 'size', 'bytes' ) );

		WP_CLI::log(
			sprintf(
				/* translators: 1: recoverable size, 2: uploads size, 3: number of media */
				__( 'Recoverable: %1$s out of %2$s in the uploads folder (%3$s media in the library).', 'bca-media-cleaner' ),
				size_format( $summary['recoverable'], 1 ) ?: '0 B',
				size_format( $summary['uploads_bytes'], 1 ) ?: '0 B',
				number_format_i18n( $summary['attachments'] )
			)
		);

		$folders = array();
		foreach ( array_slice( $summary['folders'], 0, 10, true ) as $folder => $bytes ) {
			$folders[] = array(
				'folder' => $folder,
				'size'   => size_format( (int) $bytes, 1 ) ?: '0 B',
			);
		}
		if ( $folders ) {
			WP_CLI::log( '' );
			WP_CLI::log( __( 'Largest folders in uploads:', 'bca-media-cleaner' ) );
			WP_CLI\Utils\format_items( 'table', $folders, array( 'folder', 'size' ) );
		}
	}

	/**
	 * Lists the items found by the last analysis.
	 *
	 * ## OPTIONS
	 *
	 * [--category=<category>]
	 * : A (unused image sizes), B (unused media) or C (orphan files). All by default.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum number of items. 0 for all.
	 * ---
	 * default: 50
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp bca-media-cleaner list --category=B
	 *     wp bca-media-cleaner list --format=csv --limit=0 > unused.csv
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ) {
		$category = strtoupper( (string) ( $assoc_args['category'] ?? '' ) );
		if ( '' !== $category && ! in_array( $category, BCAMC_Report::CATEGORIES, true ) ) {
			WP_CLI::error( __( 'Unknown category. Use A, B or C.', 'bca-media-cleaner' ) );
		}

		$limit = (int) ( $assoc_args['limit'] ?? 50 );
		$limit = $limit <= 0 ? PHP_INT_MAX : $limit;

		$rows = array();
		foreach ( BCAMC_Report::items( $category, min( $limit, 1000000 ) ) as $item ) {
			$rows[] = array(
				'category'      => $item['category'],
				'attachment_id' => (int) $item['attachment_id'] ?: '',
				'size_name'     => $item['size_name'],
				'path'          => $item['rel_path'],
				'bytes'         => (int) $item['bytes'],
				'size'          => size_format( (int) $item['bytes'], 1 ) ?: '0 B',
				'files'         => count( (array) json_decode( (string) $item['files'], true ) ),
				'added'         => $item['added_at'],
			);
		}

		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'category', 'attachment_id', 'size_name', 'path', 'size', 'bytes', 'files', 'added' ) );
	}
}
