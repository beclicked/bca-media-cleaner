<?php
/**
 * Page d'administration : Médias > Nettoyage des médias.
 *
 * Phase 1 : analyse et rapport uniquement, aucune action sur les fichiers.
 *
 * @package BCA_Media_Cleaner
 */

defined( 'ABSPATH' ) || exit;

final class BCAMC_Admin {

	const SLUG     = 'bca-media-cleaner';
	const PER_PAGE = 50;

	private static string $hook = '';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_bcamc_scan', array( __CLASS__, 'ajax_scan' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BCAMC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'upload.php' ) );
	}

	public static function menu(): void {
		self::$hook = (string) add_media_page(
			__( 'Media cleanup', 'bca-media-cleaner' ),
			__( 'Media cleanup', 'bca-media-cleaner' ),
			BCAMC_Settings::capability(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( array $links ): array {
		if ( BCAMC_Settings::user_can() ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'bca-media-cleaner' ) . '</a>' );
		}
		return $links;
	}

	public static function assets( string $hook ): void {
		if ( $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 'bcamc-admin', BCAMC_URL . 'assets/admin.css', array(), BCAMC_VERSION );
		wp_enqueue_script( 'bcamc-admin', BCAMC_URL . 'assets/admin.js', array(), BCAMC_VERSION, true );
		wp_localize_script(
			'bcamc-admin',
			'BCAMC',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'bcamc_scan' ),
				'progress' => BCAMC_Scanner::progress(),
				'i18n'     => array(
					'starting'   => __( 'Starting the analysis...', 'bca-media-cleaner' ),
					'done'       => __( 'Analysis complete. Loading the report...', 'bca-media-cleaner' ),
					'error'      => __( 'The analysis was interrupted. Click "Resume" to continue where it stopped.', 'bca-media-cleaner' ),
					'confirmNew' => __( 'Start a new analysis? The current results will be replaced.', 'bca-media-cleaner' ),
					'leave'      => __( 'The analysis is running. If you leave, you can resume it later.', 'bca-media-cleaner' ),
				),
			)
		);
	}

	/* -------------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------------- */

	public static function ajax_scan(): void {
		check_ajax_referer( 'bcamc_scan', 'nonce' );
		if ( ! BCAMC_Settings::user_can() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'bca-media-cleaner' ) ), 403 );
		}

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'step';

		if ( 'start' === $op ) {
			BCAMC_Scanner::start();
		}

		wp_send_json_success( BCAMC_Scanner::step( 6.0 ) );
	}

	/* -------------------------------------------------------------------------
	 * Page
	 * ---------------------------------------------------------------------- */

	public static function render(): void {
		if ( ! BCAMC_Settings::user_can() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'bca-media-cleaner' ) );
		}

		$summary  = BCAMC_Report::summary();
		$progress = BCAMC_Scanner::progress();
		$has_scan = 'done' === $summary['scan']['status'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['tab'] ) ) ) : '';
		$tab = in_array( $tab, BCAMC_Report::CATEGORIES, true ) ? $tab : '';
		?>
		<div class="wrap bcamc">
			<h1 class="bcamc-title">
				<img src="<?php echo esc_url( BCAMC_URL . 'assets/icon.svg' ); ?>" alt="" width="36" height="36">
				<?php esc_html_e( 'Media cleanup', 'bca-media-cleaner' ); ?>
			</h1>
			<p class="bcamc-lead"><?php esc_html_e( 'Find the media and files your site no longer uses, and see how much disk space you can recover.', 'bca-media-cleaner' ); ?></p>

			<div class="bcamc-notice-readonly">
				<strong><?php esc_html_e( 'Read-only analysis.', 'bca-media-cleaner' ); ?></strong>
				<?php esc_html_e( 'This version only analyzes and reports. No file is moved or deleted.', 'bca-media-cleaner' ); ?>
			</div>

			<?php self::render_scan_box( $summary, $progress ); ?>

			<?php
			if ( $has_scan ) {
				if ( '' === $tab ) {
					self::render_summary( $summary );
				} else {
					self::render_list( $tab, $summary );
				}
			}
			?>

			<?php self::render_limits(); ?>
			<?php self::render_settings(); ?>
		</div>
		<?php
	}

	private static function render_scan_box( array $summary, array $progress ): void {
		$running = 'running' === $progress['status'];
		$done    = 'done' === $summary['scan']['status'];
		?>
		<div class="bcamc-card bcamc-scan" id="bcamc-scan">
			<div class="bcamc-scan-text">
				<?php if ( $done ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: date, 2: duration */
							esc_html__( 'Last analysis: %1$s (duration: %2$s).', 'bca-media-cleaner' ),
							esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $summary['scan']['finished_at'] ) ),
							esc_html( human_time_diff( $summary['scan']['started_at'], max( $summary['scan']['started_at'] + 1, $summary['scan']['finished_at'] ) ) )
						);
						?>
					</p>
				<?php elseif ( $running ) : ?>
					<p><?php esc_html_e( 'An analysis was started and has not finished yet. You can resume it.', 'bca-media-cleaner' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'The analysis reads your content, settings, style sheets and plugin data, then compares them with your media library and your uploads folder. It can take a few minutes on a large site. You can close the page and resume later.', 'bca-media-cleaner' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="bcamc-scan-actions">
				<?php if ( $running ) : ?>
					<button type="button" class="button button-primary" data-bcamc="resume"><?php esc_html_e( 'Resume the analysis', 'bca-media-cleaner' ); ?></button>
					<button type="button" class="button" data-bcamc="start" data-confirm="1"><?php esc_html_e( 'Start over', 'bca-media-cleaner' ); ?></button>
				<?php else : ?>
					<button type="button" class="button button-primary" data-bcamc="start" <?php echo $done ? 'data-confirm="1"' : ''; ?>>
						<?php $done ? esc_html_e( 'Run a new analysis', 'bca-media-cleaner' ) : esc_html_e( 'Run the analysis', 'bca-media-cleaner' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<div class="bcamc-progress" <?php echo $running ? '' : 'hidden'; ?>>
				<div class="bcamc-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $progress['percent'] ); ?>">
					<span style="width: <?php echo esc_attr( (string) $progress['percent'] ); ?>%"></span>
				</div>
				<p class="bcamc-progress-label" aria-live="polite"><?php echo esc_html( $progress['label'] ); ?></p>
			</div>
		</div>
		<?php
	}

	private static function render_summary( array $summary ): void {
		$uploads = max( 1, $summary['uploads_bytes'] );
		?>
		<div class="bcamc-total bcamc-card">
			<div>
				<span class="bcamc-total-label"><?php esc_html_e( 'Space you could recover', 'bca-media-cleaner' ); ?></span>
				<span class="bcamc-total-value"><?php echo esc_html( size_format( $summary['recoverable'], 1 ) ?: '0 B' ); ?></span>
			</div>
			<div class="bcamc-total-side">
				<?php
				printf(
					/* translators: 1: size of the uploads folder, 2: percentage */
					esc_html__( 'out of %1$s in the uploads folder (%2$s%%)', 'bca-media-cleaner' ),
					esc_html( size_format( $summary['uploads_bytes'], 1 ) ?: '0 B' ),
					esc_html( number_format_i18n( 100 * $summary['recoverable'] / $uploads, 1 ) )
				);
				?>
				<br>
				<?php
				printf(
					/* translators: %s: number of media */
					esc_html( _n( '%s media in the library', '%s media in the library', $summary['attachments'], 'bca-media-cleaner' ) ),
					esc_html( number_format_i18n( $summary['attachments'] ) )
				);
				?>
			</div>
		</div>

		<div class="bcamc-categories">
			<?php foreach ( $summary['categories'] as $category => $data ) : ?>
				<a class="bcamc-card bcamc-category" href="<?php echo esc_url( self::url( array( 'tab' => strtolower( $category ) ) ) ); ?>">
					<span class="bcamc-category-label"><?php echo esc_html( $data['label'] ); ?></span>
					<span class="bcamc-category-value"><?php echo esc_html( size_format( $data['bytes'], 1 ) ?: '0 B' ); ?></span>
					<span class="bcamc-category-count">
						<?php
						printf(
							/* translators: %s: number of items */
							esc_html( _n( '%s item', '%s items', $data['count'], 'bca-media-cleaner' ) ),
							esc_html( number_format_i18n( $data['count'] ) )
						);
						?>
					</span>
					<span class="bcamc-category-help"><?php echo esc_html( BCAMC_Report::category_help( $category ) ); ?></span>
					<span class="bcamc-category-link"><?php esc_html_e( 'See the list', 'bca-media-cleaner' ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<?php $top = BCAMC_Report::items( '', 20 ); ?>
		<?php if ( $top ) : ?>
			<div class="bcamc-card">
				<div class="bcamc-card-head">
					<h2><?php esc_html_e( 'The 20 heaviest items', 'bca-media-cleaner' ); ?></h2>
					<a class="button" href="<?php echo esc_url( BCAMC_Export::url() ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download everything (CSV)', 'bca-media-cleaner' ); ?></a>
				</div>
				<?php self::render_table( $top, true ); ?>
			</div>
		<?php endif; ?>

		<?php if ( $summary['folders'] ) : ?>
			<div class="bcamc-card">
				<h2><?php esc_html_e( 'Where the space goes', 'bca-media-cleaner' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Size of each folder inside the uploads folder. Folders other than the yearly ones belong to plugins (Elementor, forms, backups...): they are measured but never analyzed.', 'bca-media-cleaner' ); ?></p>
				<table class="widefat striped bcamc-folders">
					<thead><tr><th><?php esc_html_e( 'Folder', 'bca-media-cleaner' ); ?></th><th class="num"><?php esc_html_e( 'Size', 'bca-media-cleaner' ); ?></th><th class="bcamc-bar-col"></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $summary['folders'], 0, 15, true ) as $folder => $bytes ) : ?>
						<tr>
							<td><code><?php echo esc_html( '/' === $folder ? '/' : $folder . '/' ); ?></code></td>
							<td class="num"><?php echo esc_html( size_format( (int) $bytes, 1 ) ?: '0 B' ); ?></td>
							<td class="bcamc-bar-col"><span class="bcamc-bar" style="width: <?php echo esc_attr( (string) round( 100 * (int) $bytes / $uploads, 1 ) ); ?>%"></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
	}

	private static function render_list( string $category, array $summary ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$total = BCAMC_Report::count( $category );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$items = BCAMC_Report::items( $category, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Back to the report', 'bca-media-cleaner' ); ?></a></p>

		<nav class="nav-tab-wrapper bcamc-tabs">
			<?php foreach ( BCAMC_Report::CATEGORIES as $c ) : ?>
				<a class="nav-tab <?php echo $c === $category ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( array( 'tab' => strtolower( $c ) ) ) ); ?>">
					<?php echo esc_html( BCAMC_Report::category_label( $c ) ); ?>
					<span class="count">(<?php echo esc_html( number_format_i18n( $summary['categories'][ $c ]['count'] ) ); ?>)</span>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="bcamc-card">
			<p class="description"><?php echo esc_html( BCAMC_Report::category_help( $category ) ); ?></p>
			<div class="bcamc-card-head">
				<p class="bcamc-list-total">
					<?php
					printf(
						/* translators: 1: number of items, 2: total size */
						esc_html( _n( '%1$s item, %2$s in total.', '%1$s items, %2$s in total.', $total, 'bca-media-cleaner' ) ),
						esc_html( number_format_i18n( $total ) ),
						esc_html( size_format( $summary['categories'][ $category ]['bytes'], 1 ) ?: '0 B' )
					);
					?>
				</p>
				<?php if ( $total ) : ?>
					<a class="button" href="<?php echo esc_url( BCAMC_Export::url( $category ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download this list (CSV)', 'bca-media-cleaner' ); ?></a>
				<?php endif; ?>
			</div>

			<?php if ( $items ) : ?>
				<?php self::render_table( $items, false ); ?>
				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%', self::url( array( 'tab' => strtolower( $category ) ) ) ),
									'format'  => '',
									'current' => $paged,
									'total'   => $pages,
								)
							)
						);
						?>
					</div></div>
				<?php endif; ?>
			<?php else : ?>
				<p class="bcamc-empty"><?php esc_html_e( 'Nothing to report in this category.', 'bca-media-cleaner' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_table( array $items, bool $show_category ): void {
		$date_format = get_option( 'date_format' );
		?>
		<table class="widefat striped bcamc-table">
			<thead>
				<tr>
					<th class="bcamc-col-thumb"><span class="screen-reader-text"><?php esc_html_e( 'Preview', 'bca-media-cleaner' ); ?></span></th>
					<th><?php esc_html_e( 'File', 'bca-media-cleaner' ); ?></th>
					<?php if ( $show_category ) : ?>
						<th class="bcamc-col-optional"><?php esc_html_e( 'Category', 'bca-media-cleaner' ); ?></th>
					<?php endif; ?>
					<th class="num bcamc-col-size"><?php esc_html_e( 'Size on disk', 'bca-media-cleaner' ); ?></th>
					<th class="bcamc-col-optional"><?php esc_html_e( 'Added', 'bca-media-cleaner' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $items as $item ) : ?>
				<?php $view = BCAMC_Report::present( $item ); ?>
				<tr>
					<td class="bcamc-col-thumb">
						<?php if ( $view['thumb'] ) : ?>
							<img src="<?php echo esc_url( $view['thumb'] ); ?>" alt="" loading="lazy" width="56" height="56">
						<?php else : ?>
							<span class="bcamc-file-icon dashicons dashicons-media-default" aria-hidden="true"></span>
						<?php endif; ?>
					</td>
					<td>
						<strong>
							<?php if ( $view['link'] ) : ?>
								<a href="<?php echo esc_url( $view['link'] ); ?>"><?php echo esc_html( $view['name'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $view['name'] ); ?>
							<?php endif; ?>
						</strong>
						<br><code class="bcamc-path"><?php echo esc_html( $view['path'] ); ?></code>
						<?php if ( '' !== $view['size'] ) : ?>
							<br><span class="description">
								<?php
								/* translators: %s: name of the image size */
								printf( esc_html__( 'Size "%s", no longer generated', 'bca-media-cleaner' ), esc_html( $view['size'] ) );
								?>
							</span>
						<?php endif; ?>
						<?php if ( count( $view['files'] ) > 1 ) : ?>
							<br><span class="description">
								<?php
								/* translators: %s: number of files */
								printf( esc_html( _n( '%s file in total (original, sizes and variants)', '%s files in total (original, sizes and variants)', count( $view['files'] ), 'bca-media-cleaner' ) ), esc_html( number_format_i18n( count( $view['files'] ) ) ) );
								?>
							</span>
						<?php endif; ?>
					</td>
					<?php if ( $show_category ) : ?>
						<td class="bcamc-col-optional"><?php echo esc_html( BCAMC_Report::category_label( (string) $item['category'] ) ); ?></td>
					<?php endif; ?>
					<td class="num bcamc-col-size"><?php echo esc_html( size_format( (int) $item['bytes'], 1 ) ?: '0 B' ); ?></td>
					<td class="bcamc-col-optional"><?php echo $item['added_at'] ? esc_html( wp_date( $date_format, strtotime( $item['added_at'] . ' UTC' ) ) ) : ''; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_limits(): void {
		?>
		<div class="bcamc-card bcamc-limits">
			<h2><?php esc_html_e( 'What the analysis cannot see', 'bca-media-cleaner' ); ?></h2>
			<p><?php esc_html_e( 'A file listed here may still be used somewhere the analysis cannot read:', 'bca-media-cleaner' ); ?></p>
			<ul>
				<li><?php esc_html_e( 'emails already sent, which link to a file on your site;', 'bca-media-cleaner' ); ?></li>
				<li><?php esc_html_e( 'social media posts and other websites that point to one of your files;', 'bca-media-cleaner' ); ?></li>
				<li><?php esc_html_e( 'SureCart products, which are stored outside WordPress;', 'bca-media-cleaner' ); ?></li>
				<li><?php esc_html_e( 'addresses written directly in your theme files;', 'bca-media-cleaner' ); ?></li>
				<li><?php esc_html_e( 'media stored on an external service or CDN storage.', 'bca-media-cleaner' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'This is why a future version will place files in quarantine for several weeks before any deletion: a forgotten use shows up as a missing image, and the file is restored in one click.', 'bca-media-cleaner' ); ?></p>
		</div>
		<?php
	}

	private static function render_settings(): void {
		$settings = BCAMC_Settings::get();
		?>
		<details class="bcamc-card bcamc-settings">
			<summary><?php esc_html_e( 'Settings', 'bca-media-cleaner' ); ?></summary>
			<form method="post" action="options.php">
				<?php settings_fields( 'bcamc_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bcamc-min-age"><?php esc_html_e( 'Ignore media added in the last', 'bca-media-cleaner' ); ?></label></th>
						<td>
							<input type="number" min="0" max="3650" id="bcamc-min-age" name="<?php echo esc_attr( BCAMC_Settings::OPTION ); ?>[min_age_days]" value="<?php echo esc_attr( (string) $settings['min_age_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'bca-media-cleaner' ); ?>
							<p class="description"><?php esc_html_e( 'A file uploaded recently may simply not be inserted in a page yet.', 'bca-media-cleaner' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Documents', 'bca-media-cleaner' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( BCAMC_Settings::OPTION ); ?>[include_documents]" value="1" <?php checked( $settings['include_documents'] ); ?>> <?php esc_html_e( 'Also analyze documents (PDF, audio, office files...)', 'bca-media-cleaner' ); ?></label>
							<p class="description"><?php esc_html_e( 'Off by default: documents are often shared outside the site (emails, social media), where the analysis cannot see them.', 'bca-media-cleaner' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bcamc-batch"><?php esc_html_e( 'Batch size', 'bca-media-cleaner' ); ?></label></th>
						<td>
							<input type="number" min="20" max="2000" id="bcamc-batch" name="<?php echo esc_attr( BCAMC_Settings::OPTION ); ?>[batch_size]" value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Number of items read at a time. Lower it if the analysis stops on a modest server.', 'bca-media-cleaner' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'Changes apply to the next analysis.', 'bca-media-cleaner' ); ?></p>
				<?php submit_button(); ?>
			</form>
		</details>
		<?php
	}
}
