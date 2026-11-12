package main

import (
	"crypto/sha256"
	"fmt"
	"strings"
	"testing"
)

func statements() map[string]string {
	return map[string]string{
		"Candidates":   sqlCandidates(),
		"PostMetadata": sqlPostMetadata("?,?"),
		"Repliers":     sqlRepliers("?,?"),
	}
}

// The SHA-256 of each statement as the tool sent it before the engine became configurable.
var mysqlGolden = map[string]string{
	"Candidates":   "3f72ed0eb98d6f35cfa296da8df510bc68c048a4ce7399f54622cb55be7f7823",
	"PostMetadata": "a1c9eeaefa862ea12e5d6e4b721b9d734563d3a6f210a15a2794a2fd725c5496",
	"Repliers":     "5cc8948f6328a9af21f4a533c5a63285fea631fdb4386e9ab9206e509998791b",
}

func TestMySQLStatementsUnchanged(t *testing.T) {
	t.Setenv("DB_ENGINE", "")
	for name, q := range statements() {
		if h := fmt.Sprintf("%x", sha256.Sum256([]byte(q))); h != mysqlGolden[name] {
			t.Errorf("MySQL statement %s changed (hash %s):\n%s", name, h, q)
		}
	}
}

func TestPostgresStatements(t *testing.T) {
	t.Setenv("DB_ENGINE", "postgres")
	for name, q := range statements() {
		if strings.Contains(q, "?") {
			t.Errorf("%s: a ? is left in the PostgreSQL statement", name)
		}
		for _, banned := range []string{"DATE_FORMAT", "IFNULL", "`"} {
			if strings.Contains(q, banned) {
				t.Errorf("%s: PostgreSQL statement contains %s", name, banned)
			}
		}
	}
	if q := sqlPostMetadata("?,?"); !strings.Contains(q, "IN ($1,$2)") {
		t.Errorf("the id list was not rebound: %s", q)
	}
}
