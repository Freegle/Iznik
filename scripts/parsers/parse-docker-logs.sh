#!/bin/bash
# Parse docker logs output for Claude — returns structured summary.
# Usage: docker logs <container> 2>&1 | scripts/parsers/parse-docker-logs.sh
#
# Reduces token usage by ~90% for verbose container logs.
# Keeps errors, warnings, and last N lines of output.

TAIL_LINES=20
ERROR_LINES=10

INPUT=$(cat)
TOTAL_LINES=$(echo "$INPUT" | wc -l)

if [ "$TOTAL_LINES" -le 50 ]; then
  # Small enough to pass through
  echo "$INPUT"
  exit 0
fi

echo "# Docker Logs Summary ($TOTAL_LINES total lines)"
echo ""

# Count and show errors
ERRORS=$(echo "$INPUT" | grep -iE '(error|fatal|panic|exception|failed|CRIT)' | grep -viE '(no error|error_count.*0|errors.*0)' | tail -"$ERROR_LINES")
ERROR_COUNT=$(echo "$INPUT" | grep -ciE '(error|fatal|panic|exception|failed|CRIT)' | grep -viE '(no error|error_count.*0)')
if [ -n "$ERRORS" ] && [ "$ERROR_COUNT" -gt 0 ]; then
  echo "## Errors ($ERROR_COUNT total)"
  echo "$ERRORS"
  echo ""
fi

# Show warnings
WARNINGS=$(echo "$INPUT" | grep -iE '(warn|warning|deprecated)' | tail -5)
WARN_COUNT=$(echo "$INPUT" | grep -ciE '(warn|warning|deprecated)')
if [ -n "$WARNINGS" ] && [ "$WARN_COUNT" -gt 0 ]; then
  echo "## Warnings ($WARN_COUNT total)"
  echo "$WARNINGS"
  echo ""
fi

# Last N lines
echo "## Last $TAIL_LINES lines"
echo "$INPUT" | tail -"$TAIL_LINES"
