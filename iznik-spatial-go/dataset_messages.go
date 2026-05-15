package main

import (
	"database/sql"
	"fmt"
	"log"
	"time"
)

// MessagesDataset implements Dataset for the messages_spatial (point) table.
type MessagesDataset struct{}

func (d *MessagesDataset) Name() string { return "messages" }

func (d *MessagesDataset) RebuildInterval() time.Duration { return 24 * time.Hour }
func (d *MessagesDataset) DeltaInterval() time.Duration   { return 2 * time.Minute }

// Load populates idx with all active (non-successful) messages from messages_spatial.
func (d *MessagesDataset) Load(mysqlDB *sql.DB, idx *Index) error {
	return loadMessages(mysqlDB, idx, "")
}

// ApplyDelta loads messages modified since `since`.
// Rows with successful=1 are removed; new/modified rows are upserted.
func (d *MessagesDataset) ApplyDelta(mysqlDB *sql.DB, idx *Index, since time.Time) error {
	rows, err := mysqlDB.Query(`
		SELECT ms.msgid,
		       ST_X(ms.point) AS lng,
		       ST_Y(ms.point) AS lat,
		       ms.msgtype, ms.groupid,
		       CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END AS promised,
		       ms.successful
		FROM messages_spatial ms
		LEFT JOIN messages_promises mp ON mp.msgid = ms.msgid
		WHERE ms.modified > ?
	`, since.UTC())
	if err != nil {
		return fmt.Errorf("messages delta query: %w", err)
	}
	defer rows.Close()

	var upserted, removed int
	for rows.Next() {
		var msgid int64
		var lng, lat float64
		var msgtype string
		var groupid, promised int64
		var successful int
		if err := rows.Scan(&msgid, &lng, &lat, &msgtype, &groupid, &promised, &successful); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		if successful == 1 {
			if err := idx.DeleteByExtID(msgid); err != nil {
				log.Printf("messages delta: remove successful msgid=%d: %v", msgid, err)
			}
			removed++
		} else {
			item := Item{
				ExtID:  msgid,
				MinLng: lng,
				MaxLng: lng,
				MinLat: lat,
				MaxLat: lat,
				Extra:  map[string]any{"msgtype": msgtype, "groupid": groupid, "promised": promised},
			}
			if err := InsertItems(idx, []Item{item}, nil); err != nil {
				log.Printf("messages delta: upsert msgid=%d: %v", msgid, err)
			}
			upserted++
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("messages delta: upserted=%d removed=%d since=%s", upserted, removed, since.Format(time.RFC3339))
	return nil
}

// Query returns nearest messages to (params.Lng, params.Lat).
func (d *MessagesDataset) Query(idx *Index, params QueryParams) ([]QueryResult, error) {
	if params.Limit <= 0 {
		params.Limit = 10
	}
	return FindNearestPoints(idx, params.Lng, params.Lat, params.Limit, params.Polygon)
}

// Within returns all message IDs whose point lies within params.Polygon.
func (d *MessagesDataset) Within(idx *Index, params QueryParams) ([]int64, error) {
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

func loadMessages(mysqlDB *sql.DB, idx *Index, extraWhere string) error {
	query := `
		SELECT ms.msgid,
		       ST_X(ms.point) AS lng,
		       ST_Y(ms.point) AS lat,
		       ms.msgtype, ms.groupid,
		       CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END AS promised
		FROM messages_spatial ms
		LEFT JOIN messages_promises mp ON mp.msgid = ms.msgid
		WHERE ms.successful = 0
	` + extraWhere

	rows, err := mysqlDB.Query(query)
	if err != nil {
		return fmt.Errorf("messages load query: %w", err)
	}
	defer rows.Close()

	var items []Item
	for rows.Next() {
		var msgid int64
		var lng, lat float64
		var msgtype string
		var groupid, promised int64
		if err := rows.Scan(&msgid, &lng, &lat, &msgtype, &groupid, &promised); err != nil {
			return fmt.Errorf("scan: %w", err)
		}
		items = append(items, Item{
			ExtID:  msgid,
			MinLng: lng,
			MaxLng: lng,
			MinLat: lat,
			MaxLat: lat,
			Extra:  map[string]any{"msgtype": msgtype, "groupid": groupid, "promised": promised},
		})
	}
	if err := rows.Err(); err != nil {
		return err
	}
	log.Printf("messages load: loaded %d items", len(items))
	return InsertItems(idx, items, nil)
}
