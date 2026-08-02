package ormharness

// Review of the "Bare SELECT EXISTS, no top-level FROM" keep-raw category (9
// sites, e.g. chat/chatmessage.go:433's "SELECT EXISTS(SELECT 1 FROM
// messages_groups WHERE msgid = ? AND rippled_in = 1 AND deleted = 0)").
//
// The recorded reason is that GORM's chainable builder always renders a FROM
// clause once .Table() (or .Model()) is called, via
// callbacks/query.go:268's unconditional db.Statement.AddClauseIfNotExists
// (clause.From{}), and that .Table()/.Select()/.Where() is the only
// established pattern for reaching that builder path in this codebase.
//
// That is true for the established pattern. It is not true for what GORM's
// public API can render: callbacks.go's Execute (callbacks.go:87-90) only
// installs the processor's default clause list ([]string{"SELECT", "FROM",
// "WHERE", "GROUP BY", "ORDER BY", "LIMIT", "FOR"}, callbacks/callbacks.go:9)
// onto Statement.BuildClauses when that field is still empty:
//
//	if len(stmt.BuildClauses) == 0 {
//	    stmt.BuildClauses = p.Clauses
//	    resetBuildClauses = true
//	}
//
// Statement.BuildClauses is an exported field. If a caller sets it before
// the terminal call, BuildQuerySQL still unconditionally registers a FROM
// clause in the Clauses map (query.go:268), but Statement.Build only walks
// the names actually passed to it (statement.go:493-509) - so a FROM that is
// registered but not named in BuildClauses is simply never written.
// Restricting BuildClauses to {"SELECT"} renders the SELECT clause alone and
// silently drops the registered FROM, matching the "SELECT EXISTS(...)"
// golden exactly.
//
// .Table(...) still has to be called (or .Model()) - not because it
// contributes a FROM (it doesn't, once FROM is excluded from BuildClauses),
// but because without it Execute's schema-parse-failure branch adds a
// "Table not set" error when the destination isn't a registered model
// (Execute's stmt.Table=="" && stmt.TableExpr==nil branch).
//
// This is a real GORM mechanism, not a workaround bolted on top of it: it is
// the same Statement.BuildClauses field callbacks.go itself uses to decide
// what to render, exercised through its exported zero value rather than left
// at the processor default. RenderDryRunSQL (golden.go) needs no change:
// this only needs the build func, passed to it exactly as every other
// harness test does, to set tx.Statement.BuildClauses before the terminal
// call.

import (
	"testing"

	"gorm.io/gorm"
)

func TestBareExists_SimpleTableNoJoin(t *testing.T) {
	// chat/chatmessage.go:440 (a4250aa0ada3): SELECT EXISTS(SELECT 1 FROM
	// rippling_reach WHERE msgid = ?)
	want := "SELECT EXISTS(SELECT 1 FROM rippling_reach WHERE msgid = ?)"
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		tx = tx.Table("rippling_reach").
			Select("EXISTS(SELECT 1 FROM rippling_reach WHERE msgid = ?)", 42)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if sql != want {
		t.Fatalf("got %q, want %q", sql, want)
	}
}

func TestBareExists_JoinedSubquery(t *testing.T) {
	// chat/chatmessage.go:393 (848af7d73bfe): the join lives inside the
	// EXISTS text itself, not as a GORM .Joins() call, so this pins that the
	// FROM-suppression trick survives an inner join written as plain SQL
	// text inside the SELECT expression, not the builder's own join clause.
	want := "SELECT EXISTS(SELECT 1 FROM messages_groups mg INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ? AND mem.collection = ? AND mem.added < NOW() - INTERVAL 300 SECOND WHERE mg.msgid = ? AND mg.rippled_in = 0 AND mg.deleted = 0)"
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		tx = tx.Table("messages_groups").
			Select("EXISTS(SELECT 1 FROM messages_groups mg "+
				"INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ? "+
				"AND mem.collection = ? AND mem.added < NOW() - INTERVAL 300 SECOND "+
				"WHERE mg.msgid = ? AND mg.rippled_in = 0 AND mg.deleted = 0)",
				7, 1, 42)
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if sql != want {
		t.Fatalf("got %q, want %q", sql, want)
	}
}

func TestBareExists_InList(t *testing.T) {
	// message/message.go:989 (c9ff161437b9): SELECT EXISTS(SELECT 1 FROM
	// rippling_reach WHERE msgid IN (?)) - a single bind slice expands to a
	// comma-joined placeholder list at DryRun/Exec time the same way it does
	// on every already-converted IN-list site in this codebase. That
	// expansion is orthogonal to the FROM-suppression mechanism under test
	// here; it is exercised for completeness because it is this category's
	// one site with a slice bind.
	want := "SELECT EXISTS(SELECT 1 FROM rippling_reach WHERE msgid IN (?,?,?))"
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		tx = tx.Table("rippling_reach").
			Select("EXISTS(SELECT 1 FROM rippling_reach WHERE msgid IN (?))", []int{1, 2, 3})
		tx.Statement.BuildClauses = []string{"SELECT"}
		return tx.Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if sql != want {
		t.Fatalf("got %q, want %q", sql, want)
	}
}

func TestBareExists_WithoutBuildClausesOverrideStillEmitsFrom(t *testing.T) {
	// Control: without the BuildClauses override, .Table()+.Select() renders
	// the FROM the keep-raw reason describes - confirming the override,
	// not some other accident, is what suppresses it above.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("rippling_reach").
			Select("EXISTS(SELECT 1 FROM rippling_reach WHERE msgid = ?)", 42).
			Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	want := "SELECT EXISTS(SELECT 1 FROM rippling_reach WHERE msgid = ?) FROM `rippling_reach`"
	if sql != want {
		t.Fatalf("expected the unmodified builder to still append its own FROM (got %q) - "+
			"if this changed, GORM's default behaviour changed and the reason for keeping "+
			"these 9 sites raw needs re-checking, not just this test", sql)
	}
}
