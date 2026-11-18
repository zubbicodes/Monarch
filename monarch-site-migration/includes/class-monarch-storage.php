<?php
/**
 * Storage: protected folders, backups list, job state and locking.
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filesystem storage helper.
 */
class Monarch_Storage {

	const OPTION_DIR      = 'monarch_storage_dir';
	const OPTION_SETTINGS = 'monarch_settings';

	/**
	 * Absolute path of the storage root (no trailing slash).
	 *
	 * @return string
	 */
	public static function root() {
		$name = get_option( self::OPTION_DIR );
		if ( ! $name ) {
			$name = self::adopt_legacy();
		}
		if ( ! $name || ! preg_match( '/^monarch-storage-[a-z0-9]{12}$/', $name ) ) {
			$name = 'monarch-storage-' . strtolower( wp_generate_password( 12, false, false ) );
			update_option( self::OPTION_DIR, $name, false );
		}
		return untrailingslashit( WP_CONTENT_DIR ) . '/' . $name;
	}

	/**
	 * Upgrade from the plugin's former name (ProBackup): take over its storage
	 * folder, backups and settings so nothing is lost after switching.
	 * The old options are removed, so uninstalling the old plugin cannot delete
	 * the adopted backups.
	 *
	 * @return string New storage folder name, or '' if there is nothing to adopt.
	 */
	private static function adopt_legacy() {
		$old = get_option( 'probackup_storage_dir' );
		if ( ! is_string( $old ) || ! preg_match( '/^probackup-storage-([a-z0-9]{12})$/', $old, $m ) ) {
			return '';
		}
		$content = untrailingslashit( WP_CONTENT_DIR );
		$name    = 'monarch-storage-' . $m[1];
		if ( is_dir( $content . '/' . $old ) && ! file_exists( $content . '/' . $name ) && ! @rename( $content . '/' . $old, $content . '/' . $name ) ) { // phpcs:ignore
			return '';
		}
		update_option( self::OPTION_DIR, $name, false );
		$settings = get_option( 'probackup_settings' );
		if ( is_array( $settings ) && false === get_option( self::OPTION_SETTINGS ) ) {
			update_option( self::OPTION_SETTINGS, $settings, false );
		}
		delete_option( 'probackup_storage_dir' );
		delete_option( 'probackup_settings' );
		delete_option( 'probackup_active_job' );
		return $name;
	}

	/**
	 * Sub folder path, created on demand.
	 *
	 * @param string $sub backups|jobs|incoming|bin|tmp|croc.
	 * @return string
	 */
	public static function dir( $sub ) {
		$dir = self::root() . '/' . $sub;
		if ( ! is_dir( $dir ) ) {
			self::ensure();
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Create root folder and protection files.
	 */
	public static function ensure() {
		$root = self::root();
		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}
		$files = array(
			'.htaccess'  => "# Monarch\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'index.html' => '',
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $file => $content ) {
			if ( ! file_exists( $root . '/' . $file ) ) {
				@file_put_contents( $root . '/' . $file, $content ); // phpcs:ignore
			}
		}
		foreach ( array( 'backups', 'jobs', 'incoming', 'bin', 'tmp', 'croc' ) as $sub ) {
			if ( ! is_dir( $root . '/' . $sub ) ) {
				wp_mkdir_p( $root . '/' . $sub );
			}
		}
	}

	/**
	 * Plugin settings with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'time_budget'      => 8,
			'croc_path'        => '',
			'croc_relay'       => '',
			'croc_pass'        => '',
			'delete_on_remove' => 0,
			'keep_backups'     => 5,
		);
		$saved    = get_option( self::OPTION_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Save settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function save_settings( $input ) {
		$s                     = self::settings();
		$s['time_budget']      = max( 3, min( 60, (int) ( isset( $input['time_budget'] ) ? $input['time_budget'] : 8 ) ) );
		$s['croc_path']        = isset( $input['croc_path'] ) ? sanitize_text_field( wp_unslash( $input['croc_path'] ) ) : '';
		$s['croc_relay']       = isset( $input['croc_relay'] ) ? preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', wp_unslash( $input['croc_relay'] ) ) : '';
		$s['croc_pass']        = isset( $input['croc_pass'] ) ? sanitize_text_field( wp_unslash( $input['croc_pass'] ) ) : '';
		$s['delete_on_remove'] = empty( $input['delete_on_remove'] ) ? 0 : 1;
		$s['keep_backups']     = max( 0, min( 100, (int) ( isset( $input['keep_backups'] ) ? $input['keep_backups'] : 5 ) ) );
		if ( '' !== $s['croc_path'] && ( ! is_file( $s['croc_path'] ) || preg_match( '/[;&|`$<>]/', $s['croc_path'] ) ) ) {
			$s['croc_path'] = '';
		}
		update_option( self::OPTION_SETTINGS, $s, false );
		return $s;
	}

	/**
	 * Validate a backup file name.
	 *
	 * @param string $name File name.
	 * @return string|false
	 */
	public static function valid_name( $name ) {
		$name = basename( (string) $name );
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.pbk$/', $name ) ? $name : false;
	}

	/**
	 * Full path of a backup by name.
	 *
	 * @param string $name File name.
	 * @return string|false
	 */
	public static function backup_path( $name ) {
		$name = self::valid_name( $name );
		return $name ? self::dir( 'backups' ) . '/' . $name : false;
	}

	/**
	 * List backups, newest first.
	 *
	 * @return array
	 */
	public static function backups() {
		$out  = array();
		$list = glob( self::dir( 'backups' ) . '/*.pbk' );
		foreach ( $list ? $list : array() as $file ) {
			$out[] = array(
				'name'  => basename( $file ),
				'size'  => Monarch_Util::filesize( $file ),
				'human' => size_format( Monarch_Util::filesize( $file ), 1 ),
				'time'  => filemtime( $file ),
				'date'  => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $file ) ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['time'] - $a['time'];
			}
		);
		return $out;
	}

	/**
	 * Remove old backups beyond the retention count.
	 */
	public static function prune() {
		$keep = (int) self::settings()['keep_backups'];
		if ( $keep < 1 ) {
			return;
		}
		$all = self::backups();
		foreach ( array_slice( $all, $keep ) as $b ) {
			@unlink( self::dir( 'backups' ) . '/' . $b['name'] ); // phpcs:ignore
		}
	}

	/**
	 * Job state path.
	 *
	 * @param string $id Job id.
	 * @return string
	 */
	public static function job_file( $id ) {
		return self::dir( 'jobs' ) . '/' . preg_replace( '/[^a-z0-9]/', '', $id ) . '.json';
	}

	/**
	 * Load job state.
	 *
	 * @param string $id Job id.
	 * @return array|null
	 */
	public static function load_job( $id ) {
		$file = self::job_file( $id );
		if ( ! is_file( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Save job state atomically.
	 *
	 * @param array $job Job state (must contain id).
	 */
	public static function save_job( $job ) {
		$file = self::job_file( $job['id'] );
		$tmp  = $file . '.' . wp_generate_password( 6, false, false ) . '.tmp';
		file_put_contents( $tmp, wp_json_encode( $job ) ); // phpcs:ignore
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore
			@unlink( $file ); // phpcs:ignore
			@rename( $tmp, $file ); // phpcs:ignore
		}
	}

	/**
	 * Delete job state and its working folder.
	 *
	 * @param string $id Job id.
	 */
	public static function delete_job( $id ) {
		@unlink( self::job_file( $id ) ); // phpcs:ignore
		self::rrmdir( self::job_dir( $id ) );
	}

	/**
	 * Working folder for a job.
	 *
	 * @param string $id Job id.
	 * @return string
	 */
	public static function job_dir( $id ) {
		$dir = self::dir( 'tmp' ) . '/' . preg_replace( '/[^a-z0-9]/', '', $id );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/**
	 * Acquire a short lock so two browser tabs can't run the same job step.
	 *
	 * @param string $id Job id.
	 * @return resource|false
	 */
	public static function lock( $id ) {
		$fh = fopen( self::job_file( $id ) . '.lock', 'c' ); // phpcs:ignore
		if ( ! $fh ) {
			return false;
		}
		if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
			fclose( $fh ); // phpcs:ignore
			return false;
		}
		return $fh;
	}

	/**
	 * Release lock.
	 *
	 * @param resource $fh Handle.
	 */
	public static function unlock( $fh ) {
		if ( $fh ) {
			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore
		}
	}

	/**
	 * Recursively remove a folder.
	 *
	 * @param string $dir Folder.
	 */
	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			if ( $f->isDir() && ! $f->isLink() ) {
				@rmdir( $f->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $f->getPathname() ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	/**
	 * Remove stale jobs/tmp older than 2 days.
	 */
	public static function cleanup_stale() {
		$limit = time() - 2 * DAY_IN_SECONDS;
		foreach ( (array) glob( self::dir( 'jobs' ) . '/*.json' ) as $f ) {
			if ( $f && filemtime( $f ) < $limit ) {
				self::delete_job( basename( $f, '.json' ) );
				@unlink( $f . '.lock' ); // phpcs:ignore
			}
		}
		foreach ( (array) glob( self::dir( 'incoming' ) . '/*.part' ) as $f ) {
			if ( $f && filemtime( $f ) < $limit ) {
				@unlink( $f ); // phpcs:ignore
			}
		}
	}
}
