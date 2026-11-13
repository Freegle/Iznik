package parity

import (
	"strings"
	"testing"

	"gorm.io/gorm"
)

// These tests exercise the comparison itself. They need one PostgreSQL database with the baseline
// schema and no MySQL: two handles on that one database stand in for the two engines, so a result
// that differs between them has to be made to differ by the test.
func pgPair(t *testing.T) []Engine {
	t.Helper()
	pg, err := openEngine(Postgres)
	if err != nil {
		if strings.TrimSpace(getenv("PG_ENGINE", "")) == "1" {
			t.Fatalf("PG_ENGINE=1 but PostgreSQL is not reachable: %v", err)
		}
		t.Skipf("PostgreSQL is not available (%v)", err)
	}
	return []Engine{
		{Name: "left", Dialect: Postgres, DB: pg.DB},
		{Name: "right", Dialect: Postgres, DB: pg.DB},
	}
}

func membershipFixture() Fixture {
	return Fixture{
		Table("users", Row{"id": ID(1)}, Row{"id": ID(2)}),
		Table("groups", Row{"id": ID(100), "nameshort": "parity-a", "type": "Freegle", "polyindex": Geometry("POINT(-3.2 55.9)")}),
		Table("memberships",
			Row{"id": ID(10), "userid": ID(1), "groupid": ID(100)},
			Row{"id": ID(11), "userid": ID(2), "groupid": ID(100)},
		),
	}
}

func groupIDsFor(db *gorm.DB, uid uint64) []uint64 {
	var ids []uint64
	db.Table("memberships").Where("userid = ?", uid).Pluck("groupid", &ids)
	return ids
}

func memberRows(db *gorm.DB) []map[string]any {
	var rows []map[string]any
	db.Table("memberships").Select("id", "userid").Where("groupid = ?", ID(100)).Order("id").Find(&rows)
	return rows
}

func TestResultParity_EnginesThatAgreePass(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT groupid FROM memberships WHERE userid = ?"}})

	assertResultParity(t, pgPair(t), "aaaaaaaaaaaa", membershipFixture(),
		func(db *gorm.DB) any { return groupIDsFor(db, ID(1)) },
		Expect([]uint64{ID(100)}))
}

func TestResultParity_DifferentRowsFail(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT groupid FROM memberships WHERE userid = ?"}})

	calls := 0
	r := &recorder{}
	assertResultParity(r, pgPair(t), "aaaaaaaaaaaa", membershipFixture(), func(db *gorm.DB) any {
		calls++
		if calls == 2 {
			return []uint64{ID(999)} // the second engine answers differently
		}
		return groupIDsFor(db, ID(1))
	})
	if !r.failed || !strings.Contains(r.message, "gives different rows on left and right") {
		t.Fatalf("different rows must fail, got failed=%v %q", r.failed, r.message)
	}
}

func TestResultParity_ChecksOrderOnlyWhereTheGoldenOrders(t *testing.T) {
	reversed := func() func(db *gorm.DB) any {
		calls := 0
		return func(db *gorm.DB) any {
			calls++
			rows := memberRows(db)
			if calls == 2 {
				rows[0], rows[1] = rows[1], rows[0]
			}
			return rows
		}
	}

	withSites(t, map[string]Site{
		"aaaaaaaaaaaa": {GoldenSQL: "SELECT id, userid FROM memberships WHERE groupid = ? ORDER BY id"},
		"bbbbbbbbbbbb": {GoldenSQL: "SELECT id, userid FROM memberships WHERE groupid = ?"},
	})

	r := &recorder{}
	assertResultParity(r, pgPair(t), "aaaaaaaaaaaa", membershipFixture(), reversed())
	if !r.failed {
		t.Fatalf("a golden with ORDER BY must compare in order")
	}

	assertResultParity(t, pgPair(t), "bbbbbbbbbbbb", membershipFixture(), reversed())

	r = &recorder{}
	assertResultParity(r, pgPair(t), "bbbbbbbbbbbb", membershipFixture(), reversed(), Ordered())
	if !r.failed {
		t.Fatalf("Ordered() must compare in order whatever the golden says")
	}

	assertResultParity(t, pgPair(t), "aaaaaaaaaaaa", membershipFixture(), reversed(), Unordered())
}

func TestResultParity_AnEmptyResultFailsUnlessAllowed(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT groupid FROM memberships WHERE userid = ?"}})

	run := func(db *gorm.DB) any { return groupIDsFor(db, ID(99)) }

	r := &recorder{}
	assertResultParity(r, pgPair(t), "aaaaaaaaaaaa", membershipFixture(), run)
	if !r.failed || !strings.Contains(r.message, "agreeing on nothing") {
		t.Fatalf("an empty result must fail: %q", r.message)
	}

	assertResultParity(t, pgPair(t), "aaaaaaaaaaaa", membershipFixture(), run, AllowEmpty())
}

func TestResultParity_AFailedStatementIsReportedEvenWhenTheSiteIgnoresIt(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT nosuchcolumn FROM memberships"}})

	r := &recorder{}
	assertResultParity(r, pgPair(t), "aaaaaaaaaaaa", membershipFixture(), func(db *gorm.DB) any {
		var ids []uint64
		db.Table("memberships").Pluck("nosuchcolumn", &ids) // the error is dropped, as production code often does
		return ids
	}, AllowEmpty())
	if !r.failed || !strings.Contains(r.message, "statement(s) failed") || !strings.Contains(r.message, "nosuchcolumn") {
		t.Fatalf("a failed statement must be reported with its SQL: %q", r.message)
	}
}

func TestResultParity_ExpectCatchesTwoEnginesDriftingTogether(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT groupid FROM memberships WHERE userid = ?"}})

	r := &recorder{}
	assertResultParity(r, pgPair(t), "aaaaaaaaaaaa", membershipFixture(),
		func(db *gorm.DB) any { return groupIDsFor(db, ID(1)) },
		Expect([]uint64{ID(101)}))
	if !r.failed || !strings.Contains(r.message, "not what the fixture should give") {
		t.Fatalf("Expect must fail when the result is not the expected one: %q", r.message)
	}
}

func TestResultParity_TheFixtureIsRolledBack(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT groupid FROM memberships WHERE userid = ?"}})

	engines := pgPair(t)
	assertResultParity(t, engines, "aaaaaaaaaaaa", membershipFixture(), func(db *gorm.DB) any { return groupIDsFor(db, ID(1)) })

	var n int64
	engines[0].DB.Table("users").Where("id >= ?", IDBase).Count(&n)
	if n != 0 {
		t.Fatalf("%d fixture rows were left behind", n)
	}
}

func TestResultParity_ABadFixtureNamesTheTableAndRow(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT 1"}})

	bad := Fixture{Table("memberships", Row{"id": ID(10), "userid": ID(1)})} // no groupid: NOT NULL
	r := &recorder{}
	assertResultParity(r, pgPair(t), "aaaaaaaaaaaa", bad, func(db *gorm.DB) any { return 1 })
	if !r.failed || !strings.Contains(r.message, "fixture table memberships row 0") {
		t.Fatalf("a fixture the engine refuses must say which row: %q", r.message)
	}
}

func TestResultParity_NeedsAnEngineAndARealSite(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT 1"}})
	run := func(db *gorm.DB) any { return 1 }

	r := &recorder{}
	assertResultParity(r, nil, "aaaaaaaaaaaa", nil, run)
	if !r.failed || !strings.Contains(r.message, "no engine") {
		t.Fatalf("no engine is not a comparison: %q", r.message)
	}

	r = &recorder{}
	assertResultParity(r, pgPair(t), "cccccccccccc", nil, run)
	if !r.failed || !strings.Contains(r.message, "no manifest entry") {
		t.Fatalf("an unknown site must fail: %q", r.message)
	}
}

// One engine runs the checks that need no second engine: the statements must not fail, the result
// must not be empty, and Expect must hold.
func TestResultParity_OneEngineStillChecksTheSiteWorksAndGivesTheExpectedRows(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT groupid FROM memberships WHERE userid = ?"}})

	one := pgPair(t)[:1]
	run := func(db *gorm.DB) any { return groupIDsFor(db, ID(1)) }
	assertResultParity(t, one, "aaaaaaaaaaaa", membershipFixture(), run, Expect([]uint64{ID(100)}))

	r := &recorder{}
	assertResultParity(r, one, "aaaaaaaaaaaa", membershipFixture(), run, Expect([]uint64{ID(101)}))
	if !r.failed {
		t.Fatalf("a wrong answer must fail on one engine too")
	}
}

// An ordinary row can end up on a fixture id on MySQL (see Fixture.Load). The fixture must still load,
// and the row must be back once the transaction is rolled back.
func TestFixtureLoad_MovesAsideARowSittingOnAFixtureID(t *testing.T) {
	my, err := openEngine(MySQL)
	if err != nil {
		t.Skipf("parity: MySQL is not available (%v)", err)
	}
	id := ID(999901)
	if err := my.DB.Exec("INSERT INTO users (id) VALUES (?)", id).Error; err != nil {
		t.Fatalf("placing a row on the fixture id: %v", err)
	}
	defer func() {
		my.DB.Exec("DELETE FROM users WHERE id = ?", id)
		// The committed row moved the counter into the fixture range; put it back.
		my.DB.Exec("ALTER TABLE `users` AUTO_INCREMENT = 1")
	}()

	tx := my.DB.Begin()
	err = Fixture{Table("users", Row{"id": id})}.Load(tx)
	tx.Rollback()
	if err != nil {
		t.Fatalf("the fixture must load over a row that sits on its id: %v", err)
	}

	var n int64
	my.DB.Table("users").Where("id = ?", id).Count(&n)
	if n != 1 {
		t.Fatalf("the row that was moved aside must be back after the rollback, found %d", n)
	}
}
