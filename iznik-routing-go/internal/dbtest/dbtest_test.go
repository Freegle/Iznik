package dbtest

import (
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"github.com/freegle/iznik-routing-go/dbengine"
)

// Every table the scratch databases promise is in the PostgreSQL schema file.
func TestSchemaHasEveryTable(t *testing.T) {
	for _, tbl := range Tables {
		if !strings.Contains(PGSchema(), "CREATE TABLE "+tbl+" (") {
			t.Errorf("pg_schema.sql has no table %s", tbl)
		}
	}
	// The unique key labels-apply checks for must be there.
	if !strings.Contains(PGSchema(), "ON rippling_reach_leaves (msgid, leaf, fp)") {
		t.Error("pg_schema.sql has no unique (msgid, leaf, fp) key on rippling_reach_leaves")
	}
}

// When the repository's baseline is next to this module, each table in the schema file is the
// baseline's, character for character. scripts/pg-test-schema.mjs --check does the whole file; this
// is the same check for a run that has no Node.
func TestSchemaMatchesBaseline(t *testing.T) {
	baseline, err := os.ReadFile(filepath.Join("..", "..", "..", "iznik-batch", "database", "postgres", "baseline.sql"))
	if err != nil {
		t.Skip("the PostgreSQL baseline is not beside this module: " + err.Error())
	}
	for _, tbl := range Tables {
		re := regexp.MustCompile(`(?m)^CREATE TABLE ` + tbl + ` \(\n[\s\S]*?\n\);\n`)
		want := re.FindString(string(baseline))
		if want == "" {
			t.Errorf("table %s is not in the baseline", tbl)
			continue
		}
		if !strings.Contains(PGSchema(), want) {
			t.Errorf("table %s differs from the baseline: run node scripts/pg-test-schema.mjs", tbl)
		}
	}
}

func TestRequiredEngines(t *testing.T) {
	cases := map[string][2]bool{ // value -> {mysql required, postgres required}
		"":               {false, false},
		"mysql":          {true, false},
		"postgres":       {false, true},
		"mysql,postgres": {true, true},
		"all":            {true, true},
		"1":              {true, true},
		" Postgres ":     {false, true},
	}
	for v, want := range cases {
		t.Setenv("ROUTING_REQUIRE_DB", v)
		if got := required(dbengine.MySQL); got != want[0] {
			t.Errorf("%q: MySQL required = %v", v, got)
		}
		if got := required(dbengine.Postgres); got != want[1] {
			t.Errorf("%q: PostgreSQL required = %v", v, got)
		}
	}
}

func TestQuote(t *testing.T) {
	if Quote(dbengine.MySQL, "groups") != "`groups`" || Quote(dbengine.Postgres, "groups") != `"groups"` {
		t.Error("identifier quoting")
	}
}
