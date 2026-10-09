<?php
/**
 * 生産者が開く投稿ページの表示。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Frontend {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/**
	 * 投稿ページのURLを登録する。
	 */
	public static function add_rewrite_rules() {
		$slug = trim( (string) SKT_Settings::get( 'slug' ), '/' );
		if ( '' === $slug ) {
			$slug = 'kantan-toko';
		}
		add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/?$', 'index.php?skt_form=1', 'top' );
	}

	public static function add_query_var( $vars ) {
		$vars[] = 'skt_form';
		return $vars;
	}

	/**
	 * 投稿ページなら専用画面を出して終了する（テーマは読み込まない）。
	 */
	public static function maybe_render() {
		if ( ! get_query_var( 'skt_form' ) ) {
			return;
		}

		// 該当する投稿がないため WordPress は 404 とみなしている。200 に戻す。
		global $wp_query;
		$wp_query->is_404 = false;

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		self::render();
		exit;
	}

	/**
	 * カテゴリの選択肢。
	 */
	private static function categories() {
		$terms = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		$out = array();
		foreach ( $terms as $term ) {
			if ( SKT_Settings::is_protected_category( $term->term_id ) ) {
				continue; // 守るカテゴリは選ばせない。
			}
			$out[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			);
		}
		return $out;
	}

	/**
	 * 画面を出力する。
	 */
	public static function render() {
		$settings = SKT_Settings::all();

		$config = array(
			'verifyUrl'       => esc_url_raw( rest_url( SKT_Rest::NS . '/verify' ) ),
			'submitUrl'       => esc_url_raw( rest_url( SKT_Rest::NS . '/submit' ) ),
			'postsUrl'        => esc_url_raw( rest_url( SKT_Rest::NS . '/posts' ) ),
			'statusUrl'       => esc_url_raw( rest_url( SKT_Rest::NS . '/status' ) ),
			'updateUrl'       => esc_url_raw( rest_url( SKT_Rest::NS . '/update' ) ),
			'trashUrl'        => esc_url_raw( rest_url( SKT_Rest::NS . '/trash' ) ),
			'categoryUrl'     => esc_url_raw( rest_url( SKT_Rest::NS . '/category' ) ),
			'galleryUrl'      => esc_url_raw( rest_url( SKT_Rest::NS . '/gallery' ) ),
			'myPostsUrl'      => esc_url_raw( rest_url( SKT_Rest::NS . '/my-posts' ) ),
			'categories'      => self::categories(),
			'authors'         => SKT_Settings::author_names(),
			'defaultCategory' => (int) $settings['default_category'],
			'maxPhotos'       => (int) $settings['max_photos'],
			'needsSetup'      => empty( $settings['passphrase_hash'] ),
			'template'        => (string) $settings['template'],
			'today'           => wp_date( 'n月j日(D)' ),
			'siteName'        => get_bloginfo( 'name' ),
			'homeUrl'         => home_url( '/' ),
		);

		$css = file_get_contents( SKT_PATH . 'assets/app.css' );
		$js  = file_get_contents( SKT_PATH . 'assets/app.js' );
		?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#3f6b3a">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="かんたん投稿">
<title>かんたん投稿 | <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<style><?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
</head>
<body>
<div id="skt-app" class="skt-app">
	<header class="skt-header">
		<h1>かんたん投稿</h1>
		<p class="skt-sub"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
	</header>

	<nav id="skt-tabs" class="skt-tabs" hidden>
		<button type="button" class="skt-tab is-active" data-target="post">投稿する</button>
		<button type="button" class="skt-tab" data-target="manage">確認・公開</button>
	</nav>

	<section id="skt-login" class="skt-card" hidden>
		<h2>合言葉を入れてください</h2>
		<p class="skt-note">一度入れると、次からは省略できます。</p>
		<input type="password" id="skt-pass" inputmode="text" autocomplete="current-password" placeholder="合言葉">
		<button type="button" id="skt-login-btn" class="skt-btn skt-btn-primary">はじめる</button>
		<p id="skt-login-error" class="skt-error" hidden></p>
	</section>

	<section id="skt-setup" class="skt-card" hidden>
		<h2>まだ準備ができていません</h2>
		<p class="skt-note">管理画面の「かんたん投稿」で合言葉を設定してください。</p>
	</section>

	<form id="skt-form" class="skt-card" hidden>
		<label class="skt-label" for="skt-photos">写真</label>
		<p class="skt-note">最大<span id="skt-max"></span>枚。スマホで撮った写真をそのまま選べます。</p>
		<input type="file" id="skt-photos" accept="image/*" multiple hidden>
		<button type="button" id="skt-pick" class="skt-btn skt-btn-pick">
			<span class="skt-pick-icon">＋</span>
			<span>写真を選ぶ</span>
		</button>
		<div id="skt-previews" class="skt-previews"></div>

		<label class="skt-label" for="skt-author">名前</label>
		<p class="skt-note">はじめての方は入力してください。次からは候補に出ます。</p>
		<input type="text" id="skt-author" class="skt-input" list="skt-author-list" autocomplete="off" placeholder="お名前を入れてください">
		<datalist id="skt-author-list"></datalist>

		<label class="skt-label" for="skt-category">カテゴリ</label>
		<select id="skt-category" class="skt-select"></select>
		<input type="text" id="skt-new-category" class="skt-input skt-gap" placeholder="新しいカテゴリの名前" hidden>

		<label class="skt-label" for="skt-title">タイトル<span class="skt-optional">（空でもOK）</span></label>
		<input type="text" id="skt-title" class="skt-input" placeholder="例：小松菜の収穫がはじまりました">

		<label class="skt-label" for="skt-body">ひとこと</label>
		<div class="skt-item-actions skt-gap">
			<button type="button" id="skt-template" class="skt-btn skt-btn-small skt-btn-ghost" hidden>定型文を入れる</button>
			<button type="button" id="skt-template-save" class="skt-btn skt-btn-small skt-btn-ghost skt-btn-quiet" hidden>自分の定型文にする</button>
		</div>
		<textarea id="skt-body" class="skt-textarea" rows="6" placeholder="今日の畑のようす、味の感想、おすすめの食べ方など"></textarea>

		<button type="submit" id="skt-submit" class="skt-btn skt-btn-primary skt-btn-send">送信する</button>
		<p id="skt-form-error" class="skt-error" hidden></p>
		<?php if ( 'publish' !== $settings['post_status'] ) : ?>
		<p class="skt-note skt-foot">送信した記事は担当者が確認してから公開されます。</p>
		<?php endif; ?>
	</form>

	<details id="skt-mine" class="skt-card skt-help" hidden>
		<summary class="skt-summary">自分の投稿</summary>
		<p class="skt-note">名前を選ぶと、その名前で送った記事の今の状態が出ます。</p>
		<div id="skt-mine-list"></div>
	</details>

	<details id="skt-help" class="skt-card skt-help" hidden>
		<summary class="skt-summary">困ったときは</summary>
		<dl class="skt-help-list">
			<dt>またこのページを開きたい</dt>
			<dd>ブラウザの共有ボタン（□に↑のマーク）から「ホーム画面に追加」を選ぶと、アイコンから開けます。</dd>

			<dt>送信できない</dt>
			<dd>電波の良い場所で、もう一度「送信する」を押してください。書いた文章は消えずに残っています。</dd>

			<dt>写真を選びまちがえた</dt>
			<dd>写真の右上の × を押すと取り消せます。</dd>

			<dt>合言葉をまた聞かれた</dt>
			<dd>スマホのデータが消えたときに出ます。担当者に確認して、もう一度入れてください。</dd>

			<?php if ( ! empty( $settings['template'] ) ) : ?>
			<dt>「定型文を入れる」とは</dt>
			<dd>作業記録の雛形が入ります。自分の書き方に直してから「自分の定型文にする」を押すと、次からその形で出ます。</dd>
			<?php endif; ?>

			<?php if ( 'publish' !== $settings['post_status'] ) : ?>
			<dt>送ったのにブログに出ない</dt>
			<dd>すぐには出ません。担当者が確認してから公開します。</dd>
			<?php endif; ?>

			<?php if ( ! empty( $settings['help_contact'] ) ) : ?>
			<dt>それでも分からない</dt>
			<dd><?php echo esc_html( $settings['help_contact'] ); ?></dd>
			<?php endif; ?>
		</dl>
	</details>

	<section id="skt-sending" class="skt-card skt-center" hidden>
		<div class="skt-spinner"></div>
		<p id="skt-sending-text">写真を準備しています…</p>
		<div class="skt-progress"><div id="skt-progress-bar"></div></div>
	</section>

	<section id="skt-manage" hidden>
		<p id="skt-manage-toast" class="skt-toast" hidden></p>
		<div class="skt-manage-head">
			<p class="skt-note">新しい順に30件。ボタンひとつで公開・非公開を切り替えられます。</p>
			<button type="button" id="skt-reload" class="skt-btn skt-btn-ghost">最新の状態にする</button>
		</div>
		<details id="skt-cats" class="skt-item">
			<summary class="skt-summary">カテゴリを整理する</summary>
			<div id="skt-cat-list" class="skt-cat-list"></div>
			<div class="skt-item-actions">
				<input type="text" id="skt-cat-new" class="skt-input" placeholder="新しいカテゴリの名前">
				<button type="button" id="skt-cat-add" class="skt-btn skt-btn-small skt-btn-primary">追加</button>
			</div>
		</details>

		<details id="skt-gal" class="skt-item">
			<summary class="skt-summary">ギャラリーの写真</summary>
			<p class="skt-note">写真を押すと、ギャラリーに出す／隠すが切り替わります。薄い写真は隠れています。</p>
			<div id="skt-gal-grid" class="skt-gal-admin"></div>
		</details>

		<div id="skt-list" class="skt-list"></div>
	</section>

	<section id="skt-done" class="skt-card skt-center" hidden>
		<div class="skt-check">✓</div>
		<h2 id="skt-done-title">送信しました</h2>
		<p id="skt-done-text" class="skt-note"></p>
		<button type="button" id="skt-again" class="skt-btn skt-btn-primary">続けて投稿する</button>
		<a class="skt-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">サイトを見る</a>
	</section>
</div>
<script>window.SKT_CONFIG = <?php echo wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;</script>
<script><?php echo $js; // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
</body>
</html>
		<?php
	}
}
