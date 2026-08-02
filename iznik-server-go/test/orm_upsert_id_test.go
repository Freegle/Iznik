package test

// The ON DUPLICATE KEY UPDATE ... id = LAST_INSERT_ID(id) idiom, and whether a
// GORM conversion of it still returns the right id.
//
// All ten sites still kept raw for LAST_INSERT_ID use this one shape:
//
//	INSERT INTO chat_rooms (...) VALUES (...)
//	ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), latestmessage = NOW()
//
// I described that as a "cross-statement idiom depending on session state". It
// is not. LAST_INSERT_ID(expr) is the one-argument form: it sets the session
// value AND makes the server report expr in the OK packet FOR THAT STATEMENT.
// So sql.Result.LastInsertId() reads it from the same packet as any insert, and
// the whole point of the idiom is to get the EXISTING row's id back on conflict
// rather than nothing.
//
// There is a genuine trap, though, and it is not the one I claimed. GORM's
// create callback returns early when RowsAffected is 0 (callbacks/create.go),
// skipping the id writeback entirely. MySQL reports rowsAffected 1 for an
// insert, 2 for an ODKU that updated a row, and 0 when the row matched but no
// column actually changed. So the third case - a repeat upsert that changes
// nothing - is where a conversion could silently stop returning an id while the
// raw code still did.
//
// These tests establish which of those three cases work, so the conversion is
// written against measured behaviour instead of my third guess in a row.

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// upsertProbe uses spam_whitelist_links, whose domain column is UNIQUE, so a
// second insert of the same domain collides exactly as chat_rooms does on its
// user1/user2/chattype key.
func upsertProbe(t *testing.T, domain string, count interface{}) (int64, int64) {
	t.Helper()
	db := database.DBConn
	row := map[string]interface{}{"domain": domain, "count": count}
	tx := db.Table("spam_whitelist_links").Clauses(clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			{Column: clause.Column{Name: "count"}, Value: gorm.Expr("VALUES(count)")},
		},
	}).Create(row)
	if tx.Error != nil {
		t.Fatalf("upsert %s: %v", domain, tx.Error)
	}
	id, _ := row["@id"].(int64)
	return id, tx.RowsAffected
}

func TestUpsertID_InsertThenConflictReturnsSameID(t *testing.T) {
	db := database.DBConn
	domain := "orm-upsert-probe.example"
	defer db.Exec("DELETE FROM spam_whitelist_links WHERE domain = ?", domain)

	// First call inserts.
	first, affected1 := upsertProbe(t, domain, 1)
	if first <= 0 {
		t.Fatalf("insert returned no id (rowsAffected=%d)", affected1)
	}

	// Second call collides and CHANGES a column, so MySQL reports 2 rows
	// affected. The idiom must hand back the same id as the insert.
	second, affected2 := upsertProbe(t, domain, 2)
	if second != first {
		t.Fatalf("conflict returned id %d, want the existing row's id %d (rowsAffected=%d)", second, first, affected2)
	}

	// And it must be the row we think it is.
	var got int
	if err := db.Table("spam_whitelist_links").Select("count").Where("id = ?", second).Row().Scan(&got); err != nil {
		t.Fatalf("reading back %d: %v", second, err)
	}
	if got != 2 {
		t.Fatalf("row %d has count %d, want 2 - the upsert updated a different row", second, got)
	}
}

func TestUpsertID_NoChangeConflictIsTheTrap(t *testing.T) {
	// The case worth knowing about before converting the ten sites: a repeat
	// upsert where nothing changes. MySQL reports rowsAffected 0, and GORM
	// returns before writing "@id".
	db := database.DBConn
	domain := "orm-upsert-nochange.example"
	defer db.Exec("DELETE FROM spam_whitelist_links WHERE domain = ?", domain)

	first, _ := upsertProbe(t, domain, 7)
	if first <= 0 {
		t.Fatal("insert returned no id")
	}

	// Same values again: the row matches and nothing changes.
	same, affected := upsertProbe(t, domain, 7)

	if affected == 0 && same == 0 {
		t.Logf("CONFIRMED TRAP: a no-change upsert reports rowsAffected=0, and GORM skips the id writeback, "+
			"so \"@id\" is absent where the raw code's sql.Result.LastInsertId() still returned %d. "+
			"Conversions of the ten LAST_INSERT_ID(id) sites must handle a missing id by looking the row "+
			"up, or keep the raw statement.", first)
		return
	}
	if same != first {
		t.Fatalf("no-change upsert returned id %d, want %d (rowsAffected=%d)", same, first, affected)
	}
	t.Logf("no-change upsert returned rowsAffected=%d and id %d, so the trap does not apply here", affected, same)
}

func TestUpsertID_RenderedSQLKeepsTheIdiom(t *testing.T) {
	// The conversion must still emit LAST_INSERT_ID(id), or the id comes back
	// as 0 on conflict and the caller silently creates duplicates downstream.
	sql, err := ormharness.RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("spam_whitelist_links").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "count"}, Value: gorm.Expr("VALUES(count)")},
			},
		}).Create(map[string]interface{}{"domain": "x", "count": 1})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if !strings.Contains(sql, "LAST_INSERT_ID(id)") {
		t.Fatalf("rendered upsert lost the LAST_INSERT_ID(id) assignment: %s", sql)
	}
}
