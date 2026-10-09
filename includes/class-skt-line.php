<?php
/**
 * LINE公式アカウント（Messaging API）からの投稿。
 *
 * 流れ:
 *   1. 生産者が写真を送る → メディアライブラリに取り込み、その人の「かご」に貯める
 *   2. 続けて文章を送る   → かごの写真と文章で記事（下書き）を作る
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Line {

	/** かごの保持時間 */
	const BASKET_TTL = 3600;

	/** 1つの記事に入れられる写真の枚数 */
	const BASKET_MAX = 8;

	/**
	 * Webhook 本体。LINE には常に 200 を返す（再送を招かないため）。
	 */
	public static function handle_webhook( WP_REST_Request $request ) {
		$settings = SKT_Settings::all();

		if ( empty( $settings['line_enabled'] ) ) {
			return new WP_REST_Response( array( 'message' => 'disabled' ), 404 );
		}

		$body      = $request->get_body();
		$signature = $request->get_header( 'x_line_signature' );

		if ( ! self::verify_signature( $body, $signature, $settings['line_channel_secret'] ) ) {
			return new WP_REST_Response( array( 'message' => 'invalid signature' ), 403 );
		}

		$payload = json_decode( $body, true );
		$events  = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array();

		foreach ( $events as $event ) {
			self::handle_event( $event, $settings );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * 署名の照合。
	 */
	private static function verify_signature( $body, $signature, $secret ) {
		if ( empty( $signature ) || empty( $secret ) ) {
			return false;
		}
		$expected = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
		return hash_equals( $expected, $signature );
	}

	/**
	 * イベント1件の処理。
	 */
	private static function handle_event( array $event, array $settings ) {
		if ( 'message' !== ( isset( $event['type'] ) ? $event['type'] : '' ) ) {
			return;
		}

		$user_id    = isset( $event['source']['userId'] ) ? $event['source']['userId'] : '';
		$reply_token = isset( $event['replyToken'] ) ? $event['replyToken'] : '';
		$message    = isset( $event['message'] ) ? $event['message'] : array();

		if ( '' === $user_id ) {
			return;
		}

		$allowed = self::allowed_users( $settings['line_allowed_users'] );
		if ( ! empty( $allowed ) && ! array_key_exists( $user_id, $allowed ) ) {
			self::reply( $reply_token, 'このアカウントからは投稿できません。担当者にご連絡ください。', $settings );
			return;
		}

		switch ( isset( $message['type'] ) ? $message['type'] : '' ) {
			case 'image':
				self::handle_image( $message, $user_id, $reply_token, $settings );
				break;
			case 'text':
				self::handle_text( $message, $user_id, $reply_token, $settings, $allowed );
				break;
			default:
				self::reply( $reply_token, '写真と文章を送ってください。', $settings );
		}
	}

	/**
	 * 写真を受け取ってかごに入れる。
	 */
	private static function handle_image( array $message, $user_id, $reply_token, array $settings ) {
		$basket = self::get_basket( $user_id );

		if ( count( $basket ) >= self::BASKET_MAX ) {
			self::reply( $reply_token, sprintf( '写真は%d枚までです。先に文章を送って記事にしてください。', self::BASKET_MAX ), $settings );
			return;
		}

		$binary = self::fetch_content( $message['id'], $settings['line_access_token'] );
		if ( is_wp_error( $binary ) ) {
			self::reply( $reply_token, '写真を受け取れませんでした。もう一度送ってください。', $settings );
			return;
		}

		$attachment_id = SKT_Media::sideload_binary( $binary, 'line-' . $message['id'] . '.jpg' );
		if ( is_wp_error( $attachment_id ) ) {
			self::reply( $reply_token, '写真を保存できませんでした。もう一度送ってください。', $settings );
			return;
		}

		$basket[] = $attachment_id;
		self::set_basket( $user_id, $basket );

		self::reply(
			$reply_token,
			sprintf( "写真を受け取りました（%d枚）。\n続けて、ひとこと文章を送ってください。そのまま記事になります。", count( $basket ) ),
			$settings
		);
	}

	/**
	 * 文章を受け取って記事にする。
	 */
	private static function handle_text( array $message, $user_id, $reply_token, array $settings, array $allowed ) {
		$text = trim( (string) ( isset( $message['text'] ) ? $message['text'] : '' ) );

		if ( in_array( $text, array( '取消', 'とりけし', 'キャンセル' ), true ) ) {
			self::clear_basket( $user_id );
			self::reply( $reply_token, '送っていただいた写真を取り消しました。', $settings );
			return;
		}

		if ( '' === $text ) {
			return;
		}

		$basket = self::get_basket( $user_id );

		$author_name = isset( $allowed[ $user_id ] ) && '' !== $allowed[ $user_id ]
			? $allowed[ $user_id ]
			: self::fetch_display_name( $user_id, $settings['line_access_token'] );

		$post_id = SKT_Post_Creator::create(
			array(
				'body'           => $text,
				'author_name'    => $author_name,
				'category_id'    => (int) $settings['line_category'],
				'attachment_ids' => $basket,
				'source'         => 'line',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			self::reply( $reply_token, '記事を作れませんでした。もう一度お試しください。', $settings );
			return;
		}

		self::clear_basket( $user_id );

		$published = 'publish' === get_post_status( $post_id );
		self::reply(
			$reply_token,
			$published
				? "ブログに公開しました。ありがとうございます！"
				: "下書きとして保存しました。担当者が確認して公開します。ありがとうございます！",
			$settings
		);
	}

	/* ---------- かご（写真の一時保管） ---------- */

	private static function basket_key( $user_id ) {
		return 'skt_line_basket_' . md5( $user_id );
	}

	private static function get_basket( $user_id ) {
		$basket = get_transient( self::basket_key( $user_id ) );
		return is_array( $basket ) ? $basket : array();
	}

	private static function set_basket( $user_id, array $basket ) {
		set_transient( self::basket_key( $user_id ), $basket, self::BASKET_TTL );
	}

	private static function clear_basket( $user_id ) {
		delete_transient( self::basket_key( $user_id ) );
	}

	/* ---------- LINE API ---------- */

	/**
	 * 画像の実体を取得する。
	 */
	private static function fetch_content( $message_id, $token ) {
		$response = wp_remote_get(
			'https://api-data.line.me/v2/bot/message/' . rawurlencode( $message_id ) . '/content',
			array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'skt_line_content', 'LINEから画像を取得できませんでした。' );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * 表示名を取得する（設定に名前がないとき用）。
	 */
	private static function fetch_display_name( $user_id, $token ) {
		$response = wp_remote_get(
			'https://api.line.me/v2/bot/profile/' . rawurlencode( $user_id ),
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$profile = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $profile['displayName'] ) ? sanitize_text_field( $profile['displayName'] ) : '';
	}

	/**
	 * 返信を送る。
	 */
	private static function reply( $reply_token, $text, array $settings ) {
		if ( empty( $reply_token ) || empty( $settings['line_access_token'] ) ) {
			return;
		}

		wp_remote_post(
			'https://api.line.me/v2/bot/message/reply',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $settings['line_access_token'],
				),
				'body'    => wp_json_encode(
					array(
						'replyToken' => $reply_token,
						'messages'   => array(
							array(
								'type' => 'text',
								'text' => $text,
							),
						),
					)
				),
			)
		);
	}

	/**
	 * 「UserId,名前」を1行ずつ書いた設定を配列にする。
	 *
	 * @return array userId => 名前
	 */
	public static function allowed_users( $raw ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$parts   = array_map( 'trim', explode( ',', $line, 2 ) );
			$user_id = $parts[0];
			if ( '' === $user_id ) {
				continue;
			}
			$out[ $user_id ] = isset( $parts[1] ) ? $parts[1] : '';
		}
		return $out;
	}
}
