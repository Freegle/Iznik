#!/usr/bin/env bash
# The emulator gate, end to end: build the app against the local stack, boot the
# emulator, install and launch, run the Playwright `app` project against the WebView,
# collect evidence, stop what this run started. Every missing prerequisite is a FAILURE,
# never a skip: a gate that quietly passes on a host that cannot run it is worse than no
# gate.
#
#   scripts/app-e2e/run.sh                 everything (SDK and AVD must already exist)
#   scripts/app-e2e/run.sh --provision     install the SDK and create the AVD first (CI)
#   scripts/app-e2e/run.sh --no-build      reuse $OUT_DIR/freegle-e2e.apk
#   scripts/app-e2e/run.sh --keep          leave the emulator running afterwards
#   scripts/app-e2e/run.sh -- <playwright args>   e.g. -- tests/e2e/app/plugins.spec.js
#
# Evidence lands in $OUT_DIR/run-<timestamp>/: Playwright's report, junit, screenshots
# and traces, the emulator log, and logcat for the run.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

provision=0 build=1 keep=0
pw_args=()
while [ $# -gt 0 ]; do
	case "$1" in
		--provision) provision=1 ;;
		--no-build) build=0 ;;
		--keep) keep=1 ;;
		--) shift; pw_args=("$@"); break ;;
		*) die "unknown argument $1" ;;
	esac
	shift
done

if [ "$provision" = 1 ]; then
	bash "$APP_E2E_DIR/setup-sdk.sh"
	bash "$APP_E2E_DIR/create-avd.sh"
fi
require_sdk
[ -f "$ANDROID_AVD_HOME/$AVD_NAME.ini" ] || die "AVD $AVD_NAME not found (scripts/app-e2e/create-avd.sh, or run.sh --provision)"
curl -sf --max-time 10 "$STACK_API_V2/online" >/dev/null || die "apiv2 not reachable at $STACK_API_V2: the local stack must be up"
curl -sf --max-time 10 "$STATUS_API_URL/api/status/all" >/dev/null || die "status API not reachable at $STATUS_API_URL"

mkdir -p "$STATE_DIR" "$OUT_DIR"
# The adb server before the lock: a server that a later adb call started would inherit
# the lock descriptor and go on holding the lock after this run has ended.
"$ADB" start-server >/dev/null 2>&1 || true
# One emulator per host: two runs never drive it at once (bounded wait).
lock_file="$STATE_DIR/emulator.lock"
exec 9>"$lock_file"
flock -w "${APP_E2E_LOCK_WAIT:-2700}" 9 || die "another run held the emulator lock ($lock_file)"

run_dir="$OUT_DIR/run-$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$run_dir"
ln -sfn "$run_dir" "$OUT_DIR/latest"
log "run directory: $run_dir"

started=0
logcat_pid=""
ticker_pid=""
cleanup() {
	[ -n "$ticker_pid" ] && kill -- "-$ticker_pid" 2>/dev/null || true
	[ -n "$logcat_pid" ] && kill "$logcat_pid" 2>/dev/null || true
	if [ "$started" = 1 ] && [ "$keep" = 0 ]; then bash "$APP_E2E_DIR/stop-emulator.sh" || true; fi
	[ -f "$OUT_DIR/$EMU_SERIAL.log" ] && cp "$OUT_DIR/$EMU_SERIAL.log" "$run_dir/emulator.log" 2>/dev/null || true
	return 0
}
trap cleanup EXIT
trap 'exit 143' TERM INT

if [ "$build" = 1 ]; then
	bash "$APP_E2E_DIR/build-apk.sh" 9>&-
fi
[ -f "$OUT_DIR/freegle-e2e.apk" ] || die "no APK at $OUT_DIR/freegle-e2e.apk"

if ! emulator_booted; then
	# Without the lock descriptor: the emulator outlives this run under --keep, and a
	# process that inherited it would hold the lock until the emulator stops.
	bash "$APP_E2E_DIR/start-emulator.sh" 9>&-
	started=1
fi

adb_emu logcat -c >/dev/null 2>&1 || true
# adb itself in the background (not a subshell, so $! is the process cleanup kills), with
# the lock descriptor closed so a logcat outliving this run cannot hold the lock.
"$ADB" -s "$DEVICE_SERIAL" logcat -v threadtime >"$run_dir/logcat.txt" 2>&1 9>&- &
logcat_pid=$!

launch_out="$(bash "$APP_E2E_DIR/launch-app.sh")" || die "launch failed"
while IFS='=' read -r key value; do
	[[ "$key" =~ ^APP_E2E_[A-Z_]+$ ]] || continue
	export "$key=$value"
done <<<"$launch_out"

cd "$NUXT_DIR"
export APP_E2E_RUN_DIR="$run_dir"
export STATUS_API_URL
export TEST_API_V2_BASE_URL="$STACK_API_V2"
# Flows with a second member drive the web front end from a headless Chromium
# (fixtures.js, app mode); the browser is fetched once and kept in Playwright's cache.
export TEST_BASE_URL="${TEST_BASE_URL:-http://freegle-prod-local.localhost${STACK_PORT_SUFFIX}}"
if [ "${CI:-}" = "true" ]; then
	# A fresh CI machine also lacks the libraries Chromium links against.
	npx playwright install --with-deps chromium >/dev/null 2>&1 || log "could not install Chromium for the second-member flows"
else
	npx playwright install chromium >/dev/null 2>&1 || log "could not install Chromium for the second-member flows"
fi
export PLAYWRIGHT_JUNIT_OUTPUT_FILE="$run_dir/junit.xml"
export PLAYWRIGHT_HTML_REPORT="$run_dir/playwright-report"
export PW_WORKERS=1
node "$APP_E2E_DIR/attach-check.cjs" "$NUXT_DIR" 9>&- ||
	die "Playwright cannot attach to the app's WebView (above); not running the tests"

# A reply only shows in /chats once chats:process-incoming has marked it processed. The
# batch scheduler that would run it is off in CI, so the status API drives it every few
# seconds for the web suite (status-nuxt/server/api/tests/playwright.post.ts); this run
# does not go through the status API, so it drives the same commands itself. The stale
# command lock is cleared each tick for the same reason as there.
docker exec "$BATCH_CONTAINER" true 2>/dev/null ||
	die "batch container $BATCH_CONTAINER not running: replies would never be processed"
log "processing replies through $BATCH_CONTAINER (batch-ticker.log)"
# Without errexit: one failed tick (a slow daemon, a restarting container) must not end
# the loop and leave the reply specs hanging. setsid gives the loop its own process
# group, so cleanup stops a tick in flight along with it.
setsid bash -c '
	while true; do
		docker exec "$1" sh -c "rm -f storage/framework/command-locks/App-Console-Commands-Chat-ProcessIncomingChatCommand.lock; php artisan chats:process-incoming; php artisan lockdown:tick" ||
			echo "[ticker] tick failed with exit $? at $(date -u +%H:%M:%S)"
		sleep 5
	done' ticker "$BATCH_CONTAINER" >>"$run_dir/batch-ticker.log" 2>&1 9>&- &
ticker_pid=$!

log "running Playwright project 'app' against $APP_E2E_ORIGIN on $APP_E2E_SERIAL"
# The line reporter prints each failure as it happens, so a run cut short (the CI job's
# hour) still says what failed; the global timeout ends the run, with its reports
# written, before that. The junit and HTML reporters take their paths from the
# PLAYWRIGHT_* variables above.
set +e
npx playwright test --project app --workers 1 --output "$run_dir/test-results" \
	--reporter=line,junit,html --global-timeout "$((${APP_E2E_GLOBAL_TIMEOUT_MIN:-25} * 60000))" \
	"${pw_args[@]}" 9>&-
rc=$?
set -e
adb_emu exec-out screencap -p >"$run_dir/final-screen.png" 2>/dev/null || true
# The junit and HTML reporters write where playwright.config.js says (test-results/ and
# playwright-report/ under iznik-nuxt3), so bring them into the run directory.
[ -f "$run_dir/junit.xml" ] || cp "$NUXT_DIR/test-results/junit.xml" "$run_dir/junit.xml" 2>/dev/null || true
[ -d "$run_dir/playwright-report" ] || cp -r "$NUXT_DIR/playwright-report" "$run_dir/playwright-report" 2>/dev/null || true
log "Playwright exit code $rc; evidence in $run_dir"
echo "ARTEFACTS $run_dir"
exit $rc
