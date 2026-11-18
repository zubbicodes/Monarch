<?php
/**
 * The .pbk streaming archive format.
 *
 * Layout:  "PBK1" | entry* | end-entry
 * Entry:   header (14 bytes: n path_len, N size_hi, N size_lo, N mtime) | path | raw data (size bytes)
 * End:     an entry named "__pbk_end__" with size 0.
 *
 * Append-only and seekable, so both writing and extracting can stop at any byte
 * and resume in the next request.
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archive reader/writer helpers.
 */
class Monarch_Archive {

	const MAGIC       = 'PBK1';
	const END         = '__pbk_end__';
	const HEADER_SIZE = 14;
	const PIECE       = 1048576; // 1 MB copy buffer.

	/**
	 * Create a new empty archive.
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	public static function create( $file ) {
		return false !== file_put_contents( $file, self::MAGIC ); // phpcs:ignore
	}

	/**
	 * Open for appending at a known-good size (truncates any half-written tail
	 * left by a request that was killed mid-write).
	 *
	 * @param string $file Path.
	 * @param int    $size Committed size.
	 * @return resource|false
	 */
	public static function open_append( $file, $size ) {
		$fh = fopen( $file, 'c+b' ); // phpcs:ignore
		if ( ! $fh ) {
			return false;
		}
		ftruncate( $fh, $size );
		fseek( $fh, $size );
		return $fh;
	}

	/**
	 * Build an entry header.
	 *
	 * @param string $name  Entry path.
	 * @param int    $size  Data size.
	 * @param int    $mtime Modified time.
	 * @return string
	 */
	public static function header( $name, $size, $mtime ) {
		$hi = (int) floor( $size / 4294967296 );
		$lo = (int) ( $size - $hi * 4294967296 );
		return pack( 'nNNN', strlen( $name ), $hi, $lo, max( 0, (int) $mtime ) ) . $name;
	}

	/**
	 * Add a small in-memory entry.
	 *
	 * @param resource $fh   Archive handle.
	 * @param string   $name Entry name.
	 * @param string   $data Data.
	 */
	public static function add_string( $fh, $name, $data ) {
		fwrite( $fh, self::header( $name, strlen( $data ), time() ) ); // phpcs:ignore
		fwrite( $fh, $data ); // phpcs:ignore
	}

	/**
	 * Copy (part of) a file into the archive. The cursor tracks progress so a big
	 * file can be split across many requests.
	 *
	 * @param resource $fh     Archive handle positioned at end.
	 * @param string   $src    Source file.
	 * @param string   $name   Entry name.
	 * @param array    $cursor Cursor: null for new entry, or [size, done].
	 * @return array|true Updated cursor, or true when entry is complete.
	 */
	public static function add_file_piece( $fh, $src, $name, $cursor ) {
		if ( empty( $cursor ) ) {
			$size   = Monarch_Util::filesize( $src );
			$cursor = array(
				'size' => $size,
				'done' => 0,
			);
			fwrite( $fh, self::header( $name, $size, (int) @filemtime( $src ) ) ); // phpcs:ignore
		}
		$in = @fopen( $src, 'rb' ); // phpcs:ignore
		if ( $in && $cursor['done'] > 0 ) {
			fseek( $in, $cursor['done'] );
		}
		while ( $cursor['done'] < $cursor['size'] ) {
			$want = (int) min( self::PIECE, $cursor['size'] - $cursor['done'] );
			$data = $in ? fread( $in, $want ) : ''; // phpcs:ignore
			if ( false === $data || '' === $data ) {
				// File shrank or became unreadable: pad so the archive stays consistent.
				$data = str_repeat( "\0", $want );
			}
			fwrite( $fh, $data ); // phpcs:ignore
			$cursor['done'] += strlen( $data );
			if ( Monarch_Util::out_of_time() && $cursor['done'] < $cursor['size'] ) {
				break;
			}
		}
		if ( $in ) {
			fclose( $in ); // phpcs:ignore
		}
		return $cursor['done'] >= $cursor['size'] ? true : $cursor;
	}

	/**
	 * Write the end marker.
	 *
	 * @param resource $fh Handle.
	 */
	public static function finish( $fh ) {
		fwrite( $fh, self::header( self::END, 0, 0 ) ); // phpcs:ignore
	}

	/**
	 * Read the next entry header at the current position.
	 *
	 * @param resource $fh Handle.
	 * @return array|false [name, size, mtime] or false on EOF/corruption.
	 */
	public static function read_header( $fh ) {
		$raw = fread( $fh, self::HEADER_SIZE ); // phpcs:ignore
		if ( false === $raw || strlen( $raw ) < self::HEADER_SIZE ) {
			return false;
		}
		$h = unpack( 'nlen/Nhi/Nlo/Nmtime', $raw );
		if ( $h['len'] < 1 ) {
			return false;
		}
		$name = fread( $fh, $h['len'] ); // phpcs:ignore
		if ( strlen( $name ) !== $h['len'] ) {
			return false;
		}
		return array(
			'name'  => $name,
			'size'  => $h['hi'] * 4294967296 + $h['lo'],
			'mtime' => $h['mtime'],
		);
	}

	/**
	 * Validate an archive and return its manifest.
	 *
	 * @param string $file Path.
	 * @return array|WP_Error
	 */
	public static function validate( $file ) {
		$fh = @fopen( $file, 'rb' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'pb_open', __( 'Cannot open the backup file.', 'monarch-site-migration' ) );
		}
		if ( fread( $fh, 4 ) !== self::MAGIC ) { // phpcs:ignore
			fclose( $fh ); // phpcs:ignore
			return new WP_Error( 'pb_magic', __( 'This is not a Monarch (.pbk) archive.', 'monarch-site-migration' ) );
		}
		$tail_len = self::HEADER_SIZE + strlen( self::END );
		$size     = Monarch_Util::filesize( $file );
		fseek( $fh, $size - $tail_len );
		$tail = fread( $fh, $tail_len ); // phpcs:ignore
		if ( substr( (string) $tail, -strlen( self::END ) ) !== self::END ) {
			fclose( $fh ); // phpcs:ignore
			return new WP_Error( 'pb_incomplete', __( 'The archive is incomplete or truncated (it was not fully transferred). Please transfer it again.', 'monarch-site-migration' ) );
		}
		fseek( $fh, 4 );
		$h = self::read_header( $fh );
		if ( ! $h || 'package.json' !== $h['name'] || $h['size'] > 50 * 1048576 ) {
			fclose( $fh ); // phpcs:ignore
			return new WP_Error( 'pb_manifest', __( 'The archive manifest is missing.', 'monarch-site-migration' ) );
		}
		$json = $h['size'] > 0 ? fread( $fh, (int) $h['size'] ) : ''; // phpcs:ignore
		fclose( $fh ); // phpcs:ignore
		$manifest = json_decode( (string) $json, true );
		if ( ! is_array( $manifest ) || empty( $manifest['siteurl'] ) ) {
			return new WP_Error( 'pb_manifest', __( 'The archive manifest is corrupted.', 'monarch-site-migration' ) );
		}
		$manifest['archive_size'] = $size;
		return $manifest;
	}

	/**
	 * Make an archive path safe for extraction. Returns false when unsafe.
	 *
	 * @param string $name Entry name.
	 * @return string|false
	 */
	public static function safe_path( $name ) {
		$name = str_replace( '\\', '/', $name );
		if ( '' === $name || '/' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) || false !== strpos( $name, "\0" ) ) {
			return false;
		}
		foreach ( explode( '/', $name ) as $part ) {
			if ( '..' === $part ) {
				return false;
			}
		}
		return $name;
	}
}
