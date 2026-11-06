<?php
/**
 * Export CSV des résultats, pour passer une liste en revue dans un tableur.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_Export {

	const ACTION = 'bcamc_export';
	const CHUNK  = 500;

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
	}

	/**
	 * Lien de téléchargement, protégé par un nonce.
	 *
	 * @param string $category A, B, C ou vide pour tout exporter.
	 */
	public static function url( string $category = '' ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'category' => $category,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	/**
	 * Séparateur adapté au tableur de l'utilisateur : point-virgule quand la virgule
	 * sert de séparateur décimal (français, allemand...), virgule sinon.
	 */
	public static function separator(): string {
		global $wp_locale;
		$decimal = ( $wp_locale instanceof WP_Locale ) ? (string) $wp_locale->number_format['decimal_point'] : '.';
		return (string) apply_filters( 'bcamc_csv_separator', ',' === $decimal ? ';' : ',' );
	}

	public static function download(): void {
		check_admin_referer( self::ACTION );
		if ( ! BCAMC_Settings::user_can() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bca-media-cleaner' ), '', array( 'response' => 403 ) );
		}

		$category = strtoupper( sanitize_key( wp_unslash( $_GET['category'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! in_array( $category, BCAMC_Report::CATEGORIES, true ) ) {
			$category = '';
		}

		$name = 'media-cleanup-' . ( '' !== $category ? strtolower( $category ) . '-' : '' ) . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		self::write( $out, $category, self::separator() );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Écrit le CSV dans un flux, par paquets (aucune liste complète en mémoire).
	 *
	 * @param resource $out       Flux ouvert en écriture.
	 * @param string   $category  A, B, C ou vide.
	 * @param string   $separator Séparateur de colonnes.
	 * @return int Nombre de lignes écrites (hors en-tête).
	 */
	public static function write( $out, string $category, string $separator = ',' ): int {
		// Marque UTF-8 : sans elle, Excel affiche mal les accents.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		self::put(
			$out,
			array(
				__( 'Category', 'bca-media-cleaner' ),
				__( 'Media ID', 'bca-media-cleaner' ),
				__( 'Title', 'bca-media-cleaner' ),
				__( 'Image size', 'bca-media-cleaner' ),
				__( 'Path', 'bca-media-cleaner' ),
				__( 'Size on disk (bytes)', 'bca-media-cleaner' ),
				__( 'Size on disk', 'bca-media-cleaner' ),
				__( 'Files', 'bca-media-cleaner' ),
				__( 'Added', 'bca-media-cleaner' ),
				__( 'File address', 'bca-media-cleaner' ),
				__( 'Edit link', 'bca-media-cleaner' ),
			),
			$separator
		);

		$written = 0;
		$offset  = 0;
		do {
			$items = BCAMC_Report::items( $category, self::CHUNK, $offset );
			foreach ( $items as $item ) {
				$view = BCAMC_Report::present( $item );
				self::put(
					$out,
					array(
						BCAMC_Report::category_label( (string) $item['category'] ),
						(int) $item['attachment_id'] ?: '',
						'B' === $item['category'] ? $view['name'] : '',
						$view['size'],
						$view['path'],
						(int) $item['bytes'],
						size_format( (int) $item['bytes'], 1 ) ?: '0 B',
						count( $view['files'] ),
						(string) $item['added_at'],
						BCAMC_Report::file_url( $view['path'] ),
						$view['link'],
					),
					$separator
				);
				++$written;
			}
			$offset += self::CHUNK;
		} while ( count( $items ) === self::CHUNK );

		return $written;
	}

	private static function put( $out, array $fields, string $separator ): void {
		// Neutralise les formules : une cellule qui commence par = + - @ serait exécutée par le tableur.
		$fields = array_map(
			static function ( $field ) {
				$field = (string) $field;
				return ( '' !== $field && in_array( $field[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $field : $field;
			},
			$fields
		);
		fputcsv( $out, $fields, $separator, '"', '' );
	}
}
