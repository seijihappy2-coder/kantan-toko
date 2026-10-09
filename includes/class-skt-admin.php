<?php
/**
 * 管理画面（設定と、記事一覧での投稿者表示）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Admin {

	const PAGE = 'kantan-toko';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_skt_save_settings', array( __CLASS__, 'save' ) );
		add_filter( 'manage_posts_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	public static function add_menu() {
		add_menu_page(
			'かんたん投稿',
			'かんたん投稿',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' ),
			'dashicons-camera',
			26
		);
	}

	/**
	 * 設定の保存。
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'skt_save_settings' );

		$before = SKT_Settings::all();

		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = 'kantan-toko';
		}

		$authors = array();
		foreach ( preg_split( '/\r\n|\r|\n/', wp_unslash( $_POST['authors'] ?? '' ) ) as $line ) {
			$line = sanitize_text_field( trim( $line ) );
			if ( '' !== $line ) {
				$authors[] = $line;
			}
		}

		SKT_Settings::update(
			array(
				'slug'                => $slug,
				'authors'             => $authors,
				'default_category'    => (int) ( $_POST['default_category'] ?? 0 ),
				'protected_categories' => array_map( 'intval', (array) ( $_POST['protected_categories'] ?? array() ) ),
				'post_status'         => 'publish' === ( $_POST['post_status'] ?? '' ) ? 'publish' : 'draft',
				'notify_email'        => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
				'post_author_id'      => (int) ( $_POST['post_author_id'] ?? 0 ),
				'max_photos'          => max( 1, min( 20, (int) ( $_POST['max_photos'] ?? 8 ) ) ),
				'template'            => sanitize_textarea_field( wp_unslash( $_POST['template'] ?? '' ) ),
				'help_contact'        => sanitize_text_field( wp_unslash( $_POST['help_contact'] ?? '' ) ),
				'video_url'           => esc_url_raw( wp_unslash( $_POST['video_url'] ?? '' ) ),
				'video_heading'       => sanitize_text_field( wp_unslash( $_POST['video_heading'] ?? '' ) ),
				'line_enabled'        => empty( $_POST['line_enabled'] ) ? 0 : 1,
				'line_channel_secret' => sanitize_text_field( wp_unslash( $_POST['line_channel_secret'] ?? '' ) ),
				'line_access_token'   => sanitize_text_field( wp_unslash( $_POST['line_access_token'] ?? '' ) ),
				'line_allowed_users'  => sanitize_textarea_field( wp_unslash( $_POST['line_allowed_users'] ?? '' ) ),
				'line_category'       => (int) ( $_POST['line_category'] ?? 0 ),
			)
		);

		$short = false;

		$passphrase = trim( wp_unslash( $_POST['passphrase'] ?? '' ) );
		if ( '' !== $passphrase && ! SKT_Settings::set_passphrase( $passphrase ) ) {
			$short = true;
		}

		$admin_passphrase = trim( wp_unslash( $_POST['admin_passphrase'] ?? '' ) );
		if ( ! empty( $_POST['admin_passphrase_clear'] ) ) {
			SKT_Settings::clear_admin_passphrase();
		} elseif ( '' !== $admin_passphrase && ! SKT_Settings::set_admin_passphrase( $admin_passphrase ) ) {
			$short = true;
		}

		SKT_Content::forget( $before['video_url'] );
		SKT_Content::forget( $_POST['video_url'] ?? '' );

		if ( $before['slug'] !== $slug ) {
			SKT_Frontend::add_rewrite_rules();
			flush_rewrite_rules();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE,
					'updated' => '1',
					'short'   => $short ? '1' : null,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * 設定画面。
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s          = SKT_Settings::all();
		$form_url   = SKT_Settings::form_url();
		$categories = get_categories( array( 'hide_empty' => false ) );
		?>
		<div class="wrap">
			<h1>かんたん投稿</h1>

			<?php if ( ! empty( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['short'] ) ) : ?>
				<div class="notice notice-error"><p>
					合言葉が短すぎるため変更しませんでした。<?php echo (int) SKT_Settings::MIN_PASSPHRASE; ?>文字以上にしてください。
				</p></div>
			<?php endif; ?>

			<?php if ( empty( $s['passphrase_hash'] ) ) : ?>
				<div class="notice notice-warning"><p>まだ合言葉が設定されていません。設定するまで投稿ページは使えません。</p></div>
			<?php endif; ?>

			<div class="card" style="max-width:none;">
				<h2>生産者に伝えるURL</h2>
				<p style="font-size:16px;">
					<a href="<?php echo esc_url( $form_url ); ?>" target="_blank"><?php echo esc_html( $form_url ); ?></a>
				</p>
				<p class="description">
					このURLと合言葉を生産者に伝えてください。スマホで開いて「ホーム画面に追加」しておくとアプリのように使えます。<br>
					管理者の方も同じURLです。<strong>管理者用の合言葉</strong>で入ると「確認・公開」画面が増えます。
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="skt_save_settings">
				<?php wp_nonce_field( 'skt_save_settings' ); ?>

				<h2>基本の設定</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="skt-passphrase">合言葉</label></th>
						<td>
							<input type="text" id="skt-passphrase" name="passphrase" class="regular-text" autocomplete="off"
								placeholder="<?php echo empty( $s['passphrase_hash'] ) ? '例：ibaraki2026' : '変更するときだけ入力'; ?>">
							<p class="description">
								<?php echo empty( $s['passphrase_hash'] ) ? '生産者全員で共有する合言葉です。' : '設定済みです。空のままなら変更されません。'; ?>
								<strong><?php echo (int) SKT_Settings::MIN_PASSPHRASE; ?>文字以上</strong>にしてください（短いと破られます）。
								推測されにくい語を2つつなげる形がおすすめです。例：<code>hatake-asaichi-26</code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-admin-passphrase">管理者用の合言葉</label></th>
						<td>
							<input type="text" id="skt-admin-passphrase" name="admin_passphrase" class="regular-text" autocomplete="off"
								placeholder="<?php echo empty( $s['admin_passphrase_hash'] ) ? '例：kanri-2026' : '変更するときだけ入力'; ?>">
							<p class="description">
								こちらの合言葉で投稿ページに入ると、<strong>「確認・公開」画面</strong>が出ます。
								届いた記事をスマホで確認して、公開・非公開の切り替えや文章の手直しができます。
								<?php echo empty( $s['admin_passphrase_hash'] ) ? '設定しなければ管理モードは使えません。' : '設定済みです。'; ?>
								こちらも<?php echo (int) SKT_Settings::MIN_PASSPHRASE; ?>文字以上にしてください。
							</p>
							<?php if ( ! empty( $s['admin_passphrase_hash'] ) ) : ?>
								<label><input type="checkbox" name="admin_passphrase_clear" value="1"> 管理者用の合言葉を削除する（管理モードを使わない）</label>
							<?php endif; ?>
							<p class="description"><strong>生産者には伝えないでください。</strong>伝えると誰でも公開・非公開を操作できます。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-authors">生産者の名前</label></th>
						<td>
							<textarea id="skt-authors" name="authors" rows="6" class="large-text" placeholder="1行に1人ずつ"><?php echo esc_textarea( implode( "\n", (array) $s['authors'] ) ); ?></textarea>
							<p class="description">投稿ページで名前の候補として出ます。空でも構いません。生産者が投稿時に入力した名前は、ここへ自動で追加されます。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">送られた記事の扱い</th>
						<td>
							<label><input type="radio" name="post_status" value="draft" <?php checked( 'draft', $s['post_status'] ); ?>> 下書きとして保存する（管理者が確認して公開）</label><br>
							<label><input type="radio" name="post_status" value="publish" <?php checked( 'publish', $s['post_status'] ); ?>> そのまま公開する</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-default-category">既定のカテゴリ</label></th>
						<td>
							<?php self::category_select( 'default_category', (int) $s['default_category'], $categories ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row">守るカテゴリ</th>
						<td>
							<fieldset>
								<?php foreach ( $categories as $category ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="protected_categories[]" value="<?php echo (int) $category->term_id; ?>"
											<?php checked( SKT_Settings::is_protected_category( $category->term_id ) ); ?>>
										<?php echo esc_html( $category->name ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">
								選んだカテゴリは、投稿ページの選択肢に出ず、名前の変更も削除もできません。
								そのカテゴリの記事も「確認・公開」に出ず、公開・非公開の切り替えや削除ができません。<br>
								農法の説明記事など、生産者の日常投稿と混ぜたくないものに使ってください。
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-notify">お知らせメール</label></th>
						<td>
							<input type="email" id="skt-notify" name="notify_email" class="regular-text" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
							<p class="description">新しい記事が届いたときに知らせます。空にすると送りません。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-author-id">記事の作成者</label></th>
						<td>
							<?php
							// 購読者や申込者まで並ぶと選びにくいので、記事を持てる権限の人だけ出す。
							wp_dropdown_users(
								array(
									'name'     => 'post_author_id',
									'id'       => 'skt-author-id',
									'selected' => (int) $s['post_author_id'],
									'role__in' => array( 'administrator', 'editor', 'author', 'contributor' ),
								)
							);
							?>
							<p class="description">WordPress上の作成者です。生産者の名前は記事一覧の「生産者」欄に表示されます。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-max-photos">写真の枚数</label></th>
						<td><input type="number" id="skt-max-photos" name="max_photos" min="1" max="20" value="<?php echo esc_attr( $s['max_photos'] ); ?>" class="small-text"> 枚まで</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-template">定型文</label></th>
						<td>
							<textarea id="skt-template" name="template" rows="6" class="large-text"><?php echo esc_textarea( $s['template'] ); ?></textarea>
							<p class="description">
								投稿画面の「定型文を入れる」ボタンで差し込まれます。<code>{日付}</code> は今日の日付（例：10月9日(金)）に置き換わります。
								空にするとボタンが出ません。
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-video-url">記事の下に入れる動画</label></th>
						<td>
							<input type="url" id="skt-video-url" name="video_url" class="regular-text" value="<?php echo esc_attr( $s['video_url'] ); ?>" placeholder="https://www.youtube.com/watch?v=...">
							<p class="description">
								すべての記事の本文の下に、この動画を自動で表示します。記事を書くたびに貼る必要はありません。
								空にすると表示しません。<strong>記事自体には書き込まないので、変更すれば過去の記事もまとめて変わります。</strong>
							</p>
							<p>
								<label for="skt-video-heading">見出し</label><br>
								<input type="text" id="skt-video-heading" name="video_heading" class="regular-text" value="<?php echo esc_attr( $s['video_heading'] ); ?>" placeholder="自然栽培について">
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-help-contact">困ったときの連絡先</label></th>
						<td>
							<input type="text" id="skt-help-contact" name="help_contact" class="regular-text" value="<?php echo esc_attr( $s['help_contact'] ); ?>" placeholder="例：事務局（000-0000-0000）">
							<p class="description">投稿ページの「困ったときは」に表示します。空なら出ません。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-slug">投稿ページのURL</label></th>
						<td>
							<?php echo esc_html( trailingslashit( home_url() ) ); ?>
							<input type="text" id="skt-slug" name="slug" value="<?php echo esc_attr( $s['slug'] ); ?>" class="regular-text" style="width:200px;"> /
							<p class="description">変更した直後にページが出ないときは「設定 &gt; パーマリンク」を一度開いてください。</p>
						</td>
					</tr>
				</table>

				<h2>LINEから投稿する（任意）</h2>
				<p class="description">
					LINE公式アカウントに写真を送り、続けて文章を送ると記事になります。
					LINE Developers で Messaging API のチャネルを作り、Webhook URL に次のアドレスを登録してください。<br>
					<code><?php echo esc_html( SKT_Settings::line_webhook_url() ); ?></code>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">LINE連携</th>
						<td><label><input type="checkbox" name="line_enabled" value="1" <?php checked( 1, (int) $s['line_enabled'] ); ?>> 使う</label></td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-line-secret">チャネルシークレット</label></th>
						<td><input type="text" id="skt-line-secret" name="line_channel_secret" class="regular-text" autocomplete="off" value="<?php echo esc_attr( $s['line_channel_secret'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-line-token">チャネルアクセストークン</label></th>
						<td><textarea id="skt-line-token" name="line_access_token" rows="3" class="large-text" autocomplete="off"><?php echo esc_textarea( $s['line_access_token'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-line-users">投稿できる人</label></th>
						<td>
							<textarea id="skt-line-users" name="line_allowed_users" rows="5" class="large-text" placeholder="Uxxxxxxxx,ハウス担当"><?php echo esc_textarea( $s['line_allowed_users'] ); ?></textarea>
							<p class="description">「LINEのユーザーID,名前」を1行ずつ。空にすると友だち全員が投稿できます。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="skt-line-category">LINE投稿のカテゴリ</label></th>
						<td><?php self::category_select( 'line_category', (int) $s['line_category'], $categories ); ?></td>
					</tr>
				</table>

				<?php submit_button( '保存する' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * カテゴリの選択欄。
	 */
	private static function category_select( $name, $selected, $categories ) {
		echo '<select name="' . esc_attr( $name ) . '" id="skt-' . esc_attr( str_replace( '_', '-', $name ) ) . '">';
		echo '<option value="0">（指定しない）</option>';
		foreach ( $categories as $category ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $category->term_id,
				selected( $selected, (int) $category->term_id, false ),
				esc_html( $category->name )
			);
		}
		echo '</select>';
	}

	/* ---------- 記事一覧に「生産者」欄を足す ---------- */

	public static function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['skt_author'] = '生産者';
			}
		}
		return $new;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'skt_author' !== $column ) {
			return;
		}
		$name   = get_post_meta( $post_id, '_skt_author_name', true );
		$source = get_post_meta( $post_id, '_skt_source', true );
		if ( '' === $name && '' === $source ) {
			echo '—';
			return;
		}
		echo esc_html( $name ? $name : '（名前なし）' );
		if ( 'line' === $source ) {
			echo ' <span class="description">(LINE)</span>';
		}
	}
}
