<?php
/**
 * Rapport : totaux par catégorie, éléments les plus lourds, listes paginées.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_Report {

	const CATEGORIES = array( 'A', 'B', 'C' );

	public static function category_label( string $category ): string {
		switch ( $category ) {
			case 'A':
				return __( 'Unused image sizes', 'bca-media-cleaner' );
			case 'B':
				return __( 'Unused media', 'bca-media-cleaner' );
			case 'C':
				return __( 'Orphan files', 'bca-media-cleaner' );
		}
		return $category;
	}

	public static function category_help( string $category ): string {
		switch ( $category ) {
			case 'A':
				return __( 'Image sizes your theme and plugins no longer generate, and that no page cites. The original image and the sizes still in use are kept.', 'bca-media-cleaner' );
			case 'B':
				return __( 'Media found nowhere on the site: no page, setting, style sheet or plugin refers to them.', 'bca-media-cleaner' );
			case 'C':
				return __( 'Files in the dated upload folders that belong to no media and are cited nowhere, often left over from deleted media or migrations.', 'bca-media-cleaner' );
		}
		return '';
	}

	/**
	 * @return array{scan: array, uploads_bytes: int, media_bytes: int, attachments: int, recoverable: int, categories: array, folders: array}
	 */
	public static function summary(): array {
		global $wpdb;

		$state = BCAMC_Scanner::state();
		$table = BCAMC_DB::items_table();
		$rows  = $wpdb->get_results( "SELECT category, COUNT(*) AS n, SUM(bytes) AS bytes FROM {$table} GROUP BY category", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$categories = array();
		foreach ( self::CATEGORIES as $category ) {
			$categories[ $category ] = array(
				'label' => self::category_label( $category ),
				'count' => 0,
				'bytes' => 0,
			);
		}
		foreach ( (array) $rows as $row ) {
			if ( isset( $categories[ $row['category'] ] ) ) {
				$categories[ $row['category'] ]['count'] = (int) $row['n'];
				$categories[ $row['category'] ]['bytes'] = (int) $row['bytes'];
			}
		}

		return array(
			'scan'          => array(
				'status'      => (string) ( $state['status'] ?? 'idle' ),
				'started_at'  => (int) ( $state['started_at'] ?? 0 ),
				'finished_at' => (int) ( $state['finished_at'] ?? 0 ),
				'settings'    => (array) ( $state['settings'] ?? array() ),
			),
			'uploads_bytes' => (int) ( $state['stats']['uploads_bytes'] ?? 0 ),
			'media_bytes'   => (int) ( $state['stats']['media_bytes'] ?? 0 ),
			'attachments'   => (int) ( $state['stats']['attachments'] ?? 0 ),
			'recoverable'   => array_sum( array_column( $categories, 'bytes' ) ),
			'categories'    => $categories,
			'folders'       => (array) ( $state['stats']['folders'] ?? array() ),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function items( string $category = '', int $limit = 50, int $offset = 0, string $order = 'bytes' ): array {
		global $wpdb;

		$table   = BCAMC_DB::items_table();
		$orderby = 'added' === $order ? 'added_at ASC, id ASC' : 'bytes DESC, id ASC';
		$where   = in_array( $category, self::CATEGORIES, true ) ? $wpdb->prepare( 'WHERE category = %s', $category ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY {$orderby} LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
	}

	public static function count( string $category ): int {
		global $wpdb;
		$table = BCAMC_DB::items_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE category = %s", $category ) );
	}

	public static function file_url( string $rel_path ): string {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $rel_path ) );
	}

	/**
	 * Données d'affichage d'un élément : nom, vignette, lien.
	 */
	public static function present( array $item ): array {
		$id   = (int) $item['attachment_id'];
		$rel  = (string) $item['rel_path'];
		$name = basename( $rel );
		$thumb = '';
		$link  = '';

		if ( 'B' === $item['category'] && $id ) {
			$title = get_the_title( $id );
			$name  = '' !== $title ? $title : $name;
			$thumb = (string) wp_get_attachment_image_url( $id, 'thumbnail' );
			$link  = (string) get_edit_post_link( $id, 'raw' );
		} elseif ( 'A' === $item['category'] && $id ) {
			$thumb = self::file_url( $rel );
			$link  = (string) get_edit_post_link( $id, 'raw' );
		} elseif ( preg_match( '/\.(jpe?g|png|gif|webp|avif|svg)$/i', $rel ) ) {
			$thumb = self::file_url( $rel );
		}

		return array(
			'name'  => $name,
			'path'  => $rel,
			'thumb' => $thumb,
			'link'  => $link,
			'size'  => (string) $item['size_name'],
			'files' => (array) json_decode( (string) $item['files'], true ),
		);
	}
}
