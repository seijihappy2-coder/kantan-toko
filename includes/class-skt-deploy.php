<?php
/**
 * 新しい版を出した合図を受け取って、その場で更新する。
 *
 * GitHub Actions から署名つきで知らせてもらう。鍵（合図用の秘密の文字列）は
 * サイトの管理画面とGitHubの両方に、利用者自身が入れる。
 *
 * この窓口は「このプラグインを、決まったリポジトリのリリースから入れ直す」ことしかしない。
 * 入れる場所はリクエストからは一切受け取らないので、鍵が漏れても他のものは入らない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Deploy {

	/** 署名の有効時間（これより古い合図は受け付けない） */
	const MAX_AGE = 300;

	/** 1時間に受け付ける回数 */
	const LIMIT = 10;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route(
			SKT_Rest::NS,
			'/deploy',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function url() {
		return rest_url( SKT_Rest::NS . '/deploy' );
	}

	/**
	 * 合図を受けて更新する。
	 */
	public static function handle( WP_REST_Request $request ) {
		$secret = (string) SKT_Settings::get( 'deploy_secret' );
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'message' => 'この窓口は使われていません。' ), 404 );
		}

		if ( self::too_many() ) {
			return new WP_REST_Response( array( 'message' => '回数が多すぎます。' ), 429 );
		}

		$body      = $request->get_body();
		$signature = (string) $request->get_header( 'x_kt_signature' );
		$expected  = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		if ( '' === $signature || ! hash_equals( $expected, $signature ) ) {
			return new WP_REST_Response( array( 'message' => '署名が違います。' ), 403 );
		}

		// 古い合図の使い回しを防ぐ。
		$payload = json_decode( $body, true );
		$time    = isset( $payload['time'] ) ? (int) $payload['time'] : 0;
		if ( abs( time() - $time ) > self::MAX_AGE ) {
			return new WP_REST_Response( array( 'message' => '合図が古すぎます。' ), 403 );
		}

		return self::upgrade();
	}

	/**
	 * WordPressの更新の仕組みに任せて入れ替える。
	 * 入れる中身は SKT_Updater が決める（決まったリポジトリのリリース）。
	 */
	private static function upgrade() {
		$before = SKT_VERSION;

		SKT_Updater::forget();
		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );
		$plugin  = plugin_basename( SKT_FILE );

		if ( ! is_object( $updates ) || empty( $updates->response[ $plugin ] ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'updated' => false,
					'message' => 'すでに最新です（' . $before . '）。',
				),
				200
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $plugin );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 500 );
		}
		if ( ! $result ) {
			return new WP_REST_Response(
				array( 'message' => '更新できませんでした。サーバーの書き込み権限を確認してください。' ),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'updated' => true,
				'from'    => $before,
				'to'      => $updates->response[ $plugin ]->new_version,
			),
			200
		);
	}

	private static function too_many() {
		$key   = 'skt_deploy_rate';
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, HOUR_IN_SECONDS );
		return $count > self::LIMIT;
	}
}
