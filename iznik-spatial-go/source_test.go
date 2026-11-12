package main

import (
	"database/sql"
	"net/url"
	"strings"
	"testing"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// envMap makes a getenv that reads from a map, with the caller's default for anything missing,
// as main's getenv does for an unset or empty variable.
func envMap(m map[string]string) func(key, def string) string {
	return func(key, def string) string {
		if v := m[key]; v != "" {
			return v
		}
		return def
	}
}

func TestSourceEngine(t *testing.T) {
	cases := []struct {
		set     string
		want    string
		wantErr bool
	}{
		{"", engineMySQL, false},
		{"mysql", engineMySQL, false},
		{"postgres", enginePostgres, false},
		{" Postgres ", enginePostgres, false},
		{"MYSQL", engineMySQL, false},
		{"pgsql", "", true},
		{"sqlite", "", true},
	}
	for _, c := range cases {
		got, err := sourceEngine(envMap(map[string]string{"SPATIAL_SOURCE_ENGINE": c.set}))
		if c.wantErr {
			require.Error(t, err, c.set)
			assert.Contains(t, err.Error(), "SPATIAL_SOURCE_ENGINE")
			continue
		}
		require.NoError(t, err, c.set)
		assert.Equal(t, c.want, got, c.set)
	}
}

// With nothing set the source is MySQL on the DSN the service has always used.
func TestMySQLSourceDSN_Defaults(t *testing.T) {
	assert.Equal(t, "iznik:iz@tcp(localhost:3306)/iznik?parseTime=true", mysqlSourceDSN(envMap(nil)))
	assert.Equal(t, "root:pw@tcp(percona:3307)/other?parseTime=true", mysqlSourceDSN(envMap(map[string]string{
		"MYSQL_USER": "root", "MYSQL_PASSWORD": "pw", "MYSQL_HOST": "percona", "MYSQL_PORT": "3307", "MYSQL_DBNAME": "other",
	})))
}

// The defaults for PostgreSQL point at the compose service, and a password with URL syntax in it
// is escaped rather than breaking the URL.
func TestPostgresSourceURL(t *testing.T) {
	u, err := url.Parse(postgresSourceURL(envMap(nil)))
	require.NoError(t, err)
	assert.Equal(t, "postgres", u.Scheme)
	assert.Equal(t, "postgres:5432", u.Host)
	assert.Equal(t, "/iznik", u.Path)
	pw, _ := u.User.Password()
	assert.Equal(t, "root", u.User.Username())
	assert.Equal(t, "iznik", pw)

	u, err = url.Parse(postgresSourceURL(envMap(map[string]string{
		"PGSQL_HOST": "db.example.test", "PGSQL_PORT": "6543", "PGSQL_DATABASE": "spatial",
		"PGSQL_USERNAME": "reader", "PGSQL_PASSWORD": "p@ss:/w?rd#",
	})))
	require.NoError(t, err)
	assert.Equal(t, "db.example.test:6543", u.Host)
	assert.Equal(t, "/spatial", u.Path)
	pw, _ = u.User.Password()
	assert.Equal(t, "reader", u.User.Username())
	assert.Equal(t, "p@ss:/w?rd#", pw)

	// pgx must accept what was built, whatever the password holds.
	cfg, err := pgx.ParseConfig(postgresSourceURL(envMap(map[string]string{"PGSQL_PASSWORD": "p@ss:/w?rd#"})))
	require.NoError(t, err)
	assert.Equal(t, "p@ss:/w?rd#", cfg.Password)
}

func TestOpenSource_RejectsAnUnknownEngine(t *testing.T) {
	_, err := openSource(envMap(map[string]string{"SPATIAL_SOURCE_ENGINE": "oracle"}))
	require.Error(t, err)
}

// The dialect follows the handle: a pgx handle gets PostgreSQL's, a MySQL handle gets MySQL's,
// and anything else (the SQLite fake the drift tests use) gets MySQL's, as before.
func TestDialectOf(t *testing.T) {
	assert.Equal(t, engineMySQL, dialectOf(nil).engine())

	my, err := openSource(envMap(nil))
	require.NoError(t, err)
	defer my.Close()
	assert.Equal(t, engineMySQL, dialectOf(my).engine())

	pg, err := openSource(envMap(map[string]string{"SPATIAL_SOURCE_ENGINE": "postgres"}))
	require.NoError(t, err)
	defer pg.Close()
	assert.Equal(t, enginePostgres, dialectOf(pg).engine())

	lite, err := sql.Open("sqlite", ":memory:")
	require.NoError(t, err)
	defer lite.Close()
	assert.Equal(t, engineMySQL, dialectOf(lite).engine())

	// A handle opened straight from a pgx config, as a test might, is recognised too.
	cfg, err := pgx.ParseConfig("postgres://u:p@localhost:5432/d")
	require.NoError(t, err)
	direct := stdlib.OpenDB(*cfg)
	defer direct.Close()
	assert.Equal(t, enginePostgres, dialectOf(direct).engine())
}

func TestPostgresRebind(t *testing.T) {
	d := postgresDialect{}
	cases := []struct{ in, want string }{
		{"SELECT 1", "SELECT 1"},
		{"SELECT a FROM t WHERE x = ?", "SELECT a FROM t WHERE x = $1"},
		{"WHERE a > ? AND b < ? AND c = ?", "WHERE a > $1 AND b < $2 AND c = $3"},
		// A ? in a string literal is text, not a placeholder.
		{"WHERE note = 'why?' AND x = ?", "WHERE note = 'why?' AND x = $1"},
		{"WHERE note = 'it''s ?' AND x = ?", "WHERE note = 'it''s ?' AND x = $1"},
		{"jsonb_path_query_first(c, ?::text::jsonpath) #>> '{}'", "jsonb_path_query_first(c, $1::text::jsonpath) #>> '{}'"},
	}
	for _, c := range cases {
		assert.Equal(t, c.want, d.rebind(c.in), c.in)
	}
	// MySQL's placeholders are its own.
	assert.Equal(t, "x = ?", mysqlDialect{}.rebind("x = ?"))
}

// The lane paths bind as parameters, in lane order, with one extraction per lane, on both engines.
func TestOverflowSelectFor_PostgresShape(t *testing.T) {
	cols, args := overflowSelectFor(postgresDialect{})
	lanes := overflowLaneOrder()
	require.Len(t, args, len(lanes))
	for i, lane := range lanes {
		assert.Equal(t, lane, args[i])
	}
	want := strings.TrimSuffix(strings.Repeat("jsonb_path_query_first(overflow_cells, ?::text::jsonpath) #>> '{}', ", len(lanes)), ", ")
	assert.Equal(t, want, cols)

	// And the whole statement numbers its placeholders in order after rebinding.
	q := postgresDialect{}.rebind("SELECT msgid, status, " + cols + " FROM rippling_reach WHERE has_overflow = 1 AND updated_at > ?")
	assert.Contains(t, q, "$10::text::jsonpath")
	assert.True(t, strings.HasSuffix(q, "updated_at > $11"), q)
}

// The postcode loader's PostgreSQL text differs from MySQL's only in the pieces the dialect owns.
func TestDialectSpellings(t *testing.T) {
	my, pg := mysqlDialect{}, postgresDialect{}

	assert.Equal(t, "ST_AsWKB(g)", my.asWKB("g"))
	assert.Equal(t, "ST_AsBinary(g)", pg.asWKB("g"))
	assert.Equal(t, "`groups`", my.quote("groups"))
	assert.Equal(t, `"groups"`, pg.quote("groups"))
	assert.Equal(t, "LOCATE(' ', n)", my.strpos("' '", "n"))
	assert.Equal(t, "strpos(n, ' ')", pg.strpos("' '", "n"))
	assert.Equal(t, "", my.notEmptyGuard("g"))
	assert.Contains(t, pg.notEmptyGuard("g"), "NOT ST_IsEmpty(g)")
	assert.Equal(t, "DATABASE()", my.currentSchema())
	assert.Equal(t, "current_schema()", pg.currentSchema())
	assert.Equal(t, "SELECT CAST(MAX(seenat) AS CHAR) FROM jobs", my.maxSeenat())
	assert.Contains(t, pg.maxSeenat(), "to_char(MAX(seenat), 'YYYY-MM-DD HH24:MI:SS')")
}
