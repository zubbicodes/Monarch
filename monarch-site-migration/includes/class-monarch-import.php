<?php
/**
 * Import step machine:
 * validate -> database (temp tables) -> replace URLs -> extract files -> swap tables -> done.
 *
 * The live site keeps running on its own tables until the final, atomic RENAME.
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importer.
 */
class Monarch_Import {

	/**
	 * Inspect an archive for the confirmation screen.
	 *
	 * @param string $file Archive path.
	 * @return array|WP_Error
	 */
	public static function inspect( $file ) {
		$m = Monarch_Archive::validate( $file );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		return array(
			'source'     => $m['home'],
			'target'     => get_option( 'home' ),
			'created'    => isset( $m['created'] ) ? $m['created'] : '',
			'wp_version' => isset( $m['wp_version'] ) ? $m['wp_version'] : '',
			'php'        => isset( $m['php_version'] ) ? $m['php_version'] : '',
			'mysql'      => isset( $m['mysql_version'] ) ? $m['mysql_version'] : '',
			'tables'     => isset( $m['tables'] ) ? count( $m['tables'] ) : 0,
			'files'      => isset( $m['files'] ) ? (int) $m['files'] : 0,
			'size'       => size_format( $m['archive_size'], 1 ),
			'database'   => ! empty( $m['has_database'] ),
			'multisite'  => ! empty( $m['multisite'] ),
		);
	}

	/**
	 * Start an import job.
	 *
	 * @param string $file  Archive path.
	 * @param array  $extra Extra find/replace pairs.
	 * @return array|WP_Error
	 */
	public static function start( $file, $extra ) {
		global $wpdb;
		if ( is_multisite() ) {
			return new WP_Error( 'pb_ms', __( 'Multisite networks are not supported yet.', 'monarch-site-migration' ) );
		}
		$m = Monarch_Archive::validate( $file );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		if ( ! empty( $m['multisite'] ) ) {
			return new WP_Error( 'pb_ms', __( 'This backup comes from a multisite network, which is not supported yet.', 'monarch-site-migration' ) );
		}
		$fh = fopen( $file, 'rb' ); // phpcs:ignore
		fseek( $fh, 4 );
		$h = Monarch_Archive::read_header( $fh );
		fseek( $fh, ftell( $fh ) + $h['size'] );
		$hdr_pos = ftell( $fh );
		$h2      = Monarch_Archive::read_header( $fh );
		$db      = null;
		$content = $hdr_pos;
		if ( $h2 && 'database.sql' === $h2['name'] ) {
			$start   = ftell( $fh );
			$db      = array(
				'pos'   => $start,
				'end'   => $start + $h2['size'],
				'index' => 0,
				'total' => max( 1, (int) $m['statements'] ),
			);
			$content = $start + $h2['size'];
		}
		fclose( $fh ); // phpcs:ignore

		$id  = Monarch_Util::rand_id( 16 );
		$job = array(
			'id'         => $id,
			'type'       => 'import',
			'archive'    => $file,
			'manifest'   => $m,
			'stage'      => $db ? 'db' : 'files',
			'tmp_prefix' => 'pbt' . Monarch_Util::rand_id( 4 ) . '_',
			'live'       => $wpdb->prefix,
			'caps'       => Monarch_DB_Import::server_caps(),
			'budget'     => (int) Monarch_Storage::settings()['time_budget'],
			'db'         => $db,
			'extra'      => $extra,
			'replace'    => array(
				'tables'  => null,
				'ti'      => 0,
				'cols'    => null,
				'last'    => null,
				'offset'  => 0,
				'batch'   => 200,
				'updated' => 0,
			),
			'files'      => array(
				'pos'     => $content,
				'cur'     => null,
				'count'   => 0,
				'bytes'   => 0,
				'start'   => $content,
				'skipped' => 0,
			),
			'skipped'    => array(),
			'error'      => null,
			'log'        => array(),
			'started'    => time(),
		);
		$caps = $job['caps'];
		Monarch_Log::add( $job, sprintf( 'Import started: %s → %s', $m['home'], home_url() ), 'step' );
		Monarch_Log::add( $job, sprintf( 'Package: %s · created %s · %s files · %s', basename( $file ), isset( $m['created'] ) ? $m['created'] : '?', Monarch_Log::num( isset( $m['files'] ) ? $m['files'] : 0 ), Monarch_Log::size( $m['archive_size'] ) ) );
		Monarch_Log::add( $job, sprintf( 'Source: WordPress %s · PHP %s · MySQL %s · prefix "%s"', isset( $m['wp_version'] ) ? $m['wp_version'] : '?', isset( $m['php_version'] ) ? $m['php_version'] : '?', isset( $m['mysql_version'] ) ? $m['mysql_version'] : '?', $m['prefix'] ) );
		Monarch_Log::add( $job, sprintf( 'This server: PHP %s · %s %s · prefix "%s" · max_allowed_packet %s · utf8mb4 %s', PHP_VERSION, false !== stripos( (string) $wpdb->db_server_info(), 'maria' ) ? 'MariaDB' : 'MySQL', $wpdb->db_version(), $wpdb->prefix, Monarch_Log::size( $caps['packet'] ), $caps['utf8mb4'] ? 'yes' : 'no' ) );
		if ( $db ) {
			Monarch_Log::add( $job, sprintf( 'Importing %s SQL statements (%s) into temporary tables "%s*" — your live site keeps running', Monarch_Log::num( $db['total'] ), Monarch_Log::size( $db['end'] - $db['pos'] ), $job['tmp_prefix'] ), 'step' );
		}
		Monarch_Storage::save_job( $job );
		return $job;
	}

	/**
	 * Run one slice.
	 *
	 * @param array $job Job (modified).
	 * @return array Progress.
	 */
	public static function step( &$job ) {
		if ( $job['error'] ) {
			return self::progress( $job );
		}
		switch ( $job['stage'] ) {
			case 'db':
				$fh = fopen( $job['archive'], 'rb' ); // phpcs:ignore
				$r  = Monarch_DB_Import::step( $job, $fh );
				fclose( $fh ); // phpcs:ignore
				if ( 'done' === $r ) {
					Monarch_Log::add( $job, sprintf( 'Database imported: %s statements%s', Monarch_Log::num( $job['db']['index'] ), $job['skipped'] ? ' (' . count( $job['skipped'] ) . ' skipped)' : '' ), 'ok' );
					$job['stage'] = 'replace';
					Monarch_Log::add( $job, 'Replacing old URLs and server paths (serialization-safe)…', 'step' );
				} elseif ( 'error' === $r ) {
					Monarch_Log::add( $job, sprintf( 'Paused at statement #%d — waiting for your decision (Retry / Skip / Cancel)', $job['error']['statement'] ), 'err' );
				}
				break;
			case 'replace':
				if ( self::replace( $job ) ) {
					Monarch_Log::add( $job, sprintf( 'URL & path replacement done: %s rows updated in %d tables', Monarch_Log::num( $job['replace']['updated'] ), is_array( $job['replace']['tables'] ) ? count( $job['replace']['tables'] ) : 0 ), 'ok' );
					$job['stage'] = 'files';
					Monarch_Log::add( $job, 'Restoring wp-content files…', 'step' );
				}
				break;
			case 'files':
				if ( self::files( $job ) ) {
					Monarch_Log::add( $job, sprintf( 'Files restored: %s files (%s)%s', Monarch_Log::num( $job['files']['count'] ), Monarch_Log::size( $job['files']['bytes'] ), $job['files']['skipped'] ? ', ' . Monarch_Log::num( $job['files']['skipped'] ) . ' skipped (Monarch itself)' : '' ), 'ok' );
					$job['stage'] = $job['db'] ? 'swap' : 'done';
					if ( $job['db'] ) {
						Monarch_Log::add( $job, 'Activating the imported database…', 'step' );
					}
				}
				break;
			case 'swap':
				self::swap( $job );
				break;
		}
		return self::progress( $job );
	}

	/**
	 * Progress payload.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function progress( $job ) {
		$stages = array( 'db', 'replace', 'files', 'swap', 'done' );
		$detail = '';
		$pct    = 0;
		switch ( $job['stage'] ) {
			case 'db':
				$p      = ( $job['db']['pos'] - ( $job['db']['end'] - $job['manifest']['db_size'] ) ) / max( 1, $job['manifest']['db_size'] );
				$pct    = 60 * min( 1, max( 0, $p ) );
				/* translators: 1: done, 2: total */
				$detail = sprintf( __( 'Statement %1$s of %2$s', 'monarch-site-migration' ), number_format_i18n( $job['db']['index'] ), number_format_i18n( $job['db']['total'] ) );
				break;
			case 'replace':
				$t      = is_array( $job['replace']['tables'] ) ? count( $job['replace']['tables'] ) : 1;
				$pct    = 60 + 10 * ( $job['replace']['ti'] / max( 1, $t ) );
				$detail = isset( $job['replace']['tables'][ $job['replace']['ti'] ] ) ? $job['replace']['tables'][ $job['replace']['ti'] ] : '';
				break;
			case 'files':
				$total  = max( 1, $job['manifest']['archive_size'] - $job['files']['start'] );
				$pct    = ( $job['db'] ? 70 : 0 ) + ( $job['db'] ? 27 : 97 ) * min( 1, ( $job['files']['pos'] - $job['files']['start'] ) / $total );
				/* translators: %s: count */
				$detail = sprintf( __( '%s files restored', 'monarch-site-migration' ), number_format_i18n( $job['files']['count'] ) );
				break;
			case 'swap':
				$pct = 98;
				break;
			case 'done':
				$pct = 100;
				break;
		}
		return array(
			'id'      => $job['id'],
			'stage'   => $job['stage'],
			'stages'  => $stages,
			'percent' => round( $pct, 1 ),
			'label'   => $detail,
			'log'     => Monarch_Log::since( $job ),
			'seq'     => isset( $job['log_seq'] ) ? $job['log_seq'] : 0,
			'stats'   => array(
				'statements' => $job['db'] ? Monarch_Log::num( $job['db']['index'] ) . ' / ' . Monarch_Log::num( $job['db']['total'] ) : '—',
				'tables'     => $job['db'] ? ( isset( $job['db']['tables_done'] ) ? $job['db']['tables_done'] : 0 ) . ' / ' . ( isset( $job['manifest']['tables'] ) ? count( $job['manifest']['tables'] ) : 0 ) : '—',
				'updated'    => Monarch_Log::num( $job['replace']['updated'] ),
				'files'      => Monarch_Log::num( $job['files']['count'] ) . ' / ' . Monarch_Log::num( isset( $job['manifest']['files'] ) ? $job['manifest']['files'] : 0 ),
			),
			'error'   => $job['error'],
			'done'    => 'done' === $job['stage'],
			'login'   => wp_login_url( admin_url( 'admin.php?page=monarch-site-migration' ) ),
			'source'  => $job['manifest']['home'],
		);
	}

	/**
	 * User decision after a DB error.
	 *
	 * @param array  $job    Job.
	 * @param string $action retry|skip.
	 */
	public static function resolve( &$job, $action ) {
		$job['caps'] = Monarch_DB_Import::server_caps(); // The host may have changed settings (e.g. max_allowed_packet).
		if ( 'skip' === $action && 'db' === $job['stage'] ) {
			$job['db']['skip'] = true;
			/* translators: %d: statement */
			Monarch_Export::log( $job, sprintf( __( 'Skipped statement #%d.', 'monarch-site-migration' ), $job['error']['statement'] ) );
		}
		$job['error'] = null;
	}

	/**
	 * Drop temporary tables (rollback before swap).
	 *
	 * @param array $job Job.
	 */
	public static function rollback( $job ) {
		global $wpdb;
		if ( empty( $job['tmp_prefix'] ) || 'done' === $job['stage'] ) {
			return;
		}
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $job['tmp_prefix'] ) . '%' ) ); // phpcs:ignore
		foreach ( (array) $tables as $t ) {
			Monarch_Util::raw_query( 'DROP TABLE IF EXISTS ' . Monarch_Util::ident( $t ) );
		}
	}

	/**
	 * Search & replace on the temporary tables, page by page.
	 *
	 * @param array $job Job.
	 * @return bool Done.
	 */
	private static function replace( &$job ) {
		global $wpdb;
		list( $pairs, $needles ) = Monarch_Replace::build( $job['manifest'], $job['extra'] );
		$rep = new Monarch_Replace( $pairs, $needles );
		$r   = &$job['replace'];
		if ( ! $rep->active() ) {
			Monarch_Log::add( $job, 'Same URL and paths as the source — nothing to replace' );
			return true;
		}
		if ( null === $r['tables'] ) {
			$r['tables'] = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $job['tmp_prefix'] ) . '%' ) ); // phpcs:ignore
			$m           = $job['manifest'];
			$shown       = array();
			foreach ( array( array( $m['home'], home_url() ), array( $m['siteurl'], site_url() ), array( isset( $m['abspath'] ) ? $m['abspath'] : '', Monarch_Util::norm( ABSPATH ) ), array( isset( $m['content_dir'] ) ? $m['content_dir'] : '', Monarch_Util::norm( WP_CONTENT_DIR ) ) ) as $p ) {
				$k = untrailingslashit( $p[0] ) . '→' . untrailingslashit( $p[1] );
				if ( '' !== $p[0] && untrailingslashit( $p[0] ) !== untrailingslashit( $p[1] ) && ! isset( $shown[ $k ] ) ) {
					$shown[ $k ] = 1;
					Monarch_Log::add( $job, sprintf( 'Replace "%s" → "%s" (plus https/http, //, JSON-escaped and URL-encoded variants)', untrailingslashit( $p[0] ), untrailingslashit( $p[1] ) ) );
				}
			}
			foreach ( (array) $job['extra'] as $e ) {
				Monarch_Log::add( $job, sprintf( 'Custom replace "%s" → "%s"', $e[0], $e[1] ) );
			}
		}
		$req_scanned = 0;
		while ( ! Monarch_Util::out_of_time() ) {
			if ( $r['ti'] >= count( $r['tables'] ) ) {
				return true;
			}
			$table = $r['tables'][ $r['ti'] ];
			if ( null === $r['cols'] ) {
				$r['cols']      = self::replace_columns( $table );
				$r['last']      = null;
				$r['offset']    = 0;
				$r['t_scanned'] = 0;
				$r['t_updated'] = 0;
			}
			$c = $r['cols'];
			if ( empty( $c['text'] ) || empty( $c['pk'] ) ) {
				self::next_table( $r );
				continue;
			}
			$select = array_unique( array_merge( $c['pk'], $c['text'] ) );
			$sql    = 'SELECT ' . implode( ',', array_map( array( 'Monarch_Util', 'ident' ), $select ) ) . ' FROM ' . Monarch_Util::ident( $table );
			$limit  = (int) $r['batch'];
			if ( $c['keyset'] ) {
				$pk    = Monarch_Util::ident( $c['pk'][0] );
				$where = null === $r['last'] ? '' : ' WHERE ' . $pk . " > '" . Monarch_Util::esc_sql_value( $r['last'] ) . "'";
				$sql  .= $where . ' ORDER BY ' . $pk . ' LIMIT ' . $limit;
			} elseif ( ! empty( $c['fullrow'] ) ) {
				// No key: rely on the table's natural (insertion) order, which UPDATEs don't change.
				$sql .= ' LIMIT ' . (int) $r['offset'] . ', ' . $limit;
			} else {
				$sql .= ' ORDER BY ' . implode( ',', array_map( array( 'Monarch_Util', 'ident' ), $c['pk'] ) ) . ' LIMIT ' . (int) $r['offset'] . ', ' . $limit;
			}
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
			if ( empty( $rows ) ) {
				self::replace_table_done( $job, $table );
				self::next_table( $r );
				continue;
			}
			$bytes = 0;
			foreach ( $rows as $row ) {
				$set = array();
				foreach ( $c['text'] as $col ) {
					$v = $row[ $col ];
					if ( null === $v ) {
						continue;
					}
					$bytes += strlen( $v );
					$nv     = $rep->value( $v );
					if ( $nv !== $v ) {
						$set[] = Monarch_Util::ident( $col ) . " = '" . Monarch_Util::esc_sql_value( $nv ) . "'";
					}
				}
				if ( $set ) {
					$w = array();
					foreach ( $c['pk'] as $k ) {
						// NULL-safe, byte-exact match (works for binary columns too).
						$w[] = null === $row[ $k ] ? Monarch_Util::ident( $k ) . ' IS NULL' : Monarch_Util::ident( $k ) . " = UNHEX('" . bin2hex( $row[ $k ] ) . "')";
					}
					Monarch_Util::raw_query( 'UPDATE ' . Monarch_Util::ident( $table ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $w ) . ' LIMIT 1' );
					$r['updated']++;
					$r['t_updated']++;
				}
				if ( $c['keyset'] ) {
					$r['last'] = $row[ $c['pk'][0] ];
				}
			}
			$n               = count( $rows );
			$r['offset']    += $n;
			$r['t_scanned'] += $n;
			$req_scanned    += $n;
			$r['batch']      = (int) max( 10, min( 1000, 2097152 / max( 1, $bytes / $n ) ) );
			if ( $n < $limit ) {
				self::replace_table_done( $job, $table );
				self::next_table( $r );
				$req_scanned = 0;
			}
		}
		if ( $req_scanned > 0 && null !== $r['cols'] && isset( $r['tables'][ $r['ti'] ] ) ) {
			Monarch_Log::add( $job, sprintf( '%s — %s rows scanned, %s updated so far', self::live_name( $job, $r['tables'][ $r['ti'] ] ), Monarch_Log::num( $r['t_scanned'] ), Monarch_Log::num( $r['t_updated'] ) ) );
		}
		return false;
	}

	/**
	 * Show a temp table under the name it will have after the swap.
	 *
	 * @param array  $job   Job.
	 * @param string $table Temp table.
	 * @return string
	 */
	private static function live_name( $job, $table ) {
		return $job['live'] . substr( $table, strlen( $job['tmp_prefix'] ) );
	}

	/**
	 * Log a table finished by search & replace.
	 *
	 * @param array  $job   Job.
	 * @param string $table Temp table.
	 */
	private static function replace_table_done( &$job, $table ) {
		$r = $job['replace'];
		Monarch_Log::add(
			$job,
			sprintf( '%s — %s rows scanned, %s updated', self::live_name( $job, $table ), Monarch_Log::num( $r['t_scanned'] ), Monarch_Log::num( $r['t_updated'] ) ),
			$r['t_updated'] > 0 ? 'ok' : 'info'
		);
	}

	/**
	 * Move to the next table.
	 *
	 * @param array $r Replace state.
	 */
	private static function next_table( &$r ) {
		$r['ti']++;
		$r['cols']   = null;
		$r['last']   = null;
		$r['offset'] = 0;
		$r['batch']  = 200;
	}

	/**
	 * Text columns and key for a table.
	 *
	 * @param string $table Table.
	 * @return array
	 */
	private static function replace_columns( $table ) {
		global $wpdb;
		$text    = array();
		$pk      = array();
		$uniq    = array();
		$numeric = false;
		foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . Monarch_Util::ident( $table ), ARRAY_A ) as $col ) { // phpcs:ignore
			$type = strtolower( $col['Type'] );
			if ( 'PRI' === $col['Key'] ) {
				$pk[]    = $col['Field'];
				$numeric = (bool) preg_match( '/int/', $type );
			}
			if ( 'guid' === $col['Field'] ) {
				continue;
			}
			if ( preg_match( '/(char|text|json)/', $type ) ) {
				$text[] = $col['Field'];
			}
		}
		$fullrow = false;
		if ( empty( $pk ) ) {
			// Fall back to the first UNIQUE index.
			$keys = $wpdb->get_results( 'SHOW KEYS FROM ' . Monarch_Util::ident( $table ) . ' WHERE Non_unique = 0', ARRAY_A ); // phpcs:ignore
			foreach ( (array) $keys as $k ) {
				$uniq[ $k['Key_name'] ][] = $k['Column_name'];
			}
			if ( $uniq ) {
				$pk = reset( $uniq );
			}
		}
		if ( empty( $pk ) ) {
			// No key at all: identify rows by every column's original value.
			$fullrow = true;
			foreach ( (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . Monarch_Util::ident( $table ) ) as $c ) { // phpcs:ignore
				$pk[] = $c;
			}
		}
		return array(
			'text'    => $text,
			'pk'      => $pk,
			'fullrow' => $fullrow,
			'keyset'  => 1 === count( $pk ) && $numeric && empty( $uniq ) && ! $fullrow,
		);
	}

	/**
	 * Extract wp-content files, resumable mid-file.
	 *
	 * @param array $job Job.
	 * @return bool Done.
	 */
	private static function files( &$job ) {
		$f       = &$job['files'];
		$fh      = fopen( $job['archive'], 'rb' ); // phpcs:ignore
		$root    = Monarch_Util::norm( WP_CONTENT_DIR );
		$self    = 'plugins/' . dirname( MONARCH_BASENAME ) . '/';
		$storage = basename( Monarch_Storage::root() );
		$done     = false;
		$lastdir  = '';
		$c_before = $f['count'];
		$b_before = $f['bytes'];
		$last_rel = '';
		while ( ! Monarch_Util::out_of_time() ) {
			if ( null === $f['cur'] ) {
				fseek( $fh, $f['pos'] );
				$h = Monarch_Archive::read_header( $fh );
				if ( ! $h ) {
					throw new Exception( esc_html( 'The archive is corrupted near byte ' . $f['pos'] . '.' ) );
				}
				if ( Monarch_Archive::END === $h['name'] ) {
					$done = true;
					break;
				}
				$target = null;
				$rel    = Monarch_Archive::safe_path( $h['name'] );
				if ( $rel && 0 === strpos( $rel, 'content/' ) ) {
					$rel = substr( $rel, 8 );
					if ( 0 !== strpos( $rel, $self ) && 0 !== strpos( $rel, $storage ) && 0 !== strpos( $rel, 'monarch-storage' ) && 0 !== strpos( $rel, 'probackup-storage' ) ) {
						$target = $root . '/' . $rel;
					}
				}
				if ( null === $target ) {
					$f['skipped']++;
				}
				$f['cur'] = array(
					'target' => $target,
					'size'   => $h['size'],
					'done'   => 0,
					'data'   => ftell( $fh ),
					'mtime'  => $h['mtime'],
				);
			}
			$cur = &$f['cur'];
			if ( $cur['target'] && $cur['size'] >= 0 ) {
				$dir = dirname( $cur['target'] );
				if ( $dir !== $lastdir ) {
					if ( ! is_dir( $dir ) ) {
						wp_mkdir_p( $dir );
					}
					$lastdir = $dir;
				}
				$out = @fopen( $cur['target'], 'c+b' ); // phpcs:ignore
				if ( ! $out ) {
					Monarch_Log::add( $job, sprintf( 'Could not write %s — check folder permissions (skipped)', str_replace( $root . '/', '', $cur['target'] ) ), 'warn' );
					$cur['target'] = null;
				} else {
					$last_rel = str_replace( $root . '/', '', $cur['target'] );
					ftruncate( $out, $cur['done'] );
					fseek( $out, $cur['done'] );
					fseek( $fh, $cur['data'] + $cur['done'] );
					while ( $cur['done'] < $cur['size'] ) {
						$chunk = fread( $fh, (int) min( Monarch_Archive::PIECE, $cur['size'] - $cur['done'] ) ); // phpcs:ignore
						if ( false === $chunk || '' === $chunk ) {
							fclose( $out ); // phpcs:ignore
							throw new Exception( esc_html( 'Unexpected end of archive.' ) );
						}
						fwrite( $out, $chunk ); // phpcs:ignore
						$cur['done']  += strlen( $chunk );
						$f['bytes']   += strlen( $chunk );
						if ( $cur['done'] < $cur['size'] && Monarch_Util::out_of_time() ) {
							break;
						}
					}
					fclose( $out ); // phpcs:ignore
					if ( $cur['done'] < $cur['size'] ) {
						Monarch_Log::add( $job, sprintf( 'Restoring large file %s… %d%% (%s of %s)', $last_rel, floor( $cur['done'] / max( 1, $cur['size'] ) * 100 ), Monarch_Log::size( $cur['done'] ), Monarch_Log::size( $cur['size'] ) ) );
						break; // Resume this file next request.
					}
					if ( $cur['size'] >= 20971520 ) {
						Monarch_Log::add( $job, sprintf( 'Restored large file %s (%s)', $last_rel, Monarch_Log::size( $cur['size'] ) ) );
					}
					if ( $cur['mtime'] ) {
						@touch( $cur['target'], $cur['mtime'] ); // phpcs:ignore
					}
					$f['count']++;
				}
			}
			$f['pos'] = $cur['data'] + $cur['size'];
			unset( $cur );
			$f['cur'] = null;
		}
		fclose( $fh ); // phpcs:ignore
		if ( $f['count'] > $c_before ) {
			Monarch_Log::add( $job, sprintf( 'Restored %s files (%s) · %s / %s total · last: %s', Monarch_Log::num( $f['count'] - $c_before ), Monarch_Log::size( $f['bytes'] - $b_before ), Monarch_Log::num( $f['count'] ), Monarch_Log::num( isset( $job['manifest']['files'] ) ? $job['manifest']['files'] : 0 ), $last_rel ) );
		}
		if ( function_exists( 'opcache_reset' ) && $done ) {
			@opcache_reset(); // phpcs:ignore
		}
		return $done;
	}

	/**
	 * Final step: fix prefix-dependent rows, keep this site's URL and Monarch
	 * settings, then atomically swap the temporary tables in.
	 *
	 * @param array $job Job.
	 */
	private static function swap( &$job ) {
		global $wpdb;
		$tmp  = $job['tmp_prefix'];
		$live = $job['live'];
		$src  = $job['manifest']['prefix'];
		$opt  = Monarch_Util::ident( $tmp . 'options' );
		$um   = Monarch_Util::ident( $tmp . 'usermeta' );
		$has  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tmp ) . '%' ) ); // phpcs:ignore

		if ( ! in_array( $tmp . 'options', $has, true ) ) {
			$job['error'] = array(
				'stage'   => 'swap',
				'message' => __( 'The backup does not contain an options table, so it cannot be activated.', 'monarch-site-migration' ),
			);
			return;
		}

		if ( $src !== $live ) {
			Monarch_Util::raw_query( "UPDATE $opt SET option_name = '" . Monarch_Util::esc_sql_value( $live . 'user_roles' ) . "' WHERE option_name = '" . Monarch_Util::esc_sql_value( $src . 'user_roles' ) . "'" );
			if ( in_array( $tmp . 'usermeta', $has, true ) ) {
				$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $um WHERE meta_key LIKE '" . Monarch_Util::esc_sql_value( Monarch_Util::esc_like( $src ) ) . "%'" ); // phpcs:ignore
				Monarch_Util::raw_query( "UPDATE $um SET meta_key = CONCAT('" . Monarch_Util::esc_sql_value( $live ) . "', SUBSTRING(meta_key, " . ( strlen( $src ) + 1 ) . ")) WHERE meta_key LIKE '" . Monarch_Util::esc_sql_value( Monarch_Util::esc_like( $src ) ) . "%'" );
				Monarch_Log::add( $job, sprintf( 'Table prefix changed "%s" → "%s": renamed user_roles and %s user meta keys (capabilities, user level, …)', $src, $live, Monarch_Log::num( $n ) ) );
			}
		}
		Monarch_Log::add( $job, sprintf( 'Kept this site\'s address (%s) and Monarch settings; cleared caches, cron and rewrite rules', home_url() ) );

		// Keep this site's address and Monarch's own settings.
		$keep = array(
			'siteurl'                    => get_option( 'siteurl' ),
			'home'                       => get_option( 'home' ),
			Monarch_Storage::OPTION_DIR      => get_option( Monarch_Storage::OPTION_DIR ),
			Monarch_Storage::OPTION_SETTINGS => maybe_serialize( Monarch_Storage::settings() ),
		);
		foreach ( $keep as $name => $value ) {
			Monarch_Util::raw_query( "DELETE FROM $opt WHERE option_name = '" . Monarch_Util::esc_sql_value( $name ) . "'" );
			Monarch_Util::raw_query( "INSERT INTO $opt (option_name, option_value, autoload) VALUES ('" . Monarch_Util::esc_sql_value( $name ) . "', '" . Monarch_Util::esc_sql_value( $value ) . "', 'yes')" );
		}
		Monarch_Util::raw_query( "DELETE FROM $opt WHERE option_name IN ('rewrite_rules','cron') OR option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );

		// Make sure Monarch stays active.
		$active = $wpdb->get_var( "SELECT option_value FROM $opt WHERE option_name = 'active_plugins'" ); // phpcs:ignore
		$list   = is_string( $active ) ? @unserialize( $active, array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore
		$list   = is_array( $list ) ? array_values( array_diff( $list, array( 'probackup/probackup.php', 'monarch-migration/monarch-migration.php' ) ) ) : array(); // Drop the plugin's former names.
		if ( ! in_array( MONARCH_BASENAME, $list, true ) ) {
			$list[] = MONARCH_BASENAME;
			Monarch_Util::raw_query( "DELETE FROM $opt WHERE option_name = 'active_plugins'" );
			Monarch_Util::raw_query( "INSERT INTO $opt (option_name, option_value, autoload) VALUES ('active_plugins', '" . Monarch_Util::esc_sql_value( serialize( $list ) ) . "', 'yes')" ); // phpcs:ignore
		}

		// Atomic swap.
		$old_prefix = 'pbo' . Monarch_Util::rand_id( 4 ) . '_';
		$live_list  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $live ) . '%' ) ); // phpcs:ignore
		$renames    = array();
		$olds       = array();
		foreach ( $live_list as $t ) {
			$short = substr( $t, strlen( $live ) );
			$new   = $old_prefix . $short;
			if ( strlen( $new ) > 64 ) {
				$new = $old_prefix . substr( md5( $short ), 0, 24 );
			}
			$renames[] = Monarch_Util::ident( $t ) . ' TO ' . Monarch_Util::ident( $new );
			$olds[]    = $new;
		}
		foreach ( $has as $t ) {
			$renames[] = Monarch_Util::ident( $t ) . ' TO ' . Monarch_Util::ident( $live . substr( $t, strlen( $tmp ) ) );
		}
		$t0               = microtime( true );
		list( $ok, $err ) = Monarch_Util::raw_query( 'RENAME TABLE ' . implode( ', ', $renames ) );
		if ( ! $ok ) {
			Monarch_Log::add( $job, 'Atomic table swap failed: ' . $err . ' — your live site was not changed', 'err' );
			$job['error'] = array(
				'stage'   => 'swap',
				'message' => __( 'Could not activate the imported tables: ', 'monarch-site-migration' ) . $err,
			);
			return;
		}
		Monarch_Log::add( $job, sprintf( 'Atomic swap in %s ms: %d old tables moved aside, %d imported tables activated', round( ( microtime( true ) - $t0 ) * 1000 ), count( $olds ), count( $has ) ), 'ok' );
		foreach ( $olds as $t ) {
			Monarch_Util::raw_query( 'DROP TABLE IF EXISTS ' . Monarch_Util::ident( $t ) );
		}
		Monarch_Log::add( $job, sprintf( 'Dropped %d previous tables', count( $olds ) ) );
		wp_cache_flush();
		$job['stage'] = 'done';
		Monarch_Log::add( $job, sprintf( 'Migration complete in %s. Log in with the SOURCE site\'s username and password.', human_time_diff( $job['started'], time() ) ), 'ok' );
	}
}
