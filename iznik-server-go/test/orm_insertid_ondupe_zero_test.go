package test

// The hazard the 9 "ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)" sites hit
// that the 49 plain-INSERT sites in this same keep-raw category do not.
//
// gorm.io/gorm/callbacks/create.go (v1.31.0) reads RowsAffected before it ever
// looks at LastInsertId:
//
//	db.RowsAffected, _ = result.RowsAffected()
//	if db.Statement.Result != nil {
//	    db.Statement.Result.Result = result       // <- set unconditionally
//	    db.Statement.Result.RowsAffected = db.RowsAffected
//	}
//	if db.RowsAffected == 0 {
//	    return                                     // <- @id is never written
//	}
//	...
//	insertID, err := result.LastInsertId()
//	...
//	values[pkFieldName] = insertID                 // pkFieldName == "@id"
//
// MySQL's own documented affected-rows accounting for
// "ON DUPLICATE KEY UPDATE col = expr" is: 1 row inserted, 2 rows if an
// existing row's stored values actually change, and 0 if the UPDATE branch
// runs but every column ends up holding the value it already had (this is
// standard behaviour, not specific to any driver - see the "Affected-Rows"
// paragraph on the INSERT ... ON DUPLICATE KEY UPDATE page of the MySQL
// manual). Setting a column to itself never counts as a change.
//
// Three of the nine sites (message/helper.go: ensureBatchRow, helperUpsertReplier,
// helperSetItemState) have *no other* column in the UPDATE clause -
// "ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)" and nothing else - so
// every duplicate-key hit is a guaranteed 0, not an edge case. The other six
// pair it with a second column (latestmessage = NOW()/VALUES(latestmessage),
// or chatid = VALUES(chatid)) that is also frequently unchanged, e.g. two
// messages to the same room within the same clock second, or a repliers row
// upserted twice with the same chatid.
//
// The raw code these sites currently run reads sql.Result.LastInsertId()
// directly and does not care about RowsAffected at all, so it gets the right
// answer every time, including on the id-only duplicate hit. A converted
// Table()+map Create call using the "@id" convention established in
// test/orm_insertid_test.go loses that: on the duplicate branch it silently
// leaves "@id" unset (or, worse, holding a stale value from a previous call
// against the same map - see TestInsertID_MapReuseAcrossCallsLeaksStaleID
// below).
//
// This is a real DB test, not dry-run SQL rendering, because the bug lives in
// runtime RowsAffected, which RenderDryRunSQL cannot see - the existing
// ormharness/upsert_test.go suite for this idiom only checks the rendered SQL
// text and would pass unchanged whether or not this bug exists.
//
// The safe alternative, also demonstrated below, is the OTHER pattern already
// proven in orm_insertid_test.go: gorm.WithResult(). Statement.Result.Result
// is populated before the RowsAffected==0 check, so
// res.Result.LastInsertId() gives the right answer on both branches - insert
// and no-op duplicate alike - exactly matching what the raw sqlDB.Exec code
// does today.

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// idOnlyUpsert reproduces the shape of ensureBatchRow / helperUpsertReplier /
// helperSetItemState: INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
// with no other assignment. domain is unique in this table (see
// orm_insertid_test.go), so a second call with the same domain always takes
// the UPDATE branch and always changes nothing.
func idOnlyUpsert(row map[string]interface{}) error {
	db := database.DBConn
	return db.Table(insertIDProbeTable).
		Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{"id": gorm.Expr("LAST_INSERT_ID(id)")}),
		}).
		Create(row).Error
}

func TestInsertID_OnDuplicateNoOpUpdateLosesAtID(t *testing.T) {
	db := database.DBConn
	domain := "orm-insertid-dupe-1.example"
	defer db.Exec("DELETE FROM "+insertIDProbeTable+" WHERE domain = ?", domain)

	first := map[string]interface{}{"domain": domain, "count": 1}
	if err := idOnlyUpsert(first); err != nil {
		t.Fatalf("first insert: %v", err)
	}
	firstID, ok := first["@id"].(int64)
	if !ok || firstID <= 0 {
		t.Fatalf("first insert did not report an id: %#v", first["@id"])
	}

	// Same domain, same count: the UPDATE branch runs (duplicate key hit) but
	// every column - including the forced id = LAST_INSERT_ID(id) - ends up
	// holding the value it already had. MySQL reports RowsAffected == 0 for
	// this, same as it would for any other no-op ON DUPLICATE KEY UPDATE.
	second := map[string]interface{}{"domain": domain, "count": 1}
	if err := idOnlyUpsert(second); err != nil {
		t.Fatalf("second (duplicate) insert: %v", err)
	}

	secondID, ok := second["@id"].(int64)
	if !ok {
		// This is the hazard: the raw code (sqlDB.Exec + result.LastInsertId())
		// gets firstID back here every time, because it never looks at
		// RowsAffected. A Table()+map Create conversion using the "@id"
		// convention does not - proving that the id-only ON DUPLICATE KEY
		// UPDATE idiom cannot be converted with the plain map pattern the
		// other 49 sites in this category use safely.
		t.Fatalf("@id missing after a no-op duplicate-key UPDATE: RowsAffected==0 short-circuits "+
			"gorm.io/gorm/callbacks/create.go before it reads LastInsertId; the raw code this replaces "+
			"would have returned %d here", firstID)
	}
	if secondID != firstID {
		t.Fatalf("@id after duplicate hit = %d, want the pre-existing row's id %d", secondID, firstID)
	}
}

// TestInsertID_WithResultSurvivesOnDuplicateNoOpUpdate proves the fix: reading
// through gorm.WithResult() instead of the "@id" map key is immune to the
// RowsAffected==0 short-circuit, because Statement.Result.Result is populated
// unconditionally, before that check runs.
func TestInsertID_WithResultSurvivesOnDuplicateNoOpUpdate(t *testing.T) {
	db := database.DBConn
	domain := "orm-insertid-dupe-2.example"
	defer db.Exec("DELETE FROM "+insertIDProbeTable+" WHERE domain = ?", domain)

	upsert := func(count int) (int64, error) {
		res := gorm.WithResult()
		row := map[string]interface{}{"domain": domain, "count": count}
		err := db.Table(insertIDProbeTable).
			Set("gorm:result", res).
			Clauses(clause.OnConflict{
				DoUpdates: clause.Assignments(map[string]interface{}{"id": gorm.Expr("LAST_INSERT_ID(id)")}),
			}).
			Create(row).Error
		if err != nil || res.Result == nil {
			return 0, err
		}
		return res.Result.LastInsertId()
	}

	firstID, err := upsert(1)
	if err != nil {
		t.Fatalf("first insert: %v", err)
	}
	if firstID <= 0 {
		t.Fatalf("first insert got no id")
	}

	// Same domain, same count again: RowsAffected will be 0 on the duplicate
	// branch, exactly as in the test above, but gorm.WithResult() does not
	// care - it already has the sql.Result.
	secondID, err := upsert(1)
	if err != nil {
		t.Fatalf("second (duplicate) insert: %v", err)
	}
	if secondID != firstID {
		t.Fatalf("gorm.WithResult() id after duplicate hit = %d, want %d", secondID, firstID)
	}
}

// TestInsertID_MapReuseAcrossCallsLeaksStaleID is go-gorm/gorm#7075's shape
// combined with the RowsAffected==0 hazard above: Create's map-Create branch
// mutates the caller's map in place. If the SAME map object is used for a
// second Create call that hits RowsAffected==0, GORM does not clear the old
// "@id" - it just doesn't touch the map at all - so a caller that reads
// row["@id"] after the second call unknowingly gets the FIRST call's id back,
// with no error and no missing-key signal. This is worse than the bare
// missing-key case above: it looks like success.
func TestInsertID_MapReuseAcrossCallsLeaksStaleID(t *testing.T) {
	db := database.DBConn
	domainA := "orm-insertid-reuse-a.example"
	domainB := "orm-insertid-reuse-b.example"
	defer db.Exec("DELETE FROM "+insertIDProbeTable+" WHERE domain IN (?, ?)", domainA, domainB)

	// domainB pre-exists with its own, known id, created through an
	// independent map so nothing links the two rows except what the test
	// does next.
	seedB := map[string]interface{}{"domain": domainB, "count": 1}
	if err := db.Table(insertIDProbeTable).Create(seedB).Error; err != nil {
		t.Fatalf("seed b: %v", err)
	}
	idB, _ := seedB["@id"].(int64)
	if idB <= 0 {
		t.Fatalf("seed b got no id")
	}

	// One map, used for a real insert of domainA.
	row := map[string]interface{}{"domain": domainA, "count": 1}
	if err := db.Table(insertIDProbeTable).Create(row).Error; err != nil {
		t.Fatalf("insert a: %v", err)
	}
	idA, _ := row["@id"].(int64)
	if idA <= 0 || idA == idB {
		t.Fatalf("insert a got a bad id: %d (b's id is %d)", idA, idB)
	}

	// The SAME map object is now repointed at domainB - the shape of a
	// caller that mutates and resubmits a map rather than allocating a fresh
	// one per Create call (unlike bulkItem.go's upsertBulkItems and
	// ingestBulkItemPhotos, which correctly declare `row := map[...]{}` fresh
	// inside their loops). domainB already exists, so the id-only
	// ON DUPLICATE KEY UPDATE idiom takes the no-op UPDATE branch:
	// RowsAffected == 0, so gorm.io/gorm/callbacks/create.go returns before
	// touching the map at all. "@id" is left holding idA from the PREVIOUS
	// Create call - a value for a row that has nothing to do with domainB.
	row["domain"] = domainB
	row["count"] = 1
	if err := idOnlyUpsert(row); err != nil {
		t.Fatalf("no-op upsert on reused map: %v", err)
	}

	gotID, ok := row["@id"].(int64)
	if !ok {
		t.Fatalf("@id vanished entirely after the no-op upsert on the reused map")
	}
	if gotID == idA && gotID != idB {
		t.Fatalf("@id after reusing the map for domainB is %d - domainA's stale id, not domainB's real id %d; "+
			"a caller reading row[\"@id\"] here gets no error and a wrong-but-plausible-looking id", gotID, idB)
	}
	if gotID != idB {
		t.Fatalf("@id after no-op upsert = %d, want domainB's real id %d", gotID, idB)
	}
}
