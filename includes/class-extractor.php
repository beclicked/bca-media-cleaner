<?php
/**
 * Extraction des références à des médias dans un texte quelconque :
 * contenu HTML, blocs Gutenberg, JSON Elementor, valeurs sérialisées, CSS.
 *
 * Principe de prudence : en cas de doute, on considère qu'il s'agit d'une référence.
 * Une fausse référence garde un fichier inutile ; une référence manquée pourrait
 * faire supprimer un fichier utilisé.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_Extractor {

	/** Extensions de fichiers reconnues dans les chemins. */
	const EXT = 'jpe?g|png|gif|webp|avif|svg|bmp|ico|tiff?|heic|heif|mp4|m4v|mov|webm|ogv|avi|wmv|mp3|m4a|wav|ogg|oga|flac|pdf|docx?|xlsx?|pptx?|odt|ods|odp|rtf|txt|csv|zip|json|vtt|srt|woff2?|ttf|otf|eot';

	/** Fragments de clés de meta et d'options qui contiennent probablement des IDs de médias. */
	const KEY_HINTS = array( 'image', 'img', 'logo', 'thumbnail', 'thumb', 'gallery', 'icon', 'background', 'bg_', '_bg', 'media', 'photo', 'picture', 'avatar', 'cover', 'banner', 'poster', 'attachment', 'file', 'video', 'audio', 'pdf', 'document', 'download', 'seopress_social', 'og_', 'twitter' );

	/** Clés contenant toujours un ID de média ou d'objet. */
	const ID_KEYS = array( '_thumbnail_id', '_product_image_gallery', '_menu_item_object_id', 'site_icon', 'custom_logo', 'thumbnail_id' );

	/** @var string|null Chemin URL du dossier d'uploads, par exemple « /wp-content/uploads/ ». */
	private static ?string $uploads_path = null;

	private static function uploads_path(): string {
		if ( null === self::$uploads_path ) {
			$uploads = wp_get_upload_dir();
			$path    = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
			$path    = '/' . trim( $path, '/' ) . '/';
			self::$uploads_path = strtolower( $path );
		}
		return self::$uploads_path;
	}

	/**
	 * Références trouvées dans un texte.
	 *
	 * @param string $text Texte brut (HTML, JSON, sérialisé, CSS...).
	 * @param string $key  Nom de la meta ou de l'option (vide pour un contenu).
	 * @return array{ids: int[], paths: string[]}
	 */
	public static function extract( string $text, string $key = '' ): array {
		$ids   = array();
		$paths = array();

		if ( '' === $text ) {
			return array( 'ids' => array(), 'paths' => array() );
		}

		// JSON Elementor et blocs : barres obliques échappées.
		$plain = str_replace( '\\/', '/', $text );

		// --- Chemins de fichiers ------------------------------------------------
		if ( false !== stripos( $plain, '.' ) ) {
			$lower = strtolower( $plain );

			// 1. Tout ce qui suit le chemin d'uploads (quel que soit le domaine, CDN compris).
			$base = preg_quote( self::uploads_path(), '#' );
			if ( preg_match_all( '#' . $base . '([^\s"\'()<>\\\\,|;]+?\.(?:' . self::EXT . '))(?=$|[\s"\'()<>\\\\,|;?\#&])#i', $lower, $m ) ) {
				foreach ( $m[1] as $p ) {
					$paths[] = $p;
				}
			}

			// 2. Chemins datés AAAA/MM/fichier.ext sans préfixe (CDN qui réécrit les adresses).
			if ( preg_match_all( '#(?<![0-9a-z])((?:19|20)\d{2}/\d{2}/[^\s"\'()<>\\\\,|;/]+?\.(?:' . self::EXT . '))(?=$|[\s"\'()<>\\\\,|;?\#&])#i', $lower, $m ) ) {
				foreach ( $m[1] as $p ) {
					$paths[] = $p;
				}
			}
		}

		// --- IDs dans le contenu, les blocs et le JSON -------------------------
		$id_patterns = array(
			'/wp-image-(\d+)/',
			'/data-(?:attachment-)?id=["\']?(\d+)/i',
			'/attachment_id=(\d+)/i',
			'/"(?:id|mediaId|mediaID|attachment_id|attachmentId|imageId|image_id|imageID|logo_id|poster_id)"\s*:\s*"?(\d+)/',
			'/\b(?:id|attachment_id|image_id)=["\']?(\d+)/i',
		);
		foreach ( $id_patterns as $pattern ) {
			if ( preg_match_all( $pattern, $plain, $m ) ) {
				foreach ( $m[1] as $id ) {
					$ids[] = (int) $id;
				}
			}
		}

		// Galeries : [gallery ids="1,2,3"], "ids":[1,2,3], include="1,2".
		if ( preg_match_all( '/(?:\bids|\binclude)\s*=\s*["\']?([\d,\s]+)/i', $plain, $m ) ) {
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, self::ints_from_list( $list ) );
			}
		}
		if ( preg_match_all( '/"ids"\s*:\s*\[([\d,\s"]+)\]/', $plain, $m ) ) {
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, self::ints_from_list( str_replace( '"', '', $list ) ) );
			}
		}

		// --- Clés de meta ou d'options qui désignent des médias -----------------
		if ( '' !== $key && self::key_holds_ids( $key ) ) {
			$ids = array_merge( $ids, self::all_ints( $text ) );
		}

		return array(
			'ids'   => array_values( array_unique( array_filter( $ids ) ) ),
			'paths' => array_values( array_unique( array_map( array( __CLASS__, 'normalize_path' ), $paths ) ) ),
		);
	}

	public static function key_holds_ids( string $key ): bool {
		$key = strtolower( $key );
		if ( in_array( $key, self::ID_KEYS, true ) ) {
			return true;
		}
		if ( 0 === strpos( $key, 'theme_mods_' ) || 0 === strpos( $key, 'widget_' ) ) {
			return true;
		}
		foreach ( self::KEY_HINTS as $hint ) {
			if ( false !== strpos( $key, $hint ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tous les entiers d'une valeur : nombre, liste, sérialisé ou JSON.
	 *
	 * @return int[]
	 */
	public static function all_ints( string $value ): array {
		$value = trim( $value );
		if ( '' === $value ) {
			return array();
		}
		if ( ctype_digit( $value ) ) {
			return array( (int) $value );
		}
		if ( preg_match( '/^[\d,\s]+$/', $value ) ) {
			return self::ints_from_list( $value );
		}

		$data = null;
		if ( is_serialized( $value ) ) {
			// Pas d'objets : on ne reconstruit jamais une classe à partir d'une donnée stockée.
			$data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		} elseif ( '{' === $value[0] || '[' === $value[0] ) {
			$data = json_decode( $value, true );
		}

		if ( ! is_array( $data ) ) {
			return array();
		}

		$ints = array();
		array_walk_recursive(
			$data,
			static function ( $item ) use ( &$ints ) {
				if ( is_int( $item ) ) {
					$ints[] = $item;
				} elseif ( is_string( $item ) && '' !== $item && ctype_digit( $item ) && strlen( $item ) < 12 ) {
					$ints[] = (int) $item;
				} elseif ( is_string( $item ) && preg_match( '/^[\d,\s]+$/', $item ) ) {
					foreach ( self::ints_from_list( $item ) as $int ) {
						$ints[] = $int;
					}
				}
			}
		);

		return $ints;
	}

	/**
	 * @return int[]
	 */
	private static function ints_from_list( string $list ): array {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', $list ) as $part ) {
			if ( '' !== $part && ctype_digit( $part ) ) {
				$out[] = (int) $part;
			}
		}
		return $out;
	}

	public static function normalize_path( string $path ): string {
		$path = rawurldecode( $path );
		$path = strtolower( ltrim( str_replace( '\\', '/', $path ), '/' ) );
		$path = preg_replace( '#/+#', '/', $path );
		return str_replace( './', '', $path );
	}

	/**
	 * Radical d'un chemin : sans extension(s) ni suffixes générés par WordPress.
	 * « 2024/05/photo-e1700000000000-300x200.jpg.webp » devient « 2024/05/photo ».
	 * Les suffixes de doublon (-1, -2) sont conservés : ce sont des fichiers distincts.
	 */
	public static function radical( string $path ): string {
		$path = self::normalize_path( $path );
		$dir  = dirname( $path );
		$name = basename( $path );

		// Une ou deux extensions (« photo.jpg.webp »).
		$name = preg_replace( '/\.(?:' . self::EXT . ')$/i', '', $name );
		$name = preg_replace( '/\.(?:jpe?g|png|gif|webp|avif|bmp|tiff?|heic|heif)$/i', '', $name );

		do {
			$before = $name;
			$name   = preg_replace( '/-\d+x\d+$/', '', $name );
			$name   = preg_replace( '/-(?:scaled|rotated)$/', '', $name );
			$name   = preg_replace( '/-e\d{13}$/', '', $name );
		} while ( $name !== $before );

		return ( '.' === $dir || '' === $dir ) ? $name : $dir . '/' . $name;
	}
}
