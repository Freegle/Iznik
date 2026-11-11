#!/bin/bash
# Runs the CI runner's self-destruct script (from cloud-init.yaml) against stubs, to check the
# cases that once left a dead runner holding the only runner slot for 12 hours.
#   bash scripts/hetzner/test-reaper.sh
set -u
here="$(cd "$(dirname "$0")" && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# The reaper, as cloud-init writes it: the block under /opt/hetzner-reaper.sh, de-indented.
awk '/path: \/opt\/hetzner-reaper.sh/{f=1;next} f&&/^  - path:/{f=0} f&&/^      /{sub(/^      /,"");print}' \
  "$here/cloud-init.yaml" | sed 's/\${HETZNER_SELF_TOKEN}/test-token/' > "$work/reaper.sh"

mkdir -p "$work/bin"
# curl: the metadata lookup answers an id; a DELETE answers the next code from $work/codes.
cat > "$work/bin/curl" <<'STUB'
#!/bin/bash
for a in "$@"; do [ "$a" = DELETE ] && del=1; done
if [ -n "${del:-}" ]; then
  echo DELETE >> "$WORK/deletes"
  code="$(head -1 "$WORK/codes")"; sed -i 1d "$WORK/codes"; printf '%s' "${code:-200}"
else
  echo 12345
fi
STUB
printf '#!/bin/bash\n[ -f "$WORK/agent" ] && [ "$1" = -x ] && exit 0\nexit 1\n' > "$work/bin/pgrep"
printf '#!/bin/bash\nexit 0\n' > "$work/bin/docker"
chmod +x "$work/bin/"*

fail=0
run() { # name uptime ready(0/1) agent(0/1) codes expect_deletes
  : > "$work/deletes"; printf '%s\n' $5 > "$work/codes"; rm -f "$work/ready" "$work/agent"
  echo "$2.0 0" > "$work/uptime"
  [ "$3" = 1 ] && touch "$work/ready"; [ "$4" = 1 ] && touch "$work/agent"
  WORK="$work" PATH="$work/bin:$PATH" REAPER_UPTIME_FILE="$work/uptime" REAPER_READY_FILE="$work/ready" \
    REAPER_SLEEP=0.1 timeout 3 bash "$work/reaper.sh" > "$work/out" 2>&1
  got="$(wc -l < "$work/deletes")"
  if [ "$got" = "$6" ]; then echo "ok   $1"; else echo "FAIL $1: $got deletes, expected $6"; cat "$work/out"; fail=1; fi
}

run "a boot that never finished is deleted"               1300 0 0 "200"     1
run "a refused delete is tried again until it is accepted" 1300 0 0 "503 200" 2
run "a healthy runner in its first hour is left alone"      600 1 1 ""        0
run "a runner still booting is given time"                  600 0 0 ""        0

exit $fail
