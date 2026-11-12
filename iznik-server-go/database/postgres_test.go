package database

import (
	"strings"
	"testing"
)

func TestEngineDefaultsToMySQL(t *testing.T) {
	t.Setenv("DB_ENGINE", "")
	if got := Engine(); got != EngineMySQL {
		t.Errorf("Engine() = %q, want mysql", got)
	}
	t.Setenv("DB_ENGINE", "MySQL")
	if got := Engine(); got != EngineMySQL {
		t.Errorf("Engine() = %q, want mysql", got)
	}
	t.Setenv("DB_ENGINE", "postgres")
	if got := Engine(); got != EnginePostgres {
		t.Errorf("Engine() = %q, want postgres", got)
	}
}

func TestEngineRefusesUnknownValue(t *testing.T) {
	t.Setenv("DB_ENGINE", "sqlite")
	defer func() {
		if recover() == nil {
			t.Error("an unknown DB_ENGINE must stop the process, not fall back to MySQL")
		}
	}()
	Engine()
}

func TestBuildPostgresDSN(t *testing.T) {
	t.Setenv("PGSQL_USERNAME", "u")
	t.Setenv("PGSQL_PASSWORD", "p@ss/word")
	t.Setenv("PGSQL_PORT", "5433")
	t.Setenv("PGSQL_DATABASE", "somedb")
	t.Setenv("PGSQL_SSLMODE", "")

	got := buildPostgresDSN("dbhost")
	for _, want := range []string{"postgres://u:p%40ss%2Fword@dbhost:5433/somedb?", "sslmode=prefer", "TimeZone=UTC"} {
		if !strings.Contains(got, want) {
			t.Errorf("buildPostgresDSN = %q, missing %q", got, want)
		}
	}
	if buildPostgresDSN("a") == buildPostgresDSN("b") {
		t.Error("different hosts must give different DSNs")
	}
}
