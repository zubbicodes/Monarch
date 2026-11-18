<?php
/**
 * Plugin Name:       Monarch – Site Migration
 * Description:       Lightweight whole-site migration. Chunked, resumable database export/import that never times out, plus encrypted peer-to-peer transfer powered by croc.
 * Version:           1.1.1
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            StratonAlly
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       monarch-site-migration
 *
 * @package Monarch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MONARCH_VERSION', '1.1.1' );
define( 'MONARCH_FILE', __FILE__ );
define( 'MONARCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'MONARCH_URL', plugin_dir_url( __FILE__ ) );
define( 'MONARCH_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'Monarch_' ) ) {
			return;
		}
		$file = MONARCH_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		Monarch_Storage::ensure();
	}
);

add_action(
	'plugins_loaded',
	function () {
		Monarch_Plugin::instance();
	}
);
