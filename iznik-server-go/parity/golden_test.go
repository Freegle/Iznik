package parity

import (
	"fmt"
	"strings"
	"testing"

	"gorm.io/gorm"
)

// recorder stands in for *testing.T so a test can assert that a bad conversion fails.
type recorder struct {
	failed  bool
	message string
}

func (r *recorder) Helper() {}
func (r *recorder) Fatalf(format string, args ...any) {
	if !r.failed {
		r.failed = true
		r.message = fmt.Sprintf(format, args...)
	}
}

func withSites(t *testing.T, sites map[string]Site) {
	t.Helper()
	t.Cleanup(setStoreForTest(sites))
}

func TestAssertGoldenSQL_PassesOnTheSameStatement(t *testing.T) {
	withSites(t, map[string]Site{
		"aaaaaaaaaaaa": {GoldenSQL: "SELECT groupid FROM memberships WHERE userid = ?"},
	})

	var ids []uint64
	AssertGoldenSQL(t, "aaaaaaaaaaaa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ?", 5).Pluck("groupid", &ids)
	})
}

func TestAssertGoldenSQL_FailsWhenThePredicateChanges(t *testing.T) {
	withSites(t, map[string]Site{
		"aaaaaaaaaaaa": {GoldenSQL: "SELECT groupid FROM memberships WHERE userid = ?"},
	})

	var ids []uint64
	r := &recorder{}
	AssertGoldenSQL(r, "aaaaaaaaaaaa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("groupid = ?", 5).Pluck("groupid", &ids)
	})
	if !r.failed || !strings.Contains(r.message, "diverges from goldenSql") {
		t.Fatalf("a changed predicate must fail Layer 1, got failed=%v message=%q", r.failed, r.message)
	}
}

func TestAssertGoldenSQL_ResolvesABoundLimit(t *testing.T) {
	withSites(t, map[string]Site{
		"aaaaaaaaaaaa": {GoldenSQL: "SELECT id FROM chat_rooms WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?) LIMIT 1"},
	})

	var id uint64
	build := func(limit int) Build {
		return func(tx *gorm.DB) *gorm.DB {
			return tx.Table("chat_rooms").Select("id").
				Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)", 1, 2, 2, 1).
				Limit(limit).Find(&id)
		}
	}
	AssertGoldenSQL(t, "aaaaaaaaaaaa", build(1))

	r := &recorder{}
	AssertGoldenSQL(r, "aaaaaaaaaaaa", build(50))
	if !r.failed {
		t.Fatalf("Limit(50) against a golden of LIMIT 1 must fail")
	}
}

func TestAssertGoldenSQL_AcceptsAnExpandedInList(t *testing.T) {
	withSites(t, map[string]Site{
		"aaaaaaaaaaaa": {GoldenSQL: "SELECT id FROM messages WHERE fromuser = ? AND id IN (?)"},
	})

	var ids []uint64
	AssertGoldenSQL(t, "aaaaaaaaaaaa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages").Where("fromuser = ? AND id IN (?)", 7, []uint64{1, 2, 3}).Pluck("id", &ids)
	})
}

func TestAssertGoldenSQL_AcceptsTheApprovedDiffAndNothingElse(t *testing.T) {
	withSites(t, map[string]Site{
		"aaaaaaaaaaaa": {
			GoldenSQL: "SELECT id FROM users WHERE deleted IS NULL",
			ApprovedDiff: &ApprovedDiff{
				SQL:    "SELECT id FROM users WHERE users.deleted IS NULL ORDER BY id",
				Reason: "The chain adds a stable order. The caller sorts anyway.",
			},
		},
	})

	var ids []uint64
	AssertGoldenSQL(t, "aaaaaaaaaaaa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("users.deleted IS NULL").Order("id").Pluck("id", &ids)
	})

	r := &recorder{}
	AssertGoldenSQL(r, "aaaaaaaaaaaa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("users.deleted IS NULL").Order("id DESC").Pluck("id", &ids)
	})
	if !r.failed || !strings.Contains(r.message, "does not match the recorded approvedDiff either") {
		t.Fatalf("a different divergence must not ride on the approved one: %q", r.message)
	}
}

func TestAssertGoldenSQL_FailsOnAnUnknownSiteAndAnEmptyGolden(t *testing.T) {
	withSites(t, map[string]Site{"bbbbbbbbbbbb": {GoldenSQL: ""}})

	var ids []uint64
	build := func(tx *gorm.DB) *gorm.DB { return tx.Table("users").Pluck("id", &ids) }

	r := &recorder{}
	AssertGoldenSQL(r, "cccccccccccc", build)
	if !r.failed || !strings.Contains(r.message, "no manifest entry") {
		t.Fatalf("an unknown id must fail loudly: %q", r.message)
	}

	r = &recorder{}
	AssertGoldenSQL(r, "bbbbbbbbbbbb", build)
	if !r.failed || !strings.Contains(r.message, "no goldenSql") {
		t.Fatalf("a site with no golden must fail loudly: %q", r.message)
	}
}

func TestAssertGoldenSQL_FailsWhenThereIsNoTerminalCall(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT id FROM users"}})

	r := &recorder{}
	AssertGoldenSQL(r, "aaaaaaaaaaaa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id")
	})
	if !r.failed || !strings.Contains(r.message, "terminal call") {
		t.Fatalf("a chain with no terminal call must say so: %q", r.message)
	}
}

func TestAssertGoldenSQL_CountsBinds(t *testing.T) {
	withSites(t, map[string]Site{"aaaaaaaaaaaa": {GoldenSQL: "SELECT id FROM users WHERE id = ?"}})

	// An argument passed to Select that the expression has no placeholder for: the text matches the
	// golden and the statement still cannot run.
	var ids []uint64
	r := &recorder{}
	AssertGoldenSQL(r, "aaaaaaaaaaaa", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Select("id", 99).Where("id = ?", 1).Find(&ids)
	})
	if !r.failed {
		t.Fatalf("a stray bind argument must fail Layer 1")
	}
}

func TestAssertDialectSQL_PostgresPlaceholdersReadAsQuestionMarks(t *testing.T) {
	var ids []uint64
	build := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ? AND emailfrequency != 0", 5).Pluck("groupid", &ids)
	}

	sql, err := RenderDryRunSQL(Postgres, build)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(sql, "$") {
		t.Fatalf("placeholders should be read as ?, got %s", sql)
	}
	AssertDialectSQL(t, Postgres, "SELECT groupid FROM memberships WHERE userid = ? AND emailfrequency != 0", build)

	r := &recorder{}
	AssertDialectSQL(r, Postgres, "SELECT groupid FROM memberships WHERE userid = ?", build)
	if !r.failed {
		t.Fatalf("a different expected statement must fail")
	}
}

func TestRenderDryRunSQL_MySQLQuotesWithBackticksAndPostgresDoesNot(t *testing.T) {
	var ids []uint64
	build := func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ?", 5).Pluck("groupid", &ids)
	}

	my, err := RenderDryRunSQL(MySQL, build)
	if err != nil {
		t.Fatal(err)
	}
	pg, err := RenderDryRunSQL(Postgres, build)
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(my, "`") || strings.Contains(pg, "`") {
		t.Fatalf("unexpected quoting: mysql=%s postgres=%s", my, pg)
	}
	// The same chain is one statement once canonicalised, which is the point of keeping both
	// dialects behind one call site.
	if Canonical(my) != Canonical(pg) {
		t.Fatalf("the engines render different statements:\n%s\n%s", my, pg)
	}
}

func TestLookupSite_UsesTheGeneratedStore(t *testing.T) {
	// Not the injected store: the embedded file must parse and hold real sites.
	sites, err := loadStore()
	if err != nil {
		t.Fatal(err)
	}
	if len(sites) < 100 {
		t.Fatalf("goldens.json holds %d sites, which is too few to be the generated store", len(sites))
	}
}

func TestAssertNoDefaultTransaction_FailsOnADefaultTransactionAndPassesWithoutOne(t *testing.T) {
	r := &recorder{}
	AssertNoDefaultTransaction(r, func(tx *gorm.DB) *gorm.DB {
		return tx.Table("memberships").Where("userid = ?", 5).Update("emailfrequency", 0)
	})
	if !r.failed || !strings.Contains(r.message, "transaction") {
		t.Fatalf("a write with GORM's default transaction must fail; got %+v", r)
	}

	r = &recorder{}
	AssertNoDefaultTransaction(r, func(tx *gorm.DB) *gorm.DB {
		return tx.Session(&gorm.Session{SkipDefaultTransaction: true}).
			Table("memberships").Where("userid = ?", 5).Update("emailfrequency", 0)
	})
	if r.failed {
		t.Fatalf("a write without the default transaction must pass: %s", r.message)
	}
}
