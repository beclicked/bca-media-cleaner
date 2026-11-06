<?php
/**
 * Désinstallation : supprime les tables et réglages de l'extension.
 *
 * Phase 1 : l'extension ne déplace ni ne supprime aucun fichier, il n'y a donc rien à restaurer.
 * La phase 2 (quarantaine) ajoutera ici la restauration automatique de tout ce qui est en quarantaine.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-db.php';

if ( is_multisite() ) {
	return;
}

BCAMC_DB::drop();

delete_option( 'bcamc_settings' );
delete_option( 'bcamc_scan' );
delete_site_transient( 'bcamc_release' );
