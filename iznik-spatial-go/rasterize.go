package main

import (
	"fmt"

	"spatial-server/cellset"
)

// maxRasterizeWKTBytes bounds the WKT this service will parse for
// rasterisation. Reach polygons run up to ~1MB (measured live 2026-08-24);
// this leaves generous headroom without accepting an unbounded body.
const maxRasterizeWKTBytes = 5 * 1024 * 1024

// rasterizeWKT is the ONE place a rippling reach polygon becomes its
// canonical compact form (plans/2026-08-24-rippling-reach-raster-storage.md,
// cellset.FromPolygonWKT). Exposed over HTTP as POST /v1/reach/rasterize so
// every writer - iznik-batch's ExpandService/MaxReachService today, and any
// future writer - calls through here rather than embedding its own copy of
// the rasteriser: one implementation, or two disagree at a boundary cell and
// nothing catches it.
func rasterizeWKT(wkt string) ([]byte, error) {
	if wkt == "" {
		return nil, fmt.Errorf("empty WKT")
	}
	if len(wkt) > maxRasterizeWKTBytes {
		return nil, fmt.Errorf("WKT too large (%d bytes, max %d)", len(wkt), maxRasterizeWKTBytes)
	}
	cs, err := cellset.FromPolygonWKT(wkt)
	if err != nil {
		return nil, err
	}
	return cs.Encode(), nil
}
