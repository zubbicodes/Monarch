<?php
/**
 * Uninstall: remove options; remove backups only if the user asked for it.
 *
 * @package Monarch
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$monarch_settings = get_option( 'monarch_settings', array() );
$monarch_dir      = get_option( 'monarch_storage_dir' );

if ( ! empty( $monarch_settings['delete_on_remove'] ) && $monarch_dir && preg_match( '/^monarch-storage-[a-z0-9]{12}$/', $monarch_dir ) ) {
	$monarch_root = untrailingslashit( WP_CONTENT_DIR ) . '/' . $monarch_dir;
	if ( is_dir( $monarch_root ) ) {
		$monarch_it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $monarch_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $monarch_it as $monarch_f ) {
			if ( $monarch_f->isDir() && ! $monarch_f->isLink() ) {
				@rmdir( $monarch_f->getPathname() ); // phpcs:ignore
			} else {
				@unlink( $monarch_f->getPathname() ); // phpcs:ignore
			}
		}
		@rmdir( $monarch_root ); // phpcs:ignore
	}
	delete_option( 'monarch_storage_dir' );
}

delete_option( 'monarch_settings' );
delete_option( 'monarch_active_job' );
