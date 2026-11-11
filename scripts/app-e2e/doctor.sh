#!/usr/bin/env bash
# Prerequisite checklist for the emulator gate, with the exact fix for each gap. Never
# changes anything.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

ok() { printf '  ok    %s\n' "$*"; }
bad() { printf '  FAIL  %s\n' "$*"; failed=1; }
failed=0

echo "app-e2e doctor"
echo "  SDK root:  $SDK_ROOT"
echo "  state:     $STATE_DIR"
echo "  AVD:       $AVD_NAME ($(system_image_pkg))"
echo "  stack:     $STACK_API_V2 (status $STATUS_API_URL)"

[ -e /dev/kvm ] && ok "/dev/kvm present" || bad "/dev/kvm missing (nested virtualisation off; see start-emulator.sh)"
[ -r /dev/kvm ] && [ -w /dev/kvm ] && ok "/dev/kvm readable and writable" || bad "no rw access to /dev/kvm: sudo usermod -aG kvm $USER"
jv="$(java_major)"
[ -n "$jv" ] && [ "$jv" -ge 17 ] && ok "java $jv" || bad "JDK 17+ missing: sudo apt-get install -y openjdk-21-jdk-headless"
command -v node >/dev/null && ok "node $(node --version)" || bad "node 22+ missing"
[ -x "$SDKMANAGER" ] && ok "sdkmanager" || bad "SDK missing: scripts/app-e2e/setup-sdk.sh"
[ -x "$ADB" ] && ok "adb" || bad "platform-tools missing: scripts/app-e2e/setup-sdk.sh"
[ -x "$EMULATOR" ] && ok "emulator" || bad "emulator missing: scripts/app-e2e/setup-sdk.sh"
[ -e "$SDK_ROOT/system-images/android-${API_LEVEL}/${IMAGE_FLAVOUR}/${ABI}/system.img" ] &&
	ok "system image $(system_image_pkg)" || bad "system image missing: scripts/app-e2e/setup-sdk.sh"
[ -f "$ANDROID_AVD_HOME/$AVD_NAME.ini" ] && ok "AVD $AVD_NAME" || bad "AVD missing: scripts/app-e2e/create-avd.sh"
[ -f "$NUXT_DIR/node_modules/.package-lock.json" ] && ok "iznik-nuxt3 node_modules" || bad "iznik-nuxt3 node_modules missing: (cd iznik-nuxt3 && npm ci)"
[ -f "$OUT_DIR/freegle-e2e.apk" ] && ok "APK $OUT_DIR/freegle-e2e.apk" || bad "APK not built: scripts/app-e2e/build-apk.sh"
if curl -sf --max-time 5 "$STACK_API_V2/online" >/dev/null 2>&1; then ok "apiv2 answers at $STACK_API_V2"; else bad "apiv2 not reachable at $STACK_API_V2 (start the stack)"; fi
if curl -sf --max-time 5 "$STATUS_API_URL/api/status/all" >/dev/null 2>&1; then ok "status API at $STATUS_API_URL"; else bad "status API not reachable at $STATUS_API_URL"; fi
if [ -x "$ADB" ] && "$ADB" devices 2>/dev/null | grep "^${EMU_SERIAL}[[:space:]]device" >/dev/null; then
	emulator_booted && ok "$EMU_SERIAL booted" || bad "$EMU_SERIAL attached but not booted"
else
	echo "  info  $EMU_SERIAL not running (start-emulator.sh boots it; run.sh does so itself)"
fi

exit $failed
