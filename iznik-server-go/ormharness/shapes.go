package ormharness

// Layer 1 for runtime-varying sites (plan section 7.2, extended for wave 5
// triage): a site kept raw with reason "runtime-varying: the statement has
// more than one possible rendered form" has no single goldenSql to compare
// against - that is exactly why it was kept raw rather than converted with
// AssertGoldenSQL. Writing that off as untestable was rejected: a query
// builder is precisely the right tool for conditionally-assembled SQL, and
// what makes such a site untestable is AssertGoldenSQL's one-golden-per-site
// assumption, not anything about the conversion itself.
//
// AssertGoldenShapes is AssertGoldenSQL generalised to N goldens. A site's
// possible renderings are its SHAPES - one per branch of whatever originally
// selected the SQL text (a switch case, a map lookup, an if/else arm) - and
// each shape gets its own expected SQL and its own GORM builder.
//
// The declared shape list lives in shapes.json (this package), not in the
// test file that calls AssertGoldenShapes, for the same reason keep-raw.json
// and approved-diffs.json are their own reviewable files rather than
// asserted inline: a test that supplies both the expected SQL and the code
// that produces it proves only that the two agree with each other. Recording
// the expected SQL somewhere the conversion's author does not also control
// is what makes the proof worth anything.
//
// shapes.json sits beside manifest.json and is embedded the same way (see
// the doc comment on embeddedManifest in golden.go): the apiv2 container
// mounts iznik-server-go as /app, so nothing outside that directory exists
// at runtime, and go:embed cannot reach outside its own package directory
// regardless of where the file is.
//
// Coverage is enforced in both directions, which is the load-bearing part of
// this mechanism. A conversion tested on two of a site's five branches is
// worse than leaving it raw, because it looks tested: the other three
// branches now run through hand-written GORM code with no test ever having
// rendered it. So:
//   - a shape shapes.json declares for this site but which the test's
//     []Shape does not name is a FAILURE (an uncovered branch), and
//   - a Shape the test supplies whose Name shapes.json does not declare for
//     this site is also a FAILURE (a name that cannot be checked against
//     anything, most likely a typo that silently stopped covering the shape
//     it meant to).
//
// Only once the two sides name exactly the same set of shapes does each
// Shape's Build get rendered and compared against its declared SQL, through
// the same assertRenderedSQL core AssertGoldenSQL uses - LIMIT/OFFSET
// binding, IN-list expansion and column-order normalisation are all still
// allowed. An approvedDiff is not: a diff is approved per SITE in the main
// manifest (plan 7.2 Layer 1), and a shaped site has no single rendered form
// for a reviewer to approve a diff against.

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"os"
	"sort"
	"strings"
	"sync"

	"gorm.io/gorm"
)

// Shape is one SQL form a runtime-varying site's original code could
// produce, paired with the GORM chain that should reproduce it. Name must
// match, character for character, a shape name declared for this site id in
// shapes.json: that is how AssertGoldenShapes finds the SQL to compare
// Build's render against, and how it tells "this shape was covered" apart
// from "this shape was renamed and neither name is now covered".
type Shape struct {
	Name  string
	Build func(tx *gorm.DB) *gorm.DB
}

// shapeEntry is one declared shape as recorded in shapes.json.
type shapeEntry struct {
	Name string `json:"name"`
	SQL  string `json:"sql"`
}

type shapesFile struct {
	Sites map[string][]shapeEntry `json:"sites"`
}

var (
	shapesOnce sync.Once
	shapesData map[string][]shapeEntry
	shapesErr  error

	// shapesPathOverride lets this package's own tests (shapes_test.go) point
	// loadShapes at a temporary shapes file instead of the embedded one,
	// mirroring manifestPathOverride in golden.go.
	shapesPathOverride string
)

//go:embed shapes.json
var embeddedShapes []byte

// loadShapes parses and caches shapes.json, preferring a test override when
// one is set. Mirrors loadManifest in golden.go.
func loadShapes() (map[string][]shapeEntry, error) {
	shapesOnce.Do(func() {
		raw := embeddedShapes
		source := "embedded shapes.json"

		if shapesPathOverride != "" {
			source = shapesPathOverride
			var err error
			raw, err = os.ReadFile(shapesPathOverride)
			if err != nil {
				shapesErr = fmt.Errorf("reading shapes at %s: %w", shapesPathOverride, err)
				return
			}
		}

		var sf shapesFile
		if err := json.Unmarshal(raw, &sf); err != nil {
			shapesErr = fmt.Errorf("parsing %s: %w", source, err)
			return
		}
		shapesData = sf.Sites
	})
	return shapesData, shapesErr
}

// resetShapesCacheForTest clears loadShapes's cache. Only shapes_test.go
// (same package) calls this, to point at a fixture shapes file per test case.
func resetShapesCacheForTest() {
	shapesOnce = sync.Once{}
	shapesData = nil
	shapesErr = nil
}

// AssertGoldenShapes builds every GORM statement named in shapes, renders
// each without executing it, and fails t unless every shape declared for
// siteID in shapes.json is both present in shapes (by Name) and canonically
// equivalent to its declared SQL - see the package doc comment above for why
// coverage is checked in both directions rather than just rendering whatever
// the caller happened to supply.
//
// Each Shape's Build has the same requirements as AssertGoldenSQL's build
// argument: it must end in a terminal call (Find, Count, Create, Delete,
// Update, Take or First), and never Scan, which GORM rejects under dry-run.
func AssertGoldenShapes(t TestingT, siteID string, shapes []Shape) {
	t.Helper()

	all, err := loadShapes()
	if err != nil {
		t.Fatalf("ormharness: %v", err)
	}

	declared, ok := all[siteID]
	if !ok || len(declared) == 0 {
		t.Fatalf("ormharness: no shapes declared for site %q in shapes.json; AssertGoldenShapes requires "+
			"a declared shape list (one entry per SQL form the site's original code could produce) before "+
			"it can check anything against it - add it to ormharness/shapes.json first, derived from the "+
			"branches in the original raw-SQL code, not from the conversion", siteID)
		return
	}

	declaredByName := make(map[string]string, len(declared))
	for _, d := range declared {
		declaredByName[d.Name] = d.SQL
	}

	suppliedByName := make(map[string]bool, len(shapes))
	for _, s := range shapes {
		suppliedByName[s.Name] = true
	}

	var missing []string
	for name := range declaredByName {
		if !suppliedByName[name] {
			missing = append(missing, name)
		}
	}
	var extra []string
	for _, s := range shapes {
		if _, ok := declaredByName[s.Name]; !ok {
			extra = append(extra, s.Name)
		}
	}

	if len(missing) > 0 || len(extra) > 0 {
		sort.Strings(missing)
		sort.Strings(extra)
		var b strings.Builder
		fmt.Fprintf(&b, "ormharness: site %q: shapes.json declares %d shape(s), the test supplies %d\n",
			siteID, len(declared), len(shapes))
		if len(missing) > 0 {
			fmt.Fprintf(&b, "  declared in shapes.json but not covered by the test: %s\n", strings.Join(missing, ", "))
		}
		if len(extra) > 0 {
			fmt.Fprintf(&b, "  supplied by the test but not declared in shapes.json: %s\n", strings.Join(extra, ", "))
		}
		b.WriteString("  every shape the original code could produce must be covered, and every " +
			"covered name must be a real declared shape - see the AssertGoldenShapes doc comment.")
		t.Fatalf("%s", b.String())
		return
	}

	for _, s := range shapes {
		assertRenderedSQL(t, siteID, fmt.Sprintf(" shape %q", s.Name), declaredByName[s.Name], "", s.Build)
	}
}
