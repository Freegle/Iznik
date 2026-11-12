// Package parity is the test harness that proves a raw SQL site can change without changing what
// it does. It has two layers.
//
// Layer 1, golden SQL parity (golden.go): the SQL a site renders after conversion is the SQL the
// original statement had. The original text is the site's goldenSql in the raw SQL manifest
// (tools/orm-migration/manifest.json), copied into goldens.json beside this file by
// `node tools/orm-migration/extract.mjs update`. The test renders the converted GORM chain
// without a database, canonicalises both sides and compares them. It needs no database.
//
// Layer 2, fixture result parity (resultparity.go): the same call gives the same rows on MySQL and
// on PostgreSQL. The test loads a small fixture into both engines, runs the site's function on each,
// and compares what came back. It needs the engines and skips when they are absent.
//
// docs/developers/reference/parity-harness.md says how to add a site.
package parity

import (
	"fmt"
	"strings"
	"sync"

	"github.com/freegle/iznik-server-go/database"
	"gorm.io/driver/mysql"
	"gorm.io/driver/postgres"
	"gorm.io/gorm"
)

// TestingT is the part of *testing.T the harness needs. Taking an interface lets the harness's own
// tests assert that a bad conversion fails: a failing subtest fails its parent, so an expected
// failure needs a recorder instead.
type TestingT interface {
	Helper()
	Fatalf(format string, args ...any)
}

// Dialect names an engine's SQL.
type Dialect string

const (
	MySQL    Dialect = "mysql"
	Postgres Dialect = "postgres"
)

// Build assembles a GORM statement from a session handle. For Layer 1 it must end in a terminal
// call that GORM can dry-run (Find, First, Take, Count, Pluck, Create, Update, Updates, Delete).
// Scan is not usable: GORM refuses it in dry-run mode.
type Build func(tx *gorm.DB) *gorm.DB

// AssertGoldenSQL renders build without executing it and fails t unless the result is the site's
// goldenSql once both are canonicalised, or matches the site's approvedDiff when a reviewer has
// recorded one.
//
//	parity.AssertGoldenSQL(t, "a1b2c3d4e5f6", func(tx *gorm.DB) *gorm.DB {
//		return myGroupIDsQuery(tx, 5).Find(&ids)
//	})
//
// Use the same function the production code calls, so the test proves the code that runs and not a
// copy of it.
func AssertGoldenSQL(t TestingT, siteID string, build Build) {
	t.Helper()

	site, err := LookupSite(siteID)
	if err != nil {
		t.Fatalf("parity: %v", err)
	}
	if site.GoldenSQL == "" {
		t.Fatalf("parity: site %q has no goldenSql to compare against", siteID)
	}

	rendered, vars, err := renderDryRun(MySQL, build)
	if err != nil {
		t.Fatalf("parity: site %q: rendering the GORM statement: %v", siteID, err)
	}

	// Count the binds before comparing any text. A chain can render the right SQL and still carry
	// the wrong number of arguments, and the text comparison is blind to that: the placeholders
	// are identical either way. It only shows up as "expected 13 arguments, got 14" at run time.
	if want, got := strings.Count(rendered, "?"), len(vars); want != got {
		t.Fatalf("parity: site %q: the statement has %d placeholder(s) but %d bind argument(s) were supplied.\n"+
			"The SQL text can match the golden exactly while this is wrong, so it is checked on its own.\n"+
			"Look for a value passed to Select, Joins or Where that the expression has no \"?\" for, or one bound twice.\n"+
			"rendered: %s", siteID, want, got, rendered)
	}

	wantCanon := Canonical(site.GoldenSQL)
	gotCanon := Canonical(rendered)
	if gotCanon == wantCanon {
		return
	}

	// GORM always binds LIMIT and OFFSET, so a golden with a literal "LIMIT 1" only matches once
	// the bound value is put back. A golden whose LIMIT was dynamic records "LIMIT ?", which the
	// unresolved render matches. Each form is an exact comparison, so a chain that changed
	// Limit(1) to Limit(50) still fails.
	resolved := Canonical(resolveLimitOffset(rendered, vars))
	if resolved == wantCanon {
		return
	}

	// An IN list expanded to the bound slice: the golden is captured from source text before
	// expansion, and the old statement expanded the same way when it ran.
	if collapseInLists(wantCanon) == collapseInLists(resolved) || collapseInLists(wantCanon) == collapseInLists(gotCanon) {
		return
	}

	// Column order of a map-valued Create or Updates, which GORM sorts alphabetically.
	wantOrdered := normaliseColumnOrder(collapseInLists(wantCanon))
	if wantOrdered == normaliseColumnOrder(collapseInLists(gotCanon)) || wantOrdered == normaliseColumnOrder(collapseInLists(resolved)) {
		return
	}

	// Report against the form closest to the golden, so the failure is about the real difference
	// and not about placeholder spelling.
	if !strings.Contains(wantCanon, "limit ?") && !strings.Contains(wantCanon, "offset ?") {
		rendered = resolveLimitOffset(rendered, vars)
		gotCanon = resolved
	}

	// The approved diff gets the same normalisations as the golden, so a reviewer records a
	// decision and not the number of values a test happens to bind.
	if site.ApprovedDiff != nil && site.ApprovedDiff.SQL != "" {
		diffCanon := Canonical(site.ApprovedDiff.SQL)
		if diffCanon == gotCanon ||
			collapseInLists(diffCanon) == collapseInLists(gotCanon) ||
			normaliseColumnOrder(collapseInLists(diffCanon)) == normaliseColumnOrder(collapseInLists(gotCanon)) {
			return
		}
	}

	verdict := "no approvedDiff is recorded for this site"
	if site.ApprovedDiff != nil {
		verdict = "the rendered SQL does not match the recorded approvedDiff either"
	}
	t.Fatalf("parity: site %q: rendered SQL diverges from goldenSql and %s\n%s",
		siteID, verdict, formatMismatch(site.GoldenSQL, rendered, wantCanon, gotCanon))
}

// AssertDialectSQL renders build under dialect d and fails t unless the result is want once both
// are canonicalised. It is how a site that has a PostgreSQL form pins that form, so a later change
// to the chain that breaks one engine is caught without that engine running. PostgreSQL numbers its
// placeholders $1, $2; they are read as "?" so want can be written the same way as a MySQL golden.
func AssertDialectSQL(t TestingT, d Dialect, want string, build Build) {
	t.Helper()

	rendered, vars, err := renderDryRun(d, build)
	if err != nil {
		t.Fatalf("parity: rendering the %s statement: %v", d, err)
	}
	if got := strings.Count(rendered, "?"); got != len(vars) {
		t.Fatalf("parity: the %s statement has %d placeholder(s) but %d bind argument(s): %s", d, got, len(vars), rendered)
	}

	wantCanon := normaliseColumnOrder(collapseInLists(Canonical(want)))
	gotCanon := normaliseColumnOrder(collapseInLists(Canonical(rendered)))
	if wantCanon != gotCanon {
		t.Fatalf("parity: the %s statement diverges from the expected SQL\n%s", d, formatMismatch(want, rendered, wantCanon, gotCanon))
	}
}

// RenderDryRunSQL builds a statement and returns the SQL GORM would send under dialect d, with the
// bind values left as "?" placeholders. It opens no connection.
func RenderDryRunSQL(d Dialect, build Build) (string, error) {
	sql, _, err := renderDryRun(d, build)
	return sql, err
}

// renderDryRun is RenderDryRunSQL plus the bind values.
func renderDryRun(d Dialect, build Build) (string, []any, error) {
	db, err := dryRunDB(d)
	if err != nil {
		return "", nil, fmt.Errorf("constructing the dry-run handle: %w", err)
	}

	session := db.Session(&gorm.Session{DryRun: true, SkipDefaultTransaction: true})
	tx := build(session)
	if tx == nil {
		return "", nil, fmt.Errorf("the build function returned a nil *gorm.DB")
	}
	if tx.Error != nil {
		return "", nil, tx.Error
	}

	sql := tx.Statement.SQL.String()
	if sql == "" {
		// GORM assembles a statement only when a terminal call runs. An empty string means the
		// chain never finished, and saying so here beats a syntax error "near ''" later.
		return "", nil, fmt.Errorf("the build function produced no SQL: it must end in a terminal call " +
			"(Find, First, Take, Count, Pluck, Create, Update, Updates, Delete); Scan is refused in dry-run mode")
	}
	if d == Postgres {
		sql = pgPlaceholders.ReplaceAllString(sql, "?")
	}
	return sql, tx.Statement.Vars, nil
}

var (
	dryRunMu   sync.Mutex
	dryRunDBs  = map[Dialect]*gorm.DB{}
	dryRunErrs = map[Dialect]error{}
)

// dryRunDB returns a handle that has never connected. gorm.Open reaches the network only to ping
// or to ask a MySQL server for its version, and both are switched off. database/sql.Open is lazy
// for both drivers.
func dryRunDB(d Dialect) (*gorm.DB, error) {
	dryRunMu.Lock()
	defer dryRunMu.Unlock()
	if db, ok := dryRunDBs[d]; ok || dryRunErrs[d] != nil {
		return db, dryRunErrs[d]
	}

	var db *gorm.DB
	var err error
	switch d {
	case MySQL:
		db, err = gorm.Open(mysql.New(mysql.Config{
			DriverName:                "mysql",
			DSN:                       "parity:parity@tcp(127.0.0.1:3306)/parity?parseTime=true&loc=Local",
			SkipInitializeWithVersion: true,
		}), &gorm.Config{DisableAutomaticPing: true})
		if err == nil {
			// Production registers the same overrides on its real handle, so Layer 1 renders what
			// production would send for REPLACE INTO and INSERT ... SELECT.
			database.RegisterCustomClauseBuilders(db)
		}
	case Postgres:
		db, err = gorm.Open(postgres.New(postgres.Config{
			DSN: "host=127.0.0.1 port=5432 user=parity password=parity dbname=parity sslmode=disable",
		}), &gorm.Config{DisableAutomaticPing: true})
	default:
		err = fmt.Errorf("unknown dialect %q", d)
	}

	dryRunDBs[d], dryRunErrs[d] = db, err
	return db, err
}

// formatMismatch renders a Layer 1 failure: both statements in full, their canonical forms and a
// word-level diff, so the point of divergence does not have to be found by eye.
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

// diffWords computes a minimal word-level edit script between a and b by longest common
// subsequence. The statements are short, so the quadratic table is negligible.
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
