"""LINEリッチメニューの画像を作る。

LINE Official Account Manager の「大(2500x1686) 6分割」テンプレートに合わせる。
上段3つを使い、下段は余白（大きなボタンにしたいので上段だけ使う案も作る）。
ここでは 2500x843（小）3分割 と 2500x1686（大）6分割 の2種類を出す。
"""

from PIL import Image, ImageDraw, ImageFont

FONT = r"C:\Windows\Fonts\YuGothB.ttc"
GREEN = (63, 107, 58)
GREEN_DARK = (46, 80, 41)
CREAM = (246, 244, 238)
WHITE = (255, 255, 255)
LINE_COLOR = (217, 212, 199)


def font(size):
    return ImageFont.truetype(FONT, size, index=0)


def fit_font(draw, text, max_w, start):
    """枠に収まるまで文字を小さくする"""
    size = start
    while size > 10:
        f = font(size)
        left, _, right, _ = draw.textbbox((0, 0), text, font=f)
        if right - left <= max_w:
            return f
        size -= 2
    return font(10)


def center_text(draw, box, text, f, fill):
    x0, y0, x1, y1 = box
    left, top, right, bottom = draw.textbbox((0, 0), text, font=f)
    draw.text(
        (x0 + (x1 - x0 - (right - left)) / 2 - left,
         y0 + (y1 - y0 - (bottom - top)) / 2 - top),
        text, font=f, fill=fill,
    )


def camera(draw, cx, cy, s, color):
    """カメラのしるし"""
    w, h = s * 1.5, s * 1.1
    draw.rounded_rectangle([cx - w / 2, cy - h / 2, cx + w / 2, cy + h / 2], radius=s * 0.22,
                           outline=color, width=max(3, int(s * 0.1)))
    draw.rounded_rectangle([cx - s * 0.28, cy - h / 2 - s * 0.18, cx + s * 0.28, cy - h / 2 + s * 0.1],
                           radius=s * 0.08, fill=color)
    draw.ellipse([cx - s * 0.33, cy - s * 0.33, cx + s * 0.33, cy + s * 0.33],
                 outline=color, width=max(3, int(s * 0.1)))


def leaf(draw, cx, cy, s, color):
    """葉のしるし（他と同じ線画）"""
    layer = Image.new("RGBA", (int(s * 4), int(s * 4)), (0, 0, 0, 0))
    ld = ImageDraw.Draw(layer)
    w, h = s * 1.15, s * 2.3
    c = s * 2
    line = max(3, int(s * 0.1))
    ld.ellipse([c - w / 2, c - h / 2, c + w / 2, c + h / 2],
               outline=color + (255,), width=line)
    ld.line([c, c - h / 2 + s * 0.25, c, c + h / 2 - s * 0.25],
            fill=color + (255,), width=line)
    layer = layer.rotate(-35, resample=Image.BICUBIC, center=(c, c))
    draw._image.paste(layer, (int(cx - c), int(cy - c)), layer)


def photos(draw, cx, cy, s, color):
    """写真のしるし"""
    draw.rounded_rectangle([cx - s * 0.95, cy - s * 0.7, cx + s * 0.55, cy + s * 0.75], radius=s * 0.15,
                           outline=color, width=max(3, int(s * 0.1)))
    draw.rounded_rectangle([cx - s * 0.55, cy - s * 0.85, cx + s * 0.95, cy + s * 0.6], radius=s * 0.15,
                           fill=CREAM, outline=color, width=max(3, int(s * 0.1)))
    draw.ellipse([cx - s * 0.3, cy - s * 0.6, cx - s * 0.05, cy - s * 0.35], fill=color)
    draw.polygon([(cx - s * 0.45, cy + s * 0.5), (cx + s * 0.1, cy - s * 0.1),
                  (cx + s * 0.8, cy + s * 0.5)], fill=color)


ICONS = {"camera": camera, "leaf": leaf, "photos": photos}


def build(path, width, height, cells, labels):
    """cells: (列数, 行数) / labels: [(見出し, 補足, アイコン), ...]"""
    cols, rows = cells
    img = Image.new("RGB", (width, height), CREAM)
    d = ImageDraw.Draw(img)

    cw, ch = width / cols, height / rows

    for i, (title, sub, icon) in enumerate(labels):
        col, row = i % cols, i // cols
        x0, y0 = col * cw, row * ch
        x1, y1 = x0 + cw, y0 + ch

        pad = min(cw, ch) * 0.06
        d.rounded_rectangle([x0 + pad, y0 + pad, x1 - pad, y1 - pad],
                            radius=min(cw, ch) * 0.10, fill=WHITE, outline=LINE_COLOR, width=4)

        icon_cy = y0 + ch * 0.33
        ICONS[icon](d, (x0 + x1) / 2, icon_cy, min(cw, ch) * 0.16, GREEN)

        title_f = fit_font(d, title, cw * 0.74, int(ch * 0.22))
        center_text(d, (x0, y0 + ch * 0.48, x1, y0 + ch * 0.70), title, title_f, GREEN_DARK)
        if sub:
            sub_f = fit_font(d, sub, cw * 0.72, int(ch * 0.115))
            center_text(d, (x0, y0 + ch * 0.70, x1, y0 + ch * 0.86), sub, sub_f, (107, 103, 94))

    img.save(path, "PNG", optimize=True)
    return path


if __name__ == "__main__":
    import sys

    out = sys.argv[1]

    build(
        out + "/richmenu-3.png", 2500, 843, (3, 1),
        [
            ("投稿する", "写真とひとこと", "camera"),
            ("自然栽培ライフ", "育て方のヒント", "leaf"),
            ("農場のブログ", "みんなの投稿", "photos"),
        ],
    )
    print("wrote richmenu-3.png")


def cover(path, w, h):
    """写真を枠いっぱいに切り抜く"""
    im = Image.open(path).convert("RGB")
    s = max(w / im.width, h / im.height)
    im = im.resize((max(1, int(im.width * s)), max(1, int(im.height * s))), Image.LANCZOS)
    left = (im.width - w) // 2
    top = (im.height - h) // 2
    return im.crop((left, top, left + w, top + h))


def build_photo(path, width, height, cells, labels):
    """写真を背景にした版。文字が読めることを最優先に、下半分を暗くする。

    labels: [(見出し, 補足, アイコン, 写真パス), ...]
    """
    cols, rows = cells
    img = Image.new("RGB", (width, height), CREAM)
    cw, ch = int(width / cols), int(height / rows)

    for i, (title, sub, icon, photo) in enumerate(labels):
        col, row = i % cols, i // cols
        x0, y0 = col * cw, row * ch

        cell = cover(photo, cw, ch)

        # 下に行くほど濃くする幕。文字の下だけしっかり暗くする。
        veil = Image.new("L", (1, ch))
        for y in range(ch):
            t = y / ch
            veil.putpixel((0, y), int(60 + 150 * max(0.0, (t - 0.25) / 0.75) ** 1.4))
        veil = veil.resize((cw, ch))
        cell = Image.composite(Image.new("RGB", (cw, ch), (12, 20, 10)), cell, veil)

        img.paste(cell, (x0, y0))

        d = ImageDraw.Draw(img)
        ICONS[icon](d, x0 + cw / 2, y0 + ch * 0.30, min(cw, ch) * 0.145, WHITE)

        title_f = fit_font(d, title, cw * 0.80, int(ch * 0.21))
        center_text(d, (x0, y0 + ch * 0.52, x0 + cw, y0 + ch * 0.74), title, title_f, WHITE)
        if sub:
            sub_f = fit_font(d, sub, cw * 0.78, int(ch * 0.115))
            center_text(d, (x0, y0 + ch * 0.74, x0 + cw, y0 + ch * 0.90), sub, sub_f, (226, 232, 220))

    # 枠の境目に細い線を入れて、押す場所を分かりやすくする。
    d = ImageDraw.Draw(img)
    for c in range(1, cols):
        d.line([(c * cw, 0), (c * cw, height)], fill=WHITE, width=6)
    for r in range(1, rows):
        d.line([(0, r * ch), (width, r * ch)], fill=WHITE, width=6)

    img.save(path, "PNG", optimize=True)
    return path
