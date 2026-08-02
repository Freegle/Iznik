package ormharness

// Review pre-flight for two keep-raw categories: "Multi-table UPDATE/DELETE
// ... JOIN" (15 sites) and "REPLACE INTO" (11 sites).
//
// Multi-table UPDATE/DELETE ... JOIN: mysql.go's UpdateClauses/DeleteClauses
// are ["UPDATE","SET","WHERE","ORDER BY","LIMIT"] / ["DELETE","FROM","WHERE",
// "ORDER BY","LIMIT"] - no FROM entry on the update side, no JOIN entry on
// either, and callbacks/update.go and callbacks/delete.go never read
// db.Statement.Joins (only callbacks/query.go's BuildQuerySQL does, at
// query.go:109-118). So db.Joins(...) is silently dropped before an
// Update/Delete terminal call - confirming the keep-raw claim for that
// mechanism specifically.
//
// But chainable_api.go's Table(name, args...) takes an entirely different
// path when name contains a space or backtick (chainable_api.go:66-67): it
// sets Statement.TableExpr to a raw clause.Expr and leaves the JOIN gap
// irrelevant, because the join never has to travel through Statement.Joins at
// all - it is already inside the verbatim table text. clause/update.go's
// Update.Build writes builder.WriteQuoted(currentTable) when no explicit
// Table is set on the clause, and statement.go's QuoteTo (case clause.Table,
// v.Name == CurrentTable) renders stmt.TableExpr verbatim when it is present
// (statement.go:93-99). So "UPDATE <verbatim join text> SET ... WHERE ..."
// is reachable without ever touching Statement.Joins.
//
// REPLACE INTO: clause/insert.go's Insert.Build only ever writes Modifier+
// "INTO"; the literal "INSERT" comes from clause.Clause.Build (clause.go:44-
// 46), which writes c.Name - and AddClause (statement.go:276-280) always sets
// c.Name = v.Name(), which for clause.Insert is the hardcoded string
// "INSERT" (insert.go:10). Table()/Clauses()/gorm.Expr all operate on the
// Expression half of the clause, never the wrapping c.Name, so none of them
// can suppress it. The one place that DOES decide c.Name's fate is
// stmt.DB.ClauseBuilders[name] (statement.go:503), a map gorm.Open populates
// on *gorm.DB.Config and Session() copies by reference (gorm.go:252,254:
// txConfig := *db.Config keeps the same underlying map) - so registering a
// custom builder for "INSERT" once, at db-setup time, is a real escape hatch
// that no single call-site chain can replicate.

import (
	"strings"
	"testing"

	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// --- Multi-table UPDATE ... JOIN, genuine cases -----------------------------

func TestUpdateJoin_SelfJoinSimpleAssignment(t *testing.T) {
	// session/merge.go:114, mergeChatRooms (bf1cd8bf4627):
	//   UPDATE chat_rooms surv JOIN chat_rooms lose ON lose.id = ?
	//   SET surv.latestmessage = lose.latestmessage
	//   WHERE surv.id = ? AND lose.latestmessage IS NOT NULL
	//     AND (surv.latestmessage IS NULL OR surv.latestmessage < lose.latestmessage)
	loserID, survivorID := 111, 222
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_rooms surv JOIN chat_rooms lose ON lose.id = ?", loserID).
			Clauses(clause.Set{
				{Column: clause.Column{Table: "surv", Name: "latestmessage"},
					Value: clause.Column{Table: "lose", Name: "latestmessage"}},
			}).
			Where("surv.id = ? AND lose.latestmessage IS NOT NULL AND (surv.latestmessage IS NULL OR surv.latestmessage < lose.latestmessage)", survivorID).
			Updates(map[string]interface{}{})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	upper := strings.ToUpper(sql)
	if !strings.HasPrefix(upper, "UPDATE CHAT_ROOMS SURV JOIN CHAT_ROOMS LOSE ON LOSE.ID = ?") {
		t.Fatalf("join did not render verbatim ahead of SET: %s", sql)
	}
	set := strings.Index(upper, "SET")
	where := strings.Index(upper, "WHERE")
	if set < 0 || where < 0 || set > where {
		t.Fatalf("expected SET before WHERE: %s", sql)
	}
	if !strings.Contains(sql, "surv.latestmessage=lose.latestmessage") &&
		!strings.Contains(sql, "surv`.`latestmessage`=`lose`.`latestmessage") {
		t.Fatalf("expected a column-to-column assignment, got: %s", sql)
	}
	// One bind in the JOIN's ON, one in the WHERE - both survive positionally.
	if strings.Count(sql, "?") != 2 {
		t.Fatalf("expected two placeholders (join ON + where), got %d: %s", strings.Count(sql, "?"), sql)
	}
}

func TestUpdateJoin_TwoJoinsWithColumnValues(t *testing.T) {
	// message/message.go:4703 area, PutMessageAs (8c15ce918aa2):
	//   UPDATE messages m JOIN users u ON u.id = ? JOIN locations l ON l.id = COALESCE(m.locationid, u.lastlocation)
	//   SET m.locationid = l.id, m.lat = l.lat, m.lng = l.lng
	//   WHERE m.id = ? AND (m.lat IS NULL OR m.lng IS NULL)
	myID, msgID := 5, 900
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages m JOIN users u ON u.id = ? JOIN locations l ON l.id = COALESCE(m.locationid, u.lastlocation)", myID).
			Clauses(clause.Set{
				{Column: clause.Column{Table: "m", Name: "locationid"}, Value: clause.Column{Table: "l", Name: "id"}},
				{Column: clause.Column{Table: "m", Name: "lat"}, Value: clause.Column{Table: "l", Name: "lat"}},
				{Column: clause.Column{Table: "m", Name: "lng"}, Value: clause.Column{Table: "l", Name: "lng"}},
			}).
			Where("m.id = ? AND (m.lat IS NULL OR m.lng IS NULL)", msgID).
			Updates(map[string]interface{}{})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	upper := strings.ToUpper(sql)
	firstJoin := strings.Index(upper, "JOIN USERS")
	secondJoin := strings.Index(upper, "JOIN LOCATIONS")
	set := strings.Index(upper, "SET")
	where := strings.Index(upper, "WHERE")
	if firstJoin < 0 || secondJoin < 0 || firstJoin > secondJoin {
		t.Fatalf("expected both joins in call order: %s", sql)
	}
	if secondJoin > set || set > where {
		t.Fatalf("expected JOIN..JOIN..SET..WHERE order, got: %s", sql)
	}
	if strings.Count(sql, "?") != 2 {
		t.Fatalf("expected two placeholders (join ON + where), got %d: %s", strings.Count(sql, "?"), sql)
	}
}

func TestUpdateJoin_SelfJoinWithLeastExpr(t *testing.T) {
	// user/user.go:3075 and :2910, handleMerge's users/memberships LEAST()
	// twins (5b72a0acad8e, a79f147726d0):
	//   UPDATE users u2 JOIN users u1 ON u1.id = ?
	//   SET u2.added = LEAST(u2.added, u1.added)
	//   WHERE u2.id = ?
	id1, id2 := 10, 20
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users u2 JOIN users u1 ON u1.id = ?", id1).
			Clauses(clause.Set{
				{Column: clause.Column{Table: "u2", Name: "added"}, Value: gorm.Expr("LEAST(u2.added, u1.added)")},
			}).
			Where("u2.id = ?", id2).
			Updates(map[string]interface{}{})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if !strings.Contains(sql, "LEAST(u2.added, u1.added)") {
		t.Fatalf("expected the LEAST() expression to render literally, got: %s", sql)
	}
	if strings.Count(sql, "?") != 2 {
		t.Fatalf("expected two placeholders (join ON + where), got %d: %s", strings.Count(sql, "?"), sql)
	}
}

// --- REPLACE INTO -----------------------------------------------------------

func TestReplaceInto_ModifierProducesWrongKeyword(t *testing.T) {
	// Pins the keep-raw claim: clause.Insert{Modifier: "REPLACE"} does not
	// produce "REPLACE INTO", it produces "INSERT REPLACE INTO", because the
	// leading "INSERT" is written by clause.Clause.Build from c.Name, which
	// AddClause hardcodes from Insert.Name() regardless of Modifier.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_spamham").
			Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"msgid": 1, "spamham": "Ham"})
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	upper := strings.ToUpper(strings.TrimSpace(sql))
	if strings.HasPrefix(upper, "REPLACE INTO") {
		t.Fatalf("driver now honours Modifier as a keyword swap; the keep-raw REPLACE INTO reason can be revisited. got: %s", sql)
	}
	if !strings.HasPrefix(upper, "INSERT REPLACE INTO") {
		t.Fatalf("expected the documented bug (\"INSERT REPLACE INTO\"), got: %s", sql)
	}
}

func TestReplaceInto_ClauseBuilderOverrideRendersCorrectly(t *testing.T) {
	// The escape hatch: stmt.DB.ClauseBuilders["INSERT"] fully replaces how the
	// INSERT clause renders (statement.go:503, Build: `if b, ok :=
	// stmt.DB.ClauseBuilders[name]; ok { b(c, stmt) } else { c.Build(stmt) }`).
	// This is set on *gorm.DB.Config once, at db-construction time, not per
	// call site - Session() shares the same underlying map (gorm.go:252). If
	// the harness's shared db handle installs this once, every REPLACE INTO
	// site becomes an ordinary .Table(...).Clauses(clause.Insert{Modifier:
	// "REPLACE"}).Create(...) call rendering the original SQL verbatim.
	db, err := gorm.Open(mysql.New(mysql.Config{
		DriverName:                "mysql",
		DSN:                       dryRunDSN,
		SkipInitializeWithVersion: true,
	}), &gorm.Config{DisableAutomaticPing: true})
	if err != nil {
		t.Fatalf("open: %v", err)
	}
	db.ClauseBuilders["INSERT"] = func(c clause.Clause, builder clause.Builder) {
		insert, ok := c.Expression.(clause.Insert)
		if !ok || insert.Modifier != "REPLACE" {
			c.Build(builder) // fall through to default INSERT / INSERT IGNORE / etc.
			return
		}
		builder.WriteString("REPLACE INTO ")
		if insert.Table.Name == "" {
			builder.WriteQuoted(clause.Table{Name: clause.CurrentTable})
		} else {
			builder.WriteQuoted(insert.Table)
		}
	}

	session := db.Session(&gorm.Session{DryRun: true, SkipDefaultTransaction: true})
	tx := session.Table("messages_spamham").
		Clauses(clause.Insert{Modifier: "REPLACE"}).
		Create(map[string]interface{}{"msgid": 1, "spamham": "Ham"})
	if tx.Error != nil {
		t.Fatalf("render: %v", tx.Error)
	}
	sql := tx.Statement.SQL.String()
	upper := strings.ToUpper(strings.TrimSpace(sql))
	if !strings.HasPrefix(upper, "REPLACE INTO MESSAGES_SPAMHAM") {
		t.Fatalf("expected REPLACE INTO messages_spamham, got: %s", sql)
	}
	if strings.Contains(upper, "INSERT") {
		t.Fatalf("the override must fully suppress the INSERT keyword, got: %s", sql)
	}

	// And ordinary INSERT IGNORE, which the wave 3 sites depend on, must be
	// completely unaffected by the override's fallthrough.
	session2 := db.Session(&gorm.Session{DryRun: true, SkipDefaultTransaction: true})
	tx2 := session2.Table("chat_roster").
		Clauses(clause.Insert{Modifier: "IGNORE"}).
		Create(map[string]interface{}{"chatid": 1, "userid": 2})
	if tx2.Error != nil {
		t.Fatalf("render: %v", tx2.Error)
	}
	sql2 := strings.ToUpper(strings.TrimSpace(tx2.Statement.SQL.String()))
	if !strings.HasPrefix(sql2, "INSERT IGNORE INTO") {
		t.Fatalf("override broke the existing INSERT IGNORE convention, got: %s", tx2.Statement.SQL.String())
	}
}
