package main

import (
	"database/sql"
	"fmt"
	"hash/fnv"
	"log"
	"sync"
	"time"

	"github.com/peterstace/simplefeatures/geom"

	"spatial-server/cellset"
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

// GroupCellRelation is one group's relation to a queried cell grid.
type GroupCellRelation struct {
	ID     int64 `json:"id"`
	Within bool  `json:"within"` // the QUERIED grid lies entirely inside this group
}

// groupCellCache holds each group's area rasterised onto the global lattice,
// in ENCODED form (~20KB a group; decoded bitmaps would be megabytes). Keyed
// by group id and validated against the WKB's length+checksum so a redrawn
// area re-rasterises. Groups change rarely and the dataset rebuilds every 15
// minutes, so hit rates are effectively total after warm-up.
var groupCellCache sync.Map // int64 -> groupCellEntry

type groupCellEntry struct {
	wkbLen int
	wkbSum uint64
	enc    []byte
}

func wkbChecksum(wkb []byte) uint64 {
	h := fnv.New64a()
	h.Write(wkb)
	return h.Sum64()
}

// groupCells returns the group's rasterised area, from cache or by
// rasterising its WKB now via the ONE production rasteriser.
func groupCells(id int64, wkb []byte) (*cellset.CellSet, error) {
	sum := wkbChecksum(wkb)
	if v, ok := groupCellCache.Load(id); ok {
		e := v.(groupCellEntry)
		if e.wkbLen == len(wkb) && e.wkbSum == sum {
			return cellset.Decode(e.enc)
		}
	}
	g, err := geom.UnmarshalWKB(wkb, geom.NoValidate{})
	if err != nil {
		return nil, err
	}
	cs, err := cellset.FromGeometry(g)
	if err != nil {
		return nil, err
	}
	groupCellCache.Store(id, groupCellEntry{wkbLen: len(wkb), wkbSum: sum, enc: cs.Encode()})
	return cs, nil
}

// IntersectingCells answers "which groups does this cell grid touch, and is
// it entirely inside any of them" - the cell form of the
// ST_Intersects(reach, polyindex) / ST_Within(reach, polyindex) pair that the
// rejection clip, the retraction pass and the crosspost count all ask. The
// R-tree narrows to bbox candidates; each candidate's area is rasterised once
// (cached) and compared cell-for-cell, so the answer derives from the same
// lattice as the reach itself and two languages can never disagree about it.
func (d *GroupsDataset) IntersectingCells(idx *Index, cs *cellset.CellSet) ([]GroupCellRelation, error) {
	minLng, minLat, maxLng, maxLat := cs.Bounds()
	candidates, err := QueryBBox(idx, minLng, maxLng, minLat, maxLat)
	if err != nil {
		return nil, err
	}
	var out []GroupCellRelation
	for _, c := range candidates {
		if c.WKB == nil {
			continue
		}
		gcs, err := groupCells(c.ExtID, c.WKB)
		if err != nil {
			log.Printf("groups intersecting: group %d unrasterisable: %v", c.ExtID, err)
			continue
		}
		if !cs.Intersects(gcs) {
			continue
		}
		out = append(out, GroupCellRelation{ID: c.ExtID, Within: cs.Within(gcs)})
	}
	return out, nil
}
