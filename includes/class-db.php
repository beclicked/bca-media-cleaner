<?php
/**
 * Tables dédiées : index des références et éléments détectés.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_DB {

	const DB_VERSION = '1';
	const OPTION     = 'bcamc_db_version';

	/** Types de références stockées dans l'index. */
	const REF_ID      = 1; // ID de pièce jointe.
	const REF_PATH    = 2; // Chemin exact relatif au dossier d'uploads, en minuscules.
	const REF_RADICAL = 3; // Radical du chemin (sans extension ni suffixe de taille).
	const REF_OWNED   = 4; // Fichier appartenant à une pièce jointe (inventaire, pas une référence).

	public static function refs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bcamc_refs';
	}

	public static function items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bcamc_items';
	}

	public static function install(): void {
		if ( is_multisite() ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$refs    = self::refs_table();
		$items   = self::items_table();

		dbDelta(
			"CREATE TABLE {$refs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind tinyint(3) unsigned NOT NULL,
				hash char(32) NOT NULL,
				value varchar(255) NOT NULL DEFAULT '',
				source varchar(40) NOT NULL DEFAULT '',
				source_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY kind_hash (kind,hash)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				category char(1) NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				size_name varchar(100) NOT NULL DEFAULT '',
				rel_path varchar(255) NOT NULL DEFAULT '',
				bytes bigint(20) unsigned NOT NULL DEFAULT 0,
				files longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'candidate',
				added_at datetime NULL,
				detected_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY category (category),
				KEY attachment_id (attachment_id)
			) {$charset};"
		);

		update_option( self::OPTION, self::DB_VERSION, false );
	}

	/**
	 * Une mise à jour ne déclenche pas l'activation : on vérifie la version du schéma.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function reset_scan_data(): void {
		global $wpdb;
		// DELETE plutôt que TRUNCATE : compatible avec tous les moteurs et sans droit DROP.
		$wpdb->query( 'DELETE FROM ' . self::refs_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . self::items_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Insère des références en ignorant les doublons (la première source trouvée est conservée).
	 *
	 * @param array<string, array{kind:int,value:string,source:string,source_id:int}> $refs Indexées par kind|hash.
	 */
	public static function insert_refs( array $refs ): void {
		global $wpdb;

		if ( empty( $refs ) ) {
			return;
		}

		$table = self::refs_table();
		foreach ( array_chunk( $refs, 400 ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $ref ) {
				$placeholders[] = '(%d,%s,%s,%s,%d)';
				$values[]       = $ref['kind'];
				$values[]       = md5( $ref['value'] );
				$values[]       = mb_substr( $ref['value'], 0, 255 );
				$values[]       = mb_substr( $ref['source'], 0, 40 );
				$values[]       = $ref['source_id'];
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (kind, hash, value, source, source_id) VALUES " . implode( ',', $placeholders ), $values ) );
		}
	}

	/**
	 * Parmi les valeurs données, renvoie celles présentes dans l'index pour ce type.
	 *
	 * @param string[] $values Valeurs (ID sous forme de chaîne, chemins ou radicaux).
	 * @return array<string, true> Valeurs trouvées.
	 */
	public static function existing( int $kind, array $values ): array {
		global $wpdb;

		$values = array_values( array_unique( array_filter( array_map( 'strval', $values ), 'strlen' ) ) );
		if ( empty( $values ) ) {
			return array();
		}

		$found = array();
		$table = self::refs_table();
		foreach ( array_chunk( $values, 400 ) as $chunk ) {
			$hashes = array_map( 'md5', $chunk );
			$in     = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT hash FROM {$table} WHERE kind = %d AND hash IN ({$in})", array_merge( array( $kind ), $hashes ) ) );
			$rows = array_flip( $rows );
			foreach ( $chunk as $value ) {
				if ( isset( $rows[ md5( $value ) ] ) ) {
					$found[ $value ] = true;
				}
			}
		}

		return $found;
	}

	/**
	 * Source de la première référence trouvée pour une valeur (affichage « utilisé dans »).
	 */
	public static function source_of( int $kind, string $value ): ?array {
		global $wpdb;
		$table = self::refs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT source, source_id FROM {$table} WHERE kind = %d AND hash = %s", $kind, md5( $value ) ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	public static function insert_items( array $items ): void {
		global $wpdb;

		if ( empty( $items ) ) {
			return;
		}

		$table = self::items_table();
		$now   = current_time( 'mysql', true );
		foreach ( array_chunk( $items, 200 ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $item ) {
				$placeholders[] = '(%s,%d,%s,%s,%d,%s,%s,%s,%s)';
				$values[]       = $item['category'];
				$values[]       = (int) ( $item['attachment_id'] ?? 0 );
				$values[]       = mb_substr( (string) ( $item['size_name'] ?? '' ), 0, 100 );
				$values[]       = mb_substr( (string) $item['rel_path'], 0, 255 );
				$values[]       = (int) $item['bytes'];
				$values[]       = wp_json_encode( array_values( $item['files'] ?? array() ) );
				$values[]       = 'candidate';
				$values[]       = (string) ( $item['added_at'] ?? '' );
				$values[]       = $now;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (category, attachment_id, size_name, rel_path, bytes, files, status, added_at, detected_at) VALUES " . implode( ',', $placeholders ), $values ) );
		}
	}

	public static function drop(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::refs_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::items_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		delete_option( self::OPTION );
	}
}
