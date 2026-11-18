<?php
/**
 * Serialization-safe search & replace.
 *
 * Serialized data is rewritten with a small tokenizer instead of unserialize(),
 * so it is safe (no object instantiation) and also works for strings containing
 * objects of classes that don't exist on this site. String lengths are
 * recalculated exactly.
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replacer.
 */
class Monarch_Replace {

	/**
	 * Search => replace map (applied with strtr, longest match first, one pass).
	 *
	 * @var array
	 */
	private $pairs;

	/**
	 * Cheap substrings used to skip values that can't match.
	 *
	 * @var array
	 */
	private $needles;

	/**
	 * Constructor.
	 *
	 * @param array $pairs   Map.
	 * @param array $needles Needles.
	 */
	public function __construct( $pairs, $needles ) {
		$this->pairs   = $pairs;
		$this->needles = $needles;
	}

	/**
	 * Build URL/path pairs for a migration.
	 *
	 * @param array $manifest Source manifest.
	 * @param array $extra    Extra [find, replace] pairs from the user.
	 * @return array [pairs, needles]
	 */
	public static function build( $manifest, $extra = array() ) {
		$pairs   = array();
		$needles = array();
		$urls    = array(
			array( $manifest['home'], get_option( 'home' ) ),
			array( $manifest['siteurl'], get_option( 'siteurl' ) ),
		);
		if ( ! empty( $manifest['uploads_url'] ) ) {
			$urls[] = array( $manifest['uploads_url'], wp_upload_dir( null, false )['baseurl'] );
		}
		foreach ( $urls as $u ) {
			$old = untrailingslashit( (string) $u[0] );
			$new = untrailingslashit( (string) $u[1] );
			if ( '' === $old || $old === $new ) {
				continue;
			}
			$old_bare = preg_replace( '#^https?:#i', '', $old ); // //host/path.
			$new_bare = preg_replace( '#^https?:#i', '', $new );
			$variants = array(
				'http:' . $old_bare  => $new,
				'https:' . $old_bare => $new,
				$old_bare            => $new_bare,
			);
			foreach ( $variants as $from => $to ) {
				$pairs[ $from ]                                = $to;
				$pairs[ str_replace( '/', '\\/', $from ) ]     = str_replace( '/', '\\/', $to ); // JSON escaped.
				$pairs[ rawurlencode( $from ) ]                = rawurlencode( $to );
				$pairs[ urlencode( $from ) ]                   = urlencode( $to ); // phpcs:ignore
			}
			$host = (string) wp_parse_url( $old, PHP_URL_HOST );
			if ( $host ) {
				$needles[] = $host;
			}
		}
		$paths = array(
			array( isset( $manifest['abspath'] ) ? $manifest['abspath'] : '', Monarch_Util::norm( ABSPATH ) ),
			array( isset( $manifest['content_dir'] ) ? $manifest['content_dir'] : '', Monarch_Util::norm( WP_CONTENT_DIR ) ),
		);
		foreach ( $paths as $p ) {
			$old = Monarch_Util::norm( $p[0] );
			$new = Monarch_Util::norm( $p[1] );
			if ( strlen( $old ) < 4 || $old === $new ) {
				continue;
			}
			$pairs[ $old ]                            = $new;
			$pairs[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
			$needles[]                                = $old;
			$needles[]                                = str_replace( '/', '\\/', $old );
		}
		foreach ( (array) $extra as $e ) {
			if ( isset( $e[0], $e[1] ) && '' !== $e[0] && $e[0] !== $e[1] ) {
				$pairs[ $e[0] ] = $e[1];
				$needles[]      = $e[0];
			}
		}
		foreach ( $pairs as $k => $v ) {
			if ( $k === $v || '' === $k ) {
				unset( $pairs[ $k ] );
			}
		}
		return array( $pairs, array_values( array_unique( $needles ) ) );
	}

	/**
	 * Needles getter.
	 *
	 * @return array
	 */
	public function needles() {
		return $this->needles;
	}

	/**
	 * Does anything need replacing at all?
	 *
	 * @return bool
	 */
	public function active() {
		return ! empty( $this->pairs );
	}

	/**
	 * Replace inside a DB value.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public function value( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! $this->might_match( $value ) ) {
			return $value;
		}
		if ( $this->looks_serialized( $value ) ) {
			$out = $this->serialized( $value );
			if ( null !== $out ) {
				return $out;
			}
		}
		return strtr( $value, $this->pairs );
	}

	/**
	 * Quick check.
	 *
	 * @param string $v Value.
	 * @return bool
	 */
	private function might_match( $v ) {
		foreach ( $this->needles as $n ) {
			if ( false !== strpos( $v, $n ) ) {
				return true;
			}
		}
		foreach ( $this->pairs as $k => $unused ) {
			if ( false !== strpos( $v, $k ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Serialized detection.
	 *
	 * @param string $v Value.
	 * @return bool
	 */
	private function looks_serialized( $v ) {
		$v = trim( $v );
		if ( strlen( $v ) < 4 || ':' !== $v[1] ) {
			return false;
		}
		$last = substr( $v, -1 );
		if ( ';' !== $last && '}' !== $last ) {
			return false;
		}
		return (bool) preg_match( '/^[aOsCbidNE]:/', $v );
	}

	/**
	 * Rewrite a serialized string. Returns null when it isn't valid serialized data.
	 *
	 * @param string $s Serialized.
	 * @return string|null
	 */
	private function serialized( $s ) {
		$len = strlen( $s );
		$out = '';
		$p   = 0;
		while ( $p < $len ) {
			$c = $s[ $p ];
			if ( 's' === $c && $p + 1 < $len && ':' === $s[ $p + 1 ] && $this->token_start( $s, $p ) ) {
				$colon = strpos( $s, ':', $p + 2 );
				if ( false === $colon ) {
					return null;
				}
				$n = substr( $s, $p + 2, $colon - $p - 2 );
				if ( ! ctype_digit( $n ) || '"' !== substr( $s, $colon + 1, 1 ) ) {
					return null;
				}
				$n     = (int) $n;
				$start = $colon + 2;
				if ( $start + $n + 2 > $len || '";' !== substr( $s, $start + $n, 2 ) ) {
					return null;
				}
				$inner = substr( $s, $start, $n );
				$inner = $this->value( $inner ); // Handles nested serialized/JSON.
				$out  .= 's:' . strlen( $inner ) . ':"' . $inner . '";';
				$p     = $start + $n + 2;
				continue;
			}
			if ( ( 'O' === $c || 'C' === $c || 'E' === $c ) && $p + 1 < $len && ':' === $s[ $p + 1 ] && $this->token_start( $s, $p ) ) {
				$colon = strpos( $s, ':', $p + 2 );
				if ( false === $colon ) {
					return null;
				}
				$n = substr( $s, $p + 2, $colon - $p - 2 );
				if ( ! ctype_digit( $n ) ) {
					return null;
				}
				$end = $colon + 2 + (int) $n + 1; // After closing quote of the class name.
				if ( $end > $len ) {
					return null;
				}
				if ( 'C' === $c ) {
					// C:len:"Class":dlen:{opaque} - copied verbatim.
					$colon2 = strpos( $s, ':', $end + 1 );
					if ( false === $colon2 ) {
						return null;
					}
					$dlen = (int) substr( $s, $end + 1, $colon2 - $end - 1 );
					$end  = $colon2 + 2 + $dlen + 1;
					if ( $end > $len ) {
						return null;
					}
				}
				$out .= substr( $s, $p, $end - $p );
				$p    = $end;
				continue;
			}
			if ( 'S' === $c && $p + 1 < $len && ':' === $s[ $p + 1 ] && $this->token_start( $s, $p ) ) {
				return null; // Escaped-string format: let the caller fall back.
			}
			$out .= $c;
			$p++;
		}
		return $out;
	}

	/**
	 * A type letter is a token only at the start or right after ; { or }.
	 *
	 * @param string $s Data.
	 * @param int    $p Position.
	 * @return bool
	 */
	private function token_start( $s, $p ) {
		if ( 0 === $p ) {
			return true;
		}
		$prev = $s[ $p - 1 ];
		return ';' === $prev || '{' === $prev || '}' === $prev;
	}
}
