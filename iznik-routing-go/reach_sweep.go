package main

// Reach engine UK-wide exactness sweep: for several hundred real posts bucketed
// across the UK (one to three per half-degree cell, exported read-only), and
// then a synthetic origin planted in every sizable partition region the posts
// did not touch, compare the engine's arrivals — BOTH the live query result
// and the stored-label round trip (Encode/Decode + seed path) — against the
// current approach's own metric (a plain full-graph Dijkstra from the same
// snapped origin with the same budget). Every arrival must match to float
// noise; every membership answer must be identical.

import (
	"bufio"
	"encoding/json"
	"fmt"
	"log"
	"math"
	"os"
	"sort"
	"sync"
	"time"
)

type sweepOrigin struct {
	Msgid    int64   `json:"msgid"`
	Lat      float64 `json:"lat"`
	Lng      float64 `json:"lng"`
	Tick     int     `json:"tick"`
	Schedule []struct {
		Tick     int     `json:"tick"`
		DriveMin float64 `json:"drive_min"`
	} `json:"schedule"`
	synthetic  bool
	fuzzBudget float64 // seconds; used when synthetic
}

func (s *sweepOrigin) budget() (float32, bool) {
	if s.synthetic {
		if s.fuzzBudget > 0 {
			return float32(s.fuzzBudget), true
		}
		return 30 * 60, true
	}
	best, ok := 0.0, false
	bestTick := -1
	for _, e := range s.Schedule {
		if e.Tick <= s.Tick && e.Tick > bestTick {
			bestTick = e.Tick
			best = e.DriveMin
			ok = true
		}
	}
	return float32(best * 60), ok
}

func reachSweepRun(file string, maxSynthetic, fuzz int, engine *ReachEngine) {
	f, err := os.Open(file)
	if err != nil {
		log.Fatalf("sweep: %v", err)
	}
	defer f.Close()
	var origins []*sweepOrigin
	sc := bufio.NewScanner(f)
	sc.Buffer(make([]byte, 1<<20), 1<<22)
	for sc.Scan() {
		line := sc.Bytes()
		if len(line) < 2 {
			continue
		}
		o := &sweepOrigin{}
		if err := json.Unmarshal(line, o); err != nil {
			log.Fatalf("sweep: bad line: %v", err)
		}
		origins = append(origins, o)
	}
	log.Printf("sweep: %d real-post origins", len(origins))

	g := engine.G
	touched := map[int32]struct{}{}

	type totals struct {
		origins, skipped, probes, mismLive, mismStored, flips int
		unreachedProbes, falseIn                              int
		metChecked, metMissing, metDev                        int
		worstLive, worstStored                                float64
		baseMs, queryMs                                       float64
	}
	var t totals
	var mu sync.Mutex

	runOne := func(o *sweepOrigin) {
		T, ok := o.budget()
		if !ok {
			mu.Lock()
			t.skipped++
			mu.Unlock()
			return
		}
		origin := nearestNodeForMode(g, o.Lat, o.Lng, Drive)
		if origin == noNode {
			mu.Lock()
			t.skipped++
			mu.Unlock()
			return
		}
		q0 := time.Now()
		lbl := engine.QueryLabels(o.Lat, o.Lng, T)
		qMs := float64(time.Since(q0).Microseconds()) / 1000
		blob := EncodeLabels(lbl)
		stored, err := engine.DecodeLabels(blob)
		if err != nil {
			log.Fatalf("sweep: decode round trip failed for %d: %v", o.Msgid, err)
		}
		b0 := time.Now()
		base, baseMet := baseDriveDijkstraM(g, origin, initialCostFor(Drive), T)
		bMs := float64(time.Since(b0).Microseconds()) / 1000

		type mism struct {
			id        NodeID
			got, want float32
			stored    bool
		}
		var misms []mism
		probes, flips := 0, 0
		stride := 1
		if len(base) > 8000 {
			stride = len(base) / 8000
		}
		i := 0
		metChecked, metMissing, metDev := 0, 0, 0
		for id, want := range base {
			i++
			if i%stride != 0 {
				continue
			}
			probes++
			// Tolerance is relative: float32 summation order across hundreds
			// of hops legitimately differs by a few parts per million, which
			// at a 90-minute arrival is ~0.01-0.02s.
			tol := 0.01 + float64(want)*1e-5
			live, liveM := engine.ArrivalAtBaseNodeM(lbl, id)
			if d := math.Abs(float64(live - want)); d > tol {
				misms = append(misms, mism{id, live, want, false})
			}
			st := engine.arrivalAtBaseNodeStored(stored, id)
			if d := math.Abs(float64(st - want)); d > tol {
				misms = append(misms, mism{id, st, want, true})
			}
			if (live <= T) != (want <= T) || (st <= T) != (want <= T) {
				flips++
			}
			// Road-metres verification: must be present, and track the base
			// path's metres except on equal-time alternate-path ties.
			if live != f32Inf {
				if liveM == f32Inf {
					metMissing++
				} else {
					metChecked++
					wm := float64(baseMet[id])
					if d := math.Abs(float64(liveM) - wm); d > math.Max(150, 0.05*wm) {
						metDev++
					}
				}
			}
		}

		// False-membership probes: pseudo-random nodes the base search did NOT
		// reach must not be claimed in reach by either evaluation path.
		unreached, falseIn := 0, 0
		seed := uint64(o.Msgid)*6364136223846793005 + 1442695040888963407
		nNodes := uint64(g.NodeCount())
		for k := 0; k < 4000; k++ {
			seed = seed*6364136223846793005 + 1442695040888963407
			id := NodeID(seed%nNodes) + 1
			if _, in := base[id]; in {
				continue
			}
			unreached++
			if engine.ArrivalAtBaseNode(lbl, id) <= T || engine.arrivalAtBaseNodeStored(stored, id) <= T {
				falseIn++
				if falseIn <= 3 {
					nd := g.Nodes[id]
					log.Printf("sweep FALSE-IN origin %d node %d (%.5f,%.5f)", o.Msgid, id, nd.Lat, nd.Lng)
				}
			}
		}

		mu.Lock()
		t.metChecked += metChecked
		t.metMissing += metMissing
		t.metDev += metDev
		t.queryMs += qMs
		t.baseMs += bMs
		for leaf := range lbl.Reached {
			touched[leaf] = struct{}{}
		}
		t.probes += probes
		t.flips += flips
		t.unreachedProbes += unreached
		t.falseIn += falseIn
		for _, m := range misms {
			d := math.Abs(float64(m.got - m.want))
			if m.stored {
				t.mismStored++
				if d > t.worstStored {
					t.worstStored = d
				}
			} else {
				t.mismLive++
				if d > t.worstLive {
					t.worstLive = d
				}
			}
			if t.mismLive+t.mismStored <= 6 {
				nd := g.Nodes[m.id]
				log.Printf("sweep MISMATCH (stored=%v) origin %d node %d (%.5f,%.5f): %.3f vs %.3f",
					m.stored, o.Msgid, m.id, nd.Lat, nd.Lng, m.got, m.want)
			}
		}
		t.origins++
		mu.Unlock()
	}

	// Fictional origins: deterministic pseudo-random coordinates over the UK
	// (sea and moorland included — they snap wherever a member would snap),
	// random chain-interior and junction origins, origins in sliver regions,
	// and a spread of budgets from 1 to 120 minutes.
	if fuzz > 0 {
		seed := uint64(20260827)
		next := func() uint64 { seed = seed*6364136223846793005 + 1442695040888963407; return seed }
		budgets := []float64{1, 3, 5, 10, 15, 20, 30, 30, 45, 45, 60}
		nNodes := uint64(g.NodeCount())
		for k := 0; k < fuzz; k++ {
			o := &sweepOrigin{Msgid: -1000000 - int64(k), synthetic: true}
			switch next() % 4 {
			case 0: // uniform UK box
				o.Lat = 49.9 + float64(next()%880000)/100000
				o.Lng = -7.6 + float64(next()%940000)/100000
			case 1: // random chain-interior node
				for {
					id := NodeID(next()%nNodes) + 1
					if engine.Ov.Idx[id] == 0 && engine.Ov.ChainEndA[id] != 0 {
						o.Lat, o.Lng = float64(g.Nodes[id].Lat), float64(g.Nodes[id].Lng)
						break
					}
				}
			case 2: // random junction
				for {
					id := NodeID(next()%nNodes) + 1
					if engine.Ov.Idx[id] != 0 {
						o.Lat, o.Lng = float64(g.Nodes[id].Lat), float64(g.Nodes[id].Lng)
						break
					}
				}
			default: // sliver region (2..500 junctions)
				for {
					leaf := int(next() % uint64(len(engine.Part.LeafNodes)))
					if n := len(engine.Part.LeafNodes[leaf]); n >= 2 && n <= 500 {
						oi := engine.Part.LeafNodes[leaf][int(next()%uint64(n))]
						nd := g.Nodes[engine.Ov.BaseNode[oi]]
						o.Lat, o.Lng = float64(nd.Lat), float64(nd.Lng)
						break
					}
				}
			}
			o.fuzzBudget = budgets[int(next()%uint64(len(budgets)))] * 60
			origins = append(origins, o)
		}
		// A few heavy long-range cases, run with everything else but rarer.
		for k := 0; k < 12; k++ {
			o := &sweepOrigin{Msgid: -2000000 - int64(k), synthetic: true}
			id := NodeID(next()%nNodes) + 1
			o.Lat, o.Lng = float64(g.Nodes[id].Lat), float64(g.Nodes[id].Lng)
			if k < 8 {
				o.fuzzBudget = 90 * 60
			} else {
				o.fuzzBudget = 120 * 60
			}
			origins = append(origins, o)
		}
		log.Printf("sweep: added %d fictional origins", fuzz+12)
	}

	// Parallel execution: origins are independent; the engine caches are
	// mutex-guarded. Concurrency bounded so heavy base searches cannot stack
	// unbounded scratch.
	sem := make(chan struct{}, 6)
	var wg sync.WaitGroup
	for _, o := range origins {
		wg.Add(1)
		sem <- struct{}{}
		go func(o *sweepOrigin) {
			defer wg.Done()
			defer func() { <-sem }()
			runOne(o)
		}(o)
	}
	wg.Wait()
	log.Printf("sweep: real+fictional done: %d origins, %d probes, %d live / %d stored mismatches, %d flips, %d/%d false-in; %d leaves touched",
		t.origins, t.probes, t.mismLive, t.mismStored, t.flips, t.falseIn, t.unreachedProbes, len(touched))

	// Synthetic origins: one per untouched sizable leaf, planted at a leaf
	// junction, so every populated region of the network gets exercised.
	type cand struct {
		leaf int32
		size int
	}
	var cands []cand
	for leaf, nodes := range engine.Part.LeafNodes {
		if _, ok := touched[int32(leaf)]; ok {
			continue
		}
		if len(nodes) < 500 {
			continue // tiny fragments: no realistic posts, nothing to learn
		}
		cands = append(cands, cand{int32(leaf), len(nodes)})
	}
	sort.Slice(cands, func(i, j int) bool { return cands[i].size > cands[j].size })
	if len(cands) > maxSynthetic {
		cands = cands[:maxSynthetic]
	}
	synth := 0
	for _, c := range cands {
		oi := engine.Part.LeafNodes[c.leaf][0]
		nd := g.Nodes[engine.Ov.BaseNode[oi]]
		o := &sweepOrigin{Msgid: -int64(c.leaf), Lat: float64(nd.Lat), Lng: float64(nd.Lng), synthetic: true}
		wg.Add(1)
		sem <- struct{}{}
		go func(o *sweepOrigin) {
			defer wg.Done()
			defer func() { <-sem }()
			runOne(o)
		}(o)
		synth++
	}
	wg.Wait()

	fmt.Printf("SWEEP TOTAL: %d origins (%d synthetic for %d untouched sizable regions), %d skipped, %d probes\n",
		t.origins, synth, len(cands), t.skipped, t.probes)
	fmt.Printf("  exactness: live mismatches %d (worst %.4fs), stored-roundtrip mismatches %d (worst %.4fs), membership flips %d\n",
		t.mismLive, t.worstLive, t.mismStored, t.worstStored, t.flips)
	fmt.Printf("  false membership: %d of %d probes on nodes the base search did not reach\n", t.falseIn, t.unreachedProbes)
	fmt.Printf("  road metres: %d checked, %d missing, %d tie deviations beyond max(150m, 5%%) (%.3f%%)\n",
		t.metChecked, t.metMissing, t.metDev, 100*float64(t.metDev)/float64(max(1, t.metChecked)))
	fmt.Printf("  coverage: %d/%d partition leaves exercised (sizable leaves only get synthetics)\n",
		len(touched), len(engine.Part.LeafNodes))
	fmt.Printf("  timing: query mean %.1fms, flat-Dijkstra mean %.0fms over %d origins\n",
		t.queryMs/float64(max(1, t.origins)), t.baseMs/float64(max(1, t.origins)), t.origins)
}

// reachNodeDebugRun prints the full arrival breakdown for one node from one
// origin, against the base-graph truth — for root-causing sweep mismatches.
func reachNodeDebugRun(lat, lng, minutes float64, target NodeID, engine *ReachEngine) {
	g, ov := engine.G, engine.Ov
	T := float32(minutes * 60)
	lbl := engine.QueryLabels(lat, lng, T)
	origin := nearestNodeForMode(g, lat, lng, Drive)
	dist, prevMap := baseDriveDijkstraPrev(g, origin, initialCostFor(Drive), T)

	fmt.Printf("origin snap base=%d (junction=%v)", origin, ov.Idx[origin] != 0)
	if ov.Idx[origin] == 0 {
		a, sa, _, b, sb, _ := chainDepartOffsets(g, ov, origin)
		fmt.Printf(" chain ends A=%d(+%.2f) B=%d(+%.2f)", a, sa, b, sb)
	}
	fmt.Println()
	for oi, s := range lbl.Seeds {
		fmt.Printf("seed oi=%d base=%d cost=%.2f leaf=%d\n", oi, ov.BaseNode[oi], s, engine.Part.LeafOf[oi])
	}
	want := dist[target]
	fmt.Printf("target base=%d true=%.3f\n", target, want)
	if oi := ov.Idx[target]; oi != 0 {
		fmt.Printf("  junction oi=%d leaf=%d originArr=%v\n", oi, engine.Part.LeafOf[oi], lbl.OriginArr[oi])
		fmt.Printf("  junctionArrival=%.3f seedArrival(stored path)=%.3f\n", engine.junctionArrival(lbl, target), engine.seedArrival(lbl, target))
	} else {
		a, b := ov.ChainEndA[target], ov.ChainEndB[target]
		fmt.Printf("  chain node: endA=%d offA=%.3f endB=%d offB=%.3f\n", a, ov.OffFromA[target], b, ov.OffFromB[target])
		if a != 0 {
			fmt.Printf("  arr(endA)=%.3f (+offA=%.3f) trueA=%.3f\n", engine.junctionArrival(lbl, a), engine.junctionArrival(lbl, a)+ov.OffFromA[target], dist[a])
		}
		if b != 0 {
			fmt.Printf("  arr(endB)=%.3f (+offB=%.3f) trueB=%.3f\n", engine.junctionArrival(lbl, b), engine.junctionArrival(lbl, b)+ov.OffFromB[target], dist[b])
		}
		if o := lbl.originChain; o != 0 {
			fmt.Printf("  originChain=%d sameChain=%v oA=%.3f vA=%.3f oB=%.3f vB=%.3f\n", o,
				ov.ChainEndA[o] == ov.ChainEndA[target] && ov.ChainEndB[o] == ov.ChainEndB[target],
				ov.OffFromA[o], ov.OffFromA[target], ov.OffFromB[o], ov.OffFromB[target])
		}
	}
	fmt.Printf("  engine live=%.3f\n", engine.ArrivalAtBaseNode(lbl, target))
	// True path tail (base space, last 25 hops).
	var path []NodeID
	for cur := target; ; {
		path = append(path, cur)
		pv, ok := prevMap[cur]
		if !ok {
			break
		}
		cur = pv
	}
	fmt.Printf("  true path %d hops (origin→target tail):\n", len(path))
	start := len(path) - 1
	if start > 24 {
		start = 24
	}
	for i := start; i >= 0; i-- {
		v := path[i]
		fmt.Printf("    base=%d oi=%d chainA/B=%d/%d true=%.3f\n", v, ov.Idx[v], ov.ChainEndA[v], ov.ChainEndB[v], dist[v])
	}
}
