// Command extract builds the raw-SQL inventory that drives the ORM migration.
//
// It is deliberately an AST walker rather than a set of regexps: the SQL in
// iznik-server-go is assembled by multi-line Go string concatenation
// ("SELECT " + "x " + "FROM y"), sometimes in backtick literals, and the
// manifest needs the enclosing function and the folded statement text. Regexps
// get all three wrong.
//
// Output is manifest.json, which is the contract the CI ratchet enforces.
package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"go/ast"
	"go/parser"
	"go/token"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
)

// Status values a site may carry. Exactly one, always.
const (
	StatusRaw         = "raw"
	StatusInProgress  = "in-progress"
	StatusConverted   = "converted"
	StatusKeepRaw     = "keep-raw"
	StatusTestFixture = "test-fixture"
	StatusRetired     = "retired"
)

// Site is one raw SQL call site.
type Site struct {
	ID       string `json:"id"`
	File     string `json:"file"`
	Line     int    `json:"line"`
	Function string `json:"function"`
	Receiver string `json:"receiver"`
	Method   string `json:"method"`

	// SQLSource is "literal" when the statement could be folded from string
	// literals, or "variable" when the call site is handed a pre-built string
	// (db.Raw(query, args...)). Variable sites are the dynamic query builders;
	// they cannot be mechanically converted, but they must still be inventoried
	// or the ratchet would not be exhaustive.
	SQLSource string `json:"sqlSource"`

	Kind       string   `json:"kind"`       // SELECT, INSERT, UPDATE, DELETE, REPLACE, DDL, DYNAMIC, OTHER
	Tables     []string `json:"tables"`     // tables touched, best effort
	MySQLisms  []string `json:"mysqlisms"`  // dialect features that will not port
	Complexity string   `json:"complexity"` // simple, moderate, complex
	Wave       int      `json:"wave"`       // suggested conversion wave, per plan 7.3
	Dynamic    bool     `json:"dynamic"`    // SQL text is not fully static
	Args       int      `json:"args"`       // bind arguments passed after the SQL

	// GoldenSQL is the original statement, whitespace-collapsed. Layer 1 parity
	// tests render the replacement ORM call and compare against this.
	GoldenSQL string `json:"goldenSql"`

	// PresentInCode is false for a site that the previous manifest knew about
	// but the current scan no longer finds. Converting a site removes its raw
	// SQL, so without retaining it the manifest would silently shrink and
	// "converted" would be indistinguishable from "quietly deleted".
	PresentInCode bool `json:"presentInCode"`

	// HasParityTest records whether a test anywhere under the scanned tree
	// names this site ID. This is plan 7.2's Gate 2 checked mechanically: a
	// site may only be counted converted once a parity test bearing its ID
	// exists.
	HasParityTest bool `json:"hasParityTest"`

	Status string `json:"status"`
	// Reason is required when Status is keep-raw, and explains why.
	Reason string `json:"reason,omitempty"`
	// ApprovedDiff records a reviewer-justified divergence between the golden
	// SQL and what the ORM emits (plan 7.2, Layer 1).
	ApprovedDiff string `json:"approvedDiff,omitempty"`
}

// sqlMethods are the call names that can carry a SQL string as first argument.
// GORM's Raw/Exec, plus database/sql for iznik-routing-go style call sites.
var sqlMethods = map[string]bool{
	"Raw": true, "Exec": true, "ExecContext": true,
	"Query": true, "QueryRow": true, "QueryContext": true, "QueryRowContext": true,
	"Prepare": true, "PrepareContext": true,
}

// leadingKeyword matches the first word of a statement. This is the filter that
// keeps non-SQL Exec() calls (template execution, os/exec) out of the manifest:
// if the folded first argument does not begin with a SQL verb, it is not a site.
// The leading "(" is not cosmetic: the newsfeed queries are unions of
// parenthesised subqueries, "(SELECT ...) UNION (SELECT ...)", and requiring a
// bare verb at position zero silently dropped them.
var leadingKeyword = regexp.MustCompile(`(?is)^[\s(]*(?:/\*.*?\*/\s*)?(select|insert|update|delete|replace|create|alter|drop|truncate|set|show|call|with|explain|analyze|optimize|lock|unlock|start|commit|rollback|begin)\b`)

var whitespace = regexp.MustCompile(`\s+`)

// normaliseGolden collapses whitespace and drops any trailing statement
// separator. A semicolon is not part of the statement: GORM never emits one, so
// recording it made 30 sites impossible to convert without an approved diff
// apiece, for a difference that carries no meaning. Trimming it here rather
// than teaching the canonicaliser to ignore it keeps the golden honest about
// what the statement actually is.
func normaliseGolden(text string) string {
	sql := whitespace.ReplaceAllString(strings.TrimSpace(text), " ")
	return strings.TrimSpace(strings.TrimRight(strings.TrimSpace(sql), ";"))
}

// mysqlismPatterns are the dialect features called out in the migration plan.
// Their presence forces a site to complex, because the conversion cannot be
// mechanical: none of this syntax exists on PostgreSQL.
var mysqlismPatterns = []struct {
	name string
	re   *regexp.Regexp
}{
	{"backticks", regexp.MustCompile("`")},
	{"on-duplicate-key", regexp.MustCompile(`(?i)\bon\s+duplicate\s+key\b`)},
	{"insert-ignore", regexp.MustCompile(`(?i)\binsert\s+ignore\b`)},
	{"replace-into", regexp.MustCompile(`(?i)\breplace\s+into\b`)},
	{"any-value", regexp.MustCompile(`(?i)\bany_value\s*\(`)},
	{"date-format", regexp.MustCompile(`(?i)\bdate_format\s*\(`)},
	{"timestampdiff", regexp.MustCompile(`(?i)\btimestampdiff\s*\(`)},
	{"ifnull", regexp.MustCompile(`(?i)\bifnull\s*\(`)},
	{"force-index", regexp.MustCompile(`(?i)\bforce\s+index\b`)},
	{"straight-join", regexp.MustCompile(`(?i)\bstraight_join\b`)},
	{"spatial", regexp.MustCompile(`(?i)\b(st_[a-z_]+|mbr[a-z_]*)\s*\(`)},
	{"last-insert-id", regexp.MustCompile(`(?i)\blast_insert_id\s*\(`)},
	{"group-concat", regexp.MustCompile(`(?i)\bgroup_concat\s*\(`)},
	{"unix-timestamp", regexp.MustCompile(`(?i)\bunix_timestamp\s*\(`)},
	{"limit-offset-comma", regexp.MustCompile(`(?i)\blimit\s+\?\s*,\s*\?`)},
}

var (
	joinRe   = regexp.MustCompile(`(?i)\bjoin\b`)
	unionRe  = regexp.MustCompile(`(?i)\bunion\b`)
	subqRe   = regexp.MustCompile(`(?i)\(\s*select\b`)
	tableRe  = regexp.MustCompile("(?i)\\b(?:from|join|into|update)\\s+([`\"]?[a-z_][a-z0-9_]*[`\"]?)")
	deleteRe = regexp.MustCompile("(?i)\\bdelete\\s+from\\s+([`\"]?[a-z_][a-z0-9_]*[`\"]?)")
)

func main() {
	var root, out, repo, rules string
	var selftest bool
	flag.StringVar(&root, "root", "../../iznik-server-go", "directory to scan")
	// The manifest lives beside the harness, not beside this tool, because the
	// Go tests embed it: the apiv2 container mounts iznik-server-go as /app, so
	// anything above that directory is unreachable at test time.
	flag.StringVar(&out, "out", "../../iznik-server-go/ormharness/manifest.json", "manifest path")
	flag.StringVar(&repo, "repo", "../..", "repo root; manifest paths are recorded relative to it")
	// Kept separate from -out because the ratchet regenerates to a temp path,
	// and deriving the rules location from the output silently dropped every
	// keep-raw decision in that run.
	flag.StringVar(&rules, "rules", "", "keep-raw rules file (default: keep-raw.json beside the manifest)")
	flag.BoolVar(&selftest, "selftest", false, "run the extractor's own checks and exit")
	var duplicates string
	// Pre-flight for gate (h). Converting one of two identical statements in a
	// file renumbers the survivor's site ID, so they have to move together. The
	// gate catches it, but only after the work is done; the Wave 2 pilot found
	// its duplicate by grepping afterwards and said so. This makes the check
	// available before editing, which is when it is useful.
	flag.StringVar(&duplicates, "duplicates", "", "list sites sharing identical SQL within a file (\"all\", or a path substring) and exit")
	flag.Parse()

	if selftest {
		failures := runSelfCheck()
		for _, f := range failures {
			fmt.Fprintln(os.Stderr, "FAIL", f)
		}
		if len(failures) > 0 {
			fmt.Fprintf(os.Stderr, "\n%d self-check failure(s)\n", len(failures))
			os.Exit(1)
		}
		fmt.Printf("extractor self-check passed (%d cases)\n", len(selfCases))
		return
	}

	sites, err := scan(root, repo)
	if err != nil {
		fmt.Fprintln(os.Stderr, "extract:", err)
		os.Exit(1)
	}

	if duplicates != "" {
		reportDuplicates(sites, duplicates)
		return
	}

	// Carry forward statuses and reviewer annotations from the existing
	// manifest. Regenerating must never silently reset a keep-raw decision or
	// drop the justification that survived review.
	if prev, err := load(out); err == nil {
		sites = merge(sites, prev)
	}

	// Gate 2, checked mechanically: a site whose raw SQL has gone and which is
	// named by a parity test counts as converted. One that has gone with no
	// test to vouch for it keeps its old status, so the ratchet can refuse it.
	tested, err := parityTestedIDs(root)
	if err != nil {
		fmt.Fprintln(os.Stderr, "extract:", err)
		os.Exit(1)
	}
	for _, s := range sites {
		s.HasParityTest = tested[s.ID]
		// keep-raw is included deliberately. merge() carries it forward as a
		// human decision, so it survives the rule that created it being
		// removed - and a site whose raw SQL has GONE and which a parity test
		// names was converted, whatever an out-of-date label says. Without
		// this, withdrawing a keep-raw rule leaves the site stuck in a status
		// nothing can move it out of, and it silently stops counting as
		// converted work.
		if !s.PresentInCode && s.HasParityTest &&
			(s.Status == StatusRaw || s.Status == StatusInProgress || s.Status == StatusKeepRaw) {
			s.Status = StatusConverted
		}
	}

	// Applied after the carry-forward so that a rule added to keep-raw.json
	// takes effect on the next run without anyone hand-editing the manifest.
	if rules == "" {
		// Beside this tool, not beside the manifest: the two now live in
		// different directories, and the rules are the tool's input.
		rules = "keep-raw.json"
	}
	applied, err := applyKeepRaw(sites, rules)
	if err != nil {
		fmt.Fprintln(os.Stderr, "extract:", err)
		os.Exit(1)
	}

	diffs, err := applyApprovedDiffs(sites, filepath.Join(filepath.Dir(rules), "approved-diffs.json"))
	if err != nil {
		fmt.Fprintln(os.Stderr, "extract:", err)
		os.Exit(1)
	}

	sort.Slice(sites, func(i, j int) bool {
		if sites[i].File != sites[j].File {
			return sites[i].File < sites[j].File
		}
		return sites[i].Line < sites[j].Line
	})

	if err := write(out, sites); err != nil {
		fmt.Fprintln(os.Stderr, "extract:", err)
		os.Exit(1)
	}
	fmt.Printf("%d sites written to %s (%d keep-raw by rule, %d approved diffs)\n", len(sites), out, applied, diffs)
}

func scan(root, repo string) ([]*Site, error) {
	var sites []*Site
	fset := token.NewFileSet()

	repoAbs, err := filepath.Abs(repo)
	if err != nil {
		return nil, err
	}

	err = filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if info.IsDir() {
			if name := info.Name(); name == "vendor" || name == ".git" || name == "node_modules" {
				return filepath.SkipDir
			}
			return nil
		}
		if !strings.HasSuffix(path, ".go") {
			return nil
		}

		// Fixture SQL is anything in a _test.go file or living in the test
		// package. testUtils.go is not named _test.go but is 79 sites of pure
		// fixture setup, and counting it as production overstates the work.
		isTest := strings.HasSuffix(path, "_test.go") ||
			strings.Contains(filepath.ToSlash(path), "/test/")
		abs, err := filepath.Abs(path)
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(repoAbs, abs)
		if err != nil {
			return err
		}
		rel = filepath.ToSlash(rel)

		found, err := sitesInFile(fset, path, rel, nil, isTest)
		if err != nil {
			return err
		}
		sites = append(sites, found...)
		return nil
	})
	return sites, err
}

// sitesInFile inventories one file. src may be nil to read from disk, or a
// string holding the source, which is what the self-test uses.
func sitesInFile(fset *token.FileSet, path, rel string, src any, isTest bool) ([]*Site, error) {
	file, err := parser.ParseFile(fset, path, src, 0)
	if err != nil {
		// A file that does not parse cannot be inventoried; fail loudly
		// rather than silently under-reporting the raw surface.
		return nil, fmt.Errorf("parse %s: %w", path, err)
	}

	var sites []*Site
	seen := map[string]int{}

	// Walk each function separately so the enclosing name is known without
	// maintaining a stack.
	{
		for _, decl := range file.Decls {
			fn, ok := decl.(*ast.FuncDecl)
			if !ok {
				continue
			}
			ast.Inspect(fn.Body, func(n ast.Node) bool {
				call, ok := n.(*ast.CallExpr)
				if !ok || len(call.Args) == 0 {
					return true
				}
				sel, ok := call.Fun.(*ast.SelectorExpr)
				if !ok || !sqlMethods[sel.Sel.Name] {
					return true
				}

				recv := exprString(sel.X)
				text, dynamic, ok := fold(call.Args[0])

				var sql, source string
				switch {
				case ok && leadingKeyword.MatchString(whitespace.ReplaceAllString(strings.TrimSpace(text), " ")):
					sql = normaliseGolden(text)
					source = "literal"

				case ok && dynamic && isSQLHandle(sel.Sel.Name, recv):
					// A fragment that begins with a hole, such as
					// db.Raw(baseQuery + " GROUP BY cm.id ..."). The statement
					// starts in a variable, so no verb appears at position zero,
					// but this is unambiguously a SQL call site and must be
					// inventoried rather than dropped.
					sql = normaliseGolden(text)
					source = "partial"

				case ok:
					return true // not SQL: template.Execute, os/exec, etc.

				case isSQLHandle(sel.Sel.Name, recv):
					// The statement is built elsewhere and passed in, so there is
					// no text to fold. These are the dynamic query builders
					// (message_list.go, chatroom.go, admin.go). Recording them as
					// unclassifiable-but-present is what keeps the inventory
					// exhaustive; a site absent from the manifest is invisible to
					// the ratchet, which is the one failure the plan forbids.
					sql = "{{built at runtime: " + exprString(call.Args[0]) + "}}"
					dynamic = true
					source = "variable"

				default:
					return true // not a SQL call site
				}

				site := &Site{
					File:      rel,
					Line:      fset.Position(call.Pos()).Line,
					Function:  fn.Name.Name,
					Receiver:  recv,
					Method:    sel.Sel.Name,
					GoldenSQL: sql,
					SQLSource: source,
					Dynamic:   dynamic,
					Args:      len(call.Args) - 1,
				}
				classify(site)

				// Identical SQL can legitimately appear twice in one file, so
				// the occurrence index discriminates. Keyed on the SQL rather
				// than a bare counter so that adding an unrelated site earlier
				// in the file does not renumber this one.
				key := rel + "\x00" + sql
				site.ID = stableID(key, seen[key])
				seen[key]++

				site.PresentInCode = true
				if isTest {
					site.Status = StatusTestFixture
				} else {
					site.Status = StatusRaw
				}
				sites = append(sites, site)
				return true
			})
		}
	}
	return sites, nil
}

// fold reconstructs the SQL text from a Go expression, evaluating constant
// string concatenation. Non-constant operands become {{expr}} holes and mark
// the site dynamic; a dynamic site can never be mechanically converted, so the
// classifier pushes it to complex.
func fold(e ast.Expr) (text string, dynamic bool, ok bool) {
	switch v := e.(type) {
	case *ast.BasicLit:
		if v.Kind != token.STRING {
			return "", false, false
		}
		s, err := strconv.Unquote(v.Value)
		if err != nil {
			return "", false, false
		}
		return s, false, true

	case *ast.BinaryExpr:
		if v.Op != token.ADD {
			return "", false, false
		}
		l, ld, lok := fold(v.X)
		if !lok {
			l, ld = "{{expr}}", true
		}
		r, rd, rok := fold(v.Y)
		if !rok {
			r, rd = "{{expr}}", true
		}
		// At least one side must be a real string literal, else this is
		// arithmetic rather than SQL assembly.
		if !lok && !rok {
			return "", false, false
		}
		return l + r, ld || rd, true

	case *ast.ParenExpr:
		return fold(v.X)

	case *ast.CallExpr:
		// fmt.Sprintf("...", x) - keep the format string, which retains the
		// SQL shape, and treat the interpolations as holes.
		if sel, isSel := v.Fun.(*ast.SelectorExpr); isSel && sel.Sel.Name == "Sprintf" && len(v.Args) > 0 {
			if s, _, sok := fold(v.Args[0]); sok {
				return s, true, true
			}
		}
		return "", false, false
	}
	return "", false, false
}

// dbHandles are the receiver names that denote a database handle in this
// codebase. The list is closed deliberately: Exec and Query are generic method
// names, and without it fiber's c.Query("uid") (an HTTP query-parameter getter,
// 300+ call sites) would flood the manifest with things that are not SQL.
var dbHandles = map[string]bool{
	"db": true, "tx": true, "sqlDB": true, "gdb": true, "stmt": true,
	"conn": true, "dbConn": true, "database.DBConn": true, "q": true,
}

// isSQLHandle decides whether a call whose first argument is not a foldable
// string is nonetheless a SQL call site. Raw is GORM's own method name and has
// no non-SQL meaning, so it always counts; the generic names have to be
// qualified by the receiver.
func isSQLHandle(method, receiver string) bool {
	if method == "Raw" {
		return true
	}
	if dbHandles[receiver] {
		return true
	}
	// Struct fields such as b.db or s.DBConn.
	if i := strings.LastIndex(receiver, "."); i >= 0 {
		switch strings.ToLower(receiver[i+1:]) {
		case "db", "dbconn", "sqldb", "tx":
			return true
		}
	}
	return false
}

func classify(s *Site) {
	if s.SQLSource == "variable" {
		// Nothing can be inferred from a statement that does not exist until
		// runtime, so it goes straight to wave 5 triage.
		s.Kind = "DYNAMIC"
		s.Complexity = "complex"
		s.Wave = 5
		s.Tables = []string{}
		return
	}
	if s.SQLSource == "partial" {
		// The visible fragment still names tables and dialect features worth
		// recording, but the statement is only assembled at runtime, so it can
		// never be a mechanical conversion.
		s.Kind = "DYNAMIC"
		s.Complexity = "complex"
		s.Wave = 5
		s.Tables = tables(s.GoldenSQL)
		for _, p := range mysqlismPatterns {
			if p.re.MatchString(s.GoldenSQL) {
				s.MySQLisms = append(s.MySQLisms, p.name)
			}
		}
		sort.Strings(s.MySQLisms)
		return
	}

	// Take the verb from the same regex that admitted the statement, rather
	// than testing the prefix directly: a union of parenthesised subqueries
	// starts with "(", and prefix matching classified those as OTHER.
	verb := ""
	if m := leadingKeyword.FindStringSubmatch(s.GoldenSQL); m != nil {
		verb = strings.ToUpper(m[1])
	}

	switch verb {
	case "SELECT", "WITH":
		s.Kind = "SELECT"
	case "INSERT", "UPDATE", "DELETE", "REPLACE":
		s.Kind = verb
	case "CREATE", "ALTER", "DROP", "TRUNCATE":
		s.Kind = "DDL"
	default:
		s.Kind = "OTHER"
	}

	for _, p := range mysqlismPatterns {
		if p.re.MatchString(s.GoldenSQL) {
			s.MySQLisms = append(s.MySQLisms, p.name)
		}
	}
	sort.Strings(s.MySQLisms)

	s.Tables = tables(s.GoldenSQL)

	// Complexity drives the wave order. "Simple" must mean genuinely
	// mechanical, so anything dynamic, spatial, multi-table or carrying
	// non-portable syntax is excluded from it.
	multiTable := len(s.Tables) > 1 || joinRe.MatchString(s.GoldenSQL)
	hard := unionRe.MatchString(s.GoldenSQL) || subqRe.MatchString(s.GoldenSQL) || s.Dynamic
	for _, m := range s.MySQLisms {
		// Backticks alone are a quoting detail the ORM handles, not a blocker.
		if m != "backticks" {
			hard = true
		}
	}

	switch {
	case hard:
		s.Complexity = "complex"
	case multiTable:
		s.Complexity = "moderate"
	default:
		s.Complexity = "simple"
	}

	s.Wave = wave(s)
}

// wave maps a site to the fixed conversion order in plan 7.3.
func wave(s *Site) int {
	if s.Complexity == "complex" {
		if len(s.MySQLisms) > 0 && s.Kind == "INSERT" {
			return 3 // upserts, via clause.OnConflict
		}
		return 5 // triage: keep-raw or individually planned
	}
	switch s.Kind {
	case "SELECT":
		if s.Complexity == "simple" {
			return 1
		}
		return 4 // multi-table SELECT, no MySQL-only functions
	case "INSERT", "UPDATE", "DELETE":
		if s.Complexity == "simple" {
			return 2
		}
		return 5
	case "REPLACE":
		return 3
	}
	return 5
}

func tables(sql string) []string {
	set := map[string]bool{}
	add := func(m [][]string) {
		for _, g := range m {
			t := strings.Trim(g[1], "`\"")
			// SELECT ... FROM (SELECT ...) and keyword collisions.
			switch strings.ToUpper(t) {
			case "SELECT", "DUAL", "":
			default:
				set[strings.ToLower(t)] = true
			}
		}
	}
	add(tableRe.FindAllStringSubmatch(sql, -1))
	add(deleteRe.FindAllStringSubmatch(sql, -1))

	out := make([]string, 0, len(set))
	for t := range set {
		out = append(out, t)
	}
	sort.Strings(out)
	return out
}

func stableID(key string, occurrence int) string {
	sum := sha256.Sum256([]byte(key + "\x00" + strconv.Itoa(occurrence)))
	return hex.EncodeToString(sum[:])[:12]
}

func exprString(e ast.Expr) string {
	switch v := e.(type) {
	case *ast.Ident:
		return v.Name
	case *ast.SelectorExpr:
		return exprString(v.X) + "." + v.Sel.Name
	case *ast.CallExpr:
		return exprString(v.Fun) + "()"
	case *ast.IndexExpr:
		return exprString(v.X) + "[]"
	}
	return "?"
}

// reportDuplicates lists sites that share identical SQL within one file, which
// are the sites gate (h) requires to be converted together. filter is "all" or
// a substring of the path, so an agent can check just the file it is about to
// edit.
func reportDuplicates(sites []*Site, filter string) {
	groups := map[string][]*Site{}
	for _, s := range sites {
		if s.Status == StatusTestFixture {
			continue
		}
		if filter != "all" && !strings.Contains(s.File, filter) {
			continue
		}
		groups[s.File+"\x00"+s.GoldenSQL] = append(groups[s.File+"\x00"+s.GoldenSQL], s)
	}

	keys := make([]string, 0, len(groups))
	for k, g := range groups {
		if len(g) > 1 {
			keys = append(keys, k)
		}
	}
	sort.Strings(keys)

	if len(keys) == 0 {
		fmt.Println("no duplicate statements found; nothing needs converting together")
		return
	}

	fmt.Printf("%d group(s) of identical statements. Convert each group together, or leave it alone:\n\n", len(keys))
	for _, k := range keys {
		g := groups[k]
		sort.Slice(g, func(i, j int) bool { return g[i].Line < g[j].Line })
		fmt.Printf("%s\n  %s\n", g[0].File, g[0].GoldenSQL)
		for _, s := range g {
			fmt.Printf("    %s  line %-6d %s()  [%s]\n", s.ID, s.Line, s.Function, s.Status)
		}
		fmt.Println()
	}
}

// keepRawRules is the declarative form of plan 7.5.
type keepRawRules struct {
	Rules []struct {
		// ID pins a rule to one call site. File/Function rules are the right
		// shape for "this whole query is too gnarly to port", but the wrong
		// shape for a decision that applies to a single statement inside a
		// function whose other statements should still be converted - an
		// INSERT whose generated id is read back, say. Without this, keeping
		// that one statement raw would drag its neighbours out of the
		// migration with it.
		ID       string `json:"id"`
		File     string `json:"file"`
		Function string `json:"function"`
		Reason   string `json:"reason"`
	} `json:"rules"`
}

// applyKeepRaw marks sites that review has decided stay raw. A converted site
// is never demoted: once a site has a passing parity test, a broad rule added
// later must not silently undo that work.
func applyKeepRaw(sites []*Site, path string) (int, error) {
	b, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}

	var rules keepRawRules
	if err := json.Unmarshal(b, &rules); err != nil {
		return 0, fmt.Errorf("%s: %w", path, err)
	}
	for i, r := range rules.Rules {
		if strings.TrimSpace(r.Reason) == "" {
			return 0, fmt.Errorf("%s: rule %d (%s) has no reason; deferral must always carry a justification", path, i, r.File)
		}
	}

	applied := 0
	hits := make([]int, len(rules.Rules))
	for _, s := range sites {
		if s.Status == StatusConverted || s.Status == StatusTestFixture {
			continue
		}
		for ri, r := range rules.Rules {
			if r.ID != "" {
				// An id-pinned rule matches that site and nothing else, so a
				// file/function given alongside it is documentation rather
				// than part of the match.
				if s.ID != r.ID {
					continue
				}
			} else {
				// A trailing slash means the rule covers a whole directory.
				matchFile := s.File == r.File ||
					(strings.HasSuffix(r.File, "/") && strings.HasPrefix(s.File, r.File))
				if !matchFile {
					continue
				}
				if r.Function != "" && s.Function != r.Function {
					continue
				}
			}
			s.Status = StatusKeepRaw
			s.Reason = r.Reason
			applied++
			hits[ri]++
			break
		}
	}

	// A rule that matches nothing is usually a typo in a path. It can also be
	// legitimate, so warn rather than fail, but never let it pass unnoticed.
	for i, r := range rules.Rules {
		if hits[i] == 0 {
			// An id-pinned rule that matches nothing is more serious than a
			// path typo: the site it named has moved or been renumbered, so
			// the decision it recorded is no longer protecting anything.
			if r.ID != "" {
				fmt.Fprintf(os.Stderr, "warning: keep-raw rule %d names site %s (%s %s), which no longer exists - it may have been renumbered\n", i, r.ID, r.File, r.Function)
			} else {
				fmt.Fprintf(os.Stderr, "warning: keep-raw rule %d matched no sites: %s %s\n", i, r.File, r.Function)
			}
		}
	}
	return applied, nil
}

// approvedDiffRules is the declarative form of plan 7.2's approved-diff
// escape hatch.
type approvedDiffRules struct {
	Diffs []struct {
		ID     string `json:"id"`
		SQL    string `json:"sql"`
		Reason string `json:"reason"`
	} `json:"diffs"`
}

// applyApprovedDiffs records reviewer-justified divergences between a site's
// golden SQL and what the ORM emits. Kept declarative, like the keep-raw
// rules, so the justification is reviewed as a diff rather than hand-edited
// into the manifest and silently lost on the next regeneration.
func applyApprovedDiffs(sites []*Site, path string) (int, error) {
	b, err := os.ReadFile(path)
	if os.IsNotExist(err) {
		return 0, nil
	}
	if err != nil {
		return 0, err
	}

	var rules approvedDiffRules
	if err := json.Unmarshal(b, &rules); err != nil {
		return 0, fmt.Errorf("%s: %w", path, err)
	}

	byID := map[string]string{}
	for i, r := range rules.Diffs {
		if strings.TrimSpace(r.Reason) == "" {
			return 0, fmt.Errorf("%s: diff %d (%s) has no reason; an approved diff asserts two statements are equivalent, which must be justified", path, i, r.ID)
		}
		if strings.TrimSpace(r.SQL) == "" {
			return 0, fmt.Errorf("%s: diff %d (%s) has no sql", path, i, r.ID)
		}
		byID[r.ID] = r.SQL
	}

	applied := 0
	seen := map[string]bool{}
	for _, s := range sites {
		if sql, ok := byID[s.ID]; ok {
			s.ApprovedDiff = sql
			seen[s.ID] = true
			applied++
		}
	}
	for id := range byID {
		if !seen[id] {
			fmt.Fprintf(os.Stderr, "warning: approved-diff entry %s matches no site\n", id)
		}
	}
	return applied, nil
}

// Manifest is the on-disk shape.
type Manifest struct {
	Generated string           `json:"generated"`
	Root      string           `json:"root"`
	Counts    map[string]int   `json:"counts"`
	Sites     map[string]*Site `json:"sites"`
}

func load(path string) (*Manifest, error) {
	b, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var m Manifest
	if err := json.Unmarshal(b, &m); err != nil {
		return nil, err
	}
	return &m, nil
}

// siteIDPattern matches the 12-hex site IDs this tool mints, so that a parity
// test naming its site can be recognised mechanically.
var siteIDPattern = regexp.MustCompile(`^[0-9a-f]{12}$`)

// parityAssertions are the harness calls that constitute proof for a site.
var parityAssertions = map[string]bool{
	"AssertGoldenSQL":    true,
	"AssertResultParity": true,
}

// parityTestedIDs collects every site ID actually asserted on by a test under
// root. Plan 7.2 makes this Gate 2: "a site cannot be marked converted unless a
// parity test bearing its ID exists and passes. The extractor checks test
// existence mechanically." Existence is what is checked here; passing is the
// suite's job.
//
// This parses rather than greps, and that distinction is load-bearing. Matching
// the ID anywhere in the file counted a COMMENT as proof, so a note reading
// "site abc123 is deliberately not converted" satisfied the gate asserting that
// it was, which is precisely backwards. Working from the AST excludes comments
// by construction, and requiring the ID to be a string argument to one of the
// assertion calls means an incidental mention cannot vouch for anything.
func parityTestedIDs(root string) (map[string]bool, error) {
	found := map[string]bool{}
	fset := token.NewFileSet()

	err := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if info.IsDir() {
			if name := info.Name(); name == "vendor" || name == ".git" || name == "node_modules" {
				return filepath.SkipDir
			}
			return nil
		}
		if !strings.HasSuffix(path, "_test.go") {
			return nil
		}

		file, err := parser.ParseFile(fset, path, nil, 0)
		if err != nil {
			return fmt.Errorf("parse %s: %w", path, err)
		}

		ast.Inspect(file, func(n ast.Node) bool {
			call, ok := n.(*ast.CallExpr)
			if !ok {
				return true
			}
			sel, ok := call.Fun.(*ast.SelectorExpr)
			if !ok || !parityAssertions[sel.Sel.Name] {
				return true
			}
			for _, arg := range call.Args {
				lit, ok := arg.(*ast.BasicLit)
				if !ok || lit.Kind != token.STRING {
					continue
				}
				s, err := strconv.Unquote(lit.Value)
				if err == nil && siteIDPattern.MatchString(s) {
					found[s] = true
				}
			}
			return true
		})
		return nil
	})
	return found, err
}

// merge carries reviewer decisions forward across regeneration, and retains
// sites the scan no longer finds. Only the human-owned fields survive on a
// site that still exists; everything derived is recomputed from source.
func merge(sites []*Site, prev *Manifest) []*Site {
	for _, s := range sites {
		old, ok := prev.Sites[s.ID]
		if !ok {
			continue
		}
		// Only decisions a human made are carried forward. The raw versus
		// test-fixture axis is derived from the file path, so letting a stale
		// manifest override it would freeze a misclassification permanently:
		// that is exactly how 79 fixture sites in testUtils.go kept counting
		// as production work after the path rule was corrected.
		switch old.Status {
		case StatusConverted, StatusInProgress, StatusKeepRaw, StatusRetired:
			s.Status = old.Status
		}
		s.Reason = old.Reason
		s.ApprovedDiff = old.ApprovedDiff
	}

	// Retain sites the scan no longer finds. A converted site's raw SQL is
	// gone by definition, so dropping it here would make the manifest shrink
	// as work progressed and would hide a site that was simply deleted.
	seen := make(map[string]bool, len(sites))
	for _, s := range sites {
		seen[s.ID] = true
	}
	for id, old := range prev.Sites {
		if seen[id] || old.Status == StatusTestFixture {
			continue
		}
		gone := *old
		gone.PresentInCode = false
		sites = append(sites, &gone)
	}
	return sites
}

func write(path string, sites []*Site) error {
	counts := map[string]int{}
	byID := make(map[string]*Site, len(sites))
	for _, s := range sites {
		counts[s.Status]++
		byID[s.ID] = s
	}
	m := Manifest{
		// No timestamp: a regenerated manifest must be byte-identical when the
		// code has not changed, or the CI ratchet's diff check is worthless.
		Generated: "derived-from-source",
		Root:      "iznik-server-go",
		Counts:    counts,
		Sites:     byID,
	}
	b, err := json.MarshalIndent(m, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, append(b, '\n'), 0o644)
}
