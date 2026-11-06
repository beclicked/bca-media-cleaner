<?php
/**
 * Tests automatisés de BCA Media Cleaner (phase 1 : détection).
 *
 * À lancer sur un WordPress de test, jamais sur un site réel :
 *   wp eval-file wp-content/plugins/bca-media-cleaner/tests/run.php
 *
 * Le script crée ses propres contenus, médias et fichiers, lance le scan,
 * vérifie chaque résultat, puis contrôle que le scan n'a rien modifié.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BCAMC_TESTS' ) && ! getenv( 'BCAMC_TESTS' ) ) {
	WP_CLI::error( 'Tests destinés à un site de test uniquement. Relancez avec BCAMC_TESTS=1.' );
}

global $wpdb;

$GLOBALS['bcamc_t_fails']  = 0;
$GLOBALS['bcamc_t_checks'] = 0;
function bcamc_t_ok( $cond, $label ) {
	++$GLOBALS['bcamc_t_checks'];
	echo ( $cond ? "[OK]   " : "[FAIL] " ) . $label . "\n";
	if ( ! $cond ) {
		++$GLOBALS['bcamc_t_fails'];
	}
}

$uploads = wp_get_upload_dir();
$base    = $uploads['basedir'];
$baseurl = $uploads['baseurl'];
$old     = strtotime( '2024-01-01 12:00:00' );

function bcamc_t_file( $rel, $bytes = 1000, $mtime = null ) {
	$base = wp_get_upload_dir()['basedir'];
	$path = $base . '/' . $rel;
	wp_mkdir_p( dirname( $path ) );
	file_put_contents( $path, str_repeat( 'x', $bytes ) );
	touch( $path, $mtime ?? strtotime( '2024-01-01 12:00:00' ) );
	return $path;
}

/**
 * Crée un média avec ses fichiers. $sizes : nom => fichier (même dossier).
 */
function bcamc_t_attachment( $rel, $args = array() ) {
	$args = array_merge(
		array(
			'mime'  => 'image/jpeg',
			'date'  => '2024-01-15 10:00:00',
			'sizes' => array( 'thumbnail' => preg_replace( '/\.(\w+)$/', '-150x150.$1', basename( $rel ) ) ),
			'bytes' => 10000,
		),
		$args
	);

	bcamc_t_file( $rel, $args['bytes'] );
	$sizes = array();
	foreach ( $args['sizes'] as $name => $file ) {
		bcamc_t_file( dirname( $rel ) . '/' . $file, 1000 );
		$sizes[ $name ] = array( 'file' => $file, 'width' => 100, 'height' => 100, 'mime-type' => $args['mime'] );
	}

	$id = wp_insert_post(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_title'     => basename( $rel ),
			'post_mime_type' => $args['mime'],
			'post_date'      => $args['date'],
			'post_date_gmt'  => $args['date'],
		)
	);
	update_post_meta( $id, '_wp_attached_file', $rel );
	update_post_meta( $id, '_wp_attachment_metadata', array( 'file' => $rel, 'width' => 1000, 'height' => 800, 'sizes' => $sizes ) );
	return $id;
}

function bcamc_t_post( $content, $args = array() ) {
	return wp_insert_post(
		array_merge(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Test ' . wp_generate_password( 6, false ),
				'post_content' => $content,
			),
			$args
		)
	);
}

function bcamc_t_items( $category ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . BCAMC_DB::items_table() . ' WHERE category = %s', $category ), ARRAY_A );
}

function bcamc_t_is_b( $id ) {
	foreach ( bcamc_t_items( 'B' ) as $item ) {
		if ( (int) $item['attachment_id'] === (int) $id ) {
			return $item;
		}
	}
	return null;
}

/* ===========================================================================
 * Nettoyage du site de test
 * ======================================================================== */

foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts}" ) as $pid ) {
	wp_delete_post( (int) $pid, true );
}
$wpdb->query( "DELETE FROM {$wpdb->postmeta}" );
foreach ( array( '2024', 'elementor' ) as $dir ) {
	if ( is_dir( $base . '/' . $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base . '/' . $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
		}
		rmdir( $base . '/' . $dir );
	}
}
foreach ( array( 'fc_campaigns', 'fp_videos', 'fs_tickets', 'team_members', 'custom_kv', 'jet_post_types', 'jet_cct_partenaires', 'independent_analytics_views' ) as $t_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$t_table}" );
}
delete_option( 'jet_engine_meta_boxes' );
delete_option( 'reglages-site' );
delete_option( 't_schedule_store' );
delete_option( 'site_icon' );
delete_option( 'theme_t_custom_option' );
remove_theme_mod( 'custom_logo' );

/* ===========================================================================
 * Fixtures
 * ======================================================================== */

$u = rtrim( $baseurl, '/' ) . '/';

// S1 Elementor : chemin échappé seul, puis ID seul (chaîne).
$e1 = bcamc_t_attachment( '2024/01/elementor-hero.jpg' );
$e2 = bcamc_t_attachment( '2024/01/elementor-card.jpg' );
$page = bcamc_t_post( '', array( 'post_title' => 'Elementor page' ) );
$json = wp_json_encode(
	array(
		array( 'id' => 'a1b2c3d', 'elType' => 'widget', 'settings' => array( 'image' => array( 'url' => $u . '2024/01/elementor-hero-1024x683.jpg', 'id' => '' ) ) ),
		array( 'id' => 'e4f5a6b', 'elType' => 'widget', 'settings' => array( 'image' => array( 'url' => '', 'id' => (string) $e2 ) ) ),
	)
);
update_post_meta( $page, '_elementor_data', wp_slash( $json ) );

// S2 Fond CSS : CSS personnalisé du Customizer, puis fichier CSS généré par Elementor (chemin relatif).
$css1 = bcamc_t_attachment( '2024/01/bg-texture.jpg' );
wp_insert_post( array( 'post_type' => 'custom_css', 'post_status' => 'publish', 'post_title' => get_stylesheet(), 'post_content' => 'body{background:url(/wp-content/uploads/2024/01/bg-texture.jpg)}' ) );
$css2 = bcamc_t_attachment( '2024/01/elementor-bg.png', array( 'mime' => 'image/png' ) );
wp_mkdir_p( $base . '/elementor/css' );
file_put_contents( $base . '/elementor/css/post-12.css', '.elementor-12 .x{background-image:url("../../2024/01/elementor-bg.png");}' );

// S3 Logo du Customizer et icône du site.
$logo = bcamc_t_attachment( '2024/01/logo.png', array( 'mime' => 'image/png' ) );
set_theme_mod( 'custom_logo', $logo );
$icon = bcamc_t_attachment( '2024/01/site-icon.png', array( 'mime' => 'image/png' ) );
update_option( 'site_icon', $icon );

// S4 Image Open Graph SEOPress (URL uniquement).
$og = bcamc_t_attachment( '2024/01/og-share.jpg' );
$seo_page = bcamc_t_post( '<p>SEO</p>' );
update_post_meta( $seo_page, '_seopress_social_fb_img', $u . '2024/01/og-share.jpg' );

// S5 Galerie WooCommerce.
$g1 = bcamc_t_attachment( '2024/01/product-1.jpg' );
$g2 = bcamc_t_attachment( '2024/01/product-2.jpg' );
$product = bcamc_t_post( '', array( 'post_type' => 'product' ) );
update_post_meta( $product, '_product_image_gallery', $g1 . ',' . $g2 );

// S6 Article programmé, brouillon, corbeille.
$sched = bcamc_t_attachment( '2024/01/scheduled.jpg' );
bcamc_t_post( '<img class="wp-image-' . $sched . '" src="x">', array( 'post_type' => 'post', 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ) ) );
$draft = bcamc_t_attachment( '2024/01/draft.jpg' );
bcamc_t_post( '<!-- wp:image {"id":' . $draft . '} -->', array( 'post_status' => 'draft' ) );
$trash = bcamc_t_attachment( '2024/01/trashed.jpg' );
bcamc_t_post( '<img class="wp-image-' . $trash . '">', array( 'post_status' => 'trash' ) );

// S7 Tailles : une taille non enregistrée citée dans un ancien article, une autre jamais citée (avec WebP).
$sz = bcamc_t_attachment(
	'2024/01/sizes.jpg',
	array(
		'sizes' => array(
			'thumbnail'      => 'sizes-150x150.jpg',
			'old_theme_size' => 'sizes-800x600.jpg',
			'legacy_size'    => 'sizes-1200x400.jpg',
		),
	)
);
bcamc_t_file( '2024/01/sizes-1200x400.jpg.webp', 400 );
$featured_post = bcamc_t_post( 'Uses sizes.jpg as featured image', array( 'post_type' => 'post' ) );
update_post_meta( $featured_post, '_thumbnail_id', $sz );
bcamc_t_post( '<img src="' . $u . '2024/01/sizes-800x600.jpg">', array( 'post_type' => 'post', 'post_date' => '2019-05-01 10:00:00' ) );

// S8 PDF lié dans une campagne FluentCRM, et PDF orphelin.
$wpdb->query( "CREATE TABLE {$wpdb->prefix}fc_campaigns (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, title varchar(191), email_body longtext, PRIMARY KEY (id))" );
$wpdb->insert( "{$wpdb->prefix}fc_campaigns", array( 'title' => 'Newsletter', 'email_body' => '<a href="' . $u . '2024/01/guide.pdf">Guide</a>' ) );
$pdf  = bcamc_t_attachment( '2024/01/guide.pdf', array( 'mime' => 'application/pdf', 'sizes' => array() ) );
$pdf2 = bcamc_t_attachment( '2024/01/orphan-doc.pdf', array( 'mime' => 'application/pdf', 'sizes' => array() ) );

// S9 Image orpheline avec variantes WebP (FlyingPress) des deux formes.
$webp = bcamc_t_attachment( '2024/01/orphan-photo.jpg' );
bcamc_t_file( '2024/01/orphan-photo.jpg.webp', 3000 );
bcamc_t_file( '2024/01/orphan-photo.webp', 2000 );

// S10 Image réellement orpheline.
$orphan = bcamc_t_attachment( '2024/01/truly-orphan.jpg', array( 'bytes' => 50000 ) );

// S11 Fichiers hors médiathèque.
bcamc_t_file( '2024/01/leftover-300x200.jpg', 7000 );
bcamc_t_file( '2024/01/recent-leftover.jpg', 7000, time() );
bcamc_t_file( '2024/01/index.php', 10 );
bcamc_t_file( '2024/01/cited-leftover.jpg', 7000 );
bcamc_t_post( '<a href="' . $u . '2024/01/cited-leftover.jpg">file</a>' );
bcamc_t_file( 'elementor/thumbs/thumb-abc.jpg', 5000 );

// S12 Référence uniquement dans une révision.
$rev = bcamc_t_attachment( '2024/01/revision-only.jpg' );
$rev_parent = bcamc_t_post( 'current version without image' );
wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $rev_parent, 'post_content' => '<img class="wp-image-' . $rev . '">' ) );

// S13 Média orphelin récent (ajouté aujourd'hui).
$recent = bcamc_t_attachment( '2024/01/recent-orphan.jpg', array( 'date' => current_time( 'mysql', true ) ) );

// S14 Média orphelin dont les metas citent son propre chemin (optimiseur d'images).
$self = bcamc_t_attachment( '2024/01/self-meta.jpg' );
update_post_meta( $self, '_optimizer_data', array( 'webp' => $u . '2024/01/self-meta.jpg.webp', 'file' => '2024/01/self-meta.jpg' ) );

// S16 Élément de menu pointant vers un média.
$menu_att = bcamc_t_attachment( '2024/01/menu-file.jpg' );
$menu_item = wp_insert_post( array( 'post_type' => 'nav_menu_item', 'post_status' => 'publish', 'post_title' => 'Menu' ) );
update_post_meta( $menu_item, '_menu_item_object', 'attachment' );
update_post_meta( $menu_item, '_menu_item_object_id', $menu_att );

// S17 Widget image.
$widget_att = bcamc_t_attachment( '2024/01/widget.jpg' );
update_option( 'widget_media_image', array( 2 => array( 'attachment_id' => $widget_att, 'url' => '' ), '_multiwidget' => 1 ) );

// S18 Image d'une catégorie de produits (term meta).
$term_att = bcamc_t_attachment( '2024/01/category.jpg' );
$term     = wp_insert_term( 'Cat ' . wp_generate_password( 4, false ), 'category' );
update_term_meta( $term['term_id'], 'thumbnail_id', $term_att );

// S19 Bloc Gutenberg et shortcode galerie.
$blk = bcamc_t_attachment( '2024/01/block.jpg' );
$ga1 = bcamc_t_attachment( '2024/01/gallery-1.jpg' );
$ga2 = bcamc_t_attachment( '2024/01/gallery-2.jpg' );
bcamc_t_post( '<!-- wp:cover {"id":' . $blk . ',"dimRatio":50} --><!-- /wp:cover -->[gallery ids="' . $ga1 . ', ' . $ga2 . '"]' );

// S20 Image de couverture d'une vidéo (meta d'une pièce jointe).
$video  = bcamc_t_attachment( '2024/01/clip.mp4', array( 'mime' => 'video/mp4', 'sizes' => array() ) );
$poster = bcamc_t_attachment( '2024/01/clip-poster.jpg' );
update_post_meta( $video, '_thumbnail_id', $poster );
bcamc_t_post( '<!-- wp:video {"id":' . $video . '} -->' );

// S22 Suffixe de doublon : « photo-1.jpg » orphelin alors que « photo.jpg » est utilisé.
$photo  = bcamc_t_attachment( '2024/01/photo.jpg' );
$photo1 = bcamc_t_attachment( '2024/01/photo-1.jpg' );
bcamc_t_post( '<img src="' . $u . '2024/01/photo-300x200.jpg">' );

// S23 Image retouchée (suffixe -e) citée dans une de ses tailles.
$edit = bcamc_t_attachment( '2024/01/edit-e1700000000000.jpg' );
bcamc_t_post( '<img src="' . $u . '2024/01/edit-e1700000000000-300x200.jpg?ver=2">' );

// S24 Option non chargée automatiquement contenant une URL.
$opt_att = bcamc_t_attachment( '2024/01/option-banner.jpg' );
add_option( 'theme_t_custom_option', array( 'hero' => $u . '2024/01/option-banner.jpg' ), '', 'no' );

// S25 Avatar local dans le profil.
$avatar = bcamc_t_attachment( '2024/01/avatar.jpg' );
update_user_meta( 1, 'simple_local_avatar', array( 'media_id' => $avatar, 'full' => '' ) );

// S26 Lien dans un commentaire.
$com_att = bcamc_t_attachment( '2024/01/comment-file.jpg' );
wp_insert_comment( array( 'comment_post_ID' => $seo_page, 'comment_content' => 'See ' . $u . '2024/01/comment-file.jpg', 'comment_approved' => 1 ) );

// S27 Adresse par CDN avec chemin daté sans « wp-content/uploads ».
$cdn = bcamc_t_attachment( '2024/01/cdn-image.jpg' );
bcamc_t_post( '<img src="https://bca.b-cdn.net/2024/01/cdn-image-768x512.jpg">' );

// S28 Snippet FluentSnippets.
$snip_att = bcamc_t_attachment( '2024/01/snippet-bg.jpg' );
wp_mkdir_p( WP_CONTENT_DIR . '/fluent-snippet-storage' );
file_put_contents( WP_CONTENT_DIR . '/fluent-snippet-storage/1-custom.php', "<?php\n// <style>.hero{background:url('" . $u . "2024/01/snippet-bg.jpg')}</style>" );


// S29 Table d'une extension inconnue (lecteur vidéo) : adresse du fichier dans un réglage JSON.
$fp_video = bcamc_t_attachment( '2024/01/fp-video.mp4', array( 'mime' => 'video/mp4', 'sizes' => array() ) );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}fp_videos (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, title varchar(191), settings longtext, PRIMARY KEY (id))" );
$wpdb->insert( "{$wpdb->prefix}fp_videos", array( 'title' => 'Demo', 'settings' => wp_json_encode( array( 'src' => $u . '2024/01/fp-video.mp4' ) ) ) );

// S30 Table inconnue où un « "id":N » désigne autre chose qu'un média (ticket de support).
$strict_att = bcamc_t_attachment( '2024/01/strict-orphan.jpg' );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}fs_tickets (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, data longtext, PRIMARY KEY (id))" );
$wpdb->insert( "{$wpdb->prefix}fs_tickets", array( 'data' => '{"id":' . $strict_att . ',"title":"Help"}' ) );

// S31 Table inconnue avec une colonne numérique nommée comme un média.
$team_att = bcamc_t_attachment( '2024/01/team-photo.jpg' );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}team_members (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(191), photo_id bigint(20), PRIMARY KEY (id))" );
$wpdb->insert( "{$wpdb->prefix}team_members", array( 'name' => 'Eric', 'photo_id' => $team_att ) );

// S32 Table sans clé numérique (clé texte).
$kv_att = bcamc_t_attachment( '2024/01/kv-image.jpg' );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}custom_kv (k varchar(64) NOT NULL, v longtext, PRIMARY KEY (k))" );
$wpdb->insert( "{$wpdb->prefix}custom_kv", array( 'k' => 'hero', 'v' => 'url(' . $u . '2024/01/kv-image.jpg)' ) );

// S33 Champs ACF de type image au nom neutre, simple et dans un répéteur.
$acf_att  = bcamc_t_attachment( '2024/01/acf-visuel.jpg' );
$acf_att2 = bcamc_t_attachment( '2024/01/acf-portrait.jpg' );
foreach ( array( 'visuel' => 'image', 'portrait' => 'image', 'intro' => 'text' ) as $f_name => $f_type ) {
	$wpdb->insert( $wpdb->posts, array( 'post_type' => 'acf-field', 'post_status' => 'publish', 'post_excerpt' => $f_name, 'post_name' => 'field_' . $f_name, 'post_content' => serialize( array( 'type' => $f_type, 'return_format' => 'id' ) ), 'post_title' => $f_name, 'post_date' => '2024-01-01 00:00:00', 'post_date_gmt' => '2024-01-01 00:00:00', 'post_modified' => '2024-01-01 00:00:00', 'post_modified_gmt' => '2024-01-01 00:00:00', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '' ) );
}
$acf_page = bcamc_t_post( 'ACF page' );
update_post_meta( $acf_page, 'visuel', (string) $acf_att );
update_post_meta( $acf_page, 'equipe_0_portrait', (string) $acf_att2 );
$acf_unused = bcamc_t_attachment( '2024/01/acf-intro-number.jpg' );
update_post_meta( $acf_page, 'intro', (string) $acf_unused );

// S34 Meta box JetEngine : champ média et galerie aux noms neutres.
$jet_att  = bcamc_t_attachment( '2024/01/jet-plan.jpg' );
$jet_g1   = bcamc_t_attachment( '2024/01/jet-gal-1.jpg' );
$jet_g2   = bcamc_t_attachment( '2024/01/jet-gal-2.jpg' );
update_option(
	'jet_engine_meta_boxes',
	array(
		'meta-1' => array(
			'args'        => array( 'name' => 'Infos' ),
			'meta_fields' => array(
				array( 'name' => 'plan_acces', 'type' => 'media', 'value_format' => 'id' ),
				array( 'name' => 'galerie_jet', 'type' => 'gallery', 'value_format' => 'id' ),
			),
		),
	)
);
update_post_meta( $acf_page, 'plan_acces', (string) $jet_att );
update_post_meta( $acf_page, 'galerie_jet', $jet_g1 . ',' . $jet_g2 );

// S35 Contenu personnalisé JetEngine (table dédiée) et S36 page d'options JetEngine.
$cct_att = bcamc_t_attachment( '2024/01/cct-visuel.jpg' );
$opt_jet = bcamc_t_attachment( '2024/01/jet-option.jpg' );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}jet_post_types (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, slug varchar(191), status varchar(40), meta_fields longtext, PRIMARY KEY (id))" );
$wpdb->insert( "{$wpdb->prefix}jet_post_types", array( 'slug' => 'partenaires', 'status' => 'content-type', 'meta_fields' => serialize( array( array( 'name' => 'visuel_cct', 'type' => 'media' ) ) ) ) );
$wpdb->insert( "{$wpdb->prefix}jet_post_types", array( 'slug' => 'reglages-site', 'status' => 'page', 'meta_fields' => serialize( array( array( 'name' => 'fond_entete', 'type' => 'media' ) ) ) ) );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}jet_cct_partenaires (_ID bigint(20) unsigned NOT NULL AUTO_INCREMENT, nom text, visuel_cct text, PRIMARY KEY (_ID))" );
$wpdb->insert( "{$wpdb->prefix}jet_cct_partenaires", array( 'nom' => 'Partenaire', 'visuel_cct' => (string) $cct_att ) );
update_option( 'reglages-site', array( 'titre' => 'Site', 'fond_entete' => (string) $opt_jet ), false );

// S37 Table de statistiques ignorée : un chemin n'y vaut pas utilisation.
$log_att = bcamc_t_attachment( '2024/01/log-only.jpg' );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}independent_analytics_views (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, url text, PRIMARY KEY (id))" );
$wpdb->insert( "{$wpdb->prefix}independent_analytics_views", array( 'url' => $u . '2024/01/log-only.jpg' ) );

// S39 Nombre rangé sous une clé qui contient « files » sans désigner un média (tâches planifiées).
$cron_att = bcamc_t_attachment( '2024/01/cron-noise.jpg' );
update_option( 't_schedule_store', array( 'wp_privacy_delete_old_export_files' => array( 'interval' => $cron_att ) ), false );

// S38 Réglages de page Elementor sérialisés (image de fond par ID).
$bg_att = bcamc_t_attachment( '2024/01/page-bg.jpg' );
update_post_meta( $acf_page, '_elementor_page_settings', array( 'background_image' => array( 'id' => $bg_att, 'url' => '' ) ) );

/* ===========================================================================
 * Empreinte avant scan (contrôle lecture seule)
 * ======================================================================== */

function bcamc_t_fingerprint() {
	global $wpdb;
	$files = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( wp_get_upload_dir()['basedir'], FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$files[] = $f->getPathname() . ':' . $f->getSize() . ':' . $f->getMTime();
	}
	sort( $files );
	return md5(
		implode( '|', $files )
		. $wpdb->get_var( "SELECT GROUP_CONCAT(ID || post_status || post_modified_gmt) FROM {$wpdb->posts}" )
		. $wpdb->get_var( "SELECT COUNT(*) || SUM(LENGTH(meta_value)) FROM {$wpdb->postmeta}" )
		. $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE 'bcamc%' AND option_name NOT LIKE '%transient%'" )
	);
}
$before = bcamc_t_fingerprint();

/* ===========================================================================
 * Scan (documents inclus pour tester les PDF)
 * ======================================================================== */

BCAMC_Scanner::run( array( 'include_documents' => true, 'min_age_days' => 30, 'batch_size' => 200 ) );

bcamc_t_ok( 'done' === BCAMC_Scanner::state()['status'], 'Scan terminé' );
bcamc_t_ok( bcamc_t_fingerprint() === $before, 'Lecture seule : aucun fichier, contenu ni réglage modifié' );

$used = array(
	'S1  Elementor, chemin échappé seul'        => $e1,
	'S1  Elementor, ID seul'                    => $e2,
	'S2  Fond CSS du Customizer'                => $css1,
	'S2  Fond dans un CSS généré par Elementor' => $css2,
	'S3  Logo du Customizer'                    => $logo,
	'S3  Icône du site'                         => $icon,
	'S4  Image Open Graph SEOPress'             => $og,
	'S5  Galerie WooCommerce (1)'               => $g1,
	'S5  Galerie WooCommerce (2)'               => $g2,
	'S6  Article programmé'                     => $sched,
	'S6  Brouillon'                             => $draft,
	'S6  Contenu dans la corbeille'             => $trash,
	'S7  Image à la une'                        => $sz,
	'S8  PDF lié dans une campagne FluentCRM'   => $pdf,
	'S16 Élément de menu'                       => $menu_att,
	'S17 Widget image'                          => $widget_att,
	'S18 Image de catégorie (term meta)'        => $term_att,
	'S19 Bloc Gutenberg'                        => $blk,
	'S19 Shortcode galerie (1)'                 => $ga1,
	'S19 Shortcode galerie (2)'                 => $ga2,
	'S20 Vidéo insérée'                         => $video,
	'S20 Image de couverture de la vidéo'       => $poster,
	'S22 photo.jpg citée par une de ses tailles' => $photo,
	'S23 Image retouchée (suffixe -e)'          => $edit,
	'S24 Option non chargée automatiquement'    => $opt_att,
	'S25 Avatar local'                          => $avatar,
	'S26 Lien dans un commentaire'              => $com_att,
	'S27 Adresse CDN sans wp-content/uploads'   => $cdn,
	'S28 Snippet FluentSnippets'                => $snip_att,
	'S13 Média orphelin récent (protégé par l\'âge)' => $recent,
	'S29 Table inconnue : adresse dans un JSON'  => $fp_video,
	'S31 Table inconnue : colonne photo_id'      => $team_att,
	'S32 Table sans clé numérique'              => $kv_att,
	'S33 Champ ACF image « visuel »'            => $acf_att,
	'S33 Champ ACF dans un répéteur'            => $acf_att2,
	'S34 Champ média JetEngine'                 => $jet_att,
	'S34 Galerie JetEngine (1)'                 => $jet_g1,
	'S34 Galerie JetEngine (2)'                 => $jet_g2,
	'S35 Contenu personnalisé JetEngine'        => $cct_att,
	'S36 Page d\'options JetEngine'             => $opt_jet,
	'S38 Réglages de page Elementor sérialisés' => $bg_att,
);
foreach ( $used as $label => $id ) {
	bcamc_t_ok( null === bcamc_t_is_b( $id ), $label . ' : conservé' );
}

$unused = array(
	'S8  PDF orphelin (documents inclus)'           => $pdf2,
	'S9  Image orpheline avec variantes WebP'       => $webp,
	'S10 Image réellement orpheline'                => $orphan,
	'S12 Image citée seulement dans une révision'   => $rev,
	'S14 Image dont seules ses propres metas parlent' => $self,
	'S22 Doublon photo-1.jpg non utilisé'           => $photo1,
	'S30 « "id":N » d\'un ticket dans une table inconnue' => $strict_att,
	'S33 Nombre dans un champ ACF texte'            => $acf_unused,
	'S37 Chemin seulement dans les statistiques'    => $log_att,
	'S39 Nombre sous une clé « ..._files » (cron)'   => $cron_att,
);
foreach ( $unused as $label => $id ) {
	bcamc_t_ok( null !== bcamc_t_is_b( $id ), $label . ' : détecté inutilisé' );
}

// Poids : l'image orpheline compte son original, sa miniature et ses deux variantes WebP.
$item = bcamc_t_is_b( $webp );
bcamc_t_ok( $item && 10000 + 1000 + 3000 + 2000 === (int) $item['bytes'], 'S9  Poids total = original + taille + variantes WebP (' . ( $item['bytes'] ?? '?' ) . ' octets)' );
bcamc_t_ok( $item && 4 === count( json_decode( $item['files'], true ) ), 'S9  4 fichiers rattachés au média' );

// Catégorie A.
$a_items = bcamc_t_items( 'A' );
$a_names = array_map( static fn( $i ) => $i['size_name'], $a_items );
bcamc_t_ok( in_array( 'legacy_size', $a_names, true ), 'S7  Taille non enregistrée et jamais citée : détectée' );
bcamc_t_ok( ! in_array( 'old_theme_size', $a_names, true ), 'S7  Taille non enregistrée mais citée dans un ancien article : conservée' );
bcamc_t_ok( ! in_array( 'thumbnail', $a_names, true ), 'S7  Taille encore enregistrée : jamais proposée' );
$legacy = array_values( array_filter( $a_items, static fn( $i ) => 'legacy_size' === $i['size_name'] ) )[0] ?? null;
bcamc_t_ok( $legacy && 1400 === (int) $legacy['bytes'], 'S7  Poids de la taille = fichier + variante WebP (' . ( $legacy['bytes'] ?? '?' ) . ' octets)' );

// Catégorie C.
$c_paths = array_map( static fn( $i ) => $i['rel_path'], bcamc_t_items( 'C' ) );
bcamc_t_ok( in_array( '2024/01/leftover-300x200.jpg', $c_paths, true ), 'S11 Fichier orphelin ancien : détecté' );
bcamc_t_ok( ! in_array( '2024/01/recent-leftover.jpg', $c_paths, true ), 'S11 Fichier orphelin récent : protégé par l\'âge' );
bcamc_t_ok( ! in_array( '2024/01/cited-leftover.jpg', $c_paths, true ), 'S11 Fichier hors médiathèque mais cité : conservé' );
bcamc_t_ok( ! in_array( '2024/01/index.php', $c_paths, true ), 'S11 index.php : jamais proposé' );
bcamc_t_ok( ! preg_grep( '#^elementor/#', $c_paths ), 'S11 Dossiers d\'extensions (elementor/) : jamais analysés' );
bcamc_t_ok( ! preg_grep( '#orphan-photo\.(jpg\.)?webp$#', $c_paths ), 'S9  Variantes WebP d\'un média : pas comptées comme orphelines' );
bcamc_t_ok( 1 === count( $c_paths ), 'S11 Exactement 1 fichier orphelin (' . implode( ', ', $c_paths ) . ')' );

// Rapport.
$summary = BCAMC_Report::summary();
bcamc_t_ok( $summary['uploads_bytes'] > 0 && isset( $summary['folders']['2024'], $summary['folders']['elementor'] ), 'Rapport : poids par dossier (2024, elementor)' );
bcamc_t_ok( $summary['recoverable'] === array_sum( array_column( $summary['categories'], 'bytes' ) ), 'Rapport : total récupérable = somme des catégories' );


// Champs média découverts.
$keys = BCAMC_Sources::media_field_keys();
bcamc_t_ok( ! array_diff( array( 'visuel', 'portrait', 'plan_acces', 'galerie_jet', 'visuel_cct', 'fond_entete' ), $keys ) && ! in_array( 'intro', $keys, true ), 'Champs média ACF et JetEngine découverts (' . implode( ', ', $keys ) . ')' );

// Export CSV.
$csv = fopen( 'php://memory', 'w+' );
$rows_written = BCAMC_Export::write( $csv, '', ';' );
rewind( $csv );
$csv_text = stream_get_contents( $csv );
fclose( $csv );
$csv_lines = array_values( array_filter( explode( "\n", $csv_text ) ) );
$csv_total = array_sum( array_column( BCAMC_Report::summary()['categories'], 'count' ) );
bcamc_t_ok( str_starts_with( $csv_text, "\xEF\xBB\xBF" ) && $rows_written === $csv_total && count( $csv_lines ) === $csv_total + 1, 'Export CSV : en-tête + ' . $csv_total . ' lignes, marque UTF-8' );
bcamc_t_ok( false !== strpos( $csv_text, '2024/01/truly-orphan.jpg' ) && 11 === count( str_getcsv( $csv_lines[1], ';', '"', '' ) ), 'Export CSV : chemins présents, 11 colonnes séparées par « ; »' );
$csv_b = fopen( 'php://memory', 'w+' );
bcamc_t_ok( BCAMC_Export::write( $csv_b, 'B' ) === BCAMC_Report::count( 'B' ), 'Export CSV d\'une seule catégorie' );
fclose( $csv_b );

// Documents exclus par défaut.
BCAMC_Scanner::run( array( 'include_documents' => false ) );
bcamc_t_ok( null === bcamc_t_is_b( $pdf2 ), 'Documents exclus par défaut : le PDF orphelin n\'est pas proposé' );
bcamc_t_ok( null !== bcamc_t_is_b( $orphan ), 'Documents exclus par défaut : les images restent analysées' );

// Reprise : scan découpé en très nombreux passages, résultat identique.
$reference = BCAMC_Report::summary()['categories'];
BCAMC_Scanner::start( array( 'include_documents' => false, 'batch_size' => 20 ) );
$passes = 0;
do {
	$p = BCAMC_Scanner::step( 0.0001 );
	++$passes;
} while ( 'running' === $p['status'] && $passes < 5000 );
$resumed = BCAMC_Report::summary()['categories'];
bcamc_t_ok( $passes > 10 && $resumed == $reference, 'Reprise : ' . $passes . ' passages de 20 éléments, résultat identique' );

// Filtre d'extension des sources.
add_filter(
	'bcamc_reference_sources',
	static function ( $sources ) {
		$sources['custom'] = array(
			'label' => 'Custom',
			'count' => static fn() => 1,
			'batch' => static fn( $cursor, $limit ) => array(
				'entries'   => array( array( 'source' => 'custom', 'source_id' => 0, 'text' => '/wp-content/uploads/2024/01/truly-orphan.jpg' ) ),
				'next'      => null,
				'processed' => 1,
			),
		);
		return $sources;
	}
);
BCAMC_Scanner::run( array( 'include_documents' => false ) );
bcamc_t_ok( null === bcamc_t_is_b( $orphan ), 'Filtre bcamc_reference_sources : une source ajoutée est prise en compte' );
remove_all_filters( 'bcamc_reference_sources' );

// Droits.
wp_set_current_user( 0 );
bcamc_t_ok( ! BCAMC_Settings::user_can(), 'Visiteur : pas d\'accès' );
$editor = wp_insert_user( array( 'user_login' => 'editor_' . wp_generate_password( 4, false ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
wp_set_current_user( $editor );
bcamc_t_ok( ! BCAMC_Settings::user_can(), 'Éditeur : pas d\'accès (manage_options requis)' );
wp_set_current_user( 1 );
bcamc_t_ok( BCAMC_Settings::user_can(), 'Administrateur : accès' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $editor );

$fails  = $GLOBALS['bcamc_t_fails'];
$checks = $GLOBALS['bcamc_t_checks'];
echo $fails ? "\n{$fails} ÉCHEC(S) sur {$checks} contrôles\n" : "\nTOUS LES TESTS PASSENT ({$checks} contrôles)\n";
