<?php
/**
 * Backup downloads.
 *
 * @package Tiny_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds each kind of backup and streams it to the browser.
 */
class Tiny_Backup_Exporter {

	/** Folders in wp-content that only hold caches, or backups made by other plugins. */
	const DISPOSABLE_FOLDERS = array(
		'ai1wm-backups',
		'backuply',
		'backups-dup-lite',
		'backups-dup-pro',
		'cache',
		'et-cache',
		'updraft',
		'upgrade',
		'upgrade-temp-backup',
		'wpvividbackups',
	);

	/** File name label for each files backup type. */
	const FILE_LABELS = array(
		'content'      => 'wp-content-no-uploads',
		'content-full' => 'wp-content',
		'uploads'      => 'uploads',
	);

	/**
	 * @return string The wp-content folder.
	 */
	public static function content_dir() {
		return Tiny_Backup_Files::normalize( WP_CONTENT_DIR );
	}

	/**
	 * @return string The uploads folder. On multisite, the main site's, which holds every site's files.
	 */
	public static function uploads_dir() {
		$switched = is_multisite() && ! is_main_site() && switch_to_blog( get_main_site_id() );
		$uploads  = wp_upload_dir( null, false );
		if ( $switched ) {
			restore_current_blog();
		}
		return Tiny_Backup_Files::normalize( $uploads['basedir'] );
	}

	/**
	 * Path of the uploads folder inside the archives: under wp-content when
	 * it lives there, so the uploads-only zip lines up with the others.
	 *
	 * @return string
	 */
	public static function uploads_archive_path() {
		$content = self::content_dir();
		$uploads = self::uploads_dir();
		if ( 0 === strpos( $uploads, $content . '/' ) ) {
			return basename( $content ) . substr( $uploads, strlen( $content ) );
		}
		return basename( $uploads );
	}

	/**
	 * @return string[] Absolute paths of the disposable folders, whether they exist or not.
	 */
	public static function disposable_paths() {
		$content = self::content_dir();
		return array_map(
			function ( $folder ) use ( $content ) {
				return $content . '/' . $folder;
			},
			self::DISPOSABLE_FOLDERS
		);
	}

	/**
	 * Streams the database as .sql or .sql.gz and ends the request.
	 *
	 * @param bool $gzip Whether to compress the dump.
	 */
	public static function export_database( $gzip ) {
		global $wpdb;
		$database = new Tiny_Backup_Database( $wpdb );
		$tables   = $database->tables();
		if ( ! $tables ) {
			wp_die( esc_html__( 'No database tables were found.', 'tiny-backup' ) );
		}

		$gzip   = $gzip && function_exists( 'deflate_init' );
		$output = self::start_download(
			self::filename( 'database', $gzip ? 'sql.gz' : 'sql' ),
			$gzip ? 'application/gzip' : 'application/sql',
			$gzip
		);
		$database->dump( $output, $tables );
		$output->close();
		exit;
	}

	/**
	 * Streams a zip of wp-content and/or uploads and ends the request.
	 *
	 * @param string $type            'content' (without uploads), 'content-full' or 'uploads'.
	 * @param bool   $skip_disposable Leave out caches and other plugins' backups.
	 */
	public static function export_files( $type, $skip_disposable ) {
		$content      = self::content_dir();
		$uploads      = self::uploads_dir();
		$with_content = 'uploads' !== $type;
		$with_uploads = 'content' !== $type && is_dir( $uploads );
		if ( $with_content && ! is_readable( $content ) ) {
			wp_die( esc_html__( 'The wp-content folder could not be read.', 'tiny-backup' ) );
		}
		if ( 'uploads' === $type && ! $with_uploads ) {
			wp_die( esc_html__( 'There is no uploads folder yet.', 'tiny-backup' ) );
		}

		/**
		 * Filters the files and folders left out of a files backup.
		 *
		 * @param string[] $excluded Absolute paths.
		 * @param string   $type     'content' (without uploads), 'content-full' or 'uploads'.
		 */
		$excluded = (array) apply_filters( 'tiny_backup_excluded_paths', $skip_disposable ? self::disposable_paths() : array(), $type );

		// Uploads may live outside wp-content or be a symbolic link, so it is always walked on its own.
		$excluded[] = $uploads;

		$output = self::start_download( self::filename( self::FILE_LABELS[ $type ], 'zip' ), 'application/zip' );
		$zip    = new Tiny_Backup_Zip( $output, wp_timezone() );
		$files  = new Tiny_Backup_Files( $excluded );
		if ( $with_content ) {
			$files->add_to_zip( $zip, $content, basename( $content ) );
		}
		if ( $with_uploads ) {
			$files->add_to_zip( $zip, $uploads, self::uploads_archive_path() );
		}
		if ( $files->skipped() ) {
			$zip->add_from_string(
				'tiny-backup-skipped.txt',
				__( 'Tiny Backup left these out of the archive:', 'tiny-backup' ) . "\n\n" . implode( "\n", $files->skipped() ) . "\n",
				time()
			);
		}
		$zip->finish();
		$output->close();
		exit;
	}

	/**
	 * Measures the folders shown on the backup page, giving up after a while on huge sites.
	 *
	 * @return array Sizes as array{bytes: int, complete: bool}, under 'content' (without
	 *               uploads and disposable folders), 'uploads' and 'disposable' (one per folder found).
	 */
	public static function sizes() {
		$deadline   = microtime( true ) + 20;
		$uploads    = self::uploads_dir();
		$disposable = array_values( array_filter( self::disposable_paths(), 'is_dir' ) );

		$content = new Tiny_Backup_Files( array_merge( $disposable, array( $uploads ) ) );
		$sizes   = array(
			'content'    => $content->measure( self::content_dir(), $deadline ),
			'uploads'    => array(
				'bytes'    => 0,
				'complete' => true,
			),
			'disposable' => array(),
		);
		if ( is_dir( $uploads ) ) {
			$sizes['uploads'] = ( new Tiny_Backup_Files() )->measure( $uploads, $deadline );
		}
		foreach ( $disposable as $path ) {
			$sizes['disposable'][] = array( 'name' => basename( $path ) ) + ( new Tiny_Backup_Files() )->measure( $path, $deadline );
		}
		return $sizes;
	}

	/**
	 * Gets PHP ready for a long download and sends the headers.
	 *
	 * @param string $filename     Download file name.
	 * @param string $content_type MIME type.
	 * @param bool   $gzip         Whether the output is gzip-compressed.
	 * @return Tiny_Backup_Output
	 */
	private static function start_download( $filename, $content_type, $gzip = false ) {
		// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet
		@ini_set( 'display_errors', '0' ); // A notice printed now would end up inside the file.
		@ini_set( 'zlib.output_compression', 'Off' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		// phpcs:enable
		wp_raise_memory_limit( 'admin' );

		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break;
			}
		}

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Accel-Buffering: no' ); // Lets nginx pass the file along as it is built.

		return new Tiny_Backup_Output( fopen( 'php://output', 'wb' ), $gzip, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * @param string $label     What the backup holds.
	 * @param string $extension File extension.
	 * @return string Something like example.com-database-2026-10-15-103000.sql.gz.
	 */
	private static function filename( $label, $extension ) {
		$site = wp_parse_url( home_url(), PHP_URL_HOST ) . wp_parse_url( home_url(), PHP_URL_PATH );
		$site = trim( preg_replace( '/[^A-Za-z0-9._-]+/', '-', $site ), '-.' );
		return sprintf( '%s-%s-%s.%s', $site ? $site : 'wordpress', $label, wp_date( 'Y-m-d-His' ), $extension );
	}
}
