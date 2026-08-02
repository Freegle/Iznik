package ormharness

// Wave 3 pre-flight: what GORM actually emits for upserts on MySQL.
//
// Wave 3 is 43 ON DUPLICATE KEY UPDATE sites and 35 INSERT IGNORE sites, and
// the obvious-looking translation of the second group is wrong in a way that
// only shows up in the rendered SQL. These tests pin the behaviour before the
// conversions are written, so the rule is a fact rather than a recollection.
//
// The trap: gorm.io/driver/mysql's ON CONFLICT clause builder writes
// "ON DUPLICATE KEY UPDATE " unconditionally, and only then looks for something
// to put after it. When DoUpdates is empty it tries to synthesise "id=id", but
// that fallback is guarded by `if s := builder.(*gorm.Statement).Schema; s != nil`.
//
// Our conversion convention is .Table(...) rather than .Model(...), deliberately,
// so that Schema stays nil and GORM cannot inject an updated_at column or turn
// a Delete into a soft delete. That convention means the fallback never runs, so
// clause.OnConflict{DoNothing: true} produces a dangling "ON DUPLICATE KEY
// UPDATE" with nothing after it - not "INSERT IGNORE", and not valid SQL.
//
// So INSERT IGNORE converts with clause.Insert{Modifier: "IGNORE"}, never with
// DoNothing.

import (
	"strings"
	"testing"

	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

func TestUpsert_InsertIgnoreUsesInsertModifier(t *testing.T) {
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").
			Clauses(clause.Insert{Modifier: "IGNORE"}).
			Create(map[string]interface{}{"chatid": 1, "userid": 2})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if !strings.HasPrefix(strings.ToUpper(strings.TrimSpace(sql)), "INSERT IGNORE INTO") {
		t.Fatalf("expected INSERT IGNORE INTO, got: %s", sql)
	}
	if strings.Contains(strings.ToUpper(sql), "ON DUPLICATE KEY") {
		t.Fatalf("INSERT IGNORE must not carry an ON DUPLICATE KEY clause, got: %s", sql)
	}
}

func TestUpsert_DoNothingIsUnusableWithTable(t *testing.T) {
	// This is the trap, pinned so that nobody "simplifies" an INSERT IGNORE
	// conversion into DoNothing and gets SQL that cannot execute. If a future
	// GORM or driver version makes DoNothing behave sensibly without a Schema,
	// this test fails and the rule above can be revisited deliberately rather
	// than by accident.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").
			Clauses(clause.OnConflict{DoNothing: true}).
			Create(map[string]interface{}{"chatid": 1, "userid": 2})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	upper := strings.ToUpper(sql)
	if strings.Contains(upper, "INSERT IGNORE") {
		t.Fatalf("driver now maps DoNothing to INSERT IGNORE; revisit the wave 3 rule. got: %s", sql)
	}
	if !strings.Contains(upper, "ON DUPLICATE KEY UPDATE") {
		t.Fatalf("expected the driver to emit ON DUPLICATE KEY UPDATE for DoNothing, got: %s", sql)
	}
	// The point of the test: with Schema nil there is nothing after the clause.
	tail := strings.TrimSpace(upper[strings.Index(upper, "ON DUPLICATE KEY UPDATE")+len("ON DUPLICATE KEY UPDATE"):])
	if tail != "" {
		t.Fatalf("DoNothing now emits an assignment (%q) rather than a dangling clause; the wave 3 rule can be revisited. full: %s", tail, sql)
	}
}

func TestUpsert_OnDuplicateKeyUpdateAssignments(t *testing.T) {
	// The shape wave 3's 43 ON DUPLICATE KEY UPDATE sites convert to. Note
	// clause.Assignments takes a map, and GORM sorts map keys - the same
	// ordering question as Updates(map), so check-set-order.sh's rule applies
	// here too.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_images").
			Clauses(clause.OnConflict{
				DoUpdates: clause.Assignments(map[string]interface{}{"archived": 0}),
			}).
			Create(map[string]interface{}{"userid": 1, "archived": 0})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if !strings.Contains(strings.ToUpper(sql), "ON DUPLICATE KEY UPDATE") {
		t.Fatalf("expected ON DUPLICATE KEY UPDATE, got: %s", sql)
	}
	if !strings.Contains(sql, "archived") {
		t.Fatalf("expected the assignment to name its column, got: %s", sql)
	}
}

func TestUpsert_ValuesReferenceUsesExcludedTable(t *testing.T) {
	// "col = VALUES(col)" is the commonest form in the codebase. GORM spells it
	// as an assignment whose value is a column in a pseudo-table called
	// "excluded", which the MySQL driver rewrites to VALUES(col). Without the
	// excluded table it binds the value instead, which is a different statement.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users_images").
			Clauses(clause.OnConflict{
				DoUpdates: clause.Set{{
					Column: clause.Column{Name: "archived"},
					Value:  clause.Column{Table: "excluded", Name: "archived"},
				}},
			}).
			Create(map[string]interface{}{"userid": 1, "archived": 0})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if !strings.Contains(strings.ToUpper(sql), "VALUES(") {
		t.Fatalf("expected VALUES(col) for an excluded-table reference, got: %s", sql)
	}
}

// TestUpsert_InsertModifierKeywordSwap records what clause.Insert{Modifier}
// renders THROUGH THIS PACKAGE'S SHARED dryRunDB, which is not the same
// question as what GORM does by default.
//
// I wrote an earlier version of this test to settle a disagreement: a source
// trace said Modifier:"REPLACE" must render the invalid "INSERT REPLACE INTO"
// (AddClause sets c.Name="INSERT" at statement.go:278, Clause.Build writes the
// name at clause.go:46, and Insert.Build adds only Modifier+"INTO"), while a
// rendering came back with a clean "REPLACE INTO". I concluded the trace was
// wrong, said so, and told the agent building a ClauseBuilders override to stop.
//
// I was wrong. RenderDryRunSQL goes through dryRunDB(), and dryRunDB() installs
// database.RegisterCustomClauseBuilders (golden.go). The override was already
// there, so the render measured the FIXED behaviour and I read it as the
// default. TestReplaceInto_DefaultBehaviourWithoutAnyOverride, which builds a
// *gorm.DB from a bare gorm.Open with no wiring, confirms the trace: unmodified
// GORM renders "INSERT REPLACE INTO", and the override is load-bearing for all
// 11 REPLACE INTO sites.
//
// The lesson is narrower than "render, do not read". Rendering only answers the
// question you think it does if you control what you are rendering against, and
// a package-level singleton that other code configures is exactly where that
// control goes missing.
func TestUpsert_InsertModifierKeywordSwap(t *testing.T) {
	render := func(modifier string) string {
		sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
			return tx.Table("spam_whitelist_links").
				Clauses(clause.Insert{Modifier: modifier}).
				Create(map[string]interface{}{"domain": "x", "count": 1})
		})
		if err != nil {
			t.Fatalf("render %q: %v", modifier, err)
		}
		return strings.ToUpper(strings.TrimSpace(sql))
	}

	// IGNORE is load-bearing: 35 converted sites depend on it rendering
	// "INSERT IGNORE INTO", and it does so with or without the override.
	if ignore := render("IGNORE"); !strings.HasPrefix(ignore, "INSERT IGNORE INTO") {
		t.Fatalf("Modifier \"IGNORE\" no longer renders INSERT IGNORE INTO, which 35 converted sites rely on: %s", ignore)
	}

	// REPLACE renders correctly HERE only because dryRunDB carries the
	// override. If this ever fails, the override has been removed or broken and
	// the 11 REPLACE INTO sites are emitting invalid SQL - which is the whole
	// reason the wiring exists.
	if replace := render("REPLACE"); !strings.HasPrefix(replace, "REPLACE INTO") {
		t.Fatalf("REPLACE no longer renders through the shared dryRunDB, so database.RegisterCustomClauseBuilders "+
			"is missing or broken and the 11 REPLACE INTO sites emit invalid SQL: %s", replace)
	}
}
