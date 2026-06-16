package main

import (
	"database/sql"
	"fmt"
	"log"
	"time"
)

// PostcodesDataset implements Dataset for postcode locations (point geometry).
//
// Postcodes are by far the largest dataset (~2M rows) and are points, so each is
// stored with a degenerate bbox and nil WKB, and queried with FindNearestPoints.
// Only full postcodes (name contains a space, e.g. "SW1A 2DU") are indexed —
// outward-only districts are excluded, matching Location::closestPostcode in the
// PHP batch which has always returned full postcodes.
//
// Extra is left nil deliberately: at 2M rows a per-item map would cost a lot of
// allocations/JSON for no benefit — the nearest lookup only needs the id, and
// callers resolve name/lat/lng from MySQL by id afterwards.
type PostcodesDataset struct{}

func (d *PostcodesDataset) Name() string { return "postcodes" }

func (d *PostcodesDataset) RebuildInterval() time.Duration { return 24 * time.Hour }

// Postcodes change rarely (only when the Doogal import adds new ones or nudges a
// lat/lng), so an hourly incremental delta is ample; the nightly full rebuild
// reconciles deletions/exclusions.
func (d *PostcodesDataset) DeltaInterval() time.Duration { return 1 * time.Hour }

func (d *PostcodesDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	return loadPostcodes(mysqlDB, idx, "")
}

func (d *PostcodesDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	return loadPostcodes(mysqlDB, idx, fmt.Sprintf(
		" AND locations.timestamp > '%s'", since.UTC().Format("2006-01-02 15:04:05")))
}

func (d *PostcodesDataset) Query(idx *Index, params QueryParams) ([]QueryResult, error) {
	if params.Limit <= 0 {
		params.Limit = 1
	}
	return FindNearestPoints(idx, params.Lng, params.Lat, params.Limit, params.Polygon)
}

func (d *PostcodesDataset) Within(idx *Index, params QueryParams) ([]int64, error) {
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

func loadPostcodes(mysqlDB *sql.DB, idx *Index, extraWhere string) error {
	query := `
		SELECT locations.id,
		       ST_X(COALESCE(ourgeometry, geometry)) AS lng,
		       ST_Y(COALESCE(ourgeometry, geometry)) AS lat
		FROM locations
		LEFT JOIN locations_excluded le ON locations.id = le.locationid
		WHERE le.locationid IS NULL
		  AND locations.type = 'Postcode'
		  AND LOCATE(' ', locations.name) > 0
		  AND COALESCE(ourgeometry, geometry) IS NOT NULL
	` + extraWhere

	rows, err := mysqlDB.Query(query)
	if err != nil {
		return fmt.Errorf("postcodes load query: %w", err)
	}
	defer rows.Close()

	var items []Item
	for rows.Next() {
		var id int64
		var lng, lat float64
		if err := rows.Scan(&id, &lng, &lat); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		items = append(items, Item{
			ExtID:  id,
			MinLng: lng,
			MaxLng: lng,
			MinLat: lat,
			MaxLat: lat,
		})
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("postcodes load: loaded %d items", len(items))
	return InsertItems(idx, items, nil)
}
