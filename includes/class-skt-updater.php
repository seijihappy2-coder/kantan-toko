<?php
/**
 * GitHubの新しい版を見つけて、WordPressの更新画面に出す。
 *
 * 公開リポジトリのリリースを見るだけなので、鍵も設定も要らない。
 * 「自動更新を有効化」を1回押しておけば、以降は何もしなくても更新される。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Updater {

	/** 更新を取りに行く先 */
	const REPO = 'seijihappy2-coder/kantan-toko';

	/** 問い合わせ結果を覚えておく時間 */
	const CACHE_KEY = 'skt_latest_release';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'check' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ), 10, 0 );
	}

	/**
	 * このプラグインの識別子（例: kantan-toko/kantan-toko.php）。
	 */
	private static function basename() {
		return plugin_basename( SKT_FILE );
	}

	private static function slug() {
		return dirname( self::basename() );
	}

	/**
	 * GitHubの最新リリースを調べる。失敗したら null。
	 */
	private static function latest() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( 'none' === $cached ) {
			return null; // 直前に失敗した。しばらく再挑戦しない。
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, 'none', HOUR_IN_SECONDS );
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $release['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, 'none', HOUR_IN_SECONDS );
			return null;
		}

		// zipはリリースに添付したものを使う（フォルダ名が正しい）。
		$package = '';
		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}

		$latest = array(
			'version' => ltrim( (string) $release['tag_name'], 'vV' ),
			'package' => $package,
			'url'     => (string) ( $release['html_url'] ?? '' ),
			'notes'   => (string) ( $release['body'] ?? '' ),
			'date'    => (string) ( $release['published_at'] ?? '' ),
		);

		set_transient( self::CACHE_KEY, $latest, self::CACHE_TTL );

		return $latest;
	}

	/**
	 * 新しい版があれば、WordPressの更新一覧に載せる。
	 */
	public static function check( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$latest = self::latest();
		if ( ! $latest || empty( $latest['package'] ) ) {
			return $transient;
		}
		if ( ! version_compare( $latest['version'], SKT_VERSION, '>' ) ) {
			return $transient;
		}

		$transient->response[ self::basename() ] = (object) array(
			'slug'        => self::slug(),
			'plugin'      => self::basename(),
			'new_version' => $latest['version'],
			'url'         => $latest['url'],
			'package'     => $latest['package'],
			'tested'      => get_bloginfo( 'version' ),
		);

		return $transient;
	}

	/**
	 * 「詳細を表示」で出す内容。
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::slug() !== $args->slug ) {
			return $result;
		}

		$latest = self::latest();
		if ( ! $latest ) {
			return $result;
		}

		return (object) array(
			'name'          => 'かんたん投稿',
			'slug'          => self::slug(),
			'version'       => $latest['version'],
			'author'        => '農場スタッフ',
			'homepage'      => $latest['url'],
			'download_link' => $latest['package'],
			'last_updated'  => $latest['date'],
			'sections'      => array(
				'description' => '農家の方がスマホから写真と一言でブログを更新できるようにするプラグインです。',
				'changelog'   => '<pre>' . esc_html( $latest['notes'] ) . '</pre>',
			),
		);
	}

	/**
	 * 更新したら、覚えている情報を捨てる。
	 */
	public static function forget() {
		delete_transient( self::CACHE_KEY );
	}
}
