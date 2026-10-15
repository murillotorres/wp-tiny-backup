<?php
/**
 * Backup page under Tools.
 *
 * @package Tiny_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page, download requests and the size estimate.
 */
class Tiny_Backup_Admin {

	const PAGE = 'tiny-backup';

	/**
	 * Hooks into WordPress.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_tiny_backup_download', array( __CLASS__, 'download' ) );
		add_action( 'wp_ajax_tiny_backup_sizes', array( __CLASS__, 'ajax_sizes' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TINY_BACKUP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * A backup holds every user's password hash and every file, so only
	 * administrators (super admins, on multisite) can download one.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability needed to download backups.
		 *
		 * @param string $capability Capability name.
		 */
		return apply_filters( 'tiny_backup_capability', is_multisite() ? 'manage_network_options' : 'manage_options' );
	}

	/**
	 * Adds Tools → Tiny Backup.
	 */
	public static function add_page() {
		$hook = add_management_page(
			__( 'Tiny Backup', 'tiny-backup' ),
			__( 'Tiny Backup', 'tiny-backup' ),
			self::capability(),
			self::PAGE,
			array( __CLASS__, 'render' )
		);
		if ( $hook ) {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		}
	}

	/**
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'tools_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'tiny-backup', plugins_url( 'assets/admin.css', TINY_BACKUP_FILE ), array(), TINY_BACKUP_VERSION );
		wp_enqueue_script( 'tiny-backup', plugins_url( 'assets/admin.js', TINY_BACKUP_FILE ), array(), TINY_BACKUP_VERSION, true );
		wp_localize_script(
			'tiny-backup',
			'tinyBackup',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'tiny_backup_sizes' ),
				'i18n'    => array(
					/* translators: %s: a size, like 4.2 GB. */
					'moreThan'    => __( 'more than %s', 'tiny-backup' ),
					'unavailable' => __( 'size unavailable', 'tiny-backup' ),
					/* translators: %s: list of folders with their sizes. */
					'found'       => __( 'Found here: %s.', 'tiny-backup' ),
					'nothing'     => __( 'No cache or backup folders on this site.', 'tiny-backup' ),
					'preparing'   => __( 'Your download will start in a moment…', 'tiny-backup' ),
				),
			)
		);
	}

	/**
	 * @param string[] $links Links under the plugin name on the Plugins screen.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		if ( current_user_can( self::capability() ) ) {
			array_unshift(
				$links,
				sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ), esc_html__( 'Backup', 'tiny-backup' ) )
			);
		}
		return $links;
	}

	/**
	 * Handles the download forms (admin-post.php).
	 */
	public static function download() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to download backups.', 'tiny-backup' ), 403 );
		}
		check_admin_referer( 'tiny_backup_download' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( 'database' === $type ) {
			Tiny_Backup_Exporter::export_database( ! empty( $_POST['gzip'] ) );
		}
		if ( 'content' === $type ) {
			Tiny_Backup_Exporter::export_files( empty( $_POST['uploads'] ) ? 'content' : 'content-full', ! empty( $_POST['skip_disposable'] ) );
		}
		if ( 'uploads' === $type ) {
			Tiny_Backup_Exporter::export_files( 'uploads', false );
		}
		// phpcs:enable

		wp_die( esc_html__( 'Unknown backup type.', 'tiny-backup' ), 400 );
	}

	/**
	 * Sends the folder sizes shown on the page.
	 */
	public static function ajax_sizes() {
		check_ajax_referer( 'tiny_backup_sizes' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( Tiny_Backup_Exporter::sizes() );
	}

	/**
	 * Renders the backup page.
	 */
	public static function render() {
		global $wpdb;
		$tables   = ( new Tiny_Backup_Database( $wpdb ) )->tables();
		$db_bytes = array_sum( wp_list_pluck( $tables, 'bytes' ) );
		$content  = basename( Tiny_Backup_Exporter::content_dir() );
		$uploads  = Tiny_Backup_Exporter::uploads_archive_path();
		$loading  = '<span class="spinner is-active"></span>';
		?>
		<div class="wrap tiny-backup">
			<h1><?php esc_html_e( 'Tiny Backup', 'tiny-backup' ); ?></h1>
			<p class="tiny-backup-intro">
				<?php esc_html_e( 'Each backup is built while it downloads and goes straight to your computer, so nothing is left on the server. Big sites can take a few minutes: keep this tab open until the download finishes.', 'tiny-backup' ); ?>
			</p>
			<?php self::time_limit_notice(); ?>

			<div class="tiny-backup-cards">
				<?php self::open_form( 'database' ); ?>
					<h2><?php esc_html_e( 'Database', 'tiny-backup' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s: table prefix, like wp_. */
							esc_html__( 'Every table starting with %s, as a SQL file you can import with phpMyAdmin, Adminer or WP-CLI.', 'tiny-backup' ),
							'<code>' . esc_html( $wpdb->base_prefix ) . '</code>'
						);
						?>
					</p>
					<p class="tiny-backup-size">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of tables, 2: their size, like 45 MB. */
								_n( '%1$s table, %2$s', '%1$s tables, %2$s', count( $tables ), 'tiny-backup' ),
								number_format_i18n( count( $tables ) ),
								size_format( $db_bytes, 1 )
							)
						);
						?>
					</p>
					<?php if ( function_exists( 'deflate_init' ) ) : ?>
						<label class="tiny-backup-option">
							<input type="checkbox" name="gzip" value="1" checked>
							<?php esc_html_e( 'Compress (.sql.gz)', 'tiny-backup' ); ?>
						</label>
					<?php endif; ?>
				<?php self::close_form( __( 'Download database', 'tiny-backup' ) ); ?>

				<?php self::open_form( 'content' ); ?>
					<h2><?php echo esc_html( $content ); ?></h2>
					<p><?php esc_html_e( 'Themes, plugins, translations and everything else your site keeps in this folder.', 'tiny-backup' ); ?></p>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Uploads', 'tiny-backup' ); ?></legend>
						<label class="tiny-backup-option">
							<input type="radio" name="uploads" value="0" checked>
							<?php esc_html_e( 'Without uploads', 'tiny-backup' ); ?>
							<span class="tiny-backup-size" data-tiny-backup-size="content"><?php echo $loading; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</label>
						<label class="tiny-backup-option">
							<input type="radio" name="uploads" value="1">
							<?php esc_html_e( 'With uploads', 'tiny-backup' ); ?>
							<span class="tiny-backup-size" data-tiny-backup-size="content-full"><?php echo $loading; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						</label>
					</fieldset>
					<label class="tiny-backup-option">
						<input type="checkbox" name="skip_disposable" value="1" checked>
						<?php esc_html_e( 'Skip caches and backups made by other plugins', 'tiny-backup' ); ?>
					</label>
					<p class="description" data-tiny-backup-disposable></p>
				<?php
				/* translators: %s: folder name, usually wp-content. */
				self::close_form( sprintf( __( 'Download %s', 'tiny-backup' ), $content ) );
				?>

				<?php self::open_form( 'uploads' ); ?>
					<h2><?php esc_html_e( 'Uploads', 'tiny-backup' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s: path of the uploads folder, like wp-content/uploads. */
							esc_html__( 'Only the media library (%s), usually the biggest part of a site.', 'tiny-backup' ),
							'<code>' . esc_html( $uploads ) . '</code>'
						);
						?>
					</p>
					<p class="tiny-backup-size" data-tiny-backup-size="uploads"><?php echo $loading; // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
				<?php self::close_form( __( 'Download uploads', 'tiny-backup' ) ); ?>
			</div>

			<div class="tiny-backup-restore">
				<h2><?php esc_html_e( 'Restoring', 'tiny-backup' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: the wp db import command. */
						esc_html__( 'Database: import the .sql or .sql.gz file with phpMyAdmin, Adminer or %s. It replaces the tables with the same names.', 'tiny-backup' ),
						'<code>wp db import</code>'
					);
					?>
				</p>
				<p>
					<?php
					printf(
						/* translators: 1: folder name, usually wp-content, 2: wp-config.php. */
						esc_html__( 'Files: each zip holds the %1$s folder itself. Extract it in the WordPress folder (the one with %2$s), replacing what is there.', 'tiny-backup' ),
						'<code>' . esc_html( $content ) . '</code>',
						'<code>wp-config.php</code>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * @param string $type Backup type posted with the form.
	 */
	private static function open_form( $type ) {
		printf( '<form class="card tiny-backup-card" method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="tiny_backup_download">';
		printf( '<input type="hidden" name="type" value="%s">', esc_attr( $type ) );
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( 'tiny_backup_download' ) ) );
	}

	/**
	 * @param string $label Button text.
	 */
	private static function close_form( $label ) {
		printf(
			'<p class="tiny-backup-actions"><button type="submit" class="button button-primary">%s</button><span class="tiny-backup-status" aria-live="polite"></span></p></form>',
			esc_html( $label )
		);
	}

	/**
	 * Warns when the server cuts PHP off after a fixed time and won't let the plugin lift it.
	 */
	private static function time_limit_notice() {
		$limit    = (int) ini_get( 'max_execution_time' );
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( $limit <= 0 || ( function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true ) ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of seconds. */
					__( 'This server stops PHP after %s seconds and does not let plugins change that, so very big downloads may be cut short. If that happens, download wp-content and uploads separately.', 'tiny-backup' ),
					number_format_i18n( $limit )
				)
			)
		);
	}
}
