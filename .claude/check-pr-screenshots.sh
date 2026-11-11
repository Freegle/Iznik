#!/bin/bash
# PreToolUse hook: a PR that changes what members or moderators see carries a
# screenshot of the change.
#
# Why: a reviewer reads the description first and the diff second, and for a
# template or style change the diff is the slow way to see what happened. A
# picture of the before and after is the fastest review there is, it is what
# Edward asks for, and it catches the mistakes a passing test does not: a button
# that wrapped, a bar that slid under the navbar, a notice in the wrong colour.
#
# Fires on: gh pr create / gh pr edit --body / gh api ... /pulls/N (PATCH or POST),
# the same commands check-pr-text.sh guards. Then:
#
#   1. Does the branch change a file that draws something? Any .vue, .scss or .css
#      under iznik-nuxt3 (the member site and ModTools share the tree), compared
#      with origin/master.
#   2. Does the body carry an image? Markdown ![...](...) or an <img> tag.
#
# Both true, or neither, and the command goes through. A UI change with no image
# is blocked with the recipe for adding one, because gh cannot upload to GitHub's
# image store from the CLI and the way round that is easy to forget.
#
# Override: PR_NO_SCREENSHOT=1 on the command, for a .vue change with nothing to
# see (a computed moved, a test id added, a string nobody reads). Say why in the
# description; the reviewer will wonder otherwise.
#
# The body is read the way check-pr-text.sh reads it: a literal --body-file path,
# -F body=@path, $(cat path), an inline --body "...", or a heredoc in the command.
# A body it cannot read is left to check-pr-text.sh, which blocks on that.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty' 2>/dev/null)
[ -z "$COMMAND" ] && exit 0

# The command with heredoc BODIES stripped, so a quoted example in a commit
# message does not fire this.
TRIGGER=$(echo "$COMMAND" | awk '
  {
    if (tag != "") { if ($0 ~ ("^[[:space:]]*" tag "[[:space:]]*$")) tag = ""; next }
    line = $0
    if (match(line, /<<-?["'"'"'"]?[A-Za-z_][A-Za-z0-9_]*["'"'"'"]?/)) {
      t = substr(line, RSTART, RLENGTH)
      sub(/^<<-?/, "", t); gsub(/["'"'"'"]/, "", t)
      tag = t
    }
    print line
  }')

echo "$TRIGGER" | grep -qE '\bgh[[:space:]]+pr[[:space:]]+(create|edit)\b|\bgh[[:space:]]+api\b[^|]*\bpulls?/[0-9]+' || exit 0
echo "$TRIGGER" | grep -qE '\bgh[[:space:]]+pr[[:space:]]+view\b' && exit 0
# Only commands that set a body.
echo "$TRIGGER" | grep -qE '\-\-body(-file)?\b|\-[fF][[:space:]]+body=|<<[-]?['"'"'"]?(EOF|MSG|BODY|PR)' || exit 0

echo "$COMMAND" | grep -qE '(^|[[:space:];&|])PR_NO_SCREENSHOT=1\b' && exit 0

# --------------------------------------------------------------------------
# Which repo, and does the branch draw anything?
# --------------------------------------------------------------------------
# A leading `cd X &&` names the checkout; otherwise the current directory.
CD_TARGET=$(echo "$COMMAND" | grep -oE '(^|[;&|][[:space:]]*)cd[[:space:]]+[^[:space:];&|]+' | head -1 | sed -E 's/^.*cd[[:space:]]+//')
if [ -n "$CD_TARGET" ] && [ -d "$CD_TARGET" ]; then
  REPO_DIR="$CD_TARGET"
else
  REPO_DIR="$PWD"
fi
git -C "$REPO_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1 || exit 0

BASE=$(git -C "$REPO_DIR" merge-base origin/master HEAD 2>/dev/null)
[ -z "$BASE" ] && exit 0
UI_FILES=$(git -C "$REPO_DIR" diff --name-only "$BASE" HEAD 2>/dev/null \
  | grep -E '^iznik-nuxt3/.*\.(vue|scss|css)$' \
  | grep -vE '/tests?/')
[ -z "$UI_FILES" ] && exit 0

# --------------------------------------------------------------------------
# Does the body carry an image?
# --------------------------------------------------------------------------
BODY_TEXT=""
add_file() {
  local f="$1"
  f="${f%\"}"; f="${f#\"}"; f="${f%\'}"; f="${f#\'}"
  [ -n "$f" ] && [ -f "$f" ] && BODY_TEXT="$BODY_TEXT
$(cat "$f")"
}
while IFS= read -r f; do
  [ -n "$f" ] && add_file "$f"
done < <(echo "$COMMAND" \
  | grep -oE '(--body-file[=[:space:]]+|-[fF][[:space:]]+body=@)[^[:space:]]+' \
  | sed -E 's/^(--body-file[=[:space:]]+|-[fF][[:space:]]+body=@)//')
while IFS= read -r f; do
  [ -n "$f" ] && add_file "$f"
done < <(echo "$COMMAND" | grep -oE '\$\((cat|<)[[:space:]]+[^)]+\)' \
  | sed -E 's/^\$\((cat|<)[[:space:]]+//; s/\)$//')
# An inline or heredoc body is in the command itself.
BODY_TEXT="$BODY_TEXT
$COMMAND"

if echo "$BODY_TEXT" | grep -qE '!\[[^]]*\]\([^)]+\)|<img[[:space:]]'; then
  exit 0
fi

{
  echo "STOP. This branch changes what people see, and the PR has no screenshot."
  echo ""
  echo "Files that draw something:"
  echo "$UI_FILES" | sed 's/^/  /' | head -12
  echo ""
  echo "A picture is the fastest review there is, and it catches what a passing test"
  echo "does not: a button that wrapped, a bar under the navbar, a notice in the wrong"
  echo "colour. Put one in the description. Before and after, where the change is to"
  echo "something that already existed."
  echo ""
  echo "How, from the CLI (gh cannot upload to GitHub's image store):"
  echo "  1. Open the page on the worktree's dev container (./freegle status for the"
  echo "     URL) and save PNGs to the scratchpad with Chrome DevTools MCP"
  echo "     take_screenshot filePath=... (phone width too if the layout is new)."
  echo "  2. Publish them on a throwaway branch with no code in it:"
  echo "       b=\$(git hash-object -w shot.png)"
  echo "       tree=\$(printf '100644 blob %s\\tshot.png\\n' \"\$b\" | git mktree)"
  echo "       c=\$(git commit-tree \"\$tree\" -m 'PR screenshots')"
  echo "       git push origin \"\$c:refs/heads/pr-assets/<branch-slug>\""
  echo "  3. Reference them in the body:"
  echo "       ![What it shows](https://raw.githubusercontent.com/Freegle/Iznik/pr-assets/<branch-slug>/shot.png)"
  echo "     and say the pr-assets branch can be deleted after merge."
  echo ""
  echo "Nothing to see (a computed moved, a test id, a string nobody reads)? Prefix the"
  echo "command with PR_NO_SCREENSHOT=1 and say so in the description."
} >&2
exit 2
