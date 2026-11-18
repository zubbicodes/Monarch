<?php
/**
 * Structured, incremental job log.
 *
 * Every entry gets a sequence number; the browser asks only for entries newer
 * than the last one it has, so the activity log streams live without resending
 * the whole history on every step.
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job logger.
 */
class Monarch_Log {

	const MAX = 2000;

	/**
	 * Sequence number the client already has (set per request).
	 *
	 * @var int
	 */
	public static $since = 0;

	/**
	 * Add an entry.
	 *
	 * @param array  $job   Job (modified).
	 * @param string $msg   Message.
	 * @param string $level info|ok|warn|err|step.
	 */
	public static function add( &$job, $msg, $level = 'info' ) {
		$seq          = isset( $job['log_seq'] ) ? $job['log_seq'] + 1 : 1;
		$job['log_seq'] = $seq;
		if ( ! isset( $job['log'] ) || ! is_array( $job['log'] ) ) {
			$job['log'] = array();
		}
		$job['log'][] = array( $seq, round( microtime( true ), 2 ), $level, (string) $msg );
		if ( count( $job['log'] ) > self::MAX ) {
			$job['log'] = array_slice( $job['log'], -self::MAX );
		}
	}

	/**
	 * Entries newer than self::$since, as objects for the UI.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function since( $job ) {
		$out = array();
		foreach ( isset( $job['log'] ) ? $job['log'] : array() as $e ) {
			if ( ! is_array( $e ) || $e[0] <= self::$since ) {
				continue;
			}
			$out[] = array(
				'seq'   => $e[0],
				't'     => $e[1],
				'level' => $e[2],
				'msg'   => $e[3],
			);
		}
		return $out;
	}

	/**
	 * Human size helper.
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function size( $bytes ) {
		return size_format( max( 0, (float) $bytes ), $bytes >= 1048576 ? 1 : 0 );
	}

	/**
	 * Human number helper.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	public static function num( $n ) {
		return number_format_i18n( (float) $n );
	}
}
