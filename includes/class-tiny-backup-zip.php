<?php
/**
 * Streaming ZIP writer.
 *
 * @package Tiny_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes a ZIP archive front to back, with no temporary files.
 *
 * - Files up to MEMORY_LIMIT are read into memory and deflated when that helps.
 * - Bigger files that compress well (logs, SQL, text) are deflated as they are
 *   read, with their CRC and sizes in a data descriptor after the data.
 * - Bigger files that don't (images, video, archives) are stored as-is, after
 *   a first pass that computes their CRC so the header can carry it.
 *
 * ZIP64 records are written only when an entry, an offset or the entry count
 * goes past the limits of the classic format.
 */
class Tiny_Backup_Zip {

	/** Files up to this size are read into memory. */
	const MEMORY_LIMIT = 8388608;

	/** Above this size, files are always stored, so streamed entries never need ZIP64. */
	const DEFLATE_LIMIT = 2147483648;

	const READ_CHUNK = 1048576;

	const MAX_16 = 0xFFFF;

	const MAX_32 = 0xFFFFFFFF;

	/** Version made by: Unix, ZIP spec 4.5. */
	const MADE_BY = 0x032D;

	/** General purpose flag: CRC and sizes follow the data in a data descriptor. */
	const FLAG_DESCRIPTOR = 0x0008;

	/** General purpose flag: the name is UTF-8. */
	const FLAG_UTF8 = 0x0800;

	/** Formats that are already compressed and gain nothing from deflate. */
	const STORED_EXTENSIONS = array(
		'7z', 'aac', 'avi', 'avif', 'br', 'bz2', 'docx', 'eot', 'flac', 'gif', 'gz', 'heic', 'jar', 'jpeg', 'jpg',
		'm4a', 'm4v', 'mkv', 'mov', 'mp3', 'mp4', 'mpeg', 'mpg', 'odt', 'ogg', 'ogv', 'opus', 'pdf', 'png', 'pptx',
		'rar', 'tgz', 'webm', 'webp', 'woff', 'woff2', 'xlsx', 'xz', 'zip', 'zst',
	);

	/**
	 * @var Tiny_Backup_Output
	 */
	private $output;

	/**
	 * Timezone for the DOS timestamps (the exact UTC time is stored too).
	 *
	 * @var DateTimeZone
	 */
	private $timezone;

	/**
	 * Bytes written so far.
	 *
	 * @var int
	 */
	private $offset = 0;

	/**
	 * Central directory records, kept aside until finish().
	 *
	 * @var resource
	 */
	private $central;

	/**
	 * @var int
	 */
	private $central_size = 0;

	/**
	 * @var int
	 */
	private $count = 0;

	/**
	 * @param Tiny_Backup_Output $output   Where the archive goes.
	 * @param DateTimeZone|null  $timezone Timezone for entry dates. Defaults to PHP's.
	 */
	public function __construct( Tiny_Backup_Output $output, ?DateTimeZone $timezone = null ) {
		$this->output   = $output;
		$this->timezone = $timezone ? $timezone : new DateTimeZone( date_default_timezone_get() );
		$this->central  = fopen( 'php://temp/maxmemory:8388608', 'w+b' );
	}

	/**
	 * @param string $name  Path inside the archive.
	 * @param int    $mtime Unix timestamp.
	 */
	public function add_directory( $name, $mtime ) {
		$entry = $this->write_local_header( rtrim( $name, '/' ) . '/', 0, 0, 0, 0, $mtime );
		$this->add_central_record( $entry, 0, 0, 0, true );
	}

	/**
	 * @param string $name     Path inside the archive.
	 * @param string $data     File contents.
	 * @param int    $mtime    Unix timestamp.
	 * @param bool   $compress Whether to try deflating the data.
	 */
	public function add_from_string( $name, $data, $mtime, $compress = true ) {
		$size   = strlen( $data );
		$crc    = crc32( $data );
		$method = 0;
		if ( $compress && $size > 0 ) {
			$deflated = gzdeflate( $data, 6 );
			if ( false !== $deflated && strlen( $deflated ) < $size ) {
				$data   = $deflated;
				$method = 8;
			}
		}
		$entry = $this->write_local_header( $name, $method, $crc, strlen( $data ), $size, $mtime );
		$this->write( $data );
		$this->add_central_record( $entry, $crc, strlen( $data ), $size );
	}

	/**
	 * @param string $path File on disk.
	 * @param string $name Path inside the archive.
	 * @return bool False when the file could not be read; nothing is written then.
	 */
	public function add_file( $path, $name ) {
		// phpcs:disable WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$size  = @filesize( $path );
		$mtime = @filemtime( $path );
		if ( false === $size || false === $mtime || ! is_readable( $path ) ) {
			return false;
		}

		if ( $size <= self::MEMORY_LIMIT ) {
			$data = @file_get_contents( $path );
			if ( false === $data ) {
				return false;
			}
			$this->add_from_string( $name, $data, $mtime, self::is_compressible( $name ) );
			return true;
		}

		$deflate = $size < self::DEFLATE_LIMIT && self::is_compressible( $name );
		$crc     = $deflate ? 0 : @hash_file( 'crc32b', $path );
		$handle  = false === $crc ? false : @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		if ( $deflate ) {
			$this->add_deflated_stream( $handle, $name, $size, $mtime );
		} else {
			$this->add_stored_stream( $handle, $name, $size, $mtime, (int) hexdec( $crc ) );
		}
		fclose( $handle );
		// phpcs:enable
		return true;
	}

	/**
	 * Writes the central directory. The output itself is left open.
	 */
	public function finish() {
		$central_offset = $this->offset;
		rewind( $this->central );
		while ( ! feof( $this->central ) ) {
			$chunk = fread( $this->central, self::READ_CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$this->write( $chunk );
		}
		fclose( $this->central ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( $this->count >= self::MAX_16 || $this->central_size >= self::MAX_32 || $central_offset >= self::MAX_32 ) {
			$zip64_offset = $this->offset;
			$this->write( pack( 'VPvvVVPPPP', 0x06064b50, 44, self::MADE_BY, 45, 0, 0, $this->count, $this->count, $this->central_size, $central_offset ) );
			$this->write( pack( 'VVPV', 0x07064b50, 0, $zip64_offset, 1 ) );
		}
		$this->write(
			pack(
				'VvvvvVVv',
				0x06054b50,
				0,
				0,
				min( $this->count, self::MAX_16 ),
				min( $this->count, self::MAX_16 ),
				min( $this->central_size, self::MAX_32 ),
				min( $central_offset, self::MAX_32 ),
				0
			)
		);
	}

	/**
	 * @param string $name Path inside the archive.
	 * @return bool Whether deflate is worth trying for this file.
	 */
	public static function is_compressible( $name ) {
		return ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::STORED_EXTENSIONS, true );
	}

	/**
	 * Deflates a file as it is read; CRC and sizes go in a data descriptor.
	 *
	 * @param resource $handle Open file.
	 * @param string   $name   Path inside the archive.
	 * @param int      $limit  Bytes to read at most (the size when the backup reached the file).
	 * @param int      $mtime  Unix timestamp.
	 */
	private function add_deflated_stream( $handle, $name, $limit, $mtime ) {
		$entry      = $this->write_local_header( $name, 8, 0, 0, 0, $mtime, true );
		$deflate    = deflate_init( ZLIB_ENCODING_RAW, array( 'level' => 6 ) );
		$hash       = hash_init( 'crc32b' );
		$size       = 0;
		$compressed = 0;
		while ( $size < $limit ) {
			$chunk = fread( $handle, (int) min( self::READ_CHUNK, $limit - $size ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			hash_update( $hash, $chunk );
			$size       += strlen( $chunk );
			$data        = deflate_add( $deflate, $chunk, ZLIB_NO_FLUSH );
			$compressed += strlen( $data );
			$this->write( $data );
		}
		$data        = deflate_add( $deflate, '', ZLIB_FINISH );
		$compressed += strlen( $data );
		$this->write( $data );

		$crc = (int) hexdec( hash_final( $hash ) );
		$this->write( pack( 'VVVV', 0x08074b50, $crc, $compressed, $size ) );
		$this->add_central_record( $entry, $crc, $compressed, $size );
	}

	/**
	 * Copies a file as-is, with the CRC computed beforehand.
	 *
	 * @param resource $handle Open file.
	 * @param string   $name   Path inside the archive.
	 * @param int      $size   File size.
	 * @param int      $mtime  Unix timestamp.
	 * @param int      $crc    CRC-32 of the file.
	 */
	private function add_stored_stream( $handle, $name, $size, $mtime, $crc ) {
		$entry     = $this->write_local_header( $name, 0, $crc, $size, $size, $mtime );
		$remaining = $size;
		while ( $remaining > 0 ) {
			$chunk = fread( $handle, (int) min( self::READ_CHUNK, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$this->write( $chunk );
			$remaining -= strlen( $chunk );
		}
		// The file shrank while being read: pad it so the rest of the archive stays readable.
		while ( $remaining > 0 ) {
			$padding = (int) min( self::READ_CHUNK, $remaining );
			$this->write( str_repeat( "\0", $padding ) );
			$remaining -= $padding;
		}
		$this->add_central_record( $entry, $crc, $size, $size );
	}

	/**
	 * @param string $name       Path inside the archive.
	 * @param int    $method     0 (stored) or 8 (deflated).
	 * @param int    $crc        CRC-32 of the uncompressed data.
	 * @param int    $compressed Size of the data that follows the header.
	 * @param int    $size       Uncompressed size.
	 * @param int    $mtime      Unix timestamp.
	 * @param bool   $streamed   CRC and sizes are unknown yet and will follow in a data descriptor.
	 * @return array What add_central_record() needs to know about the entry.
	 */
	private function write_local_header( $name, $method, $crc, $compressed, $size, $mtime, $streamed = false ) {
		$mtime = max( 0, min( (int) $mtime, 0x7FFFFFFF ) );
		$entry = array(
			'name'      => $name,
			'method'    => $method,
			'offset'    => $this->offset,
			'flags'     => ( preg_match( '//u', $name ) ? self::FLAG_UTF8 : 0 ) | ( $streamed ? self::FLAG_DESCRIPTOR : 0 ),
			'dos'       => $this->dos_datetime( $mtime ),
			// Extended timestamp field: the exact modification time, in UTC.
			'timestamp' => pack( 'vvCV', 0x5455, 5, 1, $mtime ),
		);

		$zip64 = $size >= self::MAX_32 || $compressed >= self::MAX_32;
		$extra = $zip64 ? pack( 'vvPP', 0x0001, 16, $size, $compressed ) . $entry['timestamp'] : $entry['timestamp'];
		$this->write(
			pack(
				'VvvvvvVVVvv',
				0x04034b50,
				$zip64 ? 45 : 20,
				$entry['flags'],
				$method,
				$entry['dos'][0],
				$entry['dos'][1],
				$crc,
				$zip64 ? self::MAX_32 : $compressed,
				$zip64 ? self::MAX_32 : $size,
				strlen( $name ),
				strlen( $extra )
			) . $name . $extra
		);
		return $entry;
	}

	/**
	 * Queues an entry's central directory record.
	 *
	 * @param array $entry      As returned by write_local_header().
	 * @param int   $crc        CRC-32 of the uncompressed data.
	 * @param int   $compressed Size of the data in the archive.
	 * @param int   $size       Uncompressed size.
	 * @param bool  $is_dir     Whether the entry is a directory.
	 */
	private function add_central_record( array $entry, $crc, $compressed, $size, $is_dir = false ) {
		// Only the values that overflow go in the ZIP64 field, in this order.
		$zip64 = '';
		foreach ( array( $size, $compressed, $entry['offset'] ) as $value ) {
			if ( $value >= self::MAX_32 ) {
				$zip64 .= pack( 'P', $value );
			}
		}
		$extra = '' === $zip64 ? $entry['timestamp'] : pack( 'vv', 0x0001, strlen( $zip64 ) ) . $zip64 . $entry['timestamp'];

		$record = pack(
			'VvvvvvvVVVvvvvvVV',
			0x02014b50,
			self::MADE_BY,
			'' === $zip64 ? 20 : 45,
			$entry['flags'],
			$entry['method'],
			$entry['dos'][0],
			$entry['dos'][1],
			$crc,
			min( $compressed, self::MAX_32 ),
			min( $size, self::MAX_32 ),
			strlen( $entry['name'] ),
			strlen( $extra ),
			0,
			0,
			0,
			$is_dir ? ( 040755 << 16 ) | 0x10 : 0100644 << 16,
			min( $entry['offset'], self::MAX_32 )
		) . $entry['name'] . $extra;

		fwrite( $this->central, $record ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->central_size += strlen( $record );
		++$this->count;
	}

	/**
	 * @param int $timestamp Unix timestamp.
	 * @return int[] MS-DOS time and date.
	 */
	private function dos_datetime( $timestamp ) {
		$date = new DateTime( '@' . $timestamp );
		$date->setTimezone( $this->timezone );
		$year = (int) $date->format( 'Y' );
		if ( $year < 1980 ) {
			return array( 0, ( 1 << 5 ) | 1 );
		}
		return array(
			( (int) $date->format( 'G' ) << 11 ) | ( (int) $date->format( 'i' ) << 5 ) | ( (int) $date->format( 's' ) >> 1 ),
			( ( $year - 1980 ) << 9 ) | ( (int) $date->format( 'n' ) << 5 ) | (int) $date->format( 'j' ),
		);
	}

	/**
	 * @param string $data Bytes to append to the archive.
	 */
	private function write( $data ) {
		$this->output->write( $data );
		$this->offset += strlen( $data );
	}
}
