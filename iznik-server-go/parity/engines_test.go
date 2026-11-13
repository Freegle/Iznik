package parity

import (
	"strings"
	"testing"
)

type fakeTB struct {
	testing.TB
	skipped bool
	failed  bool
	message string
}

func (f *fakeTB) Helper()                         {}
func (f *fakeTB) Logf(format string, args ...any) {}
func (f *fakeTB) Skipf(format string, args ...any) {
	f.skipped = true
	f.message = format
	panic(stop{})
}
func (f *fakeTB) Skip(args ...any) { f.skipped = true; panic(stop{}) }
func (f *fakeTB) Fatalf(format string, args ...any) {
	f.failed = true
	f.message = format
	panic(stop{})
}

type stop struct{}

// run calls OpenEngines the way a test would, stopping where the test would stop.
func run(f *fakeTB) (engines []Engine) {
	defer func() {
		if r := recover(); r != nil {
			if _, ok := r.(stop); !ok {
				panic(r)
			}
		}
	}()
	return OpenEngines(f)
}

func TestMySQLDSN_HasTheOptionsProductionUses(t *testing.T) {
	t.Setenv("MYSQL_USER", "u")
	t.Setenv("MYSQL_PASSWORD", "p")
	t.Setenv("MYSQL_PROTOCOL", "tcp")
	t.Setenv("MYSQL_HOST", "h")
	t.Setenv("MYSQL_PORT", "3306")
	t.Setenv("MYSQL_DBNAME", "d")

	got := mysqlDSN()
	want := "u:p@tcp(h:3306)/d?charset=utf8mb4&parseTime=True&loc=Local&interpolateParams=true"
	if got != want {
		t.Fatalf("got %s want %s", got, want)
	}
}

func TestPgDSN_DefaultsToTheComposeServiceAndPinsUTC(t *testing.T) {
	for _, k := range []string{"PGSQL_HOST", "PGSQL_PORT", "PGSQL_USERNAME", "PGSQL_PASSWORD", "PGSQL_DATABASE"} {
		t.Setenv(k, "")
	}
	got := pgDSN()
	for _, part := range []string{"postgres://root:iznik@postgres:5432/iznik_go_test", "TimeZone=UTC", "sslmode=disable"} {
		if !strings.Contains(got, part) {
			t.Fatalf("%s should contain %s", got, part)
		}
	}

	t.Setenv("PGSQL_HOST", "db")
	t.Setenv("PGSQL_DATABASE", "other")
	if got := pgDSN(); !strings.Contains(got, "@db:5432/other") {
		t.Fatalf("the environment should win: %s", got)
	}
}

func TestOpenEngines_ASingleEngineNeverFallsBackToTheOther(t *testing.T) {
	t.Setenv("PARITY_ENGINES", "bogus")
	f := &fakeTB{}
	run(f)
	if !f.failed || !strings.Contains(f.message, "PARITY_ENGINES") {
		t.Fatalf("an unknown value must fail, got %+v", f)
	}
}

func TestOpenEngines_NoMySQLRunsOnPostgresAlone(t *testing.T) {
	t.Setenv("PARITY_ENGINES", "")
	t.Setenv("PG_ENGINE", "")
	opened = map[Dialect]*openResult{
		MySQL:    {err: errNotThere},
		Postgres: {engine: &Engine{Name: "postgres", Dialect: Postgres}},
	}
	t.Cleanup(func() { opened = map[Dialect]*openResult{} })
	f := &fakeTB{}
	engines := run(f)
	if f.skipped || f.failed || len(engines) != 1 || engines[0].Dialect != Postgres {
		t.Fatalf("with no MySQL the run is on PostgreSQL alone, got %+v and %+v", engines, f)
	}
}

func TestOpenEngines_PgEngineOneFailsWhenMySQLIsNotReachable(t *testing.T) {
	t.Setenv("PARITY_ENGINES", "")
	t.Setenv("PG_ENGINE", "1")
	opened = map[Dialect]*openResult{
		MySQL:    {err: errNotThere},
		Postgres: {engine: &Engine{Name: "postgres", Dialect: Postgres}},
	}
	t.Cleanup(func() { opened = map[Dialect]*openResult{} })
	f := &fakeTB{}
	run(f)
	if !f.failed || !strings.Contains(f.message, "MySQL is not reachable") {
		t.Fatalf("PG_ENGINE=1 means both engines are compared, so a missing MySQL fails, got %+v", f)
	}
}

func TestOpenEngines_NoEngineAtAllFails(t *testing.T) {
	t.Setenv("PARITY_ENGINES", "")
	t.Setenv("PG_ENGINE", "")
	opened = map[Dialect]*openResult{MySQL: {err: errNotThere}, Postgres: {err: errNotThere}}
	t.Cleanup(func() { opened = map[Dialect]*openResult{} })
	f := &fakeTB{}
	run(f)
	if !f.failed || !strings.Contains(f.message, "neither engine") {
		t.Fatalf("with no engine there is nothing to run on, so it fails, got %+v", f)
	}
}

func TestOpenEngines_PgEngineZeroRunsOnMySQLAloneEvenWhenPostgresIsThere(t *testing.T) {
	t.Setenv("PARITY_ENGINES", "")
	t.Setenv("PG_ENGINE", "0")
	opened = map[Dialect]*openResult{
		MySQL:    {engine: &Engine{Name: "mysql", Dialect: MySQL}},
		Postgres: {engine: &Engine{Name: "postgres", Dialect: Postgres}},
	}
	t.Cleanup(func() { opened = map[Dialect]*openResult{} })
	f := &fakeTB{}
	engines := run(f)
	if f.skipped || f.failed || len(engines) != 1 || engines[0].Dialect != MySQL {
		t.Fatalf("PG_ENGINE=0 must run on MySQL alone, got %+v and %+v", engines, f)
	}
}

func TestOpenEngines_PgEngineOneFailsWhenPostgresIsNotReachable(t *testing.T) {
	t.Setenv("PARITY_ENGINES", "")
	t.Setenv("PG_ENGINE", "1")
	opened = map[Dialect]*openResult{
		MySQL:    {engine: &Engine{Name: "mysql", Dialect: MySQL}},
		Postgres: {err: errNotThere},
	}
	t.Cleanup(func() { opened = map[Dialect]*openResult{} })
	f := &fakeTB{}
	run(f)
	if !f.failed || !strings.Contains(f.message, "PG_ENGINE=1") {
		t.Fatalf("PG_ENGINE=1 must turn the skip into a failure, got %+v", f)
	}

	t.Setenv("PG_ENGINE", "")
	f = &fakeTB{}
	engines := run(f)
	if f.skipped || f.failed || len(engines) != 1 || engines[0].Dialect != MySQL {
		t.Fatalf("without PG_ENGINE an unreachable PostgreSQL leaves MySQL alone, got %+v and %+v", engines, f)
	}
}

type notThere struct{}

func (notThere) Error() string { return "not there" }

var errNotThere error = notThere{}
