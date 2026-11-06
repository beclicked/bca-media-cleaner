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
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fc_campaigns" );
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
