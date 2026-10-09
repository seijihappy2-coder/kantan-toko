<?php
/**
 * 写真ギャラリー。
 *
 * かんたん投稿から送られ、公開されている記事の写真だけを新しい順に並べる。
 * ページには [kantan_gallery] を置くだけ。手で並べ直す必要はない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKT_Gallery {

	/** 隠した写真につける印 */
	const HIDDEN_META = '_skt_gallery_hidden';

	public static function init() {
		add_shortcode( 'kantan_gallery', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * 並べる写真を集める。
	 *
	 * @param int  $limit         最大枚数。
	 * @param bool $include_hidden 隠した写真も含めるか（管理モード用）。
	 * @return array
	 */
	public static function photos( $limit = 200, $include_hidden = false, $category = '' ) {
		$args = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'numberposts' => 200,
			'orderby'     => 'date',
			'order'       => 'DESC',
		);

		// カテゴリは画面に出ている名前で指定できるようにする（スラッグやIDでも可、カンマ区切りで複数も可）。
		$category = trim( (string) $category );
		if ( '' !== $category ) {
			$ids = array();
			foreach ( array_map( 'trim', explode( ',', $category ) ) as $one ) {
				if ( '' === $one ) {
					continue;
				}
				if ( is_numeric( $one ) ) {
					$ids[] = (int) $one;
					continue;
				}
				$term = get_term_by( 'name', $one, 'category' );
				if ( ! $term ) {
					$term = get_term_by( 'slug', $one, 'category' );
				}
				if ( $term ) {
					$ids[] = (int) $term->term_id;
				}
			}

			if ( empty( $ids ) ) {
				return array(); // 指定されたカテゴリが見つからない。
			}

			$args['category__in'] = $ids;
		}

		$posts = get_posts( $args );

		if ( empty( $posts ) ) {
			return array();
		}

		// 写真 → それが載っている記事。アイキャッチ画像と、記事に添付された画像の両方を拾う。
		$owner = array();
		foreach ( $posts as $post ) {
			$thumb_id = get_post_thumbnail_id( $post->ID );
			if ( $thumb_id ) {
				$owner[ (int) $thumb_id ] = $post;
			}
		}

		$attached = get_posts(
			array(
				'post_type'       => 'attachment',
				'post_mime_type'  => 'image',
				'post_status'     => 'inherit',
				'post_parent__in' => wp_list_pluck( $posts, 'ID' ),
				'numberposts'     => 500,
			)
		);

		$by_id = array();
		foreach ( $attached as $attachment ) {
			$by_id[ (int) $attachment->ID ] = $attachment;
			if ( ! isset( $owner[ (int) $attachment->ID ] ) ) {
				$owner[ (int) $attachment->ID ] = get_post( $attachment->post_parent );
			}
		}

		// アイキャッチは記事に添付されていないことが多いので、足りない分をまとめて取る。
		$missing = array_diff( array_keys( $owner ), array_keys( $by_id ) );
		if ( ! empty( $missing ) ) {
			foreach ( get_posts(
				array(
					'post_type'      => 'attachment',
					'post_mime_type' => 'image',
					'post_status'    => 'inherit',
					'post__in'       => $missing,
					'numberposts'    => count( $missing ),
				)
			) as $attachment ) {
				$by_id[ (int) $attachment->ID ] = $attachment;
			}
		}

		// 新しい写真から順に。
		uasort(
			$by_id,
			function ( $a, $b ) {
				return strcmp( $b->post_date, $a->post_date );
			}
		);

		$photos = array();
		foreach ( $by_id as $id => $attachment ) {
			$is_hidden = '' !== (string) get_post_meta( $id, self::HIDDEN_META, true );
			if ( $is_hidden && ! $include_hidden ) {
				continue;
			}

			$thumb = wp_get_attachment_image_url( $id, 'medium_large' );
			if ( ! $thumb ) {
				continue;
			}
			$full = wp_get_attachment_image_url( $id, 'large' );

			$parent = isset( $owner[ $id ] ) ? $owner[ $id ] : null;

			$photos[] = array(
				'id'     => (int) $id,
				'thumb'  => $thumb,
				'full'   => $full ? $full : $thumb,
				'title'  => $parent ? $parent->post_title : '',
				'date'   => $parent ? get_the_date( 'Y年n月j日', $parent ) : '',
				'link'   => $parent ? get_permalink( $parent ) : '',
				'author' => $parent ? (string) get_post_meta( $parent->ID, '_skt_author_name', true ) : '',
				'hidden' => $is_hidden,
			);

			if ( count( $photos ) >= (int) $limit ) {
				break;
			}
		}

		return $photos;
	}

	/**
	 * 写真をギャラリーに出す／隠す。
	 */
	public static function set_hidden( $attachment_id, $hidden ) {
		$attachment_id = (int) $attachment_id;
		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}
		if ( $hidden ) {
			update_post_meta( $attachment_id, self::HIDDEN_META, '1' );
		} else {
			delete_post_meta( $attachment_id, self::HIDDEN_META );
		}
		return true;
	}

	/**
	 * [kantan_gallery limit="200" columns="4"]
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'    => 200,
				'columns'  => 4,
				'category' => '',
			),
			$atts,
			'kantan_gallery'
		);

		$photos = self::photos( (int) $atts['limit'], false, (string) $atts['category'] );
		if ( empty( $photos ) ) {
			return '<p>公開中の記事に写真がありません。記事を公開すると、その写真がここに並びます。</p>';
		}

		$columns = max( 2, min( 6, (int) $atts['columns'] ) );

		ob_start();
		?>
		<div class="skt-gal" style="--skt-gal-cols:<?php echo (int) $columns; ?>">
			<?php foreach ( $photos as $index => $photo ) : ?>
				<button type="button" class="skt-gal-cell"
					data-index="<?php echo (int) $index; ?>"
					data-full="<?php echo esc_url( $photo['full'] ); ?>"
					data-title="<?php echo esc_attr( $photo['title'] ); ?>"
					data-date="<?php echo esc_attr( $photo['date'] ); ?>"
					data-author="<?php echo esc_attr( $photo['author'] ); ?>"
					data-link="<?php echo esc_url( $photo['link'] ); ?>">
					<img src="<?php echo esc_url( $photo['thumb'] ); ?>"
						alt="<?php echo esc_attr( $photo['title'] ); ?>"
						loading="lazy" decoding="async">
				</button>
			<?php endforeach; ?>
		</div>

		<dialog class="skt-gal-view">
			<button type="button" class="skt-gal-close" aria-label="閉じる">×</button>
			<button type="button" class="skt-gal-prev" aria-label="前の写真">‹</button>
			<img class="skt-gal-img" src="" alt="">
			<button type="button" class="skt-gal-next" aria-label="次の写真">›</button>
			<figcaption class="skt-gal-cap">
				<span class="skt-gal-cap-text"></span>
				<a class="skt-gal-cap-link" href="#">記事を読む</a>
			</figcaption>
		</dialog>

		<style><?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput ?></style>
		<script><?php echo self::js(); // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
		<?php
		return ob_get_clean();
	}

	/**
	 * 升目の見た目。写真の縦横がまちまちでも揃って見えるよう、正方形に切り抜いて並べる。
	 */
	private static function css() {
		return '
.skt-gal{display:grid;grid-template-columns:repeat(var(--skt-gal-cols,4),1fr);gap:8px;margin:0 0 24px}
@media(max-width:900px){.skt-gal{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.skt-gal{grid-template-columns:repeat(2,1fr);gap:6px}}
.skt-gal-cell{padding:0;border:0;background:#eceae4;border-radius:6px;overflow:hidden;cursor:zoom-in;aspect-ratio:1/1;display:block}
.skt-gal-cell img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .35s ease,opacity .2s ease}
.skt-gal-cell:hover img,.skt-gal-cell:focus-visible img{transform:scale(1.05)}
.skt-gal-cell:focus-visible{outline:3px solid #3f6b3a;outline-offset:2px}
.skt-gal-view{border:0;padding:0;background:transparent;max-width:100vw;max-height:100vh;width:100%;height:100%;color:#fff}
.skt-gal-view::backdrop{background:rgba(20,20,18,.92)}
.skt-gal-view[open]{display:grid;grid-template-rows:1fr auto;place-items:center}
.skt-gal-img{max-width:94vw;max-height:78vh;object-fit:contain;border-radius:4px;grid-row:1}
.skt-gal-cap{grid-row:2;padding:12px 16px 24px;text-align:center;font-size:15px;line-height:1.7}
.skt-gal-cap-link{display:inline-block;margin-left:12px;color:#cfe0bf}
.skt-gal-close,.skt-gal-prev,.skt-gal-next{position:fixed;background:rgba(0,0,0,.45);color:#fff;border:0;border-radius:999px;cursor:pointer;line-height:1;z-index:1}
.skt-gal-close{top:12px;right:12px;width:48px;height:48px;font-size:26px}
.skt-gal-prev,.skt-gal-next{top:50%;transform:translateY(-50%);width:52px;height:52px;font-size:34px}
.skt-gal-prev{left:10px}.skt-gal-next{right:10px}
@media(prefers-reduced-motion:reduce){.skt-gal-cell img{transition:none}}
';
	}

	/**
	 * 拡大表示。写真をタップすると大きく出て、左右で送れる。
	 */
	private static function js() {
		return "
(function(){
	var grid=document.currentScript.parentNode.querySelector('.skt-gal');
	var view=document.currentScript.parentNode.querySelector('.skt-gal-view');
	if(!grid||!view||!view.showModal){return;}
	var cells=[].slice.call(grid.querySelectorAll('.skt-gal-cell'));
	var img=view.querySelector('.skt-gal-img');
	var text=view.querySelector('.skt-gal-cap-text');
	var link=view.querySelector('.skt-gal-cap-link');
	var at=0;
	function open(i){
		at=(i+cells.length)%cells.length;
		var c=cells[at];
		img.src=c.dataset.full;
		img.alt=c.dataset.title||'';
		var who=c.dataset.author?' ／ '+c.dataset.author:'';
		text.textContent=(c.dataset.title||'')+(c.dataset.date?'（'+c.dataset.date+'）':'')+who;
		if(c.dataset.link){link.href=c.dataset.link;link.hidden=false;}else{link.hidden=true;}
		if(!view.open){view.showModal();}
	}
	cells.forEach(function(c,i){c.addEventListener('click',function(){open(i);});});
	view.querySelector('.skt-gal-prev').addEventListener('click',function(){open(at-1);});
	view.querySelector('.skt-gal-next').addEventListener('click',function(){open(at+1);});
	view.querySelector('.skt-gal-close').addEventListener('click',function(){view.close();});
	view.addEventListener('click',function(e){if(e.target===view){view.close();}});
	view.addEventListener('keydown',function(e){
		if(e.key==='ArrowLeft'){open(at-1);}
		if(e.key==='ArrowRight'){open(at+1);}
	});
})();
";
	}
}
