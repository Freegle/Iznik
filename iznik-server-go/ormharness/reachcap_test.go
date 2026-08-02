package ormharness

// Reach-cap pre-flight (review of the 10 "Reach-cap WHERE fragment (plan 7.5
// named)" keep-raw sites in isochrone/message.go).
//
// utils.AuthorReachCapWhere is a package-level `const string` WHERE fragment,
// today string-concatenated onto three different db.Raw call sites
// (isochrone/message.go: fetchReachCandidates, nearbyCount; message/search.go:
// nearbyFeedMsgIDs). The keep-raw reason says it must stay raw because it
// "combines three things that each translate differently" (JSON extraction,
// haversine trig, spatial predicates) and should be "port[ed] ... behind the
// repository layer plan 7.5 asks for" with "its own integration test before
// any caller moves".
//
// That reasoning is about the SQL dialect (true, and out of scope for a
// GORM-vs-raw question), not about whether GORM can compose the fragment. It
// can: gorm.io/gorm@v1.31.0 chainable_api.go:376's own doc comment for
// DB.Scopes gives exactly this shape -
//
//	func OrderStatus(status []string) func (db *gorm.DB) *gorm.DB {
//	    return func (db *gorm.DB) *gorm.DB {
//	        return db.Scopes(AmountGreaterThan1000).Where("status in (?)", status)
//	    }
//	}
//
// a closure over per-call dynamic values (here: sentinel, viewerLat,
// viewerLng) that adds a Where condition, composed onto ANY chain via
// db.Scopes(...). authorReachCapScope below is that shape applied to the
// EXISTING utils.AuthorReachCapWhere constant, unmodified apart from
// stripping its leading "AND " (the splice glue the raw callers rely on -
// GORM's own Where-chaining supplies that glue instead, per clause/where.go's
// Where.MergeClause, which appends each Where call's Exprs after the ones
// already present. clause.Where{Exprs: conds} in chainable_api.go:207-213 is
// literally string+args -> Expr, so this is not a special case of Where, it
// is what Where always does).
//
// These two tests are the evidence for two separate claims:
//   - the scope's own SQL (JSON_EXTRACT / ACOS) survives GORM rendering
//     verbatim when composed onto a multi-join chain (TestReachCap_...Fetch)
//   - the SAME scope function, unmodified, composes correctly onto a second,
//     differently-shaped chain (a COUNT rather than a row SELECT) - the
//     "reusable rather than re-spliced" property plan 7.5 and the review
//     question both ask about (TestReachCap_...Reused)
//
// Bind order is asserted explicitly (not just "the SQL contains the text"):
// clause.Where.MergeClause appends, so the scope's four binds (sentinel,
// viewerLat, viewerLng, viewerLat) must land AFTER every earlier Where/Joins
// bind and in that exact order. A silent reordering here would not be a
// syntax error - MySQL would happily bind the wrong float to the wrong
// placeholder and return a plausible, wrong result set.

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// authorReachCapScope is the reusable-GORM-scope shape for
// utils.AuthorReachCapWhere. sentinel/viewerLat/viewerLng are the same three
// per-request values every existing raw call site already resolves before
// splicing the constant in (BrowseDistanceUnlimited, latlng.Lat, latlng.Lng).
// The clause itself is utils.AuthorReachCapWhere VERBATIM - proving the
// existing constant slots into Scopes unmodified, not a rewritten copy - with
// only its leading "AND " trimmed, because db.Where supplies that glue itself
// when composed after other Where/Scopes calls.
func authorReachCapScope(sentinel, viewerLat, viewerLng float64) func(*gorm.DB) *gorm.DB {
	frag := strings.TrimPrefix(strings.TrimSpace(utils.AuthorReachCapWhere), "AND ")
	return func(db *gorm.DB) *gorm.DB {
		return db.Where(frag, sentinel, viewerLat, viewerLng, viewerLat)
	}
}

// TestReachCap_ScopeComposesOntoFetchReachCandidatesShape renders the
// fetchReachCandidates shape (isochrone/message.go:160, site 5adca7e5928e):
// a multi-join row SELECT with a bound LEFT JOIN and two prior Where calls,
// then the reach-cap scope on top.
func TestReachCap_ScopeComposesOntoFetchReachCandidatesShape(t *testing.T) {
	sql, vars, err := renderDryRun(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial ms").
			Select("ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, ms.msgid AS id").
			Joins("INNER JOIN messages m ON m.id = ms.msgid").
			Joins("INNER JOIN users au ON au.id = m.fromuser").
			Joins("INNER JOIN rippling_reach rr ON rr.msgid = ms.msgid").
			Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", uint64(42), "View").
			Where("ms.successful = 0").
			Where("rr.status != 'held'").
			Scopes(authorReachCapScope(9007199254740991, 51.5, -0.1)).
			Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}

	upper := strings.ToUpper(sql)
	for _, want := range []string{
		"JSON_EXTRACT(AU.SETTINGS",
		"ACOS(LEAST(1.0",
		"RADIANS(ST_Y(MS.POINT))",
	} {
		if !strings.Contains(upper, want) {
			t.Fatalf("expected the reach-cap clause to survive rendering (missing %q): %s", want, sql)
		}
	}

	// 2 binds from the bound LEFT JOIN, then the scope's 4 - in that order.
	// A swap anywhere here binds the wrong value to the wrong placeholder
	// silently; MySQL does not reject it, it just returns wrong rows.
	if len(vars) != 6 {
		t.Fatalf("expected 6 bind values (2 join + 4 reach-cap), got %d: %v", len(vars), vars)
	}
	wantTail := []any{9007199254740991.0, 51.5, -0.1, 51.5}
	gotTail := vars[2:]
	for i, w := range wantTail {
		if gotTail[i] != w {
			t.Fatalf("reach-cap bind %d: want %v, got %v (full vars: %v)", i, w, gotTail[i], vars)
		}
	}
}

// TestReachCap_ScopeReusedOnNearbyCountShape reuses the EXACT SAME
// authorReachCapScope closure from the previous test, unmodified, on a
// second and differently-shaped chain: the nearbyCount COUNT(DISTINCT ...)
// query (isochrone/message.go:694, site 73c548fa7cce), which today
// re-splices utils.AuthorReachCapWhere as a second, independent string
// concatenation. This is the "reusable scope, not a spliced string per
// caller" property the review question asks about.
func TestReachCap_ScopeReusedOnNearbyCountShape(t *testing.T) {
	sql, vars, err := renderDryRun(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_spatial ms").
			Select("COUNT(DISTINCT ms.msgid)").
			Joins("INNER JOIN messages m ON m.id = ms.msgid").
			Joins("INNER JOIN users au ON au.id = m.fromuser").
			Joins("INNER JOIN rippling_reach rr ON rr.msgid = ms.msgid").
			Joins("LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ?", uint64(7), "View").
			Where("ms.successful = 0 AND ml.msgid IS NULL").
			Scopes(authorReachCapScope(9007199254740991, 51.5, -0.1)).
			Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}

	upper := strings.ToUpper(sql)
	if !strings.Contains(upper, "COUNT(DISTINCT MS.MSGID)") {
		t.Fatalf("expected the literal COUNT(DISTINCT ...) select to survive: %s", sql)
	}
	if !strings.Contains(upper, "JSON_EXTRACT(AU.SETTINGS") {
		t.Fatalf("expected the SAME reach-cap clause to render on a second, differently-shaped chain: %s", sql)
	}

	// 2 join binds, then the scope's 4 - same order guarantee as above, on a
	// completely different statement shape, proving this is a property of
	// the scope (and of GORM's Where-append ordering), not an artefact of
	// one particular query's layout.
	if len(vars) != 6 {
		t.Fatalf("expected 6 bind values (2 join + 4 reach-cap), got %d: %v", len(vars), vars)
	}
	wantTail := []any{9007199254740991.0, 51.5, -0.1, 51.5}
	gotTail := vars[2:]
	for i, w := range wantTail {
		if gotTail[i] != w {
			t.Fatalf("reach-cap bind %d: want %v, got %v (full vars: %v)", i, w, gotTail[i], vars)
		}
	}
}
