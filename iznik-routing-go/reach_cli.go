package main

// Reach engine prototype CLI: `go run . reach <cmd>`. Not reachable in server
// deployments (the server starts only when no reach arg is given), so this
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

const reachSnapPath = "data/reach/graph.snap"

func reachMain(args []string) {
	if len(args) == 0 {
		fmt.Fprintln(os.Stderr, "usage: reach <build|stats|partition|matrices|leaftables|query|parity> [args]")
		os.Exit(2)
	}
	switch args[0] {
	case "build":
		reachBuildCmd()
	case "stats":
		reachStatsCmd()
	case "partition":
		reachPartitionCmd(args[1:])
	case "matrices":
		reachMatricesCmd(args[1:])
	case "leaftables":
		reachLeafTablesCmd(args[1:])
	case "labels-export":
		reachLabelsExportCmd(args[1:])
	case "labels-apply":
		reachLabelsApplyCmd(args[1:])
	case "query":
		reachQueryCmd(args[1:])
	case "leafcheck":
		reachLeafCheckCmd(args[1:])
	case "tracepath":
		reachTracePathCmd(args[1:])
	case "boundarydebug":
		reachBoundaryDebugCmd(args[1:])
	case "exactdebug":
		reachExactDebugCmd(args[1:])
	case "graphmem":
		reachGraphMemCmd()
	case "nodedebug":
		reachNodeDebugCmd(args[1:])
	case "sweep":
		reachSweepCmd(args[1:])
	case "parity":
		reachParityCmd(args[1:])
	default:
		fmt.Fprintf(os.Stderr, "unknown reach command %q\n", args[0])
		os.Exit(2)
	}
}

// reachLoadOrBuild returns the graph+overlay, from snapshot when it is present and
// still current for the extract.
func reachLoadOrBuild() (*Graph, *Overlay) {
	if stale := snapshotStaleAgainstPBF(reachSnapPath); stale != "" {
		// The snapshot is a cache of the extract, so an extract that has moved on
		// makes it wrong rather than merely old. Rebuild instead of loading it:
		// reusing it here is how a refreshed map quietly fails to take effect.
		log.Printf("reach: %s; rebuilding from the extract", stale)
	} else if _, err := os.Stat(reachSnapPath); err == nil {
		g, ov, err := LoadReachSnapshot(reachSnapPath)
		if err == nil {
			return g, ov
		}
		log.Printf("reach: snapshot load failed (%v), rebuilding", err)
	}
	pbf := getenv("OSM_PBF_PATH", "data/uk-latest.osm.pbf")
	var dep *DeprivationIndex
	if path := getenv("DEPRIVATION_CSV", ""); path != "" {
		dep = LoadDeprivation(path)
	}
	g, err := BuildGraph(pbf, dep)
	if err != nil {
		log.Fatalf("reach: BuildGraph: %v", err)
	}
	ov := BuildOverlay(g)
	// The three-mode edges existed only to shape the contraction. Nothing
	// serves from them and the snapshot does not store them, so let them go
	// before the save rather than holding a second edge list through it.
	g.releaseModalEdges()
	if err := os.MkdirAll(filepath.Dir(reachSnapPath), 0o755); err != nil {
		log.Fatalf("reach: mkdir: %v", err)
	}
	if err := SaveReachSnapshot(reachSnapPath, g, ov); err != nil {
		log.Fatalf("reach: snapshot save: %v", err)
	}
	return g, ov
}

func reachBuildCmd() {
	g, ov := reachLoadOrBuild()
	printOverlayStats(g, ov)
}

func reachStatsCmd() {
	g, ov := reachLoadOrBuild()
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
		for range ov.EdgesFrom(oi) {
			driveEdges++
			has = true
		}
		if has {
			driveJunctions++
		}
	}

	absorbed := 0
	for v := NodeID(1); v <= NodeID(n); v++ {
		if ov.ChainA(v) != 0 {
			absorbed++
		}
	}

	// Chain edge length distribution (drive seconds).
	var secs []float64
	for _, e := range ov.Edges {
		secs = append(secs, float64(e.Sec()))
	}
	sort.Float64s(secs)
	pct := func(p float64) float64 {
		if len(secs) == 0 {
			return 0
		}
		i := int(p * float64(len(secs)-1))
		return secs[i]
	}

	fmt.Printf("reach stats:\n")
	fmt.Printf("  base:    %d nodes / %d directed edges\n", n, len(g.Edges))
	fmt.Printf("  overlay: %d junctions / %d chain edges (%.1fx node, %.1fx edge reduction)\n",
		on, len(ov.Edges), float64(n)/float64(on), float64(len(g.Edges))/float64(len(ov.Edges)))
	fmt.Printf("  drive subgraph: %d junctions / %d directed chain edges\n", driveJunctions, driveEdges)
	fmt.Printf("  absorbed chain nodes: %d (%.1f%% of base)\n", absorbed, 100*float64(absorbed)/float64(n))
	fmt.Printf("  drive chain secs p50/p90/p99/max: %.1f / %.1f / %.1f / %.1f\n",
		pct(0.50), pct(0.90), pct(0.99), pct(1.0))
}

func reachPartitionCmd(args []string) {
	fs := flag.NewFlagSet("partition", flag.ExitOnError)
	leaf := fs.Int("leaf", 10000, "maximum junctions per leaf region")
	alpha := fs.Float64("alpha", 0.25, "inertial-flow source/sink fraction")
	_ = fs.Parse(args)
	reachPartitionRun(*leaf, *alpha)
}

func reachMatricesCmd(args []string) {
	reachMatricesRun()
}

func reachQueryCmd(args []string) {
	fs := flag.NewFlagSet("query", flag.ExitOnError)
	lat := fs.Float64("lat", 51.4545, "origin latitude")
	lng := fs.Float64("lng", -2.5879, "origin longitude")
	minutes := fs.Float64("minutes", 30, "drive-time budget in minutes")
	_ = fs.Parse(args)
	reachQueryRun(*lat, *lng, *minutes)
}

func reachParityCmd(args []string) {
	fs := flag.NewFlagSet("parity", flag.ExitOnError)
	dir := fs.String("dir", "data/reach/prod", "directory of exported prod posts")
	_ = fs.Parse(args)
	reachParityRun(*dir, reachLoadEngine())
}

func reachExactDebugCmd(args []string) {
	fs := flag.NewFlagSet("exactdebug", flag.ExitOnError)
	path := fs.String("json", "", "path to one exported post .json")
	_ = fs.Parse(args)
	reachExactDebugRun(*path, reachLoadEngine())
}

func reachBoundaryDebugCmd(args []string) {
	fs := flag.NewFlagSet("boundarydebug", flag.ExitOnError)
	path := fs.String("json", "", "path to one exported post .json")
	_ = fs.Parse(args)
	reachBoundaryDebugRun(*path, reachLoadEngine())
}

func reachTracePathCmd(args []string) {
	fs := flag.NewFlagSet("tracepath", flag.ExitOnError)
	path := fs.String("json", "", "post json")
	node := fs.Uint64("node", 0, "target base node id")
	_ = fs.Parse(args)
	reachTracePathRun(*path, NodeID(*node), reachLoadEngine())
}

func reachLeafCheckCmd(args []string) {
	fs := flag.NewFlagSet("leafcheck", flag.ExitOnError)
	path := fs.String("json", "", "post json")
	node := fs.Uint64("node", 0, "target base node id")
	_ = fs.Parse(args)
	reachLeafCheckRun(*path, NodeID(*node), reachLoadEngine())
}

func reachSweepCmd(args []string) {
	fs := flag.NewFlagSet("sweep", flag.ExitOnError)
	file := fs.String("file", "data/reach/sweep.jsonl", "jsonl of exported origins")
	synth := fs.Int("synthetic", 400, "max synthetic origins for untouched regions")
	fuzz := fs.Int("fuzz", 600, "fictional origins (0 disables)")
	_ = fs.Parse(args)
	reachSweepRun(*file, *synth, *fuzz, reachLoadEngine())
}

func reachNodeDebugCmd(args []string) {
	fs := flag.NewFlagSet("nodedebug", flag.ExitOnError)
	lat := fs.Float64("lat", 0, "origin lat")
	lng := fs.Float64("lng", 0, "origin lng")
	minutes := fs.Float64("minutes", 30, "budget minutes")
	node := fs.Uint64("node", 0, "target base node")
	_ = fs.Parse(args)
	reachNodeDebugRun(*lat, *lng, *minutes, NodeID(*node), reachLoadEngine())
}

// reachGraphMemCmd measures the base graph's resident heap alone — the
// like-for-like baseline for what the routing server holds today.
func reachGraphMemCmd() {
	g, ov := reachLoadOrBuild()
	ov.BaseNode, ov.Ref, ov.EdgeStart, ov.Edges = nil, nil, nil, nil
	ov.ChainEndB, ov.OffFromA, ov.OffFromB = nil, nil, nil
	var ms runtime.MemStats
	runtime.GC()
	runtime.ReadMemStats(&ms)
	fmt.Printf("base graph only: heap %.2fGB (sys %.2fGB); nodes %d edges %d\n",
		float64(ms.HeapAlloc)/1e9, float64(ms.Sys)/1e9, g.NodeCount(), len(g.Edges))
}

// reachLeafTablesCmd builds the precomputed leaf-tables artifact for an
// artifact directory — the explicit pipeline form of the server's background
// self-heal, for building once on the artifact host and rsyncing to nodes.
func reachLeafTablesCmd(args []string) {
	fs := flag.NewFlagSet("leaftables", flag.ExitOnError)
	dir := fs.String("dir", "data/reach", "artifact directory (graph/partition/matrices .snap)")
	workers := fs.Int("workers", runtime.NumCPU(), "parallel leaf builders")
	_ = fs.Parse(args)
	eng, err := loadReachEngineCore(*dir)
	if err != nil {
		log.Fatalf("reach: leaftables: %v", err)
	}
	if err := BuildLeafTablesFile(filepath.Join(*dir, leafTablesName), eng, *workers); err != nil {
		log.Fatalf("reach: leaftables: %v", err)
	}
}
