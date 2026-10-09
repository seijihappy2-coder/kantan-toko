<?php
/**
 * 投稿フォームが叩くエンドポイント。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Rest {

	const NS = 'shumei-toko/v1';

	/** 同じ回線から1時間に受け付ける送信回数 */
	const SUBMIT_LIMIT = 40;

	/** 同じ回線から1時間に受け付ける合言葉の確認回数 */
	const VERIFY_LIMIT = 20;

	/** 同じ回線から1時間に受け付ける管理操作の回数 */
	const MANAGE_LIMIT = 200;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_verify' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_submit' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/my-posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_my_posts' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_posts' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/update',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_update' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/category',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_category' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/gallery',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_gallery' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/trash',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_trash' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/line',
			array(
				'methods'             => 'POST',
				'callback'            => array( 'SKT_Line', 'handle_webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * 合言葉だけを確かめる（ログイン画面用）。
	 */
	public static function handle_verify( WP_REST_Request $request ) {
		if ( self::too_many( 'verify', self::VERIFY_LIMIT ) ) {
			return new WP_REST_Response( array( 'message' => 'しばらく時間をおいてからお試しください。' ), 429 );
		}
		$role = SKT_Settings::role_for( $request->get_param( 'passphrase' ) );
		if ( '' === $role ) {
			return new WP_REST_Response( array( 'message' => '合言葉が違います。' ), 403 );
		}
		return new WP_REST_Response(
			array(
				'ok'   => true,
				'role' => $role,
			),
			200
		);
	}

	/**
	 * 写真と文章を受け取って記事を作る。
	 */
	public static function handle_submit( WP_REST_Request $request ) {
		// 合言葉の判定より先に数える（総当たり対策のため）。
		if ( self::too_many( 'submit', self::SUBMIT_LIMIT ) ) {
			return new WP_REST_Response( array( 'message' => '短時間に送りすぎです。しばらく待ってからお試しください。' ), 429 );
		}
		if ( '' === SKT_Settings::role_for( $request->get_param( 'passphrase' ) ) ) {
			return new WP_REST_Response( array( 'message' => '合言葉が違います。画面を再読み込みして入れ直してください。' ), 403 );
		}

		$max_photos = (int) SKT_Settings::get( 'max_photos' );
		$files      = self::normalize_files( $request->get_file_params() );

		if ( count( $files ) > $max_photos ) {
			return new WP_REST_Response(
				array( 'message' => sprintf( '写真は一度に%d枚までです。', $max_photos ) ),
				400
			);
		}

		$attachment_ids = array();
		foreach ( $files as $file ) {
			$attachment_id = SKT_Media::sideload_uploaded_file( $file );
			if ( is_wp_error( $attachment_id ) ) {
				foreach ( $attachment_ids as $done ) {
					wp_delete_attachment( $done, true );
				}
				return new WP_REST_Response( array( 'message' => $attachment_id->get_error_message() ), 400 );
			}
			$attachment_ids[] = $attachment_id;
		}

		$author_name = (string) $request->get_param( 'author_name' );
		SKT_Settings::remember_author( $author_name );

		// 「＋ 新しいカテゴリ」で入力されたときは、その場で作る。
		$category_id  = (int) $request->get_param( 'category_id' );
		$new_category = (string) $request->get_param( 'new_category' );
		if ( '' !== trim( $new_category ) ) {
			$category_id = SKT_Settings::ensure_category( $new_category );
		}

		$post_id = SKT_Post_Creator::create(
			array(
				'title'          => (string) $request->get_param( 'title' ),
				'body'           => (string) $request->get_param( 'body' ),
				'author_name'    => $author_name,
				'category_id'    => $category_id,
				'attachment_ids' => $attachment_ids,
				'source'         => 'form',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			foreach ( $attachment_ids as $done ) {
				wp_delete_attachment( $done, true );
			}
			return new WP_REST_Response( array( 'message' => $post_id->get_error_message() ), 400 );
		}

		$published = 'publish' === get_post_status( $post_id );

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'post_id'    => $post_id,
				'published'  => $published,
				'categories' => self::category_list(),
				'message'   => $published
					? '投稿しました。ありがとうございます！'
					: '送信しました。担当者が確認して公開します。ありがとうございます！',
			),
			200
		);
	}

	/**
	 * 自分が送った記事の状態を返す（生産者用・見るだけ）。
	 */
	public static function handle_my_posts( WP_REST_Request $request ) {
		if ( self::too_many( 'submit', self::SUBMIT_LIMIT ) ) {
			return new WP_REST_Response( array( 'message' => '少し時間をおいてからお試しください。' ), 429 );
		}
		if ( '' === SKT_Settings::role_for( $request->get_param( 'passphrase' ) ) ) {
			return new WP_REST_Response( array( 'message' => '合言葉が違います。' ), 403 );
		}

		$author_name = sanitize_text_field( (string) $request->get_param( 'author_name' ) );
		if ( '' === $author_name ) {
			return new WP_REST_Response( array( 'ok' => true, 'posts' => array() ), 200 );
		}

		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => 10,
				'category__not_in' => SKT_Settings::protected_categories(),
				'meta_key'    => '_skt_author_name', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $author_name, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'id'         => (int) $post->ID,
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'statusText' => self::status_text( $post->post_status ),
				'date'       => get_the_date( 'n月j日 H:i', $post ),
				'link'       => 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post ),
			);
		}

		return new WP_REST_Response( array( 'ok' => true, 'posts' => $items ), 200 );
	}

	/**
	 * 触ってよい記事かどうか。
	 *
	 * 固定ページ（トップページ、秀明自然農法とは、お申込み など）はサイトの骨組みで、
	 * 消えると表示が壊れる。このツールからは一切さわらせない。
	 * 一覧に出さないだけでなく、ここで書き換えも止める。
	 *
	 * @return WP_Post|null 記事でなければ null。
	 */
	private static function editable_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return null;
		}
		if ( SKT_Settings::post_is_protected( $post->ID ) ) {
			return null; // 守るカテゴリの記事（農法の説明など）もさわらせない。
		}
		return $post;
	}

	/* ---------- 管理モード（公開・非公開の切り替え） ---------- */

	/**
	 * 管理者用の合言葉かどうか。違えばエラー応答を返す。
	 *
	 * @return WP_REST_Response|null 問題なければ null。
	 */
	private static function deny_unless_admin( WP_REST_Request $request ) {
		if ( self::too_many( 'manage', self::MANAGE_LIMIT ) ) {
			return new WP_REST_Response( array( 'message' => '短時間に操作しすぎです。しばらく待ってからお試しください。' ), 429 );
		}
		if ( ! SKT_Settings::check_admin_passphrase( $request->get_param( 'passphrase' ) ) ) {
			return new WP_REST_Response( array( 'message' => '管理者用の合言葉が必要です。' ), 403 );
		}
		return null;
	}

	/**
	 * 最近の記事の一覧を返す。
	 */
	public static function handle_posts( WP_REST_Request $request ) {
		$denied = self::deny_unless_admin( $request );
		if ( $denied ) {
			return $denied;
		}

		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'numberposts'      => 30,
				'category__not_in' => SKT_Settings::protected_categories(),
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$body = (string) get_post_meta( $post->ID, '_skt_body_text', true );
			if ( '' === $body ) {
				$body = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			}
			$body = trim( preg_replace( '/\s*\R\s*/u', "\n", $body ) );

			$thumb_id = get_post_thumbnail_id( $post->ID );

			$items[] = array(
				'id'         => (int) $post->ID,
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'statusText' => self::status_text( $post->post_status ),
				'date'       => get_the_date( 'n月j日 H:i', $post ),
				'thumb'      => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '',
				'excerpt'    => mb_strimwidth( $body, 0, 120, '…' ),
				'body'       => $body,
				'author'     => (string) get_post_meta( $post->ID, '_skt_author_name', true ),
				'source'     => (string) get_post_meta( $post->ID, '_skt_source', true ),
				'editable'   => '' !== get_post_meta( $post->ID, '_skt_source', true ),
				'link'       => 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post ),
			);
		}

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'posts'      => $items,
				'categories' => self::category_list(),
			),
			200
		);
	}

	/**
	 * 公開・非公開を切り替える。
	 */
	public static function handle_status( WP_REST_Request $request ) {
		$denied = self::deny_unless_admin( $request );
		if ( $denied ) {
			return $denied;
		}

		$post_id = (int) $request->get_param( 'post_id' );
		$status  = 'publish' === $request->get_param( 'status' ) ? 'publish' : 'draft';

		if ( ! self::editable_post( $post_id ) ) {
			return new WP_REST_Response( array( 'message' => 'この記事は変更できません。' ), 404 );
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'status'     => $status,
				'statusText' => self::status_text( $status ),
				'message'    => 'publish' === $status ? 'ブログに公開しました。' : '非公開に戻しました。',
			),
			200
		);
	}

	/**
	 * タイトルと文章を書き直す。
	 */
	public static function handle_update( WP_REST_Request $request ) {
		$denied = self::deny_unless_admin( $request );
		if ( $denied ) {
			return $denied;
		}

		$result = SKT_Post_Creator::update_text(
			(int) $request->get_param( 'post_id' ),
			(string) $request->get_param( 'title' ),
			(string) $request->get_param( 'body' )
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}

		return new WP_REST_Response( array( 'ok' => true, 'message' => '書き直しました。' ), 200 );
	}

	/**
	 * ゴミ箱へ移す（完全には消さない）。
	 */
	public static function handle_trash( WP_REST_Request $request ) {
		$denied = self::deny_unless_admin( $request );
		if ( $denied ) {
			return $denied;
		}

		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! self::editable_post( $post_id ) ) {
			return new WP_REST_Response( array( 'message' => 'この記事は削除できません。' ), 404 );
		}

		if ( ! wp_trash_post( $post_id ) ) {
			return new WP_REST_Response( array( 'message' => 'ゴミ箱へ移せませんでした。' ), 400 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'message' => 'ゴミ箱へ移しました。元に戻すときはWordPressの管理画面から戻せます。',
			),
			200
		);
	}

	/**
	 * カテゴリの追加・名前変更・削除。
	 * 削除したカテゴリの記事は、WordPressの既定カテゴリへ移る。
	 */
	public static function handle_category( WP_REST_Request $request ) {
		$denied = self::deny_unless_admin( $request );
		if ( $denied ) {
			return $denied;
		}

		$op   = (string) $request->get_param( 'op' );
		$id   = (int) $request->get_param( 'id' );
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );

		if ( in_array( $op, array( 'create', 'rename' ), true ) && '' === $name ) {
			return new WP_REST_Response( array( 'message' => 'カテゴリ名を入れてください。' ), 400 );
		}

		if ( $id && SKT_Settings::is_protected_category( $id ) ) {
			return new WP_REST_Response( array( 'message' => 'このカテゴリは変更できない設定になっています。' ), 403 );
		}

		switch ( $op ) {
			case 'create':
				$result = wp_insert_term( $name, 'category' );
				break;
			case 'rename':
				$result = wp_update_term( $id, 'category', array( 'name' => $name ) );
				break;
			case 'delete':
				if ( (int) get_option( 'default_category' ) === $id ) {
					return new WP_REST_Response( array( 'message' => '既定のカテゴリは削除できません。' ), 400 );
				}
				$result = wp_delete_term( $id, 'category' );
				break;
			default:
				return new WP_REST_Response( array( 'message' => '操作が分かりません。' ), 400 );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'categories' => self::category_list(),
				'message'    => 'create' === $op ? 'カテゴリを作りました。' : ( 'rename' === $op ? '名前を変えました。' : 'カテゴリを削除しました。' ),
			),
			200
		);
	}

	/**
	 * カテゴリの一覧（id / name / count）。
	 */
	public static function category_list() {
		$out = array();
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $term ) {
			if ( SKT_Settings::is_protected_category( $term->term_id ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $term->term_id,
				'name'  => $term->name,
				'count' => (int) $term->count,
			);
		}
		return $out;
	}

	/**
	 * ギャラリーの写真一覧と、出す／隠すの切り替え。
	 */
	public static function handle_gallery( WP_REST_Request $request ) {
		$denied = self::deny_unless_admin( $request );
		if ( $denied ) {
			return $denied;
		}

		$id = (int) $request->get_param( 'id' );
		if ( $id && ! SKT_Gallery::set_hidden( $id, (int) $request->get_param( 'hidden' ) ) ) {
			return new WP_REST_Response( array( 'message' => '写真が見つかりませんでした。' ), 404 );
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'photos' => SKT_Gallery::photos( 120, true ),
			),
			200
		);
	}

	/**
	 * 状態の日本語表示。
	 */
	private static function status_text( $status ) {
		switch ( $status ) {
			case 'publish':
				return '公開中';
			case 'future':
				return '予約';
			case 'private':
				return '非公開';
			case 'pending':
				return '確認待ち';
			default:
				return '下書き';
		}
	}

	/**
	 * $_FILES の photos[] 形式を1件ずつの配列にほどく。
	 */
	private static function normalize_files( array $file_params ) {
		if ( empty( $file_params['photos'] ) ) {
			return array();
		}

		$photos = $file_params['photos'];
		$files  = array();

		if ( ! is_array( $photos['name'] ) ) {
			return array( $photos );
		}

		$count = count( $photos['name'] );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( empty( $photos['name'][ $i ] ) ) {
				continue;
			}
			$files[] = array(
				'name'     => $photos['name'][ $i ],
				'type'     => $photos['type'][ $i ],
				'tmp_name' => $photos['tmp_name'][ $i ],
				'error'    => $photos['error'][ $i ],
				'size'     => $photos['size'][ $i ],
			);
		}

		return $files;
	}

	/**
	 * 同一IPからの連続アクセスを数える。
	 */
	private static function rate_key( $bucket ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'skt_rate_' . $bucket . '_' . md5( $ip );
	}

	/**
	 * 1回数えて、上限を超えていれば true。
	 */
	private static function too_many( $bucket, $limit ) {
		$key   = self::rate_key( $bucket );
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, HOUR_IN_SECONDS );
		return $count > $limit;
	}
}
