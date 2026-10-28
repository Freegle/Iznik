#!/bin/bash
# Which repository a `git commit` command commits in.
#
# A hook runs in the session's own directory, so `git rev-parse` there names the session's
# repo, not the one the command commits in. `cd ~/clone && git commit` or
# `git -C ~/clone commit` was checked against the wrong tree, or none, and passed. This
# reads the directory out of the command instead: `git -C DIR commit` first, else the last
# `cd DIR` before the commit, else the hook's cwd. A directory spelt with a variable cannot
# be expanded here (the hook sees the command unexpanded), so it falls back to the cwd.
#
# Usage: commit_repo_dir "$COMMAND" "$CWD"   -> prints the repo's top level, or nothing.
commit_repo_dir() {
  local cmd="$1" cwd="${2:-$PWD}" dir="" before
  dir=$(printf '%s' "$cmd" | grep -oE '\bgit[[:space:]]+-C[[:space:]]+("[^"]+"|'"'"'[^'"'"']+'"'"'|[^[:space:]]+)[[:space:]]+commit\b' \
    | head -1 | sed -E 's/^git[[:space:]]+-C[[:space:]]+//; s/[[:space:]]+commit$//')
  if [ -z "$dir" ]; then
    before=$(printf '%s' "$cmd" | awk '{ i = match($0, /git[[:space:]]+commit/); if (i) { print substr($0, 1, i - 1); exit } print }')
    dir=$(printf '%s' "$before" | grep -oE '(^|[;&|(][[:space:]]*|^[[:space:]]*)cd[[:space:]]+("[^"]+"|'"'"'[^'"'"']+'"'"'|[^[:space:];&|)]+)' \
      | tail -1 | sed -E 's/^.*cd[[:space:]]+//')
  fi
  dir="${dir%\"}"; dir="${dir#\"}"; dir="${dir%\'}"; dir="${dir#\'}"
  case "$dir" in
    "" | *'$'* | *'`'*) dir="$cwd" ;;
    "~") dir="$HOME" ;;
    "~/"*) dir="$HOME/${dir#~/}" ;;
    /*) ;;
    *) dir="$cwd/$dir" ;;
  esac
  git -C "$dir" rev-parse --show-toplevel 2>/dev/null
}
