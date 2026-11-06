<?php
/**
 * Inventaire : fichiers réels de chaque média, et classement en catégories.
 *
 *   A  tailles d'images qui ne sont plus enregistrées et dont le fichier n'est cité nulle part
 *   B  médias sans aucune référence (ni ID, ni chemin)
 *   C  fichiers des dossiers datés qui n'appartiennent à aucun média et ne sont cités nulle part
 *
 * Lecture seule : rien n'est déplacé ni supprimé.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_Inventory {

	/** @var array<string, array<string, array{name: string, bytes: int, mtime: int}>> Contenu des dossiers, par lot. */
	private static array $dir_cache = array();

	private static function basedir(): string {
		return wp_normalize_path( wp_get_upload_dir()['basedir'] );
	}

	/**
	 * Fichiers d'un dossier relatif, indexés par nom en minuscules.
	 *
	 * @return array<string, array{name: string, bytes: int, mtime: int}>
	 */
	public static function list_dir( string $rel_dir ): array {
		$rel_dir = trim( $rel_dir, '/' );
		if ( isset( self::$dir_cache[ $rel_dir ] ) ) {
			return self::$dir_cache[ $rel_dir ];
		}

		$abs   = self::basedir() . ( '' === $rel_dir ? '' : '/' . $rel_dir );
		$files = array();
		if ( is_dir( $abs ) ) {
			foreach ( (array) scandir( $abs ) as $name ) {
				$name = (string) $name;
				if ( '' === $name || '.' === $name[0] ) {
					continue;
				}
				$path = $abs . '/' . $name;
				if ( is_file( $path ) ) {
					$files[ strtolower( $name ) ] = array(
						'name'  => $name,
						'bytes' => (int) filesize( $path ),
						'mtime' => (int) filemtime( $path ),
					);
				}
			}
		}

		// Cache limité : un lot touche en général quelques dossiers seulement.
		if ( count( self::$dir_cache ) > 24 ) {
			self::$dir_cache = array();
		}
		self::$dir_cache[ $rel_dir ] = $files;
		return $files;
	}

	public static function flush_cache(): void {
		self::$dir_cache = array();
	}

	/**
	 * Fichier existant (insensible à la casse) : chemin relatif en minuscules et poids.
	 */
	private static function stat( string $rel_path ): ?array {
		$rel_path = BCAMC_Extractor::normalize_path( $rel_path );
		$dir      = dirname( $rel_path );
		$dir      = '.' === $dir ? '' : $dir;
		$listing  = self::list_dir( $dir );
		$key      = strtolower( basename( $rel_path ) );

		if ( ! isset( $listing[ $key ] ) ) {
			return null;
		}
		return array(
			'rel'   => $rel_path,
			'bytes' => $listing[ $key ]['bytes'],
		);
	}

	/**
	 * Variantes WebP et AVIF générées à côté d'un fichier (FlyingPress, optimiseurs).
	 *
	 * @return array<int, array{rel: string, bytes: int}>
	 */
	private static function variants( string $rel_path ): array {
		$without_ext = preg_replace( '/\.[a-z0-9]+$/i', '', $rel_path );
		$candidates  = array( $rel_path . '.webp', $rel_path . '.avif', $without_ext . '.webp', $without_ext . '.avif' );
		$out         = array();
		foreach ( array_unique( $candidates ) as $candidate ) {
			if ( strtolower( $candidate ) === strtolower( $rel_path ) ) {
				continue;
			}
			$stat = self::stat( $candidate );
			if ( $stat ) {
				$out[] = $stat;
			}
		}
		return $out;
	}

	/**
	 * Tous les fichiers existants d'un média.
	 *
	 * @return array{main: string, files: array<string, array{role: string, bytes: int}>, sizes: array<string, string>}
	 *         files indexés par chemin relatif en minuscules ; sizes : nom de taille => chemin relatif.
	 */
	public static function files_of( string $attached_file, $metadata, $backup_sizes ): array {
		$main  = BCAMC_Extractor::normalize_path( $attached_file );
		$dir   = dirname( $main );
		$dir   = '.' === $dir ? '' : $dir . '/';
		$files = array();
		$sizes = array();

		$add = static function ( string $rel, string $role ) use ( &$files ) {
			$stat = self::stat( $rel );
			if ( ! $stat || isset( $files[ $stat['rel'] ] ) ) {
				return;
			}
			$files[ $stat['rel'] ] = array( 'role' => $role, 'bytes' => $stat['bytes'] );
			foreach ( self::variants( $stat['rel'] ) as $variant ) {
				if ( ! isset( $files[ $variant['rel'] ] ) ) {
					$files[ $variant['rel'] ] = array( 'role' => $role . ':variant', 'bytes' => $variant['bytes'] );
				}
			}
		};

		if ( '' !== $main ) {
			$add( $main, 'main' );
		}

		$metadata = is_array( $metadata ) ? $metadata : array();
		if ( ! empty( $metadata['original_image'] ) ) {
			$add( $dir . $metadata['original_image'], 'original' );
		}
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$rel             = BCAMC_Extractor::normalize_path( $dir . $size['file'] );
				$sizes[ (string) $name ] = $rel;
				$add( $rel, 'size:' . $name );
			}
		}
		foreach ( (array) ( is_array( $backup_sizes ) ? $backup_sizes : array() ) as $name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$add( $dir . $size['file'], 'backup:' . $name );
			}
		}

		return array(
			'main'  => $main,
			'files' => $files,
			'sizes' => $sizes,
		);
	}

	public static function count_attachments(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
	}

	public static function is_document( string $mime ): bool {
		return ! preg_match( '#^(image|video)/#', $mime );
	}

	/**
	 * Traite un lot de médias : fichiers possédés (pour la catégorie C), catégories A et B.
	 *
	 * @param array $context registered_sizes (string[]), cutoff (timestamp), include_documents (bool).
	 * @return array{next: int|null, processed: int, bytes: int}
	 */
	public static function batch( int $after_id, int $limit, array $context ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_mime_type, post_date_gmt, post_date FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$after_id,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array( 'next' => null, 'processed' => 0, 'bytes' => 0 );
		}

		$ids  = array_map( static fn( $r ) => (int) $r['ID'], $rows );
		$in   = implode( ',', $ids );
		$meta = array();
		// IDs entiers issus de la base : pas d'injection possible.
		$meta_rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes')", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $meta_rows as $m ) {
			$meta[ (int) $m['post_id'] ][ $m['meta_key'] ] = $m['meta_value'];
		}

		// 1. Fichiers de chaque média.
		$attachments = array();
		$owned       = array();
		$radicals    = array();
		foreach ( $rows as $row ) {
			$id   = (int) $row['ID'];
			$data = $meta[ $id ] ?? array();
			$info = self::files_of(
				(string) ( $data['_wp_attached_file'] ?? '' ),
				maybe_unserialize( $data['_wp_attachment_metadata'] ?? '' ),
				maybe_unserialize( $data['_wp_attachment_backup_sizes'] ?? '' )
			);

			foreach ( array_keys( $info['files'] ) as $rel ) {
				$owned[ BCAMC_DB::REF_OWNED . '|' . $rel ] = array(
					'kind'      => BCAMC_DB::REF_OWNED,
					'value'     => $rel,
					'source'    => 'attachment',
					'source_id' => $id,
				);
			}

			$radical = '' !== $info['main'] ? BCAMC_Extractor::radical( $info['main'] ) : '';
			if ( '' !== $radical ) {
				$radicals[] = $radical;
			}

			$attachments[ $id ] = array(
				'row'     => $row,
				'info'    => $info,
				'radical' => $radical,
			);
		}
		BCAMC_DB::insert_refs( array_values( $owned ) );

		// 2. Références connues pour ce lot.
		$used_ids      = BCAMC_DB::existing( BCAMC_DB::REF_ID, array_map( 'strval', $ids ) );
		$used_radicals = BCAMC_DB::existing( BCAMC_DB::REF_RADICAL, $radicals );

		$size_paths = array();
		foreach ( $attachments as $a ) {
			foreach ( $a['info']['sizes'] as $name => $rel ) {
				if ( ! in_array( $name, $context['registered_sizes'], true ) ) {
					$size_paths[] = $rel;
					foreach ( self::variants( $rel ) as $variant ) {
						$size_paths[] = $variant['rel'];
					}
				}
			}
		}
		$used_paths = BCAMC_DB::existing( BCAMC_DB::REF_PATH, $size_paths );

		// 3. Classement.
		$items = array();
		$bytes = 0;
		foreach ( $attachments as $id => $a ) {
			$row   = $a['row'];
			$info  = $a['info'];
			$total = array_sum( array_column( $info['files'], 'bytes' ) );
			$bytes += $total;

			$date_gmt = ( '0000-00-00 00:00:00' !== $row['post_date_gmt'] && '' !== $row['post_date_gmt'] ) ? $row['post_date_gmt'] : get_gmt_from_date( $row['post_date'] );
			$added_ts = strtotime( $date_gmt . ' UTC' );
			$is_used  = isset( $used_ids[ (string) $id ] ) || ( '' !== $a['radical'] && isset( $used_radicals[ $a['radical'] ] ) );
			$eligible = $added_ts && $added_ts < $context['cutoff']
				&& ( $context['include_documents'] || ! self::is_document( (string) $row['post_mime_type'] ) );

			// B : média sans aucune référence.
			if ( ! $is_used && $eligible && $total > 0 ) {
				$items[] = array(
					'category'      => 'B',
					'attachment_id' => $id,
					'rel_path'      => $info['main'],
					'bytes'         => $total,
					'files'         => array_keys( $info['files'] ),
					'added_at'      => $date_gmt,
				);
				continue;
			}

			// A : tailles qui ne sont plus enregistrées et que personne ne cite.
			foreach ( $info['sizes'] as $name => $rel ) {
				if ( in_array( $name, $context['registered_sizes'], true ) || ! isset( $info['files'][ $rel ] ) ) {
					continue;
				}
				// Ne jamais toucher un fichier qui sert aussi d'original ou à une autre taille encore enregistrée.
				$shared = false;
				foreach ( $info['sizes'] as $other_name => $other_rel ) {
					if ( $other_rel === $rel && $other_name !== $name && in_array( $other_name, $context['registered_sizes'], true ) ) {
						$shared = true;
					}
				}
				if ( $shared || $rel === $info['main'] ) {
					continue;
				}

				$group = array( $rel => $info['files'][ $rel ]['bytes'] );
				foreach ( self::variants( $rel ) as $variant ) {
					$group[ $variant['rel'] ] = $variant['bytes'];
				}

				$cited = false;
				foreach ( array_keys( $group ) as $path ) {
					if ( isset( $used_paths[ $path ] ) ) {
						$cited = true;
						break;
					}
				}
				if ( $cited ) {
					continue;
				}

				$items[] = array(
					'category'      => 'A',
					'attachment_id' => $id,
					'size_name'     => (string) $name,
					'rel_path'      => $rel,
					'bytes'         => array_sum( $group ),
					'files'         => array_keys( $group ),
					'added_at'      => $date_gmt,
				);
			}
		}

		BCAMC_DB::insert_items( $items );
		self::flush_cache();

		return array(
			'next'      => count( $rows ) < $limit ? null : (int) end( $ids ),
			'processed' => count( $rows ),
			'bytes'     => $bytes,
		);
	}

	/* -------------------------------------------------------------------------
	 * Fichiers du dossier d'uploads
	 * ---------------------------------------------------------------------- */

	/**
	 * Unités de travail : dossiers datés (AAAA/MM), autres dossiers de premier niveau, fichiers racine.
	 *
	 * @return array<int, array{type: string, path: string}>
	 */
	public static function file_units(): array {
		$base  = self::basedir();
		$units = array();
		if ( ! is_dir( $base ) ) {
			return $units;
		}

		$root_files = false;
		foreach ( (array) scandir( $base ) as $entry ) {
			$entry = (string) $entry;
			if ( '' === $entry || '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $base . '/' . $entry;
			if ( is_file( $path ) ) {
				$root_files = true;
				continue;
			}
			if ( ! is_dir( $path ) ) {
				continue;
			}
			if ( preg_match( '/^(19|20)\d{2}$/', $entry ) ) {
				foreach ( (array) scandir( $path ) as $month ) {
					$month = (string) $month;
					if ( preg_match( '/^\d{2}$/', $month ) && is_dir( $path . '/' . $month ) ) {
						$units[] = array( 'type' => 'dated', 'path' => $entry . '/' . $month );
					}
				}
				// Contenu inattendu dans un dossier d'année : compté, jamais classé.
				$units[] = array( 'type' => 'year-extra', 'path' => $entry );
			} else {
				$units[] = array( 'type' => 'folder', 'path' => $entry );
			}
		}
		if ( $root_files ) {
			$units[] = array( 'type' => 'root', 'path' => '' );
		}

		return $units;
	}

	/**
	 * Traite une unité : poids total et, pour les dossiers datés, fichiers orphelins (catégorie C).
	 *
	 * @return array{bytes: int, folder: string}
	 */
	public static function process_unit( array $unit, int $cutoff ): array {
		$base = self::basedir();

		if ( 'dated' === $unit['type'] ) {
			$listing = self::list_dir( $unit['path'] );
			$bytes   = 0;
			$paths   = array();
			foreach ( $listing as $lower => $file ) {
				$bytes += $file['bytes'];
				if ( in_array( $lower, array( 'index.php', 'index.html', '.htaccess' ), true ) ) {
					continue;
				}
				$paths[ $unit['path'] . '/' . $lower ] = $file;
			}

			$owned = BCAMC_DB::existing( BCAMC_DB::REF_OWNED, array_keys( $paths ) );
			$cited = BCAMC_DB::existing( BCAMC_DB::REF_PATH, array_keys( $paths ) );

			$items = array();
			foreach ( $paths as $rel => $file ) {
				if ( isset( $owned[ $rel ] ) || isset( $cited[ $rel ] ) || $file['mtime'] >= $cutoff ) {
					continue;
				}
				$items[] = array(
					'category' => 'C',
					'rel_path' => $rel,
					'bytes'    => $file['bytes'],
					'files'    => array( $rel ),
					'added_at' => gmdate( 'Y-m-d H:i:s', $file['mtime'] ),
				);
			}
			BCAMC_DB::insert_items( $items );
			self::flush_cache();

			// Sous-dossiers éventuels d'un mois : comptés dans le poids, jamais classés.
			$bytes += self::dir_size( $base . '/' . $unit['path'], true );

			return array( 'bytes' => $bytes, 'folder' => substr( $unit['path'], 0, 4 ) );
		}

		if ( 'year-extra' === $unit['type'] ) {
			$bytes = 0;
			foreach ( (array) scandir( $base . '/' . $unit['path'] ) as $entry ) {
				$entry = (string) $entry;
				if ( '.' === $entry || '..' === $entry || preg_match( '/^\d{2}$/', $entry ) ) {
					continue;
				}
				$path   = $base . '/' . $unit['path'] . '/' . $entry;
				$bytes += is_dir( $path ) ? self::dir_size( $path ) : (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			return array( 'bytes' => $bytes, 'folder' => $unit['path'] );
		}

		if ( 'root' === $unit['type'] ) {
			$bytes = 0;
			foreach ( (array) scandir( $base ) as $entry ) {
				$path = $base . '/' . $entry;
				if ( is_file( $path ) ) {
					$bytes += (int) filesize( $path );
				}
			}
			return array( 'bytes' => $bytes, 'folder' => '/' );
		}

		return array( 'bytes' => self::dir_size( $base . '/' . $unit['path'] ), 'folder' => $unit['path'] );
	}

	/**
	 * Poids d'un dossier. Avec $subdirs_only, seuls ses sous-dossiers sont comptés.
	 */
	public static function dir_size( string $dir, bool $subdirs_only = false ): int {
		$bytes = 0;
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		try {
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				if ( $subdirs_only && wp_normalize_path( $file->getPath() ) === wp_normalize_path( $dir ) ) {
					continue;
				}
				$bytes += (int) $file->getSize();
			}
		} catch ( Exception $e ) {
			return $bytes;
		}
		return $bytes;
	}
}
