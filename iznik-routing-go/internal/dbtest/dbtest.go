// Package dbtest gives tests a scratch database on either engine.
//
// Each call to Open creates a database of its own on the configured server, gives it the tables the
// routing service reads and writes, hands back a connection pool and drops the database when the test
// ends. Nothing in the server's real databases is touched.
//
// Servers come from the same settings the service reads (MYSQL_* and PGSQL_*). Where a setting is
// missing the default is the compose service: host percona or postgres, user root, password iznik.
//
//   - MySQL: the scratch tables are copies of the tables in the server's MYSQL_DBNAME database
//     (default iznik), made with CREATE TABLE ... LIKE, so they have the real column types, SRIDs and
//     spatial indexes.
//   - PostgreSQL: the scratch tables come from pg_schema.sql, which scripts/pg-test-schema.mjs copies
//     from the generated baseline.
//
// ROUTING_TEST_DB_PREFIX changes the "routing_go_test" prefix of the scratch database names.
//
// When the server cannot be reached the test is skipped, unless ROUTING_REQUIRE_DB names the engine
// (mysql, postgres, or all): then it fails. A CI job that is meant to run an engine sets that, so a
// missing server cannot pass as a skip.
package dbtest

import (
	"context"
	"crypto/rand"
	"database/sql"
	_ "embed"
	"encoding/hex"
	"fmt"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-routing-go/dbengine"
)

//go:embed pg_schema.sql
var pgSchema string

// PGSchema is the PostgreSQL schema the scratch databases are built from.
func PGSchema() string { return pgSchema }

// Tables are the tables a scratch database holds.
var Tables = []string{
	"chat_messages", "config", "groups", "locations", "memberships", "messages",
	"messages_attachments", "messages_groups", "messages_likes", "messages_outcomes",
	"messages_spatial", "rippling_reach", "rippling_reach_leaves", "sessions",
	"transport_postcode_classification", "users", "users_approxlocs",
}

// Engines is every engine the tests run on.
var Engines = []dbengine.Engine{dbengine.MySQL, dbengine.Postgres}

func required(e dbengine.Engine) bool {
	for _, w := range strings.Split(strings.ToLower(os.Getenv("ROUTING_REQUIRE_DB")), ",") {
		w = strings.TrimSpace(w)
		if w == "all" || w == "1" || w == string(e) {
			return true
		}
	}
	return false
}

func settings(e dbengine.Engine) dbengine.Settings {
	return dbengine.SettingsFromEnv(e, dbengine.Settings{
		Host:     map[dbengine.Engine]string{dbengine.MySQL: "percona", dbengine.Postgres: "postgres"}[e],
		User:     "root",
		Password: "iznik",
	})
}

func open(e dbengine.Engine, s dbengine.Settings) (*sql.DB, error) {
	return sql.Open(e.Driver(), e.DSN(s, dbengine.DSNOptions{ParseTime: true}))
}

func quote(e dbengine.Engine, name string) string {
	if e == dbengine.MySQL {
		return "`" + name + "`"
	}
	return `"` + name + `"`
}

// Quote quotes an identifier for the engine.
func Quote(e dbengine.Engine, name string) string { return quote(e, name) }

// Scratch is a scratch database.
type Scratch struct {
	Engine   dbengine.Engine
	DB       *sql.DB
	Settings dbengine.Settings
}

// SetEnv points the service's own settings (DB_ENGINE and MYSQL_* or PGSQL_*) at the scratch
// database for the rest of the test, for code that opens its own connection from the environment.
func (s *Scratch) SetEnv(t testing.TB) {
	t.Helper()
	t.Setenv(dbengine.EnvEngine, string(s.Engine))
	if s.Engine == dbengine.Postgres {
		t.Setenv("PGSQL_HOST", s.Settings.Host)
		t.Setenv("PGSQL_PORT", s.Settings.Port)
		t.Setenv("PGSQL_USERNAME", s.Settings.User)
		t.Setenv("PGSQL_PASSWORD", s.Settings.Password)
		t.Setenv("PGSQL_DATABASE", s.Settings.Name)
		return
	}
	t.Setenv("MYSQL_HOST", s.Settings.Host)
	t.Setenv("MYSQL_PORT", s.Settings.Port)
	t.Setenv("MYSQL_USER", s.Settings.User)
	t.Setenv("MYSQL_PASSWORD", s.Settings.Password)
	t.Setenv("MYSQL_DBNAME", s.Settings.Name)
}

// Geom is a geometry value for Insert, given as WKT in degrees. It is stored with the engine's SRID
// for the column: 3857 on MySQL and 4326 on PostgreSQL.
type Geom string

// Insert adds one row. Identifiers are quoted for the engine, ? placeholders are rebound, and a Geom
// becomes ST_GeomFromText(?, srid).
func (s *Scratch) Insert(t testing.TB, table string, cols []string, vals ...any) {
	t.Helper()
	if len(cols) != len(vals) {
		t.Fatalf("insert into %s: %d columns, %d values", table, len(cols), len(vals))
	}
	qc := make([]string, len(cols))
	ph := make([]string, len(cols))
	args := make([]any, 0, len(vals))
	srid := 3857
	if s.Engine == dbengine.Postgres {
		srid = 4326
	}
	for i, c := range cols {
		qc[i] = quote(s.Engine, c)
		if g, ok := vals[i].(Geom); ok {
			ph[i] = fmt.Sprintf("ST_GeomFromText(?, %d)", srid)
			args = append(args, string(g))
			continue
		}
		ph[i] = "?"
		args = append(args, vals[i])
	}
	q := fmt.Sprintf("INSERT INTO %s (%s) VALUES (%s)", quote(s.Engine, table), strings.Join(qc, ", "), strings.Join(ph, ", "))
	if _, err := s.DB.Exec(s.Engine.Rebind(q), args...); err != nil {
		t.Fatalf("insert into %s: %v", table, err)
	}
}

// Exec runs a statement written with ? placeholders.
func (s *Scratch) Exec(t testing.TB, q string, args ...any) {
	t.Helper()
	if _, err := s.DB.Exec(s.Engine.Rebind(q), args...); err != nil {
		t.Fatalf("%s: %v", q, err)
	}
}

// Open creates a scratch database on engine e and returns it. The caller must have set DB_ENGINE to
// e (the service's SQL follows that setting); Run does both.
func Open(t testing.TB, e dbengine.Engine) *Scratch {
	t.Helper()
	adminSettings := settings(e)
	if e == dbengine.Postgres {
		adminSettings.Name = "postgres"
	} else {
		adminSettings.Name = ""
	}
	admin, err := open(e, adminSettings)
	if err != nil {
		t.Fatalf("open %s: %v", e.Label(), err)
	}
	t.Cleanup(func() { admin.Close() })
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := admin.PingContext(ctx); err != nil {
		msg := fmt.Sprintf("%s is not reachable at %s:%s (%v)", e.Label(), adminSettings.Host, adminSettings.Port, err)
		if required(e) {
			t.Fatal(msg)
		}
		t.Skip(msg + "; set ROUTING_REQUIRE_DB to make this a failure")
	}

	suffix := make([]byte, 4)
	if _, err := rand.Read(suffix); err != nil {
		t.Fatal(err)
	}
	prefix := os.Getenv("ROUTING_TEST_DB_PREFIX")
	if prefix == "" {
		prefix = "routing_go_test"
	}
	name := fmt.Sprintf("%s_%s_%s", prefix, e, hex.EncodeToString(suffix))
	if _, err := admin.Exec("CREATE DATABASE " + quote(e, name)); err != nil {
		t.Fatalf("create %s database: %v", e.Label(), err)
	}
	t.Cleanup(func() {
		drop := "DROP DATABASE IF EXISTS " + quote(e, name)
		if e == dbengine.Postgres {
			drop += " WITH (FORCE)"
		}
		if _, err := admin.Exec(drop); err != nil {
			t.Errorf("drop scratch database %s: %v", name, err)
		}
	})

	scratch := adminSettings
	scratch.Name = name
	db, err := open(e, scratch)
	if err != nil {
		t.Fatalf("open scratch %s database: %v", e.Label(), err)
	}
	t.Cleanup(func() { db.Close() })
	db.SetMaxOpenConns(8)

	switch e {
	case dbengine.Postgres:
		// No arguments, so pgx sends this as one simple-protocol message and every statement runs.
		if _, err := db.Exec(pgSchema); err != nil {
			t.Fatalf("load the PostgreSQL schema: %v", err)
		}
	default:
		src := settings(e).Name
		for _, tbl := range Tables {
			q := fmt.Sprintf("CREATE TABLE %s.%s LIKE %s.%s", quote(e, name), quote(e, tbl), quote(e, src), quote(e, tbl))
			if _, err := admin.Exec(q); err != nil {
				t.Fatalf("copy table %s from %s: %v", tbl, src, err)
			}
		}
	}
	return &Scratch{Engine: e, DB: db, Settings: scratch}
}

// Run runs fn as a subtest on each engine, with DB_ENGINE set to it and a scratch database open.
func Run(t *testing.T, fn func(t *testing.T, s *Scratch)) {
	t.Helper()
	for _, e := range Engines {
		e := e
		t.Run(string(e), func(t *testing.T) {
			t.Setenv(dbengine.EnvEngine, string(e))
			fn(t, Open(t, e))
		})
	}
}
