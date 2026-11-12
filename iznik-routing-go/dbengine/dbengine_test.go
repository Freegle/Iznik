package dbengine

import (
	"testing"
	"time"
)

func TestParse(t *testing.T) {
	cases := map[string]Engine{
		"":           MySQL,
		"mysql":      MySQL,
		" MySQL ":    MySQL,
		"postgres":   Postgres,
		"PostgreSQL": Postgres,
	}
	for in, want := range cases {
		got, err := Parse(in)
		if err != nil || got != want {
			t.Errorf("Parse(%q) = %v, %v; want %v", in, got, err, want)
		}
	}
	for _, bad := range []string{"mariadb", "pg", "sqlite", "postgre"} {
		if _, err := Parse(bad); err == nil {
			t.Errorf("Parse(%q) should be an error: a misspelling must not select an engine", bad)
		}
	}
}

func TestCurrentDefaultsToMySQL(t *testing.T) {
	t.Setenv(EnvEngine, "")
	if Current() != MySQL {
		t.Error("with DB_ENGINE unset the engine must be MySQL")
	}
	t.Setenv(EnvEngine, "postgres")
	if Current() != Postgres {
		t.Error("DB_ENGINE=postgres must select PostgreSQL")
	}
	t.Setenv(EnvEngine, "nonsense")
	if Current() != MySQL {
		t.Error("an unknown DB_ENGINE falls back to MySQL, and FromEnv reports the error")
	}
	if _, err := FromEnv(); err == nil {
		t.Error("FromEnv must report an unknown DB_ENGINE")
	}
}

func TestDrivers(t *testing.T) {
	if MySQL.Driver() != "mysql" || Postgres.Driver() != "pgx" {
		t.Errorf("drivers: %q %q", MySQL.Driver(), Postgres.Driver())
	}
	if MySQL.Label() != "MySQL" || Postgres.Label() != "PostgreSQL" {
		t.Errorf("labels: %q %q", MySQL.Label(), Postgres.Label())
	}
}

func clearEnv(t *testing.T) {
	for _, k := range []string{
		"MYSQL_HOST", "MYSQL_PORT", "MYSQL_USER", "MYSQL_PASSWORD", "MYSQL_DBNAME",
		"PGSQL_HOST", "PGSQL_PORT", "PGSQL_USERNAME", "PGSQL_PASSWORD", "PGSQL_DATABASE", "PGSQL_SSLMODE",
	} {
		t.Setenv(k, "")
	}
}

func TestSettingsMySQL(t *testing.T) {
	clearEnv(t)
	s := SettingsFromEnv(MySQL, Settings{})
	if s.Host != "" || s.Port != "3306" || s.User != "iznik" || s.Name != "iznik" {
		t.Errorf("defaults: %+v", s)
	}
	t.Setenv("MYSQL_HOST", "db")
	t.Setenv("MYSQL_PORT", "3307")
	t.Setenv("MYSQL_USER", "u")
	t.Setenv("MYSQL_PASSWORD", "p")
	t.Setenv("MYSQL_DBNAME", "n")
	s = SettingsFromEnv(MySQL, Settings{})
	if s != (Settings{Host: "db", Port: "3307", User: "u", Password: "p", Name: "n"}) {
		t.Errorf("from env: %+v", s)
	}
}

func TestSettingsPostgres(t *testing.T) {
	clearEnv(t)
	// MYSQL_* must not leak into the PostgreSQL settings.
	t.Setenv("MYSQL_HOST", "mysqlhost")
	s := SettingsFromEnv(Postgres, Settings{})
	if s.Host != "" || s.Port != "5432" || s.User != "root" || s.Name != "iznik" {
		t.Errorf("defaults: %+v", s)
	}
	t.Setenv("PGSQL_HOST", "pg")
	t.Setenv("PGSQL_PORT", "5433")
	t.Setenv("PGSQL_USERNAME", "u")
	t.Setenv("PGSQL_PASSWORD", "p")
	t.Setenv("PGSQL_DATABASE", "n")
	s = SettingsFromEnv(Postgres, Settings{})
	if s != (Settings{Host: "pg", Port: "5433", User: "u", Password: "p", Name: "n"}) {
		t.Errorf("from env: %+v", s)
	}
}

func TestSettingsCommandDefaults(t *testing.T) {
	clearEnv(t)
	s := SettingsFromEnv(MySQL, Settings{Host: "localhost", User: "root"})
	if s.Host != "localhost" || s.User != "root" || s.Port != "3306" || s.Name != "iznik" {
		t.Errorf("%+v", s)
	}
}

// The MySQL DSNs are byte for byte what the service built before the engine was configurable.
func TestMySQLDSNUnchanged(t *testing.T) {
	s := Settings{Host: "percona", Port: "3306", User: "root", Password: "pw", Name: "iznik"}
	if got, want := MySQL.DSN(s, DSNOptions{ParseTime: true}), "root:pw@tcp(percona:3306)/iznik?parseTime=true"; got != want {
		t.Errorf("service DSN %q, want %q", got, want)
	}
	got := MySQL.DSN(s, DSNOptions{ParseTime: false, ReadTimeout: 300 * time.Second})
	if want := "root:pw@tcp(percona:3306)/iznik?parseTime=false&readTimeout=300s"; got != want {
		t.Errorf("extract DSN %q, want %q", got, want)
	}
}

func TestPostgresDSN(t *testing.T) {
	clearEnv(t)
	s := Settings{Host: "postgres", Port: "5432", User: "root", Password: "p@ss/word", Name: "iznik"}
	got := Postgres.DSN(s, DSNOptions{ParseTime: true})
	want := "postgres://root:p%40ss%2Fword@postgres:5432/iznik?sslmode=prefer&timezone=UTC"
	if got != want {
		t.Errorf("got %q, want %q", got, want)
	}
	t.Setenv("PGSQL_SSLMODE", "disable")
	if got := Postgres.DSN(s, DSNOptions{}); got != "postgres://root:p%40ss%2Fword@postgres:5432/iznik?sslmode=disable&timezone=UTC" {
		t.Errorf("sslmode not taken from the environment: %q", got)
	}
	// An IPv6 host needs its brackets.
	s.Host = "::1"
	if got := Postgres.DSN(s, DSNOptions{}); got != "postgres://root:p%40ss%2Fword@[::1]:5432/iznik?sslmode=disable&timezone=UTC" {
		t.Errorf("ipv6: %q", got)
	}
}

func TestRebind(t *testing.T) {
	cases := []struct{ in, want string }{
		{"SELECT 1", "SELECT 1"},
		{"SELECT a FROM t WHERE x = ? AND y = ?", "SELECT a FROM t WHERE x = $1 AND y = $2"},
		{"SELECT '?' , ? FROM t", "SELECT '?' , $1 FROM t"},
		{"SELECT 'it''s ?', ?", "SELECT 'it''s ?', $1"},
		{`SELECT "a?b", ?`, `SELECT "a?b", $1`},
		{"SELECT ? -- really?\n, ?", "SELECT $1 -- really?\n, $2"},
		{"IN (?,?,?)", "IN ($1,$2,$3)"},
	}
	for _, c := range cases {
		if got := Postgres.Rebind(c.in); got != c.want {
			t.Errorf("Rebind(%q) = %q, want %q", c.in, got, c.want)
		}
		if got := MySQL.Rebind(c.in); got != c.in {
			t.Errorf("MySQL Rebind(%q) changed the query: %q", c.in, got)
		}
	}
}

func TestFingerprintArg(t *testing.T) {
	const big = uint64(0xcbf29ce484222325) // above the signed 64-bit range
	if v, ok := MySQL.FingerprintArg(big).(uint64); !ok || v != big {
		t.Errorf("MySQL must receive the unsigned value unchanged, got %v", MySQL.FingerprintArg(big))
	}
	v, ok := Postgres.FingerprintArg(big).(int64)
	if !ok {
		t.Fatalf("PostgreSQL must receive an int64, got %T", Postgres.FingerprintArg(big))
	}
	if uint64(v) != big {
		t.Errorf("the signed value must keep the bit pattern: %d", v)
	}
	if Postgres.FingerprintArg(5).(int64) != 5 {
		t.Error("a small fingerprint must not change")
	}
}
