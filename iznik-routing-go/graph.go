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
//   Oxford OX3 8GH → Highclere: ours 30 min, OSRM 40 min, Google 53 min
//   Newcastle NE1  → Alnwick:   ours 30 min, OSRM 40 min, Google 45 min
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

// driveSpeedFactorsByClass scales drive speeds per OSM highway class to match
// real-world UK driving speeds.  OSM speed limits alone are 30-40 % too
// optimistic vs reality because we don't model junctions, urban congestion or
// traffic.
//
// Calibrated from the DfT congestion statistics (updated 14 May 2026, data
// through Dec 2025):
//
//	Strategic Road Network (motorways + major A): 56.4 mph avg vs 70 mph limit → 0.81×
//	Local A-roads, urban:                         17.2 mph avg vs 30 mph limit → 0.57×
//	Local A-roads, rural:                         34.3 mph avg vs 60 mph limit → 0.57×
//
// Source:
//
//	https://www.gov.uk/government/statistical-data-sets/average-speed-delay-and-reliability-of-travel-times-cgn
//	Tables CGN0404 (SRN) and CGN0503 (local A-roads).
//
// The urban-vs-rural shortfall is the same proportion (0.57×) on local
// A-roads once normalised by speed limit — cities are slower because their
// limits are lower, not because they have extra congestion on top.  So no
// per-postcode density modifier is needed; per-road-class is enough.
//
// Residential/unclassified/service: not directly measured by DfT; set to 0.50
// as a conservative estimate (slower than A-roads due to side friction).
//
// ROUTING_DRIVE_SPEED_FACTOR env var still applies as a uniform extra
// multiplier on top of the per-class factor (1.0 = no extra scaling).
var driveSpeedFactorsByClass = map[string]float32{
	"motorway":      0.81,
	"motorway_link": 0.81,
	"trunk":         0.81,
	"trunk_link":    0.81,
	"primary":       0.57,
	"primary_link":  0.57,
	"secondary":     0.57,
	"secondary_link": 0.57,
	"tertiary":      0.57,
	"tertiary_link": 0.57,
	"residential":   0.50,
	"unclassified":  0.50,
	"living_street": 0.50,
	"service":       0.50,
	"track":         0.50,
}

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
// highway=* tag.  Falls back to 0.57 (A-road equivalent) for unknown classes.
func driveSpeedFactorFor(highwayTag string) float32 {
	if f, ok := driveSpeedFactorsByClass[highwayTag]; ok {
		return f * driveSpeedFactorOverride
	}
	return 0.57 * driveSpeedFactorOverride
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
		speeds, oneway := waySpeedsAndOneway(w)
		if speeds[0] < 0 && speeds[1] < 0 && speeds[2] < 0 {
			continue
		}
		refs := make([]int64, len(w.Nodes))
		for i, n := range w.Nodes {
			refs[i] = int64(n.ID)
			refSet[int64(n.ID)] = struct{}{}
		}
		ways = append(ways, wayRecord{refs, speeds, oneway})
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

	log.Printf("spatial-server: pass 2 (node coordinates, N=%d)", N)
	// ── Pass 2: populate node coordinates ─────────────────────────────────────
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
			tempEdges = append(tempEdges, tempEdge{from, to, secs})
			if !w.oneway {
				tempEdges = append(tempEdges, tempEdge{to, from, secs})
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

	return g, nil
}

// RawNodeSpec describes a node for BuildGraphFromRaw.
type RawNodeSpec struct {
	OSMID    int64
	Lat, Lng float64
}

// RawWaySpec describes a way for BuildGraphFromRaw.
// Highway must match a key in highwaySpeed (e.g. "residential", "motorway").
type RawWaySpec struct {
	NodeIDs []int64
	Highway string
	Oneway  bool
}

// BuildGraphFromRaw constructs a routing Graph from explicit node/way data.
// Equivalent to BuildGraph but reads from in-memory slices rather than an OSM
// PBF file. Used by tests and offline tooling.
func BuildGraphFromRaw(rawNodes []RawNodeSpec, rawWays []RawWaySpec, dep *DeprivationIndex) *Graph {
	N := len(rawNodes)

	osmToSeq := make(map[int64]NodeID, N)
	nodes := make([]Node, N+1) // [0] = unused sentinel
	for i, n := range rawNodes {
		id := NodeID(i + 1)
		osmToSeq[n.OSMID] = id
		nodes[id] = Node{Lat: float32(n.Lat), Lng: float32(n.Lng)}
	}

	if dep != nil {
		for i := NodeID(1); i <= NodeID(N); i++ {
			nd := &nodes[i]
			nd.Quintile = dep.Lookup(float64(nd.Lat), float64(nd.Lng))
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
		// Apply the DfT-calibrated per-road-class factor to match the
		// PBF path (waySpeedsAndOneway).  Without this, raw-built graphs
		// (tests + offline tooling) would diverge from production.
		if speeds[Drive] > 0 {
			speeds[Drive] *= driveSpeedFactorFor(w.Highway)
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
			tempEdges = append(tempEdges, tempEdge{from, to, secs})
			if !w.Oneway {
				tempEdges = append(tempEdges, tempEdge{to, from, secs})
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

	return g
}

// waySpeedsAndOneway returns per-mode speeds (m/s, -1 = unusable) and whether oneway.
func waySpeedsAndOneway(w *osm.Way) ([3]float32, bool) {
	tags := w.TagMap()
	highway := tags["highway"]
	speeds, ok := highwaySpeed[highway]
	if !ok {
		return [3]float32{-1, -1, -1}, false
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
		}
	}

	// Apply DfT-calibrated per-road-class factor at the end so it covers
	// both the highwaySpeed defaults and any maxspeed-tagged overrides.
	if speeds[Drive] > 0 {
		speeds[Drive] *= driveSpeedFactorFor(highway)
	}

	oneway := tags["oneway"] == "yes" || tags["oneway"] == "1"
	if tags["junction"] == "roundabout" {
		oneway = true
	}
	return speeds, oneway
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
