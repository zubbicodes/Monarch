<?php
/**
 * Shared helpers: time/memory budget, SQL escaping, misc.
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility functions.
 */
class Monarch_Util {

	/**
	 * Request start time.
	 *
	 * @var float
	 */
	private static $start = 0;

	/**
	 * Seconds budget for this request.
	 *
	 * @var float
	 */
	private static $budget = 8;

	/**
	 * Memory limit in bytes (0 = unlimited).
	 *
	 * @var int
	 */
	private static $mem_limit = 0;

	/**
	 * Start the step timer.
	 *
	 * @param float $budget Seconds.
	 */
	public static function start_timer( $budget ) {
		self::$start  = microtime( true );
		/**
		 * Filter the seconds each step may run before handing back to the browser.
		 *
		 * @param float $budget Seconds.
		 */
		self::$budget = max( 0.05, (float) apply_filters( 'monarch_time_budget', max( 2, (float) $budget ) ) );
		@set_time_limit( max( 60, (int) ( $budget * 4 ) ) ); // phpcs:ignore
		@ignore_user_abort( true ); // phpcs:ignore
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		self::$mem_limit = self::to_bytes( ini_get( 'memory_limit' ) );
	}

	/**
	 * True when the step should stop and hand control back to the browser.
	 *
	 * @return bool
	 */
	public static function out_of_time() {
		if ( microtime( true ) - self::$start >= self::$budget ) {
			return true;
		}
		if ( self::$mem_limit > 0 && memory_get_usage( true ) > self::$mem_limit * 0.75 ) {
			return true;
		}
		return false;
	}

	/**
	 * Convert php.ini size to bytes.
	 *
	 * @param string $val Value.
	 * @return int
	 */
	public static function to_bytes( $val ) {
		$val = trim( (string) $val );
		if ( '' === $val || '-1' === $val ) {
			return 0;
		}
		$num  = (float) $val;
		$unit = strtolower( substr( $val, -1 ) );
		switch ( $unit ) {
			case 'g':
				$num *= 1024;
				// fall through.
			case 'm':
				$num *= 1024;
				// fall through.
			case 'k':
				$num *= 1024;
		}
		return (int) $num;
	}

	/**
	 * Filesize that never warns.
	 *
	 * @param string $file Path.
	 * @return int
	 */
	public static function filesize( $file ) {
		clearstatcache( true, $file );
		$s = @filesize( $file ); // phpcs:ignore
		return false === $s ? 0 : (int) $s;
	}

	/**
	 * Escape a value for a MySQL string literal (connection independent, deterministic).
	 * Newlines are escaped, so one SQL row always stays on one line.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function esc_sql_value( $value ) {
		return strtr(
			(string) $value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	/**
	 * Quote an identifier.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public static function ident( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Escape a string for LIKE.
	 *
	 * @param string $s Value.
	 * @return string
	 */
	public static function esc_like( $s ) {
		return addcslashes( $s, '_%\\' );
	}

	/**
	 * Run a raw SQL statement and return [ok, error, errno].
	 *
	 * @param string $sql SQL.
	 * @return array
	 */
	public static function raw_query( $sql ) {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		try {
			$res = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery -- Statements come from the plugin's own SQL dump.
		} catch ( Exception $e ) {
			$wpdb->suppress_errors( $quiet );
			return array( false, $e->getMessage(), (int) $e->getCode() );
		}
		$wpdb->suppress_errors( $quiet );
		if ( false === $res ) {
			// The MySQL error number drives the automatic repairs (collations, packet size, ...).
			$errno = ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli ) ? (int) $wpdb->dbh->errno : 0;
			return array( false, $wpdb->last_error, $errno );
		}
		$wpdb->flush();
		return array( true, '', 0 );
	}

	/**
	 * Generate a short random lowercase id.
	 *
	 * @param int $len Length.
	 * @return string
	 */
	public static function rand_id( $len = 10 ) {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$out   = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $chars[ random_int( 0, 35 ) ];
		}
		return $out;
	}

	/**
	 * Normalize a path to forward slashes without trailing slash.
	 *
	 * @param string $p Path.
	 * @return string
	 */
	public static function norm( $p ) {
		return rtrim( str_replace( '\\', '/', (string) $p ), '/' );
	}

	/**
	 * Is a PHP function usable (exists and not disabled)?
	 *
	 * @param string $fn Function name.
	 * @return bool
	 */
	public static function fn_enabled( $fn ) {
		if ( ! function_exists( $fn ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', strtolower( (string) ini_get( 'disable_functions' ) ) ) );
		return ! in_array( strtolower( $fn ), $disabled, true );
	}
}
