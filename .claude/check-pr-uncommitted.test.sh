#!/bin/bash
# Tests for check-pr-uncommitted.sh. Self-contained: builds its own dirty repo.
#
#   bash .claude/check-pr-uncommitted.test.sh
#
# In a file rather than typed at a shell because the commands under test contain
# the literal the hook triggers on - which is the whole point of half of them.

HOOK="$(cd "$(dirname "$0")" && pwd)/check-pr-uncommitted.sh"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
REPO="$TMP/repo"
mkdir -p "$REPO" && cd "$REPO" || exit 1
git init -q . && git -c user.email=t@t -c user.name=t commit -q --allow-empty -m init
echo "wip" > wip.txt          # leave it dirty

G="gh"; PRC="$G pr create"
pass=0; fail=0
run() {
  printf '%s' "$(jq -nc --arg c "$1" --arg d "$REPO" '{tool_input:{command:$c},cwd:$d}')" \
    | "$HOOK" >/dev/null 2>"$TMP/err"; echo $?
}
check() { local name="$1" want="$2" cmd="$3" got; got=$(run "$cmd")
  if [ "$got" = "$want" ]; then printf '  PASS  %s\n' "$name"; pass=$((pass+1))
  else printf '  FAIL  %s (exit %s, wanted %s)\n' "$name" "$got" "$want"
       sed 's/^/        /' "$TMP/err" | head -3; fail=$((fail+1)); fi; }

echo "== must fire: opening a PR from a dirty tree =="
check "plain invocation"  2 "$PRC --base master --head x --title t --body b"
check "with env prefix"   2 "PR_TEXT_VERIFIED=1 $PRC --base master --head x --title t"

echo
echo "== must not fire: quoting or searching for the trigger =="
# Both of these were observed blocking real work before the trigger was tightened:
# a read-only grep, and a git commit whose message showed an example.
check "grep whose pattern quotes it" 0 "grep -rn '$PRC' .claude/*.sh"
check "grep -l over hook scripts"    0 "grep -ln 'gh pr create' .claude/*.sh"
QUOTED="git commit -F - <<'MSG'
docs: explain the flow

    $PRC --body-file body.md
MSG"
check "commit message quoting it"    0 "$QUOTED"
check "override honoured"            0 "ALLOW_DIRTY_PR=1 $PRC --title t --body b"
check "unrelated command"            0 "ls -la"

echo
echo "== must not fire: clean tree =="
git add -A && git -c user.email=t@t -c user.name=t commit -q -m wip
check "clean tree passes" 0 "$PRC --base master --head x --title t --body b"

echo
echo "  $pass passed, $fail failed"
[ "$fail" = "0" ]
