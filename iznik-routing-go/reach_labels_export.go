package main

// Offline label export.
//
// A partition rebuild renumbers the regions that every stored reach_labels blob
// refers to, so all stored labels go stale at once — on 2026-09-03 that emptied
// every member's nearby feed the moment new artifacts were loaded. Regenerating
// them in the database row by row is not a fix either: mid-apply the engine
// serves ONE partition while the table holds a mix, so a growing fraction of
// members is wrong throughout.
//
// This command does the expensive half offline instead. It loads an engine from
// an arbitrary REACH_DIR (the NEW artifacts, which need not be the ones the
// live server is using), reads each post's coordinates and budget, computes the
// label against that partition, and writes msgid+blob to a file. It never
// writes to the database, so it is safe to run while the old artifacts serve.
//
// The output is then applied in one atomic step at cutover — build a new table
// and RENAME, rather than updating in place — so no member ever sees a table
// whose labels disagree with the loaded partition.
//
//   ./iznik-routing-go reach labels-export --dir /path/to/new/artifacts \
//        --out /path/labels.bin [--limit N] [--workers N]
//
// File format (little-endian):
//   magic  "FRLX"                     4 bytes
//   version uint32 = 1                4
//   partFP  uint64                    8   the partition these labels are for
//   count   uint64                    8   number of records that follow
//   records: msgid uint64, len uint32, blob []byte
//
// The partFP header is the whole point: an apply step MUST refuse to load a
// file whose fingerprint does not match the artifacts being cut over to.

import (
	"bufio"
	"database/sql"
	"encoding/binary"
	"flag"
	"fmt"
	"log"
	"os"
	"sync"
	"time"
)

const labelsExportMagic = "FRLX"
const labelsExportVersion = uint32(1)

type labelExportRow struct {
	msgid    uint64
	lat      float64
	lng      float64
	driveMin float64
}

func reachLabelsExportCmd(args []string) {
	fs := flag.NewFlagSet("labels-export", flag.ExitOnError)
	dir := fs.String("dir", "", "artifact directory to load the engine from (defaults to REACH_DIR)")
	out := fs.String("out", "", "output file (required)")
	limit := fs.Int("limit", 0, "max rows to export (0 = all); for timing a sample")
	workers := fs.Int("workers", 4, "parallel label computations")
	_ = fs.Parse(args)

	if *out == "" {
		log.Fatalf("labels-export: --out is required")
	}
	artDir := *dir
	if artDir == "" {
		artDir = getenv("REACH_DIR", "")
	}
	if artDir == "" {
		log.Fatalf("labels-export: --dir or REACH_DIR required")
	}

	log.Printf("labels-export: loading engine from %s", artDir)
	engStart := time.Now()
	eng, err := loadReachEngineFromDir(artDir)
	if err != nil {
		log.Fatalf("labels-export: cannot load engine from %s: %v", artDir, err)
	}
	log.Printf("labels-export: engine ready in %v (partFP %d, %d regions)",
		time.Since(engStart).Round(time.Millisecond), eng.partFP, len(eng.Part.LeafNodes))

	rows := loadLabelExportRows(*limit)
	if len(rows) == 0 {
		log.Fatalf("labels-export: no rows to export")
	}
	log.Printf("labels-export: %d rows to compute", len(rows))

	// Compute in parallel, collect in order-independent slots: the file is
	// keyed by msgid, so ordering does not matter to the apply step.
	blobs := make([][]byte, len(rows))
	var wg sync.WaitGroup
	ch := make(chan int, len(rows))
	for i := range rows {
		ch <- i
	}
	close(ch)
	var done int64
	var mu sync.Mutex
	start := time.Now()
	for w := 0; w < *workers; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for i := range ch {
				r := rows[i]
				lbl := eng.QueryLabels(r.lat, r.lng, float32(r.driveMin*60))
				blobs[i] = eng.EncodeLabels(lbl)
				mu.Lock()
				done++
				n := done
				mu.Unlock()
				if n%2000 == 0 {
					rate := float64(n) / time.Since(start).Seconds()
					log.Printf("labels-export: %d/%d (%.0f rows/s, ETA %s)",
						n, len(rows), rate,
						time.Duration(float64(len(rows)-int(n))/rate*float64(time.Second)).Round(time.Second))
				}
			}
		}()
	}
	wg.Wait()
	log.Printf("labels-export: computed %d labels in %v", len(rows), time.Since(start).Round(time.Second))

	f, err := os.Create(*out)
	if err != nil {
		log.Fatalf("labels-export: create %s: %v", *out, err)
	}
	defer f.Close()
	w := bufio.NewWriterSize(f, 1<<20)
	if _, err := w.WriteString(labelsExportMagic); err != nil {
		log.Fatalf("labels-export: write: %v", err)
	}
	must := func(v any) {
		if err := binary.Write(w, binary.LittleEndian, v); err != nil {
			log.Fatalf("labels-export: write: %v", err)
		}
	}
	must(labelsExportVersion)
	must(eng.partFP)
	must(uint64(len(rows)))
	var bytesOut int
	for i, r := range rows {
		must(r.msgid)
		must(uint32(len(blobs[i])))
		if _, err := w.Write(blobs[i]); err != nil {
			log.Fatalf("labels-export: write: %v", err)
		}
		bytesOut += len(blobs[i])
	}
	if err := w.Flush(); err != nil {
		log.Fatalf("labels-export: flush: %v", err)
	}
	log.Printf("labels-export: wrote %s (%d records, %d MB of label data, partFP %d)",
		*out, len(rows), bytesOut/(1<<20), eng.partFP)
}

// loadLabelExportRows reads the same population ripple:backfill-reach-labels
// walks: every row with a budget. Read-only — this command never writes.
func loadLabelExportRows(limit int) []labelExportRow {
	dsn := groupsDSN()
	if dsn == "" {
		log.Fatalf("labels-export: no database configuration (MYSQL_HOST etc.)")
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Fatalf("labels-export: db open: %v", err)
	}
	defer db.Close()
	if err := db.Ping(); err != nil {
		log.Fatalf("labels-export: db ping: %v", err)
	}

	q := "SELECT msgid, lat, lng, max_drive_min FROM rippling_reach WHERE max_drive_min > 0 ORDER BY msgid"
	if limit > 0 {
		q += fmt.Sprintf(" LIMIT %d", limit)
	}
	rs, err := db.Query(q)
	if err != nil {
		log.Fatalf("labels-export: query: %v", err)
	}
	defer rs.Close()

	var out []labelExportRow
	for rs.Next() {
		var r labelExportRow
		if err := rs.Scan(&r.msgid, &r.lat, &r.lng, &r.driveMin); err != nil {
			log.Fatalf("labels-export: scan: %v", err)
		}
		out = append(out, r)
	}
	if err := rs.Err(); err != nil {
		log.Fatalf("labels-export: rows: %v", err)
	}
	return out
}
