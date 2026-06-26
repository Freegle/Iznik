#!/usr/bin/env python3
"""Image preprocessing for PR walkthroughs.

Two jobs, both keyed on FRACTIONAL coordinates (0..1 of the image's natural size) so
they stay resolution-independent:

  grid  <in> <out>            overlay a labelled coordinate grid (for measuring regions)
  mask  <example-dir>         bake PII masks from <dir>/masks.json into <dir>/assets

PII masking is BAKED into a new copy of the image, so the sensitive pixels never reach
the rendered video frames at all (we don't merely cover them in CSS).
"""
import json
import os
import sys
from PIL import Image, ImageDraw, ImageFont, ImageFilter


def _font(size):
    for path in (
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    ):
        if os.path.exists(path):
            return ImageFont.truetype(path, size)
    return ImageFont.load_default()


def grid(inp, outp, step=0.05, label_step=0.1):
    im = Image.open(inp).convert("RGB")
    w, h = im.size
    ov = im.copy()
    d = ImageDraw.Draw(ov, "RGBA")
    font = _font(max(14, w // 70))

    f = 0.0
    while f <= 1.0001:
        x = int(f * (w - 1))
        major = abs((f / label_step) - round(f / label_step)) < 1e-6
        col = (255, 40, 40, 200) if major else (0, 120, 255, 90)
        d.line([(x, 0), (x, h)], fill=col, width=2 if major else 1)
        if major:
            d.text((x + 3, 3), f"{f:.1f}", fill=(255, 40, 40, 255), font=font)
        f += step

    f = 0.0
    while f <= 1.0001:
        y = int(f * (h - 1))
        major = abs((f / label_step) - round(f / label_step)) < 1e-6
        col = (255, 40, 40, 200) if major else (0, 120, 255, 90)
        d.line([(0, y), (w, y)], fill=col, width=2 if major else 1)
        if major:
            d.text((3, y + 2), f"{f:.1f}", fill=(255, 40, 40, 255), font=font)
        f += step

    ov.save(outp)
    print(f"grid: {outp} ({w}x{h})")


def _apply_region(im, r):
    w, h = im.size
    x0 = int(max(0, min(1, r["x"])) * w)
    y0 = int(max(0, min(1, r["y"])) * h)
    x1 = int(max(0, min(1, r["x"] + r["w"])) * w)
    y1 = int(max(0, min(1, r["y"] + r["h"])) * h)
    if x1 <= x0 or y1 <= y0:
        return
    mode = r.get("mode", "pixelate")
    box = (x0, y0, x1, y1)
    crop = im.crop(box)

    if mode == "box":
        ImageDraw.Draw(im).rectangle(box, fill=tuple(r.get("color", (32, 38, 28))))
    elif mode == "blur":
        radius = r.get("radius", max(8, (x1 - x0) // 8))
        im.paste(crop.filter(ImageFilter.GaussianBlur(radius)), box)
    else:  # pixelate (default) — strong mosaic, irreversible
        blocks = max(3, int(r.get("blocks", 10)))  # mosaic cells across the region
        bw = max(1, (x1 - x0) // blocks)
        small = crop.resize((max(1, (x1 - x0) // bw), max(1, (y1 - y0) // bw)), Image.BILINEAR)
        im.paste(small.resize((x1 - x0, y1 - y0), Image.NEAREST), box)


def mask(example_dir):
    cfg_path = os.path.join(example_dir, "masks.json")
    if not os.path.exists(cfg_path):
        print(f"mask: no masks.json in {example_dir} — nothing to do")
        return
    with open(cfg_path) as fh:
        cfg = json.load(fh)
    assets = os.path.join(example_dir, "assets")
    for entry in cfg.get("images", []):
        src = os.path.join(assets, entry["src"])
        out = os.path.join(assets, entry["out"])
        im = Image.open(src).convert("RGB")
        regions = [r for r in entry.get("regions", []) if all(k in r for k in ("x", "y", "w", "h"))]
        for r in regions:
            _apply_region(im, r)
        im.save(out)
        print(f"mask: {entry['src']} -> {entry['out']} ({len(regions)} region(s) masked)")


def main(argv):
    if len(argv) < 2:
        print(__doc__)
        return 1
    cmd = argv[1]
    if cmd == "grid" and len(argv) >= 4:
        grid(argv[2], argv[3])
    elif cmd == "mask" and len(argv) >= 3:
        mask(argv[2])
    else:
        print(__doc__)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
