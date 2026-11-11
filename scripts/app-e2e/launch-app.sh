#!/usr/bin/env bash
# Installs the built APK on the booted emulator and launches it with the local stack
# reachable: a reverse tunnel from the device's traefik port to the host's, and runtime
# permissions granted so no system dialog sits over the WebView.
#
# Prints KEY=VALUE lines for the Playwright run (eval the output):
#   APP_E2E_SERIAL, APP_E2E_PACKAGE, APP_E2E_ORIGIN, APP_E2E_ADB, APP_E2E_OUT
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
require_sdk

apk="${1:-$OUT_DIR/freegle-e2e.apk}"
[ -f "$apk" ] || die "APK not found at $apk. Run: scripts/app-e2e/build-apk.sh"
[ -f "$OUT_DIR/app-origin.txt" ] || die "$OUT_DIR/app-origin.txt missing (build-apk.sh writes it)"
emulator_booted || die "$DEVICE_SERIAL is not booted. Run: scripts/app-e2e/start-emulator.sh"
[ "$(adb_emu shell id -u 2>/dev/null | tr -d '\r')" = "0" ] || die "adbd is not root on $DEVICE_SERIAL (start-emulator.sh does this)"

# Device port N -> host port N, so the URLs baked into the bundle (http://apiv2.localhost:N)
# reach the host's traefik. Chromium resolves *.localhost to loopback without DNS, so the
# Host header still routes correctly inside traefik.
adb_emu reverse "tcp:$PORT_TRAEFIK_HTTP" "tcp:$PORT_TRAEFIK_HTTP" >/dev/null ||
	die "adb reverse tcp:$PORT_TRAEFIK_HTTP failed"
log "reverse tunnel: device :$PORT_TRAEFIK_HTTP -> host :$PORT_TRAEFIK_HTTP"

# A fresh install every launch: the data of an earlier run (session, Capacitor preferences,
# the push debug log) must not leak into this one.
adb_emu uninstall "$APP_PACKAGE" >/dev/null 2>&1 || true
log "installing $apk"
adb_emu install -r -g "$apk" >/dev/null || die "adb install failed"
# -g grants the runtime permissions the manifest declares; POST_NOTIFICATIONS (API 33+) is
# the one the push tests depend on, so insist on it.
adb_emu shell pm grant "$APP_PACKAGE" android.permission.POST_NOTIFICATIONS >/dev/null 2>&1 || true

adb_emu shell am start -W -n "$APP_PACKAGE/$APP_ACTIVITY" >/dev/null || die "am start failed"
for _ in $(seq 1 60); do
	pid="$(adb_emu shell pidof "$APP_PACKAGE" 2>/dev/null | tr -d '\r')"
	if [ -n "$pid" ] && adb_emu shell cat /proc/net/unix 2>/dev/null | grep "webview_devtools_remote_${pid}" >/dev/null; then
		break
	fi
	sleep 1
done
[ -n "${pid:-}" ] || die "$APP_PACKAGE did not start"
adb_emu shell cat /proc/net/unix 2>/dev/null | grep "webview_devtools_remote_${pid}" >/dev/null ||
	die "no WebView devtools socket for pid $pid: the APK was not built with FREEGLE_APP_E2E=true"
log "$APP_PACKAGE running as pid $pid, WebView devtools socket present"

echo "APP_E2E_SERIAL=$DEVICE_SERIAL"
echo "APP_E2E_PACKAGE=$APP_PACKAGE"
echo "APP_E2E_ORIGIN=$(cat "$OUT_DIR/app-origin.txt")"
echo "APP_E2E_ADB=$ADB"
echo "APP_E2E_OUT=$OUT_DIR"
