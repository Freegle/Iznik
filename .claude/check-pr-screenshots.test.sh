#!/bin/bash
# Tests for check-pr-screenshots.sh. Self-contained: builds a throwaway git repo
# with an origin/master and a branch, so the hook's diff has something to read.
#
#   bash .claude/check-pr-screenshots.test.sh
#
# The command strings under test contain "gh pr create", so this lives in a file
# rather than being typed at a shell - sibling hooks react to that literal.

HOOK="$(cd "$(dirname "$0")" && pwd)/check-pr-screenshots.sh"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
G="gh"; PRC="$G pr create"
pass=0; fail=0

run() { printf '%s' "$(jq -nc --arg c "$1" '{tool_input:{command:$c}}')" | "$HOOK" >/dev/null 2>"$TMP/err"; echo $?; }

check() { # name  want_exit  command  [must_contain]
  local name="$1" want="$2" cmd="$3" needle="${4:-}" got ok=1
  got=$(run "$cmd")
  [ "$got" = "$want" ] || ok=0
  [ -n "$needle" ] && ! grep -qi -- "$needle" "$TMP/err" && ok=0
  if [ "$ok" = "1" ]; then
    printf '  PASS  %s\n' "$name"; pass=$((pass+1))
  else
    printf '  FAIL  %s (exit %s, wanted %s%s)\n' "$name" "$got" "$want" "${needle:+, needle '$needle'}"
    sed 's/^/          /' "$TMP/err" | head -6; fail=$((fail+1))
  fi
}

# A repo whose branch changes a component, and one whose branch changes only Go.
mkrepo() { # dir  changed-file
  local d="$1" f="$2"
  mkdir -p "$d" && git -C "$d" init -q -b master
  git -C "$d" -c user.email=t@t -c user.name=t commit -q --allow-empty -m base
  git -C "$d" update-ref refs/remotes/origin/master HEAD
  git -C "$d" checkout -q -b feature
  mkdir -p "$d/$(dirname "$f")" && echo x > "$d/$f"
  git -C "$d" add -A && git -C "$d" -c user.email=t@t -c user.name=t commit -q -m change
}
mkrepo "$TMP/ui" "iznik-nuxt3/modtools/components/ModThing.vue"
mkrepo "$TMP/go" "iznik-server-go/thing/thing.go"
mkrepo "$TMP/spec" "iznik-nuxt3/tests/unit/components/Thing.spec.js"

printf 'Changes the thing.\n' > "$TMP/plain.md"
printf 'Changes the thing.\n\n![The thing](https://raw.githubusercontent.com/Freegle/Iznik/pr-assets/x/thing.png)\n' > "$TMP/shot.md"
printf 'Changes the thing.\n\n<img src="https://example.com/t.png" width="300">\n' > "$TMP/img.md"

echo "== a UI change needs a picture =="
check "component change, no image: blocked" 2 \
  "cd $TMP/ui && $PRC --title t --body-file $TMP/plain.md" "no screenshot"
check "the blocked message names the file" 2 \
  "cd $TMP/ui && $PRC --title t --body-file $TMP/plain.md" "ModThing.vue"
check "the blocked message gives the recipe" 2 \
  "cd $TMP/ui && $PRC --title t --body-file $TMP/plain.md" "pr-assets"
check "markdown image: allowed" 0 \
  "cd $TMP/ui && $PRC --title t --body-file $TMP/shot.md"
check "img tag: allowed" 0 \
  "cd $TMP/ui && $PRC --title t --body-file $TMP/img.md"
check "inline body with image: allowed" 0 \
  "cd $TMP/ui && $PRC --title t --body \"See ![x](https://e.com/x.png)\""
check "gh api PATCH with -F body=@file: blocked" 2 \
  "cd $TMP/ui && $G api -X PATCH repos/O/R/pulls/9 -F body=@$TMP/plain.md" "no screenshot"
check "PR_NO_SCREENSHOT=1 overrides" 0 \
  "cd $TMP/ui && PR_NO_SCREENSHOT=1 $PRC --title t --body-file $TMP/plain.md"

echo
echo "== nothing drawn, nothing asked =="
check "Go-only branch: allowed" 0 \
  "cd $TMP/go && $PRC --title t --body-file $TMP/plain.md"
check "spec-only branch: allowed" 0 \
  "cd $TMP/spec && $PRC --title t --body-file $TMP/plain.md"
check "gh pr edit with only a label: not a body" 0 \
  "cd $TMP/ui && $G pr edit 9 --add-label x"
check "gh pr view: ignored" 0 \
  "cd $TMP/ui && $G pr view 9 --json body"
check "a commit quoting the command in a heredoc: ignored" 0 \
  "cd $TMP/ui && git commit -m x -F - <<'MSG'
docs: say to run $PRC --body-file body.md
MSG"
check "not a git repo: ignored" 0 \
  "cd $TMP && $PRC --title t --body-file $TMP/plain.md"

echo
echo "$pass passed, $fail failed"
[ "$fail" = "0" ]
