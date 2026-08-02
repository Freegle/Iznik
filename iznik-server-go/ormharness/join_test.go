package ormharness

// Wave 4 pre-flight: what GORM emits for multi-table SELECTs.
//
// Wave 4 is 140 sites, every one of them a join - 105 INNER, 40 LEFT, 24 with
// GROUP BY, 17 DISTINCT. Before converting any of them it is worth knowing
// which parts of a join GORM renders verbatim and which it rewrites, because
// the failure mode for a join is not a syntax error: it is the right columns
// from the wrong rows.
//
// The same approach as upsert_test.go, which found that
// clause.OnConflict{DoNothing} silently produces unusable SQL. Establish the
// behaviour by rendering it, then write the conversions against facts.

import (
	"strings"
	"testing"

	"gorm.io/gorm"
)

func TestJoin_TableAliasSurvives(t *testing.T) {
	// "users u" must stay an alias, not become a quoted table literally named
	// "users u". Everything downstream - the ON clause, the select list, the
	// WHERE - refers to it by the short name.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users u").Select("u.id").Where("u.id = ?", 1).Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if !strings.Contains(sql, "users u") {
		t.Fatalf("table alias did not survive: %s", sql)
	}
	if strings.Contains(sql, "`users u`") {
		t.Fatalf("alias was quoted as a single identifier: %s", sql)
	}
}

func TestJoin_MultipleJoinsKeepCallOrder(t *testing.T) {
	// A join chain is order-sensitive: the second join's ON clause may refer to
	// the first join's alias, so a reordering would not merely look different,
	// it would not resolve. GORM must emit them in call order.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages m").
			Select("m.id").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = m.id").
			Joins("INNER JOIN memberships ms ON ms.groupid = mg.groupid").
			Where("m.id = ?", 1).
			Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	first := strings.Index(sql, "messages_groups")
	second := strings.Index(sql, "memberships")
	if first < 0 || second < 0 {
		t.Fatalf("a join went missing: %s", sql)
	}
	if first > second {
		t.Fatalf("joins were reordered, so the second ON clause could not resolve: %s", sql)
	}
}

func TestJoin_WhereLandsAfterJoinsRegardlessOfCallOrder(t *testing.T) {
	// GORM assembles by clause, not by call order, so a Where written before a
	// Joins still renders after it. Worth pinning because the conversions will
	// often be written in whatever order reads best next to the original.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users u").
			Where("u.id = ?", 1).
			Joins("LEFT JOIN locations l ON l.id = u.lastlocation").
			Select("u.id, l.lat").
			Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	join := strings.Index(sql, "LEFT JOIN")
	where := strings.Index(strings.ToUpper(sql), "WHERE")
	if join < 0 || where < 0 {
		t.Fatalf("expected both a join and a where: %s", sql)
	}
	if join > where {
		t.Fatalf("the join rendered after the WHERE, which is not valid SQL: %s", sql)
	}
}

func TestJoin_BindArgsInJoinCondition(t *testing.T) {
	// Several wave 4 sites bind a value inside the ON clause rather than the
	// WHERE. The bind has to stay in the join, and in the right position
	// relative to the WHERE's own binds, or the arguments silently pair up with
	// the wrong placeholders.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("chat_rooms r").
			Select("r.id").
			Joins("INNER JOIN chat_roster cr ON cr.chatid = r.id AND cr.userid = ?", 7).
			Where("r.chattype = ?", "User2User").
			Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	join := strings.Index(sql, "chat_roster")
	where := strings.Index(strings.ToUpper(sql), "WHERE")
	if join < 0 || where < 0 || join > where {
		t.Fatalf("join with a bound condition did not render before the WHERE: %s", sql)
	}
	if strings.Count(sql, "?") != 2 {
		t.Fatalf("expected two placeholders, one in the join and one in the where, got %d: %s", strings.Count(sql, "?"), sql)
	}
}

func TestJoin_DistinctRendersInSelect(t *testing.T) {
	// 17 wave 4 sites are SELECT DISTINCT. Writing it into Select(...) keeps the
	// text closest to the original; this pins that it is not swallowed or
	// duplicated by GORM's own Distinct().
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages m").
			Select("DISTINCT m.id").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = m.id").
			Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if strings.Count(strings.ToUpper(sql), "DISTINCT") != 1 {
		t.Fatalf("expected exactly one DISTINCT, got: %s", sql)
	}
}

func TestJoin_GroupByAndHaving(t *testing.T) {
	// 24 sites group, 1 has a HAVING. GORM has dedicated builders for both, and
	// they must land in the right order relative to each other and the WHERE.
	sql, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages m").
			Select("m.id, COUNT(*) AS n").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = m.id").
			Where("m.id > ?", 0).
			Group("m.id").
			Having("COUNT(*) > ?", 1).
			Find(&dest)
	})
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	upper := strings.ToUpper(sql)
	where, group, having := strings.Index(upper, "WHERE"), strings.Index(upper, "GROUP BY"), strings.Index(upper, "HAVING")
	if where < 0 || group < 0 || having < 0 {
		t.Fatalf("expected WHERE, GROUP BY and HAVING: %s", sql)
	}
	if !(where < group && group < having) {
		t.Fatalf("clauses out of order (where=%d group=%d having=%d): %s", where, group, having, sql)
	}
}
