<?php
/**
 * 画像をメディアライブラリへ取り込む処理。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Media {

	/** 受け付ける画像形式 */
	const ALLOWED_MIME = array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' );

	/** 1枚あたりの上限（縮小後の想定サイズに余裕を持たせた値） */
	const MAX_BYTES = 12582912; // 12MB

	/**
	 * $_FILES 相当の配列から添付ファイルを作る。
	 *
	 * @param array $file  name / type / tmp_name / error / size を持つ配列。
	 * @return int|WP_Error 添付ファイルID。
	 */
	public static function sideload_uploaded_file( array $file ) {
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'skt_upload_error', '写真のアップロードに失敗しました。通信環境のよい場所で、もう一度お試しください。' );
		}
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'skt_upload_invalid', '写真を受け取れませんでした。もう一度選び直してください。' );
		}
		if ( $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'skt_upload_too_big', '写真のサイズが大きすぎます。枚数を減らしてお試しください。' );
		}

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$type  = ! empty( $check['type'] ) ? $check['type'] : '';
		if ( ! in_array( $type, self::ALLOWED_MIME, true ) ) {
			return new WP_Error( 'skt_upload_type', '画像ファイル（JPEG・PNG）以外は送信できません。' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$moved = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( isset( $moved['error'] ) ) {
			return new WP_Error( 'skt_upload_move', $moved['error'] );
		}

		return self::attach( $moved['file'], $moved['type'], $moved['url'] );
	}

	/**
	 * バイナリ文字列から添付ファイルを作る（LINE から取得した画像用）。
	 *
	 * @param string $binary   画像データ。
	 * @param string $filename 保存したいファイル名。
	 * @return int|WP_Error 添付ファイルID。
	 */
	public static function sideload_binary( $binary, $filename ) {
		if ( '' === $binary ) {
			return new WP_Error( 'skt_binary_empty', '画像データが空です。' );
		}
		if ( strlen( $binary ) > self::MAX_BYTES ) {
			return new WP_Error( 'skt_binary_too_big', '画像のサイズが大きすぎます。' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( sanitize_file_name( $filename ), null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'skt_binary_write', $upload['error'] );
		}

		$check = wp_check_filetype_and_ext( $upload['file'], $upload['file'] );
		$type  = ! empty( $check['type'] ) ? $check['type'] : '';
		if ( ! in_array( $type, self::ALLOWED_MIME, true ) ) {
			wp_delete_file( $upload['file'] );
			return new WP_Error( 'skt_binary_type', '画像ファイル以外は取り込めません。' );
		}

		return self::attach( $upload['file'], $type, $upload['url'] );
	}

	/**
	 * 保存済みファイルをメディアライブラリに登録し、サムネイルを生成する。
	 */
	private static function attach( $path, $type, $url ) {
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $type,
				'post_title'     => sanitize_text_field( pathinfo( $path, PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$path
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			wp_delete_file( $path );
			return new WP_Error( 'skt_attach_failed', '写真をメディアライブラリに登録できませんでした。' );
		}

		$meta = wp_generate_attachment_metadata( $attachment_id, $path );
		wp_update_attachment_metadata( $attachment_id, $meta );

		return (int) $attachment_id;
	}
}
