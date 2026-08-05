#!/usr/bin/env bash
#
# check-imports.sh - does every Go file that uses gorm/clause/dbresolver
# actually import it?
#
# gofmt checks formatting, not compilation, so a missing import passes every
# check an agent can run and only surfaces when the build runs - which agents
# cannot do. That blind spot has broken two validation runs: once with a
# nonexistent "gorm.io/gorm/dbresolver" path, once with gorm.Expr used in
# noticeboard.go with no gorm import at all.
#
# This is a cheap textual approximation, not a compiler. It exists to catch the
# specific mistake that keeps happening, before a six-minute suite run does.
set -uo pipefail

ROOT="${1:-/home/edward/FreegleDocker-orm-v2/iznik-server-go}"
fail=0

while IFS= read -r f; do
  case "$f" in
    */vendor/*) continue ;;
  esac

  # Strip the import block (so an import line is not mistaken for a use) and
  # strip comments (so prose mentioning "clause.Expr machinery" or
  # "gorm.DeletedAt" is not either). Comment-blindness made the first version
  # of this check fire on seven files that compile perfectly well, and a check
  # that cries wolf gets ignored, which is worse than not having one.
  body="$(sed '/^import (/,/^)/d' "$f" | perl -0pe 's{/\*.*?\*/}{}gs; s{//[^\n]*}{}g')"

  if grep -qE '\bgorm\.[A-Z]' <<<"$body" && ! grep -q '"gorm.io/gorm"' "$f"; then
    echo "MISSING IMPORT  $f  uses gorm. but does not import gorm.io/gorm"
    fail=1
  fi
  if grep -qE '\bclause\.[A-Z]' <<<"$body" && ! grep -q '"gorm.io/gorm/clause"' "$f"; then
    echo "MISSING IMPORT  $f  uses clause. but does not import gorm.io/gorm/clause"
    fail=1
  fi
  if grep -qE '\bdbresolver\.[A-Z]' <<<"$body" && ! grep -q '"gorm.io/plugin/dbresolver"' "$f"; then
    echo "MISSING IMPORT  $f  uses dbresolver. but does not import gorm.io/plugin/dbresolver"
    fail=1
  fi

  # The path that does not exist. A batch used it once and broke the build.
  if grep -q '"gorm.io/gorm/dbresolver"' "$f"; then
    echo "BAD IMPORT PATH $f  gorm.io/gorm/dbresolver does not exist; use gorm.io/plugin/dbresolver"
    fail=1
  fi
done < <(find "$ROOT" -name '*.go' -type f)

if [ "$fail" -ne 0 ]; then
  echo "check-imports: FAIL"
  exit 1
fi
echo "check-imports: OK"
