"""
Build uk_lsoa_quintile.csv covering England, Wales, Scotland and Northern Ireland.

Sources:
  England/Wales centroids: ONS Geography (LSOA 2011 population-weighted centroids)
  Scotland centroids:       Scottish Gov Data Zones 2011 centroids (maps.gov.scot)
  NI centroids:             NISRA SOA 2001 GeoJSON polygon centroids (OpenDataNI)
  Quintile scores:          mySociety composite UK IMD (UK_IMD_E.csv)
                            Quintile 1 = most deprived, 5 = least deprived
"""

import csv, io, json, sys, requests
import pandas as pd
from pyproj import Transformer

# NI GeoJSON is in Irish Grid (EPSG:29903), not WGS84
_ni_transformer = Transformer.from_crs("EPSG:29903", "EPSG:4326", always_xy=True)

# Accept output path as command-line argument, or use default
OUT = sys.argv[1] if len(sys.argv) > 1 else "/home/edward/FreegleDocker-spatial-improvements/iznik-routing-go/data/uk_lsoa_quintile.csv"

def log(msg):
    print(msg, flush=True)

def polygon_centroid_xy(coords):
    """Signed-area centroid of the first ring of a GeoJSON polygon. Returns (x, y)."""
    ring = coords[0]
    n = len(ring)
    cx, cy, area = 0.0, 0.0, 0.0
    for i in range(n - 1):
        x0, y0 = ring[i]
        x1, y1 = ring[i + 1]
        cross = x0 * y1 - x1 * y0
        area += cross
        cx += (x0 + x1) * cross
        cy += (y0 + y1) * cross
    area *= 0.5
    if abs(area) < 1e-15:
        xs = [p[0] for p in ring]
        ys = [p[1] for p in ring]
        return sum(xs) / len(xs), sum(ys) / len(ys)
    cx /= (6 * area)
    cy /= (6 * area)
    return cx, cy  # return (x, y)

# ── 1. mySociety composite UK IMD ────────────────────────────────────────────
log("Downloading mySociety composite UK IMD...")
imd_url = "https://raw.githubusercontent.com/mysociety/composite_uk_imd/master/data/uk_index/UK_IMD_E.csv"
imd_r = requests.get(imd_url, timeout=60)
imd_r.raise_for_status()
imd = pd.read_csv(io.StringIO(imd_r.text))
log(f"  IMD rows: {len(imd)}, columns: {list(imd.columns)}")

# Use UK_IMD_E_pop_quintile if available (already 1-5), else derive from decile
if "UK_IMD_E_pop_quintile" in imd.columns:
    imd["quintile"] = imd["UK_IMD_E_pop_quintile"].astype(int)
    log("  Using column: UK_IMD_E_pop_quintile")
elif "E_expanded_decile" in imd.columns:
    imd["quintile"] = ((imd["E_expanded_decile"] - 1) // 2 + 1).clip(1, 5).astype(int)
    log("  Derived quintile from E_expanded_decile")
else:
    raise ValueError(f"No usable quintile column found in {list(imd.columns)}")

code_col = "lsoa" if "lsoa" in imd.columns else "Code"
log(f"  Code column: {code_col}, quintile distribution: {imd['quintile'].value_counts().sort_index().to_dict()}")
imd_lookup = dict(zip(imd[code_col].astype(str), imd["quintile"]))
log(f"  Lookup entries: {len(imd_lookup)}")

rows = []  # (lat, lng, quintile)

# ── 2. England & Wales LSOAs ─────────────────────────────────────────────────
log("Downloading England & Wales LSOA centroids (ONS, paginated)...")
ons_url = ("https://services1.arcgis.com/ESMARspQHYMw9BZ9/arcgis/rest/services/"
           "LSOA_Dec_2011_PWC_in_England_and_Wales_2022/FeatureServer/0/query")
offset = 0
ew_count = 0
while True:
    r = requests.get(ons_url, params={
        "where": "1=1", "outFields": "lsoa11cd",
        "returnGeometry": "true", "outSR": "4326", "f": "json",
        "resultOffset": offset, "resultRecordCount": 2000,
    }, timeout=60)
    r.raise_for_status()
    data = r.json()
    if data.get("error"):
        log(f"  E&W API error: {data['error']}")
        break
    feats = data.get("features", [])
    for f in feats:
        code = str(f["attributes"].get("lsoa11cd", ""))
        geo = f.get("geometry", {})
        lng, lat = geo.get("x"), geo.get("y")
        if lat is None or lng is None:
            continue
        q = imd_lookup.get(code)
        if q:
            rows.append((lat, lng, q))
            ew_count += 1
    if not data.get("exceededTransferLimit"):
        break
    offset += 2000
    if offset % 20000 == 0:
        log(f"  E&W: {offset} fetched so far...")
log(f"  England & Wales matched: {ew_count}")

# ── 3. Scotland Data Zones ────────────────────────────────────────────────────
# The DataZoneCent2011 layer has a pagination bug: offset-based pagination stops
# at ~3976 records even though there are 6976. Paginate by objectid instead.
log("Downloading Scotland Data Zone centroids (maps.gov.scot, objectid pagination)...")
scot_url = ("https://maps.gov.scot/server/rest/services/ScotGov/StatisticalUnits/MapServer/4/query")
last_oid = 0
scot_count = 0
scot_total = 0
while True:
    r = requests.get(scot_url, params={
        "where": f"objectid > {last_oid}",
        "outFields": "objectid,datazone",
        "returnGeometry": "true", "outSR": "4326",
        "orderByFields": "objectid",
        "f": "json", "resultRecordCount": 1000,
    }, timeout=60)
    r.raise_for_status()
    data = r.json()
    if data.get("error"):
        log(f"  Scotland API error: {data['error']}")
        break
    feats = data.get("features", [])
    if not feats:
        break
    scot_total += len(feats)
    last_oid = max(f["attributes"]["objectid"] for f in feats)
    for f in feats:
        code = str(f["attributes"].get("datazone", ""))
        geo = f.get("geometry", {})
        lng, lat = geo.get("x"), geo.get("y")
        if lat is None or lng is None:
            continue
        q = imd_lookup.get(code)
        if q:
            rows.append((lat, lng, q))
            scot_count += 1
    if not data.get("exceededTransferLimit"):
        break
    if scot_total % 3000 == 0:
        log(f"  Scotland: {scot_total} fetched so far...")
log(f"  Scotland matched: {scot_count} (of {scot_total} fetched)")

# ── 4. Northern Ireland SOAs ──────────────────────────────────────────────────
log("Downloading Northern Ireland SOA 2001 polygons (OpenDataNI GeoJSON)...")
ni_url = ("https://admin.opendatani.gov.uk/dataset/678697e1-ae71-41f3-abba-0ef5f3f352c2"
          "/resource/80392e82-8bee-42de-a1e3-82d1cbaa983f/download/soa2001.json")
ni_r = requests.get(ni_url, timeout=120)
ni_r.raise_for_status()
ni_data = ni_r.json()
ni_count = 0
for feat in ni_data.get("features", []):
    props = feat.get("properties", {})
    code = str(props.get("SOA_CODE", ""))
    geo = feat.get("geometry", {})
    gtype = geo.get("type", "")
    coords = geo.get("coordinates", [])
    if gtype == "Polygon":
        proj_x, proj_y = polygon_centroid_xy(coords)
    elif gtype == "MultiPolygon":
        largest = max(coords, key=lambda p: len(p[0]))
        proj_x, proj_y = polygon_centroid_xy(largest)
    else:
        continue
    # Convert Irish Grid (EPSG:29903) → WGS84
    lng, lat = _ni_transformer.transform(proj_x, proj_y)
    q = imd_lookup.get(code)
    if q:
        rows.append((lat, lng, q))
        ni_count += 1
log(f"  Northern Ireland matched: {ni_count}")

# ── 5. Write output ────────────────────────────────────────────────────────────
log(f"Total rows: {len(rows)}. Writing to {OUT}...")
with open(OUT, "w", newline="") as f:
    w = csv.writer(f)
    w.writerow(["lat", "lng", "quintile"])
    w.writerows(rows)
log("Done.")
