#!/bin/bash
# Tests for check-raw-sql.sh, covering BOTH modes.
#
# The editor mode is exercised by feeding it the same JSON shape Claude Code
# sends on a Write/Edit; the CI mode by building a throwaway git repo and
# running the real --diff scan over a real commit. Neither mode is mocked -
# an earlier revision of the hook shipped with a commit message claiming
# "11 cases" of verification and no test at all, which is how it went
# unnoticed that the script exited 0 under $CI and so could never have
# guarded a build.
#
# Usage: .claude/check-raw-sql.test.sh
set -uo pipefail

HOOK="$(cd "$(dirname "$0")" && pwd)/check-raw-sql.sh"
PASS=0
FAIL=0

# editor <name> <expected: allow|block> <file path> <added text>
editor() {
  local name=$1 expect=$2 file=$3 text=$4
  local json out rc
  json=$(jq -nc --arg f "$file" --arg t "$text" '{tool_input: {file_path: $f, new_string: $t}}')
  out=$(printf '%s' "$json" | "$HOOK" 2>&1)
  rc=$?
  local got=allow
  [ $rc -ne 0 ] && got=block
  if [ "$got" = "$expect" ]; then
    PASS=$((PASS + 1))
  else
    FAIL=$((FAIL + 1))
    echo "FAIL [editor] $name: expected $expect, got $got (rc=$rc)"
    [ -n "$out" ] && echo "     $(echo "$out" | head -1)"
  fi
}

echo "== editor mode =="
editor 'go raw blocked'          block 'x/iznik-server-go/foo.go' 'db.Raw("SELECT 1").Scan(&x)'
editor 'go Exec blocked'         block 'x/iznik-server-go/foo.go' 'db.Exec("DELETE FROM t")'
editor 'go keep-raw allowed'     allow 'x/iznik-server-go/foo.go' '// keep-raw: index hint must survive
db.Raw("SELECT 1")'
editor 'go _test.go exempt'      allow 'x/iznik-server-go/foo_test.go' 'db.Raw("SELECT 1")'
editor 'go test pkg exempt'      allow 'x/iznik-server-go/test/util.go' 'db.Raw("SELECT 1")'
editor 'go gorm.Expr allowed'    allow 'x/iznik-server-go/foo.go' 'db.Where("a = ?", gorm.Expr("NOW()"))'
editor 'php DB::select blocked'  block 'x/iznik-batch/app/S.php' 'DB::select("SELECT 1");'
editor 'php whereRaw blocked'    block 'x/iznik-batch/app/S.php' '$q->whereRaw("a = 1");'
editor 'php orderByRaw blocked'  block 'x/iznik-batch/app/S.php' '$q->orderByRaw("LOWER(n)");'
editor 'php keep-raw allowed'    allow 'x/iznik-batch/app/S.php' '// keep-raw: json_unquote widens
$q->whereRaw("a = 1");'
editor 'php tests exempt'        allow 'x/iznik-batch/tests/T.php' 'DB::select("SELECT 1");'
editor 'php migrations exempt'   allow 'x/iznik-batch/database/migrations/m.php' 'DB::statement("ALTER TABLE t");'
editor 'php EeeSqlite exempt'    allow 'x/iznik-batch/app/EeeSqliteService.php' 'DB::select("SELECT 1");'
editor 'php builder allowed'     allow 'x/iznik-batch/app/S.php' 'DB::table("t")->where("a", 1)->get();'
editor 'unrelated file allowed'  allow 'x/iznik-nuxt3/a.js' 'const q = "DB::select(1)"'

echo
echo "== CI (--diff) mode =="
REPO=$(mktemp -d)
trap 'rm -rf "$REPO"' EXIT
(
  cd "$REPO" || exit 1
  git init -q .
  git config user.email t@t.t
  git config core.autocrlf false
  git config user.name t
  mkdir -p iznik-batch/app iznik-batch/tests iznik-server-go
  echo '<?php' > iznik-batch/app/Base.php
  git add -A && git commit -qm base
  git branch -M master
  git checkout -qb feature
)

ci() {
  local name=$1 expect=$2
  local out rc
  out=$(cd "$REPO" && "$HOOK" --diff master 2>&1)
  rc=$?
  local got=allow
  [ $rc -ne 0 ] && got=block
  if [ "$got" = "$expect" ]; then
    PASS=$((PASS + 1))
  else
    FAIL=$((FAIL + 1))
    echo "FAIL [ci] $name: expected $expect, got $got (rc=$rc)"
    echo "$out" | head -3 | sed 's/^/     /'
  fi
}

(cd "$REPO" && printf '<?php\nDB::select("SELECT 1");\n' > iznik-batch/app/New.php && git add -A && git commit -qm add)
ci 'new raw php blocked' block

(cd "$REPO" && printf '<?php\n// keep-raw: dialect-specific\nDB::select("SELECT 1");\n' > iznik-batch/app/New.php && git add -A && git commit -qm justify)
ci 'justified raw allowed' allow

(cd "$REPO" && printf '<?php\nDB::select("SELECT 1");\n' > iznik-batch/tests/T.php && git add -A && git commit -qm testfile)
ci 'raw in tests allowed' allow

(cd "$REPO" && printf 'package m\nfunc f() { db.Raw("SELECT 1") }\n' > iznik-server-go/a.go && git add -A && git commit -qm go)
ci 'new raw go blocked' block

# The regression that mattered: the hook must NOT disable itself under CI.
(cd "$REPO" && git checkout -q -- . && printf 'package m\n// keep-raw: dynamic table name\nfunc f() { db.Raw("SELECT 1") }\n' > iznik-server-go/a.go && git add -A && git commit -qm gojustify)
CI=true ci 'still enforces when $CI is set' allow
(cd "$REPO" && printf 'package m\nfunc f() { db.Raw("SELECT 2") }\n' > iznik-server-go/b.go && git add -A && git commit -qm gobad)
CI=true ci 'blocks even when $CI is set' block

echo
echo "passed: $PASS, failed: $FAIL"
[ "$FAIL" -eq 0 ]
