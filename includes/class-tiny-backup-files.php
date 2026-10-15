<?php
/**
 * Folder walking.
 *
 * @package Tiny_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Walks folders for an archive or a size estimate, leaving out excluded
 * paths and keeping track of anything that could not be read.
 */
class Tiny_Backup_Files {

	/**
	 * Normalized absolute paths to leave out, as keys.
	 *
	 * @var array<string, true>
	 */
	private $excluded = array();

	/**
	 * Paths left out because they could not be read, with the reason.
	 *
	 * @var string[]
	 */
	private $skipped = array();

	/**
	 * @param string[] $excluded_paths Absolute paths of files or folders to leave out.
	 */
	public function __construct( array $excluded_paths = array() ) {
		foreach ( $excluded_paths as $path ) {
			$this->excluded[ self::normalize( $path ) ] = true;
		}
	}

	/**
	 * @param string $path Filesystem path.
	 * @return string Path with forward slashes and no trailing slash.
	 */
	public static function normalize( $path ) {
		return untrailingslashit( wp_normalize_path( $path ) );
	}

	/**
	 * Adds a folder and everything below it to an archive.
	 *
	 * @param Tiny_Backup_Zip $zip    Archive being written.
	 * @param string          $root   Folder on disk.
	 * @param string          $prefix Path of that folder inside the archive.
	 */
	public function add_to_zip( Tiny_Backup_Zip $zip, $root, $prefix ) {
		$zip->add_directory( $prefix, (int) @filemtime( $root ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		foreach ( $this->walk( $root ) as $relative => $info ) {
			try {
				if ( $info->isDir() ) {
					$zip->add_directory( $prefix . '/' . $relative, $info->getMTime() );
				} elseif ( $info->isFile() && ! $zip->add_file( $info->getPathname(), $prefix . '/' . $relative ) ) {
					$this->skip( $info->getPathname(), __( 'could not be read', 'tiny-backup' ) );
				}
			} catch ( RuntimeException $e ) {
				// Removed or changed while the backup was running.
				$this->skip( $info->getPathname(), __( 'could not be read', 'tiny-backup' ) );
			}
		}
	}

	/**
	 * Adds up the size of the files below a folder, stopping at a deadline.
	 *
	 * @param string $root     Folder on disk.
	 * @param float  $deadline Value of microtime( true ) to stop at.
	 * @return array{bytes: int, complete: bool}
	 */
	public function measure( $root, $deadline ) {
		$bytes = 0;
		$count = 0;
		foreach ( $this->walk( $root ) as $info ) {
			if ( 0 === ++$count % 1000 && microtime( true ) > $deadline ) {
				return array(
					'bytes'    => $bytes,
					'complete' => false,
				);
			}
			try {
				if ( $info->isFile() ) {
					$bytes += $info->getSize();
				}
			} catch ( RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Removed while counting.
			}
		}
		return array(
			'bytes'    => $bytes,
			'complete' => true,
		);
	}

	/**
	 * @return string[] Paths left out because they could not be read, with the reason.
	 */
	public function skipped() {
		return $this->skipped;
	}

	/**
	 * Yields everything below a folder as relative path => SplFileInfo, each
	 * folder before its contents. Symbolic links to folders are not followed.
	 *
	 * @param string $root Folder on disk.
	 * @return Generator
	 */
	private function walk( $root ) {
		$root = self::normalize( $root );
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
					array( $this, 'accept' )
				),
				RecursiveIteratorIterator::SELF_FIRST,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
		} catch ( UnexpectedValueException $e ) {
			$this->skip( $root, __( 'could not be read', 'tiny-backup' ) );
			return;
		}

		$offset = strlen( $root ) + 1;
		foreach ( $iterator as $path => $info ) {
			yield substr( $path, $offset ) => $info;
		}
	}

	/**
	 * Filter callback for walk(). Public only because SPL needs to call it.
	 *
	 * @param SplFileInfo $info Current entry.
	 * @param string      $path Its full path.
	 * @return bool Whether to include the entry (and, for folders, look inside).
	 */
	public function accept( SplFileInfo $info, $path ) {
		if ( isset( $this->excluded[ $path ] ) ) {
			return false;
		}
		if ( $info->isDir() ) {
			if ( $info->isLink() ) {
				$this->skip( $path, __( 'symbolic link to a folder, not followed', 'tiny-backup' ) );
				return false;
			}
			if ( ! $info->isReadable() || ! $info->isExecutable() ) {
				$this->skip( $path, __( 'could not be read', 'tiny-backup' ) );
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $path   Full path.
	 * @param string $reason Why it was left out.
	 */
	private function skip( $path, $reason ) {
		$this->skipped[] = $path . ' (' . $reason . ')';
	}
}
