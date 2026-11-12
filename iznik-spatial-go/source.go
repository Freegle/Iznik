package main

import (
	"database/sql"
	"fmt"
	"net"
	"net/url"
	"strings"

	_ "github.com/go-sql-driver/mysql"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
)

// The loaders read their rows from one source database, which is MySQL unless
// SPATIAL_SOURCE_ENGINE says otherwise. The index itself is always the SQLite R-tree; only the
// SELECTs that fill it differ by engine.
//
// Each engine is a sourceDialect: the small set of spellings the loaders need that are not
// portable (geometry to WKB, identifier quoting, a JSON path extraction and so on). The loaders
// build their statements from those pieces, so the shape of every query is written once and the
// MySQL text stays exactly what it was (source_transcript_test.go pins it).
//
// The dialect follows the handle. dialectOf looks at the driver behind a *sql.DB, so a loader
// can never be given a PostgreSQL handle and MySQL SQL. Any driver that is not pgx, including
// the SQLite fake the drift tests use, gets the MySQL dialect: those queries are portable SQL
// that happens to be MySQL-shaped.

const (
	engineMySQL    = "mysql"
	enginePostgres = "postgres"
)

// sourceDialect holds the engine-specific spellings the loaders use.
type sourceDialect interface {
	// engine is the value of SPATIAL_SOURCE_ENGINE that selects this dialect.
	engine() string

	// rebind turns the portable ? placeholders the loaders write into the engine's own.
	rebind(query string) string

	// quote quotes an identifier that is a reserved word, such as the groups table.
	quote(name string) string

	// asWKB wraps a geometry expression so the driver returns plain OGC WKB.
	asWKB(geomExpr string) string

	// strpos is the 1-based position of needle in haystack, 0 when absent.
	strpos(needle, haystack string) string

	// notEmptyGuard is an extra AND clause, with its own leading whitespace, that keeps empty
	// geometries out of a query that reads a coordinate from them. Empty for an engine where
	// that read cannot fail.
	notEmptyGuard(geomExpr string) string

	// currentSchema is the expression for the schema the connection reads from, to compare with
	// information_schema.columns.table_schema.
	currentSchema() string

	// maxSeenat is a query returning MAX(jobs.seenat) as text in the fixed form
	// "YYYY-MM-DD HH:MM:SS", so that it compares in time order as a string.
	maxSeenat() string

	// jsonText extracts the JSON path given by the placeholder from a JSON column as unquoted
	// text. A missing path gives NULL. The path syntax is shared: both engines take "$.a.b" and
	// `$.a."1"`.
	jsonText(col, placeholder string) string
}

// dialectOf returns the dialect that matches the handle's driver.
func dialectOf(db *sql.DB) sourceDialect {
	if db != nil {
		if _, ok := db.Driver().(*stdlib.Driver); ok {
			return postgresDialect{}
		}
	}
	return mysqlDialect{}
}

// srcQuery runs a query on the source database. The query is written with ? placeholders.
func srcQuery(db *sql.DB, query string, args ...any) (*sql.Rows, error) {
	return db.Query(dialectOf(db).rebind(query), args...)
}

// srcQueryRow is srcQuery for a query that returns one row.
func srcQueryRow(db *sql.DB, query string, args ...any) *sql.Row {
	return db.QueryRow(dialectOf(db).rebind(query), args...)
}

// sourceEngine reads SPATIAL_SOURCE_ENGINE. Empty means MySQL.
func sourceEngine(getenv func(key, def string) string) (string, error) {
	switch e := strings.ToLower(strings.TrimSpace(getenv("SPATIAL_SOURCE_ENGINE", engineMySQL))); e {
	case engineMySQL, enginePostgres:
		return e, nil
	default:
		return "", fmt.Errorf("SPATIAL_SOURCE_ENGINE=%q: want %q or %q", e, engineMySQL, enginePostgres)
	}
}

// mysqlSourceDSN is the DSN for the MySQL source, from the MYSQL_* variables.
func mysqlSourceDSN(getenv func(key, def string) string) string {
	return fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true",
		getenv("MYSQL_USER", "iznik"),
		getenv("MYSQL_PASSWORD", "iz"),
		getenv("MYSQL_HOST", "localhost"),
		getenv("MYSQL_PORT", "3306"),
		getenv("MYSQL_DBNAME", "iznik"),
	)
}

// postgresSourceURL is the connection URL for the PostgreSQL source, from the PGSQL_* variables
// the Laravel connection and the rest of the PostgreSQL tooling read. The defaults point at the
// compose service. Anything else libpq-style (PGSSLMODE and friends) is read by pgx itself.
func postgresSourceURL(getenv func(key, def string) string) string {
	u := url.URL{
		Scheme: "postgres",
		User:   url.UserPassword(getenv("PGSQL_USERNAME", "root"), getenv("PGSQL_PASSWORD", "iznik")),
		Host:   net.JoinHostPort(getenv("PGSQL_HOST", "postgres"), getenv("PGSQL_PORT", "5432")),
		Path:   "/" + getenv("PGSQL_DATABASE", "iznik"),
	}
	return u.String()
}

// openSource opens the source database the loaders read.
func openSource(getenv func(key, def string) string) (*sql.DB, error) {
	engine, err := sourceEngine(getenv)
	if err != nil {
		return nil, err
	}
	if engine == enginePostgres {
		cfg, err := pgx.ParseConfig(postgresSourceURL(getenv))
		if err != nil {
			return nil, fmt.Errorf("postgres source config: %w", err)
		}
		// timestamptz columns are written and read as UTC, and the loaders compare them with
		// UTC literals and parameters.
		cfg.RuntimeParams["timezone"] = "UTC"
		return stdlib.OpenDB(*cfg), nil
	}
	db, err := sql.Open("mysql", mysqlSourceDSN(getenv))
	if err != nil {
		return nil, fmt.Errorf("mysql open: %w", err)
	}
	return db, nil
}
