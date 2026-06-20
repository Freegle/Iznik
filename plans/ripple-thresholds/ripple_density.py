#!/usr/bin/env python3
"""
Nationwide rippling-out post-density sampler.

Token- and prod-friendly: pulls a handful of bounded result sets ONCE over the
live tunnel, then does all distance bucketing locally. Emits a compact CSV.

Pulls (all bounded):
  Q1  sample of real users (userid, lat, lng) from users_approxlocs
  Q2  those users' Freegle group memberships (userid, groupid, emailfrequency)
  Q3  per-group last-N-day Offer/Wanted counts  -> current group-based load
  Q4  all last-N-day Offer/Wanted points (lng, lat)  -> reach/density bucketing

Then per sampled user:
  current_load/day  = sum over their groups of (group_count / window_days)
  posts/day at R    = (# Q4 points within R km) / window_days, for each R band

Usage:
  python3 ripple_density.py --probe                 # verify schema first
  python3 ripple_density.py --n 800 --window 7      # full run -> sample.csv
"""
import argparse, csv, math, os, subprocess, sys, re

HERE = os.path.dirname(os.path.abspath(__file__))
ENV  = os.path.join(HERE, "..", "..", ".env")
RADII_KM = [2, 5, 10, 15, 20, 30, 40]

def creds():
    def g(k):
        with open(ENV) as f:
            for ln in f:
                m = re.match(rf'^{k}=(.*)$', ln.strip())
                if m: return m.group(1)
        sys.exit(f"missing {k} in {ENV}")
    return g("LIVE_DB_PORT"), g("LIVE_DB_USER"), g("LIVE_DB_PASSWORD")

PORT, USER, PWD = creds()

def q(sql):
    """Run SQL via mysql CLI, return list of rows (list of str fields)."""
    env = dict(os.environ, MYSQL_PWD=PWD)
    p = subprocess.run(
        ["mysql", "--connect-timeout=15", "-h127.0.0.1", f"-P{PORT}", f"-u{USER}",
         "-N", "-B", "iznik", "-e", sql],
        capture_output=True, env=env, timeout=600)          # bytes (geom safe)
    if p.returncode != 0:
        sys.exit(f"SQL error:\n{p.stderr.decode('utf-8','replace').strip()}\n--SQL--\n{sql}")
    out = p.stdout.decode("utf-8", "replace").rstrip("\n")
    return [ln.split("\t") for ln in out.split("\n")] if out else []

def haversine_km(lat1, lng1, lat2, lng2):
    R = 6371.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dphi = math.radians(lat2 - lat1); dlmb = math.radians(lng2 - lng1)
    a = math.sin(dphi/2)**2 + math.cos(p1)*math.cos(p2)*math.sin(dlmb/2)**2
    return 2*R*math.asin(math.sqrt(a))

# ---------------------------------------------------------------- probe
def probe():
    print("== messages_spatial sample (point encoding?) ==")
    for r in q("SELECT msgid, ST_X(point), ST_Y(point), ST_SRID(point), msgtype, arrival, COALESCE(groupid,0) FROM messages_spatial ORDER BY arrival DESC LIMIT 5"):
        print("  ", r)
    print("== last-7d Offer/Wanted volume ==")
    for r in q("SELECT msgtype, COUNT(*) FROM messages_spatial WHERE arrival >= NOW() - INTERVAL 7 DAY AND msgtype IN ('Offer','Wanted') GROUP BY msgtype"):
        print("  ", r)
    print("== users_approxlocs columns ==")
    for r in q("SHOW COLUMNS FROM users_approxlocs"):
        print("  ", r)
    print("== users_approxlocs sample ==")
    for r in q("SELECT * FROM users_approxlocs LIMIT 3"):
        print("  ", r)
    print("== users_approxlocs count ==")
    print("  ", q("SELECT COUNT(*) FROM users_approxlocs"))
    print("== memberships columns ==")
    for r in q("SHOW COLUMNS FROM memberships"):
        print("  ", r)
    print("== groups: count by type ==")
    for r in q("SELECT type, COUNT(*) FROM groups GROUP BY type"):
        print("  ", r)

# ---------------------------------------------------------------- main run
def run(n, window):
    # NOTE: adjust column names/point encoding per probe output before trusting.
    print(f"Q1 sampling {n} users ...", file=sys.stderr)
    users = q(f"""
        SELECT userid, lat, lng
        FROM users_approxlocs
        WHERE lat BETWEEN 49 AND 61 AND lng BETWEEN -11 AND 2
        ORDER BY RAND() LIMIT {n}
    """)
    users = [(int(u), float(la), float(ln)) for u, la, ln in users if la and ln]
    uids = ",".join(str(u) for u, _, _ in users)

    print("Q2 memberships ...", file=sys.stderr)
    memb = {}  # userid -> [(groupid, emailfreq)]
    for uid, gid, ef in q(f"""
        SELECT m.userid, m.groupid, m.emailfrequency
        FROM memberships m JOIN `groups` g ON g.id = m.groupid
        WHERE m.userid IN ({uids}) AND g.type = 'Freegle'
    """):
        memb.setdefault(int(uid), []).append((int(gid), ef))

    print("Q3 per-group post counts ...", file=sys.stderr)
    gcount = {int(g): int(c) for g, c in q(f"""
        SELECT groupid, COUNT(*) FROM messages_spatial
        WHERE arrival >= NOW() - INTERVAL {window} DAY
          AND msgtype IN ('Offer','Wanted') AND groupid IS NOT NULL
        GROUP BY groupid
    """)}

    print("Q4 pulling post points ...", file=sys.stderr)
    posts = []  # (lat, lng)
    for x, y in q(f"""
        SELECT ST_X(point), ST_Y(point) FROM messages_spatial
        WHERE arrival >= NOW() - INTERVAL {window} DAY
          AND msgtype IN ('Offer','Wanted')
    """):
        try: posts.append((float(y), float(x)))   # (lat, lng)  [X=lng, Y=lat]
        except ValueError: pass
    print(f"  {len(posts)} posts", file=sys.stderr)

    # spatial grid for fast radius counting (0.1deg cells ~ 11km lat)
    CELL = 0.1
    grid = {}
    for la, ln in posts:
        grid.setdefault((round(la/CELL), round(ln/CELL)), []).append((la, ln))

    def counts_within(la, ln, radii):
        rmax = max(radii)
        span = int(rmax/111.0/CELL) + 1
        cla, cln = round(la/CELL), round(ln/CELL)
        cand = []
        for i in range(cla-span, cla+span+1):
            for j in range(cln-span, cln+span+1):
                cand += grid.get((i, j), [])
        out = [0]*len(radii)
        for pla, pln in cand:
            d = haversine_km(la, ln, pla, pln)
            for k, r in enumerate(radii):
                if d <= r: out[k] += 1
        return out

    print("computing per-user density ...", file=sys.stderr)
    outp = os.path.join(HERE, "sample.csv")
    with open(outp, "w", newline="") as f:
        w = csv.writer(f)
        w.writerow(["userid", "lat", "lng", "ngroups", "digest",
                    "current_load_day"] + [f"posts_{r}km_day" for r in RADII_KM])
        for uid, la, ln in users:
            groups = memb.get(uid, [])
            cur = sum(gcount.get(g, 0) for g, _ in groups) / window
            digest = 1 if any((ef not in (None, "", "-1") and float(ef) > 0) for _, ef in groups) else 0
            cnts = counts_within(la, ln, RADII_KM)
            w.writerow([uid, f"{la:.5f}", f"{ln:.5f}", len(groups), digest,
                        f"{cur:.2f}"] + [f"{c/window:.2f}" for c in cnts])
    print(f"wrote {outp} ({len(users)} rows)")

if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--probe", action="store_true")
    ap.add_argument("--n", type=int, default=800)
    ap.add_argument("--window", type=int, default=7)
    a = ap.parse_args()
    if a.probe: probe()
    else: run(a.n, a.window)
