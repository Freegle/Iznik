# Ipswich areas — data-driven re-partitioning

## Problem

Each Freegle postcode is mapped to a user-visible area label (e.g.
"Ravenswood", "Stowmarket"). Moderators of the Ipswich Recycle group
(groupid 21483) report the current areas are poor — names wrong,
boundaries unrecognisable to locals.

Goal: produce a proposed set of areas as a standalone HTML
visualisation that moderators can inspect and approve.

## Constraints

1. **No administrative borders.** Parish/ward/LSOA boundaries don't
   match the borders in people's heads.
2. **Exclude postcodes beyond 2R from the group centre**, where R is
   the maximum distance from centre to group polygon boundary. For
   Ipswich, R = 20 km → cutoff 40 km.
3. **No overlapping areas.** Every postcode belongs to exactly one
   cell.
4. **Freegle tile server** for the map
   (`https://tiles.ilovefreegle.org/tile/{z}/{x}/{y}.png`).
5. **Single standalone HTML file** — no server.

## Approach — place-seeded flood fill on postcode graph

OSM `place=*` nodes are the seed authority. Instead of a plain
Voronoi around those seeds (which produces straight-line
perpendicular-bisector edges through built-up areas), the partition
is a **flood fill on a k-NN postcode graph**. Postcode-density gaps
(fields, rivers, industrial land) act as natural barriers. OSM
barriers (rivers, major roads, railways) further weight edges that
cross them, so boundaries prefer to follow real geography.

### Pipeline

1. **Pull PAF aggregates** — one row per postcode within the 2R
   bbox with `lat`, `lng`, `dp_count` (delivery-point count).
   ~34k postcodes in bbox, ~7.6k inside the group polygon.

2. **Pull OSM place nodes** (town, village, suburb, neighbourhood,
   hamlet, locality) within the 2R bbox via Overpass.

3. **Filter seeds** to places inside the group polygon plus a 4 km
   buffer. Strictly-inside-only dropped Manningtree, Stowmarket,
   Woodbridge whose OSM nodes sit just outside the polygon but
   whose built-up areas reach in. 4 km recovers these without
   pulling in Colchester or Bury.

4. **Build a k-NN graph (k=8) on in-group postcodes** plus a
   postcode-level Voronoi tessellation (envelope = group polygon
   buffered 10 km). Each postcode maps to one Voronoi cell; the
   graph is symmetrised so edges are bidirectional.

5. **Apply OSM barrier penalties on graph edges.**
   `fetch_barriers.sh` pulls `waterway=river`,
   `highway=motorway|trunk|primary` and `railway=rail` ways.
   Each k-NN edge is checked with `LineString.crosses()` against
   a barrier STRtree; if it crosses, its weight is multiplied by
   the highest penalty among barriers it crosses. Penalties:
   river 6×, motorway 6×, trunk 5×, rail 4×, primary 2.5×.

6. **Partition per region via multi-source Dijkstra.** For each
   seed, find its nearest postcode; run
   `dijkstra(adj, indices=seed_postcodes, min_only=True)`. Each
   postcode learns which seed is graph-nearest; dissolve each
   postcode's Voronoi cell into its seed's cell; clip to the
   parent region.

7. **Three hierarchical granularities** (same partitioner each time):

   | Level  | Seeds                                                                          | Cells |
   |--------|--------------------------------------------------------------------------------|------:|
   | Coarse | `place=town` only                                                              |     9 |
   | Medium | Subdivide each coarse cell by `village\|suburb\|neighbourhood` inside it (include parent town seed so town centre keeps a cell) | 113 |
   | Fine   | Subdivide each medium cell by `hamlet\|locality` inside it                     | 150 |

   Hierarchy matters: Ipswich's single `place=town` node would lose
   a flat flood-fill contest against surrounding suburb nodes.
   Town-first keeps its coarse shape (~100k pop) and still
   subdivides properly at medium.

8. **Clip all cells to the group polygon.** 2R is a data envelope;
   output belongs to the group's serving area.

9. **Score each cell**: sum PAF `dp_count` for postcodes inside →
   `population`; count Freegle members → `member_count` (for
   display, not partitioning).

10. **Rename the town-seeded cell nearest the group centroid to
    "Central"** at every level, using seed-object identity so the
    same spatial area stays "Central" when switching level.

11. **Disambiguate duplicate names** — compass-direction suffix
    relative to centroid of duplicates (e.g. "Combs Ford (N)" /
    "Combs Ford (S)").

12. **Privacy jitter on member dots.** Each member home is
    Gaussian-displaced by σ = 150 m in a local tangent frame; only
    `lat`/`lng` (rounded to 5 d.p.) are exported. Exact home
    coordinates are not recoverable from the HTML.

## Verification

- Zero overlap at every level (pairwise intersection test).
- Total cell area = group polygon area across all three levels.
- Every coarse cell has positive population.
- Coarse counts (9): Central 99,683 · Needham Market 23,562 ·
  Hadleigh 16,393 · Kesgrave 13,999 · Manningtree 13,029 ·
  Woodbridge 4,272 · Harwich 3,964 · Felixstowe 2,198 ·
  Stowmarket 1,100.

## Files

| Path                                             | Role                                                                |
|--------------------------------------------------|---------------------------------------------------------------------|
| `/tmp/ipswich-areas/places_voronoi.py`           | Primary pipeline (filename vestigial — flood fill, not Voronoi)     |
| `/tmp/ipswich-areas/cluster.py`                  | Utility functions (group polygon loader etc.) used by the pipeline  |
| `/tmp/ipswich-areas/fetch_osm.sh`                | Overpass query for `place=*` nodes                                  |
| `/tmp/ipswich-areas/fetch_barriers.sh`           | Overpass query for waterway/highway/rail barriers                   |
| `/tmp/ipswich-areas/paf_postcodes.tsv`           | Input: PAF delivery counts per postcode                             |
| `/tmp/ipswich-areas/osm_places.json`             | Input: OSM place nodes                                              |
| `/tmp/ipswich-areas/osm_barriers.json`           | Input: OSM barrier ways                                             |
| `/tmp/ipswich-areas/members.tsv`                 | Input: member export (only for display of blurred dots)             |
| `/tmp/ipswich-areas/current_areas.tsv`           | Input: existing postcode → area mapping (reference overlay)         |
| `/tmp/ipswich-areas/clusters.json`               | Output: 3 levels of cell features                                   |
| `/tmp/ipswich-areas/members.json`                | Output: jittered member coordinates                                 |
| `/tmp/ipswich-areas/places.json`                 | Output: places GeoJSON                                              |
| `/tmp/ipswich-areas/barriers.json`               | Output: barriers GeoJSON for display layer                          |
| `/tmp/ipswich-areas/bundle.json`                 | Combined payload substituted into the HTML                          |
| `/tmp/ipswich-areas/ipswich_areas_template.html` | Leaflet UI shell with `__PAYLOAD__` slot                            |
| `/tmp/ipswich-areas/ipswich_areas.html`          | Final standalone deliverable (~4.3 MB)                              |
| `/tmp/ipswich-areas-freegle/`                    | Git checkout of `Freegle/ipswich-areas` (Pages repo)                |

## Hosting

Canonical share link: **`https://freegle.github.io/ipswich-areas/`**
— served from `main` branch root of `Freegle/ipswich-areas`. Local
checkout at `/tmp/ipswich-areas-freegle/` for `git push` updates.

## How to regenerate

All work happens in `/tmp/ipswich-areas/`. Venv: `./venv/bin/python`.
Deps: numpy, scipy (sparse, csgraph, spatial), shapely 2.x.

### 1. Refresh raw inputs (only when source data has moved on)

```bash
cd /tmp/ipswich-areas

# OSM place=* nodes:
./fetch_osm.sh            # → osm_places.json

# OSM barrier ways:
./fetch_barriers.sh       # → osm_barriers.json

# PAF delivery-point counts per postcode:
docker exec freegle-apiv2-live mysql -h db-live -P 11234 -u root -pF5432f12azfvds iznik <<'SQL'
  SELECT l.name AS postcode, l.lat, l.lng, COUNT(pa.id) AS dp_count
  FROM locations l LEFT JOIN paf_addresses pa ON l.id = pa.postcodeid
  WHERE l.type = 'Postcode'
    AND l.lat BETWEEN 51.65 AND 52.45
    AND l.lng BETWEEN 0.45  AND 1.70
  GROUP BY l.id;
SQL
# (pipe to paf_postcodes.tsv with a header row)
```

### 2. Run the pipeline

```bash
cd /tmp/ipswich-areas
./venv/bin/python places_voronoi.py
# writes clusters.json, members.json, places.json, barriers.json
```

~3 s on the 7.5k-postcode in-group graph. Most of the runtime is
the edge-barrier crossing check.

### 3. Re-embed into the HTML

```bash
./venv/bin/python <<'PY'
import json, math, csv
from cluster import load_group_poly
from shapely.geometry import mapping
clusters = json.load(open('clusters.json'))
members  = json.load(open('members.json'))
places   = json.load(open('places.json'))
barriers = json.load(open('barriers.json'))
group    = load_group_poly()
lat_c, lng_c = group.centroid.y, group.centroid.x
pts = []
for i in range(65):
    a = 2 * math.pi * i / 64
    dlat = (40000 / 111320.0) * math.cos(a)
    dlng = (40000 / (111320.0 * math.cos(math.radians(lat_c)))) * math.sin(a)
    pts.append([lng_c + dlng, lat_c + dlat])
current = []
try:
    for row in csv.DictReader(open('current_areas.tsv'), delimiter='\t'):
        try:
            current.append({'postcode': row['postcode'], 'area': row.get('area') or '',
                            'lat': float(row['lat']), 'lng': float(row['lng'])})
        except (ValueError, KeyError): pass
except FileNotFoundError: pass
bundle = {
    'group_poly':    {'type':'Feature','geometry':mapping(group),'properties':{}},
    'cutoff_circle': {'type':'Feature','geometry':{'type':'Polygon','coordinates':[pts]},'properties':{}},
    'cutoff_km':     40.0,
    'clusters': clusters, 'members': members, 'places': places,
    'current':  current,  'barriers': barriers,
}
json.dump(bundle, open('bundle.json','w'), separators=(',',':'))
tpl = open('ipswich_areas_template.html').read()
open('ipswich_areas.html','w').write(tpl.replace('__PAYLOAD__', open('bundle.json').read()))
PY
```

### 4. Republish

```bash
cp /tmp/ipswich-areas/ipswich_areas.html /tmp/ipswich-areas-freegle/index.html
cd /tmp/ipswich-areas-freegle
git add index.html
git -c commit.gpgsign=false commit -m "Refresh proposed areas preview"
git push
# Pages rebuilds within ~60 s.
```

## Tunables

All in `places_voronoi.py`:

- `COARSE_TYPES / MEDIUM_TYPES / FINE_TYPES` — OSM `place=*` values
  seeding each level.
- `KNN_K = 8` — neighbours in the postcode graph. Higher → smoother
  boundaries but more cross-barrier edges.
- `MEMBER_JITTER_M = 150.0` — σ on member dots in metres.
- `BARRIER_PENALTIES` — per-type multiplier on edges that cross a
  feature. River/motorway = 6×, trunk = 5×, rail = 4×, primary =
  2.5×. Raise to make a barrier harder to cross.
- Seed-filter buffer (4 km) in `main()` — how far outside the group
  polygon a place may sit and still count as a seed.
