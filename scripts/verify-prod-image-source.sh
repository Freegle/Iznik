#!/bin/bash
# Prove the production Nuxt containers hold the source of the commit under test.
#
# The Playwright suite runs from this checkout but drives the app served by
# freegle-prod-local and modtools-prod-local, and BuildKit can reuse a COPY layer
# from an earlier branch on a pooled runner VM. The images then hold other
# branches' source: the tests are right and the app they hit is not (jobs 41657
# and 41710). Nothing in the build reports it, so this compares every tracked
# file under iznik-nuxt3/ with the copy inside each container. On a difference it
# rebuilds that image with --no-cache and checks again; a second difference fails
# the job.
#
# Run from the repo root once the containers exist (they need not be healthy: the
# source is in the image from the start). Uses COMPOSE_PROJECT_NAME for names.

set -u

PROJECT="${COMPOSE_PROJECT_NAME:-freegle}"
MANIFEST=$(mktemp)
trap 'rm -f "$MANIFEST"' EXIT

# Files the image build does not copy or rewrites: iznik-nuxt3/.dockerignore, and
# the lockfiles `npm install` rewrites inside the modtools image.
EXCLUDE='(^|/)(\.git|node_modules|\.nuxt|\.output|dist|logs|coverage|monocart-report|playwright-report|test-results)(/|$)|(^|/)\.gitignore$|(^|/)\.env(\.[^/]*)?$|\.log$|(^|/)package-lock\.json$'

(
  cd iznik-nuxt3 || exit 1
  git ls-files -z . | grep -zv -E "$EXCLUDE" | xargs -0 -r sha1sum 2>/dev/null
) > "$MANIFEST"

TOTAL=$(wc -l < "$MANIFEST")
if [ "$TOTAL" -lt 100 ]; then
  echo "Only $TOTAL files in the source manifest: refusing to verify against an empty list."
  exit 1
fi
echo "Source manifest: $TOTAL files from commit $(git rev-parse --short HEAD)"

# Prints the files that differ, or nothing when the container matches.
differences() {
  docker exec -i "$1" sh -c 'cd /app && sha1sum -c' < "$MANIFEST" 2>&1 \
    | grep -v ': OK$' | grep -v '^sha1sum: WARNING' || true
}

verify() {
  local container=$1 service=$2 diffs count
  diffs=$(differences "$container")
  if [ -z "$diffs" ]; then
    echo "OK: $container holds the source of this commit"
    return 0
  fi
  count=$(echo "$diffs" | wc -l)
  echo "STALE: $container differs from this checkout in $count files, first 20:"
  echo "$diffs" | head -20

  echo "Rebuilding $service without cache..."
  docker-compose build --no-cache "$service" || return 1
  docker-compose up -d --force-recreate --no-deps "$service" || return 1

  diffs=$(differences "$container")
  if [ -z "$diffs" ]; then
    echo "OK: $container matches after a clean rebuild"
    return 0
  fi
  echo "STILL DIFFERENT after a clean rebuild, first 20:"
  echo "$diffs" | head -20
  return 1
}

FAILED=0
verify "${PROJECT}-prod-local" freegle-prod-local || FAILED=1
verify "${PROJECT}-modtools-prod-local" modtools-prod-local || FAILED=1
exit $FAILED
