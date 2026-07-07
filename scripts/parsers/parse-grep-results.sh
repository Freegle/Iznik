#!/bin/bash
# Parse grep/ripgrep output for Claude — deduplicates and summarises.
# Usage: grep -rn pattern . | scripts/parsers/parse-grep-results.sh
#
# Reduces token usage by ~70% for broad searches.
# Groups by file, shows match count per file, limits total output.

MAX_FILES=15
MAX_LINES_PER_FILE=3

INPUT=$(cat)
TOTAL_LINES=$(echo "$INPUT" | wc -l)

if [ "$TOTAL_LINES" -le 30 ]; then
  echo "$INPUT"
  exit 0
fi

echo "# Search Results Summary ($TOTAL_LINES matches)"
echo ""

# Group by file and count
echo "$INPUT" | cut -d: -f1 | sort | uniq -c | sort -rn | head -"$MAX_FILES" | while read count file; do
  echo "## $file ($count matches)"
  echo "$INPUT" | grep "^${file}:" | head -"$MAX_LINES_PER_FILE"
  echo ""
done

UNIQUE_FILES=$(echo "$INPUT" | cut -d: -f1 | sort -u | wc -l)
if [ "$UNIQUE_FILES" -gt "$MAX_FILES" ]; then
  echo "... and $((UNIQUE_FILES - MAX_FILES)) more files"
fi
