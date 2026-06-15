package main

import (
	"database/sql"
	"fmt"
	"log"
	"time"
)

// NewsfeedDataset implements Dataset for the newsfeed (point) table.
type NewsfeedDataset struct{}

func (d *NewsfeedDataset) Name() string { return "newsfeed" }

func (d *NewsfeedDataset) RebuildInterval() time.Duration { return 24 * time.Hour }
func (d *NewsfeedDataset) DeltaInterval() time.Duration   { return 2 * time.Minute }

func (d *NewsfeedDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	return loadNewsfeed(mysqlDB, idx, "")
}

func (d *NewsfeedDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	rows, err := mysqlDB.Query(`
		SELECT id, ST_X(position) AS lng, ST_Y(position) AS lat,
		       COALESCE(type, '') AS type, COALESCE(userid, 0) AS userid, deleted
		FROM newsfeed
		WHERE modified > ?
	`, since.UTC())
	if err != nil {
		return fmt.Errorf("newsfeed delta query: %w", err)
	}
	defer rows.Close()

	var upserted, removed int
	for rows.Next() {
		var id int64
		var lng, lat float64
		var nfType string
		var userid int64
		var deleted *string
		if err := rows.Scan(&id, &lng, &lat, &nfType, &userid, &deleted); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		if deleted != nil {
			if err := idx.DeleteByExtID(id); err != nil {
				log.Printf("newsfeed delta: remove deleted id=%d: %v", id, err)
			}
			removed++
		} else {
			item := Item{
				ExtID:  id,
				MinLng: lng,
				MaxLng: lng,
				MinLat: lat,
				MaxLat: lat,
				Extra:  map[string]any{"type": nfType, "userid": userid},
			}
			if err := InsertItems(idx, []Item{item}, nil); err != nil {
				log.Printf("newsfeed delta: upsert id=%d: %v", id, err)
			}
			upserted++
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("newsfeed delta: upserted=%d removed=%d since=%s", upserted, removed, since.Format(time.RFC3339))
	return nil
}

func (d *NewsfeedDataset) Query(idx *Index, params QueryParams) ([]QueryResult, error) {
	if params.Limit <= 0 {
		params.Limit = 10
	}
	return FindNearestPoints(idx, params.Lng, params.Lat, params.Limit, params.Polygon)
}

func (d *NewsfeedDataset) Within(idx *Index, params QueryParams) ([]int64, error) {
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

func loadNewsfeed(mysqlDB *sql.DB, idx *Index, extraWhere string) error {
	query := `
		SELECT id, ST_X(position) AS lng, ST_Y(position) AS lat, COALESCE(type, '') AS type, COALESCE(userid, 0) AS userid
		FROM newsfeed
		WHERE deleted IS NULL
		  AND replyto IS NULL
		  AND position IS NOT NULL
	` + extraWhere

	rows, err := mysqlDB.Query(query)
	if err != nil {
		return fmt.Errorf("newsfeed load query: %w", err)
	}
	defer rows.Close()

	var items []Item
	for rows.Next() {
		var id int64
		var lng, lat float64
		var nfType string
		var userid int64
		if err := rows.Scan(&id, &lng, &lat, &nfType, &userid); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		items = append(items, Item{
			ExtID:  id,
			MinLng: lng,
			MaxLng: lng,
			MinLat: lat,
			MaxLat: lat,
			Extra:  map[string]any{"type": nfType, "userid": userid},
		})
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("newsfeed load: loaded %d items", len(items))
	return InsertItems(idx, items, nil)
}
