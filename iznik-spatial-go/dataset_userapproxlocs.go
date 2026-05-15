package main

import (
	"database/sql"
	"fmt"
	"log"
	"time"
)

// UserApproxLocsDataset implements Dataset for the users_approxlocs (point, rebuild-only) table.
type UserApproxLocsDataset struct{}

func (d *UserApproxLocsDataset) Name() string { return "userapproxlocs" }

func (d *UserApproxLocsDataset) RebuildInterval() time.Duration { return 15 * time.Minute }
func (d *UserApproxLocsDataset) DeltaInterval() time.Duration   { return 0 }

func (d *UserApproxLocsDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	rows, err := mysqlDB.Query(`SELECT userid, lng, lat FROM users_approxlocs WHERE lng IS NOT NULL AND lat IS NOT NULL`)
	if err != nil {
		return fmt.Errorf("userapproxlocs load query: %w", err)
	}
	defer rows.Close()

	var items []Item
	for rows.Next() {
		var userid int64
		var lng, lat float64
		if err := rows.Scan(&userid, &lng, &lat); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		items = append(items, Item{
			ExtID:  userid,
			MinLng: lng,
			MaxLng: lng,
			MinLat: lat,
			MaxLat: lat,
		})
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("userapproxlocs load: loaded %d items", len(items))
	return InsertItems(idx, items, nil)
}

func (d *UserApproxLocsDataset) ApplyDelta(_ *sql.DB, _ *Index, _ time.Time) error {
	return ErrDeltaNotSupported
}

func (d *UserApproxLocsDataset) Query(idx *Index, params QueryParams) ([]QueryResult, error) {
	if params.Limit <= 0 {
		params.Limit = 10
	}
	return FindNearestPoints(idx, params.Lng, params.Lat, params.Limit, params.Polygon)
}

func (d *UserApproxLocsDataset) Within(idx *Index, params QueryParams) ([]int64, error) {
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
