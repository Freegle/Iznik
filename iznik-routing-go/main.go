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

	log.Printf("spatial-server: loading graph from %s", pbfPath)
	g, err := BuildGraph(pbfPath, dep)
	if err != nil {
		log.Fatalf("spatial-server: BuildGraph: %v", err)
	}
	log.Printf("spatial-server: loaded %d nodes, %d edges", g.NodeCount(), len(g.Edges))

	// DfT transport-connectivity, for the connectivity-friction reach model. Optional;
	// unscored nodes (Conn==0, e.g. Scotland) fall back to the plain isochrone.
	if path := getenv("CONNECTIVITY_CSV", ""); path != "" {
		log.Printf("spatial-server: loading connectivity data from %s", path)
		if ci := LoadConnectivity(path); ci == nil {
			log.Printf("spatial-server: WARNING: failed to load connectivity data from %s", path)
		} else {
			TagConnectivity(g, ci)
			log.Printf("spatial-server: connectivity data loaded and tagged onto nodes")
		}
	}

	startServer(g)
}
