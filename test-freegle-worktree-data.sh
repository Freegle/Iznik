#!/bin/bash
# Tests for the freegle CLI's shared-data helper (_share_gitignored_data), which gives a new
# worktree the large gitignored downloads (the UK OSM extract) that its containers need to boot.
# Run: bash test-freegle-worktree-data.sh
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

# Build a fake main checkout with the gitignored extract, and an empty fake worktree.
setup_pair() {
    local name="$1"
    local main="$tmproot/$name-main" wt="$tmproot/$name-wt"
    rm -rf "$main" "$wt"
    mkdir -p "$main/iznik-routing-go/data" "$wt/iznik-routing-go/data"
    echo "$name-pbf-contents" > "$main/iznik-routing-go/data/uk-latest.osm.pbf"
    echo "$main" "$wt"
}

# 1. The extract is absent from the worktree → it gets it.
read -r main wt < <(setup_pair copies)
_share_gitignored_data "$main" "$wt" >/dev/null 2>&1
check "copies the OSM extract into a fresh worktree" \
    "copies-pbf-contents" "$(cat "$wt/iznik-routing-go/data/uk-latest.osm.pbf" 2>/dev/null)"

# 2. It is shared by hardlink, so N worktrees do not cost N x 2.5GB of disk.
read -r main wt < <(setup_pair links)
_share_gitignored_data "$main" "$wt" >/dev/null 2>&1
main_inode=$(stat -c %i "$main/iznik-routing-go/data/uk-latest.osm.pbf" 2>/dev/null)
wt_inode=$(stat -c %i "$wt/iznik-routing-go/data/uk-latest.osm.pbf" 2>/dev/null)
check "shares the extract by hardlink rather than duplicating 2.5GB" "$main_inode" "$wt_inode"

# 3. A worktree that already has its own extract keeps it untouched.
read -r main wt < <(setup_pair keeps)
echo "worktree-own-pbf" > "$wt/iznik-routing-go/data/uk-latest.osm.pbf"
_share_gitignored_data "$main" "$wt" >/dev/null 2>&1
check "leaves an extract the worktree already has alone" \
    "worktree-own-pbf" "$(cat "$wt/iznik-routing-go/data/uk-latest.osm.pbf")"

# 4. No extract in the main checkout is not an error - a machine that never downloaded it should
#    still get a working worktree for everything that does not need the routing graph.
read -r main wt < <(setup_pair missing)
rm -f "$main/iznik-routing-go/data/uk-latest.osm.pbf"
_share_gitignored_data "$main" "$wt" >/dev/null 2>&1
check "succeeds when the main checkout has no extract" "0" "$?"
check "and creates no bogus file" \
    "absent" \
    "$([[ -e "$wt/iznik-routing-go/data/uk-latest.osm.pbf" ]] && echo present || echo absent)"

# 5. Works when the worktree data directory does not exist yet.
read -r main wt < <(setup_pair nodir)
rm -rf "$wt/iznik-routing-go"
_share_gitignored_data "$main" "$wt" >/dev/null 2>&1
check "creates the data directory when missing" \
    "nodir-pbf-contents" "$(cat "$wt/iznik-routing-go/data/uk-latest.osm.pbf" 2>/dev/null)"

echo
if (( fails > 0 )); then
    echo "$fails test(s) failed"
    exit 1
fi
echo "All shared-data tests passed"
