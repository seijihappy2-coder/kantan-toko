<?php
/**
 * 投稿フォームからも LINE からも共通で使う記事作成処理。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Post_Creator {

	/**
	 * 記事を作る。
	 *
	 * @param array $args {
	 *     @type string $title          タイトル（空なら本文から自動生成）。
	 *     @type string $body           本文。
	 *     @type string $author_name    生産者の表示名。
	 *     @type int    $category_id    カテゴリID。
	 *     @type array  $attachment_ids 添付ファイルID。
	 *     @type string $source         'form' か 'line'。
	 * }
	 * @return int|WP_Error 投稿ID。
	 */
	public static function create( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'title'          => '',
				'body'           => '',
				'author_name'    => '',
				'category_id'    => 0,
				'attachment_ids' => array(),
				'source'         => 'form',
			)
		);

		$body           = trim( (string) $args['body'] );
		$attachment_ids = array_values( array_filter( array_map( 'intval', (array) $args['attachment_ids'] ) ) );

		if ( '' === $body && empty( $attachment_ids ) ) {
			return new WP_Error( 'skt_empty', '写真か文章のどちらかは入れてください。' );
		}

		$title = trim( (string) $args['title'] );
		if ( '' === $title ) {
			$title = self::title_from_body( $body );
		}

		$settings = SKT_Settings::all();

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => self::build_content( $body, $attachment_ids ),
				'post_status'  => 'publish' === $settings['post_status'] ? 'publish' : 'draft',
				'post_type'    => 'post',
				'post_author'  => (int) $settings['post_author_id'] ? (int) $settings['post_author_id'] : 1,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$category_id = (int) $args['category_id'];
		if ( ! $category_id ) {
			$category_id = (int) $settings['default_category'];
		}
		if ( $category_id && term_exists( $category_id, 'category' ) ) {
			wp_set_post_categories( $post_id, array( $category_id ) );
		}

		if ( ! empty( $attachment_ids ) ) {
			set_post_thumbnail( $post_id, $attachment_ids[0] );
			foreach ( $attachment_ids as $attachment_id ) {
				wp_update_post(
					array(
						'ID'          => $attachment_id,
						'post_parent' => $post_id,
					)
				);
			}
		}

		$author_name = sanitize_text_field( $args['author_name'] );
		if ( '' !== $author_name ) {
			update_post_meta( $post_id, '_skt_author_name', $author_name );
		}
		update_post_meta( $post_id, '_skt_source', 'line' === $args['source'] ? 'line' : 'form' );

		// あとからツール上で文章を直せるように、元の文章と写真の順番を覚えておく。
		update_post_meta( $post_id, '_skt_body_text', $body );
		update_post_meta( $post_id, '_skt_photo_ids', $attachment_ids );

		self::notify_admin( $post_id, $author_name, $args['source'] );

		return (int) $post_id;
	}

	/**
	 * ツールから作った記事の、タイトルと文章を書き直す。
	 * 写真はそのまま（順番も）残す。
	 *
	 * @return true|WP_Error
	 */
	public static function update_text( $post_id, $title, $body ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'skt_not_found', '記事が見つかりませんでした。' );
		}
		if ( '' === get_post_meta( $post_id, '_skt_source', true ) ) {
			return new WP_Error( 'skt_not_editable', 'この記事はこのツールで作ったものではないため、文章を直せません。' );
		}

		$body  = trim( (string) $body );
		$title = trim( (string) $title );
		if ( '' === $title ) {
			$title = self::title_from_body( $body );
		}

		$photo_ids = get_post_meta( $post_id, '_skt_photo_ids', true );
		$photo_ids = is_array( $photo_ids ) ? array_map( 'intval', $photo_ids ) : array();

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => self::build_content( $body, $photo_ids ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_post_meta( $post_id, '_skt_body_text', $body );

		return true;
	}

	/**
	 * 本文の先頭から見出しを作る。
	 */
	private static function title_from_body( $body ) {
		if ( '' === $body ) {
			return sprintf( '%s の畑から', wp_date( 'n月j日' ) );
		}
		$lines = preg_split( '/\r\n|\r|\n/', $body );
		$first = trim( (string) $lines[0] );
		if ( '' === $first ) {
			return sprintf( '%s の畑から', wp_date( 'n月j日' ) );
		}
		if ( mb_strlen( $first ) > 40 ) {
			$first = mb_substr( $first, 0, 40 ) . '…';
		}
		return $first;
	}

	/**
	 * 本文と写真からブロックエディタ用のHTMLを組み立てる。
	 */
	private static function build_content( $body, array $attachment_ids ) {
		$blocks = array();

		$body = wp_strip_all_tags( $body );
		if ( '' !== $body ) {
			$paragraphs = preg_split( '/(\r\n|\r|\n){2,}/', $body );
			foreach ( $paragraphs as $paragraph ) {
				$paragraph = trim( $paragraph );
				if ( '' === $paragraph ) {
					continue;
				}
				$html     = nl2br( esc_html( $paragraph ) );
				$blocks[] = "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->";
			}
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$src = wp_get_attachment_image_url( $attachment_id, 'large' );
			if ( ! $src ) {
				continue;
			}
			$alt      = esc_attr( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
			$blocks[] = sprintf(
				"<!-- wp:image {\"id\":%1\$d,\"sizeSlug\":\"large\",\"linkDestination\":\"none\"} -->\n" .
				"<figure class=\"wp-block-image size-large\"><img src=\"%2\$s\" alt=\"%3\$s\" class=\"wp-image-%1\$d\"/></figure>\n" .
				'<!-- /wp:image -->',
				$attachment_id,
				esc_url( $src ),
				$alt
			);
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * 管理者に「新しい投稿が届いた」と知らせる。
	 */
	private static function notify_admin( $post_id, $author_name, $source ) {
		$to = SKT_Settings::get( 'notify_email' );
		if ( empty( $to ) || ! is_email( $to ) ) {
			return;
		}

		$post   = get_post( $post_id );
		$status = get_post_status( $post_id );
		$label  = 'line' === $source ? 'LINE' : '投稿ページ';

		$subject = sprintf( '[%s] 新しい記事が届きました：%s', get_bloginfo( 'name' ), $post->post_title );

		$lines = array(
			sprintf( '%s から新しい記事が届きました。', $label ),
			'',
			'タイトル: ' . $post->post_title,
			'投稿者  : ' . ( '' !== $author_name ? $author_name : '（未入力）' ),
			'状態    : ' . ( 'publish' === $status ? '公開済み' : '下書き（未公開）' ),
			'',
			'スマホから確認して公開する:',
			SKT_Settings::form_url(),
			'（管理者用の合言葉で入り、「確認・公開」を開いてください）',
			'',
			'パソコンの管理画面で編集する:',
			admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		);

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
