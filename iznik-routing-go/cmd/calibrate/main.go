// Command calibrate fits the drive-time model (per-class speed factors +
// junction/signal/roundabout penalties + fixed overhead) against ground-truth
// durations from the Google Routes API.
//
// It builds a drive-only graph from the OSM pbf that RETAINS per-edge road
// class and junction features (which the production graph discards), routes
// each origin-destination pair with A*, decomposes the route into per-class
// free-flow seconds and feature counts, and fits the parameters by weighted
// least squares.  Because route choice depends on the parameters, fitting
// iterates: route -> fit -> re-route, until the factors settle.
//
// Modes:
//
//	route: route all pairs under -params, write per-pair times + features
//	fit:   iterative fit against -google ground truth, write fitted params +
//	       per-pair predictions + train/holdout metrics per iteration
//
// Example:
//
//	calibrate -pbf data/uk-latest.osm.pbf -pairs pairs.json -mode route -params '{"factors":[0.81,0.81,0.57,0.57,0.57,0.5,0.5,0.5]}' -out routed.json
//	calibrate -pbf data/uk-latest.osm.pbf -pairs pairs.json -google google.jsonl -target static_s -mode fit -iters 4 -out fit.json
package main

import (
	"bufio"
	"container/heap"
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"math"
	"os"
	"runtime"
	"sort"
	"sync"

	"github.com/paulmach/osm"
	"github.com/paulmach/osm/osmpbf"
)

// ---------- class groups ----------

const nClasses = 8

// Speed features are per class x {untagged, tagged}: a maxspeed-tagged way's
// base speed is the legal limit, an untagged way's base is a conservative
// class default, so one shared factor cannot fit both.
const nSpeed = nClasses * 2

func speedFeature(class uint8, tagged bool) int {
	i := int(class) * 2
	if tagged {
		i++
	}
	return i
}

// factorCap bounds each speed factor: tagged ways cannot average above the
// limit (1.05 allows measurement slack); untagged ways cannot exceed the
// class's plausible legal ceiling over the conservative default base.
func factorCap(feature int) float64 {
	if feature%2 == 1 {
		return 1.05
	}
	caps := [nClasses]float64{31.3 / 27.8, 26.8 / 22.2, 26.8 / 13.9, 26.8 / 11.1, 26.8 / 8.3, 26.8 / 8.3, 13.4 / 8.3, 8.9 / 4.2}
	return caps[feature/2]
}

var classNames = [nClasses]string{
	"motorway", "trunk", "primary", "secondary", "tertiary", "unclassified", "residential", "service",
}

// classOf maps an OSM highway tag to a class group; -1 = not drivable.
func classOf(highway string) int {
	switch highway {
	case "motorway", "motorway_link":
		return 0
	case "trunk", "trunk_link":
		return 1
	case "primary", "primary_link":
		return 2
	case "secondary", "secondary_link":
		return 3
	case "tertiary", "tertiary_link":
		return 4
	case "unclassified":
		return 5
	case "residential", "living_street":
		return 6
	case "service":
		return 7
	}
	// track is NOT drivable in production (highwaySpeed sets Drive=-1), so the
	// harness must not route over it either or the fitted factors transfer to
	// a graph with different topology.
	return -1
}

// classDefaultSpeed mirrors highwaySpeed[...][Drive] in graph.go (m/s, unfactored).
func classDefaultSpeed(highway string) float32 {
	switch highway {
	case "motorway":
		return 27.8
	case "motorway_link", "trunk":
		return 22.2
	case "trunk_link":
		return 16.7
	case "primary":
		return 13.9
	case "primary_link", "secondary":
		return 11.1
	case "secondary_link", "tertiary", "tertiary_link", "unclassified", "residential":
		return 8.3
	case "living_street":
		return 2.8
	case "service":
		return 4.2
	}
	return -1
}

// parseMaxspeed replicates graph.go's parseMaxspeed exactly so that the
// harness reproduces production edge times when given production factors.
func parseMaxspeed(s string) float32 {
	switch s {
	case "20 mph", "20":
		return 8.9
	case "30 mph", "30":
		return 13.4
	case "40 mph", "40":
		return 17.9
	case "50 mph", "50":
		return 22.4
	case "60 mph", "60":
		return 26.8
	case "70 mph", "70", "national", "GB:national", "GB:motorway":
		return 31.3
	case "10 mph", "10":
		return 4.5
	case "5 mph", "5":
		return 2.2
	}
	return 0
}

// ---------- attributed graph ----------

const (
	flagRoundabout uint8 = 1 << iota // edge is part of a roundabout way
	flagToSignal                     // edge's to-node is highway=traffic_signals
	flagToMiniRbt                    // to-node is highway=mini_roundabout
	flagToCrossing                   // to-node is a pedestrian crossing (zebra / signals)
	flagToJunction                   // to-node is a way-based junction (set post-build)
	flagTagged                       // way had a parsed maxspeed tag (base speed = the limit)
)

type calEdge struct {
	To       uint32
	FreeSecs float32 // seconds at unfactored free-flow speed (maxspeed tag or class default)
	DistM    float32
	Class    uint8
	Flags    uint8
}

type calGraph struct {
	Lat, Lng  []float32
	EdgeStart []int32
	Edges     []calEdge
	grid      map[[2]int16][]uint32
}

func (g *calGraph) edgesFrom(id uint32) []calEdge {
	return g.Edges[g.EdgeStart[id]:g.EdgeStart[id+1]]
}

const gridRes = 0.01

func (g *calGraph) nearestNode(lat, lng float64, maxKm float64) uint32 {
	ci, cj := int16(lat/gridRes), int16(lng/gridRes)
	var best uint32
	bestD := maxKm * 1000
	rMax := int16(maxKm/1.0) + 1
	for r := int16(0); r <= rMax; r++ {
		if best != 0 && float64(r-1)*1000 > bestD {
			break
		}
		for i := ci - r; i <= ci+r; i++ {
			for j := cj - r; j <= cj+r; j++ {
				di, dj := i-ci, j-cj
				if max16(abs16(di), abs16(dj)) != r {
					continue
				}
				for _, id := range g.grid[[2]int16{i, j}] {
					d := haversineM(lat, lng, float64(g.Lat[id]), float64(g.Lng[id]))
					if d < bestD {
						bestD = d
						best = id
					}
				}
			}
		}
	}
	return best
}

func abs16(a int16) int16 {
	if a < 0 {
		return -a
	}
	return a
}
func max16(a, b int16) int16 {
	if a > b {
		return a
	}
	return b
}

func haversineM(lat1, lng1, lat2, lng2 float64) float64 {
	const R = 6371000.0
	dLat := (lat2 - lat1) * math.Pi / 180
	dLng := (lng2 - lng1) * math.Pi / 180
	a := math.Sin(dLat/2)*math.Sin(dLat/2) +
		math.Cos(lat1*math.Pi/180)*math.Cos(lat2*math.Pi/180)*math.Sin(dLng/2)*math.Sin(dLng/2)
	return R * 2 * math.Atan2(math.Sqrt(a), math.Sqrt(1-a))
}

// buildCalGraph builds the drive-only attributed graph from the pbf.
func buildCalGraph(pbfPath string) (*calGraph, error) {
	f, err := os.Open(pbfPath)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	type wayRec struct {
		refs   []int64
		free   float32 // unfactored free-flow m/s
		class  uint8
		oneway bool
		rbt    bool
		tagged bool
	}
	var ways []wayRec
	refSet := make(map[int64]struct{}, 40_000_000)

	log.Printf("calibrate: pass 1 (ways)")
	sc1 := osmpbf.New(context.Background(), f, runtime.NumCPU())
	sc1.SkipRelations = true
	sc1.SkipNodes = true
	for sc1.Scan() {
		w, ok := sc1.Object().(*osm.Way)
		if !ok || len(w.Nodes) < 2 {
			continue
		}
		tags := w.TagMap()
		highway := tags["highway"]
		cls := classOf(highway)
		if cls < 0 {
			continue
		}
		// Mirror production drivability rules (waySpeedsAndOneway).
		if tags["motor_vehicle"] == "no" || tags["vehicle"] == "no" || tags["access"] == "no" {
			continue
		}
		if tags["toll"] == "yes" { // production excludes tolls from car routing
			continue
		}
		free := classDefaultSpeed(highway)
		if free <= 0 {
			continue
		}
		tagged := false
		if ms := tags["maxspeed"]; ms != "" {
			if s := parseMaxspeed(ms); s > 0 {
				free = s
				tagged = true
			}
		}
		oneway := tags["oneway"] == "yes" || tags["oneway"] == "1"
		rbt := tags["junction"] == "roundabout"
		if rbt {
			oneway = true
		}
		refs := make([]int64, len(w.Nodes))
		for i, n := range w.Nodes {
			refs[i] = int64(n.ID)
			refSet[int64(n.ID)] = struct{}{}
		}
		ways = append(ways, wayRec{refs, free, uint8(cls), oneway, rbt, tagged})
	}
	if err := sc1.Err(); err != nil {
		return nil, err
	}
	log.Printf("calibrate: pass 1 done: %d drivable ways, %d node refs", len(ways), len(refSet))

	rawIDs := make([]int64, 0, len(refSet))
	for id := range refSet {
		rawIDs = append(rawIDs, id)
	}
	sort.Slice(rawIDs, func(i, j int) bool { return rawIDs[i] < rawIDs[j] })
	refSet = nil
	runtime.GC()
	nodeSeq := func(osmID int64) (uint32, bool) {
		i := sort.Search(len(rawIDs), func(j int) bool { return rawIDs[j] >= osmID })
		if i >= len(rawIDs) || rawIDs[i] != osmID {
			return 0, false
		}
		return uint32(i + 1), true
	}

	N := len(rawIDs)
	lat := make([]float32, N+1)
	lng := make([]float32, N+1)
	nodeFlag := make([]uint8, N+1) // signal / mini-rbt / crossing flags per node

	log.Printf("calibrate: pass 2 (node coords + tags, N=%d)", N)
	if _, err := f.Seek(0, 0); err != nil {
		return nil, err
	}
	sc2 := osmpbf.New(context.Background(), f, runtime.NumCPU())
	sc2.SkipWays = true
	sc2.SkipRelations = true
	for sc2.Scan() {
		nd, ok := sc2.Object().(*osm.Node)
		if !ok {
			continue
		}
		id, found := nodeSeq(int64(nd.ID))
		if !found {
			continue
		}
		lat[id] = float32(nd.Lat)
		lng[id] = float32(nd.Lon)
		for _, t := range nd.Tags {
			if t.Key == "highway" {
				switch t.Value {
				case "traffic_signals":
					nodeFlag[id] |= flagToSignal
				case "mini_roundabout":
					nodeFlag[id] |= flagToMiniRbt
				case "crossing":
					nodeFlag[id] |= flagToCrossing
				}
			}
		}
	}
	if err := sc2.Err(); err != nil {
		return nil, err
	}

	log.Printf("calibrate: building edges")
	type tempEdge struct {
		from uint32
		e    calEdge
	}
	var tempEdges []tempEdge
	for _, w := range ways {
		var wf uint8
		if w.rbt {
			wf |= flagRoundabout
		}
		if w.tagged {
			wf |= flagTagged
		}
		for i := 0; i < len(w.refs)-1; i++ {
			from, ok1 := nodeSeq(w.refs[i])
			to, ok2 := nodeSeq(w.refs[i+1])
			if !ok1 || !ok2 {
				continue
			}
			if lat[from] == 0 && lng[from] == 0 {
				continue
			}
			if lat[to] == 0 && lng[to] == 0 {
				continue
			}
			distM := float32(haversineM(float64(lat[from]), float64(lng[from]), float64(lat[to]), float64(lng[to])))
			fw := calEdge{To: to, DistM: distM, FreeSecs: distM / w.free, Class: w.class, Flags: wf | (nodeFlag[to] &^ flagRoundabout)}
			tempEdges = append(tempEdges, tempEdge{from, fw})
			if !w.oneway {
				bw := calEdge{To: from, DistM: distM, FreeSecs: distM / w.free, Class: w.class, Flags: wf | (nodeFlag[from] &^ flagRoundabout)}
				tempEdges = append(tempEdges, tempEdge{to, bw})
			}
		}
	}
	// Junction flag, way-based (cheap enough for production BuildGraph too, so
	// the fitted coefficient transfers exactly): a node is a junction if it is
	// referenced by >=3 drivable ways, or by 2 with at least one INTERIOR
	// reference (a T-junction).  Two ways merely sharing an endpoint is the
	// common way-segment boundary (tag change mid-road), not a junction.
	log.Printf("calibrate: junction detection (way-based)")
	junc := make([]bool, N+1)
	{
		wayRefCnt := make([]uint8, N+1)
		interior := make([]bool, N+1)
		for _, w := range ways {
			for i, ref := range w.refs {
				if id, ok := nodeSeq(ref); ok {
					if wayRefCnt[id] < 255 {
						wayRefCnt[id]++
					}
					if i > 0 && i < len(w.refs)-1 {
						interior[id] = true
					}
				}
			}
		}
		for id := uint32(1); id <= uint32(N); id++ {
			if wayRefCnt[id] >= 3 || (wayRefCnt[id] == 2 && interior[id]) {
				junc[id] = true
			}
		}
	}

	ways = nil
	rawIDs = nil
	runtime.GC()

	sort.Slice(tempEdges, func(i, j int) bool { return tempEdges[i].from < tempEdges[j].from })
	edgeStart := make([]int32, N+2)
	edges := make([]calEdge, len(tempEdges))
	{
		pos := 0
		for id := uint32(1); id <= uint32(N); id++ {
			edgeStart[id] = int32(pos)
			for pos < len(tempEdges) && tempEdges[pos].from == id {
				edges[pos] = tempEdges[pos].e
				pos++
			}
		}
		edgeStart[N+1] = int32(pos)
	}
	tempEdges = nil
	runtime.GC()

	g := &calGraph{Lat: lat, Lng: lng, EdgeStart: edgeStart, Edges: edges, grid: make(map[[2]int16][]uint32, 2_000_000)}

	nj := 0
	for id := uint32(1); id <= uint32(N); id++ {
		if junc[id] {
			nj++
		}
	}
	for i := range edges {
		if junc[edges[i].To] {
			edges[i].Flags |= flagToJunction
		}
	}
	log.Printf("calibrate: %d junction nodes", nj)

	// Connected components (undirected over drive edges).  Snapping ignores
	// nodes in tiny fragments (disconnected service loops, private estates) so
	// a postcode beside one doesn't become unroutable; genuine islands (Isle of
	// Wight etc.) are big components and stay snappable.
	const minCompSize = 1000
	compOK := make([]bool, N+1)
	{
		parent := make([]uint32, N+1)
		for i := range parent {
			parent[i] = uint32(i)
		}
		find := func(x uint32) uint32 {
			for parent[x] != x {
				parent[x] = parent[parent[x]]
				x = parent[x]
			}
			return x
		}
		for from := uint32(1); from <= uint32(N); from++ {
			for _, e := range g.edgesFrom(from) {
				ra, rb := find(from), find(e.To)
				if ra != rb {
					parent[ra] = rb
				}
			}
		}
		csize := make([]uint32, N+1)
		for id := uint32(1); id <= uint32(N); id++ {
			csize[find(id)]++
		}
		nOK, nFrag := 0, 0
		for id := uint32(1); id <= uint32(N); id++ {
			if csize[find(id)] >= minCompSize {
				compOK[id] = true
				nOK++
			} else {
				nFrag++
			}
		}
		log.Printf("calibrate: components: %d nodes in >=%d-node components, %d in fragments", nOK, minCompSize, nFrag)
	}

	for id := uint32(1); id <= uint32(N); id++ {
		if lat[id] == 0 && lng[id] == 0 {
			continue
		}
		if !compOK[id] {
			continue
		}
		key := [2]int16{int16(lat[id] / gridRes), int16(lng[id] / gridRes)}
		g.grid[key] = append(g.grid[key], id)
	}
	log.Printf("calibrate: graph ready: %d nodes, %d edges", N, len(edges))
	return g, nil
}

// ---------- parameters & routing ----------

type params struct {
	Factors [nSpeed]float64 `json:"factors"`
	PSignal float64         `json:"p_signal"`     // secs per traffic-signal node traversed
	PRbt    float64         `json:"p_roundabout"` // secs per roundabout/mini-rbt edge entered
	PJunc   float64         `json:"p_junction"`   // secs per >=3-way junction node traversed (non-SRN edge)
	PCross  float64         `json:"p_crossing"`   // secs per pedestrian-crossing node traversed
	C0      float64         `json:"c0"`           // fixed per-trip overhead secs
}

func prodParams() params {
	perClass := [nClasses]float64{0.81, 0.81, 0.57, 0.57, 0.57, 0.50, 0.50, 0.50}
	var f [nSpeed]float64
	for c := 0; c < nClasses; c++ {
		f[c*2] = perClass[c]
		f[c*2+1] = perClass[c]
	}
	return params{Factors: f}
}

// features of one routed path
type features struct {
	SpeedSecs [nSpeed]float64   `json:"speed_secs"` // free-flow secs per class x taggedness (unfactored)
	ClassDist [nClasses]float64 `json:"class_dist"` // metres per class
	NSignal   int               `json:"n_signal"`
	NRbt      int               `json:"n_rbt"`
	NJunc     int               `json:"n_junc"`
	NCross    int               `json:"n_cross"`
}

// edgeWeight computes the edge cost under params.
func edgeWeight(e *calEdge, p *params) float64 {
	w := float64(e.FreeSecs) / p.Factors[speedFeature(e.Class, e.Flags&flagTagged != 0)]
	if e.Flags&flagToSignal != 0 {
		w += p.PSignal
	}
	if e.Flags&(flagRoundabout|flagToMiniRbt) != 0 {
		// price roundabout entry on the first roundabout edge: approximated by
		// pricing every roundabout edge start; multiplicity is absorbed by the
		// fitted coefficient
		w += p.PRbt
	}
	if e.Flags&flagToJunction != 0 && e.Class >= 2 && e.Flags&flagToSignal == 0 && e.Flags&flagRoundabout == 0 {
		w += p.PJunc
	}
	if e.Flags&flagToCrossing != 0 {
		w += p.PCross
	}
	return w
}

// countsFor returns which penalty counters an edge increments (mirrors edgeWeight).
func countsFor(e *calEdge, ft *features) {
	ft.SpeedSecs[speedFeature(e.Class, e.Flags&flagTagged != 0)] += float64(e.FreeSecs)
	ft.ClassDist[e.Class] += float64(e.DistM)
	if e.Flags&flagToSignal != 0 {
		ft.NSignal++
	}
	if e.Flags&(flagRoundabout|flagToMiniRbt) != 0 {
		ft.NRbt++
	}
	if e.Flags&flagToJunction != 0 && e.Class >= 2 && e.Flags&flagToSignal == 0 && e.Flags&flagRoundabout == 0 {
		ft.NJunc++
	}
	if e.Flags&flagToCrossing != 0 {
		ft.NCross++
	}
}

type pqItem struct {
	id   uint32
	f, g float64
	idx  int
}
type pQ []*pqItem

func (q pQ) Len() int           { return len(q) }
func (q pQ) Less(i, j int) bool { return q[i].f < q[j].f }
func (q pQ) Swap(i, j int)      { q[i], q[j] = q[j], q[i]; q[i].idx = i; q[j].idx = j }
func (q *pQ) Push(x any)        { it := x.(*pqItem); it.idx = len(*q); *q = append(*q, it) }
func (q *pQ) Pop() any          { old := *q; n := len(old); it := old[n-1]; *q = old[:n-1]; return it }

// route runs A* (admissible heuristic: crow-fly at 32 m/s) from o to d.
// Returns total seconds, path features, road distance; ok=false if unreachable
// within budget.
func route(g *calGraph, p *params, oLat, oLng, dLat, dLng float64, budgetSecs float64) (float64, features, float64, bool) {
	origin := g.nearestNode(oLat, oLng, 2.0)
	dest := g.nearestNode(dLat, dLng, 2.0)
	var ft features
	if origin == 0 || dest == 0 || origin == dest {
		return 0, ft, 0, false
	}
	destLat, destLng := float64(g.Lat[dest]), float64(g.Lng[dest])
	h := func(id uint32) float64 {
		return haversineM(float64(g.Lat[id]), float64(g.Lng[id]), destLat, destLng) / 32.0
	}
	gScore := map[uint32]float64{origin: 0}
	prevNode := map[uint32]uint32{}
	prevEdge := map[uint32]int32{}
	q := &pQ{}
	heap.Push(q, &pqItem{id: origin, f: h(origin), g: 0})
	found := false
	for q.Len() > 0 {
		cur := heap.Pop(q).(*pqItem)
		if cur.g > gScore[cur.id] {
			continue
		}
		if cur.id == dest {
			found = true
			break
		}
		if cur.g > budgetSecs {
			break
		}
		start := g.EdgeStart[cur.id]
		for i, e := range g.edgesFrom(cur.id) {
			ng := cur.g + edgeWeight(&e, p)
			if old, seen := gScore[e.To]; !seen || ng < old {
				gScore[e.To] = ng
				prevNode[e.To] = cur.id
				prevEdge[e.To] = start + int32(i)
				heap.Push(q, &pqItem{id: e.To, g: ng, f: ng + h(e.To)})
			}
		}
	}
	if !found {
		return 0, ft, 0, false
	}
	// walk back, accumulate features
	distM := 0.0
	for at := dest; at != origin; {
		ei := prevEdge[at]
		e := &g.Edges[ei]
		countsFor(e, &ft)
		distM += float64(e.DistM)
		at = prevNode[at]
	}
	return gScore[dest], ft, distM, true
}

// ---------- IO types ----------

type pairRec struct {
	ID      int    `json:"id"`
	Stratum string `json:"stratum"`
	Pilot   bool   `json:"pilot"`
	Holdout bool   `json:"holdout"`
	O       struct {
		PC  string  `json:"pc"`
		Lat float64 `json:"lat"`
		Lng float64 `json:"lng"`
	} `json:"o"`
	D struct {
		PC  string  `json:"pc"`
		Lat float64 `json:"lat"`
		Lng float64 `json:"lng"`
	} `json:"d"`
	CrowKM  float64 `json:"crow_km"`
	Density string  `json:"density"`
}

type googleRec struct {
	ID      int    `json:"id"`
	Flavour string `json:"flavour"`
	OK      bool   `json:"ok"`
	DurS    *int   `json:"dur_s"`
	StaticS *int   `json:"static_s"`
	DistM   *int   `json:"dist_m"`
}

type routedRec struct {
	ID    int      `json:"id"`
	Secs  float64  `json:"secs"`
	DistM float64  `json:"dist_m"`
	OK    bool     `json:"ok"`
	Feat  features `json:"feat"`
	PredS float64  `json:"pred_s,omitempty"`
	GoogS float64  `json:"goog_s,omitempty"`
	GoogM float64  `json:"goog_dist_m,omitempty"`
	Excl  bool     `json:"excluded,omitempty"`
}

// ---------- fitting ----------

// designRow builds the regression row for a routed pair.
// beta layout: [c0, invF0..invF15, pSig, pRbt, pJunc, pCross]
const nBeta = 1 + nSpeed + 4

func designRow(ft *features) []float64 {
	x := make([]float64, nBeta)
	x[0] = 1
	for c := 0; c < nSpeed; c++ {
		x[1+c] = ft.SpeedSecs[c]
	}
	x[1+nSpeed+0] = float64(ft.NSignal)
	x[1+nSpeed+1] = float64(ft.NRbt)
	x[1+nSpeed+2] = float64(ft.NJunc)
	x[1+nSpeed+3] = float64(ft.NCross)
	return x
}

func betaToParams(beta []float64) params {
	var p params
	p.C0 = beta[0]
	for c := 0; c < nSpeed; c++ {
		inv := beta[1+c]
		if inv < 1e-9 {
			inv = 1e-9
		}
		f := 1 / inv
		if cap := factorCap(c); f > cap {
			f = cap
		}
		if f < 0.25 {
			f = 0.25
		}
		p.Factors[c] = f
	}
	p.PSignal = beta[1+nSpeed+0]
	p.PRbt = beta[1+nSpeed+1]
	p.PJunc = beta[1+nSpeed+2]
	p.PCross = beta[1+nSpeed+3]
	return p
}

// solveWLS solves min sum w_i (x_i . beta - y_i)^2 subject to beta >= lb
// (simple active-set: clamp violators to bound, re-solve rest).
func solveWLS(X [][]float64, y, w []float64, lb []float64) []float64 {
	n := nBeta
	fixed := make([]bool, n)
	fixedVal := make([]float64, n)
	for iter := 0; iter < 25; iter++ {
		// build normal equations over free params
		freeIdx := []int{}
		for j := 0; j < n; j++ {
			if !fixed[j] {
				freeIdx = append(freeIdx, j)
			}
		}
		m := len(freeIdx)
		A := make([][]float64, m)
		b := make([]float64, m)
		for i := range A {
			A[i] = make([]float64, m)
		}
		for r := range X {
			// residual target after removing fixed contributions
			yr := y[r]
			for j := 0; j < n; j++ {
				if fixed[j] {
					yr -= X[r][j] * fixedVal[j]
				}
			}
			for a := 0; a < m; a++ {
				xa := X[r][freeIdx[a]]
				if xa == 0 {
					continue
				}
				wxa := w[r] * xa
				b[a] += wxa * yr
				for c := a; c < m; c++ {
					A[a][c] += wxa * X[r][freeIdx[c]]
				}
			}
		}
		for a := 0; a < m; a++ {
			for c := 0; c < a; c++ {
				A[a][c] = A[c][a]
			}
			A[a][a] += 1e-8 // ridge for stability
		}
		sol := gauss(A, b)
		beta := make([]float64, n)
		for j := 0; j < n; j++ {
			if fixed[j] {
				beta[j] = fixedVal[j]
			}
		}
		for a, j := range freeIdx {
			beta[j] = sol[a]
		}
		// clamp violators
		violated := false
		for j := 0; j < n; j++ {
			if !fixed[j] && beta[j] < lb[j] {
				fixed[j] = true
				fixedVal[j] = lb[j]
				violated = true
			}
		}
		if !violated {
			return beta
		}
	}
	// give up: return all-clamped solution
	out := make([]float64, n)
	for j := 0; j < n; j++ {
		if fixed[j] {
			out[j] = fixedVal[j]
		} else {
			out[j] = lb[j]
		}
	}
	return out
}

func gauss(A [][]float64, b []float64) []float64 {
	n := len(b)
	for i := 0; i < n; i++ {
		// partial pivot
		p := i
		for r := i + 1; r < n; r++ {
			if math.Abs(A[r][i]) > math.Abs(A[p][i]) {
				p = r
			}
		}
		A[i], A[p] = A[p], A[i]
		b[i], b[p] = b[p], b[i]
		if math.Abs(A[i][i]) < 1e-12 {
			continue
		}
		for r := i + 1; r < n; r++ {
			f := A[r][i] / A[i][i]
			for c := i; c < n; c++ {
				A[r][c] -= f * A[i][c]
			}
			b[r] -= f * b[i]
		}
	}
	x := make([]float64, n)
	for i := n - 1; i >= 0; i-- {
		if math.Abs(A[i][i]) < 1e-12 {
			x[i] = 0
			continue
		}
		s := b[i]
		for c := i + 1; c < n; c++ {
			s -= A[i][c] * x[c]
		}
		x[i] = s / A[i][i]
	}
	return x
}

// ---------- metrics ----------

type metrics struct {
	N      int     `json:"n"`
	MAPE   float64 `json:"mape"`    // mean abs pct error
	MedAPE float64 `json:"med_ape"` // median abs pct error
	P90APE float64 `json:"p90_ape"`
	Bias   float64 `json:"bias"` // mean (pred-goog)/goog
	RMSEs  float64 `json:"rmse_s"`
}

func computeMetrics(pred, goog []float64) metrics {
	var m metrics
	apes := []float64{}
	var sumApe, sumBias, sumSq float64
	for i := range pred {
		e := pred[i] - goog[i]
		ape := math.Abs(e) / goog[i]
		apes = append(apes, ape)
		sumApe += ape
		sumBias += e / goog[i]
		sumSq += e * e
	}
	n := len(apes)
	if n == 0 {
		return m
	}
	sort.Float64s(apes)
	m.N = n
	m.MAPE = sumApe / float64(n)
	m.MedAPE = apes[n/2]
	m.P90APE = apes[(n*9)/10]
	m.Bias = sumBias / float64(n)
	m.RMSEs = math.Sqrt(sumSq / float64(n))
	return m
}

// ---------- main ----------

func main() {
	pbf := flag.String("pbf", "data/uk-latest.osm.pbf", "OSM pbf path")
	pairsPath := flag.String("pairs", "pairs.json", "pairs JSON")
	googlePath := flag.String("google", "", "google results JSONL")
	flavour := flag.String("flavour", "traffic", "google flavour to use")
	target := flag.String("target", "dur_s", "target field: dur_s or static_s")
	mode := flag.String("mode", "route", "route | fit")
	paramsJSON := flag.String("params", "", "params JSON (default production)")
	iters := flag.Int("iters", 4, "fit iterations")
	out := flag.String("out", "out.json", "output path")
	trimPct := flag.Float64("trim", 0.02, "fraction of worst residuals excluded from fit")
	weightFloorS := flag.Float64("weightfloor", 0, "floor (seconds) applied to y in the 1/y^2 WLS weight; 0 = pure relative weighting.  A floor stops a handful of very short trips dominating the intercept-like coefficients (c0, penalties)")
	flag.Parse()

	var pairs []pairRec
	pb, err := os.ReadFile(*pairsPath)
	if err != nil {
		log.Fatal(err)
	}
	if err := json.Unmarshal(pb, &pairs); err != nil {
		log.Fatal(err)
	}

	google := map[int]googleRec{}
	if *googlePath != "" {
		f, err := os.Open(*googlePath)
		if err != nil {
			log.Fatal(err)
		}
		sc := bufio.NewScanner(f)
		sc.Buffer(make([]byte, 1024*1024), 1024*1024)
		for sc.Scan() {
			var r googleRec
			if json.Unmarshal(sc.Bytes(), &r) == nil && r.OK && r.Flavour == *flavour {
				google[r.ID] = r
			}
		}
		f.Close()
		log.Printf("calibrate: %d google records (%s)", len(google), *flavour)
	}

	p := prodParams()
	if *paramsJSON != "" {
		if err := json.Unmarshal([]byte(*paramsJSON), &p); err != nil {
			log.Fatal(err)
		}
	}

	g, err := buildCalGraph(*pbf)
	if err != nil {
		log.Fatal(err)
	}

	googTarget := func(gr googleRec) (float64, bool) {
		var v *int
		if *target == "static_s" {
			v = gr.StaticS
		} else {
			v = gr.DurS
		}
		if v == nil || *v <= 0 {
			return 0, false
		}
		return float64(*v), true
	}

	routeAll := func(pp *params) []routedRec {
		recs := make([]routedRec, len(pairs))
		var wg sync.WaitGroup
		idx := make(chan int, len(pairs))
		for i := range pairs {
			idx <- i
		}
		close(idx)
		workers := runtime.NumCPU()
		wg.Add(workers)
		for w := 0; w < workers; w++ {
			go func() {
				defer wg.Done()
				for i := range idx {
					pr := pairs[i]
					secs, ft, distM, ok := route(g, pp, pr.O.Lat, pr.O.Lng, pr.D.Lat, pr.D.Lng, 3*3600)
					recs[i] = routedRec{ID: pr.ID, Secs: secs, DistM: distM, OK: ok, Feat: ft}
					if gr, has := google[pr.ID]; has {
						if gs, gok := googTarget(gr); gok {
							recs[i].GoogS = gs
							if gr.DistM != nil {
								recs[i].GoogM = float64(*gr.DistM)
							}
						}
					}
				}
			}()
		}
		wg.Wait()
		return recs
	}

	switch *mode {
	case "route":
		recs := routeAll(&p)
		writeJSON(*out, map[string]any{"params": p, "routed": recs})
		nOK := 0
		for _, r := range recs {
			if r.OK {
				nOK++
			}
		}
		log.Printf("calibrate: routed %d/%d", nOK, len(recs))

	case "fit":
		holdoutSet := map[int]bool{}
		for _, pr := range pairs {
			if pr.Holdout {
				holdoutSet[pr.ID] = true
			}
		}
		type iterInfo struct {
			Params  params  `json:"params"`
			Train   metrics `json:"train"`
			Holdout metrics `json:"holdout"`
			NTrim   int     `json:"n_trimmed"`
		}
		var history []iterInfo
		cur := p
		for it := 0; it < *iters; it++ {
			recs := routeAll(&cur)

			// assemble train set
			type row struct {
				x []float64
				y float64
				i int
			}
			var rows []row
			nDivergent := 0
			for i := range recs {
				r := &recs[i]
				if !r.OK || r.GoogS <= 0 || holdoutSet[r.ID] {
					continue
				}
				// Exclude topology-divergent rows from FITTING: where our route is a
				// fundamentally different shape to Google's (toll exclusion forcing an
				// estuary detour, ferries), no speed factor can reconcile them, and
				// letting them in poisons the factors.  They still count in metrics.
				if r.GoogM > 0 {
					ratio := r.DistM / r.GoogM
					if ratio > 1.4 || ratio < 0.6 {
						r.Excl = true
						nDivergent++
						continue
					}
				}
				rows = append(rows, row{designRow(&r.Feat), r.GoogS, i})
			}
			if nDivergent > 0 {
				log.Printf("iter %d: %d topology-divergent rows excluded from fit", it, nDivergent)
			}
			// trim worst residuals under CURRENT params
			nTrimmed := 0
			if *trimPct > 0 && len(rows) > 50 {
				type resid struct {
					ape float64
					k   int
				}
				rs := make([]resid, len(rows))
				for k, rw := range rows {
					pred := recs[rw.i].Secs + cur.C0 // route time under current params + fixed overhead
					rs[k] = resid{math.Abs(pred-rw.y) / rw.y, k}
				}
				sort.Slice(rs, func(a, b int) bool { return rs[a].ape > rs[b].ape })
				nTrim := int(float64(len(rows)) * *trimPct)
				nTrimmed = nTrim
				drop := map[int]bool{}
				for _, r := range rs[:nTrim] {
					drop[r.k] = true
					recs[rows[r.k].i].Excl = true
				}
				var kept []row
				for k, rw := range rows {
					if !drop[k] {
						kept = append(kept, rw)
					}
				}
				rows = kept
			}
			X := make([][]float64, len(rows))
			y := make([]float64, len(rows))
			w := make([]float64, len(rows))
			for k, rw := range rows {
				X[k] = rw.x
				y[k] = rw.y
				wy := rw.y
				if *weightFloorS > 0 && wy < *weightFloorS {
					wy = *weightFloorS
				}
				w[k] = 1 / (wy * wy) // relative-error weighting (floored)
			}
			lb := make([]float64, nBeta)
			lb[0] = 0
			for c := 0; c < nSpeed; c++ {
				lb[1+c] = 1 / factorCap(c)
			}
			beta := solveWLS(X, y, w, lb)
			next := betaToParams(beta)
			// metrics under the NEW params require re-routing; approximate the
			// iteration metrics with a linear prediction on the fitted rows, and
			// report exact metrics next iteration (or after the loop).
			var trPred, trGoog, hoPred, hoGoog []float64
			for i := range recs {
				r := &recs[i]
				if !r.OK || r.GoogS <= 0 {
					continue
				}
				xr := designRow(&r.Feat)
				pred := 0.0
				pred += beta[0]
				for c := 0; c < nSpeed; c++ {
					pred += xr[1+c] / next.Factors[c]
				}
				pred += xr[1+nSpeed+0]*next.PSignal + xr[1+nSpeed+1]*next.PRbt + xr[1+nSpeed+2]*next.PJunc + xr[1+nSpeed+3]*next.PCross
				r.PredS = pred
				if holdoutSet[r.ID] {
					hoPred = append(hoPred, pred)
					hoGoog = append(hoGoog, r.GoogS)
				} else if !r.Excl {
					trPred = append(trPred, pred)
					trGoog = append(trGoog, r.GoogS)
				}
			}
			info := iterInfo{Params: next, Train: computeMetrics(trPred, trGoog), Holdout: computeMetrics(hoPred, hoGoog), NTrim: nTrimmed}
			history = append(history, info)
			log.Printf("iter %d: factors=%v c0=%.1f pSig=%.1f pRbt=%.1f pJunc=%.1f pCross=%.2f | train MAPE %.1f%% med %.1f%% | holdout MAPE %.1f%% med %.1f%% bias %+.1f%%",
				it, fmtFactors(next.Factors), next.C0, next.PSignal, next.PRbt, next.PJunc, next.PCross,
				info.Train.MAPE*100, info.Train.MedAPE*100, info.Holdout.MAPE*100, info.Holdout.MedAPE*100, info.Holdout.Bias*100)
			cur = next
		}
		// final exact evaluation: re-route with final params
		finalRecs := routeAll(&cur)
		var trPred, trGoog, hoPred, hoGoog []float64
		for i := range finalRecs {
			r := &finalRecs[i]
			r.PredS = r.Secs + cur.C0
			if !r.OK || r.GoogS <= 0 {
				continue
			}
			if holdoutSet[r.ID] {
				hoPred = append(hoPred, r.PredS)
				hoGoog = append(hoGoog, r.GoogS)
			} else {
				trPred = append(trPred, r.PredS)
				trGoog = append(trGoog, r.GoogS)
			}
		}
		final := map[string]any{
			"history":       history,
			"final_params":  cur,
			"final_train":   computeMetrics(trPred, trGoog),
			"final_holdout": computeMetrics(hoPred, hoGoog),
			"routed":        finalRecs,
		}
		writeJSON(*out, final)
		log.Printf("FINAL exact: train MAPE %.1f%% | holdout MAPE %.1f%% med %.1f%%",
			computeMetrics(trPred, trGoog).MAPE*100, computeMetrics(hoPred, hoGoog).MAPE*100, computeMetrics(hoPred, hoGoog).MedAPE*100)

	default:
		log.Fatalf("unknown mode %s", *mode)
	}
}

func fmtFactors(f [nSpeed]float64) string {
	s := "["
	for i, v := range f {
		if i > 0 {
			s += " "
		}
		suffix := "u"
		if i%2 == 1 {
			suffix = "t"
		}
		s += fmt.Sprintf("%s.%s=%.2f", classNames[i/2][:4], suffix, v)
	}
	return s + "]"
}

func writeJSON(path string, v any) {
	f, err := os.Create(path)
	if err != nil {
		log.Fatal(err)
	}
	enc := json.NewEncoder(f)
	if err := enc.Encode(v); err != nil {
		log.Fatal(err)
	}
	f.Close()
}
