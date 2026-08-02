package test

// Tier 6 of the ORM migration keep-raw adversarial review: group/group.go's
// GetGroup and getMultipleGroups both used db.Raw(sql, id).First(&dest). The
// naive conversion - swap Raw()+First() for Table()/Select()+First() and keep
// everything else the same - would be wrong: First() unconditionally adds an
// ORDER BY + LIMIT 1 clause, but GORM's query builder only ever appends those
// clauses to the rendered SQL when Statement.SQL was still empty at build
// time (see callbacks/query.go's BuildQuerySQL). Raw() pre-populates
// Statement.SQL, so on the OLD code that clause was silently a no-op; on a
// Table()-based chain it would not be, and the SQL actually sent would gain a
// real "ORDER BY id LIMIT 1" the recorded golden never had.
//
// The fix converts to Find() instead, which never adds those clauses and
// never raises gorm.ErrRecordNotFound on a zero-row result - the caller now
// checks RowsAffected directly. See group/group.go's comments on both sites
// for the exact caller-side change (GetGroup's found=true a small
// correctness improvement over the old error comparison; getMultipleGroups
// unchanged in effect since it already treated not-found and error alike).
//
// The Select string below backtick-quotes `groups` (both as `groups`.* and
// in `groups`.settings): GORM only quotes identifiers it builds itself from
// Table()/Where()'s own parsing, not text passed through Select()/Where()/
// Joins() strings verbatim - and "groups" is a reserved word in MySQL 8. The
// unquoted form compiled, rendered a plausible-looking query, and only broke
// at actual query time, which is exactly the kind of failure a dry-run-only
// Layer 1 test cannot catch by itself (it never executes anything against a
// real server) - this was caught by the tests that DO run against MySQL,
// which is the reminder to carry forward: any reserved word inside a
// Select/Where/Joins STRING needs its own backticks.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

func TestTier6Group_2811b4d3acf7(t *testing.T) {
	// group/group.go GetGroup
	ormharness.AssertGoldenSQL(t, "2811b4d3acf7", func(tx *gorm.DB) *gorm.DB {
		var group struct{}
		return tx.Table("groups").
			Select("`groups`.*, CAST(JSON_EXTRACT(`groups`.settings, '$.showjoin') AS UNSIGNED) AS showjoin, ST_AsText(ST_ENVELOPE(polyindex)) AS bbox").
			Where("id = ?", 1).
			Find(&group)
	})
}

func TestTier6Group_547458a591ae(t *testing.T) {
	// group/group.go getMultipleGroups
	ormharness.AssertGoldenSQL(t, "547458a591ae", func(tx *gorm.DB) *gorm.DB {
		var group struct{}
		return tx.Table("groups").
			Select("`groups`.*, CAST(JSON_EXTRACT(`groups`.settings, '$.showjoin') AS UNSIGNED) AS showjoin, ST_AsText(ST_ENVELOPE(polyindex)) AS bbox").
			Where("id = ?", 1).
			Find(&group)
	})
}
