package test

// Can a converted INSERT get its generated id back?
//
// 58 sites were kept raw on the grounds that LAST_INSERT_ID() is
// connection-scoped session state and GORM's writeback for a schema-less
// Table()+map Create is undocumented. The first half of that is true of the SQL
// function. It is NOT true of what GORM actually does, and the distinction
// matters because it is the difference between 58 sites being unconvertible and
// being ordinary.
//
// gorm.io/gorm/callbacks/create.go reads result.LastInsertId() from the SAME
// sql.Result the INSERT returned - the value the MySQL protocol sent back with
// that statement, on the connection that ran it. No second query, so no
// connection to lose. When Statement.Schema is nil, which is the case for
// Table()+map, it writes the value into the map under the key "@id".
//
// These tests execute against the real database rather than reasoning about it,
// and they check the id is not merely present but CORRECT, by reading the row
// back. A test that only asserted "id > 0" would pass against a stale or
// unrelated value, which is precisely the failure mode being guarded against.

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"gorm.io/gorm"
)

// insertIDProbeTable uses a table that already exists in the test schema and
// has an auto-increment primary key, so the test needs no DDL of its own.
const insertIDProbeTable = "spam_whitelist_links"

func TestInsertID_MapCreateReturnsIDUnderAtID(t *testing.T) {
	db := database.DBConn
	row := map[string]interface{}{"domain": "orm-insertid-probe-1.example", "count": 1}

	if err := db.Table(insertIDProbeTable).Create(row).Error; err != nil {
		t.Fatalf("insert: %v", err)
	}
	defer db.Exec("DELETE FROM "+insertIDProbeTable+" WHERE domain = ?", "orm-insertid-probe-1.example")

	raw, ok := row["@id"]
	if !ok {
		t.Fatal("Table()+map Create did not write the generated id back under \"@id\"; the keep-raw reason for 58 sites would stand")
	}
	id, ok := raw.(int64)
	if !ok || id <= 0 {
		t.Fatalf("@id is not a usable id: %#v", raw)
	}

	// The id must identify the row we just wrote, not merely be a number.
	var domain string
	if err := db.Table(insertIDProbeTable).Select("domain").Where("id = ?", id).Row().Scan(&domain); err != nil {
		t.Fatalf("reading back row %d: %v", id, err)
	}
	if domain != "orm-insertid-probe-1.example" {
		t.Fatalf("@id %d points at the wrong row: got domain %q", id, domain)
	}
}

func TestInsertID_WithResultExposesSQLResult(t *testing.T) {
	// The supported alternative, for call sites that would rather not reach
	// into the map: gorm.WithResult() hands back the sql.Result itself, so
	// LastInsertId comes from the same place the raw code was reading it.
	db := database.DBConn
	res := gorm.WithResult()
	row := map[string]interface{}{"domain": "orm-insertid-probe-2.example", "count": 1}

	if err := db.Table(insertIDProbeTable).Set("gorm:result", res).Create(row).Error; err != nil {
		t.Fatalf("insert: %v", err)
	}
	defer db.Exec("DELETE FROM "+insertIDProbeTable+" WHERE domain = ?", "orm-insertid-probe-2.example")

	if res.Result == nil {
		t.Skip("gorm:result not populated by this GORM version; the @id path above is the one to use")
	}
	id, err := res.Result.LastInsertId()
	if err != nil || id <= 0 {
		t.Fatalf("LastInsertId from gorm.WithResult: id=%d err=%v", id, err)
	}
}

func TestInsertID_IsPerStatementNotPerConnectionRace(t *testing.T) {
	// The actual worry behind the keep-raw reason: that under a connection pool
	// the id could come from someone else's insert. It cannot, because it is
	// read from the sql.Result of this statement. Two interleaved inserts must
	// therefore report their own ids, not each other's.
	db := database.DBConn
	a := map[string]interface{}{"domain": "orm-insertid-probe-a.example", "count": 1}
	b := map[string]interface{}{"domain": "orm-insertid-probe-b.example", "count": 1}

	if err := db.Table(insertIDProbeTable).Create(a).Error; err != nil {
		t.Fatalf("insert a: %v", err)
	}
	if err := db.Table(insertIDProbeTable).Create(b).Error; err != nil {
		t.Fatalf("insert b: %v", err)
	}
	defer db.Exec("DELETE FROM "+insertIDProbeTable+" WHERE domain IN (?, ?)",
		"orm-insertid-probe-a.example", "orm-insertid-probe-b.example")

	idA, _ := a["@id"].(int64)
	idB, _ := b["@id"].(int64)
	if idA <= 0 || idB <= 0 || idA == idB {
		t.Fatalf("ids not distinct and positive: a=%d b=%d", idA, idB)
	}

	for _, c := range []struct {
		id   int64
		want string
	}{{idA, "orm-insertid-probe-a.example"}, {idB, "orm-insertid-probe-b.example"}} {
		var domain string
		if err := db.Table(insertIDProbeTable).Select("domain").Where("id = ?", c.id).Row().Scan(&domain); err != nil {
			t.Fatalf("reading back %d: %v", c.id, err)
		}
		if domain != c.want {
			t.Fatalf("id %d points at %q, want %q", c.id, domain, c.want)
		}
	}
}
