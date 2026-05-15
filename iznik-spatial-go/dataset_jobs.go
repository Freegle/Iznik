package main

import (
	"database/sql"
	"fmt"
	"log"
	"time"

	"github.com/peterstace/simplefeatures/geom"
)

// JobsDataset implements Dataset for the jobs (polygon) table.
type JobsDataset struct{}

func (d *JobsDataset) Name() string { return "jobs" }

func (d *JobsDataset) RebuildInterval() time.Duration { return 24 * time.Hour }
func (d *JobsDataset) DeltaInterval() time.Duration   { return 5 * time.Minute }

func (d *JobsDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	return loadJobs(mysqlDB, idx, "")
}

func (d *JobsDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	rows, err := mysqlDB.Query(`
		SELECT id, ST_AsWKB(geometry) AS wkb, title, city, cpc, visible
		FROM jobs
		WHERE seenat > ?
	`, since.UTC())
	if err != nil {
		return fmt.Errorf("jobs delta query: %w", err)
	}
	defer rows.Close()

	var upserted, removed int
	for rows.Next() {
		var id int64
		var wkbRaw []byte
		var title, city string
		var cpc float64
		var visible int
		if err := rows.Scan(&id, &wkbRaw, &title, &city, &cpc, &visible); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		if visible == 0 {
			if err := idx.DeleteByExtID(id); err != nil {
				log.Printf("jobs delta: remove invisible id=%d: %v", id, err)
			}
			removed++
			continue
		}
		wkb := stripSRIDPrefix(wkbRaw)
		g, err := geom.UnmarshalWKB(wkb, geom.NoValidate{})
		if err != nil {
			continue
		}
		env := g.Envelope()
		min, max, ok := env.MinMaxXYs()
		if !ok {
			continue
		}
		item := Item{
			ExtID:  id,
			WKB:    wkb,
			Area:   g.Area(),
			MinLng: min.X,
			MaxLng: max.X,
			MinLat: min.Y,
			MaxLat: max.Y,
			Extra:  map[string]any{"title": title, "city": city, "cpc": cpc},
		}
		if err := InsertItems(idx, []Item{item}, nil); err != nil {
			log.Printf("jobs delta: upsert id=%d: %v", id, err)
		}
		upserted++
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("jobs delta: upserted=%d removed=%d since=%s", upserted, removed, since.Format(time.RFC3339))
	return nil
}

func (d *JobsDataset) Query(idx *Index, params QueryParams) ([]QueryResult, error) {
	if params.Limit <= 0 {
		params.Limit = 10
	}
	return FindNearestPolygon(idx, params.Lng, params.Lat, params.Limit)
}

func (d *JobsDataset) Within(idx *Index, params QueryParams) ([]int64, error) {
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

func loadJobs(mysqlDB *sql.DB, idx *Index, extraWhere string) error {
	query := `
		SELECT id, ST_AsWKB(geometry) AS wkb, title, city, cpc
		FROM jobs
		WHERE visible = 1 AND cpc >= 0.10
		  AND geometry IS NOT NULL
	` + extraWhere

	rows, err := mysqlDB.Query(query)
	if err != nil {
		return fmt.Errorf("jobs load query: %w", err)
	}
	defer rows.Close()

	var items []Item
	var skipped int
	for rows.Next() {
		var id int64
		var wkbRaw []byte
		var title, city string
		var cpc float64
		if err := rows.Scan(&id, &wkbRaw, &title, &city, &cpc); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		wkb := stripSRIDPrefix(wkbRaw)
		g, err := geom.UnmarshalWKB(wkb, geom.NoValidate{})
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
		items = append(items, Item{
			ExtID:  id,
			WKB:    wkb,
			Area:   g.Area(),
			MinLng: min.X,
			MaxLng: max.X,
			MinLat: min.Y,
			MaxLat: max.Y,
			Extra:  map[string]any{"title": title, "city": city, "cpc": cpc},
		})
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("jobs load: loaded %d items (%d skipped)", len(items), skipped)
	return InsertItems(idx, items, nil)
}
