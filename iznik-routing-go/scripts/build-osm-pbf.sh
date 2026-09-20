#!/bin/bash
#
# Build iznik-routing-go/data/uk-latest.osm.pbf, the OSM extract the routing
# graph is loaded from.
#
# The file is NOT a single Geofabrik download. Freegle covers the British Isles,
# which Geofabrik splits across four extracts, because the Crown Dependencies are
# constitutionally not part of the United Kingdom and so are not in
# great-britain:
#
#   great-britain                 England, Scotland, Wales
#   ireland-and-northern-ireland  Northern Ireland and the Republic
#   isle-of-man                   Isle of Man
#   guernsey-jersey               Jersey, Guernsey, Alderney, Sark, Herm
#
# Miss one and the graph has no roads there. Nothing fails: nearestNodeGrid
# searches about 11km, finds nothing across the sea, and Isochrone returns an
# empty result. Every reach, ripple and nearby-browse result for that island is
# then silently empty. That is how the Isle of Man went a year with no reach
# rows at all, for a live group of 825 members.
#
# So this script verifies, before installing anything, that each region really
# does have a road network in the merged file.
#
# Usage:  iznik-routing-go/scripts/build-osm-pbf.sh [--work-dir DIR] [--keep]
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TARGET="$REPO_ROOT/iznik-routing-go/data/uk-latest.osm.pbf"
WORK_DIR="${TMPDIR:-/tmp}/osm-pbf-build"
KEEP=0

while [ $# -gt 0 ]; do
  case "$1" in
    --work-dir) WORK_DIR="$2"; shift 2 ;;
    --keep)     KEEP=1; shift ;;
    -h|--help)  sed -n '2,32p' "$0"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

for tool in curl osmium; do
  command -v "$tool" >/dev/null || { echo "missing required tool: $tool" >&2; exit 1; }
done

# Geofabrik extracts that together cover the British Isles.
REGIONS=(
  "great-britain|https://download.geofabrik.de/europe/great-britain-latest.osm.pbf"
  "ireland-and-northern-ireland|https://download.geofabrik.de/europe/ireland-and-northern-ireland-latest.osm.pbf"
  "isle-of-man|https://download.geofabrik.de/europe/isle-of-man-latest.osm.pbf"
  "guernsey-jersey|https://download.geofabrik.de/europe/guernsey-jersey-latest.osm.pbf"
)

# Probes: name|minLng,minLat,maxLng,maxLat|minimum highway ways expected.
#
# One per source extract, each over a piece of land that only that extract
# supplies. Thresholds sit far above complete-way spillover (great-britain
# carries about 90 stray Isle of Man nodes and no roads) and far below a real
# road network, so the check cannot pass on spillover alone.
PROBES=(
  "anglesey-GB|-4.71,53.13,-4.02,53.43|2000"
  "cork-IE|-8.60,51.85,-8.35,51.95|2000"
  "isle-of-man|-4.85,54.03,-4.28,54.42|2000"
  "jersey-CI|-2.27,49.16,-2.00,49.27|1000"
  "guernsey-CI|-2.67,49.42,-2.50,49.51|1000"
)

mkdir -p "$WORK_DIR"
echo "Work directory: $WORK_DIR"

echo
echo "== Downloading Geofabrik extracts =="
INPUTS=()
for entry in "${REGIONS[@]}"; do
  name="${entry%%|*}"
  url="${entry#*|}"
  out="$WORK_DIR/$name.osm.pbf"
  echo "-- $name"
  curl -sSL --fail --retry 3 --retry-delay 5 -o "$out" "$url"
  osmium fileinfo "$out" >/dev/null || { echo "$name: not a readable PBF" >&2; exit 1; }
  INPUTS+=("$out")
done

echo
echo "== Merging =="
MERGED="$WORK_DIR/merged.osm.pbf"
# All four extracts are cut from the same planet snapshot, so shared boundary
# objects carry identical versions and osmium merge keeps exactly one of each.
# Mixing extract dates instead leaves duplicate ids that later osmium passes
# reject, so always download all four together rather than topping up an
# existing file.
osmium merge "${INPUTS[@]}" -o "$MERGED" --overwrite
ls -l "$MERGED"

echo
echo "== Verifying every region has a road network =="
PROBE_DIR="$WORK_DIR/probes"
rm -rf "$PROBE_DIR"; mkdir -p "$PROBE_DIR"

CONFIG="$WORK_DIR/probes.json"
{
  echo '{"directory":"'"$PROBE_DIR"'","extracts":['
  first=1
  for entry in "${PROBES[@]}"; do
    IFS='|' read -r name bbox _ <<<"$entry"
    IFS=',' read -r w s e n <<<"$bbox"
    [ $first -eq 1 ] || echo ','
    first=0
    printf '{"output":"%s.osm.pbf","bbox":[%s,%s,%s,%s]}' "$name" "$w" "$s" "$e" "$n"
  done
  echo ']}'
} > "$CONFIG"

# One pass over the merged file cuts every probe at once.
osmium extract -c "$CONFIG" "$MERGED" --overwrite

FAILED=0
for entry in "${PROBES[@]}"; do
  IFS='|' read -r name bbox threshold <<<"$entry"
  hw="$PROBE_DIR/$name-highways.osm.pbf"
  osmium tags-filter "$PROBE_DIR/$name.osm.pbf" w/highway -o "$hw" --overwrite >/dev/null 2>&1
  ways=$(osmium fileinfo -e "$hw" | awk '/Number of ways:/ {print $4; exit}')
  ways=${ways:-0}
  if [ "$ways" -lt "$threshold" ]; then
    printf '  FAIL %-14s %8s highway ways (need >= %s)\n' "$name" "$ways" "$threshold"
    FAILED=1
  else
    printf '  ok   %-14s %8s highway ways\n' "$name" "$ways"
  fi
done

if [ "$FAILED" -ne 0 ]; then
  echo
  echo "Refusing to install: a region has no road network in the merged file." >&2
  echo "Merged file left at $MERGED for inspection." >&2
  exit 1
fi

echo
echo "== Installing =="
# freegle worktree create HARDLINKS this file into every worktree, so write
# through the existing inode rather than renaming over it: a rename would give
# the main checkout the new data and leave every worktree on the old file.
# Verification above has already passed, so the destructive write is safe.
if [ -e "$TARGET" ]; then
  echo "Existing target has $(stat -c %h "$TARGET") hardlink(s); writing in place to update them all."
  cat "$MERGED" > "$TARGET"
else
  mkdir -p "$(dirname "$TARGET")"
  cp "$MERGED" "$TARGET"
fi
ls -l "$TARGET"

if [ "$KEEP" -eq 0 ]; then
  rm -rf "$WORK_DIR"
  echo "Removed $WORK_DIR (pass --keep to retain downloads)."
fi

echo
echo "Done. Restart the routing containers to load it:"
echo "  docker restart \${COMPOSE_PROJECT_NAME:-freegle}-spatial"
