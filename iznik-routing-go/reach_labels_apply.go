package main

// Offline label apply - the other half of labels-export.
//
// Stages an export file's contents beside the live data, where nothing reads
// them until the cutover:
//
//   - the label blob  -> rippling_reach.reach_labels_next, stamped with
//                        reach_labels_next_fp = the file's partition
//   - the leaf set    -> rippling_reach_leaves (--leaves-table), stamped with
//                        the new fp, alongside the old partition's rows
//
// The stored reach_labels that decide membership today are never touched, and
// the old partition's leaf rows stay exactly as they are. Writing new labels
// into reach_labels itself, one row at a time, would leave the table holding
// a mix of two partitions while the engine serves one - a growing fraction of
// posts answering "not in reach" for the entire duration. Staging beside the
// live data and switching by fingerprint has no such window.
//
// Leaves can share the live table only because its UNIQUE key was widened to
// (msgid, leaf, fp). Under the original (msgid, leaf) key an INSERT IGNORE of
// a new-partition row colliding with an old one is silently dropped and the
// post goes undiscoverable after the switch - so the command checks the key
// shape from information_schema and refuses a table without it. The leaf
// loader already filters by fp, so the old rows are invisible to the new
// engine and vice versa; nothing else needs to change.
//
//   ./iznik-routing-go reach labels-apply --file /path/labels.bin \
//        --expect-fp <partFP> [--leaves-table rippling_reach_leaves] \
//        [--sleep-ms N] [--limit N] [--dry-run]
//
// --expect-fp is mandatory and must equal the file header's fingerprint. It
// is the operator saying out loud which partition they are staging; a file
// for some other build is refused before a single row is written.
//
// origin_union_secs is in the file but is NOT written here: it lives in a
// live-read column with no staging twin. It is a road time, not a region
// number, so the old value stays valid across the switch; refreshing it is a
// separate, unhurried step after cutover.
//
// One UPDATE per post, autocommit, paced by --sleep-ms: the Galera rule for
// any bulk change to the 7 GB table. Leaves go in per-post chunks of 500, the
// same shape ReachService::storeLabels uses on the live table. Idempotent: a
// post already stamped with this fingerprint is skipped, leaf inserts are
// INSERT IGNORE, so a top-up run after a fresh export only pays for what
// changed.

import (
	"bufio"
	"database/sql"
	"encoding/binary"
	"flag"
	"fmt"
	"io"
	"log"
	"os"
	"regexp"
	"strings"
	"time"
)

var leavesTableName = regexp.MustCompile(`^[A-Za-z0-9_]+$`)

func reachLabelsApplyCmd(args []string) {
	fs := flag.NewFlagSet("labels-apply", flag.ExitOnError)
	file := fs.String("file", "", "export file from labels-export (required)")
	expectFP := fs.Uint64("expect-fp", 0, "partition fingerprint the file MUST carry (required)")
	leavesTable := fs.String("leaves-table", "", "staging table for leaf rows (omit to stage labels only)")
	sleepMs := fs.Int("sleep-ms", 20, "pause between posts")
	limit := fs.Int("limit", 0, "max posts to apply (0 = all); for timing a sample")
	dryRun := fs.Bool("dry-run", false, "read and validate the file, write nothing")
	_ = fs.Parse(args)

	if *file == "" {
		log.Fatalf("labels-apply: --file is required")
	}
	if *expectFP == 0 {
		log.Fatalf("labels-apply: --expect-fp is required (the partition you are staging)")
	}
	if *leavesTable != "" && !leavesTableName.MatchString(*leavesTable) {
		log.Fatalf("labels-apply: --leaves-table %q is not a plain table name", *leavesTable)
	}

	f, err := os.Open(*file)
	if err != nil {
		log.Fatalf("labels-apply: open %s: %v", *file, err)
	}
	defer f.Close()
	r := bufio.NewReaderSize(f, 1<<20)

	magic := make([]byte, 4)
	if _, err := io.ReadFull(r, magic); err != nil || string(magic) != labelsExportMagic {
		log.Fatalf("labels-apply: %s is not a labels export (magic %q)", *file, magic)
	}
	var version uint32
	var fileFP, count uint64
	read := func(v any) {
		if err := binary.Read(r, binary.LittleEndian, v); err != nil {
			log.Fatalf("labels-apply: read: %v", err)
		}
	}
	read(&version)
	if version != labelsExportVersion {
		log.Fatalf("labels-apply: file version %d, want %d (re-run labels-export)", version, labelsExportVersion)
	}
	read(&fileFP)
	read(&count)
	if fileFP != *expectFP {
		log.Fatalf("labels-apply: REFUSING: file was built for partition %d but --expect-fp is %d",
			fileFP, *expectFP)
	}
	log.Printf("labels-apply: %s: %d posts for partition %d", *file, count, fileFP)

	var db *sql.DB
	if !*dryRun {
		dsn := groupsDSN()
		if dsn == "" {
			log.Fatalf("labels-apply: no database configuration (MYSQL_HOST etc.)")
		}
		if db, err = sql.Open("mysql", dsn); err != nil {
			log.Fatalf("labels-apply: db open: %v", err)
		}
		defer db.Close()
		if err := db.Ping(); err != nil {
			log.Fatalf("labels-apply: db ping: %v", err)
		}
		if *leavesTable != "" {
			// Two partitions' rows can only share a table whose UNIQUE key
			// includes fp. Without that, INSERT IGNORE silently drops any
			// new-partition row that collides with an old (msgid, leaf) pair
			// and the post goes undiscoverable after the switch. Decided from
			// information_schema, not the table's name: the live table is
			// fine once its key has been widened, and a staging twin created
			// before the widening is not.
			var n int
			if err := db.QueryRow(
				"SELECT COUNT(*) FROM information_schema.statistics s "+
					"WHERE s.table_schema = DATABASE() AND s.table_name = ? AND s.non_unique = 0 "+
					"AND s.index_name <> 'PRIMARY' "+
					"AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics "+
					"     WHERE table_schema = s.table_schema AND table_name = s.table_name AND index_name = s.index_name) "+
					"    = 'msgid,leaf,fp'",
				*leavesTable).Scan(&n); err != nil {
				log.Fatalf("labels-apply: inspect %s: %v", *leavesTable, err)
			}
			if n == 0 {
				log.Fatalf("labels-apply: REFUSING: %s has no UNIQUE (msgid, leaf, fp) key, so a second "+
					"partition's rows cannot coexist in it - widen the key first", *leavesTable)
			}
		}
	}

	const labelQ = "UPDATE rippling_reach SET reach_labels_next = ?, reach_labels_next_fp = ? " +
		"WHERE msgid = ? AND (reach_labels_next_fp IS NULL OR reach_labels_next_fp <> ?)"

	var (
		seen, written, skipped, missing, leafRows, leafInserted uint64
		bytesOut                                                int
		start                                                   = time.Now()
		pause                                                   = time.Duration(*sleepMs) * time.Millisecond
	)
	for i := uint64(0); i < count; i++ {
		if *limit > 0 && seen >= uint64(*limit) {
			break
		}
		var msgid uint64
		var unionSecs float32
		var labelLen, leafCount uint32
		read(&msgid)
		read(&unionSecs)
		read(&labelLen)
		blob := make([]byte, labelLen)
		if _, err := io.ReadFull(r, blob); err != nil {
			log.Fatalf("labels-apply: post %d (msgid %d): truncated label: %v", i, msgid, err)
		}
		read(&leafCount)
		leaves := make([]int32, leafCount)
		for j := range leaves {
			read(&leaves[j])
		}
		seen++
		bytesOut += int(labelLen)
		leafRows += uint64(leafCount)
		if *dryRun {
			continue
		}

		res, err := db.Exec(labelQ, blob, fileFP, msgid, fileFP)
		if err != nil {
			log.Fatalf("labels-apply: msgid %d: %v", msgid, err)
		}
		if aff, _ := res.RowsAffected(); aff == 1 {
			written++
		} else {
			// Either already stamped with this fp, or the post is gone (purged
			// since the export). Only tell them apart when it happens.
			var exists int
			if err := db.QueryRow("SELECT 1 FROM rippling_reach WHERE msgid = ?", msgid).Scan(&exists); err == sql.ErrNoRows {
				missing++
				continue // no leaves for a post that no longer exists
			}
			skipped++
		}

		if *leavesTable != "" && leafCount > 0 {
			for lo := 0; lo < len(leaves); lo += 500 {
				hi := lo + 500
				if hi > len(leaves) {
					hi = len(leaves)
				}
				chunk := leaves[lo:hi]
				vals := make([]string, len(chunk))
				argv := make([]any, 0, len(chunk)*3)
				for k, leaf := range chunk {
					vals[k] = "(?, ?, ?)"
					argv = append(argv, msgid, leaf, fileFP)
				}
				ins, err := db.Exec("INSERT IGNORE INTO "+*leavesTable+" (msgid, leaf, fp) VALUES "+
					strings.Join(vals, ","), argv...)
				if err != nil {
					log.Fatalf("labels-apply: leaves for msgid %d: %v", msgid, err)
				}
				n, _ := ins.RowsAffected()
				leafInserted += uint64(n)
			}
		}

		if seen%1000 == 0 {
			rate := float64(seen) / time.Since(start).Seconds()
			log.Printf("labels-apply: %d/%d (written %d, skipped %d, missing %d, leaves +%d; %.1f posts/s, ETA %s)",
				seen, count, written, skipped, missing, leafInserted, rate,
				time.Duration(float64(count-seen)/rate*float64(time.Second)).Round(time.Second))
		}
		if pause > 0 {
			time.Sleep(pause)
		}
	}

	if *dryRun {
		log.Printf("labels-apply: DRY RUN: %d posts read cleanly (%d MB of labels, %d leaf rows), nothing written",
			seen, bytesOut/(1<<20), leafRows)
		return
	}
	log.Printf("labels-apply: done in %v: %d read, %d written, %d already staged, %d missing, %d leaf rows inserted",
		time.Since(start).Round(time.Second), seen, written, skipped, missing, leafInserted)

	// Verify from the database, not from our own counters.
	var staged uint64
	if err := db.QueryRow("SELECT COUNT(*) FROM rippling_reach WHERE reach_labels_next_fp = ?", fileFP).Scan(&staged); err != nil {
		log.Fatalf("labels-apply: verify: %v", err)
	}
	msg := fmt.Sprintf("%d posts carry partition %d in reach_labels_next", staged, fileFP)
	if *leavesTable != "" {
		var lr, lp uint64
		if err := db.QueryRow("SELECT COUNT(*), COUNT(DISTINCT msgid) FROM "+*leavesTable+" WHERE fp = ?", fileFP).Scan(&lr, &lp); err != nil {
			log.Fatalf("labels-apply: verify leaves: %v", err)
		}
		msg += fmt.Sprintf("; %s holds %d leaf rows for %d posts", *leavesTable, lr, lp)
	}
	log.Printf("labels-apply: verify: %s", msg)
	if *limit == 0 && staged < count-missing {
		log.Printf("labels-apply: WARNING: expected %d staged (posts minus missing), database says %d",
			count-missing, staged)
		os.Exit(2)
	}
}
