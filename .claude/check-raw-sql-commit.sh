#!/bin/bash
# PreToolUse hook for git commit: warn when the commit adds new raw SQL.
#
# The v2 API and iznik-batch are being moved off hand-written SQL onto GORM /
# Eloquent a wave at a time. CI enforces this in ci-ratchet.sh gates (b) and
# (d) - a new raw site with no manifest entry, or a raw+in-progress count above
# the baseline, fails the build. That feedback arrives minutes after a push.
# This hook says the same thing at commit time instead.
#
# WARNING ONLY - it never blocks. Raw SQL is sometimes right (see keep-raw.json)
# and test fixtures are exempt by design. The point is to make the author decide
# deliberately rather than find out from CI.
#
# The method surfaces below are copied from the two extractors so this agrees
# with what CI will actually count:
#   Go    - sqlMethods + sqlWrappers in tools/orm-migration/extract.go
#   PHP   - CONNECTION_SQL_METHODS + BUILDER_FRAGMENT_METHODS +
#           BLUEPRINT_DDL_METHODS in tools/orm-migration/php-extractor/extract.php
# If either list changes there, change it here too.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // ""' 2>/dev/null)

# Only fire for git commit.
if ! echo "$COMMAND" | grep -qE '\bgit\s+commit\b'; then
  exit 0
fi

REPO_ROOT=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
cd "$REPO_ROOT" 2>/dev/null || exit 0

# What is about to be committed. `git commit -a` sweeps in tracked-but-unstaged
# changes too, so include those when the flag is present.
DIFF=$(git diff --cached -U0 2>/dev/null)
if echo "$COMMAND" | grep -qE '\bgit\s+commit\b[^|;&]*\s-[a-zA-Z]*a'; then
  DIFF="$DIFF"$'\n'$(git diff -U0 2>/dev/null)
fi
[ -z "$DIFF" ] && exit 0

GO_METHODS='Raw|Exec|ExecContext|Query|QueryRow|QueryContext|QueryRowContext|Prepare|PrepareContext'
GO_WRAPPERS='RetryExec|RetryExecResult|RetryQuery|ExecInsertGetID'
PHP_CONNECTION='select|selectOne|scalar|cursor|insert|update|delete|statement|unprepared|raw'
PHP_FRAGMENT='selectRaw|fromRaw|whereRaw|orWhereRaw|groupByRaw|havingRaw|orHavingRaw|orderByRaw'
PHP_DDL='rawIndex|rawColumn'

# Walk the diff, tracking which file each added line belongs to, and split hits
# into production vs test/migration. A raw call in a test is normal - the
# extractor files those as status "test-fixture" - so it gets a quieter note.
REPORT=$(echo "$DIFF" | awk \
  -v go_m="$GO_METHODS" -v go_w="$GO_WRAPPERS" \
  -v php_c="$PHP_CONNECTION" -v php_f="$PHP_FRAGMENT" -v php_d="$PHP_DDL" '
  /^\+\+\+ b\// { file = substr($0, 7); next }
  /^\+/ && !/^\+\+\+/ {
    line = substr($0, 2)
    if (line ~ /^[[:space:]]*(\/\/|#|\*)/) next          # comments

    hit = ""
    if (file ~ /\.go$/) {
      if (line ~ ("\\.(" go_m ")\\(")) hit = "Go"
      else if (line ~ ("(" go_w ")\\(")) hit = "Go wrapper"
    } else if (file ~ /\.php$/) {
      if (line ~ ("DB::(" php_c ")\\(")) hit = "Laravel DB::"
      else if (line ~ ("->(" php_f ")\\(")) hit = "Laravel fragment"
      else if (line ~ ("->(" php_d ")\\(")) hit = "Laravel DDL"
    }
    if (hit == "") next

    is_test = (file ~ /_test\.go$/ || file ~ /\/tests?\// || file ~ /Test\.php$/ || file ~ /\/database\/migrations\//)
    gsub(/^[[:space:]]+/, "", line)
    if (length(line) > 100) line = substr(line, 1, 100) "..."
    if (is_test) { t[file] = t[file] "      " line "\n"; tn++ }
    else         { p[file] = p[file] "      " hit ": " line "\n"; pn++ }
  }
  END {
    if (pn == 0 && tn == 0) exit 1
    printf "PROD=%d\n", pn
    if (pn > 0) {
      printf "  %d new raw SQL call(s) in PRODUCTION code:\n", pn
      for (f in p) printf "    %s\n%s", f, p[f]
    }
    if (tn > 0) printf "  %d in test/migration files (normally fine - the extractor files these as test-fixture).\n", tn
    exit 0
  }')

[ -z "$REPORT" ] && exit 0

PROD_COUNT=$(echo "$REPORT" | head -1 | cut -d= -f2)
BODY=$(echo "$REPORT" | tail -n +2)

# Test-only hits get a one-liner. The full explanation is only worth the noise
# when production code gained raw SQL, which is what CI actually gates on.
if [ "$PROD_COUNT" = "0" ]; then
  jq -n --arg b "$BODY" '{systemMessage: ("Raw SQL check: test/migration files only, which CI files as test-fixture. Nothing to do.\n" + $b)}'
  exit 0
fi

# Emit as a systemMessage so it is a visible warning, not a block.
jq -n --arg b "$BODY" '{systemMessage: ("Raw SQL check: this commit adds raw SQL.\n\n" + $b + "\nThe v2 API and iznik-batch are migrating onto GORM/Eloquent. Prefer the query builder. If raw is genuinely right, it needs a manifest entry, and if it changes a converted site'"'"'s SQL it needs an approved-diffs.json entry with a reason.\n\n  cd tools/orm-migration && go run .     # regenerate the manifest\n  bash tools/orm-migration/ci-ratchet.sh # what CI will check\n\nSee docs/developers/reference/orm-migration-harness.md. Warning only - nothing is blocked.")}'
exit 0
