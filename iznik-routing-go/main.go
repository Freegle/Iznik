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
	// Stage 2 prototype CLI (`go run . stage2 <cmd>`): offline tooling only,
	// never reached by server deployments (no args there).
	if len(os.Args) > 1 && os.Args[1] == "stage2" {
		stage2Main(os.Args[2:])
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

	// Stage 2 artifact boot: STAGE2_DIR set means load the prebuilt graph +
	// reach engine in seconds instead of rebuilding from the PBF (which stays
	// the fallback, and the only path when STAGE2_DIR is unset).
	if g := stage2BootFromEnv(); g != nil {
		if dep != nil {
			g.Deprivation = dep
		}
		log.Printf("spatial-server: loaded %d nodes, %d edges from stage2 artifacts", g.NodeCount(), len(g.Edges))
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
