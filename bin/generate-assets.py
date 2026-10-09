#!/usr/bin/env python3
"""Generate the Smart Domain Mapping System banner and plugin icons.

Renders vector-style artwork with Pillow at high resolution and downsamples
with LANCZOS, so the output is crisp at every target size. Produces:

    assets/banner-1544x500.png   (WordPress.org banner, retina)
    assets/banner-772x250.png    (WordPress.org banner)
    assets/icon-256x256.png
    assets/icon-128x128.png

Requires Pillow (`pip install Pillow`). Run from anywhere:

    python bin/generate-assets.py
"""

from __future__ import annotations

import math
import os

from PIL import Image, ImageDraw, ImageFilter, ImageFont

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ASSETS_DIR = os.path.join(REPO_ROOT, "assets")

# Palette.
NAVY_TOP = (8, 13, 28)
NAVY_BOTTOM = (30, 58, 138)
INDIGO = (76, 29, 149)
WP_BLUE = (34, 113, 177)
CYAN = (56, 189, 248)
LIGHT = (186, 230, 253)
WHITE = (255, 255, 255)

FONT_BOLD_CANDIDATES = [
    r"C:\Windows\Fonts\segoeuib.ttf",
    r"C:\Windows\Fonts\seguisb.ttf",
    r"C:\Windows\Fonts\arialbd.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
]
FONT_REGULAR_CANDIDATES = [
    r"C:\Windows\Fonts\segoeui.ttf",
    r"C:\Windows\Fonts\arial.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
]


def pick_font(candidates, size):
    for path in candidates:
        if os.path.exists(path):
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def vertical_gradient(size, top, bottom):
    width, height = size
    column = Image.new("RGB", (1, height))
    for y in range(height):
        t = y / max(1, height - 1)
        column.putpixel(
            (0, y),
            tuple(round(top[i] + (bottom[i] - top[i]) * t) for i in range(3)),
        )
    return column.resize(size, Image.Resampling.BICUBIC)


def radial_glow(size, center, radius, color, max_alpha):
    glow = Image.new("L", size, 0)
    draw = ImageDraw.Draw(glow)
    cx, cy = center
    draw.ellipse([cx - radius, cy - radius, cx + radius, cy + radius], fill=max_alpha)
    glow = glow.filter(ImageFilter.GaussianBlur(radius * 0.55))
    layer = Image.new("RGBA", size, color + (255,))
    layer.putalpha(glow)
    return layer


def diagonal(overlay, points, color):
    draw = ImageDraw.Draw(overlay)
    draw.polygon(points, fill=color)
    return overlay.filter(ImageFilter.GaussianBlur(70))


def dot_grid(size, spacing, color):
    layer = Image.new("RGBA", size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    for x in range(spacing, size[0], spacing):
        for y in range(spacing, size[1], spacing):
            draw.ellipse([x - 2, y - 2, x + 2, y + 2], fill=color)
    return layer


def draw_globe(size, center, radius, line=(56, 189, 248, 150), bright=(125, 211, 252, 170)):
    layer = Image.new("RGBA", size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    cx, cy = center
    r = radius

    def ring(rx, ry, colour, width):
        draw.ellipse([cx - rx, cy - ry, cx + rx, cy + ry], outline=colour, width=width)

    ring(r, r, bright, 4)
    ring(r - 16, r - 16, line, 2)
    for k in (0.36, 0.72):
        ring(r * k, r, line, 2)
    for k in (0.4, 0.76):
        ring(r, r * k, line, 2)
    draw.line([cx, cy - r, cx, cy + r], fill=line, width=2)
    draw.line([cx - r, cy, cx + r, cy], fill=line, width=2)
    return layer


def draw_network(size, center, radius, node_color=(125, 211, 252, 235), link_color=(125, 211, 252, 90)):
    layer = Image.new("RGBA", size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(layer)
    cx, cy = center

    angles = [12, 58, 104, 152, 198, 246, 292, 336]
    for index, angle in enumerate(angles):
        rad = math.radians(angle)
        ring = radius * (1.42 if index % 2 == 0 else 1.16)
        px = cx + ring * math.cos(rad)
        py = cy + ring * 0.98 * math.sin(rad)
        draw.line([cx, cy, px, py], fill=link_color, width=2)
        dot = 11 if index % 2 == 0 else 8
        draw.ellipse([px - dot, py - dot, px + dot, py + dot], fill=node_color)

    draw.ellipse([cx - 13, cy - 13, cx + 13, cy + 13], fill=(224, 242, 254, 255))
    return layer


def draw_shield(draw, cx, cy, s, fill, check):
    points = [
        (cx - s, cy - s * 1.12),
        (cx + s, cy - s * 1.12),
        (cx + s, cy + s * 0.34),
        (cx, cy + s * 1.28),
        (cx - s, cy + s * 0.34),
    ]
    draw.polygon(points, fill=fill)
    width = max(3, int(s * 0.16))
    draw.line(
        [(cx - s * 0.42, cy - s * 0.02), (cx - s * 0.08, cy + s * 0.34), (cx + s * 0.5, cy - s * 0.32)],
        fill=check,
        width=width,
        joint="curve",
    )


def build_banner():
    width, height = 3088, 1000
    base = vertical_gradient((width, height), NAVY_TOP, NAVY_BOTTOM).convert("RGBA")

    accent = diagonal(
        Image.new("RGBA", (width, height), (0, 0, 0, 0)),
        [(0, height), (width, height), (width, int(height * 0.28)), (0, int(height * 0.72))],
        INDIGO + (95,),
    )
    base = Image.alpha_composite(base, accent)
    base = Image.alpha_composite(base, radial_glow((width, height), (2400, 500), 560, CYAN, 78))
    base = Image.alpha_composite(base, dot_grid((width, height), 58, (148, 197, 253, 16)))

    globe_center = (2400, 500)
    base = Image.alpha_composite(base, draw_globe((width, height), globe_center, 300))
    base = Image.alpha_composite(base, draw_network((width, height), globe_center, 300))

    text = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(text)

    title = "Smart Domain Mapping System"
    title_size = 108
    title_font = pick_font(FONT_BOLD_CANDIDATES, title_size)
    max_title_width = 1960
    while title_font.getlength(title) > max_title_width and title_size > 56:
        title_size -= 4
        title_font = pick_font(FONT_BOLD_CANDIDATES, title_size)

    tag_font = pick_font(FONT_REGULAR_CANDIDATES, 47)
    tag_lines = [
        "Multisite domain mapping with DNS verification,",
        "SSL and audit logging",
    ]

    title_box = draw.textbbox((0, 0), title, font=title_font)
    title_h = title_box[3] - title_box[1]
    tag_h = draw.textbbox((0, 0), tag_lines[0], font=tag_font)[3]
    line_gap = 12

    bar_h = 12
    gap_bar_title = 30
    gap_title_tag = 40
    block_h = bar_h + gap_bar_title + title_h + gap_title_tag + tag_h * 2 + line_gap
    top = (height - block_h) // 2
    left = 180

    draw.rounded_rectangle(
        [left, top, left + 170, top + bar_h],
        radius=6,
        fill=CYAN + (255,),
    )

    y = top + bar_h + gap_bar_title
    draw.text((left, y - title_box[1]), title, font=title_font, fill=WHITE + (255,))
    y += title_h + gap_title_tag
    for line in tag_lines:
        draw.text((left + 2, y), line, font=tag_font, fill=LIGHT + (240,))
        y += tag_h + line_gap

    base = Image.alpha_composite(base, text)

    rgb = base.convert("RGB")
    rgb.resize((1544, 500), Image.Resampling.LANCZOS).save(
        os.path.join(ASSETS_DIR, "banner-1544x500.png")
    )
    rgb.resize((772, 250), Image.Resampling.LANCZOS).save(
        os.path.join(ASSETS_DIR, "banner-772x250.png")
    )


def build_icon():
    size = 1024
    base = vertical_gradient((size, size), NAVY_TOP, NAVY_BOTTOM).convert("RGBA")

    accent = diagonal(
        Image.new("RGBA", (size, size), (0, 0, 0, 0)),
        [(0, size), (size, size), (size, int(size * 0.3)), (0, int(size * 0.7))],
        INDIGO + (95,),
    )
    base = Image.alpha_composite(base, accent)
    base = Image.alpha_composite(base, radial_glow((size, size), (512, 430), 470, CYAN, 82))

    center = (512, 476)
    base = Image.alpha_composite(base, draw_globe((size, size), center, 244))
    base = Image.alpha_composite(base, draw_network((size, size), center, 244))

    badge = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    badge_draw = ImageDraw.Draw(badge)
    draw_shield(badge_draw, 726, 742, 118, WP_BLUE + (255,), WHITE + (255,))
    base = Image.alpha_composite(base, badge)

    # Subtle inner border to frame the square.
    border = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    ImageDraw.Draw(border).rounded_rectangle(
        [16, 16, size - 16, size - 16], radius=190, outline=(148, 197, 253, 26), width=4
    )
    base = Image.alpha_composite(base, border)

    rgb = base.convert("RGB")
    rgb.resize((256, 256), Image.Resampling.LANCZOS).save(
        os.path.join(ASSETS_DIR, "icon-256x256.png")
    )
    rgb.resize((128, 128), Image.Resampling.LANCZOS).save(
        os.path.join(ASSETS_DIR, "icon-128x128.png")
    )


def main():
    os.makedirs(ASSETS_DIR, exist_ok=True)
    build_banner()
    build_icon()
    for name in (
        "banner-1544x500.png",
        "banner-772x250.png",
        "icon-256x256.png",
        "icon-128x128.png",
    ):
        path = os.path.join(ASSETS_DIR, name)
        with Image.open(path) as image:
            print(f"{name}: {image.size[0]}x{image.size[1]} ({os.path.getsize(path)} bytes)")


if __name__ == "__main__":
    main()
