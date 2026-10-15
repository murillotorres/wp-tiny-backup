<?php
/**
 * Buffered output, optionally gzip-compressed.
 *
 * @package Tiny_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collects writes into large chunks and sends each one on to a stream,
 * flushing PHP's output after every chunk so long downloads keep moving.
 */
class Tiny_Backup_Output {

	const CHUNK_SIZE = 1048576;

	/**
	 * Destination stream.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Incremental gzip context, when compressing.
	 *
	 * @var resource|DeflateContext|null
	 */
	private $deflate = null;

	/**
	 * Whether to flush PHP's output buffers to the client after each chunk.
	 *
	 * @var bool
	 */
	private $flush;

	/**
	 * Data not sent yet.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * @param resource $handle Stream to write to.
	 * @param bool     $gzip   Compress everything written as a single gzip stream.
	 * @param bool     $flush  Call flush() after each chunk (for HTTP downloads).
	 */
	public function __construct( $handle, $gzip = false, $flush = false ) {
		$this->handle = $handle;
		$this->flush  = $flush;
		if ( $gzip ) {
			$this->deflate = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 6 ) );
		}
	}

	/**
	 * @param string $data Bytes to write.
	 */
	public function write( $data ) {
		$this->buffer .= $data;
		if ( strlen( $this->buffer ) >= self::CHUNK_SIZE ) {
			$this->emit( false );
		}
	}

	/**
	 * Sends whatever is left and ends the gzip stream.
	 */
	public function close() {
		$this->emit( true );
	}

	/**
	 * @param bool $final Whether this is the last chunk.
	 * @throws RuntimeException When the stream stops accepting data.
	 */
	private function emit( $final ) {
		$data         = $this->buffer;
		$this->buffer = '';
		if ( $this->deflate ) {
			$data = deflate_add( $this->deflate, $data, $final ? ZLIB_FINISH : ZLIB_NO_FLUSH );
		}
		while ( '' !== $data ) {
			$written = fwrite( $this->handle, $data );
			if ( ! $written ) {
				throw new RuntimeException( 'Could not write the backup.' );
			}
			$data = (string) substr( $data, $written );
		}
		if ( $this->flush ) {
			flush();
		}
	}
}
