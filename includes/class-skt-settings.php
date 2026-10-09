<?php
/**
 * 設定値の読み書き。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Settings {

	const OPTION = 'skt_settings';

	/** 合言葉の最低文字数。短いと総当たりで破られる。 */
	const MIN_PASSPHRASE = 8;

	/** 「定型文を入れる」で差し込まれる雛形。{日付} は今日の日付に置き換わる。 */
	const DEFAULT_TEMPLATE = "{日付}　天気　気温　度\n【作業時間】　時　分〜　時　分\n【作業内容】\n\n参加者：";

	/**
	 * 既定値。
	 */
	public static function defaults() {
		return array(
			'slug'                 => 'kantan-toko',
			'passphrase_hash'      => '',
			'admin_passphrase_hash' => '',
			'authors'              => array(),
			'default_category'     => 0,
			'protected_categories' => array(),
			'post_status'          => 'draft',
			'notify_email'         => get_option( 'admin_email' ),
			'post_author_id'       => 0,
			'max_photos'           => 8,
			'template'             => self::DEFAULT_TEMPLATE,
			'help_contact'         => '',
			'video_url'            => '',
			'deploy_secret'        => '',
			'link_label'           => '',
			'link_url'             => '',
			'video_heading'        => '自然栽培について',
			'line_enabled'         => 0,
			'line_channel_secret'  => '',
			'line_access_token'    => '',
			'line_allowed_users'   => '',
			'line_category'        => 0,
		);
	}

	/**
	 * 設定を丸ごと取得。
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * 単一の設定値を取得。
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * 設定を保存（部分更新）。
	 */
	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all );
		return $all;
	}

	/**
	 * 初回有効化時に既定値を入れる。
	 */
	public static function install_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			$defaults = self::defaults();
			if ( ! $defaults['post_author_id'] ) {
				$admins = get_users(
					array(
						'role'   => 'administrator',
						'number' => 1,
						'fields' => 'ID',
					)
				);
				$defaults['post_author_id'] = ! empty( $admins ) ? (int) $admins[0] : 1;
			}
			add_option( self::OPTION, $defaults );
		}
	}

	/**
	 * 合言葉を保存（ハッシュ化して保存し、平文は残さない）。
	 */
	public static function set_passphrase( $plain ) {
		$plain = trim( (string) $plain );
		if ( mb_strlen( $plain ) < self::MIN_PASSPHRASE ) {
			return false;
		}
		self::update( array( 'passphrase_hash' => wp_hash_password( $plain ) ) );
		return true;
	}

	/**
	 * 管理者用の合言葉を保存する。
	 */
	public static function set_admin_passphrase( $plain ) {
		$plain = trim( (string) $plain );
		if ( mb_strlen( $plain ) < self::MIN_PASSPHRASE ) {
			return false;
		}
		self::update( array( 'admin_passphrase_hash' => wp_hash_password( $plain ) ) );
		return true;
	}

	/**
	 * 管理者用の合言葉を消す（管理モードを使わなくする）。
	 */
	public static function clear_admin_passphrase() {
		self::update( array( 'admin_passphrase_hash' => '' ) );
	}

	/**
	 * 生産者用の合言葉の照合。
	 */
	public static function check_passphrase( $plain ) {
		return self::check_hash( 'passphrase_hash', $plain );
	}

	/**
	 * 管理者用の合言葉の照合。
	 */
	public static function check_admin_passphrase( $plain ) {
		return self::check_hash( 'admin_passphrase_hash', $plain );
	}

	/**
	 * どちらの合言葉かを判定する。
	 *
	 * @return string 'admin' | 'producer' | ''
	 */
	public static function role_for( $plain ) {
		if ( self::check_admin_passphrase( $plain ) ) {
			return 'admin';
		}
		if ( self::check_passphrase( $plain ) ) {
			return 'producer';
		}
		return '';
	}

	private static function check_hash( $key, $plain ) {
		$hash = self::get( $key );
		if ( empty( $hash ) ) {
			return false;
		}
		return wp_check_password( (string) $plain, $hash );
	}

	/**
	 * 投稿者名の一覧。
	 */
	public static function author_names() {
		$authors = self::get( 'authors' );
		return is_array( $authors ) ? array_values( array_filter( $authors ) ) : array();
	}

	/**
	 * 守るカテゴリ（ツールから見せない・変えさせない）。
	 */
	public static function protected_categories() {
		$ids = self::get( 'protected_categories' );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	public static function is_protected_category( $term_id ) {
		return in_array( (int) $term_id, self::protected_categories(), true );
	}

	/**
	 * この記事は守るカテゴリに入っているか。
	 */
	public static function post_is_protected( $post_id ) {
		$ids = self::protected_categories();
		if ( empty( $ids ) ) {
			return false;
		}
		$terms = wp_get_post_categories( (int) $post_id );
		return (bool) array_intersect( $ids, array_map( 'intval', $terms ) );
	}

	/**
	 * 初めての名前なら一覧に足す（生産者が自分で名前を登録できるように）。
	 */
	public static function remember_author( $name ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' === $name ) {
			return;
		}
		$authors = self::author_names();
		if ( in_array( $name, $authors, true ) ) {
			return;
		}
		$authors[] = $name;
		self::update( array( 'authors' => array_slice( $authors, -50 ) ) );
	}

	/**
	 * カテゴリ名から term_id を得る。なければ作る。
	 */
	public static function ensure_category( $name ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$term = term_exists( $name, 'category' );
		if ( ! $term ) {
			$term = wp_insert_term( $name, 'category' );
		}
		return is_wp_error( $term ) ? 0 : (int) $term['term_id'];
	}

	/* ---------- 合言葉を覚えておくCookie ---------- */

	/** Cookieの名前 */
	const COOKIE = 'skt_auth';

	/** 覚えておく期間 */
	const COOKIE_DAYS = 180;

	/**
	 * Cookieに入れる合図。合言葉そのものは入れない。
	 * 合言葉を変えるとハッシュが変わるので、古いCookieは自動的に無効になる。
	 */
	private static function token( $role ) {
		$hash = self::get( 'admin' === $role ? 'admin_passphrase_hash' : 'passphrase_hash' );
		if ( empty( $hash ) ) {
			return '';
		}
		return hash_hmac( 'sha256', $role . '|' . $hash, wp_salt( 'auth' ) );
	}

	/**
	 * 合言葉が合っていたら、その端末に覚えさせる。
	 */
	public static function remember_role( $role ) {
		$token = self::token( $role );
		if ( '' === $token || headers_sent() ) {
			return;
		}

		setcookie(
			self::COOKIE,
			$role . ':' . $token,
			array(
				'expires'  => time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
				'path'     => wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Cookieから役割を読む。合わなければ空。
	 */
	public static function role_from_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return '';
		}

		$parts = explode( ':', sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ), 2 );
		if ( count( $parts ) !== 2 ) {
			return '';
		}

		$role = 'admin' === $parts[0] ? 'admin' : 'producer';
		$token = self::token( $role );

		return ( '' !== $token && hash_equals( $token, $parts[1] ) ) ? $role : '';
	}

	/**
	 * 投稿ページのURL。
	 */
	public static function form_url() {
		return home_url( '/' . trim( (string) self::get( 'slug' ), '/' ) . '/' );
	}

	/**
	 * LINE Webhook のURL。
	 */
	public static function line_webhook_url() {
		return rest_url( SKT_Rest::NS . '/line' );
	}
}
