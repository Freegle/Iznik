package parity

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"gorm.io/driver/mysql"
	"gorm.io/driver/postgres"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"
)

// Engine is one database a Layer 2 test runs against.
type Engine struct {
	Name    string
	Dialect Dialect
	DB      *gorm.DB
}

// Environment. MySQL reads the variables the Go API reads, so a parity test runs against the same
// database as the rest of the suite. PostgreSQL reads the variables the Laravel connection and the
// routing spike read, with defaults that point at the compose service.
//
//	MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD, MYSQL_PROTOCOL, MYSQL_DBNAME
//	PGSQL_HOST (postgres), PGSQL_PORT (5432), PGSQL_USERNAME (root), PGSQL_PASSWORD (iznik),
//	PGSQL_DATABASE (iznik_go_test)
//	PG_ENGINE: unset, use PostgreSQL if it is reachable; 1, it must be; 0, skip it.
//	PARITY_ENGINES: unset, both engines; "postgres" or "mysql", that one alone. A single-engine run
//	checks that the site works there and gives the fixture's answer (Expect), and compares nothing.
//	It is for a developer who has one engine up. CI leaves it unset.
//
// With PostgreSQL absent a Layer 2 test is skipped, because there is nothing to compare. CI sets
// PG_ENGINE=1 once a job provides the engine, and the skip becomes a failure.
const (
	pgDefaultHost     = "postgres"
	pgDefaultPort     = "5432"
	pgDefaultUser     = "root"
	pgDefaultPassword = "iznik"
	pgDefaultDatabase = "iznik_go_test"
)

func getenv(key, def string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return def
}

// mysqlDSN has the options production uses, so the driver returns the same Go types.
func mysqlDSN() string {
	return fmt.Sprintf(
		"%s:%s@%s(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=Local&interpolateParams=true",
		os.Getenv("MYSQL_USER"),
		os.Getenv("MYSQL_PASSWORD"),
		os.Getenv("MYSQL_PROTOCOL"),
		os.Getenv("MYSQL_HOST"),
		os.Getenv("MYSQL_PORT"),
		os.Getenv("MYSQL_DBNAME"),
	)
}

// pgDSN pins the session time zone to UTC, as the baseline's timestamptz columns need.
func pgDSN() string {
	u := url.URL{
		Scheme: "postgres",
		User:   url.UserPassword(getenv("PGSQL_USERNAME", pgDefaultUser), getenv("PGSQL_PASSWORD", pgDefaultPassword)),
		Host:   getenv("PGSQL_HOST", pgDefaultHost) + ":" + getenv("PGSQL_PORT", pgDefaultPort),
		Path:   getenv("PGSQL_DATABASE", pgDefaultDatabase),
	}
	q := url.Values{}
	q.Set("sslmode", "disable")
	q.Set("TimeZone", "UTC")
	q.Set("connect_timeout", "5")
	u.RawQuery = q.Encode()
	return u.String()
}

var (
	enginesMu sync.Mutex
	opened    = map[Dialect]*openResult{}
)

type openResult struct {
	engine *Engine
	err    error
}

func openEngine(d Dialect) (*Engine, error) {
	enginesMu.Lock()
	defer enginesMu.Unlock()
	if r, ok := opened[d]; ok {
		return r.engine, r.err
	}

	var e *Engine
	var err error
	cfg := &gorm.Config{Logger: captureLogger{logger.Discard}}
	switch d {
	case MySQL:
		if os.Getenv("MYSQL_HOST") == "" {
			err = fmt.Errorf("MYSQL_HOST is not set")
			break
		}
		var db *gorm.DB
		if db, err = gorm.Open(mysql.Open(mysqlDSN()), cfg); err == nil {
			e = &Engine{Name: "mysql", Dialect: MySQL, DB: db}
		}
	case Postgres:
		var db *gorm.DB
		if db, err = gorm.Open(postgres.Open(pgDSN()), cfg); err == nil {
			e = &Engine{Name: "postgres", Dialect: Postgres, DB: db}
		}
	}
	if e != nil {
		if sqlDB, derr := e.DB.DB(); derr != nil {
			err = derr
		} else {
			ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
			defer cancel()
			if perr := sqlDB.PingContext(ctx); perr != nil {
				err = perr
			}
		}
	}
	if err != nil {
		e = nil
	}
	opened[d] = &openResult{engine: e, err: err}
	return e, err
}

// OpenEngines returns MySQL and PostgreSQL for a Layer 2 test, or skips it when an engine is not
// there. PG_ENGINE=1 turns the PostgreSQL skip into a failure.
func OpenEngines(tb testing.TB) []Engine {
	tb.Helper()

	switch only := os.Getenv("PARITY_ENGINES"); only {
	case "":
	case "postgres":
		pg, err := openEngine(Postgres)
		if err != nil {
			tb.Fatalf("parity: PARITY_ENGINES=postgres but PostgreSQL is not reachable: %v", err)
		}
		return []Engine{*pg}
	case "mysql":
		my, err := openEngine(MySQL)
		if err != nil {
			tb.Fatalf("parity: PARITY_ENGINES=mysql but MySQL is not reachable: %v", err)
		}
		return []Engine{*my}
	default:
		tb.Fatalf("parity: PARITY_ENGINES=%q: use postgres, mysql or leave it unset", only)
	}

	my, err := openEngine(MySQL)
	if err != nil {
		tb.Skipf("parity: MySQL is not available (%v)", err)
	}

	mode := os.Getenv("PG_ENGINE")
	if mode == "0" {
		tb.Skip("parity: PG_ENGINE=0, PostgreSQL is switched off")
	}
	pg, err := openEngine(Postgres)
	if err != nil {
		if mode == "1" {
			tb.Fatalf("parity: PG_ENGINE=1 but PostgreSQL is not reachable: %v", err)
		}
		tb.Skipf("parity: PostgreSQL is not available (%v). Set PG_ENGINE=1 to make this a failure", err)
	}
	return []Engine{*my, *pg}
}

// errorKey carries a recorder in a statement's context.
type errorKey struct{}

// errorRecorder collects the statements that failed during one run. A site that ignores the error
// from a query returns an empty result when a statement fails, and an empty result is also a
// legitimate answer, so the failure has to be seen where it happens.
type errorRecorder struct {
	mu     sync.Mutex
	errors []string
}

func (r *errorRecorder) add(sql string, err error) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.errors = append(r.errors, fmt.Sprintf("%v\n      in: %s", err, strings.TrimSpace(sql)))
}

func (r *errorRecorder) all() []string {
	r.mu.Lock()
	defer r.mu.Unlock()
	return append([]string(nil), r.errors...)
}

// captureLogger is the engine handle's logger. GORM reports every statement to Trace with the error
// it ended in, so a failure is seen whichever call path ran it.
type captureLogger struct{ logger.Interface }

func (l captureLogger) LogMode(logger.LogLevel) logger.Interface { return l }

func (l captureLogger) Trace(ctx context.Context, _ time.Time, fc func() (string, int64), err error) {
	if err == nil || errors.Is(err, gorm.ErrRecordNotFound) {
		return
	}
	if rec, ok := ctx.Value(errorKey{}).(*errorRecorder); ok {
		sql, _ := fc()
		rec.add(sql, err)
	}
}
