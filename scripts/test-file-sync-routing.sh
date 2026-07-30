#!/bin/bash
# Checks which containers file-sync.sh routes each source path to.
#
# The trap this guards: unit test files are synced to the vitest runner
# container (modtools-dev-local), so every source tree those tests import has to
# be synced there too. When it is not, the runner executes today's tests against
# whatever was baked into the image, which fails locally while CI stays green
# because CI builds the image from a full checkout.
#
# Usage: bash scripts/test-file-sync-routing.sh

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
CN=freegle

# Pull get_container_info out of file-sync.sh rather than sourcing the whole
# script, which would start the watcher.
FUNC_FILE="$(mktemp)"
trap 'rm -f "$FUNC_FILE"' EXIT
awk '/^get_container_info\(\)/,/^}/' "$PROJECT_DIR/file-sync.sh" > "$FUNC_FILE"
# shellcheck disable=SC1090
source "$FUNC_FILE"

VITEST_RUNNER="${CN}-modtools-dev-local"
failures=0

assert_targets() {
    local path="$1" expected="$2" description="$3"
    local got
    got="$(get_container_info "$PROJECT_DIR/$path")"

    if printf '%s' "$got" | grep -q "$expected"; then
        echo "ok   - $description"
    else
        echo "FAIL - $description"
        echo "       $path did not route to $expected"
        echo "       got: ${got:-<nothing>}"
        failures=$((failures + 1))
    fi
}

echo "== Sources imported by unit tests must reach the vitest runner =="

# tests/unit/server/sitemap.spec.js imports ~/server/utils/sitemap
assert_targets "iznik-nuxt3/server/utils/sitemap.js" "$VITEST_RUNNER" \
    "server/ reaches the vitest runner"

# tests/unit/pages/message/id.spec.js and stats.spec.js mount page components
assert_targets "iznik-nuxt3/pages/message/[id].vue" "$VITEST_RUNNER" \
    "pages/ reaches the vitest runner"

# MessageSummaryRowSize.spec.js reads assets/css/_feed-card.scss off disk
assert_targets "iznik-nuxt3/assets/css/_feed-card.scss" "$VITEST_RUNNER" \
    "assets/ reaches the vitest runner"

assert_targets "iznik-nuxt3/components/MessageSummary.vue" "$VITEST_RUNNER" \
    "components/ reaches the vitest runner"

assert_targets "iznik-nuxt3/stores/message.js" "$VITEST_RUNNER" \
    "stores/ reaches the vitest runner"

assert_targets "iznik-nuxt3/composables/useMessage.js" "$VITEST_RUNNER" \
    "composables/ reaches the vitest runner"

assert_targets "iznik-nuxt3/tests/unit/pages/stats.spec.js" "$VITEST_RUNNER" \
    "unit test files reach the vitest runner"

echo
echo "== Other routing stays as it was =="

assert_targets "iznik-nuxt3/pages/message/[id].vue" "${CN}-dev-local" \
    "pages/ still reaches the Freegle dev container"

assert_targets "iznik-nuxt3/tests/e2e/foo.spec.js" "${CN}-playwright" \
    "e2e tests go to the Playwright container"

assert_targets "iznik-server-go/router/routes.go" "${CN}-apiv2" \
    "Go sources go to apiv2"

assert_targets "iznik-batch/app/Services/Foo.php" "${CN}-batch" \
    "Laravel sources go to batch"

echo
if [ "$failures" -eq 0 ]; then
    echo "All routing checks passed."
    exit 0
fi

echo "$failures routing check(s) failed."
exit 1
