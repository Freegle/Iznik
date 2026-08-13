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
)

# Every CI case is INDEPENDENT. Each starts from the base commit on a fresh
# branch with a clean tree, so a case cannot inherit whatever the previous one
# committed. Without that, a case's result depends on where in this file it
# happens to sit - which produced a real false failure while this suite was
# being written, and would hide a real one just as easily.
scenario() {
  (
    cd "$REPO" || exit 1
    git checkout -q master
    git branch -qD feature >/dev/null 2>&1
    git clean -qfd
    git checkout -qb feature
    # git clean removes untracked DIRECTORIES too, so the fixture dirs go with
    # them - recreate before the case writes into them, or its printf fails
    # silently and the case sees an empty diff.
    mkdir -p iznik-batch/app iznik-batch/tests iznik-server-go
  )
}

# run <name> <allow|block>  - call after the scenario body has committed.
run() {
  local name=$1 expect=$2 out rc got
  out=$(cd "$REPO" && "$HOOK" --diff master 2>&1)
  rc=$?
  got=allow
  [ $rc -ne 0 ] && got=block
  if [ "$got" = "$expect" ]; then
    PASS=$((PASS + 1))
  else
    FAIL=$((FAIL + 1))
    echo "FAIL [ci] $name: expected $expect, got $got (rc=$rc)"
    echo "$out" | head -3 | sed 's/^/     /'
  fi
}

scenario
(cd "$REPO" && printf '<?php\nDB::select("SELECT 1");\n' > iznik-batch/app/New.php && git add -A && git commit -qm add)
run 'new raw php blocked' block

scenario
(cd "$REPO" && printf '<?php\n// keep-raw: dialect-specific\nDB::select("SELECT 1");\n' > iznik-batch/app/New.php && git add -A && git commit -qm justify)
run 'justified raw allowed' allow

scenario
(cd "$REPO" && printf '<?php\nDB::select("SELECT 1");\n' > iznik-batch/tests/T.php && git add -A && git commit -qm testfile)
run 'raw in tests allowed' allow

# The justification window must span a whole fluent chain. At 8 lines this gate
# blocked its own repository: a keep-raw comment above the statement, then a
# builder chain that did not reach its whereRaw until the 11th line.
scenario
(cd "$REPO" && printf '<?php\n// keep-raw: ST_Contains has no builder method.\n// b\n// c\n// d\n// e\n// f\n// g\n$q = DB::table("t")\n  ->select("a")\n  ->where("b", 1)\n  ->where("c", 2)\n  ->whereRaw("ST_Contains(g, p)")\n  ->get();\n' > iznik-batch/app/Long.php && git add -A && git commit -qm longchain)
run 'keep-raw above a long chain allowed' allow

scenario
(cd "$REPO" && printf 'package m\nfunc f() { db.Raw("SELECT 1") }\n' > iznik-server-go/a.go && git add -A && git commit -qm go)
run 'new raw go blocked' block

# The regression that mattered: the hook must NOT disable itself under $CI.
scenario
(cd "$REPO" && printf 'package m\n// keep-raw: dynamic table name\nfunc f() { db.Raw("SELECT 1") }\n' > iznik-server-go/a.go && git add -A && git commit -qm gojustify)
CI=true run 'still enforces when $CI is set' allow

scenario
(cd "$REPO" && printf 'package m\nfunc f2() { db.Raw("SELECT 2") }\n' > iznik-server-go/b.go && git add -A && git commit -qm gobad)
CI=true run 'blocks even when $CI is set' block

echo
echo "passed: $PASS, failed: $FAIL"
[ "$FAIL" -eq 0 ]
