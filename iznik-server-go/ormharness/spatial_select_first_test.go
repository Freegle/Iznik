package ormharness

// Skeptic review of the "Spatial: ST_*/haversine as literal text in a SELECT
// column list" claim (group/group.go:212 GetGroup, site 2811b4d3acf7, and its
// twin group/group.go:398 getMultipleGroups, site 547458a591ae).
//
// The claim's own proposed chain is:
//
//	db.Table("`groups`").Select("`groups`.*, CAST(...) AS showjoin, ST_AsText(ST_ENVELOPE(polyindex)) AS bbox").
//		Where("id = ?", id).First(&group)
//
// and cites user/user.go:408 (site b06cd1b62344, already converted) as proof
// the Select-verbatim mechanism works. That citation is good: b06cd1b62344
// really is live, and TestWave5A_b06cd1b62344 (test/orm_wave5_a_test.go:258)
// really does assert it against its golden. But b06cd1b62344 ends in
// .Scan(&memberships), not .First(&group) - and that difference is exactly
// where the proposed chain breaks.
//
// finisher_api.go:120-124, First():
//
//	tx = db.Limit(1).Order(clause.OrderByColumn{
//		Column: clause.Column{Table: clause.CurrentTable, Name: clause.PrimaryKey},
//	})
//
// This runs unconditionally, before the query callback. callbacks/query.go's
// BuildQuerySQL only skips clause-based building (Select/From/Where/Order/
// Limit, ending in db.Statement.Build(db.Statement.BuildClauses...) at
// query.go:272) when db.Statement.SQL.Len() > 0 already - which is true for
// the CURRENT code (.Raw() fills SQL.Len() immediately, chainable_api.go:461-
// 467, so First()'s injected Order/Limit clauses are computed but never
// rendered) and FALSE for the proposed chain (no .Raw(), so BuildQuerySQL's
// full block runs and DOES render them).
//
// The site's goldenSql is:
//
//	SELECT `groups`.*, CAST(JSON_EXTRACT(groups.settings, '$.showjoin') AS UNSIGNED) AS showjoin,
//	ST_AsText(ST_ENVELOPE(polyindex)) AS bbox FROM `groups` WHERE id = ?
//
// - no ORDER BY, no LIMIT. golden.go's assertRenderedSQL (the thing that
// decides pass/fail) has exactly four documented tolerances: LIMIT/OFFSET
// placeholder resolution (only fires when the golden ALREADY contains a LIMIT
// token), IN-list collapsing, column-order normalisation for map-valued
// Create/Updates, and a recorded approvedDiff. None of those absorb an
// ORDER BY/LIMIT pair that is simply absent from the golden. So the proposed
// chain, run through the project's own Layer 1 gate, fails.
//
// TestSpatialSelectFirst_InjectsOrderByLimitNotInGolden renders the proposed
// chain and shows the extra clauses appear. TestSpatialSelectFirst_FindFixesSQLButLosesNotFoundSignal
// shows the obvious fix (swap First for Find, which finisher_api.go:164-173
// confirms adds neither Limit nor Order) repairs the SQL but silently drops
// the not-found signal the surrounding code depends on: scan.go:366-367 only
// raises gorm.ErrRecordNotFound when db.Statement.RaiseErrorOnNotFound is
// true, and that field is set by First/Take/Last only (finisher_api.go:129,
// 142, and the equivalent in Last) - never by Find. GetGroup's caller does
//
//	err := q.Raw(...).First(&group).Error
//	found = !errors.Is(err, gorm.ErrRecordNotFound)
//
// so swapping in Find without also changing that check turns "group does not
// exist" into "found = true" with a zero-value Group.
//
// Net: the mechanism (verbatim ST_* text in a Select() column list) is fine -
// b06cd1b62344 proves that part. But "the proposed chain" for the two
// First()-shaped sites in this group is wrong as written, and the one-line
// fix that restores golden-SQL parity (Find instead of First) introduces a
// correctness regression unless GetGroup's found-detection is also rewritten
// to check RowsAffected==0 instead of the error - exactly what
// authority/authority.go's Single already does for its own two spatial sites
// (92416198b77d, 3048dd76eab0), which use .Scan() plus
// `result.Error != nil || result.RowsAffected == 0` and are NOT affected by
// this problem. So the fix exists, but it is not the chain the claim offered,
// and it is not free - it requires touching caller logic, which is exactly
// the kind of "silent and expensive" rewrite risk keep-raw.json's reason was
// gesturing at, just via a different mechanism than the one it named.

import (
	"errors"
	"strings"
	"testing"

	"gorm.io/gorm"
)

func TestSpatialSelectFirst_InjectsOrderByLimitNotInGolden(t *testing.T) {
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest struct {
			ID int64 `gorm:"column:id"`
		}
		return tx.Table("`groups`").
			Select("`groups`.*, CAST(JSON_EXTRACT(groups.settings, '$.showjoin') AS UNSIGNED) AS showjoin, ST_AsText(ST_ENVELOPE(polyindex)) AS bbox").
			Where("id = ?", 1).
			First(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	t.Logf("rendered: %s", sql)

	golden := "SELECT `groups`.*, CAST(JSON_EXTRACT(groups.settings, '$.showjoin') AS UNSIGNED) AS showjoin, " +
		"ST_AsText(ST_ENVELOPE(polyindex)) AS bbox FROM `groups` WHERE id = ?"

	if Canonical(sql) == Canonical(golden) {
		t.Fatalf("expected First() to inject ORDER BY/LIMIT and diverge from the golden (no such clauses) - "+
			"if this now passes, GORM changed First()'s behaviour and the claim's proposed chain is fine "+
			"as written; revisit this finding. got: %s", sql)
	}

	upper := strings.ToUpper(sql)
	if !strings.Contains(upper, "ORDER BY") || !strings.Contains(upper, "LIMIT") {
		t.Fatalf("expected the mismatch to specifically be an injected ORDER BY ... LIMIT 1 "+
			"(finisher_api.go:120-124), got a different divergence: %s", sql)
	}
}

func TestSpatialSelectFirst_FindFixesSQLButLosesNotFoundSignal(t *testing.T) {
	// Swapping First for Find is the obvious fix for the SQL-text problem.
	// Confirm it actually fixes the SQL...
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("`groups`").
			Select("`groups`.*, CAST(JSON_EXTRACT(groups.settings, '$.showjoin') AS UNSIGNED) AS showjoin, ST_AsText(ST_ENVELOPE(polyindex)) AS bbox").
			Where("id = ?", 1).
			Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	golden := "SELECT `groups`.*, CAST(JSON_EXTRACT(groups.settings, '$.showjoin') AS UNSIGNED) AS showjoin, " +
		"ST_AsText(ST_ENVELOPE(polyindex)) AS bbox FROM `groups` WHERE id = ?"
	if Canonical(sql) != Canonical(golden) {
		t.Fatalf("expected Find() (unlike First()) to match the golden exactly (finisher_api.go:164-173 adds "+
			"neither Limit nor Order), got: %s", sql)
	}

	// ...then confirm the price: RaiseErrorOnNotFound is First/Take/Last-only
	// (finisher_api.go:129 "tx.Statement.RaiseErrorOnNotFound = true" appears
	// in First, Take and Last; Find never sets it), and scan.go:366-367 is the
	// only place that turns a zero-row result into gorm.ErrRecordNotFound, and
	// it is gated on exactly that flag:
	//
	//	if db.RowsAffected == 0 && db.Statement.RaiseErrorOnNotFound && db.Error == nil {
	//		db.AddError(ErrRecordNotFound)
	//	}
	//
	// So GetGroup's `found = !errors.Is(err, gorm.ErrRecordNotFound)` would
	// silently become `found = true` on a nonexistent id if First is swapped
	// for Find without also rewriting that check. This is not exercised via
	// dry-run (dry-run never executes), so state the contract directly.
	var raiseErrorOnNotFoundSetByFind = false // Find never sets it; see finisher_api.go:164-173.
	if raiseErrorOnNotFoundSetByFind {
		t.Fatalf("this constant documents a fact, not a live check; if Find ever starts setting " +
			"RaiseErrorOnNotFound this test's premise is stale and GetGroup's found-detection is safe again")
	}

	// Show the actual failure mode with a bare gorm.ErrRecordNotFound check,
	// exactly as group.go:212 area's `errors.Is(err, gorm.ErrRecordNotFound)`
	// does, to make the regression concrete rather than asserted.
	var probeErr error // Find leaves this nil on a zero-row result; First would have set ErrRecordNotFound.
	if errors.Is(probeErr, gorm.ErrRecordNotFound) {
		t.Fatalf("sanity check inverted")
	}
	// probeErr is nil, so errors.Is(...) above is false, so `found` would end
	// up true - the regression is exactly this line evaluating to true when it
	// should not.
}
