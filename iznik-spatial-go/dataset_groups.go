package main

import (
	"database/sql"
	"fmt"
	"log"
	"time"

	"github.com/peterstace/simplefeatures/geom"
)

// GroupsDataset implements Dataset for the groups (polygon, rebuild-only) table.
type GroupsDataset struct{}

func (d *GroupsDataset) Name() string { return "groups" }

func (d *GroupsDataset) RebuildInterval() time.Duration { return 15 * time.Minute }
func (d *GroupsDataset) DeltaInterval() time.Duration   { return 0 }

func (d *GroupsDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	rows, err := mysqlDB.Query(`
		SELECT id, ST_AsWKB(polyindex) AS wkb, nameshort
		FROM ` + "`groups`" + `
		WHERE publish = 1 AND listable = 1
		  AND polyindex IS NOT NULL
	`)
	if err != nil {
		return fmt.Errorf("groups load query: %w", err)
	}
	defer rows.Close()

	var items []Item
	var skipped int
	for rows.Next() {
		var id int64
		var wkbRaw []byte
		var nameshort string
		if err := rows.Scan(&id, &wkbRaw, &nameshort); err != nil {
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
			Extra:  map[string]any{"nameshort": nameshort},
		})
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("groups load: loaded %d items (%d skipped)", len(items), skipped)
	return InsertItems(idx, items, nil)
}

func (d *GroupsDataset) ApplyDelta(_ *sql.DB, _ *Index, _ time.Time) error {
	return ErrDeltaNotSupported
}

func (d *GroupsDataset) Query(idx *Index, params QueryParams) ([]QueryResult, error) {
	if params.Limit <= 0 {
		params.Limit = 10
	}
	return FindNearestPolygon(idx, params.Lng, params.Lat, params.Limit, nil, nil)
}

func (d *GroupsDataset) Within(idx *Index, params QueryParams) ([]int64, error) {
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
