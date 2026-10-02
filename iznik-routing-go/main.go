package main

import (
	"log"
	"os"
)

func getenv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

func main() {
	// Reach engine prototype CLI (`go run . reach <cmd>`): offline tooling only,
	// never reached by server deployments (no args there).
	if len(os.Args) > 1 && os.Args[1] == "reach" {
		reachMain(os.Args[2:])
		return
	}

	pbfPath := getenv("OSM_PBF_PATH", "")
	if pbfPath == "" {
		log.Fatal("OSM_PBF_PATH environment variable required")
	}

	var dep *DeprivationIndex
	if path := getenv("DEPRIVATION_CSV", ""); path != "" {
		log.Printf("spatial-server: loading deprivation data from %s", path)
		dep = LoadDeprivation(path)
		if dep == nil {
			log.Printf("spatial-server: WARNING: failed to load deprivation data from %s", path)
		} else {
			log.Printf("spatial-server: deprivation data loaded")
		}
	}

	// Reach engine artifact boot: REACH_DIR set means load the prebuilt graph +
	// reach engine in seconds instead of rebuilding from the PBF (which stays
	// the fallback, and the only path when REACH_DIR is unset).
	if g := reachBootFromEnv(); g != nil {
		if dep != nil {
			g.Deprivation = dep
		}
		log.Printf("spatial-server: loaded %d nodes, %d edges from reach-engine artifacts", g.NodeCount(), len(g.Edges))
		startServer(g)
		return
	}

	log.Printf("spatial-server: loading graph from %s", pbfPath)
	g, err := BuildGraph(pbfPath, dep)
	if err != nil {
		log.Fatalf("spatial-server: BuildGraph: %v", err)
	}
	log.Printf("spatial-server: loaded %d nodes, %d edges", g.NodeCount(), len(g.Edges))

	startServer(g)
}
