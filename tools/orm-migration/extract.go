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
	var root, out, repo string
	var selftest bool
	flag.StringVar(&root, "root", "../../iznik-server-go", "directory to scan")
	flag.StringVar(&out, "out", "manifest.json", "manifest path")
	flag.StringVar(&repo, "repo", "../..", "repo root; manifest paths are recorded relative to it")
	flag.BoolVar(&selftest, "selftest", false, "run the extractor's own checks and exit")
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

	// Carry forward statuses and reviewer annotations from the existing
	// manifest. Regenerating must never silently reset a keep-raw decision or
	// drop the justification that survived review.
	if prev, err := load(out); err == nil {
		merge(sites, prev)
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
	fmt.Printf("%d sites written to %s\n", len(sites), out)
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

		isTest := strings.HasSuffix(path, "_test.go")
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
					sql = whitespace.ReplaceAllString(strings.TrimSpace(text), " ")
					source = "literal"

				case ok && dynamic && isSQLHandle(sel.Sel.Name, recv):
					// A fragment that begins with a hole, such as
					// db.Raw(baseQuery + " GROUP BY cm.id ..."). The statement
					// starts in a variable, so no verb appears at position zero,
					// but this is unambiguously a SQL call site and must be
					// inventoried rather than dropped.
					sql = whitespace.ReplaceAllString(strings.TrimSpace(text), " ")
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

// merge carries reviewer decisions forward across regeneration. Only the
// human-owned fields survive; everything derived is recomputed from source.
func merge(sites []*Site, prev *Manifest) {
	for _, s := range sites {
		old, ok := prev.Sites[s.ID]
		if !ok {
			continue
		}
		if old.Status != "" {
			s.Status = old.Status
		}
		s.Reason = old.Reason
		s.ApprovedDiff = old.ApprovedDiff
	}
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
