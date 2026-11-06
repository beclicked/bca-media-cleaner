<?php
/**
 * Sources indexées par le scan.
 *
 * Chaque source est un tableau :
 *   key     identifiant court
 *   label   libellé affiché pendant le scan
 *   count   callable(): int, nombre d'unités à traiter (pour la progression)
 *   batch   callable( mixed $cursor, int $limit ): array{entries: array, next: mixed, processed: int}
 *           chaque entrée : array{source: string, source_id: int, text: string, key?: string}
 *           next vaut null quand la source est terminée.
 *
 * D'autres sources peuvent être ajoutées avec le filtre « bcamc_reference_sources ».
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_Sources {

	/** Metas des pièces jointes qui décrivent le fichier lui-même : ce ne sont pas des utilisations. */
	const SELF_META = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', '_wp_attachment_image_alt' );

	/** Metas techniques sans intérêt (et parfois volumineuses). */
	const SKIP_META = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme', 'session_tokens' );

	/** Tables de WordPress, lues par les sources dédiées. */
	const CORE_TABLES = array( 'posts', 'postmeta', 'options', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'users', 'usermeta', 'comments', 'commentmeta', 'links' );

	/**
	 * Tables de journaux, de statistiques et de files d'attente : volumineuses et sans
	 * utilisation réelle de médias. Filtrable avec « bcamc_skip_tables ».
	 */
	const SKIP_TABLES = array(
		'independent_analytics_*',
		'wf_sn_*',
		'wsal_*',
		'simple_history*',
		'actionscheduler_logs',
		'actionscheduler_claims',
		'wpmailsmtp_debug_events',
		'redirection_404',
		'redirection_logs',
		'e_events',
		'woocommerce_sessions',
		'wc_sessions',
		'wfblockediplog',
		'wfhits',
		'wflogins',
		'wfls_*',
		'itsec_logs',
		'itsec_temp',
		'yoast_seo_links',
		'yoast_indexable*',
		'seopress_seo_issues',
		'seopress_significant_keywords',
		'seopress_content_analysis',
	);

	/** Types de champs ACF et JetEngine qui stockent des médias. */
	const MEDIA_FIELD_TYPES = array( 'image', 'file', 'gallery', 'media', 'video', 'audio', 'oembed_media', 'image_crop', 'image_aspect_ratio_crop', 'focal_point_image' );

	/** Tables d'extensions connues : lues comme un contenu (IDs et chemins). */
	const KNOWN_TABLES = array(
		'fc_campaigns',
		'fc_funnels',
		'fc_funnel_sequences',
		'fc_meta',
		'fluentform_forms',
		'fluentform_form_meta',
		'fcal_calendars',
		'fcal_calendar_events',
		'fcal_meta',
		'presto_player_videos',
		'presto_player_presets',
		'learndash_pro_quiz_question',
	);

	/**
	 * @return array<string, array> Sources indexées par clé.
	 */
	public static function all(): array {
		$sources = array(
			'posts'    => array(
				'label' => __( 'Content (all post types and statuses)', 'bca-media-cleaner' ),
				'count' => array( __CLASS__, 'count_posts' ),
				'batch' => array( __CLASS__, 'batch_posts' ),
			),
			'postmeta' => array(
				'label' => __( 'Content settings (Elementor, featured images, SEO, galleries)', 'bca-media-cleaner' ),
				'count' => array( __CLASS__, 'count_postmeta' ),
				'batch' => array( __CLASS__, 'batch_postmeta' ),
			),
			'options'  => array(
				'label' => __( 'Site settings (logo, icon, theme, widgets)', 'bca-media-cleaner' ),
				'count' => array( __CLASS__, 'count_options' ),
				'batch' => array( __CLASS__, 'batch_options' ),
			),
			'termmeta' => array(
				'label' => __( 'Categories and tags', 'bca-media-cleaner' ),
				'count' => array( __CLASS__, 'count_termmeta' ),
				'batch' => array( __CLASS__, 'batch_termmeta' ),
			),
			'usermeta' => array(
				'label' => __( 'User profiles', 'bca-media-cleaner' ),
				'count' => array( __CLASS__, 'count_usermeta' ),
				'batch' => array( __CLASS__, 'batch_usermeta' ),
			),
			'comments' => array(
				'label' => __( 'Comments', 'bca-media-cleaner' ),
				'count' => array( __CLASS__, 'count_comments' ),
				'batch' => array( __CLASS__, 'batch_comments' ),
			),
			'tables'   => array(
				'label' => __( 'Plugin tables (FluentCRM, Fluent Forms, JetEngine...)', 'bca-media-cleaner' ),
				'count' => array( __CLASS__, 'count_tables' ),
				'batch' => array( __CLASS__, 'batch_tables' ),
			),
			'files'    => array(
				'label' => __( 'Style sheets and code snippets', 'bca-media-cleaner' ),
				'count' => array( __CLASS__, 'count_files' ),
				'batch' => array( __CLASS__, 'batch_files' ),
			),
		);

		$sources = (array) apply_filters( 'bcamc_reference_sources', $sources );

		foreach ( $sources as $key => $source ) {
			if ( ! is_array( $source ) || ! isset( $source['count'], $source['batch'] ) || ! is_callable( $source['count'] ) || ! is_callable( $source['batch'] ) ) {
				unset( $sources[ $key ] );
				continue;
			}
			$sources[ $key ]['label'] = (string) ( $source['label'] ?? $key );
		}

		return $sources;
	}

	/* -------------------------------------------------------------------------
	 * Contenus
	 * ---------------------------------------------------------------------- */

	private static function posts_where(): string {
		// Tous les types et statuts, sauf révisions, brouillons automatiques et pièces jointes elles-mêmes.
		return "post_type NOT IN ('revision','attachment') AND post_status <> 'auto-draft'";
	}

	public static function count_posts(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE " . self::posts_where() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function batch_posts( $cursor, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts} WHERE ID > %d AND " . self::posts_where() . ' ORDER BY ID ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $cursor,
				$limit
			),
			ARRAY_A
		);

		$entries = array();
		$last    = (int) $cursor;
		foreach ( $rows as $row ) {
			$last      = (int) $row['ID'];
			$entries[] = array(
				'source'    => 'post',
				'source_id' => $last,
				'text'      => $row['post_content'] . "\n" . $row['post_excerpt'],
			);
		}

		return self::result( $entries, $last, count( $rows ), $limit );
	}

	/* -------------------------------------------------------------------------
	 * Metas des contenus
	 * ---------------------------------------------------------------------- */

	public static function count_postmeta(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
	}

	public static function batch_postmeta( $cursor, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_status
				FROM {$wpdb->postmeta} pm
				LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_id > %d
				ORDER BY pm.meta_id ASC
				LIMIT %d",
				(int) $cursor,
				$limit
			),
			ARRAY_A
		);

		$entries = array();
		$last    = (int) $cursor;
		foreach ( $rows as $row ) {
			$last = (int) $row['meta_id'];
			$type = $row['post_type'];
			$key  = (string) $row['meta_key'];

			// Meta orpheline (contenu supprimé), révision ou brouillon automatique : pas une utilisation.
			if ( null === $type || 'revision' === $type || 'auto-draft' === $row['post_status'] ) {
				continue;
			}
			if ( in_array( $key, self::SKIP_META, true ) ) {
				continue;
			}
			// Les metas d'une pièce jointe la décrivent elle-même. Seule exception :
			// l'image de couverture d'une vidéo ou d'un son, qui désigne un autre média.
			if ( 'attachment' === $type && '_thumbnail_id' !== $key ) {
				continue;
			}

			$entries[] = array(
				'source'    => 'postmeta',
				'source_id' => (int) $row['post_id'],
				'text'      => (string) $row['meta_value'],
				'key'       => $key,
			);
		}

		return self::result( $entries, $last, count( $rows ), $limit );
	}

	/* -------------------------------------------------------------------------
	 * Options
	 * ---------------------------------------------------------------------- */

	private static function options_where(): string {
		global $wpdb;
		return $wpdb->prepare(
			'option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s',
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%',
			$wpdb->esc_like( 'bcamc_' ) . '%'
		);
	}

	public static function count_options(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE " . self::options_where() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function batch_options( $cursor, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id > %d AND " . self::options_where() . ' ORDER BY option_id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $cursor,
				$limit
			),
			ARRAY_A
		);

		$entries = array();
		$last    = (int) $cursor;
		foreach ( $rows as $row ) {
			$last      = (int) $row['option_id'];
			$entries[] = array(
				'source'    => 'option',
				'source_id' => $last,
				'text'      => (string) $row['option_value'],
				'key'       => (string) $row['option_name'],
			);
		}

		return self::result( $entries, $last, count( $rows ), $limit );
	}

	/* -------------------------------------------------------------------------
	 * Termes, utilisateurs, commentaires
	 * ---------------------------------------------------------------------- */

	public static function count_termmeta(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta}" );
	}

	public static function batch_termmeta( $cursor, int $limit ): array {
		global $wpdb;
		return self::meta_batch( $wpdb->termmeta, 'meta_id', 'term_id', 'termmeta', $cursor, $limit );
	}

	public static function count_usermeta(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" );
	}

	public static function batch_usermeta( $cursor, int $limit ): array {
		global $wpdb;
		return self::meta_batch( $wpdb->usermeta, 'umeta_id', 'user_id', 'usermeta', $cursor, $limit );
	}

	private static function meta_batch( string $table, string $pk, string $owner, string $source, $cursor, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$pk} AS pk, {$owner} AS owner, meta_key, meta_value FROM {$table} WHERE {$pk} > %d ORDER BY {$pk} ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $cursor,
				$limit
			),
			ARRAY_A
		);

		$entries = array();
		$last    = (int) $cursor;
		foreach ( $rows as $row ) {
			$last = (int) $row['pk'];
			if ( in_array( (string) $row['meta_key'], self::SKIP_META, true ) ) {
				continue;
			}
			$entries[] = array(
				'source'    => $source,
				'source_id' => (int) $row['owner'],
				'text'      => (string) $row['meta_value'],
				'key'       => (string) $row['meta_key'],
			);
		}

		return self::result( $entries, $last, count( $rows ), $limit );
	}

	public static function count_comments(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved NOT IN ('spam','trash')" );
	}

	public static function batch_comments( $cursor, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_content FROM {$wpdb->comments} WHERE comment_ID > %d AND comment_approved NOT IN ('spam','trash') ORDER BY comment_ID ASC LIMIT %d",
				(int) $cursor,
				$limit
			),
			ARRAY_A
		);

		$entries = array();
		$last    = (int) $cursor;
		foreach ( $rows as $row ) {
			$last      = (int) $row['comment_ID'];
			$entries[] = array(
				'source'    => 'comment',
				'source_id' => $last,
				'text'      => (string) $row['comment_content'],
			);
		}

		return self::result( $entries, $last, count( $rows ), $limit );
	}

	/* -------------------------------------------------------------------------
	 * Tables des extensions
	 * ---------------------------------------------------------------------- */

	/** @var array|null Cache des tables pour la requête en cours. */
	private static ?array $tables_cache = null;

	/**
	 * Toutes les tables du site autres que celles de WordPress, avec leur clé et leurs colonnes texte.
	 *
	 * Les tables connues (KNOWN_TABLES) sont lues comme un contenu. Les autres sont lues
	 * en mode strict : chemins de fichiers partout, IDs seulement sous des colonnes de média.
	 *
	 * @return array<int, array{table: string, pk: string, cols: string[], strict: bool}>
	 */
	public static function plugin_tables(): array {
		global $wpdb;

		if ( null !== self::$tables_cache ) {
			return self::$tables_cache;
		}

		$known = array();
		foreach ( (array) apply_filters( 'bcamc_scan_tables', self::KNOWN_TABLES ) as $suffix ) {
			$known[ $wpdb->prefix . preg_replace( '/[^a-z0-9_]/i', '', (string) $suffix ) ] = true;
		}
		$core = array();
		foreach ( self::CORE_TABLES as $suffix ) {
			$core[ $wpdb->prefix . $suffix ] = true;
		}
		$skip = (array) apply_filters( 'bcamc_skip_tables', self::SKIP_TABLES );

		$names = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		sort( $names );

		$tables = array();
		foreach ( $names as $table ) {
			$table = (string) $table;
			if ( isset( $core[ $table ] ) || ! preg_match( '/^[A-Za-z0-9_$]+$/', $table ) ) {
				continue;
			}
			$suffix = substr( $table, strlen( $wpdb->prefix ) );
			if ( str_starts_with( $suffix, 'bcamc_' ) || self::matches_any( $suffix, $skip ) ) {
				continue;
			}

			$strict = ! isset( $known[ $table ] );
			$pk     = '';
			$cols   = array();
			foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ) as $col ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type = strtolower( (string) ( $col['Type'] ?? '' ) );
				$name = (string) ( $col['Field'] ?? '' );
				if ( ! preg_match( '/^[A-Za-z0-9_$]+$/', $name ) ) {
					continue;
				}
				if ( '' === $pk && 'PRI' === ( $col['Key'] ?? '' ) && preg_match( '/int/', $type ) ) {
					$pk = $name;
				} elseif ( preg_match( $strict ? '/text|char|json/' : '/text|char|json|blob/', $type ) ) {
					$cols[] = $name;
				} elseif ( $strict && preg_match( '/int/', $type ) && BCAMC_Extractor::key_holds_ids( $name ) ) {
					// Colonne numérique nommée comme un média (« photo_id », champ média JetEngine...).
					$cols[] = $name;
				}
			}

			if ( $cols ) {
				$tables[] = array(
					'table'  => $table,
					'pk'     => $pk,
					'cols'   => $cols,
					'strict' => $strict,
				);
			}
		}

		self::$tables_cache = $tables;
		return $tables;
	}

	/** Oublie le cache des tables (nouveau scan, tests). */
	public static function flush(): void {
		self::$tables_cache = null;
	}

	/**
	 * @param string[] $patterns Motifs avec « * » comme joker.
	 */
	private static function matches_any( string $suffix, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( strtolower( (string) $pattern ), '/' ) ) . '$/';
			if ( preg_match( $regex, strtolower( $suffix ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function count_tables(): int {
		global $wpdb;
		$total = 0;
		foreach ( self::plugin_tables() as $t ) {
			$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$t['table']}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $total;
	}

	/**
	 * Curseur : array{t: int, id: int} (index de la table, dernière clé lue,
	 * ou nombre de lignes déjà lues pour une table sans clé numérique).
	 */
	public static function batch_tables( $cursor, int $limit ): array {
		global $wpdb;

		$tables = self::plugin_tables();
		$cursor = is_array( $cursor ) ? $cursor : array( 't' => 0, 'id' => 0 );
		$index  = (int) ( $cursor['t'] ?? 0 );
		$from   = (int) ( $cursor['id'] ?? 0 );

		if ( ! isset( $tables[ $index ] ) ) {
			return array( 'entries' => array(), 'next' => null, 'processed' => 0 );
		}

		$t    = $tables[ $index ];
		$cols = implode( ', ', array_map( static fn( $c ) => '`' . $c . '`', $t['cols'] ) );
		if ( '' !== $t['pk'] ) {
			$sql = $wpdb->prepare(
				"SELECT `{$t['pk']}` AS bcamc_pk, {$cols} FROM `{$t['table']}` WHERE `{$t['pk']}` > %d ORDER BY `{$t['pk']}` ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$from,
				$limit
			);
		} else {
			$sql = $wpdb->prepare( "SELECT 0 AS bcamc_pk, {$cols} FROM `{$t['table']}` LIMIT %d, %d", $from, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$rows = (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$source  = 'table:' . substr( $t['table'], strlen( $wpdb->prefix ) );
		$entries = array();
		$last    = $from;
		foreach ( $rows as $i => $row ) {
			$last = '' !== $t['pk'] ? (int) $row['bcamc_pk'] : $from + $i + 1;
			unset( $row['bcamc_pk'] );

			if ( ! $t['strict'] ) {
				$entries[] = array(
					'source'    => $source,
					'source_id' => $last,
					'text'      => implode( "\n", array_map( 'strval', $row ) ),
				);
				continue;
			}

			// Mode strict : chaque colonne est lue avec son nom, qui peut désigner un média.
			foreach ( $row as $col => $value ) {
				$value = (string) $value;
				if ( '' === $value || '0' === $value ) {
					continue;
				}
				$entries[] = array(
					'source'    => $source,
					'source_id' => $last,
					'text'      => $value,
					'key'       => (string) $col,
					'strict'    => true,
				);
			}
		}

		if ( count( $rows ) < $limit ) {
			$next = isset( $tables[ $index + 1 ] ) ? array( 't' => $index + 1, 'id' => 0 ) : null;
		} else {
			$next = array( 't' => $index, 'id' => $last );
		}

		return array( 'entries' => $entries, 'next' => $next, 'processed' => count( $rows ) );
	}

	/* -------------------------------------------------------------------------
	 * Champs média déclarés par les constructeurs de champs (ACF, JetEngine)
	 * ---------------------------------------------------------------------- */

	/**
	 * Noms des champs de type image, fichier ou galerie. Une meta, une option ou
	 * une colonne portant ce nom contient des IDs de médias, même si le nom
	 * ne le laisse pas deviner (« visuel », « portrait », « plan_acces »...).
	 *
	 * @return string[]
	 */
	public static function media_field_keys(): array {
		global $wpdb;
		$defs = array();

		// ACF : groupes enregistrés en base, en JSON local ou en PHP.
		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups() as $group ) {
				$defs[] = (array) acf_get_fields( $group );
			}
		}
		$acf = $wpdb->get_results( "SELECT post_excerpt, post_content FROM {$wpdb->posts} WHERE post_type = 'acf-field'", ARRAY_A );
		foreach ( (array) $acf as $row ) {
			$field = @unserialize( (string) $row['post_content'], array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
			if ( is_array( $field ) ) {
				$field['name'] = (string) $row['post_excerpt'];
				$defs[]        = array( $field );
			}
		}

		// JetEngine : meta boxes, puis types de contenus, contenus personnalisés et taxonomies.
		$defs[] = (array) get_option( 'jet_engine_meta_boxes', array() );
		foreach ( array( 'jet_post_types', 'jet_taxonomies' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				continue;
			}
			$columns = array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! in_array( 'meta_fields', $columns, true ) ) {
				continue;
			}
			foreach ( (array) $wpdb->get_col( "SELECT meta_fields FROM `{$table}`" ) as $raw ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$data = is_serialized( (string) $raw )
					? @unserialize( (string) $raw, array( 'allowed_classes' => false ) ) // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
					: json_decode( (string) $raw, true );
				if ( is_array( $data ) ) {
					$defs[] = $data;
				}
			}
		}

		$keys = array();
		foreach ( $defs as $def ) {
			self::collect_media_fields( $def, $keys, 0 );
		}

		$keys = (array) apply_filters( 'bcamc_media_field_keys', array_keys( $keys ) );
		$keys = array_values( array_unique( array_filter( array_map( static fn( $k ) => strtolower( trim( (string) $k ) ), $keys ), static fn( $k ) => strlen( $k ) >= 3 ) ) );
		sort( $keys );
		return $keys;
	}

	/**
	 * Parcourt une définition de champs et retient les noms des champs média,
	 * à tous les niveaux (répéteurs, contenus flexibles, onglets).
	 */
	private static function collect_media_fields( $data, array &$keys, int $depth ): void {
		if ( ! is_array( $data ) || $depth > 10 ) {
			return;
		}
		if ( isset( $data['type'], $data['name'] ) && is_string( $data['type'] ) && is_string( $data['name'] )
			&& in_array( strtolower( $data['type'] ), self::MEDIA_FIELD_TYPES, true ) ) {
			$keys[ $data['name'] ] = true;
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				self::collect_media_fields( $value, $keys, $depth + 1 );
			}
		}
	}

	/* -------------------------------------------------------------------------
	 * Fichiers : CSS générés (Elementor et autres) et snippets FluentSnippets
	 * ---------------------------------------------------------------------- */

	/**
	 * Liste triée des fichiers à lire.
	 *
	 * @return string[] Chemins absolus.
	 */
	public static function code_files(): array {
		$files   = array();
		$uploads = wp_get_upload_dir();
		$base    = wp_normalize_path( $uploads['basedir'] );

		// Feuilles de style hors dossiers datés (uploads/elementor/css, etc.), profondeur limitée.
		if ( is_dir( $base ) ) {
			foreach ( (array) scandir( $base ) as $entry ) {
				if ( '.' === $entry[0] || preg_match( '/^(19|20)\d{2}$/', (string) $entry ) || str_starts_with( (string) $entry, 'bca-mc-quarantine' ) ) {
					continue;
				}
				$path = $base . '/' . $entry;
				if ( is_file( $path ) && preg_match( '/\.css$/i', $entry ) ) {
					$files[] = $path;
				} elseif ( is_dir( $path ) ) {
					$files = array_merge( $files, self::find_files( $path, '/\.css$/i', 4 ) );
				}
			}
		}

		// Snippets FluentSnippets (emplacement par défaut ou constante de configuration).
		$snippets = ( defined( 'FLUENT_SNIPPETS_STORAGE_DIR' ) && FLUENT_SNIPPETS_STORAGE_DIR )
			? untrailingslashit( (string) FLUENT_SNIPPETS_STORAGE_DIR )
			: WP_CONTENT_DIR . '/fluent-snippet-storage';
		if ( is_dir( $snippets ) ) {
			$files = array_merge( $files, self::find_files( $snippets, '/\.(php|css|js)$/i', 2 ) );
		}

		$files = array_values( array_unique( array_map( 'wp_normalize_path', $files ) ) );
		sort( $files );

		return (array) apply_filters( 'bcamc_scan_code_files', $files );
	}

	/**
	 * @return string[]
	 */
	private static function find_files( string $dir, string $pattern, int $depth ): array {
		$found = array();
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			$iterator->setMaxDepth( $depth );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && preg_match( $pattern, $file->getFilename() ) && $file->getSize() < 5 * MB_IN_BYTES ) {
					$found[] = $file->getPathname();
				}
			}
		} catch ( Exception $e ) {
			return $found;
		}
		return $found;
	}

	public static function count_files(): int {
		return count( self::code_files() );
	}

	public static function batch_files( $cursor, int $limit ): array {
		$files  = self::code_files();
		$offset = (int) $cursor;
		$slice  = array_slice( $files, $offset, max( 1, (int) ceil( $limit / 4 ) ) );

		$entries = array();
		foreach ( $slice as $path ) {
			$content = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $content ) {
				continue;
			}
			$entries[] = array(
				'source'    => 'file:' . basename( $path ),
				'source_id' => 0,
				'text'      => $content,
			);
		}

		$next = $offset + count( $slice );
		return array(
			'entries'   => $entries,
			'next'      => $next < count( $files ) ? $next : null,
			'processed' => count( $slice ),
		);
	}

	/* -------------------------------------------------------------------------
	 * Utilitaire
	 * ---------------------------------------------------------------------- */

	private static function result( array $entries, int $last, int $fetched, int $limit ): array {
		return array(
			'entries'   => $entries,
			'next'      => $fetched < $limit ? null : $last,
			'processed' => $fetched,
		);
	}
}
