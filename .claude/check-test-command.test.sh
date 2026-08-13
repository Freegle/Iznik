#!/bin/bash
# Tests for check-test-command.sh's trigger. Kept in a file because the cases under test
# contain the very literals the hook reacts to.

HOOK="$(cd "$(dirname "$0")" && pwd)/check-test-command.sh"
pass=0; fail=0
G="go"; T="test"; PHP="php"

TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

run() { printf '%s' "$(jq -nc --arg c "$1" '{tool_input:{command:$c}}')" | "$HOOK" >/dev/null 2>"$TMP/err"; echo $?; }
check() { local n="$1" want="$2" cmd="$3" got; got=$(run "$cmd")
  if [ "$got" = "$want" ]; then printf '  PASS  %s\n' "$n"; pass=$((pass+1))
  else printf '  FAIL  %s (exit %s, wanted %s)\n' "$n" "$got" "$want"; sed 's/^/        /' "$TMP/err"|head -3; fail=$((fail+1)); fi; }

echo "== must still block a real test run =="
check "go test"            2 "cd /x && $G $T ./..."
check "artisan test"       2 "docker exec c $PHP artisan $T --filter=Foo"
check "vendor phpunit"     2 "docker exec c vendor/bin/phpunit"
check "npx playwright"     2 "npx playwright $T"
check "go build"           2 "$G build ./dashboard/"

echo
echo "== must NOT block: quoting, not running =="
QUOTED="cat >> notes.md <<'EOF'
NOTE: running migrations by hand is blocked; use the status API.
  docker exec c $PHP artisan migrate --force
EOF"
check "heredoc quoting a command" 0 "$QUOTED"
check "filenames that abut into a trigger" 0 \
  "cp a/dashboard/cache_$T.go b/$T/dashboard_$T.go"
check "grep for the literal"      0 "grep -rn '$G $T' .claude/*.sh"
check "status API POST"           0 "curl -s -X POST http://localhost:12114/api/tests/laravel"
check "unrelated"                 0 "ls -la"

echo
echo "  $pass passed, $fail failed"
[ "$fail" = "0" ]
