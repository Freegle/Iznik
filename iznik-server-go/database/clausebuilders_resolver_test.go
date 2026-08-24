package database

import (
	"os"
	"strings"
	"testing"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// TestCustomClauseBuildersSurviveReadReplica pins the production database
// construction order: the custom VALUES (InsertSelect) and INSERT (REPLACE
// INTO) clause builders must still be in force after InitDatabase has
// registered the dbresolver read replica.
//
// Regression test for the 2026-08-06 incident: RegisterCustomClauseBuilders
// ran before dbresolver.Register, and the mysql dialector's Initialize -
// which dbresolver invokes against the same *gorm.DB for each replica
// dialector - re-installed the driver's default "VALUES" builder over ours.
// Every production InsertSelect then rendered the literal placeholder column
// (`INSERT INTO x (__insert_select_placeholder__) VALUES (0)`) and failed
// with Error 1054. CI never caught it because MYSQL_HOST_READ is set only in
// production, so the resolver path never ran under test. This test runs
// InitDatabase with a replica configured (pointed back at the write host -
// what matters is that the resolver initialization path executes, not where
// reads land) and asserts both custom builders survive.
func TestCustomClauseBuildersSurviveReadReplica(t *testing.T) {
	host := os.Getenv("MYSQL_HOST")
	if host == "" {
		t.Skip("MYSQL_HOST not set; skipping resolver integration test")
	}
	t.Setenv("MYSQL_HOST_READ", host)

	InitDatabase()

	// InsertSelect must render the caller's column list and SELECT body, not
	// the placeholder column the mechanism feeds GORM internally.
	sess := DBConn.Session(&gorm.Session{DryRun: true})
	r := InsertSelect(sess, "chat_roster",
		"(chatid, userid) SELECT id, ? FROM chat_rooms WHERE id = ?",
		uint64(1), uint64(2))
	if r.Error != nil {
		t.Fatalf("InsertSelect dry run errored: %v", r.Error)
	}
	sql := r.Statement.SQL.String()
	if strings.Contains(sql, insertSelectPlaceholderColumn) {
		t.Fatalf("InsertSelect rendered the placeholder column - the custom VALUES builder was clobbered by resolver initialization: %q", sql)
	}
	if !strings.Contains(sql, "SELECT id") {
		t.Fatalf("InsertSelect did not render the SELECT body: %q", sql)
	}

	// REPLACE INTO must also survive. It happened to survive the original
	// incident only because the mysql dialector installs no "INSERT" builder;
	// pin it anyway so a future driver that does install one fails here
	// instead of in production.
	r2 := DBConn.Session(&gorm.Session{DryRun: true}).
		Table("spam_users").
		Clauses(clause.Insert{Modifier: "REPLACE"}).
		Create(map[string]interface{}{"userid": uint64(1)})
	if r2.Error != nil {
		t.Fatalf("REPLACE INTO dry run errored: %v", r2.Error)
	}
	if !strings.HasPrefix(r2.Statement.SQL.String(), "REPLACE INTO") {
		t.Fatalf("REPLACE modifier did not render REPLACE INTO: %q", r2.Statement.SQL.String())
	}
}
