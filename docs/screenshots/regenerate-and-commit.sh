#!/usr/bin/env bash
#
# Regenerate the documentation screenshots and, if any changed MEANINGFULLY,
# commit them back to the current branch WITHOUT triggering another CI run.
#
# This is the "auto-commit on change" half of keeping docs fresh: a CI job (or a
# person) runs it, and any drift in the screenshots is committed alongside the
# change that caused it. The commit message carries [skip ci] so pushing it does
# not start a new pipeline (CircleCI honours [skip ci] / [ci skip]).
#
# Loop safety, in order:
#   1. Refuses to run on master/production (screenshots are regenerated on
#      feature branches, before merge).
#   2. No-ops if HEAD is already an auto-generated screenshot commit.
#   3. Commits with [skip ci] so the push it makes does not re-run CI.
#
# Required-checks caveat: because the pushed commit skips CI, it will not carry a
# CircleCI status. If a CircleCI check is *required* to merge, either do not make
# the screenshot job a required check, or drop [skip ci] and accept a fast,
# path-filtered no-op re-run instead. See docs/maintaining-docs.md.
#
# Environment:
#   TEST_BASE_URL, TEST_MODTOOLS_BASE_URL, DOCS_MEMBER_*, DOCS_MOD_*  - passed
#     through to the capture script (see docs/screenshots/README.md).
#   GITHUB_TOKEN  - if set, used to push over HTTPS (for CI). Otherwise a plain
#     `git push` is used (for local runs with existing credentials).
#   DOCS_COMMIT_AUTHOR / DOCS_COMMIT_EMAIL - optional bot identity for the commit.
#   DOCS_PIXEL_THRESHOLD / DOCS_PIXEL_FUZZ - meaningful-change tuning. Needs the
#     ImageMagick `compare` tool; without it, all pixel changes are committed.
set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT"

# --commit-only skips generation and just commits assets already in the working
# tree (CI generates inside the -playwright container and copies them out).
COMMIT_ONLY=0
[ "${1:-}" = "--commit-only" ] && COMMIT_ONLY=1

# In CI the checkout is a detached HEAD, so prefer CIRCLE_BRANCH for the branch.
BRANCH="${CIRCLE_BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"
if [ "$BRANCH" = "production" ]; then
  echo "On production - not committing screenshots here. Exiting."
  exit 0
fi
if [ "$BRANCH" = "master" ] && [ "${DOCS_ALLOW_MASTER:-}" != "1" ]; then
  echo "On master and DOCS_ALLOW_MASTER != 1 - not committing. Exiting."
  exit 0
fi

if git log -1 --pretty=%s | grep -qi '\[skip ci\].*screenshot\|regenerate screenshots'; then
  echo "HEAD is already an auto-generated screenshot commit - nothing to do."
  exit 0
fi

if [ "$COMMIT_ONLY" = 0 ]; then
  echo "Regenerating screenshots..."
  ( cd iznik-nuxt3 && node tests/e2e/docs-screenshots.mjs ) || {
    echo "Screenshot generation failed - not committing." >&2
    exit 1
  }
fi

# Keep only screenshots that changed MEANINGFULLY. Screenshots carry a few
# non-deterministic pixels (antialiasing, carets, sub-pixel layout), so a raw
# byte diff would commit noise. Compare each changed PNG to the committed
# version and revert ones whose differing-pixel count is below a threshold.
# Brand-new screenshots (no committed version) are always kept.
THRESHOLD="${DOCS_PIXEL_THRESHOLD:-400}"   # differing pixels to count as meaningful
FUZZ="${DOCS_PIXEL_FUZZ:-6%}"              # per-pixel colour tolerance
if command -v compare >/dev/null 2>&1; then
  while IFS= read -r png; do
    [ -z "$png" ] && continue
    if ! git cat-file -e "HEAD:$png" 2>/dev/null; then
      echo "  new screenshot (keep): $png"
      continue
    fi
    old="$(mktemp --suffix=.png)"
    git show "HEAD:$png" >"$old"
    raw="$(compare -metric AE -fuzz "$FUZZ" "$old" "$png" null: 2>&1 || true)"
    rm -f "$old"
    diffpx="$(printf '%s' "$raw" | grep -oE '^[0-9]+' || true)"
    [ -z "$diffpx" ] && diffpx=999999   # unparseable (e.g. size change) -> meaningful
    if [ "$diffpx" -lt "$THRESHOLD" ]; then
      git checkout -- "$png"
      echo "  noise only (${diffpx}px < ${THRESHOLD}, reverted): $png"
    else
      echo "  meaningful change (${diffpx}px): $png"
    fi
  done < <(git diff --name-only -- 'docs/*/assets/*.png')
else
  echo "WARNING: ImageMagick 'compare' not found - committing all pixel changes" >&2
  echo "         without the meaningfulness filter. Install imagemagick in CI." >&2
fi

git add docs/members/assets docs/moderators/assets 2>/dev/null || true

if git diff --cached --quiet -- docs/members/assets docs/moderators/assets; then
  echo "No screenshot changes. Done."
  exit 0
fi

echo "Screenshots changed - committing."
AUTHOR_NAME="${DOCS_COMMIT_AUTHOR:-freegle-docs-bot}"
AUTHOR_EMAIL="${DOCS_COMMIT_EMAIL:-docs-bot@ilovefreegle.org}"

git -c user.name="$AUTHOR_NAME" -c user.email="$AUTHOR_EMAIL" \
  commit -m "docs: regenerate screenshots [skip ci]" -- docs/members/assets docs/moderators/assets

if [ -n "${GITHUB_TOKEN:-}" ]; then
  # Push over HTTPS with the CI token so the commit lands on the PR branch.
  REMOTE_URL="https://x-access-token:${GITHUB_TOKEN}@github.com/Freegle/Iznik.git"
  git push "$REMOTE_URL" "HEAD:${BRANCH}"
else
  git push origin "HEAD:${BRANCH}"
fi

echo "Pushed regenerated screenshots to $BRANCH (with [skip ci])."
