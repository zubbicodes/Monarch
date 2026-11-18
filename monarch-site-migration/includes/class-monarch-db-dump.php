<?php
/**
 * Chunked, resumable database dump.
 *
 * Output format (consumed by Monarch_DB_Import):
 *   -- PB-STMT
 *   <one SQL statement, ending with ";">
 * Rows are written one per line (newlines inside values are escaped), so the
 * importer can split statements and even split huge INSERTs by line without
 * parsing SQL quoting.
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database exporter.
 */
class Monarch_DB_Dump {

	const MARKER        = '-- PB-STMT';
	const PREFIX_TOKEN  = '{{PB_PREFIX}}';
	const MAX_STATEMENT = 524288; // 512 KB per INSERT keeps every host's max_allowed_packet happy.

	/**
	 * Build the initial DB state: table list with key info.
	 *
	 * @param array $opts Export options.
	 * @return array
	 */
	public static function init( $opts ) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$tables = array();
		$est    = array();
		// Fast row estimates (COUNT(*) on huge InnoDB tables is slow). Only used for progress.
		foreach ( (array) $wpdb->get_results( 'SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', ARRAY_N ) as $r ) { // phpcs:ignore
			$est[ $r[0] ] = (int) $r[1];
		}
		$rows = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			$name = $r[0];
			$type = isset( $r[1] ) ? $r[1] : 'BASE TABLE';
			if ( 'BASE TABLE' !== $type || 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			$tables[] = self::table_info( $name, $prefix, $opts, isset( $est[ $name ] ) ? $est[ $name ] : 0 );
		}
		return array(
			'tables'    => $tables,
			'ti'        => 0,
			'stage'     => 'schema',
			'last'      => null,
			'offset'    => 0,
			'batch'     => 500,
			'sql_size'  => 0,
			'rows_done' => 0,
			'stmts'     => 0,
		);
	}

	/**
	 * Inspect a table.
	 *
	 * @param string $name   Table.
	 * @param string $prefix Prefix.
	 * @param array  $opts   Options.
	 * @param int    $estimate Estimated rows.
	 * @return array
	 */
	private static function table_info( $name, $prefix, $opts, $estimate ) {
		global $wpdb;
		$cols    = array();
		$pk      = array();
		$numeric = false;
		$info    = $wpdb->get_results( 'SHOW COLUMNS FROM ' . Monarch_Util::ident( $name ), ARRAY_A ); // phpcs:ignore
		foreach ( (array) $info as $c ) {
			$type   = strtolower( $c['Type'] );
			$cols[] = array(
				'name'   => $c['Field'],
				'binary' => (bool) preg_match( '/(blob|binary|bit\()/', $type ),
			);
			if ( 'PRI' === $c['Key'] ) {
				$pk[]    = $c['Field'];
				$numeric = (bool) preg_match( '/int/', $type );
			}
		}
		$short = substr( $name, strlen( $prefix ) );
		$where = array();
		if ( 'posts' === $short && ! empty( $opts['no_revisions'] ) ) {
			$where[] = "`post_type` <> 'revision'";
		}
		if ( 'comments' === $short && ! empty( $opts['no_spam'] ) ) {
			$where[] = "`comment_approved` NOT IN ('spam','trash')";
		}
		if ( ( 'options' === $short || 'sitemeta' === $short ) && ! empty( $opts['no_transients'] ) ) {
			$col     = 'options' === $short ? 'option_name' : 'meta_key';
			$where[] = "`$col` NOT LIKE '\\_transient\\_%' AND `$col` NOT LIKE '\\_site\\_transient\\_%'";
		}
		$count = $estimate;
		if ( $estimate < 200000 ) {
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Monarch_Util::ident( $name ) . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) ); // phpcs:ignore
		}
		return array(
			'name'   => $name,
			'short'  => $short,
			'cols'   => $cols,
			'pk'     => $pk,
			'keyset' => ( 1 === count( $pk ) && $numeric ),
			'where'  => implode( ' AND ', $where ),
			'rows'   => $count,
		);
	}

	/**
	 * Total rows to export.
	 *
	 * @param array $st DB state.
	 * @return int
	 */
	public static function total_rows( $st ) {
		$t = 0;
		foreach ( $st['tables'] as $tb ) {
			$t += $tb['rows'];
		}
		return max( 1, $t );
	}

	/**
	 * Run a slice of the dump.
	 *
	 * @param array  $job  Job (modified; uses $job['db']).
	 * @param string $file SQL output file.
	 * @return bool True when the whole dump is finished.
	 */
	public static function step( &$job, $file ) {
		global $wpdb;
		$st        = &$job['db'];
		$req_rows  = 0;
		$req_table = '';
		$fh = Monarch_Archive::open_append( $file, $st['sql_size'] );
		if ( ! $fh ) {
			throw new Exception( esc_html( 'Cannot write the database dump file. Check free disk space and permissions.' ) );
		}
		$done = false;
		while ( ! Monarch_Util::out_of_time() ) {
			if ( $st['ti'] >= count( $st['tables'] ) ) {
				$done = true;
				break;
			}
			$tb    = $st['tables'][ $st['ti'] ];
			$token = Monarch_Util::ident( self::PREFIX_TOKEN . $tb['short'] );

			if ( 'schema' === $st['stage'] ) {
				$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . Monarch_Util::ident( $tb['name'] ), ARRAY_N ); // phpcs:ignore
				if ( ! $create || empty( $create[1] ) ) {
					// Table disappeared while exporting; skip it.
					Monarch_Log::add( $job, sprintf( 'Table %s disappeared during export — skipped', $tb['name'] ), 'warn' );
					$st['ti']++;
					continue;
				}
				$st['t_rows']  = 0;
				$st['t_bytes'] = 0;
				Monarch_Log::add(
					$job,
					sprintf(
						'[%d/%d] %s — structure saved, ~%s rows%s%s',
						$st['ti'] + 1,
						count( $st['tables'] ),
						$tb['name'],
						Monarch_Log::num( $tb['rows'] ),
						$tb['keyset'] ? ' (fast primary-key paging)' : ( $tb['pk'] ? ' (key paging)' : ' (no primary key, offset paging)' ),
						$tb['where'] ? ' · filtered' : ''
					)
				);
				$sql = $create[1];
				$sql = preg_replace( '/^CREATE TABLE `' . preg_quote( $tb['name'], '/' ) . '`/', 'CREATE TABLE ' . $token, $sql );
				$sql = str_replace( 'REFERENCES `' . $wpdb->prefix, 'REFERENCES `' . self::PREFIX_TOKEN, $sql );
				fwrite( $fh, self::MARKER . "\nDROP TABLE IF EXISTS $token;\n" . self::MARKER . "\n" . $sql . ";\n" ); // phpcs:ignore
				$st['stmts']  += 2;
				$st['stage']   = 'rows';
				$st['last']    = null;
				$st['offset']  = 0;
				continue;
			}

			$rows = self::fetch( $tb, $st );
			if ( empty( $rows ) ) {
				self::table_done( $job, $tb );
				$st['ti']++;
				$st['stage'] = 'schema';
				$req_rows    = 0;
				continue;
			}

			$col_list = array();
			foreach ( $tb['cols'] as $c ) {
				$col_list[] = Monarch_Util::ident( $c['name'] );
			}
			$head  = self::MARKER . "\nINSERT INTO $token (" . implode( ',', $col_list ) . ") VALUES\n";
			$buf   = '';
			$bytes = 0;
			$pk_i  = $tb['keyset'] ? self::col_index( $tb, $tb['pk'][0] ) : -1;
			foreach ( $rows as $row ) {
				$vals = array();
				foreach ( $tb['cols'] as $i => $c ) {
					$v = $row[ $i ];
					if ( null === $v ) {
						$vals[] = 'NULL';
					} elseif ( $c['binary'] ) {
						$vals[] = '' === $v ? "''" : '0x' . $v;
					} else {
						$vals[] = "'" . Monarch_Util::esc_sql_value( $v ) . "'";
					}
				}
				$line = '(' . implode( ',', $vals ) . ')';
				if ( '' !== $buf && strlen( $buf ) + strlen( $line ) > self::MAX_STATEMENT ) {
					fwrite( $fh, $head . $buf . ";\n" ); // phpcs:ignore
					$st['stmts']++;
					$buf = '';
				}
				$buf    .= ( '' === $buf ? '' : ",\n" ) . $line;
				$bytes  += strlen( $line );
				if ( $pk_i >= 0 ) {
					$st['last'] = $row[ $pk_i ];
				}
			}
			if ( '' !== $buf ) {
				fwrite( $fh, $head . $buf . ";\n" ); // phpcs:ignore
				$st['stmts']++;
			}
			$count             = count( $rows );
			$st['offset']     += $count;
			$st['rows_done']  += $count;
			$st['t_rows']      = ( isset( $st['t_rows'] ) ? $st['t_rows'] : 0 ) + $count;
			$st['t_bytes']     = ( isset( $st['t_bytes'] ) ? $st['t_bytes'] : 0 ) + $bytes;
			$req_rows         += $count;
			$req_table         = $tb['name'];
			// Adapt batch size: aim for ~2 MB of row data per query.
			$avg         = $bytes / max( 1, $count );
			$st['batch'] = (int) max( 20, min( 5000, 2097152 / max( 1, $avg ) ) );
			unset( $rows, $buf );
			if ( $count < self::batch_limit( $st ) && $tb['keyset'] ) {
				// Fewer rows than requested => table finished (saves one query).
				self::table_done( $job, $tb );
				$st['ti']++;
				$st['stage'] = 'schema';
				$req_rows    = 0;
			}
		}
		if ( $req_rows > 0 && 'rows' === $st['stage'] && isset( $st['tables'][ $st['ti'] ] ) ) {
			$tb = $st['tables'][ $st['ti'] ];
			Monarch_Log::add( $job, sprintf( '%s — %s / ~%s rows exported (batch size %s)', $req_table, Monarch_Log::num( $st['t_rows'] ), Monarch_Log::num( max( $tb['rows'], $st['t_rows'] ) ), Monarch_Log::num( $st['batch'] ) ) );
		}
		fflush( $fh );
		$st['sql_size'] = ftell( $fh );
		fclose( $fh ); // phpcs:ignore
		return $done;
	}

	/**
	 * Log a finished table.
	 *
	 * @param array $job Job.
	 * @param array $tb  Table info.
	 */
	private static function table_done( &$job, $tb ) {
		$rows  = isset( $job['db']['t_rows'] ) ? $job['db']['t_rows'] : 0;
		$bytes = isset( $job['db']['t_bytes'] ) ? $job['db']['t_bytes'] : 0;
		Monarch_Log::add( $job, sprintf( '%s — done: %s rows (%s)', $tb['name'], Monarch_Log::num( $rows ), Monarch_Log::size( $bytes ) ), 'ok' );
	}

	/**
	 * Previous batch limit (the batch size used for the query just made).
	 *
	 * @param array $st State.
	 * @return int
	 */
	private static function batch_limit( $st ) {
		return isset( $st['used_batch'] ) ? (int) $st['used_batch'] : (int) $st['batch'];
	}

	/**
	 * Fetch the next page of rows as numeric arrays.
	 *
	 * @param array $tb Table info.
	 * @param array $st State (modified: used_batch).
	 * @return array
	 */
	private static function fetch( $tb, &$st ) {
		global $wpdb;
		$select = array();
		foreach ( $tb['cols'] as $c ) {
			$select[] = $c['binary'] ? 'HEX(' . Monarch_Util::ident( $c['name'] ) . ')' : Monarch_Util::ident( $c['name'] );
		}
		$limit            = (int) $st['batch'];
		$st['used_batch'] = $limit;
		$where            = array();
		if ( $tb['where'] ) {
			$where[] = '(' . $tb['where'] . ')';
		}
		$sql = 'SELECT ' . implode( ',', $select ) . ' FROM ' . Monarch_Util::ident( $tb['name'] );
		if ( $tb['keyset'] ) {
			$pk = Monarch_Util::ident( $tb['pk'][0] );
			if ( null !== $st['last'] ) {
				$where[] = $pk . " > '" . Monarch_Util::esc_sql_value( $st['last'] ) . "'";
			}
			$sql .= ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . " ORDER BY $pk ASC LIMIT $limit";
		} else {
			$order = '';
			if ( $tb['pk'] ) {
				$order = ' ORDER BY ' . implode( ',', array_map( array( 'Monarch_Util', 'ident' ), $tb['pk'] ) );
			}
			$sql .= ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' ) . $order . ' LIMIT ' . (int) $st['offset'] . ', ' . $limit;
		}
		$rows = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore
		if ( $wpdb->last_error ) {
			throw new Exception( esc_html( 'Database read error on ' . $tb['name'] . ': ' . $wpdb->last_error ) );
		}
		return (array) $rows;
	}

	/**
	 * Column index by name.
	 *
	 * @param array  $tb   Table info.
	 * @param string $name Column.
	 * @return int
	 */
	private static function col_index( $tb, $name ) {
		foreach ( $tb['cols'] as $i => $c ) {
			if ( $c['name'] === $name ) {
				return $i;
			}
		}
		return -1;
	}
}
