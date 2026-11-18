<?php
/**
 * Chunked, resumable SQL import, read straight from the archive.
 *
 * - Reads one statement at a time with fgets() from a saved byte offset.
 * - Imports into temporary tables, so the live site keeps working until the swap.
 * - Auto-repairs common cross-server problems (collations, utf8mb4, key length,
 *   storage engines, max_allowed_packet, duplicate keys, lost connections).
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database importer.
 */
class Monarch_DB_Import {

	/**
	 * Prepare server capabilities once per job.
	 *
	 * @return array
	 */
	public static function server_caps() {
		global $wpdb;
		$colls = array();
		foreach ( (array) $wpdb->get_col( "SHOW COLLATION WHERE Charset IN ('utf8mb4','utf8','utf8mb3')" ) as $c ) { // phpcs:ignore
			$colls[] = $c;
		}
		$packet = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
		return array(
			'utf8mb4'    => in_array( 'utf8mb4_general_ci', $colls, true ),
			'collations' => $colls,
			'packet'     => $packet > 0 ? $packet : 1048576,
			'engines'    => array_map( 'strtoupper', (array) $wpdb->get_col( "SELECT ENGINE FROM information_schema.ENGINES WHERE SUPPORT IN ('YES','DEFAULT')" ) ), // phpcs:ignore
		);
	}

	/**
	 * Session setup for the import connection.
	 *
	 * @param array $caps Caps.
	 */
	public static function session( $caps ) {
		Monarch_Util::raw_query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		Monarch_Util::raw_query( 'SET FOREIGN_KEY_CHECKS = 0' );
		Monarch_Util::raw_query( 'SET UNIQUE_CHECKS = 0' );
		Monarch_Util::raw_query( $caps['utf8mb4'] ? 'SET NAMES utf8mb4' : 'SET NAMES utf8' );
		Monarch_Util::raw_query( 'SET SESSION wait_timeout = 600' );
	}

	/**
	 * Run a slice of the import.
	 *
	 * @param array    $job Job (modified). Uses $job['db'].
	 * @param resource $fh  Archive handle.
	 * @return string 'done' | 'more' | 'error'
	 */
	public static function step( &$job, $fh ) {
		$st   = &$job['db'];
		$caps = $job['caps'];
		self::session( $caps );
		// Autocommit stays ON: every statement is durable the moment the cursor
		// moves past it, so a dropped connection can never lose committed rows.
		$result          = 'more';
		$st['req_rows']  = 0;
		$st['req_stmts'] = 0;
		while ( true ) {
			if ( $st['pos'] >= $st['end'] ) {
				$result = 'done';
				self::table_finished( $job );
				break;
			}
			if ( Monarch_Util::out_of_time() ) {
				break;
			}
			list( $sql, $next ) = self::read_statement( $fh, $st['pos'], $st['end'] );
			if ( '' === trim( $sql ) ) {
				$st['pos'] = $next;
				continue;
			}
			if ( ! empty( $st['skip'] ) ) {
				$st['skip']  = false;
				$st['pos']   = $next;
				$st['index']++;
				$job['skipped'][] = $st['index'];
				Monarch_Log::add( $job, sprintf( 'Skipped statement #%d at your request', $st['index'] ), 'warn' );
				continue;
			}
			self::track( $sql, $job );
			$err = self::execute( $sql, $job );
			if ( true !== $err ) {
				Monarch_Log::add( $job, sprintf( 'Statement #%d failed: %s', $st['index'] + 1, $err ), 'err' );
				$job['error'] = array(
					'stage'     => 'db',
					'statement' => $st['index'] + 1,
					'message'   => $err,
					'sql'       => function_exists( 'mb_substr' ) ? mb_substr( $sql, 0, 400 ) : substr( $sql, 0, 400 ),
				);
				$result       = 'error';
				break;
			}
			$st['pos'] = $next;
			$st['index']++;
			$st['req_stmts']++;
			if ( 0 === strncasecmp( ltrim( $sql ), 'INSERT', 6 ) ) {
				$rows             = substr_count( $sql, "),\n(" ) + 1;
				$st['cur_rows']   = ( isset( $st['cur_rows'] ) ? $st['cur_rows'] : 0 ) + $rows;
				$st['cur_bytes']  = ( isset( $st['cur_bytes'] ) ? $st['cur_bytes'] : 0 ) + strlen( $sql );
				$st['req_rows']  += $rows;
			}
		}
		if ( 'more' === $result && $st['req_rows'] > 0 && ! empty( $st['cur'] ) ) {
			Monarch_Log::add( $job, sprintf( '%s — %s rows imported so far · statement %s of %s', $st['cur'], Monarch_Log::num( $st['cur_rows'] ), Monarch_Log::num( $st['index'] ), Monarch_Log::num( $st['total'] ) ) );
		}
		return $result;
	}

	/**
	 * Notice table changes from the statement head and log per-table results.
	 *
	 * @param string $sql SQL.
	 * @param array  $job Job.
	 */
	private static function track( $sql, &$job ) {
		if ( ! preg_match( '/^\s*CREATE TABLE `' . preg_quote( Monarch_DB_Dump::PREFIX_TOKEN, '/' ) . '([^`]+)`/', $sql, $m ) ) {
			return;
		}
		self::table_finished( $job );
		$st                = &$job['db'];
		$st['cur']         = $m[1];
		$st['cur_rows']    = 0;
		$st['cur_bytes']   = 0;
		$st['tables_done'] = ( isset( $st['tables_done'] ) ? $st['tables_done'] : 0 ) + 1;
		$total             = isset( $job['manifest']['tables'] ) ? count( $job['manifest']['tables'] ) : 0;
		Monarch_Log::add( $job, sprintf( '[%d/%d] Creating table %s', $st['tables_done'], $total, $job['live'] . $m[1] ) );
	}

	/**
	 * Log the table that just completed.
	 *
	 * @param array $job Job.
	 */
	private static function table_finished( &$job ) {
		$st = &$job['db'];
		if ( empty( $st['cur'] ) ) {
			return;
		}
		Monarch_Log::add( $job, sprintf( '%s — done: %s rows (%s of SQL)', $job['live'] . $st['cur'], Monarch_Log::num( isset( $st['cur_rows'] ) ? $st['cur_rows'] : 0 ), Monarch_Log::size( isset( $st['cur_bytes'] ) ? $st['cur_bytes'] : 0 ) ), 'ok' );
		$st['cur'] = '';
	}

	/**
	 * Read one statement starting at a marker line.
	 *
	 * @param resource $fh  Handle.
	 * @param int      $pos Start offset.
	 * @param int      $end End offset of the SQL entry.
	 * @return array [sql, next_offset]
	 */
	private static function read_statement( $fh, $pos, $end ) {
		fseek( $fh, $pos );
		$sql   = '';
		$first = true;
		while ( ftell( $fh ) < $end ) {
			$line_pos = ftell( $fh );
			$line     = fgets( $fh );
			if ( false === $line ) {
				break;
			}
			if ( $line_pos + strlen( $line ) > $end ) {
				$line = substr( $line, 0, $end - $line_pos );
			}
			if ( 0 === strpos( $line, Monarch_DB_Dump::MARKER ) && rtrim( $line ) === Monarch_DB_Dump::MARKER ) {
				if ( $first ) {
					$first = false;
					continue;
				}
				return array( $sql, $line_pos );
			}
			$first = false;
			$sql  .= $line;
		}
		return array( $sql, $end );
	}

	/**
	 * Rewrite and execute a statement with automatic repairs.
	 *
	 * @param string $sql SQL.
	 * @param array  $job Job (modified: caps/log/budget).
	 * @return true|string True or error message.
	 */
	private static function execute( $sql, &$job ) {
		$sql  = rtrim( $sql );
		$tmp  = $job['tmp_prefix'];
		$type = strtoupper( substr( ltrim( $sql ), 0, 6 ) );

		if ( 'INSERT' === $type ) {
			$head_len = strpos( $sql, ' VALUES' );
			$head_len = false === $head_len ? 200 : $head_len;
			$sql      = str_replace( Monarch_DB_Dump::PREFIX_TOKEN, $tmp, substr( $sql, 0, $head_len ) ) . substr( $sql, $head_len );
		} else {
			$sql = str_replace( Monarch_DB_Dump::PREFIX_TOKEN, $tmp, $sql );
		}
		if ( 'CREATE' === $type ) {
			$notes = array();
			$sql   = self::fix_create( $sql, $job['caps'], $notes );
			foreach ( $notes as $note ) {
				// Log each kind of fix once in full; afterwards just mention the table.
				$kind = substr( $note, strpos( $note, ': ' ) + 2 );
				if ( empty( $job['db']['seen'][ md5( $kind ) ] ) ) {
					$job['db']['seen'][ md5( $kind ) ] = 1;
					Monarch_Log::add( $job, 'Compatibility fix: ' . $note, 'warn' );
				} else {
					Monarch_Log::add( $job, 'Compatibility fix applied to ' . strtok( $note, ':' ) . ' (same as above)', 'warn' );
				}
			}
		}

		// Too big for this server's max_allowed_packet? Split the multi-row INSERT.
		if ( 'INSERT' === $type && strlen( $sql ) > $job['caps']['packet'] - 4096 ) {
			$parts = self::split_insert( $sql, max( 16384, $job['caps']['packet'] - 65536 ) );
			if ( count( $parts ) < 2 ) {
				return self::too_big( $sql, $job );
			}
			Monarch_Log::add( $job, sprintf( 'INSERT of %s is larger than this server\'s max_allowed_packet (%s) — split into %d smaller queries', Monarch_Log::size( strlen( $sql ) ), Monarch_Log::size( $job['caps']['packet'] ), count( $parts ) ), 'warn' );
			foreach ( $parts as $part ) {
				$r = self::run( $part, $job, 'INSERT' );
				if ( true !== $r ) {
					return $r;
				}
			}
			return true;
		}
		return self::run( $sql, $job, $type );
	}

	/**
	 * Execute with retry and repair strategies.
	 *
	 * @param string $sql  SQL.
	 * @param array  $job  Job.
	 * @param string $type Statement type.
	 * @return true|string
	 */
	private static function run( $sql, &$job, $type ) {
		global $wpdb;
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			list( $ok, $error, $errno ) = Monarch_Util::raw_query( $sql );
			if ( $ok ) {
				return true;
			}
			$lower = strtolower( $error );
			// Lost connection: reconnect, shrink the time budget, retry.
			if ( 2006 === $errno || 2013 === $errno || false !== strpos( $lower, 'gone away' ) || false !== strpos( $lower, 'lost connection' ) ) {
				if ( method_exists( $wpdb, 'check_connection' ) ) {
					$wpdb->check_connection( false );
				}
				self::session( $job['caps'] );
				$job['budget'] = max( 3, (int) ( $job['budget'] * 0.7 ) );
				Monarch_Log::add( $job, sprintf( 'Database connection lost (%s) — reconnected and retrying; step time reduced to %ss', $error, $job['budget'] ), 'warn' );
				if ( 'INSERT' === $type && strlen( $sql ) > 65536 ) {
					$parts = self::split_insert( $sql, (int) max( 16384, strlen( $sql ) / 4 ) );
					if ( count( $parts ) > 1 ) {
						foreach ( $parts as $part ) {
							$r = self::run( $part, $job, 'INSERT' );
							if ( true !== $r ) {
								return $r;
							}
						}
						return true;
					}
				}
				continue;
			}
			if ( 'INSERT' === $type && ( 1062 === $errno || false !== strpos( $lower, 'duplicate entry' ) ) && 0 !== stripos( $sql, 'INSERT IGNORE' ) ) {
				$sql = preg_replace( '/^INSERT INTO/i', 'INSERT IGNORE INTO', ltrim( $sql ) );
				Monarch_Log::add( $job, 'Duplicate key (' . $error . ') — rows already present, continuing with INSERT IGNORE', 'warn' );
				continue;
			}
			if ( 'INSERT' === $type && ( 1153 === $errno || false !== strpos( $lower, 'max_allowed_packet' ) ) && strlen( $sql ) > 16384 ) {
				$parts = self::split_insert( $sql, (int) max( 16384, strlen( $sql ) / 2 ) - 4096 );
				if ( count( $parts ) < 2 ) {
					return self::too_big( $sql, $job );
				}
				Monarch_Log::add( $job, sprintf( 'Server rejected a %s query (max_allowed_packet) — retrying as %d smaller queries', Monarch_Log::size( strlen( $sql ) ), count( $parts ) ), 'warn' );
				foreach ( $parts as $part ) {
					$r = self::run( $part, $job, 'INSERT' );
					if ( true !== $r ) {
						return $r;
					}
				}
				return true;
			}
			if ( 'CREATE' === $type ) {
				$fixed = $sql;
				$why   = '';
				if ( ( 1050 === $errno || false !== strpos( $lower, 'already exists' ) ) && preg_match( '/^CREATE TABLE\s+(`[^`]+`)/i', ltrim( $sql ), $tm ) ) {
					// A previous request was killed after CREATE but before saving progress.
					Monarch_Util::raw_query( 'DROP TABLE IF EXISTS ' . $tm[1] );
					Monarch_Log::add( $job, 'Table ' . $tm[1] . ' existed from an interrupted step — recreated it', 'warn' );
					continue;
				}
				if ( 1273 === $errno || false !== strpos( $lower, 'unknown collation' ) || false !== strpos( $lower, 'unknown character set' ) ) {
					$fixed = self::downgrade_charset( $sql, true );
					$why   = 'unknown collation/charset → using a compatible one';
				} elseif ( 1071 === $errno || false !== strpos( $lower, 'key was too long' ) ) {
					$fixed = self::downgrade_charset( $sql, false );
					$why   = 'index too long for this server → using utf8 for this table';
				} elseif ( 1286 === $errno || false !== strpos( $lower, 'unknown storage engine' ) ) {
					$fixed = preg_replace( '/ENGINE\s*=\s*\w+/i', 'ENGINE=InnoDB', $sql );
					$why   = 'storage engine not available → InnoDB';
				} elseif ( 1067 === $errno || false !== strpos( $lower, 'invalid default value' ) ) {
					$fixed = preg_replace( "/DEFAULT '0000-00-00( 00:00:00)?'/i", 'DEFAULT NULL', $sql );
					$fixed = preg_replace( '/NOT NULL DEFAULT NULL/i', 'NULL DEFAULT NULL', $fixed );
					$why   = 'zero-date defaults not allowed → DEFAULT NULL';
				}
				if ( $fixed !== $sql ) {
					Monarch_Log::add( $job, sprintf( 'Server said "%s" — %s, retrying', $error, $why ), 'warn' );
					$sql = $fixed;
					continue;
				}
			}
			return $error ? $error : __( 'Unknown database error.', 'monarch-site-migration' );
		}
		return isset( $error ) && $error ? $error : __( 'Unknown database error.', 'monarch-site-migration' );
	}

	/**
	 * Proactive CREATE TABLE compatibility rewrites.
	 *
	 * @param string $sql  SQL.
	 * @param array  $caps  Caps.
	 * @param array  $notes Human-readable notes about what was changed (out).
	 * @return string
	 */
	private static function fix_create( $sql, $caps, &$notes = array() ) {
		$table = preg_match( '/^\s*CREATE TABLE `pbt[a-z0-9]{4}_([^`]+)`/', $sql, $tm ) ? $tm[1] : 'table';
		// MariaDB-only table options.
		$before = $sql;
		$sql    = preg_replace( '/\s(PAGE_CHECKSUM|TRANSACTIONAL)=\d/i', '', $sql );
		if ( $sql !== $before ) {
			$notes[] = "$table: removed MariaDB-only table options";
		}
		$sql = preg_replace( '/\/\*!\d+\s*DEFINER=[^*]*\*\//i', '', $sql );
		$sql = preg_replace( '/DEFINER\s*=\s*`[^`]*`@`[^`]*`/i', '', $sql );
		// MySQL 8 writes "utf8mb3"; older servers only know the alias "utf8".
		if ( ! in_array( 'utf8mb3_general_ci', $caps['collations'], true ) && false !== stripos( $sql, 'utf8mb3' ) ) {
			$sql     = preg_replace( '/utf8mb3/i', 'utf8', $sql );
			$notes[] = "$table: utf8mb3 → utf8 (older server naming)";
		}
		// Unknown collations (e.g. MySQL 8 utf8mb4_0900_ai_ci on MariaDB / MySQL 5.7).
		if ( preg_match_all( '/(?:COLLATE[= ]+)(\w+)/i', $sql, $m ) ) {
			foreach ( array_unique( $m[1] ) as $coll ) {
				if ( ! in_array( $coll, $caps['collations'], true ) && preg_match( '/^utf8/i', $coll ) ) {
					$best    = self::best_collation( $coll, $caps );
					$sql     = str_replace( $coll, $best, $sql );
					$notes[] = "$table: collation $coll is not available here → $best";
				}
			}
		}
		if ( ! $caps['utf8mb4'] && false !== stripos( $sql, 'utf8mb4' ) ) {
			$sql     = self::downgrade_charset( $sql, false );
			$notes[] = "$table: this server has no utf8mb4 → utf8 (emoji may be lost)";
		}
		if ( ! empty( $caps['engines'] ) && preg_match( '/ENGINE\s*=\s*(\w+)/i', $sql, $e ) && ! in_array( strtoupper( $e[1] ), $caps['engines'], true ) ) {
			$sql     = preg_replace( '/ENGINE\s*=\s*\w+/i', 'ENGINE=InnoDB', $sql );
			$notes[] = "$table: storage engine {$e[1]} not available → InnoDB";
		}
		return $sql;
	}

	/**
	 * Pick a supported replacement collation.
	 *
	 * @param string $coll Missing collation.
	 * @param array  $caps Caps.
	 * @return string
	 */
	private static function best_collation( $coll, $caps ) {
		$mb4 = 0 === stripos( $coll, 'utf8mb4' ) && $caps['utf8mb4'];
		$try = $mb4 ? array( 'utf8mb4_unicode_520_ci', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci' ) : array( 'utf8_unicode_520_ci', 'utf8_unicode_ci', 'utf8_general_ci', 'utf8mb3_unicode_ci', 'utf8mb3_general_ci' );
		foreach ( $try as $c ) {
			if ( in_array( $c, $caps['collations'], true ) ) {
				return $c;
			}
		}
		return $mb4 ? 'utf8mb4_general_ci' : 'utf8_general_ci';
	}

	/**
	 * Replace utf8mb4 with utf8 (fixes old servers and 767-byte key limits),
	 * or with $collation_only only fix collations.
	 *
	 * @param string $sql            SQL.
	 * @param bool   $collation_only Only normalise collations.
	 * @return string
	 */
	private static function downgrade_charset( $sql, $collation_only ) {
		if ( $collation_only ) {
			$sql = preg_replace( '/utf8mb4_\w+/i', 'utf8mb4_unicode_ci', $sql );
			$sql = preg_replace( '/utf8mb3_\w+/i', 'utf8_general_ci', $sql );
			return $sql;
		}
		$sql = preg_replace( '/utf8mb4_0900_\w+/i', 'utf8_general_ci', $sql );
		$sql = preg_replace( '/utf8mb4_unicode_520_ci/i', 'utf8_unicode_ci', $sql );
		$sql = preg_replace( '/utf8mb4_/i', 'utf8_', $sql );
		$sql = preg_replace( '/utf8mb4/i', 'utf8', $sql );
		return $sql;
	}

	/**
	 * Message for a single row that exceeds max_allowed_packet.
	 *
	 * @param string $sql SQL.
	 * @param array  $job Job.
	 * @return string
	 */
	private static function too_big( $sql, $job ) {
		return sprintf(
			/* translators: 1: row size, 2: server limit */
			__( 'A single database row is %1$s, but this MySQL server only accepts %2$s per query (max_allowed_packet). Ask your host to raise max_allowed_packet to at least 64M and click Retry — or Skip this row.', 'monarch-site-migration' ),
			size_format( strlen( $sql ), 1 ),
			size_format( $job['caps']['packet'], 0 )
		);
	}

	/**
	 * Split a multi-row INSERT (one row per line) into smaller statements.
	 *
	 * @param string $sql   INSERT.
	 * @param int    $limit Max bytes per statement.
	 * @return array
	 */
	public static function split_insert( $sql, $limit ) {
		$nl = strpos( $sql, "VALUES\n" );
		if ( false === $nl ) {
			return array( $sql );
		}
		$head  = substr( $sql, 0, $nl + 7 );
		$body  = rtrim( substr( $sql, $nl + 7 ), " ;\n\r" );
		$rows  = explode( ",\n", $body );
		$parts = array();
		$buf   = '';
		foreach ( $rows as $row ) {
			if ( '' !== $buf && strlen( $head ) + strlen( $buf ) + strlen( $row ) + 2 > $limit ) {
				$parts[] = $head . $buf . ';';
				$buf     = '';
			}
			$buf .= ( '' === $buf ? '' : ",\n" ) . $row;
		}
		if ( '' !== $buf ) {
			$parts[] = $head . $buf . ';';
		}
		return $parts;
	}
}
