#!/usr/bin/env python3
"""
Apply candidate posts/day thresholds to sample.csv and summarise nationwide.

For each (floor, ceil) candidate, per sampled user we pick the smallest reach
band whose posts/day >= floor (clamped to [min_reach, max_reach]); that band's
posts/day is the user's NEW load. We then report, overall and stratified by
their CURRENT (group-based) load, how the policy behaves.

Usage: python3 analyze_thresholds.py [sample.csv]
"""
import csv, os, statistics, sys

HERE = os.path.dirname(os.path.abspath(__file__))
CSV  = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, "sample.csv")
RADII = [2, 5, 10, 15, 20, 30, 40]
MIN_REACH, MAX_REACH = 5, 40           # km clamp on the ripple
CANDIDATES = [(10, 40), (15, 50), (20, 50), (20, 60), (25, 60)]

def pct(xs, p):
    if not xs: return float("nan")
    xs = sorted(xs); i = min(len(xs)-1, int(round(p/100*(len(xs)-1))))
    return xs[i]

def load():
    rows = []
    with open(CSV) as f:
        for d in csv.DictReader(f):
            rows.append({
                "cur": float(d["current_load_day"]),
                "digest": d["digest"] == "1",
                "band": {r: float(d[f"posts_{r}km_day"]) for r in RADII},
            })
    return rows

def choose(band, floor):
    """smallest reach >= MIN_REACH whose posts/day >= floor; clamp at MAX_REACH."""
    usable = [r for r in RADII if MIN_REACH <= r <= MAX_REACH]
    for r in usable:
        if band[r] >= floor:
            return r, band[r]
    r = usable[-1]
    return r, band[r]            # sparse: capped at max reach, still < floor

def main():
    rows = load()
    n = len(rows)
    print(f"sample n={n}  digest-users={sum(r['digest'] for r in rows)}")
    print(f"current load/day: p10={pct([r['cur'] for r in rows],10):.0f} "
          f"p50={pct([r['cur'] for r in rows],50):.0f} "
          f"p90={pct([r['cur'] for r in rows],90):.0f} "
          f"max={max(r['cur'] for r in rows):.0f}")
    print(f"max-reach ({MAX_REACH}km) posts/day: "
          f"p10={pct([r['band'][MAX_REACH] for r in rows],10):.0f} "
          f"p50={pct([r['band'][MAX_REACH] for r in rows],50):.0f} "
          f"p90={pct([r['band'][MAX_REACH] for r in rows],90):.0f}")
    print()
    for floor, ceil in CANDIDATES:
        reach, new, cur = [], [], []
        under, over = 0, 0          # under-floor (sparse), over-ceil at min reach
        up, down = 0, 0
        for r in rows:
            R, ld = choose(r["band"], floor)
            reach.append(R); new.append(ld); cur.append(r["cur"])
            if ld < floor: under += 1
            if R == MIN_REACH and ld > ceil: over += 1
            if ld > r["cur"] + 0.5: up += 1
            elif ld < r["cur"] - 0.5: down += 1
        inband = sum(floor <= x <= ceil for x in new)
        print(f"floor={floor:>3} ceil={ceil:>3}  "
              f"in-band={inband*100//n:>3}%  "
              f"reach p50={pct(reach,50):>2}km p90={pct(reach,90):>2}km  "
              f"new/day p50={pct(new,50):>3.0f} p90={pct(new,90):>3.0f}  "
              f"sparse<floor={under*100//n:>2}%  dense>ceil={over*100//n:>2}%  "
              f"up={up*100//n}% down={down*100//n}%")
    # stratify the headline candidate by current-load tercile
    floor, ceil = 20, 50
    print(f"\nstratified by CURRENT load  (policy floor={floor} ceil={ceil}):")
    rows.sort(key=lambda r: r["cur"])
    k = max(1, n//3)
    for name, seg in [("low(near-boundary)", rows[:k]),
                      ("mid", rows[k:2*k]),
                      ("high(busy)", rows[2*k:])]:
        cur = [r["cur"] for r in seg]
        new = [choose(r["band"], floor)[1] for r in seg]
        rch = [choose(r["band"], floor)[0] for r in seg]
        print(f"  {name:<20} cur p50={pct(cur,50):>3.0f}  "
              f"new p50={pct(new,50):>3.0f} (p90={pct(new,90):>3.0f})  "
              f"reach p50={pct(rch,50):>2}km")

if __name__ == "__main__":
    main()
