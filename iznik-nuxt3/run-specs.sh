#!/bin/bash
#
# WHY THIS SCRIPT EXISTS INSTEAD OF USING PLAYWRIGHT WORKERS
# ===========================================================
# Playwright normally runs all spec files in parallel using its own worker pool
# (configured via `workers` in playwright.config.js). We are NOT doing that here.
# Instead, this script runs each spec file in its own separate `npx playwright test`
# process, N at a time (controlled by PARALLEL_SPECS, default 11 local / 4 CI).
#
# ROOT CAUSE (partially diagnosed):
# After ~128 tests in a single Playwright process, the Chromium renderer freezes:
# the main thread spins at 100% CPU inside v8::internal::Runtime_PromiseHookAfter —
# V8's internal callback that fires on every Promise resolution. GDB confirmed this
# is a pure userspace spin; strace shows only futex + getpid syscalls with no I/O.
#
# Source map analysis of the modtools bundle (D9YAJGtQ.js) showed the hot symbols
# are Ww (queueFlush) and I1 (queueJob) — Vue's own scheduler internals, not an
# application bug. Vue's scheduler resolves a Promise on every reactive update cycle
# via Promise.resolve().then(flushJobs). Over a 2-hour single-process test run these
# accumulate until the V8 PromiseHookAfter overhead saturates the renderer.
#
# FIX: each spec file gets its own fresh Playwright/Chromium process, so the
# accumulated V8 Promise hook state is reset between files. This trades Playwright's
# built-in worker pool for OS-level process parallelism — same throughput, no freeze.
#
# PARALLEL_SPECS env var controls concurrency (default: 11 local, 4 CI).
#
cd "$(dirname "$0")"

if [ -n "$PARALLEL_SPECS" ]; then
  PARALLEL=$PARALLEL_SPECS
elif [ -n "$CIRCLECI" ]; then
  PARALLEL=4
else
  PARALLEL=11
fi

RESULTS_DIR=$(mktemp -d)
trap "rm -rf '$RESULTS_DIR'" EXIT

SPECS=()
if [ -f "tests/e2e/ordered-tests.txt" ]; then
  while IFS= read -r line || [ -n "$line" ]; do
    [ -n "$line" ] && SPECS+=("$line")
  done < "tests/e2e/ordered-tests.txt"
else
  while IFS= read -r line; do
    SPECS+=("$line")
  done < <(find tests/e2e -name "*.spec.js" | sort)
fi

TOTAL=${#SPECS[@]}
echo "=== Parallel spec run: $TOTAL specs, $PARALLEL concurrent Playwright processes ==="
echo ""

declare -A pid_spec
declare -A pid_num
running_pids=()

collect_one() {
  while true; do
    for j in "${!running_pids[@]}"; do
      local pid="${running_pids[$j]}"
      if ! kill -0 "$pid" 2>/dev/null; then
        wait "$pid"
        local rc=$?
        local spec="${pid_spec[$pid]}"
        local num="${pid_num[$pid]}"
        if [ $rc -eq 0 ]; then
          echo "[PASS] [$num/$TOTAL] $spec"
          echo "PASS" > "$RESULTS_DIR/$num"
        else
          echo "[FAIL] [$num/$TOTAL] $spec"
          echo "FAIL:$spec" > "$RESULTS_DIR/$num"
          echo "=== Last 50 lines of output for failed spec ==="
          tail -50 "$RESULTS_DIR/log-$num"
          echo "==="
        fi
        unset "pid_spec[$pid]"
        unset "pid_num[$pid]"
        unset "running_pids[$j]"
        running_pids=("${running_pids[@]}")
        return
      fi
    done
    sleep 0.2
  done
}

for i in "${!SPECS[@]}"; do
  spec="${SPECS[$i]}"
  num=$((i + 1))

  while [ "${#running_pids[@]}" -ge "$PARALLEL" ]; do
    collect_one
  done

  echo "[START] [$num/$TOTAL] $spec"
  # --workers=1: each process handles one spec file serially; extra workers would
  # just launch idle browsers (11 processes × 11 workers = 121 browsers vs 11).
  # --output=test-results-$num: each process gets a unique artifact directory so
  # .playwright-artifacts-0/ dirs don't collide between parallel processes (ENOENT
  # on trace files when one process cleans up another's artifacts).
  # CI=true: suppresses HTML reporter auto-open (avoid 11 browsers trying port 9323).
  CI=true npx playwright test "$spec" --workers=1 --output="test-results-$num" > "$RESULTS_DIR/log-$num" 2>&1 &
  pid=$!
  running_pids+=("$pid")
  pid_spec[$pid]="$spec"
  pid_num[$pid]=$num
done

while [ "${#running_pids[@]}" -gt 0 ]; do
  collect_one
done

PASS=0
FAIL=0
FAILED=()
for f in "$RESULTS_DIR"/[0-9]*; do
  [ -f "$f" ] || continue
  content=$(cat "$f")
  if [ "$content" = "PASS" ]; then
    PASS=$((PASS + 1))
  else
    FAIL=$((FAIL + 1))
    FAILED+=("${content#FAIL:}")
  fi
done

echo ""
echo "=== Complete: $PASS/$TOTAL passed, $FAIL failed ==="
if [ "${#FAILED[@]}" -gt 0 ]; then
  echo "Failed specs:"
  printf '  %s\n' "${FAILED[@]}"
  exit 1
fi
exit 0
