package ormharness

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"gorm.io/gorm"
)

// withTestManifest points loadManifest at a temporary, fully-controlled
// manifest for the duration of the calling test, via the unexported
// manifestPathOverride hook (see golden.go). This lets the approvedDiff and
// mismatch-handling tests pin down exact goldenSql/approvedDiff values
// without depending on the content of any real site in
// tools/orm-migration/manifest.json, which is being actively edited by the
// rest of the migration programme.
func withTestManifest(t *testing.T, sites map[string]manifestSite) {
	t.Helper()

	path := filepath.Join(t.TempDir(), "manifest.json")
	data, err := json.Marshal(manifestFile{Sites: sites})
	if err != nil {
		t.Fatalf("marshalling test manifest: %v", err)
	}
	if err := os.WriteFile(path, data, 0o600); err != nil {
		t.Fatalf("writing test manifest: %v", err)
	}

	prev := manifestPathOverride
	manifestPathOverride = path
	resetManifestCacheForTest()

	t.Cleanup(func() {
		manifestPathOverride = prev
		resetManifestCacheForTest()
	})
}

// runAssertGoldenSQL runs AssertGoldenSQL in a subtest and reports whether
// it passed, which is the standard Go idiom for testing that a t.Fatalf-
// based helper fails the test under the right conditions: t.Run executes
// the subtest synchronously and returns false if it failed, without taking
// the outer test down with it.
func runAssertGoldenSQL(t *testing.T, siteID string, build func(tx *gorm.DB) *gorm.DB) (passed bool) {
	t.Helper()
	return t.Run(siteID, func(st *testing.T) {
		AssertGoldenSQL(st, siteID, build)
	})
}

// --- Tests against the real manifest -----------------------------------
//
// These two prove the actual contract end to end: AssertGoldenSQL looks a
// site up BY ID in the real tools/orm-migration/manifest.json, and a GORM
// dry-run render that is only cosmetically different from the recorded
// goldenSql (backtick quoting, keyword case) still passes. The site IDs are
// pinned to specific, stable fixture-cleanup call sites (see the comments
// below) rather than invented, so a passing test here is also a live check
// that findManifestPath still locates the real manifest from this
// package's working directory.

func TestAssertGoldenSQL_RealManifestSite_Delete(t *testing.T) {
	// iznik-server-go/test/chat_test.go:2293, TestReviewChatOwnGroupFirst:
	// db.Exec("DELETE FROM chat_messages WHERE id = ?", id).
	const siteID = "001cd13b12ca"

	if !runAssertGoldenSQL(t, siteID, func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").Where("id = ?", 1).Delete(nil)
	}) {
		t.Fatalf("expected the GORM delete to match site %s's goldenSql", siteID)
	}
}

func TestAssertGoldenSQL_RealManifestSite_Count(t *testing.T) {
	// iznik-server-go/test/message_test.go:6660,
	// TestRejectToDraftOwnerWithdrawsAllGroups:
	// db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ?", id).
	const siteID = "001f278f7332"
	var count int64

	if !runAssertGoldenSQL(t, siteID, func(tx *gorm.DB) *gorm.DB {
		return tx.Table("messages_groups").Where("msgid = ?", 1).Count(&count)
	}) {
		t.Fatalf("expected the GORM count to match site %s's goldenSql", siteID)
	}
}

func TestAssertGoldenSQL_UnknownSiteFails(t *testing.T) {
	if runAssertGoldenSQL(t, "not-a-real-site-id", func(tx *gorm.DB) *gorm.DB {
		return tx.Raw("SELECT 1")
	}) {
		t.Fatalf("expected AssertGoldenSQL to fail for a site id absent from the manifest")
	}
}

// --- Tests against a synthetic manifest ---------------------------------

func TestAssertGoldenSQL_MatchingRenderPasses(t *testing.T) {
	withTestManifest(t, map[string]manifestSite{
		"site1": {GoldenSQL: "SELECT id FROM users WHERE id = ?"},
	})

	if !runAssertGoldenSQL(t, "site1", func(tx *gorm.DB) *gorm.DB {
		// Deliberately different cosmetically (backticks, keyword case) to
		// prove the pass goes through Canonical, not a literal comparison.
		return tx.Raw("select `id` from `users` where id = ?", 1)
	}) {
		t.Fatalf("expected a canonically equivalent render to pass")
	}
}

// This is the "a genuine mismatch correctly fails" case required of Layer
// 1: a rendered statement that queries a different table entirely must not
// be waved through by Canonical, and must fail the test rather than the
// harness silently accepting it.
func TestAssertGoldenSQL_GenuineMismatchFails(t *testing.T) {
	withTestManifest(t, map[string]manifestSite{
		"site1": {GoldenSQL: "SELECT id FROM users WHERE id = ?"},
	})

	if runAssertGoldenSQL(t, "site1", func(tx *gorm.DB) *gorm.DB {
		return tx.Raw("SELECT id FROM groups WHERE id = ?", 1)
	}) {
		t.Fatalf("expected a genuinely different rendered statement to fail parity")
	}
}

func TestAssertGoldenSQL_NoGoldenSQLFails(t *testing.T) {
	withTestManifest(t, map[string]manifestSite{
		"site1": {}, // present in the manifest, but with no goldenSql recorded
	})

	if runAssertGoldenSQL(t, "site1", func(tx *gorm.DB) *gorm.DB {
		return tx.Raw("SELECT 1")
	}) {
		t.Fatalf("expected a site with no goldenSql to fail rather than pass vacuously")
	}
}

// --- approvedDiff escape hatch -------------------------------------------
//
// Modelled on the plan's own example of a legitimate divergence: the ORM
// selecting an explicit column list where the hand-written SQL used *.

func TestAssertGoldenSQL_ApprovedDiffPasses(t *testing.T) {
	withTestManifest(t, map[string]manifestSite{
		"site1": {
			GoldenSQL:    "SELECT * FROM users WHERE id = ?",
			ApprovedDiff: "SELECT id, email FROM users WHERE id = ?",
		},
	})

	if !runAssertGoldenSQL(t, "site1", func(tx *gorm.DB) *gorm.DB {
		return tx.Raw("select id, email from users where id = ?", 1)
	}) {
		t.Fatalf("expected a render matching the recorded approvedDiff to pass")
	}
}

// The escape hatch is for exactly one reviewed divergence, not "anything
// goes once a site has an approvedDiff": a third, unreviewed rendering must
// still fail.
func TestAssertGoldenSQL_ApprovedDiffDoesNotCoverOtherDivergences(t *testing.T) {
	withTestManifest(t, map[string]manifestSite{
		"site1": {
			GoldenSQL:    "SELECT * FROM users WHERE id = ?",
			ApprovedDiff: "SELECT id, email FROM users WHERE id = ?",
		},
	})

	if runAssertGoldenSQL(t, "site1", func(tx *gorm.DB) *gorm.DB {
		return tx.Raw("select id, email, phone from users where id = ?", 1)
	}) {
		t.Fatalf("expected a divergence other than the recorded approvedDiff to fail")
	}
}

// --- RenderDryRunSQL, directly --------------------------------------------

func TestRenderDryRunSQL_NoDatabaseNeeded(t *testing.T) {
	// This is the load-bearing claim of Layer 1: it must be possible to
	// render SQL without a database fixture. There is deliberately no test
	// database setup anywhere in this file; if RenderDryRunSQL ever needed
	// one, every test above would hang or fail on a connection error
	// instead of running instantly.
	got, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Raw("SELECT id FROM users WHERE id = ?", 5)
	})
	if err != nil {
		t.Fatalf("RenderDryRunSQL returned an error with no database available: %v", err)
	}

	want := "SELECT id FROM users WHERE id = ?"
	if Canonical(got) != Canonical(want) {
		t.Fatalf("got %q, want canonically %q", got, want)
	}
}

// GORM refuses a global UPDATE/DELETE with no WHERE clause
// (gorm.ErrMissingWhereClause) even in dry-run mode; RenderDryRunSQL must
// surface that as a Go error rather than paper over it, since a converted
// site that lost its WHERE clause is exactly the kind of bug Layer 1 exists
// to catch before Layer 2 or production would.
func TestRenderDryRunSQL_PropagatesBuildError(t *testing.T) {
	_, err := RenderDryRunSQL(func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Delete(nil) // no Where(...)
	})
	if err == nil {
		t.Fatalf("expected an error for a DELETE with no WHERE clause, got nil")
	}
}
