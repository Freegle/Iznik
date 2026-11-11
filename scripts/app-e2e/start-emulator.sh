#!/usr/bin/env bash
# Boots the gate's AVD headless and waits until Android reports boot complete, then
# restarts adbd as root (needed for the port-80 reverse tunnel and the fake FCM broadcast).
#   start-emulator.sh            headless (CI default): no window, no audio
#   start-emulator.sh --window   with a window, for a human
# Environment: APP_E2E_GPU overrides the -gpu mode, APP_E2E_BOOT_TIMEOUT the boot wait in
# seconds (default 420), APP_E2E_EMU_EXTRA_ARGS appends emulator flags.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
require_sdk

window=0
[ "${1:-}" = "--window" ] && window=1

# Hardware acceleration: without it the emulator either refuses to start or runs so slowly
# the boot wait times out, so fail early with the fix.
[ -e /dev/kvm ] || {
	if is_wsl; then
		die "/dev/kvm missing. In WSL2 enable nested virtualisation: add 'nestedVirtualization=true' under [wsl2] in %UserProfile%\\.wslconfig, then 'wsl --shutdown' from Windows"
	fi
	die "/dev/kvm missing: enable VT-x/AMD-V in the BIOS and load kvm_intel or kvm_amd (on a cloud VM, nested virtualisation must be on)"
}
[ -r /dev/kvm ] && [ -w /dev/kvm ] ||
	die "no read/write access to /dev/kvm. Fix: sudo usermod -aG kvm $USER, then log out and in (or: sudo chmod 666 /dev/kvm on a throwaway CI host)"

[ -f "$ANDROID_AVD_HOME/$AVD_NAME.ini" ] || die "AVD $AVD_NAME not found. Run: scripts/app-e2e/create-avd.sh"

if "$ADB" devices 2>/dev/null | grep "^${EMU_SERIAL}[[:space:]]" >/dev/null; then
	if emulator_booted; then
		log "$EMU_SERIAL already running and booted"
		exit 0
	fi
fi

mkdir -p "$OUT_DIR"
# Always a cold boot (-no-snapshot: no load, no save), so a run never inherits a snapshot's
# stale data disk and the data disk is exactly as the last clean shutdown left it.
args=(-avd "$AVD_NAME" -port "$EMU_PORT" -no-boot-anim -no-audio -no-snapshot)
if [ "$window" = 1 ]; then
	gpu="${APP_E2E_GPU:-auto}"
	is_wsl && gpu="${APP_E2E_GPU:-swiftshader_indirect}"
else
	# Software rendering: works without a display or GPU driver on every host.
	gpu="${APP_E2E_GPU:-swiftshader_indirect}"
	args+=(-no-window)
fi
args+=(-gpu "$gpu")
# shellcheck disable=SC2206
[ -n "${APP_E2E_EMU_EXTRA_ARGS:-}" ] && args+=(${APP_E2E_EMU_EXTRA_ARGS})

log "starting emulator: ${args[*]}"
start=$(date +%s)
nohup "$EMULATOR" "${args[@]}" >"$OUT_DIR/$EMU_SERIAL.log" 2>&1 &
echo $! >"$OUT_DIR/$EMU_SERIAL.pid"

timeout="${APP_E2E_BOOT_TIMEOUT:-420}"
"$ADB" start-server >/dev/null 2>&1 || true
while :; do
	now=$(date +%s)
	if ! kill -0 "$(cat "$OUT_DIR/$EMU_SERIAL.pid")" 2>/dev/null; then
		tail -20 "$OUT_DIR/$EMU_SERIAL.log" >&2
		die "emulator process exited during boot (log: $OUT_DIR/$EMU_SERIAL.log)"
	fi
	if emulator_booted; then break; fi
	if [ $((now - start)) -ge "$timeout" ]; then
		tail -20 "$OUT_DIR/$EMU_SERIAL.log" >&2
		die "emulator did not finish booting within ${timeout}s (log: $OUT_DIR/$EMU_SERIAL.log)"
	fi
	sleep 2
done
elapsed=$(($(date +%s) - start))

# Deterministic UI for uiautomator: no animations, screen stays on, keyguard dismissed.
for k in window_animation_scale transition_animation_scale animator_duration_scale; do
	adb_emu shell settings put global "$k" 0 >/dev/null 2>&1 || true
done
adb_emu shell svc power stayon true >/dev/null 2>&1 || true
adb_emu shell input keyevent KEYCODE_WAKEUP >/dev/null 2>&1 || true
adb_emu shell wm dismiss-keyguard >/dev/null 2>&1 || true

# adbd as root: a Play Store image refuses this, which is why the gate uses google_apis.
# On a loaded host adb root can fail just after boot on the right image too, so retry, and
# only blame the image when adbd says it is a production build.
root_out=""
for _ in $(seq 1 10); do
	if root_out=$(adb_emu root 2>&1); then break; fi
	case "$root_out" in *production*) break ;; esac
	sleep 3
done
case "$root_out" in
*production*) die "adb root refused on $EMU_SERIAL (is the AVD a google_apis image, not google_apis_playstore?): $root_out" ;;
esac
adb_emu wait-for-device
for _ in $(seq 1 30); do
	[ "$(adb_emu shell id -u 2>/dev/null | tr -d '\r')" = "0" ] && break
	sleep 1
done
[ "$(adb_emu shell id -u 2>/dev/null | tr -d '\r')" = "0" ] || die "adbd did not come back as root (adb root said: ${root_out:-nothing})"

log "$EMU_SERIAL booted in ${elapsed}s (API $(adb_emu shell getprop ro.build.version.sdk | tr -d '\r'), $(adb_emu shell getprop ro.build.type | tr -d '\r'), adbd root)"
