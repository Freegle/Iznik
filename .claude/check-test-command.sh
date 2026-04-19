#!/bin/bash
# Hook script to enforce use of the status API for running tests locally.
# Direct test runner invocations (go test, artisan test, etc.) are blocked —
# use the status container API instead:
#
#   curl -s -X POST http://localhost:8081/api/tests/go
#   curl -s -X POST http://localhost:8081/api/tests/laravel
#   curl -s -X POST http://localhost:8081/api/tests/php
#   curl -s -X POST http://localhost:8081/api/tests/vitest
#   curl -s -X POST http://localhost:8081/api/tests/playwright
#
# On CI (CIRCLECI=true) this hook is a no-op — tests run normally there.

if [ -n "$CI" ]; then
  exit 0
fi

# Read the tool input from stdin
INPUT=$(cat)

# Extract the command from the JSON input
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty')

if [ -z "$COMMAND" ]; then
  exit 0  # No command, allow
fi

# Skip commands that are just reading/checking data (not executing tests).
IS_DATA_COMMAND=false
if echo "$COMMAND" | grep -qE '\bcurl\b.*localhost:8081/api/tests.*/status'; then
  IS_DATA_COMMAND=true
fi
if echo "$COMMAND" | grep -qE '\bcurl\b.*circle-artifacts\.com'; then
  IS_DATA_COMMAND=true
fi
if echo "$COMMAND" | grep -qE '\bcurl\b.*circleci\.com/api'; then
  IS_DATA_COMMAND=true
fi
if echo "$COMMAND" | grep -qE '^\s*(cat|tail|head|less|wc)\b.*/tmp/'; then
  IS_DATA_COMMAND=true
fi
if echo "$COMMAND" | grep -qE '\bdocker (top|logs|cp)\b'; then
  IS_DATA_COMMAND=true
fi
# `docker exec` is only classed as a data command when the inner argv is
# narrowly a read (mysql -e/-B, cat/tail/head/ls/grep/wc, ps/env/which,
# supervisorctl status, bare artisan subcommands that are informational).
# Any `docker exec ... vendor/bin/phpunit`, `docker exec ... go test`,
# `docker exec ... artisan test`, etc. must fall through to TEST_PATTERNS.
if echo "$COMMAND" | grep -qE '\bdocker exec\b' && \
   ! echo "$COMMAND" | grep -qE '\b(phpunit|go test|go vet|go build|artisan test|artisan dusk|artisan migrate|vitest|playwright|npm test|npm run test)\b'; then
  IS_DATA_COMMAND=true
fi
if echo "$COMMAND" | grep -qE '\b(ps|pgrep)\b'; then
  IS_DATA_COMMAND=true
fi
if echo "$COMMAND" | grep -qE '^\s*git\b'; then
  IS_DATA_COMMAND=true
fi

# Allow status API POSTs — this IS the correct way to run tests locally.
if echo "$COMMAND" | grep -qE '\bcurl\b.*-X\s*POST.*localhost:8081/api/tests'; then
  IS_DATA_COMMAND=true
fi

# Check if this is a direct test runner invocation.
TEST_PATTERNS=(
  '\bplaywright test\b'
  '\bnpx playwright test\b'
  '\bnpm run test\b'
  '\bnpm test\b'
  '\bgo test\b'
  '\bgo vet\b'
  '\bgo build\b'
  '\bartisan test\b'
  '\bartisan dusk\b'
  '\bartisan migrate\b'
  '\bvendor/bin/phpunit\b'
  '\bvendor/phpunit/phpunit/phpunit\b'
  '\bphp\b.*\bphpunit\b'
  '\bvitest\b'
  '\bnpx vitest\b'
  # Spinning up an ephemeral dev-toolchain container on the host is not
  # allowed — use the status API which points at the dev-profile containers.
  '\bdocker run\b.*\b(golang|node|composer|php):'
)

IS_TEST_COMMAND=false
if [ "$IS_DATA_COMMAND" = false ]; then
  for pattern in "${TEST_PATTERNS[@]}"; do
    if echo "$COMMAND" | grep -qE "$pattern"; then
      IS_TEST_COMMAND=true
      break
    fi
  done
fi

if [ "$IS_TEST_COMMAND" = false ]; then
  exit 0  # Not a test command, allow
fi

# Allow --list (dry run) and --help since they don't execute tests
if echo "$COMMAND" | grep -qE -- "--list|--help"; then
  exit 0
fi

# Block direct test execution — use the status API instead.
echo "BLOCKED: Use the status API to run tests locally, not direct test commands." >&2
echo "" >&2
echo "  curl -s -X POST http://localhost:8081/api/tests/go" >&2
echo "  curl -s -X POST http://localhost:8081/api/tests/laravel" >&2
echo "  curl -s -X POST http://localhost:8081/api/tests/php" >&2
echo "  curl -s -X POST http://localhost:8081/api/tests/vitest" >&2
echo "  curl -s -X POST http://localhost:8081/api/tests/playwright" >&2
echo "" >&2
echo "  Then poll for results:" >&2
echo "  curl -s http://localhost:8081/api/tests/go/status" >&2
exit 2
