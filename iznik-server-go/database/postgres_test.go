package database

import (
	"strings"
	"testing"

	"github.com/jackc/pgx/v5"
)

// The pool defaults are the sizes the MySQL pools have, keep no more idle than open, and take sizes from
// the environment.
func TestPgPoolSizes(t *testing.T) {
	t.Setenv("PGSQL_MAX_OPEN_CONNS", "")
	t.Setenv("PGSQL_MAX_IDLE_CONNS", "")
	open, idle := pgPoolSizes()
	if open != pgDefaultMaxOpenConns || idle != pgDefaultMaxIdleConns {
		t.Errorf("defaults = %d open, %d idle", open, idle)
	}
	if idle > open {
		t.Errorf("idle (%d) must not be above open (%d)", idle, open)
	}
	if open != 200 || idle != 200 {
		t.Errorf("defaults = %d open, %d idle, want the 200 and 200 the MySQL pools use (database.go)", open, idle)
	}

	t.Setenv("PGSQL_MAX_OPEN_CONNS", "80")
	t.Setenv("PGSQL_MAX_IDLE_CONNS", "25")
	if open, idle = pgPoolSizes(); open != 80 || idle != 25 {
		t.Errorf("from the environment = %d open, %d idle, want 80 and 25", open, idle)
	}

	// Idle is never more than open, and nonsense falls back to the default.
	t.Setenv("PGSQL_MAX_OPEN_CONNS", "5")
	t.Setenv("PGSQL_MAX_IDLE_CONNS", "50")
	if open, idle = pgPoolSizes(); open != 5 || idle != 5 {
		t.Errorf("idle above open = %d open, %d idle, want 5 and 5", open, idle)
	}
	t.Setenv("PGSQL_MAX_OPEN_CONNS", "-3")
	t.Setenv("PGSQL_MAX_IDLE_CONNS", "lots")
	if open, idle = pgPoolSizes(); open != pgDefaultMaxOpenConns || idle != pgDefaultMaxIdleConns {
		t.Errorf("bad values = %d open, %d idle, want the defaults", open, idle)
	}
}

// Statements must not be kept as named prepared statements on the server (one per distinct text, per
// connection), and the parameter types must still come from the server for the lenient codecs.
func TestPgConnConfigDescribesWithoutPreparing(t *testing.T) {
	t.Setenv("PGSQL_SSLMODE", "disable")
	cfg, err := pgConnConfig(buildPostgresDSN("dbhost"))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.DefaultQueryExecMode != pgx.QueryExecModeCacheDescribe {
		t.Errorf("exec mode = %v, want cache_describe", cfg.DefaultQueryExecMode)
	}
	if _, err := pgConnConfig("postgres://u@h:notaport/db"); err == nil {
		t.Error("a DSN that does not parse must be an error")
	}
}

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
	t.Setenv("PGSQL_JIT", "")
	t.Setenv("PGSQL_LOCK_TIMEOUT", "")

	got := buildPostgresDSN("dbhost")
	for _, want := range []string{"postgres://u:p%40ss%2Fword@dbhost:5433/somedb?", "sslmode=prefer", "TimeZone=UTC", "jit=off", "lock_timeout=50s"} {
		if !strings.Contains(got, want) {
			t.Errorf("buildPostgresDSN = %q, missing %q", got, want)
		}
	}
	if buildPostgresDSN("a") == buildPostgresDSN("b") {
		t.Error("different hosts must give different DSNs")
	}

	// JIT is off unless asked for, and "server" sends no setting at all.
	t.Setenv("PGSQL_JIT", "on")
	if got := buildPostgresDSN("dbhost"); !strings.Contains(got, "jit=on") {
		t.Errorf("PGSQL_JIT=on: %q", got)
	}
	t.Setenv("PGSQL_JIT", "server")
	if got := buildPostgresDSN("dbhost"); strings.Contains(got, "jit") {
		t.Errorf("PGSQL_JIT=server must send no jit setting: %q", got)
	}

	// A lock wait ends after 50 seconds unless told otherwise, and "server" sends no setting at all.
	t.Setenv("PGSQL_LOCK_TIMEOUT", "200ms")
	if got := buildPostgresDSN("dbhost"); !strings.Contains(got, "lock_timeout=200ms") {
		t.Errorf("PGSQL_LOCK_TIMEOUT=200ms: %q", got)
	}
	t.Setenv("PGSQL_LOCK_TIMEOUT", "server")
	if got := buildPostgresDSN("dbhost"); strings.Contains(got, "lock_timeout") {
		t.Errorf("PGSQL_LOCK_TIMEOUT=server must send no lock_timeout setting: %q", got)
	}
}

// The settings reach the server as session parameters, and pgx does not mistake them for its own.
func TestPgConnConfigCarriesSessionSettings(t *testing.T) {
	t.Setenv("PGSQL_SSLMODE", "disable")
	t.Setenv("PGSQL_JIT", "")
	t.Setenv("PGSQL_LOCK_TIMEOUT", "")
	cfg, err := pgConnConfig(buildPostgresDSN("dbhost"))
	if err != nil {
		t.Fatal(err)
	}
	if cfg.RuntimeParams["jit"] != "off" || cfg.RuntimeParams["TimeZone"] != "UTC" || cfg.RuntimeParams["lock_timeout"] != "50s" {
		t.Errorf("session parameters = %v", cfg.RuntimeParams)
	}
}
