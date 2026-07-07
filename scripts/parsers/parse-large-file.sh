#!/bin/bash
# Parse large file reads for Claude — returns structure summary.
# Usage: scripts/parsers/parse-large-file.sh <filepath> [--section PATTERN]
#
# For files >500 lines, returns: line count, structure outline, and matching section.
# Reduces token usage by ~90% for large files.

FILE="$1"
SECTION_PATTERN="$2"

if [ ! -f "$FILE" ]; then
  echo "File not found: $FILE"
  exit 1
fi

LINES=$(wc -l < "$FILE")
SIZE=$(stat --format=%s "$FILE" 2>/dev/null || stat -f%z "$FILE" 2>/dev/null)

if [ "$LINES" -le 200 ]; then
  cat "$FILE"
  exit 0
fi

echo "# File: $FILE ($LINES lines, $((SIZE/1024))KB)"
echo ""

# Detect file type and show structure
EXT="${FILE##*.}"
case "$EXT" in
  php)
    echo "## Structure (classes/functions)"
    grep -nE '^\s*(class |function |public function |private function |protected function )' "$FILE" | head -30
    ;;
  go)
    echo "## Structure (types/functions)"
    grep -nE '^(type |func )' "$FILE" | head -30
    ;;
  js|ts|vue)
    echo "## Structure (exports/functions/components)"
    grep -nE '(^export |^const |^function |^class |defineComponent|defineStore|<template|<script|<style)' "$FILE" | head -30
    ;;
  yml|yaml)
    echo "## Top-level keys"
    grep -nE '^[a-zA-Z]' "$FILE" | head -20
    ;;
  *)
    echo "## First 10 lines"
    head -10 "$FILE"
    echo "..."
    echo "## Last 5 lines"
    tail -5 "$FILE"
    ;;
esac

if [ -n "$SECTION_PATTERN" ]; then
  echo ""
  echo "## Matching section: $SECTION_PATTERN"
  grep -n -A 20 "$SECTION_PATTERN" "$FILE" | head -30
fi
