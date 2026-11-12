package main

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"fmt"
	"io"
	"os"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/stretchr/testify/require"
)

// The MySQL source queries are part of production behaviour: the loaders run them against the
// live database every few minutes. This test records every statement and argument list the
// loaders send through a fake driver and compares the transcript with a golden file taken from
// the code as it was before the source engine became configurable. Any change to MySQL's SQL
// text shows up here as a diff.
//
// Regenerate with UPDATE_GOLDEN=1 only when a MySQL query is meant to change.

const mysqlTranscriptGolden = "testdata/mysql_source_queries.golden"

type transcript struct {
	mu    sync.Mutex
	lines []string
}

func (r *transcript) add(query string, args []driver.NamedValue) {
	r.mu.Lock()
	defer r.mu.Unlock()
	vals := make([]string, len(args))
	for i, a := range args {
		vals[i] = fmt.Sprintf("%v", a.Value)
	}
	r.lines = append(r.lines, "QUERY: "+query+"\nARGS: ["+strings.Join(vals, " | ")+"]\n")
}

// recDriverCount names each recording driver uniquely, since sql.Register panics on a repeat.
var recDriverCount int64

type recDriver struct{ rec *transcript }

func (d recDriver) Open(string) (driver.Conn, error) { return &recConn{rec: d.rec}, nil }

type recConn struct{ rec *transcript }

func (c *recConn) Prepare(string) (driver.Stmt, error) { return nil, fmt.Errorf("unsupported") }
func (c *recConn) Close() error                        { return nil }
func (c *recConn) Begin() (driver.Tx, error)           { return nil, fmt.Errorf("unsupported") }

func (c *recConn) QueryContext(_ context.Context, query string, args []driver.NamedValue) (driver.Rows, error) {
	c.rec.add(query, args)
	return answerFor(query), nil
}

// answerFor gives the few single-row answers the loaders need to reach their later queries: the
// column probes say the columns exist, the id list has one id and the jobs timestamp has a value.
func answerFor(query string) driver.Rows {
	switch {
	case strings.Contains(query, "information_schema"):
		return &recRows{cols: []string{"n"}, rows: [][]driver.Value{{int64(1)}}}
	case strings.Contains(query, "MAX(seenat)"):
		return &recRows{cols: []string{"m"}, rows: [][]driver.Value{{"2026-01-01 00:00:00"}}}
	case strings.Contains(query, "COUNT(*)"):
		return &recRows{cols: []string{"n"}, rows: [][]driver.Value{{int64(0)}}}
	case strings.HasPrefix(strings.TrimSpace(query), "SELECT msgid FROM rippling_reach"):
		return &recRows{cols: []string{"msgid"}, rows: [][]driver.Value{{int64(7)}}}
	}
	return &recRows{}
}

type recRows struct {
	cols []string
	rows [][]driver.Value
	next int
}

func (r *recRows) Columns() []string { return r.cols }
func (r *recRows) Close() error      { return nil }
func (r *recRows) Next(dest []driver.Value) error {
	if r.next >= len(r.rows) {
		return io.EOF
	}
	copy(dest, r.rows[r.next])
	r.next++
	return nil
}

// recordSourceQueries runs every loader entry point against a recording driver and returns what
// it sent.
func recordSourceQueries(t *testing.T) string {
	t.Helper()
	rec := &transcript{}
	name := fmt.Sprintf("spatialrec%d", atomic.AddInt64(&recDriverCount, 1))
	sql.Register(name, recDriver{rec: rec})
	db, err := sql.Open(name, "")
	require.NoError(t, err)
	t.Cleanup(func() { db.Close() })

	// gridColumnsPresent probes once per process; reset it so the probes are recorded.
	gridColsOnce = sync.Once{}
	gridColsPresent = false
	unionColPresent = false

	since := time.Date(2026, 11, 12, 10, 0, 0, 0, time.UTC)
	datasets := []Dataset{
		&LocationsDataset{}, &MessagesDataset{}, &NewsfeedDataset{}, &UserApproxLocsDataset{},
		&GroupsDataset{}, &JobsDataset{}, &PostcodesDataset{}, &ReachDataset{}, &ReachOverflowDataset{},
	}
	for _, ds := range datasets {
		idx, err := CreateIndex(":memory:")
		require.NoError(t, err)
		rec.add("-- "+ds.Name()+" Load", nil)
		_ = ds.Load(db, idx)
		rec.add("-- "+ds.Name()+" ApplyDelta", nil)
		_ = ds.ApplyDelta(db, idx, since)
		if dc, ok := ds.(DriftChecker); ok {
			rec.add("-- "+ds.Name()+" CheckDrift and NoteReconciled", nil)
			_, _, _ = dc.CheckDrift(db, idx)
			_ = dc.NoteReconciled(db)
		}
		idx.Close()
	}

	rec.add("-- postcode lookup", nil)
	saved := placesDB
	placesDB = db
	lookupPostcode("SW1A 2AA")
	placesDB = saved

	return strings.Join(rec.lines, "\n")
}

func TestMySQLSourceQueriesAreUnchanged(t *testing.T) {
	got := recordSourceQueries(t)

	if os.Getenv("UPDATE_GOLDEN") == "1" {
		require.NoError(t, os.MkdirAll("testdata", 0o755))
		require.NoError(t, os.WriteFile(mysqlTranscriptGolden, []byte(got), 0o644))
		return
	}

	want, err := os.ReadFile(mysqlTranscriptGolden)
	require.NoError(t, err, "golden file missing: generate it from the pre-change code with UPDATE_GOLDEN=1")
	require.Equal(t, string(want), got, "the MySQL source queries changed")
}
