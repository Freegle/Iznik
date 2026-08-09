package database

import (
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// RegisterCustomClauseBuilders wires two GORM clause-builder overrides that
// production ORM conversions of two keep-raw SQL shapes depend on: REPLACE
// INTO, and INSERT ... SELECT. Both have to be registered once, at
// db-construction time, on db.Config.ClauseBuilders - a map *gorm.DB.Session()
// shares by reference (gorm.go: `txConfig := *db.Config` keeps the same
// underlying map) - rather than reachable from any per-call-site chain. See
// the doc comments on each override below for why neither shape can be
// expressed any other way.
//
// Call this once, AFTER every plugin that initializes a dialector against
// the same *gorm.DB has run - in particular after dbresolver.Register. The
// mysql driver's Initialize re-installs its default ClauseBuilders entries
// (mysql.go: `for k, v := range dialector.ClauseBuilders() {
// db.ClauseBuilders[k] = v }`), so a "VALUES" override registered before
// dbresolver sets up its replica dialectors is silently overwritten - that
// ordering mistake shipped 2026-08-06 and broke every production
// InsertSelect (Error 1054 placeholder inserts) while CI, which configures
// no replica, stayed green. See TestCustomClauseBuildersSurviveReadReplica
// in clausebuilders_resolver_test.go, which pins the production
// construction order.
func RegisterCustomClauseBuilders(db *gorm.DB) {
	db.ClauseBuilders["INSERT"] = replaceIntoClauseBuilder

	// The MySQL dialector already registers its own "VALUES" builder at
	// gorm.Open time (gorm.io/driver/mysql/mysql.go's ClauseBuilders():
	// renders "VALUES()" for a zero-column clause.Values, which is what an
	// all-default-fields Create() needs on MySQL - plain clause.Values.Build
	// would write the SQL-standard but MySQL-invalid "DEFAULT VALUES"
	// instead. Overwriting db.ClauseBuilders["VALUES"] outright would silence
	// that for every ordinary Create() in the codebase, not just
	// InsertSelect's. Capturing it here and falling back to it - rather than
	// to clause.Clause.Build directly - keeps every non-InsertSelect Create()
	// byte-identical to before this file existed.
	previousValuesBuilder := db.ClauseBuilders["VALUES"]
	db.ClauseBuilders["VALUES"] = func(c clause.Clause, builder clause.Builder) {
		insertSelectClauseBuilder(c, builder, previousValuesBuilder)
	}
}

// --- REPLACE INTO ------------------------------------------------------

// replaceIntoClauseBuilder makes REPLACE INTO reachable through the ordinary
// GORM Create() pipeline. clause.Insert{Modifier: "REPLACE"} alone renders
// "INSERT REPLACE INTO ...", not "REPLACE INTO ...": the literal "INSERT"
// is written by clause.Clause.Build from c.Name, which Statement.AddClause
// hardcodes from clause.Insert.Name() ("INSERT") regardless of Modifier
// (clause/insert.go). Table(), Clauses() and gorm.Expr all operate on the
// Expression half of the clause, never that wrapping Name, so nothing
// reachable from a call site can suppress it.
//
// stmt.DB.ClauseBuilders[name] (statement.go: `if b, ok :=
// stmt.DB.ClauseBuilders[name]; ok { b(c, stmt) } else { c.Build(stmt) }`)
// is the one hook that decides how the clause NAMED "INSERT" actually
// renders, bypassing clause.Clause.Build (and its hardcoded "INSERT ")
// entirely - so this is the only place the keyword swap can happen, and it
// has to happen once, here, not per conversion.
//
// Every ordinary Create() - a plain INSERT, or the existing INSERT IGNORE
// convention (clause.Insert{Modifier: "IGNORE"}, proven by the retired
// ormharness's upsert_test.go, removed in d22ba1d6c)
// - falls through to the default builder unchanged, since only
// Modifier == "REPLACE" is handled specially.
//
// Proven, with the negative case ("INSERT REPLACE INTO" from Modifier alone
// is NOT valid SQL), in the retired ormharness's updatejoin_replace_test.go
// and test/orm_tier4_test.go (both removed in d22ba1d6c).
func replaceIntoClauseBuilder(c clause.Clause, builder clause.Builder) {
	insert, ok := c.Expression.(clause.Insert)
	if !ok || insert.Modifier != "REPLACE" {
		c.Build(builder) // fall through: ordinary INSERT, INSERT IGNORE, etc.
		return
	}
	builder.WriteString("REPLACE INTO ")
	if insert.Table.Name == "" {
		builder.WriteQuoted(clause.Table{Name: clause.CurrentTable})
	} else {
		builder.WriteQuoted(insert.Table)
	}
}

// --- INSERT ... SELECT ---------------------------------------------------

// insertSelectSettingKey is the db.Set/Get key InsertSelect uses to carry an
// INSERT ... SELECT's column list and SELECT body past
// callbacks/create.go's Create(), which unconditionally recomputes the
// VALUES clause from Statement.Dest just before Build runs
// (`db.Statement.AddClause(ConvertToCreateValues(db.Statement))`) - so
// anything pre-set via a chained .Clauses(...) call is simply overwritten
// before insertSelectClauseBuilder ever sees it. Statement.Settings (the
// same escape hatch documented on gorm.WithResult - see
// test/insertid_gorm_writeback_test.go's note that it must be reached via .Clauses(),
// not .Set(), for THAT mechanism) is, for THIS mechanism, exactly the right
// channel: db.Set stores into Statement.Settings directly, and it survives
// untouched because ConvertToCreateValues never looks at it.
const insertSelectSettingKey = "orm:insert-select"

// insertSelectPlaceholderColumn is the single dummy column InsertSelect
// passes to Create() purely to give ConvertToCreateValues a Dest to convert.
// Its value is never rendered: insertSelectClauseBuilder ignores whatever
// clause.Values ConvertToCreateValues built from it and substitutes the real
// SELECT text in its place. It has to be a genuine non-empty, non-slice map
// so ConvertMapToValuesForCreate's ordinary path runs rather than
// ConvertSliceOfMapToValuesForCreate's `len(mapValues) == 0` guard, which
// calls stmt.AddError(gorm.ErrEmptySlice) and returns before any SQL is
// built at all - the exact way the retired ormharness's insertselect_test.go's first
// attempt at this proof failed to reach the comparison it was written to
// make.
const insertSelectPlaceholderColumn = "__insert_select_placeholder__"

// InsertSelect issues an INSERT ... SELECT as a single atomic statement -
// the one production shape GORM's own Create() has no vocabulary for: it
// takes value ROWS, never a source query, so there is no .Values(subquery)
// method to call, and splitting it into a read then a separate Create()
// would open a race the original single statement does not have.
//
// columnsAndSelect is everything the raw SQL originally wrote after the
// table name: the parenthesised column list, then the SELECT itself (with
// its own FROM/JOIN/WHERE), "?" placeholders exactly where the original
// used them. vars are those binds, in order. For example, newsfeed.go's
// "carry the photo" site:
//
//	database.InsertSelect(db, "messages_attachments",
//	    "(msgid, contenttype, archived, hash, externaluid, externalmods, identification, `primary`) "+
//	        "SELECT ?, ni.contenttype, ni.archived, ni.hash, ni.externaluid, ni.externalmods, ni.identification, 1 "+
//	        "FROM newsfeed n INNER JOIN newsfeed_images ni ON ni.id = n.imageid "+
//	        "WHERE n.id = ? AND ni.externaluid IS NOT NULL "+
//	        "AND NOT EXISTS (SELECT 1 FROM messages_attachments ma WHERE ma.msgid = ?)",
//	    req.Msgid, req.ID, req.Msgid)
//
// This still goes through the ordinary GORM Create() pipeline (transactions,
// hooks, dry-run support all work unchanged) with exactly one clause
// substituted - not a bespoke .Exec() escape hatch reachable only here.
func InsertSelect(tx *gorm.DB, table string, columnsAndSelect string, vars ...interface{}) *gorm.DB {
	return tx.Table(table).
		Set(insertSelectSettingKey, gorm.Expr(columnsAndSelect, vars...)).
		Create(map[string]interface{}{insertSelectPlaceholderColumn: 0})
}

// insertSelectClauseBuilder renders the VALUES clause (createClauses =
// []string{"INSERT", "VALUES", "ON CONFLICT"}, callbacks/callbacks.go) as an
// InsertSelect call's SELECT text, when one was set via
// insertSelectSettingKey, instead of GORM's own "(cols) VALUES (...)" - see
// InsertSelect's doc comment for why Statement.Settings, not the clause
// ConvertToCreateValues computed, is what this reads.
//
// Every ordinary Create() call on this *gorm.DB - which is the overwhelming
// majority - falls through to fallback unchanged: only a statement built via
// InsertSelect carries this setting. fallback is whatever
// db.ClauseBuilders["VALUES"] held before RegisterCustomClauseBuilders ran
// (the MySQL dialector's own default, normally), so ordinary Create() keeps
// behaving exactly as it did before this file existed; nil falls back to
// clause.Clause.Build itself.
func insertSelectClauseBuilder(c clause.Clause, builder clause.Builder, fallback clause.ClauseBuilder) {
	if stmt, ok := builder.(*gorm.Statement); ok {
		if raw, ok := stmt.Settings.Load(insertSelectSettingKey); ok {
			if expr, ok := raw.(clause.Expr); ok {
				expr.Build(builder)
				return
			}
		}
	}
	if fallback != nil {
		fallback(c, builder)
		return
	}
	c.Build(builder)
}
