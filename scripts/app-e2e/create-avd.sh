#!/usr/bin/env bash
# Creates the gate's AVD from the installed system image. Idempotent: an existing AVD is
# left alone unless --force is given (which wipes it).
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
require_sdk

force=0
[ "${1:-}" = "--force" ] && force=1

mkdir -p "$ANDROID_AVD_HOME"
if [ -f "$ANDROID_AVD_HOME/$AVD_NAME.ini" ] && [ "$force" = 0 ]; then
	log "AVD $AVD_NAME already exists in $ANDROID_AVD_HOME (use --force to recreate)"
	exit 0
fi

img="$(system_image_pkg)"
[ -d "$SDK_ROOT/system-images/android-${API_LEVEL}/${IMAGE_FLAVOUR}/${ABI}" ] ||
	die "system image $img not installed. Run: scripts/app-e2e/setup-sdk.sh"

log "creating AVD $AVD_NAME from $img"
echo no | "$AVDMANAGER" create avd --force -n "$AVD_NAME" -k "$img" -d pixel_6 >/dev/null

cfg="$ANDROID_AVD_HOME/$AVD_NAME.avd/config.ini"
[ -f "$cfg" ] || die "avdmanager did not write $cfg"
set_cfg() {
	local key="$1" value="$2"
	if grep -q "^${key}[ ]*=" "$cfg"; then
		sed -i.bak "s|^${key}[ ]*=.*|${key}=${value}|" "$cfg" && rm -f "$cfg.bak"
	else
		echo "${key}=${value}" >>"$cfg"
	fi
}
# The Nuxt bundle in a WebView wants more than the profile's default RAM; the data
# partition holds the 90MB app plus its caches.
set_cfg hw.ramSize 3072
set_cfg disk.dataPartition.size 6G
set_cfg hw.keyboard yes
set_cfg hw.gpu.enabled yes
# An emulated back camera (a moving test pattern) gives the camera test a picture to
# take without any host camera; virtualscene would need a real GPU.
set_cfg hw.camera.back emulated
set_cfg hw.camera.front none
log "AVD $AVD_NAME ready ($cfg)"
