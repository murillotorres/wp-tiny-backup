<?php
/**
 * SQL dump.
 *
 * @package Tiny_Backup
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dumps the site's tables as SQL through $wpdb, so it works on hosts that
 * don't allow running mysqldump.
 */
class Tiny_Backup_Database {

	/** Rows are fetched in batches of roughly this many bytes. */
	const BATCH_BYTES = 2097152;

	/** Each INSERT stays under this size, well below MySQL's default max_allowed_packet. */
	const STATEMENT_BYTES = 1048576;

	/** Same escaping as mysqli_real_escape_string(), without needing the connection. */
	const ESCAPES = array(
		'\\'   => '\\\\',
		"\0"   => '\\0',
		"\n"   => '\\n',
		"\r"   => '\\r',
		"'"    => "\\'",
		'"'    => '\\"',
		"\x1a" => '\\Z',
	);

	/**
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * @param wpdb $wpdb Database connection.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Lists the tables that belong to this site: every table whose name
	 * starts with the table prefix (all sites, on multisite). Views are left out.
	 *
	 * @return array<string, array{bytes: int, avg_row: int}> Table name => size on disk and average row length.
	 */
	public function tables() {
		$wpdb   = $this->wpdb;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ), ARRAY_A );
		$tables = array();
		foreach ( (array) $rows as $row ) {
			if ( empty( $row['Engine'] ) ) {
				continue;
			}
			$tables[ $row['Name'] ] = array(
				'bytes'   => (int) $row['Data_length'] + (int) $row['Index_length'],
				'avg_row' => (int) $row['Avg_row_length'],
			);
		}

		/**
		 * Filters the tables included in the database backup.
		 *
		 * @param array $tables Table name => array( 'bytes' => int, 'avg_row' => int ).
		 */
		return apply_filters( 'tiny_backup_tables', $tables );
	}

	/**
	 * Writes the dump. Tables are read inside one transaction, so InnoDB
	 * tables are consistent with each other even if the site is busy.
	 *
	 * @param Tiny_Backup_Output $output Where the SQL goes.
	 * @param array              $tables As returned by tables().
	 */
	public function dump( Tiny_Backup_Output $output, array $tables ) {
		$wpdb = $this->wpdb;
		$wpdb->hide_errors(); // Printed errors would end up inside the file.

		$charset = preg_replace( '/[^a-z0-9_]/i', '', (string) $wpdb->charset );
		$server  = method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : $wpdb->db_version();

		$output->write(
			implode(
				"\n",
				array(
					'-- Tiny Backup ' . TINY_BACKUP_VERSION . ' SQL dump',
					'-- Site: ' . home_url(),
					'-- Date: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
					'-- Server: ' . self::comment( (string) $server ),
					'-- Tables: ' . count( $tables ),
					'',
					'SET NAMES ' . ( $charset ? $charset : 'utf8mb4' ) . ';',
					'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;',
					'SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;',
					"SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';",
					"SET @OLD_TIME_ZONE=@@TIME_ZONE, TIME_ZONE='+00:00';",
					'',
				)
			)
		);

		// TIMESTAMP columns are read in UTC, matching the TIME_ZONE set in the dump.
		$wpdb->query( "SET time_zone = '+00:00'" );
		$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		foreach ( $tables as $table => $info ) {
			$this->dump_table( $output, $table, isset( $info['avg_row'] ) ? (int) $info['avg_row'] : 0 );
		}
		$wpdb->query( 'COMMIT' );

		$output->write(
			implode(
				"\n",
				array(
					'',
					'SET TIME_ZONE=@OLD_TIME_ZONE;',
					'SET SQL_MODE=@OLD_SQL_MODE;',
					'SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;',
					'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;',
					'',
					'-- Dump completed on ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
					'',
				)
			)
		);
	}

	/**
	 * @param Tiny_Backup_Output $output  Where the SQL goes.
	 * @param string             $table   Table name.
	 * @param int                $avg_row Average row length in bytes, 0 if unknown.
	 */
	private function dump_table( Tiny_Backup_Output $output, $table, $avg_row ) {
		$wpdb    = $this->wpdb;
		$name    = self::quote_name( $table );
		$create  = $wpdb->get_row( "SHOW CREATE TABLE $name", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = empty( $create[1] ) ? array() : $wpdb->get_results( "SHOW COLUMNS FROM $name", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $columns ) ) {
			$output->write( "\n-- Tiny Backup could not read table $name: " . self::comment( $wpdb->last_error ) . "\n" );
			return;
		}
		$output->write( "\n--\n-- Table $name\n--\n\nDROP TABLE IF EXISTS $name;\n{$create[1]};\n\n" );

		$fields  = array();
		$selects = array();
		$kinds   = array();
		$primary = array();
		foreach ( $columns as $column ) {
			$field = self::quote_name( $column['Field'] );
			if ( 'PRI' === $column['Key'] ) {
				$primary[] = $field;
			}
			// Generated columns are computed by MySQL and can't be inserted.
			if ( preg_match( '/\b(VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', $column['Extra'] ) ) {
				continue;
			}
			$kind     = self::column_kind( $column['Type'] );
			$fields[] = $field;
			// Drivers return BIT values either as raw bytes or as digits; asking for a number settles it.
			$selects[] = 'bit' === $kind ? "$field + 0" : $field;
			$kinds[]   = 'bit' === $kind ? 'number' : $kind;
		}

		// With a single numeric primary key, page by key: OFFSET gets slower the deeper it goes.
		$key = 1 === count( $primary ) ? array_search( $primary[0], $fields, true ) : false;
		if ( false !== $key && 'number' !== $kinds[ $key ] ) {
			$key = false;
		}

		$select    = 'SELECT ' . implode( ', ', $selects ) . " FROM $name";
		$order     = $primary ? ' ORDER BY ' . implode( ', ', $primary ) : '';
		$limit     = $avg_row > 0 ? (int) max( 10, min( 5000, floor( self::BATCH_BYTES / $avg_row ) ) ) : 500;
		$insert    = "INSERT INTO $name (" . implode( ', ', $fields ) . ") VALUES\n";
		$statement = '';
		$offset    = 0;
		$last      = null;

		do {
			if ( false !== $key ) {
				$where = null === $last ? '' : " WHERE {$primary[0]} > $last";
				$rows  = $wpdb->get_results( "$select$where$order LIMIT $limit", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$rows = $wpdb->get_results( "$select$order LIMIT $limit OFFSET $offset", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( $wpdb->last_error ) {
				if ( '' !== $statement ) {
					$output->write( $statement . ";\n" );
				}
				$output->write( "-- Tiny Backup could not read all rows of $name: " . self::comment( $wpdb->last_error ) . "\n" );
				return;
			}

			$rows = (array) $rows;
			foreach ( $rows as $row ) {
				foreach ( $row as $i => $value ) {
					$row[ $i ] = self::format_value( $value, $kinds[ $i ] );
				}
				$tuple = '(' . implode( ',', $row ) . ')';
				if ( '' !== $statement && strlen( $statement ) + strlen( $tuple ) > self::STATEMENT_BYTES ) {
					$output->write( $statement . ";\n" );
					$statement = '';
				}
				$statement .= ( '' === $statement ? $insert : ",\n" ) . $tuple;
			}

			$count   = count( $rows );
			$offset += $count;
			if ( $count && false !== $key ) {
				$last = self::format_value( $rows[ $count - 1 ][ $key ], 'number' );
			}
		} while ( $count === $limit );

		if ( '' !== $statement ) {
			$output->write( $statement . ";\n" );
		}
	}

	/**
	 * @param string $type Column type, as shown by SHOW COLUMNS.
	 * @return string 'number', 'bit', 'binary' or 'string'.
	 */
	private static function column_kind( $type ) {
		$type = strtolower( $type );
		if ( 0 === strpos( $type, 'bit' ) ) {
			return 'bit';
		}
		if ( preg_match( '/^((tiny|small|medium|big)?int|decimal|numeric|float|double|real|year)\b/', $type ) ) {
			return 'number';
		}
		if ( preg_match( '/^((tiny|medium|long)?blob|(var)?binary|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/', $type ) ) {
			return 'binary';
		}
		return 'string';
	}

	/**
	 * @param string|null $value Value as returned by $wpdb.
	 * @param string      $kind  See column_kind().
	 * @return string SQL literal.
	 */
	private static function format_value( $value, $kind ) {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( 'binary' === $kind ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		if ( 'number' === $kind && is_numeric( $value ) ) {
			return $value;
		}
		return "'" . strtr( $value, self::ESCAPES ) . "'";
	}

	/**
	 * @param string $name Table or column name.
	 * @return string The name between backticks.
	 */
	private static function quote_name( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * @param string $text Text for a single-line SQL comment.
	 * @return string
	 */
	private static function comment( $text ) {
		return str_replace( array( "\r", "\n" ), ' ', $text );
	}
}
