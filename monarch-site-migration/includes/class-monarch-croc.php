<?php
/**
 * croc integration (https://github.com/schollz/croc).
 *
 * croc sends files end-to-end encrypted (PAKE) between two computers through a
 * relay, using a shared code phrase. Monarch runs croc in the background on
 * the server: the source site "sends" the .pbk, the destination "receives" it.
 * The code phrase is passed through the CROC_SECRET environment variable, so it
 * never appears in the process list.
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * croc runner.
 */
class Monarch_Croc {

	const VERSION = '11.6.5';

	/**
	 * Words for code phrases.
	 *
	 * @var array
	 */
	private static $words = array( 'amber', 'apple', 'arrow', 'atlas', 'bamboo', 'beacon', 'birch', 'bison', 'blue', 'breeze', 'canyon', 'cedar', 'cobalt', 'comet', 'coral', 'crane', 'delta', 'dune', 'eagle', 'ember', 'falcon', 'fern', 'forest', 'galaxy', 'garnet', 'glacier', 'harbor', 'hazel', 'island', 'ivory', 'jade', 'jaguar', 'koala', 'lagoon', 'lemon', 'lotus', 'maple', 'meadow', 'mango', 'nebula', 'ocean', 'olive', 'onyx', 'orbit', 'panda', 'pepper', 'pine', 'planet', 'quartz', 'raven', 'river', 'robin', 'saffron', 'sierra', 'silver', 'summit', 'tiger', 'topaz', 'tulip', 'velvet', 'violet', 'walnut', 'willow', 'zephyr' );

	/**
	 * Generate a strong code phrase like 4821-amber-tiger-ocean-maple.
	 *
	 * @return string
	 */
	public static function code() {
		$parts = array( (string) random_int( 1000, 9999 ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$parts[] = self::$words[ random_int( 0, count( self::$words ) - 1 ) ];
		}
		return implode( '-', $parts );
	}

	/**
	 * Validate a code phrase.
	 *
	 * @param string $code Code.
	 * @return string|false
	 */
	public static function valid_code( $code ) {
		$code = trim( (string) $code );
		return preg_match( '/^[A-Za-z0-9-]{6,64}$/', $code ) ? $code : false;
	}

	/**
	 * Is this Windows?
	 *
	 * @return bool
	 */
	private static function is_windows() {
		return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
	}

	/**
	 * Can PHP start processes?
	 *
	 * @return bool
	 */
	public static function can_exec() {
		return Monarch_Util::fn_enabled( 'proc_open' );
	}

	/**
	 * Release asset name for this server.
	 *
	 * @return string|false
	 */
	public static function asset() {
		$m = strtolower( php_uname( 'm' ) );
		if ( self::is_windows() ) {
			$os  = 'Windows';
			$ext = 'zip';
		} elseif ( 'Darwin' === PHP_OS ) {
			$os  = 'macOS';
			$ext = 'tar.gz';
		} elseif ( 'Linux' === PHP_OS ) {
			$os  = 'Linux';
			$ext = 'tar.gz';
		} elseif ( 'FreeBSD' === PHP_OS ) {
			$os  = 'FreeBSD';
			$ext = 'tar.gz';
		} else {
			return false;
		}
		if ( in_array( $m, array( 'x86_64', 'amd64' ), true ) ) {
			$arch = '64bit';
		} elseif ( in_array( $m, array( 'aarch64', 'arm64' ), true ) ) {
			$arch = 'ARM64';
		} elseif ( 0 === strpos( $m, 'armv7' ) || 0 === strpos( $m, 'armv6' ) ) {
			$arch = 'ARM';
		} elseif ( in_array( $m, array( 'i386', 'i686', 'x86' ), true ) ) {
			$arch = '32bit';
		} else {
			return false;
		}
		return 'croc_v' . self::VERSION . '_' . $os . '-' . $arch . '.' . $ext;
	}

	/**
	 * Path to the croc binary, or false.
	 *
	 * @return string|false
	 */
	public static function binary() {
		$custom = Monarch_Storage::settings()['croc_path'];
		if ( $custom && is_file( $custom ) ) {
			return $custom;
		}
		$own = Monarch_Storage::dir( 'bin' ) . '/' . ( self::is_windows() ? 'croc.exe' : 'croc' );
		return is_file( $own ) ? $own : false;
	}

	/**
	 * Environment status for the UI.
	 *
	 * @return array
	 */
	public static function status_info() {
		$bin = self::binary();
		return array(
			'exec'      => self::can_exec(),
			'installed' => (bool) $bin,
			'asset'     => self::asset(),
			'version'   => $bin ? self::version( $bin ) : '',
		);
	}

	/**
	 * Read `croc --version`.
	 *
	 * @param string $bin Binary.
	 * @return string
	 */
	private static function version( $bin ) {
		if ( ! self::can_exec() ) {
			return '';
		}
		$out = self::run_sync( escapeshellarg( $bin ) . ' --version' );
		return preg_match( '/v?\d+\.\d+\.\d+/', $out, $m ) ? $m[0] : '';
	}

	/**
	 * Run a short command and return its output.
	 *
	 * @param string $cmd Command (already escaped).
	 * @return string
	 */
	private static function run_sync( $cmd ) {
		$p = @proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes ); // phpcs:ignore
		if ( ! is_resource( $p ) ) {
			return '';
		}
		$out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore
		fclose( $pipes[2] ); // phpcs:ignore
		proc_close( $p );
		return (string) $out;
	}

	/**
	 * Download, verify (SHA-256) and install croc into storage/bin.
	 *
	 * @return true|WP_Error
	 */
	public static function install() {
		$asset = self::asset();
		if ( ! $asset ) {
			return new WP_Error( 'pb_croc_os', __( 'No croc build is available for this server (unknown OS/CPU). Set a custom croc path in Settings.', 'monarch-site-migration' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$base = 'https://github.com/schollz/croc/releases/download/v' . self::VERSION . '/';
		$sums = wp_remote_get( $base . 'croc_v' . self::VERSION . '_checksums.txt', array( 'timeout' => 30 ) );
		if ( is_wp_error( $sums ) || 200 !== wp_remote_retrieve_response_code( $sums ) ) {
			return new WP_Error( 'pb_croc_dl', __( 'Could not reach GitHub to download croc. Your host may block outgoing connections.', 'monarch-site-migration' ) );
		}
		$expected = '';
		foreach ( preg_split( '/\r?\n/', wp_remote_retrieve_body( $sums ) ) as $line ) {
			if ( preg_match( '/^([a-f0-9]{64})\s+\*?(\S+)$/', trim( $line ), $m ) && $m[2] === $asset ) {
				$expected = $m[1];
			}
		}
		if ( ! $expected ) {
			return new WP_Error( 'pb_croc_sum', __( 'Checksum for croc not found; refusing to install.', 'monarch-site-migration' ) );
		}
		$tmp = download_url( $base . $asset, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( ! hash_equals( $expected, hash_file( 'sha256', $tmp ) ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return new WP_Error( 'pb_croc_sum', __( 'croc download failed the SHA-256 check; it was deleted.', 'monarch-site-migration' ) );
		}
		$bin_dir = Monarch_Storage::dir( 'bin' );
		$name    = self::is_windows() ? 'croc.exe' : 'croc';
		$work    = $bin_dir . '/extract-' . Monarch_Util::rand_id( 6 );
		wp_mkdir_p( $work );
		$ok = false;
		try {
			if ( '.zip' === substr( $asset, -4 ) ) {
				if ( class_exists( 'ZipArchive' ) ) {
					$zip = new ZipArchive();
					if ( true === $zip->open( $tmp ) ) {
						$ok = $zip->extractTo( $work, $name );
						$zip->close();
					}
				}
			} else {
				$gz = $work . '/croc.tar.gz';
				copy( $tmp, $gz );
				if ( class_exists( 'PharData' ) ) {
					$phar = new PharData( $gz );
					$phar->decompress();
					$tar = new PharData( $work . '/croc.tar' );
					$ok  = $tar->extractTo( $work, $name, true );
				} elseif ( self::can_exec() ) {
					self::run_sync( 'tar -xzf ' . escapeshellarg( $gz ) . ' -C ' . escapeshellarg( $work ) . ' ' . escapeshellarg( $name ) );
					$ok = is_file( $work . '/' . $name );
				}
			}
		} catch ( Exception $e ) {
			$ok = false;
		}
		@unlink( $tmp ); // phpcs:ignore
		if ( ! $ok || ! is_file( $work . '/' . $name ) ) {
			Monarch_Storage::rrmdir( $work );
			return new WP_Error( 'pb_croc_extract', __( 'Could not extract croc (needs the Phar or Zip PHP extension).', 'monarch-site-migration' ) );
		}
		rename( $work . '/' . $name, $bin_dir . '/' . $name ); // phpcs:ignore
		@chmod( $bin_dir . '/' . $name, 0755 ); // phpcs:ignore
		Monarch_Storage::rrmdir( $work );
		return true;
	}

	/**
	 * Session file path.
	 *
	 * @param string $sid Session id.
	 * @return string
	 */
	private static function session_file( $sid ) {
		return Monarch_Storage::dir( 'croc' ) . '/' . preg_replace( '/[^a-z0-9]/', '', $sid ) . '.json';
	}

	/**
	 * Load a session.
	 *
	 * @param string $sid Session id.
	 * @return array|null
	 */
	public static function load( $sid ) {
		$f = self::session_file( $sid );
		if ( ! is_file( $f ) ) {
			return null;
		}
		$d = json_decode( (string) file_get_contents( $f ), true ); // phpcs:ignore
		return is_array( $d ) ? $d : null;
	}

	/**
	 * Save a session.
	 *
	 * @param array $s Session.
	 */
	private static function save( $s ) {
		file_put_contents( self::session_file( $s['id'] ), wp_json_encode( $s ) ); // phpcs:ignore
	}

	/**
	 * Global croc flags (relay etc.).
	 *
	 * @return string
	 */
	private static function global_flags() {
		$s     = Monarch_Storage::settings();
		$flags = ' --yes --ignore-stdin';
		if ( $s['croc_relay'] ) {
			$flags .= ' --relay ' . escapeshellarg( $s['croc_relay'] );
		}
		if ( $s['croc_pass'] ) {
			$flags .= ' --pass ' . escapeshellarg( $s['croc_pass'] );
		}
		return $flags;
	}

	/**
	 * Start croc in the background.
	 *
	 * @param string $args Escaped arguments after the binary.
	 * @param string $code Code phrase.
	 * @param string $log  Log file.
	 * @param string $cwd  Working dir.
	 * @return int|WP_Error PID (0 when unknown).
	 */
	private static function spawn( $args, $code, $log, $cwd ) {
		$bin = self::binary();
		if ( ! $bin ) {
			return new WP_Error( 'pb_croc_missing', __( 'croc is not installed yet.', 'monarch-site-migration' ) );
		}
		if ( ! self::can_exec() ) {
			return new WP_Error( 'pb_croc_exec', __( 'Your host disabled proc_open(), so croc cannot run on this server. Use the manual command or the upload option instead.', 'monarch-site-migration' ) );
		}
		$config = Monarch_Storage::dir( 'croc' ) . '/config';
		wp_mkdir_p( $config );
		$env = array(
			'CROC_SECRET'     => $code,
			'CROC_CONFIG_DIR' => $config,
			'HOME'            => $config,
			'PATH'            => getenv( 'PATH' ) ? getenv( 'PATH' ) : '/usr/local/bin:/usr/bin:/bin',
		);
		if ( self::is_windows() ) {
			$env['SystemRoot'] = getenv( 'SystemRoot' );
			$env['TEMP']       = getenv( 'TEMP' );
			$cmd               = 'start /B "" ' . escapeshellarg( $bin ) . ' ' . $args . ' > ' . escapeshellarg( $log ) . ' 2>&1';
		} else {
			$prefix = @is_executable( '/usr/bin/setsid' ) ? 'setsid ' : ''; // phpcs:ignore
			$cmd    = $prefix . 'nohup ' . escapeshellarg( $bin ) . ' ' . $args . ' > ' . escapeshellarg( $log ) . ' 2>&1 < /dev/null & echo $!';
		}
		$p = @proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, $env ); // phpcs:ignore
		if ( ! is_resource( $p ) ) {
			return new WP_Error( 'pb_croc_start', __( 'Could not start croc.', 'monarch-site-migration' ) );
		}
		fclose( $pipes[0] ); // phpcs:ignore
		$out = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] ); // phpcs:ignore
		fclose( $pipes[2] ); // phpcs:ignore
		proc_close( $p );
		return (int) trim( (string) $out );
	}

	/**
	 * Send a backup.
	 *
	 * @param string $name Backup name.
	 * @param string $code Optional code phrase.
	 * @return array|WP_Error Session.
	 */
	public static function send( $name, $code = '' ) {
		$path = Monarch_Storage::backup_path( $name );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'pb_nofile', __( 'Backup not found.', 'monarch-site-migration' ) );
		}
		$code = $code ? self::valid_code( $code ) : self::code();
		if ( ! $code ) {
			return new WP_Error( 'pb_code', __( 'The code phrase may only contain letters, numbers and dashes (6–64 characters).', 'monarch-site-migration' ) );
		}
		$sid = Monarch_Util::rand_id( 12 );
		$log = Monarch_Storage::dir( 'croc' ) . '/' . $sid . '.log';
		$pid = self::spawn( trim( self::global_flags() ) . ' send ' . escapeshellarg( basename( $path ) ), $code, $log, dirname( $path ) );
		if ( is_wp_error( $pid ) ) {
			return $pid;
		}
		$s = array(
			'id'      => $sid,
			'mode'    => 'send',
			'pid'     => $pid,
			'log'     => $log,
			'code'    => $code,
			'file'    => basename( $path ),
			'size'    => Monarch_Util::filesize( $path ),
			'started' => time(),
		);
		self::save( $s );
		return $s;
	}

	/**
	 * Receive a backup.
	 *
	 * @param string $code Code phrase.
	 * @return array|WP_Error Session.
	 */
	public static function receive( $code ) {
		$code = self::valid_code( $code );
		if ( ! $code ) {
			return new WP_Error( 'pb_code', __( 'Please enter a valid code phrase.', 'monarch-site-migration' ) );
		}
		$sid = Monarch_Util::rand_id( 12 );
		$out = Monarch_Storage::dir( 'incoming' ) . '/croc-' . $sid;
		wp_mkdir_p( $out );
		$log = Monarch_Storage::dir( 'croc' ) . '/' . $sid . '.log';
		$pid = self::spawn( trim( self::global_flags() ) . ' --overwrite --out ' . escapeshellarg( $out ), $code, $log, $out );
		if ( is_wp_error( $pid ) ) {
			return $pid;
		}
		$s = array(
			'id'      => $sid,
			'mode'    => 'receive',
			'pid'     => $pid,
			'log'     => $log,
			'out'     => $out,
			'started' => time(),
		);
		self::save( $s );
		return $s;
	}

	/**
	 * Is a PID alive?
	 *
	 * @param int $pid PID.
	 * @return bool|null Null when unknown.
	 */
	private static function alive( $pid ) {
		if ( $pid <= 0 ) {
			return null;
		}
		if ( function_exists( 'posix_kill' ) ) {
			return @posix_kill( $pid, 0 ); // phpcs:ignore
		}
		if ( is_dir( '/proc' ) ) {
			return is_dir( '/proc/' . $pid );
		}
		return null;
	}

	/**
	 * Poll a session.
	 *
	 * @param string $sid Session id.
	 * @return array|WP_Error
	 */
	public static function poll( $sid ) {
		$s = self::load( $sid );
		if ( ! $s ) {
			return new WP_Error( 'pb_sess', __( 'Transfer session not found.', 'monarch-site-migration' ) );
		}
		$log   = is_file( $s['log'] ) ? (string) file_get_contents( $s['log'], false, null, max( 0, Monarch_Util::filesize( $s['log'] ) - 8192 ) ) : ''; // phpcs:ignore
		$log   = str_replace( "\r", "\n", $log );
		$log   = preg_replace( '/\x1b\[[0-9;]*[A-Za-z]/', '', $log );
		$pct   = 0;
		if ( preg_match_all( '/(\d{1,3})%\s*\|/', $log, $m ) ) {
			$pct = (int) end( $m[1] );
		}
		$alive = self::alive( (int) $s['pid'] );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $log ) ) ) );
		$last  = $lines ? end( $lines ) : '';
		$state = 'waiting';
		if ( $pct > 0 ) {
			$state = 'transferring';
		}
		$file = '';
		if ( 'receive' === $s['mode'] ) {
			$found = glob( $s['out'] . '/*.pbk' );
			if ( $found && ( false === $alive || $pct >= 100 ) ) {
				$src  = $found[0];
				$name = Monarch_Storage::valid_name( basename( $src ) );
				if ( ! $name ) {
					$name = 'received-' . gmdate( 'Ymd-His' ) . '.pbk';
				}
				$dest = Monarch_Storage::dir( 'backups' ) . '/' . $name;
				if ( file_exists( $dest ) ) {
					$name = preg_replace( '/\.pbk$/', '', $name ) . '-' . Monarch_Util::rand_id( 4 ) . '.pbk';
					$dest = Monarch_Storage::dir( 'backups' ) . '/' . $name;
				}
				$check = Monarch_Archive::validate( $src );
				if ( ! is_wp_error( $check ) ) {
					rename( $src, $dest ); // phpcs:ignore
					Monarch_Storage::rrmdir( $s['out'] );
					$state = 'done';
					$file  = $name;
					$pct   = 100;
				} elseif ( false === $alive ) {
					$state = 'error';
					$last  = $check->get_error_message();
				}
			}
		} elseif ( $pct >= 100 && false === $alive ) {
			$state = 'done';
		} elseif ( preg_match( '/transfer(red)? (complete|done)|file(s)? sent/i', $log ) && false === $alive ) {
			$state = 'done';
			$pct   = 100;
		}
		if ( false === $alive && ! in_array( $state, array( 'done' ), true ) ) {
			$state = 'error';
			if ( '' === $last ) {
				$last = __( 'croc stopped unexpectedly. Your host may block outgoing connections to the relay (TCP ports 9009–9013).', 'monarch-site-migration' );
			}
		}
		return array(
			'id'      => $s['id'],
			'mode'    => $s['mode'],
			'state'   => $state,
			'percent' => min( 100, $pct ),
			'message' => wp_strip_all_tags( $last ),
			'lines'   => self::clean_lines( $lines ),
			'code'    => isset( $s['code'] ) ? $s['code'] : '',
			'file'    => $file ? $file : ( isset( $s['file'] ) ? $s['file'] : '' ),
			'elapsed' => time() - (int) $s['started'],
		);
	}

	/**
	 * croc output for the live log: messages plus only the latest progress-bar line.
	 *
	 * @param array $lines Raw lines.
	 * @return array
	 */
	private static function clean_lines( $lines ) {
		$out  = array();
		$last = '';
		foreach ( $lines as $l ) {
			if ( preg_match( '/\d{1,3}%\s*\|/', $l ) ) {
				$last = $l;
				continue;
			}
			if ( ! in_array( $l, $out, true ) ) {
				$out[] = $l;
			}
		}
		if ( '' !== $last ) {
			$out[] = $last;
		}
		return array_map( 'wp_strip_all_tags', array_slice( $out, -14 ) );
	}

	/**
	 * Stop a session.
	 *
	 * @param string $sid Session id.
	 */
	public static function cancel( $sid ) {
		$s = self::load( $sid );
		if ( ! $s ) {
			return;
		}
		$pid = (int) $s['pid'];
		if ( $pid > 0 ) {
			if ( function_exists( 'posix_kill' ) ) {
				@posix_kill( $pid, 15 ); // phpcs:ignore
			} elseif ( self::can_exec() && ! self::is_windows() ) {
				self::run_sync( 'kill ' . (int) $pid );
			}
		}
		if ( ! empty( $s['out'] ) ) {
			Monarch_Storage::rrmdir( $s['out'] );
		}
		@unlink( $s['log'] ); // phpcs:ignore
		@unlink( self::session_file( $sid ) ); // phpcs:ignore
	}

	/**
	 * Manual commands for running croc on a PC.
	 *
	 * @param string $code Code.
	 * @param string $file File.
	 * @return array
	 */
	public static function manual( $code, $file = 'backup.pbk' ) {
		return array(
			'send_unix'    => 'CROC_SECRET="' . $code . '" croc send ' . $file,
			'send_win'     => 'croc send --code ' . $code . ' ' . $file,
			'receive_unix' => 'CROC_SECRET="' . $code . '" croc',
			'receive_win'  => 'croc ' . $code,
		);
	}
}
