#!/usr/bin/env bash
#
# check-canonical-corpus-sync.sh - do the three copies of the cross-language
# canonicaliser pinning corpus still agree?
#
# tools/orm-migration/canonical-corpus.json is the file to actually edit, but
# neither test runner can read it from there at test time: the Go apiv2
# container's build context is iznik-server-go alone (Dockerfile: `COPY . .`
# run from inside that directory), and the Laravel batch container only
# bind-mounts iznik-batch itself - confirmed with `docker inspect` while
# building this, not assumed. So a byte-identical copy lives in each
# service's own tree instead (iznik-server-go/ormharness/canonical-corpus.json,
# embedded into the Go test binary via go:embed; iznik-batch's own copy at
# tests/Support/OrmHarness/canonical-corpus.json, read directly since the
# whole of iznik-batch IS visible inside its container). Three files that are
# supposed to be identical and are not mechanically checked will drift the
# same way any manually-synchronised copy does, which is the exact failure
# mode a *shared* corpus exists to prevent in the first place - so this
# exists to make "someone edited one copy and forgot the others" a build
# failure rather than a silent divergence.
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

SOURCE_OF_TRUTH="$SCRIPT_DIR/canonical-corpus.json"
GO_COPY="$REPO_ROOT/iznik-server-go/ormharness/canonical-corpus.json"
PHP_COPY="$REPO_ROOT/iznik-batch/tests/Support/OrmHarness/canonical-corpus.json"

fail=0

for copy in "$GO_COPY" "$PHP_COPY"; do
  if [ ! -f "$copy" ]; then
    echo "check-canonical-corpus-sync: MISSING  $copy"
    fail=1
    continue
  fi
  if ! diff -q "$SOURCE_OF_TRUTH" "$copy" >/dev/null 2>&1; then
    echo "check-canonical-corpus-sync: DRIFTED  $copy does not match $SOURCE_OF_TRUTH"
    fail=1
  fi
done

if [ "$fail" -ne 0 ]; then
  echo "check-canonical-corpus-sync: FAIL"
  echo "fix: edit $SOURCE_OF_TRUTH, then run:"
  echo "  cp $SOURCE_OF_TRUTH $GO_COPY"
  echo "  cp $SOURCE_OF_TRUTH $PHP_COPY"
  exit 1
fi
echo "check-canonical-corpus-sync: OK (source of truth and both language copies agree)"
