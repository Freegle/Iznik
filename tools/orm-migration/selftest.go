package main

import (
	"fmt"
	"go/token"
	"os"
	"path/filepath"
	"strings"
)

// The extractor is the contract the whole migration rests on: a site it fails
// to see is a site the CI ratchet cannot protect. These cases pin the
// behaviours that were actually got wrong during development, so a regression
// shows up as a named failure rather than a quietly smaller manifest.
//
// The same table runs two ways: `go run . -selftest` locally (the repo's hooks
// block `go test` on the host) and TestSelfCheck in CI.

type selfCase struct {
	name string
	src  string

	wantSites int
	// Assertions applied to the first site found, when wantSites > 0.
	wantSQLPrefix string
	wantSource    string
	wantKind      string
	wantTables    []string
	wantMySQLisms []string
	wantComplex   string
}

var selfCases = []selfCase{
	{
		name: "simple select is captured with its tables",
		src: `package p
func f() {
	db.Raw("SELECT id, name FROM users WHERE id = ?", 1).Scan(&u)
}`,
		wantSites:     1,
		wantSQLPrefix: "SELECT id, name FROM users",
		wantSource:    "literal",
		wantKind:      "SELECT",
		wantTables:    []string{"users"},
		wantComplex:   "simple",
	},
	{
		name: "multi-line concatenation is folded into one statement",
		src: `package p
func f() {
	db.Raw("" +
		"SELECT a " +
		"FROM messages " +
		"WHERE id = ?", 1).Scan(&m)
}`,
		wantSites:     1,
		wantSQLPrefix: "SELECT a FROM messages WHERE id = ?",
		wantSource:    "literal",
		wantKind:      "SELECT",
		wantTables:    []string{"messages"},
	},
	{
		name:          "backtick raw string literal is folded",
		src:           "package p\nfunc f() {\n\tdb.Exec(`UPDATE users SET x = 1 WHERE id = ?`, 2)\n}",
		wantSites:     1,
		wantSQLPrefix: "UPDATE users SET x = 1",
		wantKind:      "UPDATE",
		wantTables:    []string{"users"},
	},
	{
		// Regression: the newsfeed queries are unions of parenthesised
		// subqueries and were silently dropped by requiring a bare verb at
		// position zero.
		name: "statement starting with an open paren is captured",
		src: `package p
func f() {
	db.Raw(fmt.Sprintf("(SELECT id FROM newsfeed FORCE INDEX (position) WHERE x = ?) UNION (SELECT id FROM alerts)", a)).Scan(&n)
}`,
		wantSites:     1,
		wantSource:    "literal",
		wantKind:      "SELECT",
		wantMySQLisms: []string{"force-index"},
		wantComplex:   "complex",
	},
	{
		// Regression: db.Raw(baseQuery + " GROUP BY ...") begins with a hole,
		// so no verb appears first, but it is unambiguously a SQL site.
		name: "fragment beginning with a variable is captured as partial",
		src: `package p
func f() {
	db.Raw(baseQuery+" GROUP BY cm.id ORDER BY cm.id ASC LIMIT ?", limit).Scan(&msgs)
}`,
		wantSites:   1,
		wantSource:  "partial",
		wantKind:    "DYNAMIC",
		wantComplex: "complex",
	},
	{
		// Regression: SQL handed in wholesale by the dynamic query builders.
		name: "SQL held entirely in a variable is captured",
		src: `package p
func f() {
	db.Raw(query, args...).Scan(&rows)
}`,
		wantSites:   1,
		wantSource:  "variable",
		wantKind:    "DYNAMIC",
		wantComplex: "complex",
	},
	{
		// Regression: fiber's HTTP query-parameter getter shares the name
		// Query with database/sql and would otherwise add 300+ non-SQL rows.
		name: "fiber c.Query is not a SQL site",
		src: `package p
func f() {
	uid := c.Query("uid")
	_ = uid
}`,
		wantSites: 0,
	},
	{
		name: "template and os/exec calls are not SQL sites",
		src: `package p
func f() {
	tmpl.Exec("some template text")
	cmd.Exec("ls -l")
}`,
		wantSites: 0,
	},
	{
		name: "SQL mentioned only in a comment is not a site",
		src: `package p
func f() {
	// returns the full SQL ready for db.Raw("SELECT * FROM users")
	x := 1
	_ = x
}`,
		wantSites: 0,
	},
	{
		name: "upsert is flagged for the wave 3 portable wrapper",
		src: `package p
func f() {
	db.Exec("INSERT INTO users_emails (userid, email) VALUES (?, ?) ON DUPLICATE KEY UPDATE email = ?", a, b, c)
}`,
		wantSites:     1,
		wantKind:      "INSERT",
		wantMySQLisms: []string{"on-duplicate-key"},
		wantComplex:   "complex",
	},
	{
		// Regression: a trailing semicolon is a statement separator, not part
		// of the statement. GORM never emits one, so recording it made 30
		// sites unconvertible without an approved diff apiece.
		name: "trailing semicolon is not part of the golden",
		src: `package p
func f() {
	db.Raw("SELECT id FROM users WHERE id = ?;", 1).Scan(&u)
}`,
		wantSites:     1,
		wantSQLPrefix: "SELECT id FROM users WHERE id = ?",
		wantKind:      "SELECT",
		wantTables:    []string{"users"},
	},
	{
		name: "spatial function forces complex",
		src: `package p
func f() {
	db.Raw("SELECT ST_Y(point) AS lat FROM messages_spatial WHERE id = ?", 1).Scan(&m)
}`,
		wantSites:     1,
		wantKind:      "SELECT",
		wantMySQLisms: []string{"spatial"},
		wantComplex:   "complex",
	},
	{
		name: "join makes a select moderate rather than simple",
		src: `package p
func f() {
	db.Raw("SELECT u.id FROM users u INNER JOIN memberships m ON m.userid = u.id WHERE m.groupid = ?", 1).Scan(&r)
}`,
		wantSites:   1,
		wantKind:    "SELECT",
		wantComplex: "moderate",
		wantTables:  []string{"memberships", "users"},
	},
}

// runSelfCheck executes the table and returns one message per failure.
func runSelfCheck() []string {
	var failures []string

	fail := func(name, format string, args ...any) {
		failures = append(failures, name+": "+fmt.Sprintf(format, args...))
	}

	for _, tc := range selfCases {
		fset := token.NewFileSet()
		got, err := sitesInFile(fset, "selftest.go", "selftest.go", tc.src, false, nil)
		if err != nil {
			fail(tc.name, "parse error: %v", err)
			continue
		}
		if len(got) != tc.wantSites {
			fail(tc.name, "found %d sites, want %d", len(got), tc.wantSites)
			continue
		}
		if tc.wantSites == 0 {
			continue
		}

		s := got[0]
		if tc.wantSQLPrefix != "" && !strings.HasPrefix(s.GoldenSQL, tc.wantSQLPrefix) {
			fail(tc.name, "goldenSql = %q, want prefix %q", s.GoldenSQL, tc.wantSQLPrefix)
		}
		if tc.wantSource != "" && s.SQLSource != tc.wantSource {
			fail(tc.name, "sqlSource = %q, want %q", s.SQLSource, tc.wantSource)
		}
		if tc.wantKind != "" && s.Kind != tc.wantKind {
			fail(tc.name, "kind = %q, want %q", s.Kind, tc.wantKind)
		}
		if tc.wantComplex != "" && s.Complexity != tc.wantComplex {
			fail(tc.name, "complexity = %q, want %q", s.Complexity, tc.wantComplex)
		}
		if tc.wantTables != nil && strings.Join(s.Tables, ",") != strings.Join(tc.wantTables, ",") {
			fail(tc.name, "tables = %v, want %v", s.Tables, tc.wantTables)
		}
		for _, want := range tc.wantMySQLisms {
			if !contains(s.MySQLisms, want) {
				fail(tc.name, "mysqlisms = %v, want to contain %q", s.MySQLisms, want)
			}
		}
		if s.ID == "" {
			fail(tc.name, "site has no stable ID")
		}
	}

	// The ID must be stable across runs, or the ratchet would flag every site
	// as new on each regeneration.
	fset := token.NewFileSet()
	src := "package p\nfunc f() { db.Raw(\"SELECT 1 FROM dual\") }"
	a, _ := sitesInFile(fset, "x.go", "x.go", src, false, nil)
	b, _ := sitesInFile(token.NewFileSet(), "x.go", "x.go", src, false, nil)
	if len(a) == 1 && len(b) == 1 && a[0].ID != b[0].ID {
		fail("stable id", "ID differs between runs: %s vs %s", a[0].ID, b[0].ID)
	}

	// Gate 2 must not accept a comment as proof. A note explaining why a site
	// was NOT converted mentions its ID, and matching IDs anywhere in the file
	// counted that as the parity test asserting it WAS - exactly backwards.
	if dir, err := os.MkdirTemp("", "ormselftest"); err == nil {
		defer os.RemoveAll(dir)
		src := "package test\n" +
			"// deliberately not converted: 0123456789ab (see the plan)\n" +
			"func TestX(t *testing.T) {\n" +
			"\tormharness.AssertGoldenSQL(t, \"abcdef012345\", nil)\n" +
			"}\n"
		if err := os.WriteFile(filepath.Join(dir, "x_test.go"), []byte(src), 0o644); err == nil {
			tested, err := parityTestedIDs(dir)
			switch {
			case err != nil:
				fail("gate 2 proof", "parityTestedIDs: %v", err)
			case tested["0123456789ab"]:
				fail("gate 2 proof", "a site ID mentioned only in a comment was counted as having a parity test")
			case !tested["abcdef012345"]:
				fail("gate 2 proof", "a site ID passed to AssertGoldenSQL was not counted")
			}
		}
	}

	// A test may reach an assertion through a local helper, and that is still a
	// parity test. message_list_tier9_test.go does exactly this: it builds the
	// parametrized cases in assertUnionAllSiteShape(t, siteID, ...) and forwards
	// to ormharness.AssertGoldenParametrizedShape. Scanning only for literals at
	// the assertion call site sees the parameter, not the ID, so the site read
	// as unproven and gate (f) reported deleted-without-proof.
	//
	// The second half is the part that matters: a helper that COMPUTES the ID it
	// forwards must not vouch for the literal at the call site, because the two
	// are then not the same string and the test does not assert what the caller
	// appears to say it does.
	if dir, err := os.MkdirTemp("", "ormselftest"); err == nil {
		defer os.RemoveAll(dir)
		src := "package test\n" +
			"func viaHelper(t *testing.T, siteID string) {\n" +
			"\tormharness.AssertGoldenParametrizedShape(t, siteID, nil, nil, nil)\n" +
			"}\n" +
			"func viaComputed(t *testing.T, prefix string) {\n" +
			"\tormharness.AssertGoldenSQL(t, prefix+\"suffix\", nil)\n" +
			"}\n" +
			"func TestX(t *testing.T) {\n" +
			"\tviaHelper(t, \"aaaaaaaaaaaa\")\n" +
			"\tviaComputed(t, \"bbbbbbbbbbbb\")\n" +
			"}\n"
		if err := os.WriteFile(filepath.Join(dir, "h_test.go"), []byte(src), 0o644); err == nil {
			tested, err := parityTestedIDs(dir)
			switch {
			case err != nil:
				fail("forwarding helper", "parityTestedIDs: %v", err)
			case !tested["aaaaaaaaaaaa"]:
				fail("forwarding helper", "an ID passed through a local helper to an assertion was not counted")
			case tested["bbbbbbbbbbbb"]:
				fail("forwarding helper", "an ID the helper only concatenated into the real one was counted as asserted")
			}
		}
	}

	// Two identical statements in one file must not collide onto one ID.
	dup := "package p\nfunc f() { db.Raw(\"SELECT 1 FROM dual\"); db.Raw(\"SELECT 1 FROM dual\") }"
	d, _ := sitesInFile(token.NewFileSet(), "x.go", "x.go", dup, false, nil)
	if len(d) == 2 && d[0].ID == d[1].ID {
		fail("duplicate sql", "two sites in one file share ID %s", d[0].ID)
	}

	// A package-level string constant must fold into the golden, so a statement
	// that is static in fact is not written off as dynamic because of how this
	// tool reads it. And a value it cannot resolve must still mark the site
	// dynamic, since such a statement genuinely has more than one form.
	constSrc := "package p\nfunc f() { db.Raw(\"SELECT \" + cols + \" FROM t\") }"
	c, _ := sitesInFile(token.NewFileSet(), "c.go", "c.go", constSrc, false,
		map[string]string{"cols": "a, b"})
	if len(c) != 1 {
		fail("const fold", "expected 1 site, got %d", len(c))
	} else {
		if c[0].GoldenSQL != "SELECT a, b FROM t" {
			fail("const fold", "constant not resolved: %q", c[0].GoldenSQL)
		}
		if c[0].Dynamic {
			fail("const fold", "site marked dynamic despite a fully resolved statement")
		}
	}

	u, _ := sitesInFile(token.NewFileSet(), "u.go", "u.go", constSrc, false, nil)
	if len(u) == 1 && !u[0].Dynamic {
		fail("const fold", "an unresolvable fragment must still mark the site dynamic, got %q", u[0].GoldenSQL)
	}

	// Setting Statement.BuildClauses = {"SELECT"} suppresses the FROM clause
	// GORM always registers. That makes .Select() able to carry a whole
	// statement with .Table() as decoration - and .Select() is not a method this
	// scan otherwise looks at, so five sites silently stopped counting as raw
	// and started counting as converted with the SQL text unchanged.
	//
	// Both halves matter and both are pinned here, because the two uses look
	// almost identical and only one is smuggling: a top-level FROM in the
	// argument means the argument IS the statement, whereas a FROM inside a
	// scalar subquery ("EXISTS(SELECT 1 FROM users ...)") is an ordinary
	// expression that renders byte-identically to something GORM otherwise
	// cannot express. Getting the second one wrong would condemn 18 legitimate
	// conversions; getting the first wrong loses the inventory's exhaustiveness.
	smuggleSrc := "package p\n" +
		"func f() {\n" +
		"\ttx := db.Table(\"chat_rooms\").Select(\"id FROM chat_rooms WHERE id = ? UNION SELECT id FROM x\")\n" +
		"\ttx.Statement.BuildClauses = []string{\"SELECT\"}\n" +
		"}\n"
	sm, _ := sitesInFile(token.NewFileSet(), "s.go", "s.go", smuggleSrc, false, nil)
	if len(sm) != 1 {
		fail("buildclauses", "a whole statement passed through Select() under a BuildClauses override was not inventoried (got %d sites)", len(sm))
	}

	scalarSrc := "package p\n" +
		"func f() {\n" +
		"\ttx := db.Table(\"users\").Select(\"EXISTS(SELECT 1 FROM users WHERE id = ?)\", id)\n" +
		"\ttx.Statement.BuildClauses = []string{\"SELECT\"}\n" +
		"}\n"
	sc, _ := sitesInFile(token.NewFileSet(), "sc.go", "sc.go", scalarSrc, false, nil)
	if len(sc) != 0 {
		fail("buildclauses", "a scalar-expression Select (no top-level FROM) was wrongly inventoried as a smuggled statement: %q", sc[0].GoldenSQL)
	}

	// Without the override, .Select() is an ordinary projection and must never
	// be inventoried, whatever it contains - otherwise every Select in the
	// codebase becomes a raw SQL site.
	noOverrideSrc := "package p\n" +
		"func f() { db.Table(\"users\").Select(\"id FROM users WHERE id = ?\") }\n"
	no, _ := sitesInFile(token.NewFileSet(), "n.go", "n.go", noOverrideSrc, false, nil)
	if len(no) != 0 {
		fail("buildclauses", "a Select() with no BuildClauses override was inventoried as SQL")
	}

	// Fiber's c.Query("start", "") reads a URL parameter. "start" is the first
	// word of START TRANSACTION, so without a receiver check it was inventoried
	// as SQL - fourteen times, and two agents were about to record those
	// phantoms as deliberate keep-raw decisions.
	fiberSrc := "package p\nfunc f() { c.Query(\"start\", \"\") }"
	fq, _ := sitesInFile(token.NewFileSet(), "f.go", "f.go", fiberSrc, false, nil)
	if len(fq) != 0 {
		fail("fiber query", "c.Query(\"start\") was inventoried as SQL: %q", fq[0].GoldenSQL)
	}

	// The opposite error is worse. Requiring a recognised receiver for EVERY
	// verb dropped three real DDL sites in authority/stats.go, where the
	// receiver is writer() - a closure returning the pinned transaction. An
	// unambiguous verb must be inventoried whatever the receiver looks like.
	writerSrc := "package p\nfunc f() { writer().Exec(\"DROP TEMPORARY TABLE IF EXISTS pc\") }"
	wq, _ := sitesInFile(token.NewFileSet(), "w.go", "w.go", writerSrc, false, nil)
	if len(wq) != 1 {
		fail("writer ddl", "expected the writer() DDL site to be inventoried, got %d sites", len(wq))
	}

	// fmt.Sprintf with a literal format and fully resolvable arguments is a
	// static statement in disguise. utils.SRID is a compile-time constant, so
	// leaving "%d" in the golden made those sites unconvertible for a reason
	// that had nothing to do with SQL.
	sprintfSrc := "package p\nfunc f() { db.Raw(fmt.Sprintf(\"SELECT ST_GeomFromText(?, %d) FROM t\", SRID)) }"
	sp, _ := sitesInFile(token.NewFileSet(), "s.go", "s.go", sprintfSrc, false,
		map[string]string{"SRID": "3857"})
	if len(sp) != 1 {
		fail("sprintf fold", "expected 1 site, got %d", len(sp))
	} else {
		if sp[0].GoldenSQL != "SELECT ST_GeomFromText(?, 3857) FROM t" {
			fail("sprintf fold", "argument not substituted: %q", sp[0].GoldenSQL)
		}
		if sp[0].Dynamic {
			fail("sprintf fold", "site marked dynamic although every argument resolved")
		}
	}

	// A doubled %% in Go source is a single % in the SQL. Recording the doubled
	// form made DATE_FORMAT goldens wrong rather than merely incomplete.
	pctSrc := "package p\nfunc f() { db.Raw(fmt.Sprintf(\"SELECT DATE_FORMAT(d, '%%Y-%%m-%%d') FROM t\")) }"
	pc, _ := sitesInFile(token.NewFileSet(), "p.go", "p.go", pctSrc, false, nil)
	if len(pc) == 1 && pc[0].GoldenSQL != "SELECT DATE_FORMAT(d, '%Y-%m-%d') FROM t" {
		fail("sprintf percent", "%%%% not unescaped: %q", pc[0].GoldenSQL)
	}

	// An argument that cannot be resolved must still mark the site dynamic -
	// that statement really does have more than one form.
	dynSrc := "package p\nfunc f() { db.Raw(fmt.Sprintf(\"SELECT * FROM t WHERE x = %d\", runtimeValue)) }"
	dy, _ := sitesInFile(token.NewFileSet(), "d.go", "d.go", dynSrc, false, nil)
	if len(dy) == 1 && !dy[0].Dynamic {
		fail("sprintf dynamic", "unresolvable argument did not mark the site dynamic: %q", dy[0].GoldenSQL)
	}

	// SQL routed through a wrapper that takes the statement as an argument must
	// be inventoried. database.RetryExec hid two production statements in
	// message/markseen.go from the manifest entirely.
	wrapSrc := "package p\nfunc f() { database.RetryExec(db, \"INSERT INTO t (a) VALUES (?)\", 1) }"
	wr, _ := sitesInFile(token.NewFileSet(), "r.go", "r.go", wrapSrc, false, nil)
	if len(wr) != 1 {
		fail("sql wrapper", "RetryExec call was not inventoried, got %d sites", len(wr))
	} else if wr[0].GoldenSQL != "INSERT INTO t (a) VALUES (?)" {
		fail("sql wrapper", "wrong golden from wrapper: %q", wr[0].GoldenSQL)
	}

	// RetryQuery puts a destination BEFORE the SQL, so a fixed argument index
	// would read the wrong argument.
	wrQSrc := "package p\nfunc f() { database.RetryQuery(db, &dest, \"SELECT a FROM t\") }"
	wqSites, _ := sitesInFile(token.NewFileSet(), "q.go", "q.go", wrQSrc, false, nil)
	if len(wqSites) != 1 || wqSites[0].GoldenSQL != "SELECT a FROM t" {
		got := ""
		if len(wqSites) == 1 {
			got = wqSites[0].GoldenSQL
		}
		fail("sql wrapper index", "RetryQuery's SQL argument not found (%d sites, golden %q)", len(wqSites), got)
	}

	return failures
}

func contains(hay []string, needle string) bool {
	for _, h := range hay {
		if h == needle {
			return true
		}
	}
	return false
}
