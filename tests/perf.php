<?php
/**
 * Test de charge : génère un site de 20 000 médias (environ 120 000 fichiers),
 * 4 000 contenus et 1 000 pages Elementor, puis mesure le scan.
 *
 * Site de test uniquement :
 *   BCAMC_TESTS=1 wp eval-file wp-content/plugins/bca-media-cleaner/tests/perf.php
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

if ( ! getenv( 'BCAMC_TESTS' ) ) {
	WP_CLI::error( 'Tests destinés à un site de test uniquement. Relancez avec BCAMC_TESTS=1.' );
}

global $wpdb;

$media  = (int) ( getenv( 'BCAMC_PERF_MEDIA' ) ?: 20000 );
$posts  = (int) ( $media / 5 );
$base   = wp_get_upload_dir()['basedir'];
$url    = rtrim( wp_get_upload_dir()['baseurl'], '/' ) . '/';
$old    = strtotime( '2024-01-01' );
$sizes  = array( 'thumbnail' => '150x150', 'medium' => '300x200', 'large' => '1024x683', 'legacy_theme' => '1200x400' );
$t0     = microtime( true );

// Nettoyage.
$wpdb->query( "DELETE FROM {$wpdb->posts}" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta}" );
if ( is_dir( $base . '/2024' ) ) {
	exec( 'rm -rf ' . escapeshellarg( $base . '/2024' ) );
}

// 1. Médias et fichiers.
$wpdb->query( 'START TRANSACTION' );
$id     = 1000;
$rows   = array();
$metas  = array();
$months = 12;
for ( $mm = 1; $mm <= $months; $mm++ ) {
	wp_mkdir_p( $base . '/2024/' . sprintf( '%02d', $mm ) );
}
for ( $i = 0; $i < $media; $i++ ) {
	++$id;
	$month = sprintf( '%02d', ( $i % $months ) + 1 );
	$dir   = '2024/' . $month;
	$name  = 'img-' . $i;
	$rel   = $dir . '/' . $name . '.jpg';

	file_put_contents( $base . '/' . $rel, str_repeat( 'x', 2000 ) );
	$meta_sizes = array();
	foreach ( $sizes as $size => $dims ) {
		$file = $name . '-' . $dims . '.jpg';
		file_put_contents( $base . '/' . $dir . '/' . $file, str_repeat( 'x', 300 ) );
		$meta_sizes[ $size ] = array( 'file' => $file, 'width' => 1, 'height' => 1, 'mime-type' => 'image/jpeg' );
	}
	if ( 0 === $i % 3 ) {
		file_put_contents( $base . '/' . $rel . '.webp', str_repeat( 'x', 800 ) );
	}

	$rows[]  = $wpdb->prepare( '(%d,1,%s,%s,%s,%s,%s,%s,%s,%s)', $id, '2024-01-15 10:00:00', '2024-01-15 10:00:00', '', $name, 'inherit', $name, 'attachment', 'image/jpeg' );
	$metas[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_wp_attached_file', $rel );
	$metas[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_wp_attachment_metadata', serialize( array( 'file' => $rel, 'width' => 1200, 'height' => 800, 'sizes' => $meta_sizes ) ) );

	if ( count( $rows ) >= 500 ) {
		$wpdb->query( "INSERT INTO {$wpdb->posts} (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_name, post_type, post_mime_type) VALUES " . implode( ',', $rows ) );
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $metas ) );
		$rows  = array();
		$metas = array();
	}
}
if ( $rows ) {
	$wpdb->query( "INSERT INTO {$wpdb->posts} (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_name, post_type, post_mime_type) VALUES " . implode( ',', $rows ) );
	$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $metas ) );
}

// 2. Contenus : chaque contenu cite 3 médias (un média sur deux est utilisé au total).
$rows  = array();
$metas = array();
for ( $p = 0; $p < $posts; $p++ ) {
	++$id;
	$content = '';
	for ( $k = 0; $k < 3; $k++ ) {
		$n = ( $p * 3 + $k ) * 2 % $media;
		$m = sprintf( '%02d', ( $n % $months ) + 1 );
		$content .= '<!-- wp:image {"id":' . ( 1001 + $n ) . '} --><figure><img src="' . $url . '2024/' . $m . '/img-' . $n . '-1024x683.jpg" class="wp-image-' . ( 1001 + $n ) . '"></figure><!-- /wp:image -->' . str_repeat( '<p>Lorem ipsum dolor sit amet.</p>', 20 );
	}
	$rows[] = $wpdb->prepare( '(%d,1,%s,%s,%s,%s,%s,%s,%s,%s)', $id, '2024-02-01 10:00:00', '2024-02-01 10:00:00', $content, 'Post ' . $p, 'publish', 'post-' . $p, 'post', '' );

	// Une page sur quatre est construite avec Elementor (JSON volumineux).
	if ( 0 === $p % 4 ) {
		$n    = ( $p * 2 + 1 ) % $media;
		$m    = sprintf( '%02d', ( $n % $months ) + 1 );
		$json = wp_json_encode( array_fill( 0, 30, array( 'id' => 'abc1234', 'elType' => 'widget', 'settings' => array( 'image' => array( 'url' => $url . '2024/' . $m . '/img-' . $n . '.jpg', 'id' => 1001 + $n ), 'text' => str_repeat( 'Lorem ipsum ', 30 ) ) ) ) );
		$metas[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_elementor_data', $json );
	}
	$metas[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_edit_last', '1' );
	$metas[] = $wpdb->prepare( '(%d,%s,%s)', $id, '_seopress_titles_title', 'Titre SEO ' . $p );

	if ( count( $rows ) >= 200 ) {
		$wpdb->query( "INSERT INTO {$wpdb->posts} (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_name, post_type, post_mime_type) VALUES " . implode( ',', $rows ) );
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $metas ) );
		$rows  = array();
		$metas = array();
	}
}
if ( $rows ) {
	$wpdb->query( "INSERT INTO {$wpdb->posts} (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_name, post_type, post_mime_type) VALUES " . implode( ',', $rows ) );
	$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $metas ) );
}
$wpdb->query( 'COMMIT' );

// 3. Fichiers orphelins (restes d'une migration).
for ( $i = 0; $i < 500; $i++ ) {
	$path = $base . '/2024/' . sprintf( '%02d', ( $i % $months ) + 1 ) . '/old-' . $i . '-768x512.jpg';
	file_put_contents( $path, str_repeat( 'x', 500 ) );
	touch( $path, $old );
}
// Les fichiers des médias datent eux aussi d'avant la limite d'âge.
exec( 'find ' . escapeshellarg( $base . '/2024' ) . ' -type f -exec touch -d 2024-01-01 {} +' );

printf( "Site généré en %.1f s : %d médias, %d contenus, %s fichiers\n", microtime( true ) - $t0, $media, $posts, trim( (string) shell_exec( 'find ' . escapeshellarg( $base . '/2024' ) . ' -type f | wc -l' ) ) );

// 4. Scan par passages de 6 secondes, comme depuis l'interface.
add_filter( 'intermediate_image_sizes', static fn( $s ) => array_values( array_diff( $s, array( 'legacy_theme' ) ) ) );
wp_cache_flush();
// Limite de mémoire réaliste d'un hébergement (WP-CLI la lève par défaut).
ini_set( 'memory_limit', getenv( 'BCAMC_PERF_MEMORY' ) ?: '256M' );
$t1      = microtime( true );
$longest = 0.0;
$passes  = 0;
BCAMC_Scanner::start( array( 'batch_size' => 200, 'include_documents' => false ) );
do {
	$s = microtime( true );
	$p = BCAMC_Scanner::step( 6.0 );
	$longest = max( $longest, microtime( true ) - $s );
	++$passes;
} while ( 'running' === $p['status'] );

$summary = BCAMC_Report::summary();
printf(
	"Scan : %.1f s en %d passages, passage le plus long %.1f s, mémoire max %s (limite %s)\n",
	microtime( true ) - $t1,
	$passes,
	$longest,
	size_format( memory_get_peak_usage( true ) ),
	ini_get( 'memory_limit' )
);
foreach ( $summary['categories'] as $c => $data ) {
	printf( "  %s %-20s %6d éléments  %s\n", $c, $data['label'], $data['count'], size_format( $data['bytes'], 1 ) );
}
printf( "  Total récupérable : %s sur %s\n", size_format( $summary['recoverable'], 1 ), size_format( $summary['uploads_bytes'], 1 ) );

// Contrôles de cohérence : un média sur deux est cité, les autres sont inutilisés.
$expected_b = $media - count( array_unique( array_merge(
	array_map( static fn( $q ) => ( $q * 2 ) % $media, range( 0, $posts * 3 - 1 ) ),
	array_map( static fn( $q ) => ( $q * 2 + 1 ) % $media, range( 0, $posts - 1, 4 ) )
) ) );
printf( "Contrôle B : %d trouvés, %d attendus => %s\n", $summary['categories']['B']['count'], $expected_b, $summary['categories']['B']['count'] === $expected_b ? 'OK' : 'ÉCART' );
printf( "Contrôle C : %d trouvés, 500 attendus => %s\n", $summary['categories']['C']['count'], 500 === $summary['categories']['C']['count'] ? 'OK' : 'ÉCART' );
printf( "Contrôle A : %d trouvés, %d attendus (médias utilisés, taille legacy_theme) => %s\n", $summary['categories']['A']['count'], $media - $expected_b, $summary['categories']['A']['count'] === $media - $expected_b ? 'OK' : 'ÉCART' );
