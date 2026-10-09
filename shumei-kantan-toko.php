<?php
/**
 * Plugin Name: 秀明ファーム茨木 かんたん投稿
 * Plugin URI:  https://xs905657.xsrv.jp/shumei-ibaraki/
 * Description: 生産者がスマホから写真と一言を送るだけでブログ記事（下書き）を作れる投稿フォームを追加します。LINE公式アカウントからの投稿にも対応。
 * Version:     1.5.1
 * Author:      秀明自然農法ファーム茨木
 * Text Domain: shumei-kantan-toko
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SKT_VERSION', '1.5.1' );
define( 'SKT_FILE', __FILE__ );
define( 'SKT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SKT_URL', plugin_dir_url( __FILE__ ) );

require_once SKT_PATH . 'includes/class-skt-settings.php';
require_once SKT_PATH . 'includes/class-skt-media.php';
require_once SKT_PATH . 'includes/class-skt-post-creator.php';
require_once SKT_PATH . 'includes/class-skt-rest.php';
require_once SKT_PATH . 'includes/class-skt-frontend.php';
require_once SKT_PATH . 'includes/class-skt-gallery.php';
require_once SKT_PATH . 'includes/class-skt-line.php';
require_once SKT_PATH . 'includes/class-skt-admin.php';

/**
 * 起動。
 */
function skt_bootstrap() {
	SKT_Frontend::init();
	SKT_Rest::init();
	SKT_Gallery::init();
	SKT_Admin::init();
}
add_action( 'plugins_loaded', 'skt_bootstrap' );

/**
 * 有効化時: 既定値を入れて、投稿ページのURLを有効にする。
 */
function skt_activate() {
	SKT_Settings::install_defaults();
	SKT_Settings::ensure_category( 'マルシェ活動' );
	SKT_Frontend::add_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'skt_activate' );

/**
 * 無効化時: URLルールを片付ける。設定と投稿はそのまま残す。
 */
function skt_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'skt_deactivate' );
