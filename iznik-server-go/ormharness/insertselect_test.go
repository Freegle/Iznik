package ormharness

// Tier 4 of the keep-raw adversarial review
// (plans/active/orm-keepraw-adversarial-review.md, §4): proves two
// ClauseBuilders overrides (database/clausebuilders.go) are actually wired
// into the shared dry-run connection every AssertGoldenSQL call uses
// (golden.go's dryRunDB), not just correct in isolation.
//
// REPLACE INTO: updatejoin_replace_test.go already proved
// db.ClauseBuilders["INSERT"] against its own ad hoc *gorm.DB. What that file
// could not prove is that the override travels with the connection every
// Tier 4 conversion's Layer 1 test actually renders against - it built and
// registered its own database from scratch. TestReplaceInto_ViaSharedDryRunDB
// below renders through the ordinary RenderDryRunSQL/AssertGoldenSQL entry
// point, with nothing extra, which is what a converted call site's own test
// needs to be true.
//
// INSERT ... SELECT: a prior version of this proof was written and
// discarded (per the review, not restored here - written fresh). It called
// Create([]map[string]interface{}{}), an EMPTY slice, as its non-Select
// Dest. That trips GORM's own guard before Build ever runs -
// callbacks/create.go's ConvertToCreateValues: `if rValLen == 0 {
// stmt.AddError(gorm.ErrEmptySlice); return }` - so the "proof" compared
// nothing against nothing and always passed regardless of whether the
// override worked. database.InsertSelect avoids this by passing a single
// non-empty map (not a slice) as Dest, which never reaches that guard - see
// its own doc comment in database/clausebuilders.go.
//
// Both mechanisms are demonstrated with their negative case per the review's
// standard for "it works": a render that would fail against the
// unconverted/unregistered form.

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- REPLACE INTO, via the shared connection --------------------------------

func TestReplaceInto_ViaSharedDryRunDB(t *testing.T) {
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_promises").
			Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"msgid": 1, "userid": 2})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	upper := strings.ToUpper(strings.TrimSpace(sql))
	if !strings.HasPrefix(upper, "REPLACE INTO") {
		t.Fatalf("expected REPLACE INTO via the shared dry-run db (database.RegisterCustomClauseBuilders "+
			"must be wired into golden.go's dryRunDB), got: %s", sql)
	}
	if strings.Contains(upper, "INSERT") {
		t.Fatalf("expected no INSERT keyword to survive alongside REPLACE INTO, got: %s", sql)
	}
	if !strings.Contains(upper, "MESSAGES_PROMISES") {
		t.Fatalf("expected the table name to survive, got: %s", sql)
	}
}

// --- INSERT ... SELECT -------------------------------------------------------

func TestInsertSelect_RendersSelectNotValues(t *testing.T) {
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return database.InsertSelect(tx, "messages_attachments",
			"(msgid, contenttype, archived, hash, externaluid, externalmods, identification, `primary`) "+
				"SELECT ?, ni.contenttype, ni.archived, ni.hash, ni.externaluid, ni.externalmods, ni.identification, 1 "+
				"FROM newsfeed n INNER JOIN newsfeed_images ni ON ni.id = n.imageid "+
				"WHERE n.id = ? AND ni.externaluid IS NOT NULL "+
				"AND NOT EXISTS (SELECT 1 FROM messages_attachments ma WHERE ma.msgid = ?)",
			42, 7, 42)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	upper := strings.ToUpper(sql)
	if !strings.HasPrefix(upper, "INSERT INTO") {
		t.Fatalf("expected INSERT INTO, got: %s", sql)
	}
	if !strings.Contains(upper, "MESSAGES_ATTACHMENTS") {
		t.Fatalf("expected the target table name to survive, got: %s", sql)
	}
	if !strings.Contains(upper, "SELECT ?, NI.CONTENTTYPE") {
		t.Fatalf("expected the SELECT body to render verbatim, got: %s", sql)
	}
	if !strings.Contains(upper, "INNER JOIN NEWSFEED_IMAGES") {
		t.Fatalf("expected the JOIN inside the SELECT to survive, got: %s", sql)
	}
	if strings.Contains(upper, "VALUES") {
		t.Fatalf("expected no VALUES clause at all - InsertSelect must fully replace it, not append "+
			"alongside it, got: %s", sql)
	}
	if strings.Contains(sql, "__insert_select_placeholder__") {
		t.Fatalf("the placeholder column InsertSelect passes to satisfy GORM's non-empty-Dest "+
			"requirement leaked into the rendered SQL instead of being fully replaced, got: %s", sql)
	}
	// Three placeholders in the SELECT body, none contributed by the
	// placeholder map (which the override must discard entirely).
	if n := strings.Count(sql, "?"); n != 3 {
		t.Fatalf("expected exactly 3 placeholders (the SELECT body's own), got %d: %s", n, sql)
	}
}

// TestInsertSelect_WithoutOverrideRendersPlaceholderNotSelect is the negative
// case: the identical InsertSelect call, on a *gorm.DB that never had
// RegisterCustomClauseBuilders applied, proves the override - not some GORM
// default - is what makes the SELECT text render. Without it, Create()'s
// ordinary VALUES clause wins, rendering the placeholder column and its
// throwaway value instead of the SELECT - a real, different, and wrong
// statement (it would insert one garbage row rather than copying anything),
// which is exactly the failure mode a converted call site must never hit if
// it somehow ran against an unconfigured connection.
func TestInsertSelect_WithoutOverrideRendersPlaceholderNotSelect(t *testing.T) {
	db, err := gorm.Open(mysql.New(mysql.Config{
		DriverName:                "mysql",
		DSN:                       dryRunDSN,
		SkipInitializeWithVersion: true,
	}), &gorm.Config{DisableAutomaticPing: true})
	if err != nil {
		t.Fatalf("open: %v", err)
	}

	session := db.Session(&gorm.Session{DryRun: true, SkipDefaultTransaction: true})
	tx := database.InsertSelect(session, "messages_attachments",
		"(msgid, contenttype) SELECT ?, ni.contenttype FROM newsfeed n WHERE n.id = ?", 42, 7)
	if tx.Error != nil {
		t.Fatalf("render: %v", tx.Error)
	}

	sql := tx.Statement.SQL.String()
	upper := strings.ToUpper(sql)
	if strings.Contains(upper, "FROM NEWSFEED") {
		t.Fatalf("expected the unregistered default to LOSE the SELECT text entirely, got: %s", sql)
	}
	if !strings.Contains(upper, "VALUES") {
		t.Fatalf("expected GORM's ordinary VALUES clause to win without the override registered, got: %s", sql)
	}
	if !strings.Contains(sql, "__insert_select_placeholder__") {
		t.Fatalf("expected the placeholder column to leak into the SQL as ordinary GORM behaviour "+
			"without the override - that leak is the point of this control, got: %s", sql)
	}
}
