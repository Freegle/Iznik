package ormharness

// Layer 1 assertion (plan section 7.2): renders a GORM call in dry-run mode
// (no query ever reaches a database) and checks the SQL it would have sent
// against the goldenSql recorded for that site in
// this package's manifest.json, via Canonical (canonical.go).
//
// Looking the golden up by site ID, rather than letting the caller pass a
// literal string, is what enforces plan Gate 2: "a site cannot be marked
// converted unless a parity test bearing its ID exists and passes". A test
// that calls AssertGoldenSQL names the manifest entry it proves; there is
// no way to write a passing Layer 1 test that is not tied to a real site.

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"sync"

	"gorm.io/driver/mysql"
	"gorm.io/gorm"
)

// TestingT is the part of *testing.T this package needs. Taking an interface
// rather than the concrete type is what lets the package's own tests assert
// that a bad conversion FAILS: a failing subtest fails its parent, so
// "expected failure" cannot be expressed with t.Run, and a recorder has to be
// substituted instead. *testing.T satisfies this as-is.
type TestingT interface {
	Helper()
	Fatalf(format string, args ...any)
}

// AssertGoldenSQL builds the GORM statement produced by build, renders it
// without executing it, and fails t unless the result is canonically
// equivalent to the goldenSql recorded for siteID in the migration
// manifest - or matches the site's approvedDiff, if one has been reviewed
// and recorded (see manifest.Site.ApprovedDiff in tools/orm-migration).
//
// build MUST include the terminal call (Find, First, Create, Save, Delete,
// Scan, Row, Rows, ...). GORM only finishes assembling a statement when a
// terminal call runs, so without one Statement.SQL is empty and there is
// nothing to compare; dry-run mode intercepts the call so no connection is
// touched. Note this is the opposite convention to Layer 2's
// ReplacementQuery (resultparity.go), which must stop SHORT of a terminal
// call because AssertResultParity invokes Rows() itself. For example:
//
//	ormharness.AssertGoldenSQL(t, "a1b2c3d4e5f6", func(tx *gorm.DB) *gorm.DB {
//		return tx.Table("alerts").Where("id = ?", 1).Delete(nil)
//	})
func AssertGoldenSQL(t TestingT, siteID string, build func(tx *gorm.DB) *gorm.DB) {
	t.Helper()

	sites, err := loadManifest()
	if err != nil {
		t.Fatalf("ormharness: %v", err)
	}

	site, ok := sites[siteID]
	if !ok {
		t.Fatalf("ormharness: no manifest entry for site %q; AssertGoldenSQL must be called with a real site id from the ORM migration manifest", siteID)
	}
	if site.GoldenSQL == "" {
		t.Fatalf("ormharness: manifest site %q has no goldenSql to compare against", siteID)
	}

	rendered, err := RenderDryRunSQL(build)
	if err != nil {
		t.Fatalf("ormharness: site %q: rendering GORM statement: %v", siteID, err)
	}

	wantCanon := Canonical(site.GoldenSQL)
	gotCanon := Canonical(rendered)
	if gotCanon == wantCanon {
		return
	}

	if site.ApprovedDiff != "" && Canonical(site.ApprovedDiff) == gotCanon {
		return // A reviewer already recorded and justified exactly this divergence.
	}

	verdict := "no approvedDiff is recorded for this site"
	if site.ApprovedDiff != "" {
		verdict = "the rendered SQL does not match the recorded approvedDiff either"
	}
	t.Fatalf("ormharness: site %q: rendered SQL diverges from goldenSql and %s\n%s",
		siteID, verdict, formatMismatch(site.GoldenSQL, rendered, wantCanon, gotCanon))
}

// RenderDryRunSQL builds a GORM statement via build and returns the raw SQL
// GORM would have sent, without ever executing a query or opening a
// database connection. Bind values are rendered as literal '?' placeholders
// (MySQL's placeholder style), matching how goldenSql is recorded in the
// manifest, rather than interpolated into the string as gorm.DB.ToSQL does.
//
// It is exported (rather than folded into AssertGoldenSQL) because it is
// independently useful: canonical_test.go and golden_test.go exercise it
// directly to test rendering and canonicalisation separately.
func RenderDryRunSQL(build func(tx *gorm.DB) *gorm.DB) (string, error) {
	db, err := dryRunDB()
	if err != nil {
		return "", fmt.Errorf("constructing dry-run *gorm.DB: %w", err)
	}

	session := db.Session(&gorm.Session{DryRun: true, SkipDefaultTransaction: true})
	tx := build(session)
	if tx == nil {
		return "", fmt.Errorf("build function returned a nil *gorm.DB")
	}
	if tx.Error != nil {
		return "", tx.Error
	}
	return tx.Statement.SQL.String(), nil
}

// dryRunDSN is never dialled. gorm.Open only reaches the network if the
// dialector's Initialize step queries "SELECT VERSION()", which
// SkipInitializeWithVersion below switches off; database/sql.Open itself is
// lazy for the go-sql-driver/mysql driver (it implements
// driver.DriverContext, so Open just parses the DSN via OpenConnector and
// defers dialling to first use). Between those two facts, building this
// *gorm.DB and running dry-run statements against it never touches a real
// database, which is what lets Layer 1 tests run with no DB fixture.
const dryRunDSN = "ormharness:ormharness@tcp(127.0.0.1:3306)/ormharness?parseTime=true&loc=Local"

var (
	dryRunDBOnce sync.Once
	dryRunDBInst *gorm.DB
	dryRunDBErr  error
)

func dryRunDB() (*gorm.DB, error) {
	dryRunDBOnce.Do(func() {
		dryRunDBInst, dryRunDBErr = gorm.Open(mysql.New(mysql.Config{
			DriverName:                "mysql",
			DSN:                       dryRunDSN,
			SkipInitializeWithVersion: true,
		}), &gorm.Config{
			// gorm.Open pings the connection pool unless this is set
			// (gorm.go:236 in v1.31.0). Without it the lazy-dial reasoning
			// above does not hold: Open would dial 127.0.0.1:3306, fail, and
			// take every Layer 1 test down with it.
			DisableAutomaticPing: true,
		})
	})
	return dryRunDBInst, dryRunDBErr
}

// manifestSite is the subset of tools/orm-migration/extract.go's Site type
// Layer 1 needs. It is redeclared here rather than imported because
// tools/orm-migration is a separate Go module (see its own go.mod) with a
// main package, not something iznik-server-go can import; json.Unmarshal
// only needs field tags to line up, which this keeps in sync with by hand.
type manifestSite struct {
	GoldenSQL    string `json:"goldenSql"`
	ApprovedDiff string `json:"approvedDiff,omitempty"`
}

type manifestFile struct {
	Sites map[string]manifestSite `json:"sites"`
}

var (
	manifestOnce sync.Once
	manifestData map[string]manifestSite
	manifestErr  error

	// manifestPathOverride lets tests in this package (golden_test.go) point
	// loadManifest at a temporary manifest instead of walking up to the real
	// repo root, so alias/mismatch/approvedDiff behaviour can be tested
	// without depending on the content of any real site. Unexported: not
	// part of the package's public surface.
	manifestPathOverride string
)

// embeddedManifest is the inventory, compiled into the test binary.
//
// Locating it on disk instead does not work. The apiv2 container mounts
// iznik-server-go as /app, so anything living above iznik-server-go in the
// repo simply does not exist at runtime, and walking upward from the package
// directory can never find it. Embedding removes the question: the manifest
// travels with the code, and the lookup cannot depend on cwd, on the
// container layout, or on where CI happens to check the repo out.
//
//go:embed manifest.json
var embeddedManifest []byte

// loadManifest parses and caches the inventory, preferring a test override
// when one is set.
func loadManifest() (map[string]manifestSite, error) {
	manifestOnce.Do(func() {
		raw := embeddedManifest
		source := "embedded manifest"

		if manifestPathOverride != "" {
			source = manifestPathOverride
			var err error
			raw, err = os.ReadFile(manifestPathOverride)
			if err != nil {
				manifestErr = fmt.Errorf("reading manifest at %s: %w", manifestPathOverride, err)
				return
			}
		}

		var mf manifestFile
		if err := json.Unmarshal(raw, &mf); err != nil {
			manifestErr = fmt.Errorf("parsing %s: %w", source, err)
			return
		}
		manifestData = mf.Sites
	})
	return manifestData, manifestErr
}

// resetManifestCacheForTest clears loadManifest's cache. Only golden_test.go
// (same package) calls this, to point at a fixture manifest per test case.
func resetManifestCacheForTest() {
	manifestOnce = sync.Once{}
	manifestData = nil
	manifestErr = nil
}

// formatMismatch renders a Layer 1 failure: both statements in full, their
// canonical forms, and a word-level diff of the canonical forms so the
// actual point of divergence does not have to be found by eye in a long
// SQL statement.
func formatMismatch(golden, rendered, wantCanon, gotCanon string) string {
	var b strings.Builder
	fmt.Fprintf(&b, "  golden SQL:         %s\n", golden)
	fmt.Fprintf(&b, "  rendered SQL:       %s\n", rendered)
	fmt.Fprintf(&b, "  canonical golden:   %s\n", wantCanon)
	fmt.Fprintf(&b, "  canonical rendered: %s\n", gotCanon)
	b.WriteString(wordDiff(wantCanon, gotCanon))
	return b.String()
}

func wordDiff(a, b string) string {
	ops := diffWords(strings.Fields(a), strings.Fields(b))
	var out strings.Builder
	out.WriteString("  diff (- golden, + rendered):\n")
	for _, op := range ops {
		switch op.kind {
		case diffEqual:
			fmt.Fprintf(&out, "      %s\n", op.text)
		case diffRemove:
			fmt.Fprintf(&out, "    - %s\n", op.text)
		case diffAdd:
			fmt.Fprintf(&out, "    + %s\n", op.text)
		}
	}
	return out.String()
}

type diffOpKind int

const (
	diffEqual diffOpKind = iota
	diffRemove
	diffAdd
)

type diffOp struct {
	kind diffOpKind
	text string
}

// diffWords computes a minimal word-level edit script between a and b via
// classic LCS dynamic programming. The statements involved are short (tens
// of tokens), so the O(n*m) table is negligible; this exists only to make a
// failing parity test fast to read, not for any performance-sensitive path.
func diffWords(a, b []string) []diffOp {
	n, m := len(a), len(b)
	lcs := make([][]int, n+1)
	for i := range lcs {
		lcs[i] = make([]int, m+1)
	}
	for i := n - 1; i >= 0; i-- {
		for j := m - 1; j >= 0; j-- {
			switch {
			case a[i] == b[j]:
				lcs[i][j] = lcs[i+1][j+1] + 1
			case lcs[i+1][j] >= lcs[i][j+1]:
				lcs[i][j] = lcs[i+1][j]
			default:
				lcs[i][j] = lcs[i][j+1]
			}
		}
	}

	var ops []diffOp
	i, j := 0, 0
	for i < n && j < m {
		switch {
		case a[i] == b[j]:
			ops = append(ops, diffOp{diffEqual, a[i]})
			i++
			j++
		case lcs[i+1][j] >= lcs[i][j+1]:
			ops = append(ops, diffOp{diffRemove, a[i]})
			i++
		default:
			ops = append(ops, diffOp{diffAdd, b[j]})
			j++
		}
	}
	for ; i < n; i++ {
		ops = append(ops, diffOp{diffRemove, a[i]})
	}
	for ; j < m; j++ {
		ops = append(ops, diffOp{diffAdd, b[j]})
	}
	return ops
}
