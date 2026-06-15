// pbftagstats — quick UK-PBF tag-coverage audit so we can decide whether
// OSM-derived junction / congestion penalties are worth implementing.
//
// Tells us, for each tag of interest, how many ways or nodes carry it.
// Sparse coverage → applying penalties based on the tag will be patchy
// and systematically under-penalise the un-tagged ones.
package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"

	"github.com/paulmach/osm"
	"github.com/paulmach/osm/osmpbf"
)

var (
	pbfPath = flag.String("pbf", "/data/uk-latest.osm.pbf", "OSM PBF file")
)

func main() {
	flag.Parse()
	log.SetFlags(log.Ltime)

	var (
		totalNodes, totalWays, totalRels                                int64
		nodeTrafficSignals, nodeStop, nodeGiveWay, nodeCrossingSig      int64
		nodeWithHighway                                                 int64
		wayRoundabout, wayMiniRA, wayJuncSignals, wayTrafficCalming     int64
		wayHighwayTotal, wayMaxspeed, wayLanes                          int64
		wayMotorway, wayTrunk, wayPrimary, waySecondary, wayResidential int64
		relRestriction                                                  int64
	)

	{
		f, err := os.Open(*pbfPath)
		if err != nil {
			log.Fatalf("open: %v", err)
		}
		log.Printf("scanning %s", *pbfPath)
		sc := osmpbf.New(context.Background(), f, 4)
		for sc.Scan() {
			switch o := sc.Object().(type) {
			case *osm.Node:
				totalNodes++
				tags := o.TagMap()
				if tags["highway"] != "" {
					nodeWithHighway++
				}
				if tags["highway"] == "traffic_signals" {
					nodeTrafficSignals++
				}
				if tags["highway"] == "stop" {
					nodeStop++
				}
				if tags["highway"] == "give_way" {
					nodeGiveWay++
				}
				if tags["crossing"] == "traffic_signals" {
					nodeCrossingSig++
				}
			case *osm.Way:
				totalWays++
				tags := o.TagMap()
				if hw := tags["highway"]; hw != "" {
					wayHighwayTotal++
					if tags["maxspeed"] != "" {
						wayMaxspeed++
					}
					if tags["lanes"] != "" {
						wayLanes++
					}
					if tags["traffic_calming"] != "" {
						wayTrafficCalming++
					}
					switch hw {
					case "motorway", "motorway_link":
						wayMotorway++
					case "trunk", "trunk_link":
						wayTrunk++
					case "primary", "primary_link":
						wayPrimary++
					case "secondary", "secondary_link":
						waySecondary++
					case "residential":
						wayResidential++
					}
				}
				j := tags["junction"]
				if j == "roundabout" {
					wayRoundabout++
				}
				if j == "mini_roundabout" {
					wayMiniRA++
				}
				if j == "traffic_signals" || j == "circular" || j == "jughandle" {
					wayJuncSignals++
				}
			case *osm.Relation:
				totalRels++
				if o.TagMap()["type"] == "restriction" {
					relRestriction++
				}
			}
		}
		sc.Close()
		f.Close()
	}

	pct := func(a, b int64) string {
		if b == 0 {
			return "0 %"
		}
		return fmt.Sprintf("%.1f %%", 100*float64(a)/float64(b))
	}

	fmt.Println()
	fmt.Println("=== OSM tag coverage in UK PBF ===")
	fmt.Printf("Totals: nodes=%d  ways=%d  relations=%d\n", totalNodes, totalWays, totalRels)
	fmt.Println()
	fmt.Printf("Routable highway ways:      %d\n", wayHighwayTotal)
	fmt.Printf("  motorway/_link:           %-9d (%s)\n", wayMotorway, pct(wayMotorway, wayHighwayTotal))
	fmt.Printf("  trunk/_link:              %-9d (%s)\n", wayTrunk, pct(wayTrunk, wayHighwayTotal))
	fmt.Printf("  primary/_link:            %-9d (%s)\n", wayPrimary, pct(wayPrimary, wayHighwayTotal))
	fmt.Printf("  secondary/_link:          %-9d (%s)\n", waySecondary, pct(waySecondary, wayHighwayTotal))
	fmt.Printf("  residential:              %-9d (%s)\n", wayResidential, pct(wayResidential, wayHighwayTotal))
	fmt.Println()
	fmt.Println("=== Tags we already use ===")
	fmt.Printf("  maxspeed=*:               %-9d (%s of routable ways)\n", wayMaxspeed, pct(wayMaxspeed, wayHighwayTotal))
	fmt.Println()
	fmt.Println("=== Tags we don't use yet ===")
	fmt.Printf("  way lanes=*:              %-9d (%s of routable ways)\n", wayLanes, pct(wayLanes, wayHighwayTotal))
	fmt.Printf("  way traffic_calming=*:    %d\n", wayTrafficCalming)
	fmt.Printf("  way junction=roundabout:  %d\n", wayRoundabout)
	fmt.Printf("  way junction=mini_round:  %d\n", wayMiniRA)
	fmt.Printf("  way junction=other:       %d\n", wayJuncSignals)
	fmt.Printf("  node highway=traffic_signals: %d\n", nodeTrafficSignals)
	fmt.Printf("  node highway=stop:        %d\n", nodeStop)
	fmt.Printf("  node highway=give_way:    %d\n", nodeGiveWay)
	fmt.Printf("  node crossing=traffic_signals: %d\n", nodeCrossingSig)
	fmt.Printf("  turn-restriction relations: %d\n", relRestriction)
}
