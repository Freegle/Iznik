package main

import (
	"context"
	"math"
	"os"

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

// NodeID is an OSM node ID.
type NodeID = osm.NodeID

// Node is a graph vertex.
type Node struct {
	Lat float64
	Lng float64
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

func (gr *Grid) get(lat, lng float64) []NodeID {
	key := [2]int16{int16(lat / gridRes), int16(lng / gridRes)}
	return gr.cells[key]
}

// Graph is an in-memory road network.
type Graph struct {
	Nodes map[NodeID]Node
	Edges map[NodeID][]Edge
	Grid  *Grid
}

// speed in m/s for each highway type × mode.
// -1 means the mode cannot use that highway type.
var highwaySpeed = map[string][3]float32{
	// walk m/s, cycle m/s, drive m/s
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

// BuildGraph reads an OSM PBF file and returns a routing graph.
func BuildGraph(pbfPath string) (*Graph, error) {
	f, err := os.Open(pbfPath)
	if err != nil {
		return nil, err
	}
	defer f.Close()

	g := &Graph{
		Nodes: make(map[NodeID]Node),
		Edges: make(map[NodeID][]Edge),
	}

	// First pass: collect node IDs referenced by routable ways.
	scanner := osmpbf.New(context.Background(), f, 4)
	scanner.SkipRelations = true

	routeNodeIDs := make(map[NodeID]bool)
	type wayData struct {
		nodes  []NodeID
		speeds [3]float32
		oneway bool
	}
	var ways []wayData

	for scanner.Scan() {
		obj := scanner.Object()
		switch o := obj.(type) {
		case *osm.Way:
			speeds, oneway := waySpeedsAndOneway(o)
			// skip ways with no usable mode
			if speeds[0] < 0 && speeds[1] < 0 && speeds[2] < 0 {
				continue
			}
			if len(o.Nodes) < 2 {
				continue
			}
			nodeRefs := make([]NodeID, len(o.Nodes))
			for i, n := range o.Nodes {
				nodeRefs[i] = n.ID
				routeNodeIDs[n.ID] = true
			}
			ways = append(ways, wayData{nodeRefs, speeds, oneway})
		}
	}
	if err := scanner.Err(); err != nil {
		return nil, err
	}

	// Second pass: collect lat/lng for referenced nodes.
	if _, err := f.Seek(0, 0); err != nil {
		return nil, err
	}
	scanner2 := osmpbf.New(context.Background(), f, 4)
	scanner2.SkipWays = true
	scanner2.SkipRelations = true

	for scanner2.Scan() {
		obj := scanner2.Object()
		if n, ok := obj.(*osm.Node); ok {
			if routeNodeIDs[n.ID] {
				g.Nodes[n.ID] = Node{Lat: n.Lat, Lng: n.Lon}
			}
		}
	}
	if err := scanner2.Err(); err != nil {
		return nil, err
	}

	// Build edges from ways.
	for _, w := range ways {
		for i := 0; i < len(w.nodes)-1; i++ {
			from := w.nodes[i]
			to := w.nodes[i+1]
			nFrom, ok1 := g.Nodes[from]
			nTo, ok2 := g.Nodes[to]
			if !ok1 || !ok2 {
				continue
			}
			dist := haversineM(nFrom.Lat, nFrom.Lng, nTo.Lat, nTo.Lng)
			var secs [3]float32
			for m := 0; m < 3; m++ {
				if w.speeds[m] < 0 {
					secs[m] = -1
				} else {
					secs[m] = float32(dist) / w.speeds[m]
				}
			}
			g.Edges[from] = append(g.Edges[from], Edge{To: to, Seconds: secs})
			if !w.oneway {
				g.Edges[to] = append(g.Edges[to], Edge{To: from, Seconds: secs})
			}
		}
	}

	// Build spatial grid for O(1) nearest-node lookup.
	g.Grid = newGrid()
	for id, n := range g.Nodes {
		g.Grid.add(n.Lat, n.Lng, id)
	}

	return g, nil
}

// waySpeedsAndOneway returns per-mode speeds (m/s, -1 = unusable) and whether oneway.
func waySpeedsAndOneway(w *osm.Way) ([3]float32, bool) {
	tags := w.TagMap()
	highway := tags["highway"]
	speeds, ok := highwaySpeed[highway]
	if !ok {
		return [3]float32{-1, -1, -1}, false
	}

	// Apply access overrides.
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

	// Driving speed from maxspeed tag.
	if ms := tags["maxspeed"]; ms != "" && speeds[Drive] > 0 {
		if s := parseMaxspeed(ms); s > 0 {
			speeds[Drive] = s
		}
	}

	oneway := tags["oneway"] == "yes" || tags["oneway"] == "1"
	if tags["junction"] == "roundabout" {
		oneway = true
	}

	return speeds, oneway
}

// parseMaxspeed parses maxspeed tag value into m/s. Returns 0 if unparseable.
func parseMaxspeed(s string) float32 {
	// Common UK values.
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

// haversineM returns the distance in metres between two lat/lng points.
func haversineM(lat1, lng1, lat2, lng2 float64) float64 {
	const R = 6371000.0
	dLat := (lat2 - lat1) * math.Pi / 180
	dLng := (lng2 - lng1) * math.Pi / 180
	a := math.Sin(dLat/2)*math.Sin(dLat/2) +
		math.Cos(lat1*math.Pi/180)*math.Cos(lat2*math.Pi/180)*
			math.Sin(dLng/2)*math.Sin(dLng/2)
	return R * 2 * math.Atan2(math.Sqrt(a), math.Sqrt(1-a))
}
