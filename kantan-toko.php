<?php
/**
 * Plugin Name: かんたん投稿
 * Description: 生産者がスマホから写真と一言を送るだけでブログ記事（下書き）を作れる投稿フォームを追加します。LINE公式アカウントからの投稿にも対応。
 * Version:     1.11.1
 * Author:      農場スタッフ
 * Text Domain: kantan-toko
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 旧フォルダ名の版がまだ有効なときは、二重に読み込まない（同名クラスで停止するのを防ぐ）。
if ( defined( 'SKT_VERSION' ) ) {
	return;
}

define( 'SKT_VERSION', '1.11.1' );

/**
 * 初めて有効化したときに、守るカテゴリとして自動で指定するカテゴリ名。
 * 農場ごとに違うので既定は空。必要なら書き換えるか、管理画面で指定する。
 */
define( 'SKT_PROTECT_BY_DEFAULT', '' );
define( 'SKT_FILE', __FILE__ );
define( 'SKT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SKT_URL', plugin_dir_url( __FILE__ ) );

require_once SKT_PATH . 'includes/class-skt-settings.php';
require_once SKT_PATH . 'includes/class-skt-media.php';
require_once SKT_PATH . 'includes/class-skt-post-creator.php';
require_once SKT_PATH . 'includes/class-skt-rest.php';
require_once SKT_PATH . 'includes/class-skt-frontend.php';
require_once SKT_PATH . 'includes/class-skt-content.php';
require_once SKT_PATH . 'includes/class-skt-gallery.php';
require_once SKT_PATH . 'includes/class-skt-line.php';
require_once SKT_PATH . 'includes/class-skt-admin.php';
require_once SKT_PATH . 'includes/class-skt-updater.php';

/**
 * 起動。
 */
function skt_bootstrap() {
	SKT_Frontend::init();
	SKT_Rest::init();
	SKT_Gallery::init();
	SKT_Content::init();
	SKT_Admin::init();
	SKT_Updater::init();
}
add_action( 'plugins_loaded', 'skt_bootstrap' );

/**
 * 有効化時: 既定値を入れて、投稿ページのURLを有効にする。
 */
function skt_activate() {
	SKT_Settings::install_defaults();
	SKT_Settings::ensure_category( 'マルシェ活動' );

	// 農法の説明記事は生産者の日常投稿とは別もの。初回だけ既定で守る対象にする。
	$saved = get_option( SKT_Settings::OPTION, array() );
	if ( '' !== SKT_PROTECT_BY_DEFAULT && ( ! is_array( $saved ) || ! array_key_exists( 'protected_categories', $saved ) ) ) {
		$term = get_term_by( 'name', SKT_PROTECT_BY_DEFAULT, 'category' );
		SKT_Settings::update( array( 'protected_categories' => $term ? array( (int) $term->term_id ) : array() ) );
	}
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
