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

# Wall-clock timeout per spec process. Playwright's per-test timeout (600s × 2 retries)
# can't fire when the Chromium renderer is frozen in V8 PromiseHookAfter — CDP messages
# never get responses, so the process hangs indefinitely. This outer timeout kills the
# entire spec process and triggers a spec-level retry with a fresh renderer.
# 900s (15m) in CI: full 38-spec run completes in < 10m normally, so any spec running
# > 15m is frozen. The per-test Playwright timeout is 600s; with retries:1 a frozen
# test would hang 20m total — this outer kill fires first at 15m and triggers a fresh retry.
if [ -n "$SPEC_TIMEOUT_SECS" ]; then
  SPEC_TIMEOUT=$SPEC_TIMEOUT_SECS
elif [ -n "$CIRCLECI" ]; then
  SPEC_TIMEOUT=900
else
  SPEC_TIMEOUT=1800
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

run_spec() {
  local spec="$1" num="$2" suffix="${3:-}"
  local outdir="test-results-${num}${suffix}"
  local logfile="$RESULTS_DIR/log-${num}${suffix}"
  CI=true timeout "$SPEC_TIMEOUT" npx playwright test "$spec" --workers=1 --output="$outdir" > "$logfile" 2>&1
}

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
          echo "[FAIL] [$num/$TOTAL] $spec (rc=$rc, retrying with fresh renderer)"
          echo "=== Last 50 lines of first attempt ==="
          tail -50 "$RESULTS_DIR/log-$num"
          echo "==="
          run_spec "$spec" "$num" "-retry"
          local retry_rc=$?
          if [ $retry_rc -eq 0 ]; then
            echo "[PASS] [$num/$TOTAL] $spec (retry succeeded)"
            echo "PASS" > "$RESULTS_DIR/$num"
          else
            echo "[FAIL] [$num/$TOTAL] $spec (failed after retry, rc=$retry_rc)"
            echo "FAIL:$spec" > "$RESULTS_DIR/$num"
            echo "=== Last 50 lines of retry ==="
            tail -50 "$RESULTS_DIR/log-${num}-retry"
            echo "==="
          fi
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
  run_spec "$spec" "$num" &
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
