package main

import (
	"database/sql"
	"fmt"
	"log"
	"sort"
	"time"

	"github.com/peterstace/simplefeatures/geom"
)

// areaMin and areaMax match the V1 ST_Area filter (decimal-degree units).
// Only locations with area in this range are loaded and returned by KNN.
const (
	areaMin = 0.00001
	areaMax = 0.15
)

// LocationsDataset implements Dataset for the locations (area + postcode) table.
type LocationsDataset struct{}

func (d *LocationsDataset) Name() string { return "locations" }

func (d *LocationsDataset) RebuildInterval() time.Duration { return 24 * time.Hour }
func (d *LocationsDataset) DeltaInterval() time.Duration   { return 15 * time.Minute }

// Load populates idx from MySQL with all eligible locations (areas and postcodes).
func (d *LocationsDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	return loadLocations(mysqlDB, idx, "")
}

// ApplyDelta loads locations modified since `since` and updates idx.
func (d *LocationsDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	// Remove excluded locations.
	excluded, err := queryExcludedLocationIDs(mysqlDB)
	if err != nil {
		return err
	}
	for _, id := range excluded {
		if err := idx.DeleteByExtID(id); err != nil {
			log.Printf("locations delta: remove excluded id=%d: %v", id, err)
		}
	}

	// Load rows modified since last sync.
	return loadLocations(mysqlDB, idx, fmt.Sprintf(" AND locations.timestamp > '%s'", since.UTC().Format("2006-01-02 15:04:05")))
}

// Query returns KNN results for the locations dataset using polygon buffer expansion.
// TypeFilter restricts the candidate pool during expansion (so the buffer keeps
// growing until a matching location is found):
//   - exact type (e.g. "Postcode"): only locations of that type
//   - "Area": any non-Postcode location — mirrors the V1 PostGIS remap pool,
//     which synced everything except postcodes
func (d *LocationsDataset) Query(idx *Index, params QueryParams) ([]QueryResult, error) {
	if params.Limit <= 0 {
		params.Limit = 1
	}

	var match func(extra map[string]any) bool
	switch params.TypeFilter {
	case "":
		// No filter.
	case "Area":
		match = func(extra map[string]any) bool {
			t, _ := extra["type"].(string)
			return t != "Postcode"
		}
	default:
		want := params.TypeFilter
		match = func(extra map[string]any) bool {
			t, _ := extra["type"].(string)
			return t == want
		}
	}

	return FindNearestPolygon(idx, params.Lng, params.Lat, params.Limit, match, nil)
}

// Within returns all location IDs whose geometry intersects params.Polygon.
func (d *LocationsDataset) Within(idx *Index, params QueryParams) ([]int64, error) {
	if params.Polygon == nil {
		return nil, fmt.Errorf("polygon parameter is required for Within queries")
	}
	ids, err := idx.QueryWithin(*params.Polygon)
	if err != nil {
		return nil, err
	}
	if len(ids) > maxWithinResults {
		return nil, ErrTooManyResults
	}
	return ids, nil
}

// loadLocations loads locations from MySQL into the index.
// extraWhere is an optional SQL snippet appended to the WHERE clause.
func loadLocations(mysqlDB *sql.DB, idx *Index, extraWhere string) error {
	query := `
		SELECT
			locations.id,
			locations.name,
			locations.type,
			ST_Area(COALESCE(ourgeometry, geometry))  AS area,
			ST_AsWKB(COALESCE(ourgeometry, geometry)) AS wkb
		FROM locations
		LEFT JOIN locations_excluded le ON locations.id = le.locationid
		WHERE le.locationid IS NULL
		  AND COALESCE(ourgeometry, geometry) IS NOT NULL
		  AND ST_Dimension(COALESCE(ourgeometry, geometry)) = 2
		  AND ST_Area(COALESCE(ourgeometry, geometry)) BETWEEN ? AND ?
	` + extraWhere

	rows, err := mysqlDB.Query(query, areaMin, areaMax)
	if err != nil {
		return fmt.Errorf("mysql query: %w", err)
	}
	defer rows.Close()

	var locs []Item
	var scanned, skipped int

	log.Printf("spatial-loader: scanning locations from MySQL...")
	for rows.Next() {
		var item Item
		var name, locType string
		var wkbRaw []byte
		if err := rows.Scan(&item.ExtID, &name, &locType, &item.Area, &wkbRaw); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		item.WKB = stripSRIDPrefix(wkbRaw)
		item.Extra = map[string]any{"name": name, "type": locType}

		g, err := geom.UnmarshalWKB(item.WKB, geom.NoValidate{})
		if err != nil {
			skipped++
			continue
		}
		env := g.Envelope()
		min, max, ok := env.MinMaxXYs()
		if !ok {
			skipped++
			continue
		}
		item.MinLng, item.MaxLng = min.X, max.X
		item.MinLat, item.MaxLat = min.Y, max.Y
		locs = append(locs, item)
		scanned++
		if scanned%10000 == 0 {
			log.Printf("spatial-loader: scanned %d rows from MySQL...", scanned)
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("spatial-loader: scanned %d rows (%d skipped invalid WKB)", scanned, skipped)

	// Sort by bbox centre for STR-packing approximation.
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

	log.Printf("spatial-loader: inserting %d locations into SQLite R-tree index...", len(locs))
	return InsertItems(idx, locs, func(done, total int) {
		if done%10000 == 0 {
			log.Printf("spatial-loader: inserted %d/%d (%.0f%%)...", done, total, 100*float64(done)/float64(total))
		}
	})
}

// queryExcludedLocationIDs returns all location IDs currently in locations_excluded.
func queryExcludedLocationIDs(mysqlDB *sql.DB) ([]int64, error) {
	rows, err := mysqlDB.Query(`SELECT locationid FROM locations_excluded`)
	if err != nil {
		return nil, fmt.Errorf("query locations_excluded: %w", err)
	}
	defer rows.Close()
	var ids []int64
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		ids = append(ids, id)
	}
	return ids, rows.Err()
}

// stripSRIDPrefix removes the 4-byte SRID header that MySQL's internal geometry
// format prepends to WKB. ST_AsWKB() on MySQL 8 already returns clean WKB, so
// we only strip when byte 0 is not a valid WKB byte-order marker.
func stripSRIDPrefix(b []byte) []byte {
	if len(b) > 4 && b[0] != 0x00 && b[0] != 0x01 && (b[4] == 0x00 || b[4] == 0x01) {
		return b[4:]
	}
	return b
}
