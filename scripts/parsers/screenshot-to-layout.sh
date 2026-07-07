#!/bin/bash
# Preprocesses a screenshot into a drastically smaller representation.
# Takes a screenshot file, outputs a posterized thumbnail + edge-detected layout map.
# The combination lets Claude see layout/positioning at ~90% fewer image tokens.
#
# Usage: scripts/parsers/screenshot-to-layout.sh input.png [output-dir]
#
# Outputs:
#   - thumb.webp: 400px wide posterized thumbnail (~53x300 = ~70 tokens vs ~2765)
#   - edges.webp: edge-detected layout map showing element boundaries (~70 tokens)
#   - colors.txt: dominant color palette (text, ~20 tokens)

set -euo pipefail

INPUT="$1"
OUTPUT_DIR="${2:-/tmp/screenshot-analysis}"
mkdir -p "$OUTPUT_DIR"

if [ ! -f "$INPUT" ]; then
  echo "File not found: $INPUT" >&2
  exit 1
fi

# Get original dimensions
DIMS=$(ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 "$INPUT" 2>/dev/null)
ORIG_W=$(echo "$DIMS" | cut -d, -f1)
ORIG_H=$(echo "$DIMS" | cut -d, -f2)

# 1. Posterized thumbnail — reduces detail but preserves layout and color regions
# Scale to 400px wide, reduce to 16 colors, WebP quality 50
ffmpeg -y -i "$INPUT" -vf "scale=400:-1,palettegen=max_colors=16" /tmp/_palette.png 2>/dev/null
ffmpeg -y -i "$INPUT" -i /tmp/_palette.png -lavfi "scale=400:-1 [scaled]; [scaled][1:v] paletteuse=dither=bayer" -quality 50 "$OUTPUT_DIR/thumb.webp" 2>/dev/null

# 2. Edge detection — shows element boundaries without content
# Grayscale, Sobel edge detect, threshold, scale down
ffmpeg -y -i "$INPUT" -vf "scale=400:-1,format=gray,convolution='0 -1 0 -1 4 -1 0 -1 0:0 -1 0 -1 4 -1 0 -1 0:0 -1 0 -1 4 -1 0 -1 0:0 -1 0 -1 4 -1 0 -1 0',negate,curves=all='0/0 0.15/1 1/1'" -quality 50 "$OUTPUT_DIR/edges.webp" 2>/dev/null

# 3. Dominant colors — extract palette
ffmpeg -y -i "$INPUT" -vf "scale=8:8,format=rgb24" -f rawvideo -pix_fmt rgb24 /tmp/_colors.raw 2>/dev/null
python3 -c "
import struct, collections
data = open('/tmp/_colors.raw', 'rb').read()
colors = []
for i in range(0, len(data), 3):
    r, g, b = data[i], data[i+1], data[i+2]
    # Quantize to reduce noise
    r, g, b = (r//32)*32, (g//32)*32, (b//32)*32
    colors.append(f'#{r:02x}{g:02x}{b:02x}')
counts = collections.Counter(colors).most_common(6)
for color, count in counts:
    pct = count * 100 // len(colors)
    print(f'{color} {pct}%')
" > "$OUTPUT_DIR/colors.txt" 2>/dev/null

# Calculate token savings
THUMB_DIMS=$(ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 "$OUTPUT_DIR/thumb.webp" 2>/dev/null)
THUMB_W=$(echo "$THUMB_DIMS" | cut -d, -f1)
THUMB_H=$(echo "$THUMB_DIMS" | cut -d, -f2)
ORIG_TOKENS=$(( (ORIG_W * ORIG_H) / 750 ))
THUMB_TOKENS=$(( (THUMB_W * THUMB_H) / 750 ))
EDGE_TOKENS=$THUMB_TOKENS
TOTAL_NEW=$(( THUMB_TOKENS + EDGE_TOKENS ))
SAVED=$(( ORIG_TOKENS - TOTAL_NEW ))
SAVED_PCT=$(( SAVED * 100 / ORIG_TOKENS ))

echo "Original: ${ORIG_W}x${ORIG_H} (~${ORIG_TOKENS} tokens)"
echo "Thumb:    ${THUMB_W}x${THUMB_H} (~${THUMB_TOKENS} tokens) — posterized, shows color/layout"
echo "Edges:    ${THUMB_W}x${THUMB_H} (~${EDGE_TOKENS} tokens) — element boundaries"
echo "Colors:   $(cat "$OUTPUT_DIR/colors.txt" | tr '\n' ', ')"
echo "Saving:   ${SAVED_PCT}% (${ORIG_TOKENS} → ${TOTAL_NEW} tokens for both images)"
echo ""
echo "Files: $OUTPUT_DIR/thumb.webp, $OUTPUT_DIR/edges.webp, $OUTPUT_DIR/colors.txt"
