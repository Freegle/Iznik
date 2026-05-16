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

	log.Printf("routing-server: loading graph from %s", pbfPath)
	g, err := BuildGraph(pbfPath)
	if err != nil {
		log.Fatalf("routing-server: BuildGraph: %v", err)
	}
	log.Printf("routing-server: loaded %d nodes, %d edge-lists", len(g.Nodes), len(g.Edges))

	addr := ":" + getenv("ROUTING_PORT", "8196")
	startServer(g, addr)
}
