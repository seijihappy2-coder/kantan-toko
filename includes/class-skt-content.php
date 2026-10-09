<?php
/**
 * 記事の下に、毎回同じ案内（自然栽培の紹介動画など）を自動で付ける。
 *
 * 記事そのものには書き込まない。表示するときに足すだけなので、
 * 設定を変えれば過去の記事も一度に変わる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Content {

	/** 動画の埋め込みHTMLを覚えておく時間 */
	const CACHE_TTL = WEEK_IN_SECONDS;

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'append_footer' ), 20 );
	}

	/**
	 * 記事ページの本文の後ろに足す。一覧や抜粋には出さない。
	 */
	public static function append_footer( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$url = trim( (string) SKT_Settings::get( 'video_url' ) );
		if ( '' === $url ) {
			return $content;
		}

		$heading = trim( (string) SKT_Settings::get( 'video_heading' ) );

		$block  = '<div class="skt-article-footer">';
		$block .= '<hr class="skt-article-line">';
		if ( '' !== $heading ) {
			$block .= '<h3 class="skt-article-heading">' . esc_html( $heading ) . '</h3>';
		}
		$block .= self::embed( $url );
		$block .= '</div>';
		$block .= '<style>'
			. '.skt-article-footer{margin-top:40px}'
			. '.skt-article-line{border:0;border-top:1px solid #d9d4c7;margin:0 0 20px}'
			. '.skt-article-heading{margin:0 0 12px;font-size:18px}'
			. '.skt-article-footer iframe{max-width:100%}'
			. '</style>';

		return $content . $block;
	}

	/**
	 * 動画を埋め込む。埋め込めない相手ならリンクにする。
	 */
	private static function embed( $url ) {
		$key    = 'skt_embed_' . md5( $url );
		$cached = get_transient( $key );

		if ( false === $cached ) {
			$cached = wp_oembed_get( $url, array( 'width' => 720 ) );
			if ( ! $cached ) {
				$cached = '';
			}
			set_transient( $key, $cached, self::CACHE_TTL );
		}

		if ( '' === $cached ) {
			return '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">動画を見る</a></p>';
		}

		return '<div class="skt-article-video">' . $cached . '</div>';
	}

	/**
	 * 設定が変わったら、覚えている埋め込みを捨てる。
	 */
	public static function forget( $url ) {
		delete_transient( 'skt_embed_' . md5( trim( (string) $url ) ) );
	}
}
