"""Decode the SVGs written by qr_dump.php with OpenCV and compare with the expected payloads.
A code counts as valid if either OpenCV decoder reads it at any of several render scales
(OpenCV's finder detection is occasionally flaky on perfectly valid symbols).
Usage: python -I tests/tools/qr_verify.py storage/logs/qr
"""
import re
import sys
from pathlib import Path

import cv2
import numpy as np


def render(svg: str, scale: int) -> np.ndarray:
    size = int(re.search(r'viewBox="0 0 (\d+) \d+"', svg).group(1))
    img = np.full((size * scale, size * scale), 255, np.uint8)
    for x, y, w in re.findall(r"M(\d+),(\d+)h(\d+)v1h-\d+z", svg):
        x, y, w = int(x), int(y), int(w)
        img[y * scale:(y + 1) * scale, x * scale:(x + w) * scale] = 0
    return img


def decode(img: np.ndarray) -> str:
    out, _, _ = cv2.QRCodeDetector().detectAndDecode(img)
    if out:
        return out
    out, _, _ = cv2.QRCodeDetectorAruco().detectAndDecode(img)
    return out


root = Path(sys.argv[1])
fails = 0
total = 0
for svg_path in sorted(root.glob("*.svg")):
    svg = svg_path.read_text(encoding="utf-8")
    expected = svg_path.with_suffix(".txt").read_text(encoding="utf-8")
    got = ""
    for scale in (10, 6, 8, 12, 5, 14):
        got = decode(render(svg, scale))
        if got == expected:
            break
    total += 1
    if got != expected:
        fails += 1
        print(f"FAIL {svg_path.name} got={got!r}")
print(f"decoded {total - fails}/{total}")
sys.exit(1 if fails else 0)
