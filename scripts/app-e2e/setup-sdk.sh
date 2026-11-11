#!/usr/bin/env bash
# Installs the Android SDK pieces the emulator gate needs into a per-user directory:
# command-line tools (checksum verified), platform-tools, the emulator, the platform and
# the google_apis system image. Gradle fetches the compile platform and build-tools itself
# once the licences are accepted here. Idempotent: re-running installs only what is
# missing. Needs JDK 17+, curl and unzip.
#
# Running this accepts the Android SDK licences on your behalf (sdkmanager --licenses);
# read them at https://developer.android.com/studio/terms
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[ "$(uname -s)/$(uname -m)" = "Linux/x86_64" ] || die "the gate runs on Linux x86_64 only (found $(uname -s)/$(uname -m))"
command -v curl >/dev/null || die "curl is required"
command -v unzip >/dev/null || die "unzip is required (apt install unzip)"
jv="$(java_major)"
[ -n "$jv" ] && [ "$jv" -ge 17 ] ||
	die "sdkmanager needs JDK 17 or newer, found '${jv:-none}'. Fix: sudo apt-get install -y openjdk-21-jdk-headless"

mkdir -p "$SDK_ROOT"

if [ ! -x "$SDKMANAGER" ]; then
	zip="commandlinetools-linux-${CMDLINE_TOOLS_BUILD}_latest.zip"
	tmp="$(mktemp -d)"
	trap 'rm -rf "$tmp"' EXIT
	log "downloading $zip"
	curl -fL --retry 3 -o "$tmp/$zip" "https://dl.google.com/android/repository/$zip"
	got="$(sha256_of "$tmp/$zip")"
	[ "$got" = "$CMDLINE_TOOLS_SHA256_linux" ] ||
		die "checksum mismatch for $zip: expected $CMDLINE_TOOLS_SHA256_linux, got $got. Not installing."
	unzip -q "$tmp/$zip" -d "$tmp/x"
	mkdir -p "$SDK_ROOT/cmdline-tools"
	rm -rf "$SDK_ROOT/cmdline-tools/latest"
	mv "$tmp/x/cmdline-tools" "$SDK_ROOT/cmdline-tools/latest"
else
	log "command-line tools already present"
fi

log "accepting SDK licences"
yes 2>/dev/null | "$SDKMANAGER" --sdk_root="$SDK_ROOT" --licenses >/dev/null || true

img="$(system_image_pkg)"
log "installing platform-tools, emulator, platforms;android-${API_LEVEL}, $img (several GB on first run)"
"$SDKMANAGER" --sdk_root="$SDK_ROOT" --install \
	"platform-tools" "emulator" "platforms;android-${API_LEVEL}" "$img" |
	grep -v '^\[=*' || true

for f in "$ADB" "$EMULATOR" "$SDK_ROOT/system-images/android-${API_LEVEL}/${IMAGE_FLAVOUR}/${ABI}/system.img"; do
	[ -e "$f" ] || die "expected $f after install, it is missing"
done
log "SDK ready at $SDK_ROOT"
"$ADB" version | head -1 >&2
"$EMULATOR" -version 2>/dev/null | head -1 >&2
