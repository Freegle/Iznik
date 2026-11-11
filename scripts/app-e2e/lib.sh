# shellcheck shell=bash disable=SC2034
# Shared settings for the app emulator scripts. Sourced, never executed.
#
# Everything is overridable from the environment so one machine can keep an existing
# Android Studio SDK and another can use the per-user default.

set -euo pipefail

APP_E2E_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$APP_E2E_DIR/../.." && pwd)"
NUXT_DIR="$REPO_ROOT/iznik-nuxt3"

# Pinned Android command-line tools build and the sha256 published on
# https://developer.android.com/studio (checked 2026-10-05). Bump both together.
CMDLINE_TOOLS_BUILD="15859902"
CMDLINE_TOOLS_SHA256_linux="4e4c464f145a7512b57d088ac6c278c03c9eea610886b35a5e0804e74eedf583"

# SDK location: respect an existing ANDROID_SDK_ROOT / ANDROID_HOME, otherwise a per-user
# directory that never needs root.
SDK_ROOT="${ANDROID_SDK_ROOT:-${ANDROID_HOME:-$HOME/.local/share/freegle-android-sdk}}"
export ANDROID_SDK_ROOT="$SDK_ROOT"
export ANDROID_HOME="$SDK_ROOT"

# Per-machine state that is not the SDK: the AVD, built APKs, run output, the lock.
STATE_DIR="${APP_E2E_STATE:-$HOME/.local/share/freegle-app-e2e}"
export ANDROID_AVD_HOME="${ANDROID_AVD_HOME:-$STATE_DIR/avd}"
OUT_DIR="${APP_E2E_OUT:-$STATE_DIR/out}"

# google_apis, NOT google_apis_playstore: the gate needs `adb root` (a Play image refuses it)
# to bind device port 80 for the reverse tunnel and to inject a fake FCM message.
API_LEVEL="${APP_E2E_API:-34}"
IMAGE_FLAVOUR="${APP_E2E_IMAGE_FLAVOUR:-google_apis}"
ABI="x86_64"
AVD_NAME="${APP_E2E_AVD:-freegle-app-e2e}"
# Inside the range the adb server keeps scanning (console 5554-5584, adb 5555-5585): when
# a loaded machine makes the server drop the emulator's connection, it finds it again in
# seconds. Outside the range (5590 was used at first) the emulator is lost for the rest of
# the run, which is what a 4-core CI machine did to every test after the first. 5580 also
# keeps clear of the default 5554 and of the Migrating Dragons emulator's 5584.
EMU_PORT="${APP_E2E_EMU_PORT:-5580}"
EMU_SERIAL="emulator-${EMU_PORT}"
DEVICE_SERIAL="${ANDROID_SERIAL:-$EMU_SERIAL}"
APP_PACKAGE="${APP_E2E_PACKAGE:-org.ilovefreegle.direct}"
APP_ACTIVITY="${APP_E2E_ACTIVITY:-.MainActivity}"

# The local stack the app talks to. The same values the playwright container gets from
# docker-compose.yml; PORT_TRAEFIK_HTTP comes from the checkout's .env when present.
if [ -f "$REPO_ROOT/.env" ]; then
	_port="$(sed -n 's/^PORT_TRAEFIK_HTTP=\([0-9]*\).*/\1/p' "$REPO_ROOT/.env" | head -1)"
	[ -n "$_port" ] && PORT_TRAEFIK_HTTP="${PORT_TRAEFIK_HTTP:-$_port}"
	_port="$(sed -n 's/^PORT_STATUS=\([0-9]*\).*/\1/p' "$REPO_ROOT/.env" | head -1)"
	[ -n "$_port" ] && PORT_STATUS="${PORT_STATUS:-$_port}"
fi
PORT_TRAEFIK_HTTP="${PORT_TRAEFIK_HTTP:-80}"
PORT_STATUS="${PORT_STATUS:-8081}"
if [ "$PORT_TRAEFIK_HTTP" = "80" ]; then
	STACK_PORT_SUFFIX=""
else
	STACK_PORT_SUFFIX=":$PORT_TRAEFIK_HTTP"
fi
# Overridable only under APP_E2E_* names. The release build's own names (IZNIK_API_V2,
# TUS_UPLOADER and the rest) are set in CI and in developers' shells: CircleCI sets
# TUS_UPLOADER to the public tus demo server, which an e2e build once picked up.
STACK_API_V2="${APP_E2E_API_V2:-http://apiv2.localhost${STACK_PORT_SUFFIX}/api}"
STACK_IMAGE_DELIVERY="${APP_E2E_IMAGE_DELIVERY:-http://delivery.localhost${STACK_PORT_SUFFIX}}"
STACK_TUS_UPLOADER="${APP_E2E_TUS_UPLOADER:-http://tusd.localhost${STACK_PORT_SUFFIX}/tus/}"
STACK_USER_SITE="${APP_E2E_USER_SITE:-http://freegle-prod-local.localhost${STACK_PORT_SUFFIX}}"
STATUS_API_URL="${STATUS_API_URL:-http://localhost:${PORT_STATUS}}"
# The checkout's .env wins over the shell: the profile exports COMPOSE_PROJECT_NAME for
# the main checkout, and a worktree run must reach its own batch container.
if [ -f "$REPO_ROOT/.env" ]; then
	_project="$(sed -n -E "s/^COMPOSE_PROJECT_NAME=[\"']?([A-Za-z0-9_-]+).*/\\1/p" "$REPO_ROOT/.env" | head -1)"
	[ -n "$_project" ] && COMPOSE_PROJECT_NAME="$_project"
fi
BATCH_CONTAINER="${COMPOSE_PROJECT_NAME:-freegle}-batch"

SDKMANAGER="$SDK_ROOT/cmdline-tools/latest/bin/sdkmanager"
AVDMANAGER="$SDK_ROOT/cmdline-tools/latest/bin/avdmanager"
ADB="$SDK_ROOT/platform-tools/adb"
EMULATOR="$SDK_ROOT/emulator/emulator"

system_image_pkg() { echo "system-images;android-${API_LEVEL};${IMAGE_FLAVOUR};${ABI}"; }

is_wsl() { grep -qi microsoft /proc/version 2>/dev/null; }

sha256_of() { sha256sum "$1" | cut -d' ' -f1; }

# The java major version, or nothing when java is absent.
java_major() {
	local v
	v="$(java -version 2>&1 | sed -n 's/.*version "\([0-9][0-9]*\)\(\.[0-9]*\)*.*".*/\1/p' | head -1)" || true
	[ "$v" = "1" ] && v=8
	echo "$v"
}

log() { printf '[app-e2e] %s\n' "$*" >&2; }
die() { printf '[app-e2e] ERROR: %s\n' "$*" >&2; exit 1; }

require_sdk() {
	[ -x "$SDKMANAGER" ] || die "Android SDK not found at $SDK_ROOT. Run: scripts/app-e2e/setup-sdk.sh"
	[ -x "$ADB" ] || die "platform-tools missing from $SDK_ROOT. Run: scripts/app-e2e/setup-sdk.sh"
}

# adb against our device only, never whatever else happens to be attached.
adb_emu() { "$ADB" -s "$DEVICE_SERIAL" "$@"; }

emulator_booted() {
	[ "$(adb_emu shell getprop sys.boot_completed 2>/dev/null | tr -d '\r')" = "1" ]
}
