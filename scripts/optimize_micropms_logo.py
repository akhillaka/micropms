#!/usr/bin/env python3
"""Compress traced MicroPMS SVG path data and emit PNGs via macOS qlmanage + PHP GD."""
from __future__ import annotations

import re
import shutil
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ICONS = ROOT / "public_html" / "icons"
ASSISTANT = ROOT / "public_html" / "assistant" / "assets"
SOURCE = ROOT / "scripts" / "micropms-logo.source.svg"
OUT_SVG = ICONS / "logo.svg"
WORDMARK = ICONS / "logo-wordmark.svg"

NUM_RE = re.compile(r"-?\d+\.\d+")


def round_num(m: re.Match) -> str:
    v = float(m.group(0))
    if abs(v - round(v)) < 1e-6:
        return str(int(round(v)))
    s = f"{v:.2f}".rstrip("0").rstrip(".")
    return s or "0"


def optimize_svg(raw: str) -> str:
    raw = NUM_RE.sub(round_num, raw)
    raw = re.sub(r"\s+", " ", raw)
    raw = raw.replace("> <", "><")
    raw = raw.replace('fill="#FEFEFE"', 'fill="#fff"')
    raw = raw.replace('fill="#FCFCFC"', 'fill="#fff"')
    raw = raw.replace('fill="#FBFBFB"', 'fill="#fff"')
    for src, dst in (
        ("#010101", "#111"),
        ("#020202", "#111"),
        ("#030303", "#111"),
        ("#040404", "#111"),
        ("#050505", "#111"),
    ):
        raw = raw.replace(f'fill="{src}"', f'fill="{dst}"')
    raw = re.sub(r'<\?xml[^?]*\?>', "", raw).strip()
    if "viewBox=" not in raw:
        raw = raw.replace(
            '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="900" height="902">',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 900 902" width="900" height="902" role="img" aria-label="MicroPMS">',
        )
    return raw + "\n"


def ql_png(svg_path: Path, size: int, dest: Path) -> None:
    with tempfile.TemporaryDirectory() as td:
        tmp = Path(td)
        subprocess.run(
            ["qlmanage", "-t", "-s", str(size), "-o", str(tmp), str(svg_path)],
            check=True,
            capture_output=True,
        )
        produced = list(tmp.glob("*.png"))
        if not produced:
            raise RuntimeError(f"qlmanage did not produce a PNG for size {size}")
        shutil.copyfile(produced[0], dest)


PHP_RESIZE = r"""
<?php
function fit($srcPath, $destPath, $size, $padRatio = 0.0, $bg = [255,255,255,0]) {
    $src = imagecreatefrompng($srcPath);
    if (!$src) { fwrite(STDERR, "fail read $srcPath\n"); exit(1); }
    imagesavealpha($src, true);
    $sw = imagesx($src); $sh = imagesy($src);
    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $bgc = imagecolorallocatealpha($out, $bg[0], $bg[1], $bg[2], $bg[3]);
    imagefilledrectangle($out, 0, 0, $size, $size, $bgc);
    $inner = (int) round($size * (1 - 2 * $padRatio));
    $x = (int) round(($size - $inner) / 2);
    $y = (int) round(($size - $inner) / 2);
    imagealphablending($out, true);
    imagecopyresampled($out, $src, $x, $y, 0, 0, $inner, $inner, $sw, $sh);
    imagepng($out, $destPath, 9);
}
$hi = $argv[1];
$dir = $argv[2];
fit($hi, "$dir/favicon-16.png", 16, 0.0, [255,255,255,0]);
fit($hi, "$dir/favicon-32.png", 32, 0.0, [255,255,255,0]);
fit($hi, "$dir/icon-96.png", 96, 0.0, [255,255,255,0]);
fit($hi, "$dir/apple-touch-icon.png", 180, 0.02, [255,255,255,0]);
fit($hi, "$dir/icon-192.png", 192, 0.0, [255,255,255,0]);
fit($hi, "$dir/icon-512.png", 512, 0.0, [255,255,255,0]);
fit($hi, "$dir/icon-192-maskable.png", 192, 0.12, [255,255,255,0]);
fit($hi, "$dir/icon-512-maskable.png", 512, 0.12, [255,255,255,0]);
"""


def main() -> None:
    raw = SOURCE.read_text(encoding="utf-8")
    opt = optimize_svg(raw)
    OUT_SVG.write_text(opt, encoding="utf-8")
    chunks = opt.split("<path ")
    wordmark = chunks[0] + "".join("<path " + c for c in chunks[2:])
    wordmark = wordmark.replace(
        'viewBox="0 0 900 902" width="900" height="902"',
        'viewBox="70 250 800 380" width="800" height="380"',
    )
    WORDMARK.write_text(wordmark, encoding="utf-8")
    print(f"svg {SOURCE.stat().st_size} -> {OUT_SVG.stat().st_size} bytes, wordmark {WORDMARK.stat().st_size}")

    hi = ICONS / "_logo-1024.png"
    ql_png(OUT_SVG, 1024, hi)

    php = ICONS / "_resize.php"
    php.write_text(PHP_RESIZE, encoding="utf-8")
    subprocess.run(["php", str(php), str(hi), str(ICONS)], check=True)
    php.unlink()
    hi.unlink()

    ASSISTANT.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(ICONS / "icon-192.png", ASSISTANT / "icon-192.png")
    shutil.copyfile(ICONS / "icon-512.png", ASSISTANT / "icon-512.png")
    shutil.copyfile(ICONS / "icon-192-maskable.png", ASSISTANT / "icon-192-maskable.png")
    shutil.copyfile(ICONS / "icon-512-maskable.png", ASSISTANT / "icon-512-maskable.png")
    shutil.copyfile(OUT_SVG, ASSISTANT / "logo.svg")
    shutil.copyfile(WORDMARK, ASSISTANT / "logo-wordmark.svg")
    print("icons written")


if __name__ == "__main__":
    main()
