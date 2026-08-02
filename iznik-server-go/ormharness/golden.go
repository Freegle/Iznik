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
	"regexp"
	"sort"
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
// build MUST include a terminal call: Find, Count, Create, Delete, Update,
// Take or First. GORM only finishes assembling a statement when one runs, so
// without it Statement.SQL is empty and there is nothing to compare; dry-run
// mode intercepts the call so no connection is touched.
//
// Scan is NOT usable here: GORM rejects it under dry-run with "dry run mode
// unsupported". Production code can keep using Scan where that reads best;
// only this build function needs a different terminal, and Find renders the
// same SQL for the shapes these sites use.
//
// Note this is the opposite convention to Layer 2's
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

	assertRenderedSQL(t, siteID, "", site.GoldenSQL, site.ApprovedDiff, build)
}

// assertRenderedSQL is the comparison core shared by AssertGoldenSQL and
// AssertGoldenShapes (shapes.go): render build in dry-run mode and check it
// against wantSQL, allowing the same acceptable divergences either caller
// tolerates. label is folded into every message this produces, so a shaped
// site's failure names which shape diverged; AssertGoldenSQL passes "" and
// gets the original unlabelled messages back unchanged.
func assertRenderedSQL(t TestingT, siteID, label, wantSQL, approvedDiff string, build func(tx *gorm.DB) *gorm.DB) {
	t.Helper()

	rendered, vars, err := renderDryRun(build)
	if err != nil {
		t.Fatalf("ormharness: site %q%s: rendering GORM statement: %v", siteID, label, err)
	}

	wantCanon := Canonical(wantSQL)

	// Two renderings are acceptable, and which one applies depends on the
	// golden, not on a guess.
	//
	// GORM always binds LIMIT and OFFSET, so a golden with a literal "LIMIT 1"
	// only matches once the bound value is substituted back in. But a golden
	// whose LIMIT was ALREADY dynamic records the placeholder text "LIMIT ?",
	// and substituting there turns "LIMIT ?" into "LIMIT 5", which no chain
	// could ever match. Resolving unconditionally made those sites impossible
	// to convert.
	//
	// Trying the unresolved form first and the resolved form second covers both
	// without weakening anything: each is an exact comparison against the
	// golden, so a conversion that changed Limit(1) to Limit(50) still fails
	// against a golden of "LIMIT 1".
	gotCanon := Canonical(rendered)
	if gotCanon == wantCanon {
		return
	}

	resolved := Canonical(resolveLimitOffset(rendered, vars))
	if resolved == wantCanon {
		return
	}

	// Third and last acceptable form: an IN list expanded to match the bound
	// slice. GORM renders "id IN (?,?,?)" for a three-element slice, while the
	// golden records whatever the source wrote, which is "IN ?" or "IN (?)".
	//
	// Crucially the OLD statement expands too: db.Raw("... IN ?", slice) goes
	// through the same clause.Expr machinery. So the executed SQL was always
	// the expanded form on both sides, and the mismatch is purely an artefact
	// of the golden being captured from source text before expansion. Comparing
	// the two is comparing a statement to itself at two different stages.
	//
	// The collapse is applied to BOTH sides so it stays symmetric, and it only
	// touches runs of placeholders: a changed column, table or operator still
	// fails, and an IN list of literals is untouched.
	if collapseInLists(wantCanon) == collapseInLists(resolved) ||
		collapseInLists(wantCanon) == collapseInLists(gotCanon) {
		return
	}

	// Fourth acceptable form: column ordering. GORM emits map-valued Create
	// columns and Updates assignments alphabetically, which a handwritten
	// statement rarely matches. normaliseColumnOrder moves each column together
	// with its value, so the mapping is preserved and a mispaired conversion
	// still fails; see its doc comment.
	wantOrdered := normaliseColumnOrder(collapseInLists(wantCanon))
	if wantOrdered == normaliseColumnOrder(collapseInLists(gotCanon)) ||
		wantOrdered == normaliseColumnOrder(collapseInLists(resolved)) {
		return
	}
	// Report against whichever form is closer to the golden, so the failure is
	// about the real difference rather than about placeholder spelling. A
	// golden that kept "limit ?" was dynamic to begin with, so the unresolved
	// render is the fair comparison; otherwise the resolved one is.
	goldenKeptPlaceholder := strings.Contains(wantCanon, "limit ?") || strings.Contains(wantCanon, "offset ?")
	if !goldenKeptPlaceholder {
		rendered = resolveLimitOffset(rendered, vars)
		gotCanon = resolved
	}

	if approvedDiff != "" && Canonical(approvedDiff) == gotCanon {
		return // A reviewer already recorded and justified exactly this divergence.
	}

	verdict := "no approvedDiff is recorded for this site"
	if approvedDiff != "" {
		verdict = "the rendered SQL does not match the recorded approvedDiff either"
	}
	t.Fatalf("ormharness: site %q%s: rendered SQL diverges from goldenSql and %s\n%s",
		siteID, label, verdict, formatMismatch(wantSQL, rendered, wantCanon, gotCanon))
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
	sql, _, err := renderDryRun(build)
	return sql, err
}

// renderDryRun is RenderDryRunSQL plus the bind values, which the golden
// comparison needs in order to resolve LIMIT and OFFSET placeholders.
func renderDryRun(build func(tx *gorm.DB) *gorm.DB) (string, []any, error) {
	db, err := dryRunDB()
	if err != nil {
		return "", nil, fmt.Errorf("constructing dry-run *gorm.DB: %w", err)
	}

	session := db.Session(&gorm.Session{DryRun: true, SkipDefaultTransaction: true})
	tx := build(session)
	if tx == nil {
		return "", nil, fmt.Errorf("build function returned a nil *gorm.DB")
	}
	if tx.Error != nil {
		return "", nil, tx.Error
	}

	sql := tx.Statement.SQL.String()
	if sql == "" {
		// GORM only assembles a statement when a terminal call runs, so an
		// empty string means the build function returned a chain that never
		// finished. Saying so here beats what happens otherwise: the empty
		// string flows onward and MySQL reports a syntax error "near ''",
		// which points nowhere near the actual mistake.
		return "", nil, fmt.Errorf("build function produced no SQL: it must end in a terminal call " +
			"(Find, Count, Create, Delete, Update, Take, First). Note Scan is NOT usable here, " +
			"since GORM rejects it in dry-run mode")
	}
	return sql, tx.Statement.Vars, nil
}

// inListPattern matches an IN or NOT IN whose operand is a run of bind
// placeholders, with or without surrounding parentheses: "in ?", "in (?)",
// "in (?,?,?)". Deliberately narrow - an IN list of literals such as
// "in (1,2,3)" is left alone, because that is real content rather than an
// artefact of how many values happened to be bound.
var inListPattern = regexp.MustCompile(`(?i)\bin\s*(?:\(\s*\?(?:\s*,\s*\?)*\s*\)|\?)`)

// collapseInLists rewrites every placeholder IN list to the single canonical
// form "in ?", so a golden captured before slice expansion compares equal to a
// render captured after it. Applied to both sides of the comparison, never to
// one alone.
func collapseInLists(sql string) string {
	return inListPattern.ReplaceAllString(sql, "in ?")
}

// insertPrefixPattern matches only as far as the table name. The column list
// and the VALUES list are then scanned with a paren matcher rather than a
// regexp, because both routinely contain nested parentheses - "values (now(),
// ?, ?)" and "json_object('chatid', ?, 'userid', ?)" are ordinary here - and a
// character-class like [^()]* silently fails to match those, which is exactly
// how six INSERT conversions slipped past this normalisation.
//
// The table name is matched with [^\s(]+ rather than \S+ for the same reason:
// the codebase writes "insert into logs(timestamp, ...)" with no space, and a
// greedy \S+ swallows the opening paren.
var insertPrefixPattern = regexp.MustCompile(`(?is)^insert(?:\s+ignore)?\s+into\s+[^\s(]+\s*\(`)

// updateSetPattern captures the SET list of an UPDATE, up to WHERE or the end.
var updateSetPattern = regexp.MustCompile(`(?is)^(update\s+.*?\sset\s+)(.*?)(\s+where\s.*|\s*)$`)

// matchParen returns the index of the parenthesis closing the one at open, or
// -1 if unbalanced. Quoted spans are skipped so a paren inside a string literal
// does not affect the depth.
func matchParen(s string, open int) int {
	depth := 0
	inQuote := byte(0)
	for i := open; i < len(s); i++ {
		c := s[i]
		if inQuote != 0 {
			if c == inQuote {
				inQuote = 0
			}
			continue
		}
		switch c {
		case '\'', '"', '`':
			inQuote = c
		case '(':
			depth++
		case ')':
			depth--
			if depth == 0 {
				return i
			}
		}
	}
	return -1
}

// normaliseColumnOrder rewrites an INSERT's column list, and an UPDATE's SET
// list, into a canonical order.
//
// GORM emits the columns of a map-valued Create, and the assignments of a
// map-valued Updates, in ALPHABETICAL key order. A handwritten statement almost
// never uses that order, so the two diverge on ordering alone. That is a large
// systemic category rather than a handful of sites: writing an approved diff
// for each would be pure toil, and would bury the diffs that record something
// real.
//
// This is safe because it moves each column TOGETHER WITH its value, so the
// column-to-value mapping is preserved exactly. A conversion that paired a
// column with the wrong value still produces different pairs and still fails.
// It is applied to both sides of the comparison, never to one alone, and it
// only touches the single-row INSERT and the SET list; anything else, including
// multi-row VALUES and INSERT ... SELECT, is returned unchanged.
func normaliseColumnOrder(sql string) string {
	trimmed := strings.TrimSpace(sql)

	// An upsert has TWO ordered lists, and for a long time this function only
	// normalised one of them. The INSERT branch below rebuilds the column and
	// value lists but copies everything after the closing parenthesis through
	// verbatim, which is where ON DUPLICATE KEY UPDATE lives. GORM sorts the
	// keys of clause.Assignments(map) exactly as it sorts Updates(map), so the
	// upsert tail came out alphabetical while the golden kept the order the
	// original was written in, and every multi-assignment upsert failed on
	// text that described the same statement.
	//
	// Normalising the tail first means the INSERT branch carries the fixed
	// version through in its verbatim copy, and a statement with an upsert tail
	// but no reorderable column list still gets handled.
	trimmed = normaliseDuplicateKeyUpdate(trimmed)

	if loc := insertPrefixPattern.FindStringIndex(trimmed); loc != nil {
		colOpen := loc[1] - 1
		colClose := matchParen(trimmed, colOpen)
		if colClose < 0 {
			return trimmed
		}

		rest := trimmed[colClose+1:]
		lower := strings.ToLower(rest)
		vi := strings.Index(lower, "values")
		if vi < 0 {
			return trimmed // INSERT ... SELECT, or something else: leave alone
		}
		valOpen := strings.Index(rest[vi:], "(")
		if valOpen < 0 {
			return trimmed
		}
		valOpen += vi
		valClose := matchParen(rest, valOpen)
		if valClose < 0 {
			return trimmed
		}
		// A second "(...)" straight after the first means multi-row VALUES,
		// where reordering one row's columns would be meaningless. Leave it.
		if strings.HasPrefix(strings.TrimSpace(rest[valClose+1:]), ",") {
			return trimmed
		}

		cols := splitList(trimmed[colOpen+1 : colClose])
		vals := splitList(rest[valOpen+1 : valClose])
		if len(cols) != len(vals) || len(cols) < 2 {
			return trimmed
		}

		type pair struct{ col, val string }
		pairs := make([]pair, len(cols))
		for i := range cols {
			pairs[i] = pair{cols[i], vals[i]}
		}
		sort.Slice(pairs, func(i, j int) bool { return pairs[i].col < pairs[j].col })

		c := make([]string, len(pairs))
		v := make([]string, len(pairs))
		for i, p := range pairs {
			c[i], v[i] = p.col, p.val
		}
		return trimmed[:colOpen] + "(" + strings.Join(c, ", ") + ") values (" +
			strings.Join(v, ", ") + ")" + rest[valClose+1:]
	}

	if m := updateSetPattern.FindStringSubmatch(trimmed); m != nil {
		assignments := splitList(m[2])
		if len(assignments) > 1 && !setOrderIsLoadBearing(assignments) {
			sort.Strings(assignments)
			return m[1] + strings.Join(assignments, ", ") + m[3]
		}
	}
	return trimmed
}

// normaliseDuplicateKeyUpdate sorts the assignment list of an
// ON DUPLICATE KEY UPDATE clause, moving each column with its value, so that
// GORM's alphabetical ordering can be compared against a golden that kept the
// order its author wrote.
//
// It declines for exactly the same reason the UPDATE branch declines: MySQL
// evaluates these assignments left to right too, so where one reads a column
// another assigns, the order is part of what the statement means. abtest.go is
// the live case - "shown = shown + 1, rate = COALESCE(100 * action / shown, 0)"
// computes rate from the incremented value, and alphabetical order would
// reverse that. Those sites use an explicit ordered clause.Set rather than a
// map, which is why they render in the original order and do not need this.
func normaliseDuplicateKeyUpdate(sql string) string {
	const marker = "on duplicate key update"
	lower := strings.ToLower(sql)
	i := strings.Index(lower, marker)
	if i < 0 {
		return sql
	}

	head, tail := sql[:i+len(marker)], sql[i+len(marker):]
	assignments := splitList(tail)
	if len(assignments) < 2 || setOrderIsLoadBearing(assignments) {
		return sql
	}

	trimmedAssignments := make([]string, len(assignments))
	for j, a := range assignments {
		trimmedAssignments[j] = strings.TrimSpace(a)
	}
	sort.Strings(trimmedAssignments)
	return head + " " + strings.Join(trimmedAssignments, ", ")
}

// setOrderIsLoadBearing reports whether an UPDATE's SET list means something
// different when reordered.
//
// MySQL evaluates assignments left to right, so
//
//	UPDATE t SET action = action + 1, rate = 100 * action / shown
//
// computes rate from the INCREMENTED action, while the reverse order computes
// it from the old one. GORM sorts map keys when building Updates(map[...]),
// which reorders the SET list, so a conversion can quietly change the answer.
//
// That matters here specifically because normaliseColumnOrder sorts SET lists
// on both sides in order to accept harmless reordering - which means that
// without this guard it would accept harmful reordering just as readily. A
// normalisation that hides a class of bug has to know where that class starts.
// Where the order carries meaning, we decline to normalise and let the exact
// text be the thing the golden pins.
//
// Only a cross-reference counts. "action = action + 1" refers to itself, which
// is order-independent; it is one assignment reading ANOTHER assignment's
// column that makes position significant.
func setOrderIsLoadBearing(assignments []string) bool {
	cols := make([]string, 0, len(assignments))
	rhs := make([]string, 0, len(assignments))
	for _, a := range assignments {
		eq := strings.Index(a, "=")
		if eq < 0 {
			return true // cannot parse it, so cannot prove reordering is safe
		}
		col := strings.TrimSpace(a[:eq])
		col = strings.Trim(col, "`")
		if i := strings.LastIndex(col, "."); i >= 0 {
			col = col[i+1:]
		}
		cols = append(cols, col)
		rhs = append(rhs, stripSQLStrings(a[eq+1:]))
	}

	for i, expr := range rhs {
		for j, col := range cols {
			if i == j || col == "" {
				continue
			}
			if regexp.MustCompile(`(?i)\b` + regexp.QuoteMeta(col) + `\b`).MatchString(expr) {
				return true
			}
		}
	}
	return false
}

// stripSQLStrings blanks out single-quoted literals so that a column name
// appearing inside one ("note = 'check the rate'") is not mistaken for a
// reference to that column.
func stripSQLStrings(s string) string {
	var b strings.Builder
	inQuote := false
	for i := 0; i < len(s); i++ {
		switch {
		case s[i] == '\\' && inQuote && i+1 < len(s):
			i++
		case s[i] == '\'':
			inQuote = !inQuote
		case !inQuote:
			b.WriteByte(s[i])
		}
	}
	return b.String()
}

// splitList splits a comma-separated SQL fragment at top level, so that commas
// inside a function call such as COALESCE(a, b) do not split it.
func splitList(s string) []string {
	var out []string
	depth, start := 0, 0
	inQuote := byte(0)

	for i := 0; i < len(s); i++ {
		c := s[i]
		if inQuote != 0 {
			if c == inQuote {
				inQuote = 0
			}
			continue
		}
		switch c {
		case '\'', '"', '`':
			inQuote = c
		case '(':
			depth++
		case ')':
			depth--
		case ',':
			if depth == 0 {
				out = append(out, strings.TrimSpace(s[start:i]))
				start = i + 1
			}
		}
	}
	out = append(out, strings.TrimSpace(s[start:]))
	return out
}

// resolveLimitOffset puts the bound LIMIT and OFFSET values back into the
// rendered SQL.
//
// GORM always binds these (clause/limit.go calls AddVar), so a handwritten
// "LIMIT 1" renders as "LIMIT ?" and diverges from its golden. That is a
// purely mechanical difference affecting a large share of sites, and writing
// an approved diff for each would be pure toil.
//
// Substituting the actual value is not merely less work, it is stricter than
// the alternatives. A blanket canonicaliser rule treating "limit N" and
// "limit ?" as equal would let a conversion that changed Limit(1) to Limit(50)
// pass unnoticed; resolving the value catches exactly that. Only LIMIT and
// OFFSET placeholders are resolved: every other bind legitimately stays "?" in
// the golden, because that is how the original was written.
func resolveLimitOffset(sql string, vars []any) string {
	if len(vars) == 0 || !strings.Contains(sql, "?") {
		return sql
	}

	var out strings.Builder
	varIdx := 0
	inQuote := byte(0)

	for i := 0; i < len(sql); i++ {
		c := sql[i]

		// Track quoted spans so a "?" inside a string literal is neither
		// counted as a bind nor rewritten.
		if inQuote != 0 {
			out.WriteByte(c)
			if c == inQuote {
				inQuote = 0
			}
			continue
		}
		if c == '\'' || c == '"' || c == '`' {
			inQuote = c
			out.WriteByte(c)
			continue
		}
		if c != '?' {
			out.WriteByte(c)
			continue
		}

		// A bind placeholder. Decide whether it is the operand of LIMIT or
		// OFFSET by looking at the preceding keyword.
		if varIdx < len(vars) && precededByLimitOrOffset(sql[:i]) {
			out.WriteString(fmt.Sprintf("%v", vars[varIdx]))
		} else {
			out.WriteByte('?')
		}
		varIdx++
	}
	return out.String()
}

// precededByLimitOrOffset reports whether the text immediately before a
// placeholder ends in the LIMIT or OFFSET keyword. MySQL also accepts
// "LIMIT offset, count", so a comma is stepped over too.
func precededByLimitOrOffset(before string) bool {
	trimmed := strings.TrimRight(before, " \t\n\r")
	trimmed = strings.TrimRight(trimmed, ",")
	trimmed = strings.TrimRight(trimmed, " \t\n\r")

	// A "LIMIT ?, ?" pair: the second placeholder follows a resolved number.
	if end := strings.LastIndexAny(trimmed, " \t\n\r"); end >= 0 {
		word := trimmed[end+1:]
		if isAllDigits(word) {
			trimmed = strings.TrimRight(trimmed[:end], " \t\n\r")
		}
	}

	upper := strings.ToUpper(trimmed)
	return strings.HasSuffix(upper, "LIMIT") || strings.HasSuffix(upper, "OFFSET")
}

func isAllDigits(s string) bool {
	if s == "" {
		return false
	}
	for i := 0; i < len(s); i++ {
		if s[i] < '0' || s[i] > '9' {
			return false
		}
	}
	return true
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
	GoldenSQL    string   `json:"goldenSql"`
	ApprovedDiff string   `json:"approvedDiff,omitempty"`
	Status       string   `json:"status"`
	File         string   `json:"file"`
	Line         int      `json:"line"`
	Function     string   `json:"function"`
	Kind         string   `json:"kind"`
	Tables       []string `json:"tables"`
}

// ConvertedSite is one entry from the manifest, exported for the executed-SQL
// check in the test package. That check is the answer to a question Layer 1
// cannot address: Layer 1 renders a chain written in a TEST and compares it to
// the golden, so it proves "a chain of this shape renders the old SQL", not
// "the chain in production does". If a conversion and its test drift apart,
// Layer 1 still passes.
type ConvertedSite struct {
	ID        string
	GoldenSQL string
	File      string
	Line      int
	Function  string
	Kind      string
	Tables    []string
}

// NormaliseForComparison applies the same shape-only normalisations Layer 1
// uses when deciding whether two statements are the same statement: IN-list
// expansion and column ordering. Exported so the executed-SQL check compares
// on exactly the same terms as the golden check, rather than inventing a
// second, subtly different notion of equality.
func NormaliseForComparison(canonical string) string {
	return normaliseColumnOrder(collapseInLists(canonical))
}

// AllGoldens returns the normalised golden of every site in the manifest,
// whatever its status, mapped to that status. The executed-SQL check uses it to
// separate two very different reasons a converted site's golden might not be
// observed running: the code path was never exercised, or it is now executing
// something else. A statement that matches no golden at all is the second kind
// of evidence.
func AllGoldens() (map[string]string, error) {
	sites, err := loadManifest()
	if err != nil {
		return nil, err
	}
	out := make(map[string]string, len(sites))
	for _, s := range sites {
		if s.GoldenSQL == "" {
			continue
		}
		out[NormaliseForComparison(Canonical(s.GoldenSQL))] = s.Status
	}
	return out, nil
}

// ConvertedSites returns every site the manifest records as converted.
func ConvertedSites() ([]ConvertedSite, error) {
	sites, err := loadManifest()
	if err != nil {
		return nil, err
	}
	out := make([]ConvertedSite, 0, len(sites))
	for id, s := range sites {
		if s.Status != "converted" || s.GoldenSQL == "" {
			continue
		}
		out = append(out, ConvertedSite{ID: id, GoldenSQL: s.GoldenSQL, File: s.File, Line: s.Line,
			Function: s.Function, Kind: s.Kind, Tables: s.Tables})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].ID < out[j].ID })
	return out, nil
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
