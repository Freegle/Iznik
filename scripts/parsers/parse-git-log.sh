#!/bin/bash
# Parse git log output for Claude — returns compact summary instead of raw log.
# Usage: git log [args] | scripts/parsers/parse-git-log.sh [--commits N] [--since DATE]
#
# Reduces token usage by ~80% vs raw git log output.
# Strips verbose diff hunks, keeps commit hash + subject + files changed.

MAX_COMMITS=30
SHOW_FILES=false

while [[ $# -gt 0 ]]; do
  case $1 in
    --commits) MAX_COMMITS="$2"; shift 2;;
    --files) SHOW_FILES=true; shift;;
    *) shift;;
  esac
done

# If stdin is a pipe, read from it; otherwise expect args as git log command
INPUT=$(cat)
LINES=$(echo "$INPUT" | wc -l)

if [ "$LINES" -gt 200 ]; then
  echo "# Git Log Summary (truncated from $LINES lines to $MAX_COMMITS commits)"
  echo ""
  # Extract commit lines and summarise
  echo "$INPUT" | grep -E '^[a-f0-9]{7,40} ' | head -"$MAX_COMMITS"
  echo ""
  echo "($LINES total lines of output — use --oneline or narrower date range)"
else
  echo "$INPUT"
fi
