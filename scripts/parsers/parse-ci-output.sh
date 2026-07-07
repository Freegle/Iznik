#!/bin/bash
# Parse CircleCI logs/artifacts for Claude — extracts failures and summary.
# Usage: curl <circleci-url> | scripts/parsers/parse-ci-output.sh
#
# Reduces token usage by ~80% for verbose CI logs.
# Keeps: step names, failures, test summaries, exit codes.

INPUT=$(cat)
TOTAL_LINES=$(echo "$INPUT" | wc -l)

if [ "$TOTAL_LINES" -le 80 ]; then
  echo "$INPUT"
  exit 0
fi

echo "# CI Output Summary ($TOTAL_LINES total lines)"
echo ""

# Extract step boundaries (CircleCI format: "#!/bin/bash -eo pipefail" or "====")
STEPS=$(echo "$INPUT" | grep -nE '(^#!/bin/bash|^====|^\*\*\*|^Step [0-9]|^Running |^Executing )' | tail -20)
if [ -n "$STEPS" ]; then
  echo "## Steps"
  echo "$STEPS"
  echo ""
fi

# Extract errors and failures
ERRORS=$(echo "$INPUT" | grep -iE '(FAIL|ERROR|FATAL|panic|Exited with code [^0]|exit status [^0]|AssertionError|Exception)' | grep -viE '(no error|0 errors|errors: 0|PASS)' | tail -20)
if [ -n "$ERRORS" ]; then
  ERROR_COUNT=$(echo "$ERRORS" | wc -l)
  echo "## Errors/Failures ($ERROR_COUNT)"
  echo "$ERRORS"
  echo ""
fi

# Extract test results (common formats)
TEST_SUMMARY=$(echo "$INPUT" | grep -iE '(Tests:|test result|passed|failed|ok |FAIL\s|Tests run:|\d+ passed|\d+ failed|Test Suites:)' | tail -10)
if [ -n "$TEST_SUMMARY" ]; then
  echo "## Test Results"
  echo "$TEST_SUMMARY"
  echo ""
fi

# Exit code if present
EXIT=$(echo "$INPUT" | grep -iE '(exit code|exit status|Exited with)' | tail -3)
if [ -n "$EXIT" ]; then
  echo "## Exit Status"
  echo "$EXIT"
  echo ""
fi

# Last 15 lines for context
echo "## Last 15 lines"
echo "$INPUT" | tail -15
