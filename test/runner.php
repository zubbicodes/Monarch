<?php
/**
 * TEST HARNESS ONLY — never deploy. Drives Monarch through its real AJAX router,
 * one HTTP request per step, with a tiny time budget to force many resumes.
 */
$do = isset( $_GET['do'] ) ? $_GET['do'] : '';
if ( 'install' === $do ) {
	define( 'WP_INSTALLING', true );
}
require __DIR__ . '/wp-load.php';

if ( 'install' === $do ) {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	if ( ! is_blog_installed() ) {
		wp_install( 'Test ' . $_GET['site'], 'admin', 'admin@example.test', true, '', $_GET['pass'] );
	}
	echo 'installed';
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! is_plugin_active( 'monarch-site-migration/monarch-site-migration.php' ) ) {
	activate_plugin( 'monarch-site-migration/monarch-site-migration.php' );
	echo 'activated';
	exit;
}
wp_set_current_user( 1 );
add_filter(
	'monarch_time_budget',
	function () {
		return isset( $_GET['budget'] ) ? (float) $_GET['budget'] : 0.3;
	}
);

if ( 'seed' === $do ) {
	global $wpdb;
	$home = home_url();
	for ( $i = 0; $i < 1500; $i++ ) {
		$id = wp_insert_post(
			array(
				'post_title'   => "Post $i — ünïcødé ✓ 😀",
				'post_content' => "Line one\nLine two with 'quotes' and \"double\" and \\ backslash. Link: <a href=\"$home/page-$i/\">x</a> img $home/wp-content/uploads/a.jpg; -- PB-STMT\n-- PB-STMT",
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $id, 'pb_ser', array( 'url' => "$home/x/$i", 'nested' => serialize( array( 'deep' => $home ) ), 'n' => $i ) );
		update_post_meta( $id, 'pb_json', wp_json_encode( array( 'link' => "$home/j/$i" ) ) );
	}
	update_option( 'pb_big', str_repeat( "BIG $home ", 7000 ), false ); // ~200 KB row: fits, but forces INSERT splitting at 256 KB packet.
	update_option( 'pb_huge', str_repeat( 'H', 700000 ), false ); // 700 KB row > 256 KB packet: must produce a clear error, then Skip.
	update_option( 'pb_obj', 'a:1:{s:3:"obj";O:12:"Missing_Cls_":1:{s:3:"url";s:' . strlen( $home ) . ':"' . $home . '";}}', false );
	$wpdb->query( "CREATE TABLE {$wpdb->prefix}custom_nopk (a VARCHAR(191), b LONGTEXT, c BLOB, d DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci" );
	for ( $i = 0; $i < 300; $i++ ) {
		$wpdb->insert( "{$wpdb->prefix}custom_nopk", array( 'a' => "k$i", 'b' => "$home/nopk/$i", 'c' => random_bytes( 64 ), 'd' => null ) );
	}
	$up = wp_upload_dir()['basedir'];
	wp_mkdir_p( "$up/2024/01" );
	file_put_contents( "$up/2024/01/big.bin", random_bytes( 5 * 1048576 + 123 ) );
	for ( $i = 0; $i < 200; $i++ ) {
		file_put_contents( "$up/2024/01/f$i.txt", str_repeat( "file $i\n", 50 ) );
	}
	wp_mkdir_p( WP_CONTENT_DIR . '/languages' );
	file_put_contents( WP_CONTENT_DIR . '/languages/x.po', 'lang' );
	echo 'seeded';
	exit;
}

if ( 'fingerprint' === $do ) {
	global $wpdb;
	$p   = $wpdb->prefix;
	$ser = $wpdb->get_col( "SELECT meta_value FROM {$p}postmeta WHERE meta_key='pb_ser' ORDER BY meta_id LIMIT 3" );
	$ok  = true;
	foreach ( $ser as $s ) {
		$u = @unserialize( $s );
		if ( ! is_array( $u ) || ! is_array( @unserialize( $u['nested'] ) ) ) {
			$ok = false;
		}
	}
	$obj = get_option( 'pb_obj' );
	$up  = wp_upload_dir()['basedir'];
	echo wp_json_encode(
		array(
			'home'          => home_url(),
			'posts'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}posts WHERE post_title LIKE 'Post %'" ),
			'meta'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}postmeta WHERE meta_key LIKE 'pb\\_%'" ),
			'title_sample'  => $wpdb->get_var( "SELECT post_title FROM {$p}posts WHERE post_title LIKE 'Post 7 %'" ),
			'content_md5'   => md5( (string) $wpdb->get_var( "SELECT GROUP_CONCAT(post_content ORDER BY ID SEPARATOR '|') FROM {$p}posts WHERE post_title LIKE 'Post 1%'" ) ),
			'ser_sample'    => $ser ? $ser[0] : '',
			'ser_valid'     => $ok,
			'json_sample'   => $wpdb->get_var( "SELECT meta_value FROM {$p}postmeta WHERE meta_key='pb_json' LIMIT 1" ),
			'big_len'       => strlen( (string) get_option( 'pb_big' ) ),
			'big_has_home'  => false !== strpos( (string) get_option( 'pb_big' ), home_url() ),
			'huge'          => strlen( (string) get_option( 'pb_huge' ) ),
			'obj'           => $obj,
			'obj_valid'     => false !== @unserialize( $obj, array( 'allowed_classes' => false ) ),
			'nopk_rows'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}custom_nopk" ),
			'nopk_sample'   => $wpdb->get_var( "SELECT b FROM {$p}custom_nopk WHERE a='k5'" ),
			'blob_len'      => (int) $wpdb->get_var( "SELECT SUM(LENGTH(c)) FROM {$p}custom_nopk" ),
			'admin_hash'    => $wpdb->get_var( "SELECT user_pass FROM {$p}users WHERE user_login='admin'" ),
			'caps_key'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}usermeta WHERE meta_key='{$p}capabilities'" ),
			'roles'         => (bool) get_option( $p . 'user_roles' ),
			'active'        => get_option( 'active_plugins' ),
			'big_file_md5'  => is_file( "$up/2024/01/big.bin" ) ? md5_file( "$up/2024/01/big.bin" ) : null,
			'small_files'   => count( (array) glob( "$up/2024/01/f*.txt" ) ),
			'lang'          => is_file( WP_CONTENT_DIR . '/languages/x.po' ),
			'leftover_tmp'  => $wpdb->get_col( "SHOW TABLES LIKE 'pb%'" ),
			'backups'       => wp_list_pluck( Monarch_Storage::backups(), 'name' ),
			'storage'       => Monarch_Storage::root(),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
	exit;
}

// Everything else goes through the real AJAX router.
$_POST                = array_merge( $_GET, $_POST );
$_POST['nonce']       = wp_create_nonce( 'monarch-site-migration' );
$_POST['action']      = 'monarch_step';
$_REQUEST             = $_POST;
add_filter( 'wp_doing_ajax', '__return_true' );
do_action( 'wp_ajax_monarch_step' );
