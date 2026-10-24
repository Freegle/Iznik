#!/bin/bash
# Tests for check-lint-commit.sh and lib/commit-repo.sh. Needs an iznik-nuxt3/node_modules
# with eslint in this checkout or in $CLAUDE_PROJECT_DIR.

HERE="$(cd "$(dirname "$0")" && pwd)"
HOOK="$HERE/check-lint-commit.sh"
export CLAUDE_PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(cd "$HERE/.." && pwd)}"
pass=0; fail=0
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

# A throwaway repo standing in for a fresh clone: no hooks, no node_modules.
R="$TMP/clone"
mkdir -p "$R/iznik-nuxt3/components" "$R/scripts" "$R/other"
cp "$HERE/../scripts/lint-staged-nuxt.mjs" "$R/scripts/"
git -C "$R" init -q && git -C "$R" config user.email t@t && git -C "$R" config user.name t
printf 'export const good = 1\n' > "$R/iznik-nuxt3/components/good.js"
printf 'export const bad = { a:1 }\n' > "$R/iznik-nuxt3/components/bad.js"
printf 'export const bad = { a:1 }\n' > "$R/other/bad.js"

run() { printf '%s' "$(jq -nc --arg c "$1" --arg d "$2" '{tool_input:{command:$c},cwd:$d}')" | "$HOOK" >/dev/null 2>"$TMP/err"; echo $?; }
check() { local n="$1" want="$2" got; got=$(run "$3" "${4:-$TMP}")
  if [ "$got" = "$want" ]; then printf '  PASS  %s\n' "$n"; pass=$((pass+1))
  else printf '  FAIL  %s (exit %s, wanted %s)\n' "$n" "$got" "$want"; sed 's/^/        /' "$TMP/err" | head -5; fail=$((fail+1)); fi; }
stage() { git -C "$R" reset -q; [ $# -gt 0 ] && git -C "$R" add "$@"; }

echo "== which repo =="
. "$HERE/lib/commit-repo.sh"
for c in "cd $R && git commit -m x" "git -C $R commit -m x" "cd /nowhere; cd '$R' && git commit -m x"; do
  got=$(commit_repo_dir "$c" /)
  if [ "$got" = "$R" ]; then printf '  PASS  %s\n' "$c"; pass=$((pass+1)); else printf '  FAIL  %s -> %s\n' "$c" "$got"; fail=$((fail+1)); fi
done
got=$(commit_repo_dir 'cd "$X" && git commit -m x' "$R")
[ "$got" = "$R" ] && { echo "  PASS  a variable falls back to the cwd"; pass=$((pass+1)); } || { echo "  FAIL  variable -> $got"; fail=$((fail+1)); }

echo
echo "== blocks lint errors the way CI does =="
stage iznik-nuxt3/components/bad.js
check "staged error, found through cd"   2 "cd $R && git commit -m x"
check "staged error, found through -C"   2 "git -C $R commit -m x"
check "staged error, cwd is the repo"    2 "git commit -m x" "$R"
grep -q "npx eslint --fix components/bad.js" "$TMP/err" && { echo "  PASS  says how to fix it"; pass=$((pass+1)); } || { echo "  FAIL  no fix hint"; fail=$((fail+1)); }

echo
echo "== passes what CI would pass =="
stage iznik-nuxt3/components/good.js
check "clean staged file"                0 "cd $R && git commit -m x"
stage other/bad.js
check "outside iznik-nuxt3"              0 "cd $R && git commit -m x"
stage iznik-nuxt3/components/good.js
printf 'export const good = { a:1 }\n' > "$R/iznik-nuxt3/components/good.js"
check "unstaged edit is not what commits" 0 "cd $R && git commit -m x"
git -C "$R" add iznik-nuxt3/components/good.js && git -C "$R" commit -qm base && printf 'export const good = { b:1 }\n' > "$R/iznik-nuxt3/components/good.js"
check "commit -a includes it"            2 "cd $R && git commit -am x"
printf 'export const crlf = 1\r\n' > "$R/iznik-nuxt3/components/crlf.js"
git -C "$R" -c core.autocrlf=true add iznik-nuxt3/components/crlf.js
check "CRLF checkout is judged as stored" 0 "cd $R && git commit -m x"
check "not a commit"                     0 "git status"

echo
echo "  $pass passed, $fail failed"
[ "$fail" = "0" ]
