<?php
/**
 * Plugin Name:       BCA Media Cleaner
 * Plugin URI:        https://beclicked.agency
 * Description:       Find the media and files your site no longer uses, and see how much disk space you can recover. The analysis is read-only.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Be Clicked Agency
 * Author URI:        https://beclicked.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bca-media-cleaner
 * Domain Path:       /languages
 * Update URI:        https://github.com/beclicked/bca-media-cleaner
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

define( 'BCAMC_VERSION', '0.1.0' );
define( 'BCAMC_FILE', __FILE__ );
define( 'BCAMC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCAMC_URL', plugin_dir_url( __FILE__ ) );

// Dépôt GitHub utilisé pour les mises à jour (propriétaire/dépôt).
if ( ! defined( 'BCAMC_REPO' ) ) {
	define( 'BCAMC_REPO', 'beclicked/bca-media-cleaner' );
}

require_once BCAMC_DIR . 'includes/class-db.php';
require_once BCAMC_DIR . 'includes/class-settings.php';
require_once BCAMC_DIR . 'includes/class-updater.php';

register_activation_hook( __FILE__, array( 'BCAMC_DB', 'install' ) );

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'bca-media-cleaner', false, dirname( plugin_basename( BCAMC_FILE ) ) . '/languages' );
	}
);

// Les mises à jour restent actives dans tous les cas, multisite compris.
BCAMC_Updater::init();

/*
 * Multisite non pris en charge dans cette version : le module ne se charge pas,
 * un message l'explique dans l'administration du réseau et des sites.
 */
if ( is_multisite() ) {
	$bcamc_multisite_notice = static function () {
		if ( current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'BCA Media Cleaner does not support multisite networks yet. The plugin is inactive on this installation.', 'bca-media-cleaner' ) . '</p></div>';
		}
	};
	add_action( 'admin_notices', $bcamc_multisite_notice );
	add_action( 'network_admin_notices', $bcamc_multisite_notice );
	return;
}

require_once BCAMC_DIR . 'includes/class-extractor.php';
require_once BCAMC_DIR . 'includes/class-sources.php';
require_once BCAMC_DIR . 'includes/class-inventory.php';
require_once BCAMC_DIR . 'includes/class-scanner.php';
require_once BCAMC_DIR . 'includes/class-report.php';
require_once BCAMC_DIR . 'includes/class-admin.php';

add_action( 'plugins_loaded', array( 'BCAMC_DB', 'maybe_upgrade' ) );
BCAMC_Settings::init();
BCAMC_Admin::init();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BCAMC_DIR . 'includes/class-cli.php';
	WP_CLI::add_command( 'bca-media-cleaner', 'BCAMC_CLI' );
}
