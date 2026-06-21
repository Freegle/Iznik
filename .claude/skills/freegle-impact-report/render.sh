#!/usr/bin/env bash
# Render a designed HTML report to PDF (headless chrome, GPU disabled) and to per-page PNGs for visual review.
# Usage: render.sh <input.html> <outdir> [dpi]
set -euo pipefail
HTML="$1"; OUT="$2"; DPI="${3:-110}"
mkdir -p "$OUT"
PDF="$OUT/report.pdf"

google-chrome --headless=new --disable-gpu --no-sandbox --no-pdf-header-footer \
  --hide-scrollbars --run-all-compositor-stages-before-draw \
  --virtual-time-budget=20000 \
  --print-to-pdf="$PDF" "file://$HTML" 2>/dev/null || \
google-chrome --headless --disable-gpu --no-sandbox \
  --print-to-pdf="$PDF" "file://$HTML" 2>/dev/null

rm -f "$OUT"/page-*.png
pdftoppm -png -r "$DPI" "$PDF" "$OUT/page" 2>/dev/null
echo "PDF: $PDF"
pdfinfo "$PDF" 2>/dev/null | grep -E "^Pages" || true
ls -1 "$OUT"/page-*.png 2>/dev/null
