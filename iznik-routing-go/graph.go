package main

import (
	"context"
	"log"
	"math"
	"os"
	"runtime"
	"sort"
	"strconv"

	"github.com/paulmach/osm"
	"github.com/paulmach/osm/osmpbf"
)

// Mode is a transport mode.
type Mode int

const (
	Walk  Mode = iota
	Cycle Mode = iota
	Drive Mode = iota
)

// NodeID is a sequential 1-based node index (0 = noNode sentinel).
type NodeID = uint32

// noNode is the zero value meaning "no node found".
const noNode NodeID = 0

// Node is a graph vertex with geographic coordinates and deprivation quintile.
type Node struct {
	Lat, Lng float32
	Quintile Quintile
}

// Edge is a directed graph edge.
type Edge struct {
	To      NodeID
	Seconds [3]float32 // walk, cycle, drive travel time in seconds; -1 = not usable
}

// gridRes is the grid cell size in degrees (~1km per cell at UK latitudes).
const gridRes = 0.01

// Grid is a 2D spatial index for fast nearest-node lookup.
type Grid struct {
	cells map[[2]int16][]NodeID
}

func newGrid() *Grid {
	return &Grid{cells: make(map[[2]int16][]NodeID, 200_000)}
}

func (gr *Grid) add(lat, lng float64, id NodeID) {
	key := [2]int16{int16(lat / gridRes), int16(lng / gridRes)}
	gr.cells[key] = append(gr.cells[key], id)
}

// Graph is an in-memory road network in CSR format.
// Nodes are 1-indexed; index 0 is an unused sentinel.
type Graph struct {
	Nodes       []Node  // Nodes[id] = node at sequential ID (1-indexed)
	EdgeStart   []int32 // EdgeStart[id] = start index in Edges for node id
	Edges       []Edge  // flat edge list in CSR order
	Grid        *Grid
	Deprivation *DeprivationIndex
	// DriveSnappable[id] is false for nodes in tiny disconnected drive
	// fragments (marina loops, private estates): drive-mode snapping skips
	// them so a postcode beside one doesn't become unroutable.  Genuine
	// islands are big components and stay snappable.  nil = no filtering.
	DriveSnappable []bool
}

// NodeCount returns the number of valid nodes (excluding sentinel at index 0).
func (g *Graph) NodeCount() int { return len(g.Nodes) - 1 }

// EdgesFrom returns the edges outgoing from node id.
func (g *Graph) EdgesFrom(id NodeID) []Edge {
	return g.Edges[g.EdgeStart[id]:g.EdgeStart[id+1]]
}

// speed in m/s for each highway type × mode; -1 = mode cannot use this type.
// These are roughly the OSM speed-limit-derived free-flow values.  Mature OSM
// routers (OSRM, ORS, GraphHopper) multiply these by a factor to approximate
// realistic average speeds (which include junctions, urban congestion etc).
//
// Spot-check vs public OSRM demo + Google Maps for two ~30-mile UK routes:
//
//	Oxford OX3 8GH → Highclere: ours 30 min, OSRM 40 min, Google 53 min
//	Newcastle NE1  → Alnwick:   ours 30 min, OSRM 40 min, Google 45 min
//
// So our free-flow times are ~33% optimistic vs OSRM and ~50-77% vs Google.
// Set ROUTING_DRIVE_SPEED_FACTOR (default 1.0) to apply a global multiplier
// to drive speeds at graph build time.  0.7 brings us roughly in line with
// OSRM.  0.6 would approximate Google with typical UK traffic.
var highwaySpeed = func() map[string][3]float32 {
	base := map[string][3]float32{
		"motorway":       {-1, -1, 27.8},
		"motorway_link":  {-1, -1, 22.2},
		"trunk":          {-1, -1, 22.2},
		"trunk_link":     {-1, -1, 16.7},
		"primary":        {1.4, 4.2, 13.9},
		"primary_link":   {1.4, 4.2, 11.1},
		"secondary":      {1.4, 4.2, 11.1},
		"secondary_link": {1.4, 4.2, 8.3},
		"tertiary":       {1.4, 4.2, 8.3},
		"tertiary_link":  {1.4, 4.2, 8.3},
		"unclassified":   {1.4, 4.2, 8.3},
		"residential":    {1.4, 3.0, 8.3},
		"living_street":  {1.4, 2.8, 2.8},
		"service":        {1.4, 2.8, 4.2},
		"pedestrian":     {1.4, 1.4, -1},
		"footway":        {1.4, -1, -1},
		"path":           {1.4, 1.4, -1},
		"cycleway":       {1.4, 5.6, -1},
		"steps":          {0.5, -1, -1},
		"track":          {1.2, 2.8, -1},
	}
	// Factor is applied later in waySpeedsAndOneway() so it also affects
	// maxspeed-tagged ways (which would otherwise bypass the defaults).
	return base
}()

// ─── Drive-time calibration ──────────────────────────────────────────────────
//
// The drive-time model separates LINK SPEED from NODE DELAY:
//
//	edge drive secs = distM / (base speed × class factor)  +  junction penalties
//
// where the base speed is the parsed maxspeed tag when present (else the
// class default from highwaySpeed), and the penalties price traffic signals,
// junctions, crossings and roundabouts individually.  A fixed per-trip
// startup overhead (driveStartupSecs) covers pulling out / local manoeuvring
// and is seeded into the origin cost of every drive Dijkstra.
//
// Parameters were fitted against ~2,500 Google Routes API journeys sampled
// across the whole UK (stratified: population-weighted, area-uniform, city
// cores, London, sparse rural, estuary crossings; Tuesday 10:30 departures,
// traffic-aware), using cmd/calibrate, which decomposes each routed path into
// per-class free-flow seconds + feature counts, fits by weighted least
// squares (relative-error weighting with an 8-minute floor so a handful of
// very short trips cannot dominate the intercept-like coefficients),
// re-routes under the new parameters and repeats until stable.
// On the 30% holdout never used for fitting, the median abs error fell from
// 16.3% to 7.4% and the mean abs error from 28.7% to 13.6% (n=770).
//
// The previous DfT-derived blanket factors (0.81 SRN / 0.57 A-road / 0.50
// residential) crammed junction delay into link speed, which over-penalised
// rural roads (few junctions per km) by ~35% and still under-penalised dense
// cities.  Splitting link speed from node delay fixes both ends at once.
//
// Factors are per class × {untagged, tagged} because a maxspeed-tagged way's
// base is the legal limit while an untagged way's base is a conservative
// default; one shared factor cannot fit both.  Tagged factors are capped at
// 1.05 (you cannot average above the limit); untagged factors are capped at
// the class's plausible legal ceiling over its default base.
//
// To recalibrate (e.g. after major OSM or traffic shifts): see
// cmd/calibrate/main.go — sample pairs, collect Google ground truth, fit,
// then transcribe the fitted values here and in drivePenalties /
// driveStartupSecs.
//
// ROUTING_DRIVE_SPEED_FACTOR still applies as a uniform extra multiplier on
// top of the per-class factor (1.0 = no extra scaling).

// driveClassFactors[tag] = {factor without maxspeed tag, factor with tag}.
// Untagged factors above 1.0 mean the class's default base speed was too
// conservative (an untagged rural A-road really averages ~41 mph, not the
// 31 mph the 13.9 m/s default implies); tagged factors below 1.0 are the
// fraction of the posted limit achieved.  The untagged motorway factor is
// pinned to the tagged value rather than fitted: untagged motorways carry
// 0.04% of sampled drive time, far too thin to fit.
var driveClassFactors = map[string][2]float32{
	"motorway":       {0.79, 0.79},
	"motorway_link":  {0.79, 0.79},
	"trunk":          {0.91, 0.74},
	"trunk_link":     {0.91, 0.74},
	"primary":        {1.29, 0.74},
	"primary_link":   {1.29, 0.74},
	"secondary":      {1.44, 0.71},
	"secondary_link": {1.44, 0.71},
	"tertiary":       {1.59, 0.72},
	"tertiary_link":  {1.59, 0.72},
	"unclassified":   {1.27, 0.46},
	"residential":    {0.69, 0.73},
	"living_street":  {0.69, 0.73},
	"service":        {1.81, 1.00},
}

// (highway=track has no entry: highwaySpeed marks it undrivable, so a factor
// for it would be dead code.  service's TAGGED factor is pinned to 1.00
// rather than fitted: tagged service ways carry 0.2% of sampled drive time,
// too thin to fit, and the unconstrained fit ran into the 1.05 cap.)

// driveFallbackFactor is used for unrecognised highway classes (treated as
// A-road equivalent).
var driveFallbackFactor = [2]float32{1.29, 0.74}

// drivePenalties are fixed seconds added to the DRIVE time of an edge whose
// to-node carries the feature (paid on arrival at the node, whichever
// direction it is approached from), or, for Roundabout, on each edge of a
// junction=roundabout way / into a mini_roundabout node.  The Junction
// penalty applies at nodes shared by >=3 drivable ways (or 2 with an interior
// reference: a T-junction), except on motorway/trunk (grade-separated) and
// where a signal already prices the delay.
var drivePenalties = struct {
	Signal, Crossing, Roundabout, Junction float32
}{Signal: 7.7, Crossing: 3.4, Roundabout: 0.1, Junction: 0.6}

// driveStartupSecs is the fixed per-trip drive overhead, seeded as the origin
// cost in every drive-mode Dijkstra so that isochrones, ripple ticks and
// drive_min all include it consistently.
const driveStartupSecs float32 = 57

// driveSpeedFactorOverride is an optional uniform multiplier applied on top
// of the per-class factor.  Default 1.0 (no extra scaling); set
// ROUTING_DRIVE_SPEED_FACTOR=0.95 etc. to globally tweak.
var driveSpeedFactorOverride = func() float32 {
	factor := float32(1.0)
	if s := os.Getenv("ROUTING_DRIVE_SPEED_FACTOR"); s != "" {
		if v, err := strconv.ParseFloat(s, 32); err == nil && v > 0 && v <= 2 {
			factor = float32(v)
		}
	}
	return factor
}()

// driveSpeedFactorFor returns the calibrated factor for a given OSM
// highway=* tag, depending on whether a maxspeed tag was parsed for the way.
func driveSpeedFactorFor(highwayTag string, maxspeedTagged bool) float32 {
	pair, ok := driveClassFactors[highwayTag]
	if !ok {
		pair = driveFallbackFactor
	}
	if maxspeedTagged {
		return pair[1] * driveSpeedFactorOverride
	}
	return pair[0] * driveSpeedFactorOverride
}

// srnHighway reports whether a highway tag is part of the strategic road
// network (grade-separated: exempt from the junction penalty).
func srnHighway(h string) bool {
	switch h {
	case "motorway", "motorway_link", "trunk", "trunk_link":
		return true
	}
	return false
}

// Node feature flags collected at build time (not stored on the runtime
// graph; penalties are folded into edge seconds).
const (
	nodeFlagSignal uint8 = 1 << iota
	nodeFlagMiniRoundabout
	nodeFlagCrossing
	nodeFlagJunction
)

// nodeFlagForTag maps a node-level highway=* tag to its feature flag (0 = none).
func nodeFlagForTag(v string) uint8 {
	switch v {
	case "traffic_signals":
		return nodeFlagSignal
	case "mini_roundabout":
		return nodeFlagMiniRoundabout
	case "crossing":
		return nodeFlagCrossing
	}
	return 0
}

// drivePenaltySecs returns the fixed seconds to add to a drive edge that
// travels into a node with toFlags, along a way of the given highway class
// (roundaboutWay = junction=roundabout).  Mirrors cmd/calibrate exactly —
// the fitted coefficients only transfer if the feature definitions match.
func drivePenaltySecs(highwayTag string, roundaboutWay bool, toFlags uint8) float32 {
	var p float32
	if toFlags&nodeFlagSignal != 0 {
		p += drivePenalties.Signal
	}
	if roundaboutWay || toFlags&nodeFlagMiniRoundabout != 0 {
		p += drivePenalties.Roundabout
	}
	if toFlags&nodeFlagJunction != 0 && !srnHighway(highwayTag) &&
		toFlags&nodeFlagSignal == 0 && !roundaboutWay {
		p += drivePenalties.Junction
	}
	if toFlags&nodeFlagCrossing != 0 {
		p += drivePenalties.Crossing
	}
	return p
}

// BuildGraph reads an OSM PBF file and returns a compact routing graph.
// dep is optional; if non-nil, each node's Quintile is populated from it.
func BuildGraph(pbfPath string, dep *DeprivationIndex) (*Graph, error) {
	f, err := os.Open(pbfPath)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	// ── Pass 1: collect routable ways and the OSM node IDs they reference ─────
	log.Printf("spatial-server: pass 1 (ways scan)")
	type wayRecord struct {
		nodeOSMIDs []int64
		speeds     [3]float32
		oneway     bool
		info       wayDriveInfo
	}
	var ways []wayRecord
	refSet := make(map[int64]struct{}, 65_000_000)

	sc1 := osmpbf.New(context.Background(), f, 4)
	sc1.SkipRelations = true
	for sc1.Scan() {
		w, ok := sc1.Object().(*osm.Way)
		if !ok || len(w.Nodes) < 2 {
			continue
		}
		speeds, oneway, info := waySpeedsAndOneway(w)
		if speeds[0] < 0 && speeds[1] < 0 && speeds[2] < 0 {
			continue
		}
		refs := make([]int64, len(w.Nodes))
		for i, n := range w.Nodes {
			refs[i] = int64(n.ID)
			refSet[int64(n.ID)] = struct{}{}
		}
		ways = append(ways, wayRecord{refs, speeds, oneway, info})
	}
	if err := sc1.Err(); err != nil {
		return nil, err
	}

	log.Printf("spatial-server: pass 1 done: %d ways, %d unique node refs", len(ways), len(refSet))

	// Replace the hash set with a sorted slice for O(log n) lookups.
	rawIDs := make([]int64, 0, len(refSet))
	for id := range refSet {
		rawIDs = append(rawIDs, id)
	}
	sort.Slice(rawIDs, func(i, j int) bool { return rawIDs[i] < rawIDs[j] })
	refSet = nil
	runtime.GC()

	// nodeSeq converts an OSM node ID to a 1-based sequential NodeID.
	nodeSeq := func(osmID int64) (NodeID, bool) {
		i := sort.Search(len(rawIDs), func(j int) bool { return rawIDs[j] >= osmID })
		if i >= len(rawIDs) || rawIDs[i] != osmID {
			return noNode, false
		}
		return NodeID(i + 1), true
	}

	N := len(rawIDs)
	nodes := make([]Node, N+1) // [0] = unused sentinel

	// Junction detection, way-based: a node is a junction if it is referenced
	// by >=3 drivable ways, or by 2 with at least one INTERIOR reference (a
	// T-junction).  Two ways merely sharing an endpoint is the common
	// way-segment boundary (a tag change mid-road), not a junction.
	nodeFlags := make([]uint8, N+1)
	{
		wayRefCnt := make([]uint8, N+1)
		interior := make([]bool, N+1)
		for _, w := range ways {
			if w.speeds[Drive] < 0 {
				continue
			}
			for i, ref := range w.nodeOSMIDs {
				if id, found := nodeSeq(ref); found {
					if wayRefCnt[id] < 255 {
						wayRefCnt[id]++
					}
					if i > 0 && i < len(w.nodeOSMIDs)-1 {
						interior[id] = true
					}
				}
			}
		}
		for id := NodeID(1); id <= NodeID(N); id++ {
			if wayRefCnt[id] >= 3 || (wayRefCnt[id] == 2 && interior[id]) {
				nodeFlags[id] |= nodeFlagJunction
			}
		}
	}

	log.Printf("spatial-server: pass 2 (node coordinates, N=%d)", N)
	// ── Pass 2: populate node coordinates + junction-feature tags ─────────────
	if _, err := f.Seek(0, 0); err != nil {
		return nil, err
	}
	sc2 := osmpbf.New(context.Background(), f, 4)
	sc2.SkipWays = true
	sc2.SkipRelations = true
	for sc2.Scan() {
		nd, ok := sc2.Object().(*osm.Node)
		if !ok {
			continue
		}
		id, found := nodeSeq(int64(nd.ID))
		if found {
			nodes[id] = Node{Lat: float32(nd.Lat), Lng: float32(nd.Lon)}
			for _, t := range nd.Tags {
				if t.Key == "highway" {
					nodeFlags[id] |= nodeFlagForTag(t.Value)
				}
			}
		}
	}
	if err := sc2.Err(); err != nil {
		return nil, err
	}

	// Assign deprivation quintiles.
	if dep != nil {
		for i := NodeID(1); i <= NodeID(N); i++ {
			nd := &nodes[i]
			if nd.Lat != 0 || nd.Lng != 0 {
				nd.Quintile = dep.Lookup(float64(nd.Lat), float64(nd.Lng))
			}
		}
	}

	log.Printf("spatial-server: building edges")
	// ── Build flat edge list ───────────────────────────────────────────────────
	type tempEdge struct {
		from, to NodeID
		secs     [3]float32
	}
	var tempEdges []tempEdge
	for _, w := range ways {
		for i := 0; i < len(w.nodeOSMIDs)-1; i++ {
			from, ok1 := nodeSeq(w.nodeOSMIDs[i])
			to, ok2 := nodeSeq(w.nodeOSMIDs[i+1])
			if !ok1 || !ok2 {
				continue
			}
			nf, nt := nodes[from], nodes[to]
			if nf.Lat == 0 && nf.Lng == 0 {
				continue
			}
			if nt.Lat == 0 && nt.Lng == 0 {
				continue
			}
			distM := haversineM(float64(nf.Lat), float64(nf.Lng), float64(nt.Lat), float64(nt.Lng))
			var secs [3]float32
			for m := 0; m < 3; m++ {
				if w.speeds[m] < 0 {
					secs[m] = -1
				} else {
					secs[m] = float32(distM) / w.speeds[m]
				}
			}
			fwd := secs
			if fwd[Drive] >= 0 {
				fwd[Drive] += drivePenaltySecs(w.info.Highway, w.info.Roundabout, nodeFlags[to])
			}
			tempEdges = append(tempEdges, tempEdge{from, to, fwd})
			if !w.oneway {
				bwd := secs
				if bwd[Drive] >= 0 {
					bwd[Drive] += drivePenaltySecs(w.info.Highway, w.info.Roundabout, nodeFlags[from])
				}
				tempEdges = append(tempEdges, tempEdge{to, from, bwd})
			}
		}
	}
	ways = nil
	rawIDs = nil
	runtime.GC()

	// Sort edges by source for CSR construction.
	sort.Slice(tempEdges, func(i, j int) bool { return tempEdges[i].from < tempEdges[j].from })

	// Build CSR: EdgeStart[id] is the index in Edges of the first edge from node id.
	edgeStart := make([]int32, N+2)
	edges := make([]Edge, len(tempEdges))
	{
		pos := 0
		for id := NodeID(1); id <= NodeID(N); id++ {
			edgeStart[id] = int32(pos)
			for pos < len(tempEdges) && tempEdges[pos].from == id {
				edges[pos] = Edge{To: tempEdges[pos].to, Seconds: tempEdges[pos].secs}
				pos++
			}
		}
		edgeStart[N+1] = int32(pos)
	}
	tempEdges = nil
	runtime.GC()

	g := &Graph{
		Nodes:       nodes,
		EdgeStart:   edgeStart,
		Edges:       edges,
		Deprivation: dep,
	}

	// Build spatial grid using two-pass construction to avoid GC pressure
	// from millions of small append-induced slice reallocations.
	log.Printf("spatial-server: building spatial grid")
	g.Grid = &Grid{cells: make(map[[2]int16][]NodeID, 600_000)}
	// Pass A: count nodes per cell.
	cellCount := make(map[[2]int16]int32, 600_000)
	for i := NodeID(1); i <= NodeID(N); i++ {
		nd := nodes[i]
		if nd.Lat != 0 || nd.Lng != 0 {
			key := [2]int16{int16(nd.Lat / gridRes), int16(nd.Lng / gridRes)}
			cellCount[key]++
		}
	}
	// Pre-allocate each cell slice to its exact size.
	for key, cnt := range cellCount {
		g.Grid.cells[key] = make([]NodeID, 0, cnt)
	}
	cellCount = nil
	// Pass B: fill node IDs.
	for i := NodeID(1); i <= NodeID(N); i++ {
		nd := nodes[i]
		if nd.Lat != 0 || nd.Lng != 0 {
			key := [2]int16{int16(nd.Lat / gridRes), int16(nd.Lng / gridRes)}
			g.Grid.cells[key] = append(g.Grid.cells[key], i)
		}
	}
	log.Printf("spatial-server: grid built (%d cells)", len(g.Grid.cells))

	g.DriveSnappable = computeDriveSnappable(g)

	return g, nil
}

// minDriveComponentNodes is the component-size threshold below which nodes
// are treated as fragments for drive snapping.  The effective threshold is
// min(this, size of the largest drive component) so small raw-built graphs
// (tests, tooling) still behave sensibly.  On the UK pbf ~1.1% of drive
// nodes sit in sub-1000-node fragments; real islands are far larger.
const minDriveComponentNodes = 1000

// computeDriveSnappable unions nodes over drive-usable edges (undirected) and
// marks nodes in components of at least min(minDriveComponentNodes, largest)
// nodes as snappable for drive routing.
func computeDriveSnappable(g *Graph) []bool {
	n := len(g.Nodes) - 1
	if n <= 0 {
		return nil
	}
	parent := make([]NodeID, n+1)
	for i := range parent {
		parent[i] = NodeID(i)
	}
	find := func(x NodeID) NodeID {
		for parent[x] != x {
			parent[x] = parent[parent[x]]
			x = parent[x]
		}
		return x
	}
	for from := NodeID(1); from <= NodeID(n); from++ {
		for _, e := range g.EdgesFrom(from) {
			if e.Seconds[Drive] < 0 {
				continue
			}
			ra, rb := find(from), find(e.To)
			if ra != rb {
				parent[ra] = rb
			}
		}
	}
	size := make([]int32, n+1)
	largest := int32(0)
	for id := NodeID(1); id <= NodeID(n); id++ {
		r := find(id)
		size[r]++
		if size[r] > largest {
			largest = size[r]
		}
	}
	threshold := int32(minDriveComponentNodes)
	if largest < threshold {
		threshold = largest
	}
	ok := make([]bool, n+1)
	nFrag := 0
	for id := NodeID(1); id <= NodeID(n); id++ {
		if size[find(id)] >= threshold {
			ok[id] = true
		} else if hasEdgeForMode(g, id, Drive) {
			// Only count real drive nodes in the log: walk-only nodes are
			// singleton "components" here and were never drive-snappable.
			nFrag++
		}
	}
	if nFrag > 0 {
		log.Printf("spatial-server: %d drive nodes in fragments below %d nodes (excluded from drive snapping)", nFrag, threshold)
	}
	return ok
}

// RawNodeSpec describes a node for BuildGraphFromRaw.
// Highway optionally carries a node-level highway=* tag ("traffic_signals",
// "mini_roundabout", "crossing") mirroring OSM node tags.
type RawNodeSpec struct {
	OSMID    int64
	Lat, Lng float64
	Highway  string
}

// RawWaySpec describes a way for BuildGraphFromRaw.
// Highway must match a key in highwaySpeed (e.g. "residential", "motorway").
// Junction set to "roundabout" mirrors the OSM junction=roundabout tag
// (implies oneway, and each edge carries the roundabout penalty).
type RawWaySpec struct {
	NodeIDs  []int64
	Highway  string
	Oneway   bool
	Junction string
}

// BuildGraphFromRaw constructs a routing Graph from explicit node/way data.
// Equivalent to BuildGraph but reads from in-memory slices rather than an OSM
// PBF file. Used by tests and offline tooling.
func BuildGraphFromRaw(rawNodes []RawNodeSpec, rawWays []RawWaySpec, dep *DeprivationIndex) *Graph {
	N := len(rawNodes)

	osmToSeq := make(map[int64]NodeID, N)
	nodes := make([]Node, N+1) // [0] = unused sentinel
	nodeFlags := make([]uint8, N+1)
	for i, n := range rawNodes {
		id := NodeID(i + 1)
		osmToSeq[n.OSMID] = id
		nodes[id] = Node{Lat: float32(n.Lat), Lng: float32(n.Lng)}
		if n.Highway != "" {
			nodeFlags[id] |= nodeFlagForTag(n.Highway)
		}
	}

	if dep != nil {
		for i := NodeID(1); i <= NodeID(N); i++ {
			nd := &nodes[i]
			nd.Quintile = dep.Lookup(float64(nd.Lat), float64(nd.Lng))
		}
	}

	// Way-based junction detection over drivable ways, mirroring BuildGraph.
	{
		wayRefCnt := make([]uint8, N+1)
		interior := make([]bool, N+1)
		for _, w := range rawWays {
			speeds, ok := highwaySpeed[w.Highway]
			if !ok || speeds[Drive] < 0 {
				continue
			}
			for i, ref := range w.NodeIDs {
				if id, found := osmToSeq[ref]; found {
					if wayRefCnt[id] < 255 {
						wayRefCnt[id]++
					}
					if i > 0 && i < len(w.NodeIDs)-1 {
						interior[id] = true
					}
				}
			}
		}
		for id := NodeID(1); id <= NodeID(N); id++ {
			if wayRefCnt[id] >= 3 || (wayRefCnt[id] == 2 && interior[id]) {
				nodeFlags[id] |= nodeFlagJunction
			}
		}
	}

	type tempEdge struct {
		from, to NodeID
		secs     [3]float32
	}
	var tempEdges []tempEdge
	for _, w := range rawWays {
		speeds, ok := highwaySpeed[w.Highway]
		if !ok {
			continue
		}
		roundabout := w.Junction == "roundabout"
		oneway := w.Oneway || roundabout
		// Apply the calibrated per-road-class factor to match the PBF path
		// (waySpeedsAndOneway).  Without this, raw-built graphs (tests +
		// offline tooling) would diverge from production.  RawWaySpec carries
		// no maxspeed, so the untagged factor applies.
		if speeds[Drive] > 0 {
			speeds[Drive] *= driveSpeedFactorFor(w.Highway, false)
		}
		for i := 0; i < len(w.NodeIDs)-1; i++ {
			from, ok1 := osmToSeq[w.NodeIDs[i]]
			to, ok2 := osmToSeq[w.NodeIDs[i+1]]
			if !ok1 || !ok2 {
				continue
			}
			nf, nt := nodes[from], nodes[to]
			distM := haversineM(float64(nf.Lat), float64(nf.Lng), float64(nt.Lat), float64(nt.Lng))
			var secs [3]float32
			for m := 0; m < 3; m++ {
				if speeds[m] < 0 {
					secs[m] = -1
				} else {
					secs[m] = float32(distM) / speeds[m]
				}
			}
			fwd := secs
			if fwd[Drive] >= 0 {
				fwd[Drive] += drivePenaltySecs(w.Highway, roundabout, nodeFlags[to])
			}
			tempEdges = append(tempEdges, tempEdge{from, to, fwd})
			if !oneway {
				bwd := secs
				if bwd[Drive] >= 0 {
					bwd[Drive] += drivePenaltySecs(w.Highway, roundabout, nodeFlags[from])
				}
				tempEdges = append(tempEdges, tempEdge{to, from, bwd})
			}
		}
	}

	sort.Slice(tempEdges, func(i, j int) bool { return tempEdges[i].from < tempEdges[j].from })

	edgeStart := make([]int32, N+2)
	edges := make([]Edge, len(tempEdges))
	{
		pos := 0
		for id := NodeID(1); id <= NodeID(N); id++ {
			edgeStart[id] = int32(pos)
			for pos < len(tempEdges) && tempEdges[pos].from == id {
				edges[pos] = Edge{To: tempEdges[pos].to, Seconds: tempEdges[pos].secs}
				pos++
			}
		}
		edgeStart[N+1] = int32(pos)
	}

	g := &Graph{
		Nodes:       nodes,
		EdgeStart:   edgeStart,
		Edges:       edges,
		Deprivation: dep,
	}

	g.Grid = newGrid()
	for i := NodeID(1); i <= NodeID(N); i++ {
		nd := nodes[i]
		if nd.Lat != 0 || nd.Lng != 0 {
			g.Grid.add(float64(nd.Lat), float64(nd.Lng), i)
		}
	}

	g.DriveSnappable = computeDriveSnappable(g)

	return g
}

// wayDriveInfo carries the way-level facts the drive-time model needs beyond
// raw speeds: the highway class (for the junction-penalty SRN exemption),
// whether the way is a roundabout, and whether a maxspeed tag was parsed
// (which selects the tagged-vs-untagged speed factor).
type wayDriveInfo struct {
	Highway        string
	Roundabout     bool
	MaxspeedTagged bool
}

// waySpeedsAndOneway returns per-mode speeds (m/s, -1 = unusable), whether
// oneway, and the drive info for the way.
func waySpeedsAndOneway(w *osm.Way) ([3]float32, bool, wayDriveInfo) {
	tags := w.TagMap()
	highway := tags["highway"]
	info := wayDriveInfo{Highway: highway}
	speeds, ok := highwaySpeed[highway]
	if !ok {
		return [3]float32{-1, -1, -1}, false, info
	}

	if tags["foot"] == "no" {
		speeds[Walk] = -1
	}
	if tags["foot"] == "yes" || tags["foot"] == "designated" || tags["foot"] == "permissive" {
		if speeds[Walk] < 0 {
			speeds[Walk] = 1.4
		}
	}
	if tags["bicycle"] == "no" {
		speeds[Cycle] = -1
	}
	if tags["bicycle"] == "yes" || tags["bicycle"] == "designated" {
		if speeds[Cycle] < 0 {
			speeds[Cycle] = 4.2
		}
	}
	if tags["motor_vehicle"] == "no" || tags["vehicle"] == "no" || tags["access"] == "no" {
		speeds[Drive] = -1
	}
	// Exclude toll roads from car routing entirely (the Humber/Dartford/Mersey/Tyne
	// crossings, the M6 Toll, etc.). OSM tags the tolled carriageway toll=yes; the free
	// foot/cycle ways alongside are separate, untagged ways, so this drops only the car
	// edge and leaves walking/cycling routes intact.
	if tags["toll"] == "yes" {
		speeds[Drive] = -1
	}
	if ms := tags["maxspeed"]; ms != "" && speeds[Drive] > 0 {
		if s := parseMaxspeed(ms); s > 0 {
			speeds[Drive] = s
			info.MaxspeedTagged = true
		}
	}

	// Apply the calibrated per-road-class factor at the end so it covers
	// both the highwaySpeed defaults and any maxspeed-tagged overrides.
	if speeds[Drive] > 0 {
		speeds[Drive] *= driveSpeedFactorFor(highway, info.MaxspeedTagged)
	}

	oneway := tags["oneway"] == "yes" || tags["oneway"] == "1"
	if tags["junction"] == "roundabout" {
		oneway = true
		info.Roundabout = true
	}
	return speeds, oneway, info
}

// parseMaxspeed parses a maxspeed tag value into m/s. Returns 0 if unparseable.
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

// haversineM returns the great-circle distance in metres between two lat/lng points.
func haversineM(lat1, lng1, lat2, lng2 float64) float64 {
	const R = 6371000.0
	dLat := (lat2 - lat1) * math.Pi / 180
	dLng := (lng2 - lng1) * math.Pi / 180
	a := math.Sin(dLat/2)*math.Sin(dLat/2) +
		math.Cos(lat1*math.Pi/180)*math.Cos(lat2*math.Pi/180)*
			math.Sin(dLng/2)*math.Sin(dLng/2)
	return R * 2 * math.Atan2(math.Sqrt(a), math.Sqrt(1-a))
}
