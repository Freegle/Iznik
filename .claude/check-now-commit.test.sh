#!/bin/bash
# Tests for check-now-commit.sh: it prompts when a commit adds SQL that reads the database clock,
# and says nothing otherwise. It never blocks.

HERE="$(cd "$(dirname "$0")" && pwd)"
HOOK="$HERE/check-now-commit.sh"
pass=0; fail=0
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

R="$TMP/repo"
mkdir -p "$R"
git -C "$R" init -q && git -C "$R" config user.email t@t && git -C "$R" config user.name t
printf 'package a\n' > "$R/a.go"; printf '<?php\n' > "$R/a.php"; printf 'x\n' > "$R/a.md"
git -C "$R" add . && git -C "$R" commit -qm base

# Stages one added line in a file and prints what the hook says about `git commit`.
said() { # file line [command]
  git -C "$R" checkout -q -- . 2>/dev/null; git -C "$R" reset -q
  printf '%s\n' "$2" >> "$R/$1"; git -C "$R" add "$1"
  jq -nc --arg c "${3:-git commit -m x}" --arg d "$R" '{tool_input:{command:$c},cwd:$d}' | "$HOOK"; echo "exit=$?"
}
prompts() { local out; out=$(said "$2" "$3" "$4")
  if echo "$out" | grep -q 'Database clock check' && echo "$out" | grep -q 'exit=0'; then printf '  PASS  %s\n' "$1"; pass=$((pass+1))
  else printf '  FAIL  %s: no prompt\n        %s\n' "$1" "$(echo "$out" | head -c 200)"; fail=$((fail+1)); fi; }
silent() { local out; out=$(said "$2" "$3" "$4")
  if [ "$out" = "exit=0" ]; then printf '  PASS  %s\n' "$1"; pass=$((pass+1))
  else printf '  FAIL  %s: said something\n        %s\n' "$1" "$(echo "$out" | head -c 200)"; fail=$((fail+1)); fi; }

echo "== prompts for the database clock =="
prompts "NOW() in Go SQL"                a.go  '	db.Exec("UPDATE t SET ts = NOW() WHERE id = ?", id)'
prompts "NOW() in PHP SQL"               a.php "        DB::statement('UPDATE t SET ts = NOW()');"
prompts "CURRENT_TIMESTAMP"              a.php "        \$q->whereRaw('ts < CURRENT_TIMESTAMP');"
prompts "CURDATE()"                      a.go  '	db.Raw("SELECT CURDATE()")'
prompts "gorm.Expr NOW()"                a.go  '		"added": gorm.Expr("NOW()"),'
prompts "a .sql file"                    b.sql 'ALTER TABLE t ADD c timestamp DEFAULT CURRENT_TIMESTAMP;'
prompts "compared with no space"         a.go  '	db.Raw("SELECT 1 FROM t WHERE ts>NOW()")'
prompts "git commit -a"                  a.go  '	db.Exec("SELECT NOW()")' 'git commit -am x'

echo
echo "== says nothing for the application clock and other text =="
silent "PHP now() helper"                a.php "        \$mark = now()->subMinutes(5);"
silent "Go time.Now()"                   a.go  '	start := time.Now()'
silent "Carbon::now()"                   a.php "        \$t = Carbon::now();"
silent "a comment about NOW()"           a.go  '	// NOW() is the statement time on MySQL'
silent "a markdown file"                 a.md  'Use NOW() here.'
silent "a method called NOW"             a.php "        \$x = \$clock->NOW();"
silent "SNOW() is not NOW()"             a.go  '	x := SNOW()'
silent "not a commit"                    a.go  '	db.Exec("SELECT NOW()")' 'git status'

echo
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
