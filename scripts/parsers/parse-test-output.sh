#!/bin/bash
# Parse test runner output for Claude — returns pass/fail summary.
# Usage: <test command> 2>&1 | scripts/parsers/parse-test-output.sh
#
# Works with: PHPUnit, Go test, Vitest, Playwright, Jest
# Reduces token usage by ~85% for large test suites.

INPUT=$(cat)
TOTAL_LINES=$(echo "$INPUT" | wc -l)

if [ "$TOTAL_LINES" -le 80 ]; then
  echo "$INPUT"
  exit 0
fi

echo "# Test Output Summary ($TOTAL_LINES total lines)"
echo ""

# Detect test framework
if echo "$INPUT" | grep -q 'PHPUnit'; then
  FRAMEWORK="PHPUnit"
elif echo "$INPUT" | grep -q '--- FAIL\|--- PASS\|FAIL\s\+github'; then
  FRAMEWORK="Go"
elif echo "$INPUT" | grep -qE 'vitest|Test Files|Tests\s+\d'; then
  FRAMEWORK="Vitest"
elif echo "$INPUT" | grep -qE 'playwright|chromium|webkit|firefox.*passed'; then
  FRAMEWORK="Playwright"
else
  FRAMEWORK="Unknown"
fi

echo "Framework: $FRAMEWORK"
echo ""

# Show failures
FAILURES=$(echo "$INPUT" | grep -E '(FAIL|FAILED|✗|✘|×|Error:|AssertionError|--- FAIL)' | head -20)
if [ -n "$FAILURES" ]; then
  FAIL_COUNT=$(echo "$FAILURES" | wc -l)
  echo "## Failures ($FAIL_COUNT)"
  echo "$FAILURES"
  echo ""
fi

# Show the summary line (usually near the end)
echo "## Summary"
echo "$INPUT" | tail -15 | grep -E '(Tests:|test|pass|fail|ok\s|FAIL|error|Time:|Duration:)' | head -10

# If there are failures, show context around the first one
if [ -n "$FAILURES" ]; then
  FIRST_FAIL=$(echo "$INPUT" | grep -nE '(FAIL|FAILED|✗|✘|×|--- FAIL)' | head -1 | cut -d: -f1)
  if [ -n "$FIRST_FAIL" ]; then
    echo ""
    echo "## First failure context (line $FIRST_FAIL)"
    START=$((FIRST_FAIL - 3))
    [ "$START" -lt 1 ] && START=1
    END=$((FIRST_FAIL + 10))
    echo "$INPUT" | sed -n "${START},${END}p"
  fi
fi
