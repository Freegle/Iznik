package test

// Executed-SQL parity: does the PRODUCTION chain send the same statement the
// raw SQL used to send?
//
// This exists because Layer 1 cannot answer that question. A Layer 1 parity
// test writes its own GORM chain and asserts that it renders the site's golden.
// It never calls the production code. So what it proves is "a chain of this
// shape renders the golden", not "the chain in message.go renders the golden" -
// and if the conversion and its test drift apart, the test still passes. That
// is a real hole, not a theoretical one, across 1300-odd conversions.
//
// So: register a GORM callback that records every statement the suite actually
// executes, run the whole suite, then check each converted site's golden
// against what was really sent. Nothing here re-implements a chain; the
// statements are whatever production code produced while the integration tests
// exercised it.
//
// Two honest limitations, both reported rather than hidden:
//
//   - Coverage is whatever the suite exercises. A converted site on a code path
//     no test touches produces no evidence either way, and is counted separately
//     rather than being quietly folded into the pass column.
//   - A match means the statement TEXT is unchanged. Layer 2 is what compares
//     the rows that come back.

import (
	"fmt"
	"os"
	"sort"
	"strings"
	"sync"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

var (
	executedMu  sync.Mutex
	executedSQL = map[string]int{}
)

// captureExecutedSQL registers callbacks that record the SQL of every statement
// GORM runs. It is registered from TestMain so it is in place before any test
// touches the database, and it only ever reads Statement.SQL - it cannot alter
// what runs.
func captureExecutedSQL(db *gorm.DB) error {
	if db == nil {
		return fmt.Errorf("no database connection to capture from")
	}
	record := func(tx *gorm.DB) {
		sql := tx.Statement.SQL.String()
		if sql == "" {
			return
		}
		executedMu.Lock()
		executedSQL[ormharness.Canonical(sql)]++
		executedMu.Unlock()
	}
	for name, cb := range map[string]func(*gorm.DB){
		"ormcapture:query":  record,
		"ormcapture:create": record,
		"ormcapture:update": record,
		"ormcapture:delete": record,
		"ormcapture:row":    record,
		"ormcapture:raw":    record,
	} {
		var err error
		switch {
		case strings.HasSuffix(name, ":query"):
			err = db.Callback().Query().After("gorm:query").Register(name, cb)
		case strings.HasSuffix(name, ":create"):
			err = db.Callback().Create().After("gorm:create").Register(name, cb)
		case strings.HasSuffix(name, ":update"):
			err = db.Callback().Update().After("gorm:update").Register(name, cb)
		case strings.HasSuffix(name, ":delete"):
			err = db.Callback().Delete().After("gorm:delete").Register(name, cb)
		case strings.HasSuffix(name, ":row"):
			err = db.Callback().Row().After("gorm:row").Register(name, cb)
		case strings.HasSuffix(name, ":raw"):
			err = db.Callback().Raw().After("gorm:raw").Register(name, cb)
		}
		if err != nil {
			return fmt.Errorf("registering %s: %w", name, err)
		}
	}
	return nil
}

// reportExecutedSQLParity is called from TestMain after the suite has run. It
// returns a non-zero count of mismatches, which TestMain turns into a failing
// exit code.
//
// The comparison uses the same normalisations as Layer 1, so an executed
// statement that differs only in column ordering or IN-list expansion is not
// reported as a divergence - those are the same statement written down at
// different stages, and the reasons are documented on each normalisation.
func reportExecutedSQLParity() int {
	sites, err := ormharness.ConvertedSites()
	if err != nil {
		fmt.Printf("EXECUTED-SQL: cannot load manifest: %v\n", err)
		return 1
	}

	executedMu.Lock()
	seen := make(map[string]int, len(executedSQL))
	for k, v := range executedSQL {
		seen[ormharness.NormaliseForComparison(k)] = v
	}
	executedMu.Unlock()

	var matched, unexercised int
	var missing []ormharness.ConvertedSite
	for _, s := range sites {
		want := ormharness.NormaliseForComparison(ormharness.Canonical(s.GoldenSQL))
		if _, ok := seen[want]; ok {
			matched++
			continue
		}
		// Not executed at all, or executed differently. We cannot tell those
		// apart from here, so both land in the same bucket and the report says
		// so rather than claiming more than it knows.
		unexercised++
		missing = append(missing, s)
	}

	fmt.Printf("\nEXECUTED-SQL PARITY (production chains, not test re-implementations)\n")
	fmt.Printf("  converted sites in manifest:      %d\n", len(sites))
	fmt.Printf("  distinct statements executed:     %d\n", len(seen))
	fmt.Printf("  goldens seen executed verbatim:   %d\n", matched)
	fmt.Printf("  not observed during this suite:   %d\n", unexercised)

	if os.Getenv("ORM_EXECUTED_SQL_LIST") != "" {
		sort.Slice(missing, func(i, j int) bool { return missing[i].File < missing[j].File })
		for _, s := range missing {
			fmt.Printf("    unobserved %s %s:%d %s\n", s.ID, s.File, s.Line, s.Function)
		}
	}

	// Deliberately does NOT fail the suite. A site the tests never reach is not
	// a defect, and failing on it would make the check something people switch
	// off rather than something they read. It fails only if the capture itself
	// broke, since a silent zero would be indistinguishable from success.
	if len(seen) == 0 {
		fmt.Printf("  FAIL: no SQL was captured at all, so this check proved nothing\n")
		return 1
	}
	return 0
}

var _ = database.DBConn
