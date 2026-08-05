package test

// Parity tests for userdump/collect_db.go and userdump/userdump.go, sites
// f0543b22c8e8 (existingTables), 1722b492f85b (runDBSpec) and 823b16eb87c6
// (gatherEmails).
//
// These were keep-raw with the reason "consistent with the existing
// userdump/sqlite.go entry" - which was wrong. sqlite.go's six sites write a
// SQLite export FILE (genuinely out of scope: no MySQL involved). These read
// the Percona cluster - the dump pipeline calls database.DBConn.DB() to get
// a raw *sql.DB and threads it through buildDump/buildPlan/scanIDs/
// runDBSpec, but that unwrapped handle is the SAME connection GORM already
// wraps. The actual (correct) blocker in the old reason was that a signature
// change was needed across all four functions and their two callers - not a
// redesign, since all four only ever needed "a handle to query MySQL", and
// gorm.DB already being that handle made the change mechanical. See the ORM
// migration site comments in collect_db.go/userdump.go for the per-function
// detail.
//
// scanIDs (e7d7cae307a7) is NOT in this file and stays keep-raw: it is a
// generic "run this query text, scan the first column as int64" helper -
// the SQL it executes is a parameter, decided at each of its three call
// sites in userdump.go's buildPlan, not by scanIDs itself. Swapping its
// *sql.DB for *gorm.DB (sqlDB.Query -> gdb.Raw(...).Rows()) was still done,
// because buildDump/buildPlan need one handle type shared by all four
// functions, but that is a connection-handle change, not a removal of raw
// SQL - the same category as database/insert.go's ExecInsertGetID and
// database/retry.go's wrappers, which are keep-raw as generic executors
// rather than converted or inventoried per caller.
//
// existingTables and gatherEmails are fixed literal statements (Layer 1,
// AssertGoldenSQL). runDBSpec takes its table name and where-fragment from a
// Go variable (one of buildDBSpecs' 61 hardcoded (table, where) pairs, never
// user input) - the manifest's own goldenSql for it is
// "{{built at runtime: q}}", so Layer 1 does not apply; Layer 2
// (AssertResultParityForSite) proves it against the seeded database
// instead, the same reasoning as orm_reviewqueue_test.go's two sites.

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// existingTables: SELECT table_name FROM information_schema.tables WHERE
// table_schema = DATABASE() - fixed, no args.
func TestGolden_f0543b22c8e8(t *testing.T) {
	var dest []string
	ormharness.AssertGoldenSQL(t, "f0543b22c8e8", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("information_schema.tables").Select("table_name").Where("table_schema = DATABASE()").Find(&dest)
	})
}

// gatherEmails: SELECT email FROM users_emails WHERE userid = ? - fixed,
// one arg.
func TestGolden_823b16eb87c6(t *testing.T) {
	var dest []string
	ormharness.AssertGoldenSQL(t, "823b16eb87c6", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_emails").Select("email").Where("userid = ?", uint64(1)).Find(&dest)
	})
}

// runDBSpec: SELECT * FROM `<table>` WHERE <where> [ORDER BY 1 DESC LIMIT
// <n>], where table/where/args/limit come from one of the 61 dbSpec entries
// buildDBSpecs assembles - always a fixed literal table name and a fixed
// literal where-fragment (never user input), just a different one per spec.
// No single golden text applies (the manifest's own extraction gives up with
// "{{built at runtime: q}}"), so this proves the two shapes runDBSpec's body
// actually branches on: plain WHERE (the majority of specs, e.g.
// users_emails), and capped+ordered (sessions, messages_by, logs, ...). A
// third case covers the IN-clause shape inClause() produces for the
// chat/message/email-tracking child-table specs.
func TestLayer2_1722b492f85b_PlainWhere(t *testing.T) {
	ormharness.AssertResultParityForSite(t, "1722b492f85b", database.DBConn,
		"SELECT * FROM `users_emails` WHERE userid = ?", []any{uint64(1)},
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("users_emails").Where("userid = ?", uint64(1))
		})
}

func TestLayer2_1722b492f85b_CappedAndOrdered(t *testing.T) {
	ormharness.AssertResultParityForSite(t, "1722b492f85b", database.DBConn,
		"SELECT * FROM `sessions` WHERE userid = ? ORDER BY 1 DESC LIMIT 1000", []any{uint64(1)},
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("sessions").Where("userid = ?", uint64(1)).Order("1 DESC").Limit(1000)
		})
}

func TestLayer2_1722b492f85b_INClause(t *testing.T) {
	ids := []any{int64(1), int64(2), int64(3)}
	ormharness.AssertResultParityForSite(t, "1722b492f85b", database.DBConn,
		"SELECT * FROM `messages_groups` WHERE msgid IN (?,?,?)", ids,
		func(tx *gorm.DB) *gorm.DB {
			return tx.Table("messages_groups").Where("msgid IN (?,?,?)", ids...)
		})
}
