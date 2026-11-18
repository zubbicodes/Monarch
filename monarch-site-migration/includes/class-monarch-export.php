<?php
/**
 * Export step machine: database -> scan files -> build archive.
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporter.
 */
class Monarch_Export {

	/**
	 * Top level wp-content entries that are never exported.
	 *
	 * @var array
	 */
	private static $always_skip = array(
		'cache',
		'upgrade',
		'upgrade-temp-backup',
		'ai1wm-backups',
		'updraft',
		'backups-dup-lite',
		'backups-dup-pro',
		'wpvividbackups',
		'backup-guard',
		'backwpup-logs',
		'et-cache',
		'litespeed',
		'wflogs',
		'debug.log',
		'object-cache.php',
		'advanced-cache.php',
		'db.php',
		'db-error.php',
	);

	/**
	 * Start a new export job.
	 *
	 * @param array $opts Options from the UI.
	 * @return array Job.
	 */
	public static function start( $opts ) {
		$o = array(
			'database'      => ! empty( $opts['database'] ),
			'uploads'       => ! empty( $opts['uploads'] ),
			'plugins'       => ! empty( $opts['plugins'] ),
			'themes'        => ! empty( $opts['themes'] ),
			'muplugins'     => ! empty( $opts['muplugins'] ),
			'other'         => ! empty( $opts['other'] ),
			'no_spam'       => ! empty( $opts['no_spam'] ),
			'no_revisions'  => ! empty( $opts['no_revisions'] ),
			'no_transients' => ! empty( $opts['no_transients'] ),
		);
		$host = preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$name = sanitize_file_name( $host . '-' . gmdate( 'Ymd-His' ) . '-' . Monarch_Util::rand_id( 4 ) . '.pbk' );
		$id   = Monarch_Util::rand_id( 16 );
		$job  = array(
			'id'      => $id,
			'type'    => 'export',
			'opts'    => $o,
			'stage'   => $o['database'] ? 'db' : 'scan',
			'name'    => $name,
			'started' => time(),
			'log'     => array(),
			'db'      => $o['database'] ? Monarch_DB_Dump::init( $o ) : null,
			'scan'    => array(
				'stack'     => array( '' ),
				'list_size' => 0,
				'files'     => 0,
				'bytes'     => 0,
			),
			'arc'     => array(
				'size'   => 0,
				'stage'  => 'meta',
				'pos'    => 0,
				'cursor' => null,
				'files'  => 0,
				'bytes'  => 0,
			),
		);
		global $wpdb, $wp_version;
		self::log( $job, sprintf( 'Export started for %s', home_url() ), 'step' );
		self::log( $job, sprintf( 'Environment: WordPress %s · PHP %s · %s %s · table prefix "%s"', $wp_version, PHP_VERSION, false !== stripos( (string) $wpdb->db_server_info(), 'maria' ) ? 'MariaDB' : 'MySQL', $wpdb->db_version(), $wpdb->prefix ) );
		$inc = array();
		foreach ( array( 'database' => 'database', 'uploads' => 'uploads', 'plugins' => 'plugins', 'themes' => 'themes', 'muplugins' => 'must-use plugins', 'other' => 'other wp-content folders' ) as $k => $label ) {
			if ( $o[ $k ] ) {
				$inc[] = $label;
			}
		}
		self::log( $job, 'Including: ' . implode( ', ', $inc ) );
		$skip = array();
		foreach ( array( 'no_spam' => 'spam/trashed comments', 'no_revisions' => 'post revisions', 'no_transients' => 'transients' ) as $k => $label ) {
			if ( $o[ $k ] && $o['database'] ) {
				$skip[] = $label;
			}
		}
		if ( $skip ) {
			self::log( $job, 'Skipping: ' . implode( ', ', $skip ) );
		}
		self::log( $job, 'Time budget per step: ' . (float) Monarch_Storage::settings()['time_budget'] . 's · memory limit ' . ini_get( 'memory_limit' ) );
		if ( $o['database'] ) {
			$rows = 0;
			foreach ( $job['db']['tables'] as $t ) {
				$rows += $t['rows'];
			}
			self::log( $job, sprintf( 'Found %d tables (~%s rows) to export', count( $job['db']['tables'] ), Monarch_Log::num( $rows ) ), 'step' );
		}
		Monarch_Storage::save_job( $job );
		return $job;
	}

	/**
	 * Append to the job log (keeps the last 60 lines).
	 *
	 * @param array  $job Job.
	 * @param string $msg Message.
	 */
	public static function log( &$job, $msg, $level = 'info' ) {
		Monarch_Log::add( $job, $msg, $level );
	}

	/**
	 * Run one slice.
	 *
	 * @param array $job Job (modified).
	 * @return array Progress payload.
	 */
	public static function step( &$job ) {
		$dir = Monarch_Storage::job_dir( $job['id'] );
		switch ( $job['stage'] ) {
			case 'db':
				if ( Monarch_DB_Dump::step( $job, $dir . '/database.sql' ) ) {
					self::log( $job, sprintf( 'Database exported: %s rows in %s SQL statements (%s)', Monarch_Log::num( $job['db']['rows_done'] ), Monarch_Log::num( $job['db']['stmts'] ), Monarch_Log::size( $job['db']['sql_size'] ) ), 'ok' );
					$job['stage'] = 'scan';
					self::log( $job, 'Scanning wp-content for files…', 'step' );
				}
				break;
			case 'scan':
				$before = $job['scan']['files'];
				if ( self::scan( $job, $dir . '/files.txt' ) ) {
					self::log( $job, sprintf( 'File scan complete: %s files, %s', Monarch_Log::num( $job['scan']['files'] ), Monarch_Log::size( $job['scan']['bytes'] ) ), 'ok' );
					$job['stage'] = 'archive';
					self::log( $job, 'Building the .pbk package…', 'step' );
				} elseif ( $job['scan']['files'] > $before ) {
					self::log( $job, sprintf( 'Scanning… %s files so far (%s), %d folders queued', Monarch_Log::num( $job['scan']['files'] ), Monarch_Log::size( $job['scan']['bytes'] ), count( $job['scan']['stack'] ) ) );
				}
				break;
			case 'archive':
				if ( self::archive( $job, $dir ) ) {
					$job['stage'] = 'done';
					rename( $dir . '/' . $job['name'] . '.part', Monarch_Storage::dir( 'backups' ) . '/' . $job['name'] ); // phpcs:ignore
					self::log( $job, sprintf( 'Package ready: %s (%s) in %s', $job['name'], Monarch_Log::size( $job['arc']['size'] ), human_time_diff( $job['started'], time() ) ), 'ok' );
					Monarch_Storage::prune();
				}
				break;
		}
		return self::progress( $job );
	}

	/**
	 * Build progress payload for the UI.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function progress( $job ) {
		$pct   = 0;
		$label = '';
		$o     = $job['opts'];
		$w_db  = $o['database'] ? 40 : 0;
		$w_sc  = 5;
		$w_ar  = 100 - $w_db - $w_sc;
		switch ( $job['stage'] ) {
			case 'db':
				$p     = $job['db']['rows_done'] / Monarch_DB_Dump::total_rows( $job['db'] );
				$pct   = $w_db * min( 1, $p );
				$table = isset( $job['db']['tables'][ $job['db']['ti'] ] ) ? $job['db']['tables'][ $job['db']['ti'] ]['name'] : '';
				/* translators: %s: table name */
				$label = sprintf( __( 'Exporting database — %s', 'monarch-site-migration' ), $table );
				break;
			case 'scan':
				$pct = $w_db + 2;
				/* translators: %s: count */
				$label = sprintf( __( 'Scanning files — %s found', 'monarch-site-migration' ), number_format_i18n( $job['scan']['files'] ) );
				break;
			case 'archive':
				$total = max( 1, $job['scan']['bytes'] + ( $o['database'] ? $job['db']['sql_size'] : 0 ) );
				$pct   = $w_db + $w_sc + $w_ar * min( 1, $job['arc']['bytes'] / $total );
				/* translators: 1: done count, 2: total count */
				$label = sprintf( __( 'Packing files — %1$s / %2$s', 'monarch-site-migration' ), number_format_i18n( $job['arc']['files'] ), number_format_i18n( $job['scan']['files'] ) );
				break;
			case 'done':
				$pct   = 100;
				$label = __( 'Export complete', 'monarch-site-migration' );
				break;
		}
		return array(
			'id'      => $job['id'],
			'stage'   => $job['stage'],
			'percent' => round( $pct, 1 ),
			'label'   => $label,
			'log'     => Monarch_Log::since( $job ),
			'seq'     => isset( $job['log_seq'] ) ? $job['log_seq'] : 0,
			'stats'   => array(
				'tables'      => $job['db'] ? min( $job['db']['ti'], count( $job['db']['tables'] ) ) . ' / ' . count( $job['db']['tables'] ) : '—',
				'rows'        => $job['db'] ? Monarch_Log::num( $job['db']['rows_done'] ) : '—',
				'files'       => Monarch_Log::num( $job['arc']['files'] ) . ' / ' . Monarch_Log::num( $job['scan']['files'] ),
				'package'     => Monarch_Log::size( $job['arc']['size'] ),
			),
			'done'    => 'done' === $job['stage'],
			'file'    => 'done' === $job['stage'] ? $job['name'] : '',
			'size'    => 'done' === $job['stage'] ? size_format( $job['arc']['size'], 1 ) : '',
		);
	}

	/**
	 * Is a wp-content relative path included by the options?
	 *
	 * @param string $rel  Relative path (no leading slash).
	 * @param bool   $is_dir Directory.
	 * @param array  $o    Options.
	 * @return bool
	 */
	private static function included( $rel, $is_dir, $o ) {
		$parts = explode( '/', $rel );
		$top   = $parts[0];
		if ( in_array( $top, self::$always_skip, true ) ) {
			return false;
		}
		$storage = basename( Monarch_Storage::root() );
		if ( $top === $storage || 0 === strpos( $top, 'monarch-storage' ) || 0 === strpos( $top, 'probackup-storage' ) ) {
			return false;
		}
		if ( ! $is_dir && preg_match( '/\.(log|pbk|wpress|tmp)$/i', $rel ) ) {
			return false;
		}
		$base = end( $parts );
		if ( in_array( $base, array( '.git', '.svn', 'node_modules', '.DS_Store', 'error_log' ), true ) ) {
			return false;
		}
		switch ( $top ) {
			case 'uploads':
				return $o['uploads'];
			case 'plugins':
				if ( isset( $parts[1] ) && dirname( MONARCH_BASENAME ) === $parts[1] ) {
					return false; // The destination already has Monarch.
				}
				return $o['plugins'];
			case 'themes':
				return $o['themes'];
			case 'mu-plugins':
				return $o['muplugins'];
			default:
				if ( 'index.php' === $rel ) {
					return false;
				}
				return $o['other'];
		}
	}

	/**
	 * Walk wp-content incrementally, writing "size\trelpath" lines to the list file.
	 *
	 * @param array  $job  Job.
	 * @param string $list List file.
	 * @return bool Done.
	 */
	private static function scan( &$job, $list ) {
		$root = Monarch_Util::norm( WP_CONTENT_DIR );
		$fh   = Monarch_Archive::open_append( $list, $job['scan']['list_size'] );
		$sc   = &$job['scan'];
		while ( ! empty( $sc['stack'] ) && ! Monarch_Util::out_of_time() ) {
			$rel  = array_pop( $sc['stack'] );
			$abs  = '' === $rel ? $root : $root . '/' . $rel;
			$list_items = @scandir( $abs ); // phpcs:ignore
			if ( false === $list_items ) {
				continue;
			}
			foreach ( $list_items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$r = '' === $rel ? $item : $rel . '/' . $item;
				$a = $abs . '/' . $item;
				if ( is_link( $a ) && is_dir( $a ) ) {
					continue;
				}
				$is_dir = is_dir( $a );
				if ( ! self::included( $r, $is_dir, $job['opts'] ) ) {
					continue;
				}
				if ( $is_dir ) {
					$sc['stack'][] = $r;
				} elseif ( is_file( $a ) && is_readable( $a ) ) {
					$size = Monarch_Util::filesize( $a );
					fwrite( $fh, $size . "\t" . $r . "\n" ); // phpcs:ignore
					$sc['files']++;
					$sc['bytes'] += $size;
				}
			}
		}
		fflush( $fh );
		$sc['list_size'] = ftell( $fh );
		fclose( $fh ); // phpcs:ignore
		return empty( $sc['stack'] );
	}

	/**
	 * Build the manifest.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	private static function manifest( $job ) {
		global $wpdb, $wp_version;
		$tables = array();
		if ( $job['db'] ) {
			foreach ( $job['db']['tables'] as $t ) {
				$tables[] = array(
					'name' => $t['short'],
					'rows' => $t['rows'],
				);
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			$plugins[] = $p;
		}
		return array(
			'generator'     => 'Monarch ' . MONARCH_VERSION,
			'created'       => gmdate( 'c' ),
			'siteurl'       => get_option( 'siteurl' ),
			'home'          => get_option( 'home' ),
			'abspath'       => Monarch_Util::norm( ABSPATH ),
			'content_dir'   => Monarch_Util::norm( WP_CONTENT_DIR ),
			'uploads_url'   => wp_upload_dir( null, false )['baseurl'],
			'wp_version'    => $wp_version,
			'php_version'   => PHP_VERSION,
			'mysql_version' => $wpdb->db_version(),
			'prefix'        => $wpdb->prefix,
			'charset'       => $wpdb->charset,
			'multisite'     => is_multisite(),
			'template'      => get_option( 'template' ),
			'stylesheet'    => get_option( 'stylesheet' ),
			'plugins'       => $plugins,
			'options'       => $job['opts'],
			'has_database'  => (bool) $job['db'],
			'tables'        => $tables,
			'statements'    => $job['db'] ? $job['db']['stmts'] : 0,
			'db_size'       => $job['db'] ? $job['db']['sql_size'] : 0,
			'files'         => $job['scan']['files'],
			'files_size'    => $job['scan']['bytes'],
		);
	}

	/**
	 * Write the archive: package.json, database.sql, then content files.
	 *
	 * @param array  $job Job.
	 * @param string $dir Job folder.
	 * @return bool Done.
	 */
	private static function archive( &$job, $dir ) {
		$file = $dir . '/' . $job['name'] . '.part';
		$a    = &$job['arc'];
		if ( 'meta' === $a['stage'] ) {
			Monarch_Archive::create( $file );
			$fh = Monarch_Archive::open_append( $file, 4 );
			Monarch_Archive::add_string( $fh, 'package.json', wp_json_encode( self::manifest( $job ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			fflush( $fh );
			$a['size']  = ftell( $fh );
			fclose( $fh ); // phpcs:ignore
			$a['stage'] = $job['db'] ? 'sql' : 'files';
			self::log( $job, 'Wrote package manifest (package.json)' );
			return false;
		}
		$files_before = $a['files'];
		$bytes_before = $a['bytes'];
		$last_rel     = '';
		$fh = Monarch_Archive::open_append( $file, $a['size'] );
		if ( ! $fh ) {
			throw new Exception( esc_html( 'Cannot write the archive. Check free disk space.' ) );
		}
		if ( 'sql' === $a['stage'] ) {
			$before = $a['cursor'] ? $a['cursor']['done'] : 0;
			$r      = Monarch_Archive::add_file_piece( $fh, $dir . '/database.sql', 'database.sql', $a['cursor'] );
			if ( true === $r ) {
				$a['bytes'] += $job['db']['sql_size'] - $before;
				$a['cursor'] = null;
				$a['stage']  = 'files';
				@unlink( $dir . '/database.sql' ); // phpcs:ignore
				self::log( $job, sprintf( 'Database added to package (%s)', Monarch_Log::size( $job['db']['sql_size'] ) ), 'ok' );
			} else {
				$a['bytes'] += $r['done'] - $before;
				$a['cursor'] = $r;
				self::log( $job, sprintf( 'Adding database to package… %s of %s', Monarch_Log::size( $r['done'] ), Monarch_Log::size( $r['size'] ) ) );
			}
		}
		if ( 'files' === $a['stage'] ) {
			$root = Monarch_Util::norm( WP_CONTENT_DIR );
			$list = @fopen( $dir . '/files.txt', 'rb' ); // phpcs:ignore
			if ( $list ) {
				fseek( $list, $a['pos'] );
				while ( ! Monarch_Util::out_of_time() ) {
					$line_start = ftell( $list );
					$line       = fgets( $list );
					if ( false === $line ) {
						$a['stage'] = 'end';
						break;
					}
					$line = rtrim( $line, "\n" );
					$tab  = strpos( $line, "\t" );
					if ( false === $tab ) {
						$a['pos'] = ftell( $list );
						continue;
					}
					$rel    = substr( $line, $tab + 1 );
					$before = $a['cursor'] ? $a['cursor']['done'] : 0;
					$r      = Monarch_Archive::add_file_piece( $fh, $root . '/' . $rel, 'content/' . $rel, $a['cursor'] );
					$last_rel = $rel;
					if ( true === $r ) {
						$fsize        = (int) substr( $line, 0, $tab );
						$a['bytes']  += max( 0, $fsize - $before );
						$a['cursor']  = null;
						$a['files']++;
						$a['pos'] = ftell( $list );
						if ( $fsize >= 20971520 ) {
							self::log( $job, sprintf( 'Packed large file %s (%s)', $rel, Monarch_Log::size( $fsize ) ) );
						}
					} else {
						$a['bytes'] += $r['done'] - $before;
						$a['cursor'] = $r;
						$a['pos']    = $line_start;
						self::log( $job, sprintf( 'Packing large file %s… %d%% (%s of %s)', $rel, floor( $r['done'] / max( 1, $r['size'] ) * 100 ), Monarch_Log::size( $r['done'] ), Monarch_Log::size( $r['size'] ) ) );
						break;
					}
				}
				fclose( $list ); // phpcs:ignore
			} else {
				$a['stage'] = 'end';
			}
		}
		if ( $a['files'] > $files_before ) {
			self::log( $job, sprintf( 'Packed %s files (%s) · %s / %s total · last: %s', Monarch_Log::num( $a['files'] - $files_before ), Monarch_Log::size( $a['bytes'] - $bytes_before ), Monarch_Log::num( $a['files'] ), Monarch_Log::num( $job['scan']['files'] ), $last_rel ) );
		}
		if ( 'end' === $a['stage'] ) {
			Monarch_Archive::finish( $fh );
			self::log( $job, 'All files packed, archive sealed', 'ok' );
		}
		fflush( $fh );
		$a['size'] = ftell( $fh );
		fclose( $fh ); // phpcs:ignore
		return 'end' === $a['stage'];
	}
}
