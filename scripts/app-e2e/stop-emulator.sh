#!/usr/bin/env bash
# Stops the gate's emulator (only ours, by serial and pid file). Shuts Android down cleanly
# first: `adb emu kill` alone stops the VM without the guest flushing its page cache, and
# the app installed in the session can then be missing on the next boot.
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
require_sdk

pidfile="$OUT_DIR/$EMU_SERIAL.pid"
pid=""
[ -f "$pidfile" ] && pid="$(cat "$pidfile")"
gone() {
	if [ -n "$pid" ]; then
		! kill -0 "$pid" 2>/dev/null
	else
		! "$ADB" devices 2>/dev/null | grep "^${EMU_SERIAL}[[:space:]]" >/dev/null
	fi
}

if "$ADB" devices 2>/dev/null | grep "^${EMU_SERIAL}[[:space:]]device" >/dev/null; then
	"$ADB" -s "$EMU_SERIAL" shell sync >/dev/null 2>&1 || true
	"$ADB" -s "$EMU_SERIAL" shell reboot -p >/dev/null 2>&1 || true
	for _ in $(seq 1 60); do
		gone && break
		sleep 1
	done
fi
if ! gone; then
	"$ADB" -s "$EMU_SERIAL" emu kill >/dev/null 2>&1 || true
	for _ in $(seq 1 30); do
		gone && break
		sleep 1
	done
fi
if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
	log "emulator pid $pid did not exit, sending SIGTERM"
	kill "$pid" 2>/dev/null || true
fi
rm -f "$pidfile"
log "emulator stopped"
