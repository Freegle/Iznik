package main

import (
	"database/sql"
	"fmt"
	"log"
	"sort"

	"github.com/peterstace/simplefeatures/geom"
)

// LoadFromMySQL reads all eligible locations from mysqlDB and inserts them into idx.
//
// Eligible = non-postcode, non-excluded, 2D polygon geometry, area within [areaMin, areaMax].
// This matches the set queried by the V1 PostGIS KNN algorithm.
//
// Rows are sorted by bounding-box centre (x then y) before insertion to
// approximate STR (Sort-Tile-Recursive) packing order, which reduces R-tree
// node overlap and improves query performance for static datasets.
func LoadFromMySQL(mysqlDB *sql.DB, idx *Index) error {
	rows, err := mysqlDB.Query(`
		SELECT
			locations.id                                              AS locationid,
			locations.name,
			locations.type,
			ST_Area(COALESCE(ourgeometry, geometry))                  AS area,
			ST_AsWKB(COALESCE(ourgeometry, geometry))                 AS wkb,
			ST_MinX(COALESCE(ourgeometry, geometry))                  AS min_lng,
			ST_MaxX(COALESCE(ourgeometry, geometry))                  AS max_lng,
			ST_MinY(COALESCE(ourgeometry, geometry))                  AS min_lat,
			ST_MaxY(COALESCE(ourgeometry, geometry))                  AS max_lat
		FROM locations
		LEFT JOIN locations_excluded le ON locations.id = le.locationid
		WHERE le.locationid IS NULL
		  AND locations.type != 'Postcode'
		  AND COALESCE(ourgeometry, geometry) IS NOT NULL
		  AND ST_Dimension(COALESCE(ourgeometry, geometry)) = 2
		  AND ST_Area(COALESCE(ourgeometry, geometry)) BETWEEN ? AND ?
	`, areaMin, areaMax)
	if err != nil {
		return fmt.Errorf("mysql query: %w", err)
	}
	defer rows.Close()

	var locs []LocationRow
	var scanned, skipped int

	log.Printf("knn-loader: scanning locations from MySQL...")
	for rows.Next() {
		var lr LocationRow
		var wkbRaw []byte
		if err := rows.Scan(
			&lr.LocationID, &lr.Name, &lr.Type, &lr.Area,
			&wkbRaw, &lr.MinLng, &lr.MaxLng, &lr.MinLat, &lr.MaxLat,
		); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		lr.WKB = stripSRIDPrefix(wkbRaw)
		// Validate before storing — skip geometries that can't be parsed.
		if _, err := geom.UnmarshalWKB(lr.WKB); err != nil {
			skipped++
			continue
		}
		locs = append(locs, lr)
		scanned++
		if scanned%10000 == 0 {
			log.Printf("knn-loader: scanned %d rows from MySQL...", scanned)
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("knn-loader: scanned %d rows (%d skipped invalid WKB)", scanned, skipped)

	// Sort by bbox centre for STR-packing approximation.
	log.Printf("knn-loader: sorting %d locations for R-tree packing...", len(locs))
	sort.Slice(locs, func(i, j int) bool {
		cxi := (locs[i].MinLng + locs[i].MaxLng) / 2
		cxj := (locs[j].MinLng + locs[j].MaxLng) / 2
		if cxi != cxj {
			return cxi < cxj
		}
		cyi := (locs[i].MinLat + locs[i].MaxLat) / 2
		cyj := (locs[j].MinLat + locs[j].MaxLat) / 2
		return cyi < cyj
	})

	log.Printf("knn-loader: inserting %d locations into SQLite R-tree index...", len(locs))
	return InsertLocations(idx, locs, func(done, total int) {
		if done%10000 == 0 {
			log.Printf("knn-loader: inserted %d/%d (%.0f%%)...", done, total, 100*float64(done)/float64(total))
		}
	})
}

// stripSRIDPrefix removes the 4-byte SRID header that MySQL prepends to WKB values
// returned by ST_AsWKB(). Byte 4 of a valid WKB is always the byte-order marker
// (0x00 big-endian or 0x01 little-endian), so we use that as a detection signal.
func stripSRIDPrefix(b []byte) []byte {
	if len(b) > 4 && (b[4] == 0x00 || b[4] == 0x01) {
		return b[4:]
	}
	return b
}
