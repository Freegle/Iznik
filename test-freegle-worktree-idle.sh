#!/bin/bash
# Tests for the freegle CLI's idle-worktree helper (worktree_idle_days), which decides
# whether a worktree has gone quiet long enough for prune to drop its containers.
# Run: bash test-freegle-worktree-idle.sh
set -uo pipefail

SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/freegle"

# Source the CLI without triggering its command dispatch.
FREEGLE_LIB_ONLY=1 source "$SCRIPT"
set +e

fails=0
check() {
    local desc="$1" expected="$2" actual="$3"
    if [[ "$expected" == "$actual" ]]; then
        echo "PASS: $desc"
    else
        echo "FAIL: $desc"
        echo "      expected: [$expected]"
        echo "      actual:   [$actual]"
        fails=$(( fails + 1 ))
    fi
}

tmproot=$(mktemp -d)
trap 'rm -rf "$tmproot"' EXIT

now=$(date +%s)
day=86400

# commit_at DIR DAYS_AGO MESSAGE - commit with both dates pinned that far back.
commit_at() {
    local dir="$1" days="$2" msg="$3" when
    when=$(date -d "@$(( now - days * day ))" --rfc-2822)
    GIT_AUTHOR_DATE="$when" GIT_COMMITTER_DATE="$when" \
        git -C "$dir" commit --quiet --allow-empty -m "$msg"
}

new_repo() {
    local dir="$1"
    mkdir -p "$dir"
    git -C "$dir" init --quiet -b main
    git -C "$dir" config user.email t@example.com
    git -C "$dir" config user.name Test
}

# A repo whose only commit is 30 days old counts as 30 days idle.
new_repo "$tmproot/old"
commit_at "$tmproot/old" 30 "old work"
check "commit 30 days ago reads as 30 days idle" "30" "$(worktree_idle_days "$tmproot/old" "$now")"

# A repo committed to today counts as zero.
new_repo "$tmproot/fresh"
commit_at "$tmproot/fresh" 0 "today"
check "commit today reads as 0 days idle" "0" "$(worktree_idle_days "$tmproot/fresh" "$now")"

# The measure is the pushed commit, not the local one: a branch pushed 20 days ago
# with a local commit made today is still 20 days idle, because nothing was pushed.
new_repo "$tmproot/remote-src"
commit_at "$tmproot/remote-src" 20 "pushed work"
git clone --quiet "$tmproot/remote-src" "$tmproot/withremote" 2>/dev/null
git -C "$tmproot/withremote" config user.email t@example.com
git -C "$tmproot/withremote" config user.name Test
commit_at "$tmproot/withremote" 0 "local only, never pushed"
check "unpushed local commit does not count as activity" "20" "$(worktree_idle_days "$tmproot/withremote" "$now")"

# Detached HEAD has no remote branch to consult, so it falls back to the commit.
git -C "$tmproot/old" checkout --quiet --detach HEAD
check "detached HEAD falls back to its commit date" "30" "$(worktree_idle_days "$tmproot/old" "$now")"

# An unreadable directory counts as idle rather than fresh, so a broken worktree
# is never treated as active work.
check "unreadable directory counts as idle" "99999" "$(worktree_idle_days "$tmproot/does-not-exist" "$now")"

echo ""
if [[ $fails -eq 0 ]]; then
    echo "All tests passed."
else
    echo "$fails test(s) failed."
fi
exit $(( fails > 0 ))
