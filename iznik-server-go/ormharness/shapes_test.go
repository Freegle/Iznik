package ormharness

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"gorm.io/gorm"
)

// withTestShapes points loadShapes at a temporary, fully-controlled shapes
// file for the duration of the calling test, via the unexported
// shapesPathOverride hook (see shapes.go). Mirrors withTestManifest in
// golden_test.go, for the same reason: these tests need to pin down exact
// coverage scenarios without depending on the content of the real
// shapes.json, which the rest of the migration programme is actively
// editing.
func withTestShapes(t *testing.T, sites map[string][]shapeEntry) {
	t.Helper()

	path := filepath.Join(t.TempDir(), "shapes.json")
	data, err := json.Marshal(shapesFile{Sites: sites})
	if err != nil {
		t.Fatalf("marshalling test shapes: %v", err)
	}
	if err := os.WriteFile(path, data, 0o600); err != nil {
		t.Fatalf("writing test shapes: %v", err)
	}

	prev := shapesPathOverride
	shapesPathOverride = path
	resetShapesCacheForTest()

	t.Cleanup(func() {
		shapesPathOverride = prev
		resetShapesCacheForTest()
	})
}

// runAssertGoldenShapes runs AssertGoldenShapes in a controlled recorder,
// mirroring runAssertGoldenSQL in golden_test.go, for the same reason:
// t.Run cannot express "this assertion is expected to fail" without failing
// the parent test too.
func runAssertGoldenShapes(t *testing.T, siteID string, shapes []Shape) (passed bool) {
	t.Helper()

	rec := &recordingT{}
	func() {
		defer func() {
			if r := recover(); r != nil && r != abortSentinel {
				panic(r) // a genuine panic, not our controlled unwind
			}
		}()
		AssertGoldenShapes(rec, siteID, shapes)
	}()

	if rec.failed {
		t.Logf("AssertGoldenShapes reported for %s: %s", siteID, rec.msg)
	}
	return !rec.failed
}

// --- Full coverage: the case a real pilot site exercises -------------------

func TestAssertGoldenShapes_FullCoveragePasses(t *testing.T) {
	withTestShapes(t, map[string][]shapeEntry{
		"site1": {
			{Name: "TypeA", SQL: "SELECT `col_a` FROM `table_a` WHERE id = ?"},
			{Name: "TypeB", SQL: "SELECT `col_b` FROM `table_b` WHERE id = ?"},
		},
	})

	if !runAssertGoldenShapes(t, "site1", []Shape{
		{Name: "TypeA", Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Table("`table_a`").Select("`col_a`").Where("id = ?", 1).Find(&map[string]interface{}{})
		}},
		{Name: "TypeB", Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Table("`table_b`").Select("`col_b`").Where("id = ?", 1).Find(&map[string]interface{}{})
		}},
	}) {
		t.Fatalf("expected full shape coverage with matching renders to pass")
	}
}

// --- Coverage in one direction: a declared shape the test never supplies --

func TestAssertGoldenShapes_UncoveredDeclaredShapeFails(t *testing.T) {
	withTestShapes(t, map[string][]shapeEntry{
		"site1": {
			{Name: "TypeA", SQL: "SELECT `col_a` FROM `table_a` WHERE id = ?"},
			{Name: "TypeB", SQL: "SELECT `col_b` FROM `table_b` WHERE id = ?"},
		},
	})

	// Only TypeA is supplied; TypeB is declared but not covered - exactly the
	// "conversion tested on two of five branches" failure mode this exists
	// to catch.
	if runAssertGoldenShapes(t, "site1", []Shape{
		{Name: "TypeA", Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Table("`table_a`").Select("`col_a`").Where("id = ?", 1).Find(&map[string]interface{}{})
		}},
	}) {
		t.Fatalf("expected a shape declared in shapes.json but not covered by the test to fail")
	}
}

// --- Coverage in the other direction: a supplied shape that isn't declared -

func TestAssertGoldenShapes_UndeclaredSuppliedShapeFails(t *testing.T) {
	withTestShapes(t, map[string][]shapeEntry{
		"site1": {
			{Name: "TypeA", SQL: "SELECT `col_a` FROM `table_a` WHERE id = ?"},
		},
	})

	// TypeA is covered, but "TypeC" (a typo'd or invented name) is also
	// supplied. This must fail too: a name that matches nothing in
	// shapes.json cannot have been checked against anything, so silently
	// accepting it would let a renamed/typo'd shape stop being covered
	// without the test noticing.
	if runAssertGoldenShapes(t, "site1", []Shape{
		{Name: "TypeA", Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Table("`table_a`").Select("`col_a`").Where("id = ?", 1).Find(&map[string]interface{}{})
		}},
		{Name: "TypeC", Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Table("`table_c`").Select("`col_c`").Where("id = ?", 1).Find(&map[string]interface{}{})
		}},
	}) {
		t.Fatalf("expected a shape name not declared in shapes.json to fail")
	}
}

// --- A genuine per-shape SQL mismatch must still fail -----------------------

func TestAssertGoldenShapes_GenuineMismatchInOneShapeFails(t *testing.T) {
	withTestShapes(t, map[string][]shapeEntry{
		"site1": {
			{Name: "TypeA", SQL: "SELECT `col_a` FROM `table_a` WHERE id = ?"},
			{Name: "TypeB", SQL: "SELECT `col_b` FROM `table_b` WHERE id = ?"},
		},
	})

	// Both shapes are named correctly (so coverage matches both ways), but
	// TypeB's Build queries the wrong table. Coverage matching alone must
	// not be enough to pass - each shape's rendered SQL still has to match
	// its own declared golden.
	if runAssertGoldenShapes(t, "site1", []Shape{
		{Name: "TypeA", Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Table("`table_a`").Select("`col_a`").Where("id = ?", 1).Find(&map[string]interface{}{})
		}},
		{Name: "TypeB", Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Table("`table_wrong`").Select("`col_b`").Where("id = ?", 1).Find(&map[string]interface{}{})
		}},
	}) {
		t.Fatalf("expected a shape whose render diverges from its own declared SQL to fail even though coverage matched")
	}
}

// --- No shapes declared for the site at all ---------------------------------

func TestAssertGoldenShapes_NoShapesDeclaredForSiteFails(t *testing.T) {
	withTestShapes(t, map[string][]shapeEntry{
		"other-site": {
			{Name: "TypeA", SQL: "SELECT 1"},
		},
	})

	if runAssertGoldenShapes(t, "site1", []Shape{
		{Name: "TypeA", Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Raw("SELECT 1").Find(&map[string]interface{}{})
		}},
	}) {
		t.Fatalf("expected AssertGoldenShapes to fail for a site id with no declared shapes at all")
	}
}

// --- Cosmetic-only differences still pass, exactly as AssertGoldenSQL ------
// allows, because both go through the same assertRenderedSQL core.

func TestAssertGoldenShapes_CosmeticDifferencePasses(t *testing.T) {
	withTestShapes(t, map[string][]shapeEntry{
		"site1": {
			{Name: "TypeA", SQL: "SELECT id FROM users WHERE id = ?"},
		},
	})

	if !runAssertGoldenShapes(t, "site1", []Shape{
		{Name: "TypeA", Build: func(tx *gorm.DB) *gorm.DB {
			return tx.Raw("select `id` from `users` where id = ?", 1).Find(&map[string]interface{}{})
		}},
	}) {
		t.Fatalf("expected a canonically equivalent render to pass, same as AssertGoldenSQL")
	}
}
