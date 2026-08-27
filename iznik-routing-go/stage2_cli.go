package main

// Stage 2 prototype CLI: `go run . stage2 <cmd>`. Not reachable in server
// deployments (the server starts only when no stage2 arg is given), so this
// carries no production risk while the prototype gate is evaluated.

import (
	"flag"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"runtime"
	"sort"
)

const stage2SnapPath = "data/stage2/graph.snap"

func stage2Main(args []string) {
	if len(args) == 0 {
		fmt.Fprintln(os.Stderr, "usage: stage2 <build|stats|partition|matrices|query|parity> [args]")
		os.Exit(2)
	}
	switch args[0] {
	case "build":
		stage2Build()
	case "stats":
		stage2Stats()
	case "partition":
		stage2PartitionCmd(args[1:])
	case "matrices":
		stage2MatricesCmd(args[1:])
	case "query":
		stage2QueryCmd(args[1:])
	case "leafcheck":
		stage2LeafCheckCmd(args[1:])
	case "tracepath":
		stage2TracePathCmd(args[1:])
	case "boundarydebug":
		stage2BoundaryDebugCmd(args[1:])
	case "exactdebug":
		stage2ExactDebugCmd(args[1:])
	case "graphmem":
		stage2GraphMemCmd()
	case "nodedebug":
		stage2NodeDebugCmd(args[1:])
	case "sweep":
		stage2SweepCmd(args[1:])
	case "parity":
		stage2ParityCmd(args[1:])
	default:
		fmt.Fprintf(os.Stderr, "unknown stage2 command %q\n", args[0])
		os.Exit(2)
	}
}

// stage2LoadOrBuild returns the graph+overlay, from snapshot when present.
func stage2LoadOrBuild() (*Graph, *Overlay) {
	if _, err := os.Stat(stage2SnapPath); err == nil {
		g, ov, err := LoadStage2Snapshot(stage2SnapPath)
		if err == nil {
			return g, ov
		}
		log.Printf("stage2: snapshot load failed (%v), rebuilding", err)
	}
	pbf := getenv("OSM_PBF_PATH", "data/uk-latest.osm.pbf")
	var dep *DeprivationIndex
	if path := getenv("DEPRIVATION_CSV", ""); path != "" {
		dep = LoadDeprivation(path)
	}
	g, err := BuildGraph(pbf, dep)
	if err != nil {
		log.Fatalf("stage2: BuildGraph: %v", err)
	}
	ov := BuildOverlay(g)
	if err := os.MkdirAll(filepath.Dir(stage2SnapPath), 0o755); err != nil {
		log.Fatalf("stage2: mkdir: %v", err)
	}
	if err := SaveStage2Snapshot(stage2SnapPath, g, ov); err != nil {
		log.Fatalf("stage2: snapshot save: %v", err)
	}
	return g, ov
}

func stage2Build() {
	g, ov := stage2LoadOrBuild()
	printOverlayStats(g, ov)
}

func stage2Stats() {
	g, ov := stage2LoadOrBuild()
	printOverlayStats(g, ov)
}

func printOverlayStats(g *Graph, ov *Overlay) {
	n := g.NodeCount()
	on := ov.NodeCount()

	// Drive-usable overlay subgraph size (what the partitioner works on).
	driveJunctions := 0
	driveEdges := 0
	for oi := uint32(1); oi <= uint32(on); oi++ {
		has := false
		for _, e := range ov.EdgesFrom(oi) {
			if e.Seconds[Drive] >= 0 {
				driveEdges++
				has = true
			}
		}
		if has {
			driveJunctions++
		}
	}

	absorbed := 0
	for v := NodeID(1); v <= NodeID(n); v++ {
		if ov.ChainEndA[v] != 0 {
			absorbed++
		}
	}

	// Chain edge length distribution (drive seconds).
	var secs []float64
	for _, e := range ov.Edges {
		if e.Seconds[Drive] >= 0 {
			secs = append(secs, float64(e.Seconds[Drive]))
		}
	}
	sort.Float64s(secs)
	pct := func(p float64) float64 {
		if len(secs) == 0 {
			return 0
		}
		i := int(p * float64(len(secs)-1))
		return secs[i]
	}

	fmt.Printf("stage2 stats:\n")
	fmt.Printf("  base:    %d nodes / %d directed edges\n", n, len(g.Edges))
	fmt.Printf("  overlay: %d junctions / %d chain edges (%.1fx node, %.1fx edge reduction)\n",
		on, len(ov.Edges), float64(n)/float64(on), float64(len(g.Edges))/float64(len(ov.Edges)))
	fmt.Printf("  drive subgraph: %d junctions / %d directed chain edges\n", driveJunctions, driveEdges)
	fmt.Printf("  absorbed chain nodes: %d (%.1f%% of base)\n", absorbed, 100*float64(absorbed)/float64(n))
	fmt.Printf("  drive chain secs p50/p90/p99/max: %.1f / %.1f / %.1f / %.1f\n",
		pct(0.50), pct(0.90), pct(0.99), pct(1.0))
}

func stage2PartitionCmd(args []string) {
	fs := flag.NewFlagSet("partition", flag.ExitOnError)
	leaf := fs.Int("leaf", 10000, "maximum junctions per leaf region")
	alpha := fs.Float64("alpha", 0.25, "inertial-flow source/sink fraction")
	_ = fs.Parse(args)
	stage2PartitionRun(*leaf, *alpha)
}

func stage2MatricesCmd(args []string) {
	stage2MatricesRun()
}

func stage2QueryCmd(args []string) {
	fs := flag.NewFlagSet("query", flag.ExitOnError)
	lat := fs.Float64("lat", 51.4545, "origin latitude")
	lng := fs.Float64("lng", -2.5879, "origin longitude")
	minutes := fs.Float64("minutes", 30, "drive-time budget in minutes")
	_ = fs.Parse(args)
	stage2QueryRun(*lat, *lng, *minutes)
}

func stage2ParityCmd(args []string) {
	fs := flag.NewFlagSet("parity", flag.ExitOnError)
	dir := fs.String("dir", "data/stage2/prod", "directory of exported prod posts")
	_ = fs.Parse(args)
	stage2ParityRun(*dir, stage2LoadEngine())
}

func stage2ExactDebugCmd(args []string) {
	fs := flag.NewFlagSet("exactdebug", flag.ExitOnError)
	path := fs.String("json", "", "path to one exported post .json")
	_ = fs.Parse(args)
	stage2ExactDebugRun(*path, stage2LoadEngine())
}

func stage2BoundaryDebugCmd(args []string) {
	fs := flag.NewFlagSet("boundarydebug", flag.ExitOnError)
	path := fs.String("json", "", "path to one exported post .json")
	_ = fs.Parse(args)
	stage2BoundaryDebugRun(*path, stage2LoadEngine())
}

func stage2TracePathCmd(args []string) {
	fs := flag.NewFlagSet("tracepath", flag.ExitOnError)
	path := fs.String("json", "", "post json")
	node := fs.Uint64("node", 0, "target base node id")
	_ = fs.Parse(args)
	stage2TracePathRun(*path, NodeID(*node), stage2LoadEngine())
}

func stage2LeafCheckCmd(args []string) {
	fs := flag.NewFlagSet("leafcheck", flag.ExitOnError)
	path := fs.String("json", "", "post json")
	node := fs.Uint64("node", 0, "target base node id")
	_ = fs.Parse(args)
	stage2LeafCheckRun(*path, NodeID(*node), stage2LoadEngine())
}

func stage2SweepCmd(args []string) {
	fs := flag.NewFlagSet("sweep", flag.ExitOnError)
	file := fs.String("file", "data/stage2/sweep.jsonl", "jsonl of exported origins")
	synth := fs.Int("synthetic", 400, "max synthetic origins for untouched regions")
	_ = fs.Parse(args)
	stage2SweepRun(*file, *synth, stage2LoadEngine())
}

func stage2NodeDebugCmd(args []string) {
	fs := flag.NewFlagSet("nodedebug", flag.ExitOnError)
	lat := fs.Float64("lat", 0, "origin lat")
	lng := fs.Float64("lng", 0, "origin lng")
	minutes := fs.Float64("minutes", 30, "budget minutes")
	node := fs.Uint64("node", 0, "target base node")
	_ = fs.Parse(args)
	stage2NodeDebugRun(*lat, *lng, *minutes, NodeID(*node), stage2LoadEngine())
}

// stage2GraphMemCmd measures the base graph's resident heap alone — the
// like-for-like baseline for what the routing server holds today.
func stage2GraphMemCmd() {
	g, ov := stage2LoadOrBuild()
	ov.BaseNode, ov.Idx, ov.EdgeStart, ov.Edges = nil, nil, nil, nil
	ov.ChainEndA, ov.ChainEndB, ov.OffFromA, ov.OffFromB = nil, nil, nil, nil
	var ms runtime.MemStats
	runtime.GC()
	runtime.ReadMemStats(&ms)
	fmt.Printf("base graph only: heap %.2fGB (sys %.2fGB); nodes %d edges %d\n",
		float64(ms.HeapAlloc)/1e9, float64(ms.Sys)/1e9, g.NodeCount(), len(g.Edges))
}
