#!/bin/bash
# Unlocks a Royal Mail "your raw address data" release (SetupRM.exe) and extracts the
# CSV PAF product, by driving the Windows extractor's GUI under Wine on a virtual display.
# AFD only ship the data inside this GUI program, so there is no command-line route.
#
# Usage: extract.sh <SetupRM.exe> <output dir> <key>
#
# Reports on stdout, one event per line, for server.mjs to parse:
#   STATE <name>        a step has started
#   PROGRESS <0..1>     the extractor's own progress bar
#   DONE <csv path>     finished; the CSV is complete
#   ERROR <code> <text> failed (codes: invalid_key, no_window, not_started, dialog, timeout)
#
# The screen positions below are offsets inside the extractor's 400x430 window, measured
# on the AUG 2026 edition. If AFD redesign the program, these are what change.

set -u

EXE="$1"
OUT="$2"
KEY="$3"

KEY_FIELD="195 186"
BEGIN_BUTTON="333 240"
NEXT_BUTTON="340 290"
FOLDER_FIELD="195 186"
# "Include BFPO Records", ticked by default. Forces addresses have no postcode we hold, so
# they would sit unapplied for ever.
BFPO_CHECKBOX="25 213"
EXTRACT_BUTTON="333 240"
# This strip runs along the inside of the progress bar.
BAR_W=348
BAR_H=8
BAR_X=22
BAR_Y=184

TITLE="your raw address data"
CSV="$OUT/CSV PAF/CSV PAF.csv"
# Extraction of the 2.8 GB CSV takes about six minutes; allow for a slow disk.
EXTRACT_TIMEOUT=${PAF_EXTRACT_TIMEOUT:-5400}

DISPLAY_NUM=:$((90 + RANDOM % 9))
export DISPLAY=$DISPLAY_NUM

cleanup() {
  wineserver -k >/dev/null 2>&1
  [ -n "${XVFB_PID:-}" ] && kill "$XVFB_PID" >/dev/null 2>&1
}
trap cleanup EXIT

fail() {
  # A screenshot of whatever was on screen is the quickest way to see what went wrong.
  import -window root "$OUT/failure.png" >/dev/null 2>&1
  echo "ERROR $1 $2"
  exit 1
}

mkdir -p "$OUT"
rm -rf "$OUT/CSV PAF"

echo "STATE starting"
Xvfb "$DISPLAY_NUM" -screen 0 1280x1024x24 >/dev/null 2>&1 &
XVFB_PID=$!
sleep 2

wine "$EXE" >/dev/null 2>&1 &

# The main window, found by title. Wine creates a hidden twin with the same title, so
# take the one that is the extractor's size.
WIN=""
for _ in $(seq 1 90); do
  for w in $(xdotool search --onlyvisible --name "$TITLE" 2>/dev/null); do
    eval "$(xdotool getwindowgeometry --shell "$w")"
    if [ "$WIDTH" -ge 300 ]; then
      WIN=$w
      WX=$X
      WY=$Y
      break 2
    fi
  done
  sleep 1
done
[ -n "$WIN" ] || fail no_window "The extractor window did not appear"

click() {
  xdotool mousemove $((WX + $1)) $((WY + $2)) click 1
  sleep 0.5
}

has_window() {
  xdotool search --onlyvisible --name "$1" >/dev/null 2>&1
}

# Any window that is not the extractor itself is an error dialog.
other_dialog() {
  for w in $(xdotool search --onlyvisible --name . 2>/dev/null); do
    name=$(xdotool getwindowname "$w" 2>/dev/null)
    case "$name" in
      "$TITLE" | "Default IME" | "") ;;
      *) echo "$name"; return 0 ;;
    esac
  done
  return 1
}

# The share of the bar filled in. Its blocks are blue (navy or light blue, depending on
# the Wine theme); the empty part, and the finished screen's text, are not.
bar_fraction() {
  import -window root -crop "${BAR_W}x${BAR_H}+$((WX + BAR_X))+$((WY + BAR_Y))" png:- 2>/dev/null \
    | convert png:- -fx 'b - r > 0.2 ? 1 : 0' -format '%[fx:mean]' info: 2>/dev/null || echo 0
}

echo "STATE key"
click $KEY_FIELD
# The field inserts its own separators, so type only the letters and digits.
xdotool type --delay 60 "$KEY"
click $BEGIN_BUTTON
sleep 5
has_window "Invalid Key" && fail invalid_key "The key was not accepted for this release"
if dialog=$(other_dialog); then
  fail dialog "$dialog"
fi

echo "STATE options"
click $NEXT_BUTTON
sleep 3

echo "STATE folder"
WINPATH="Z:$(echo "$OUT" | tr '/' '\\')"
click $FOLDER_FIELD
xdotool key End
for _ in $(seq 1 120); do xdotool key BackSpace; done
xdotool type --delay 30 "$WINPATH"
click $BFPO_CHECKBOX
click $EXTRACT_BUTTON

echo "STATE extracting"
started=0
quiet=0
last_size=-1
start=$(date +%s)
while true; do
  sleep 10
  now=$(date +%s)
  [ $((now - start)) -gt "$EXTRACT_TIMEOUT" ] && fail timeout "Extraction took longer than ${EXTRACT_TIMEOUT}s"
  if dialog=$(other_dialog); then
    fail dialog "$dialog"
  fi

  frac=$(bar_fraction)
  size=$(stat -c %s "$CSV" 2>/dev/null || echo -1)

  if [ "$started" = 0 ]; then
    if [ "$size" -ge 0 ] || awk "BEGIN{exit !($frac > 0)}"; then
      started=1
    elif [ $((now - start)) -gt 120 ]; then
      fail not_started "Extraction did not start"
    fi
    continue
  fi

  if awk "BEGIN{exit !($frac > 0)}"; then
    echo "PROGRESS $frac"
    quiet=0
  elif [ "$size" -gt 0 ] && [ "$size" = "$last_size" ]; then
    # The bar has gone (the window now says it has finished) and the file has stopped
    # growing. Wait for three quiet checks in a row before believing it.
    quiet=$((quiet + 1))
    [ "$quiet" -ge 3 ] && break
  fi
  last_size=$size
done

[ -s "$CSV" ] || fail not_started "No CSV PAF file was produced"
echo "DONE $CSV"
