package database

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"fmt"
	"net/url"
	"os"
	"strconv"
	"strings"
	"sync/atomic"
	"time"

	sentrylogpackage "github.com/freegle/iznik-server-go/sentrylog"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	"gorm.io/driver/postgres"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"
	"gorm.io/plugin/dbresolver"
)

// The database engine is chosen by DB_ENGINE. Unset or "mysql" is MySQL, the default and what production
// runs. "postgres" reads the PGSQL_* settings instead (the names the Laravel pgsql connection and the
// routing service use). Any other value stops the process: it never falls back to the other engine.
const (
	EngineMySQL    = "mysql"
	EnginePostgres = "postgres"
)

// Engine returns the engine DB_ENGINE names, and panics on a value that names neither.
func Engine() string {
	switch strings.ToLower(strings.TrimSpace(os.Getenv("DB_ENGINE"))) {
	case "", EngineMySQL:
		return EngineMySQL
	case EnginePostgres:
		return EnginePostgres
	default:
		panic("database: DB_ENGINE must be mysql or postgres, got " + os.Getenv("DB_ENGINE"))
	}
}

// StatementNow is the SQL for the time the current statement started. That is what MySQL's NOW() means.
// PostgreSQL's NOW() is the time the transaction started, so inside a transaction it can be well before a
// time the application took a moment ago; statement_timestamp() is its clock for the statement. Use this
// where a time written inside a transaction is later compared with a time from another clock.
func StatementNow() string {
	if Engine() == EnginePostgres {
		return "statement_timestamp()"
	}
	return "NOW()"
}

func pgEnv(name, def string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return def
}

// buildPostgresDSN builds the connection URL for a host. The write host and a read replica share every
// other part. The session time zone is UTC, because the baseline's timestamp columns are timestamptz and
// its datetime columns are timestamp without a zone, and both must mean what they meant on MySQL.
//
// JIT compilation is turned off for the API's sessions. The API's statements are short and each is planned
// afresh, so nothing compiled is ever reused; when a feed statement's estimated cost crosses the server's
// jit_above_cost, PostgreSQL spends 30 to 200 ms compiling it on every call, often more than it saves.
// PGSQL_JIT=on turns it back on, and PGSQL_JIT=server sends nothing and leaves the server's own setting,
// which is what a connection pooler that refuses startup parameters it does not know needs.
//
// A statement that waits for a lock gives up after 50 seconds, as InnoDB does by default
// (innodb_lock_wait_timeout). PostgreSQL's own default is to wait for ever, so one stuck writer would hold
// a pool connection for each request queued behind it. PGSQL_LOCK_TIMEOUT changes the wait (PostgreSQL
// syntax: 50s, 200ms, 0 for no limit), and "server" sends nothing.
func buildPostgresDSN(host string) string {
	q := url.Values{}
	q.Set("sslmode", pgEnv("PGSQL_SSLMODE", "prefer"))
	q.Set("TimeZone", "UTC")
	if jit := strings.ToLower(pgEnv("PGSQL_JIT", "off")); jit != "server" {
		q.Set("jit", jit)
	}
	if lt := strings.ToLower(pgEnv("PGSQL_LOCK_TIMEOUT", pgDefaultLockTimeout)); lt != "server" {
		q.Set("lock_timeout", lt)
	}
	u := url.URL{
		Scheme:   "postgres",
		User:     url.UserPassword(pgEnv("PGSQL_USERNAME", "root"), os.Getenv("PGSQL_PASSWORD")),
		Host:     host + ":" + pgEnv("PGSQL_PORT", "5432"),
		Path:     "/" + pgEnv("PGSQL_DATABASE", "iznik"),
		RawQuery: q.Encode(),
	}
	return u.String()
}

// pgDefaultLockTimeout is how long a statement waits for a lock before it fails with SQLSTATE 55P03.
const pgDefaultLockTimeout = "50s"

// Pool sizes for PostgreSQL: the same 200 per pool as MySQL (database.go), so a burst that MySQL serves is
// served here too and does not queue. A PostgreSQL connection is a whole server process, and a stock server
// accepts only 100 of them, so the server's max_connections must be raised to cover the write pool, the
// replica pool and the batch jobs (docs/developers/reference/database-engines.md). PGSQL_MAX_OPEN_CONNS and
// PGSQL_MAX_IDLE_CONNS override the sizes, per pool. A request that finds the pool full waits for a
// connection, it does not fail.
const (
	pgDefaultMaxOpenConns = 200
	pgDefaultMaxIdleConns = 200
)

// pgPoolSizes returns the most connections a pool may open and the most it may keep idle. By default
// the two are equal: a pool that closes backends as load dips and opens them again pays for a new
// PostgreSQL process and an empty statement cache each time. A value that is not a positive number is
// ignored, and idle is never more than open.
func pgPoolSizes() (maxOpen, maxIdle int) {
	maxOpen = pgEnvInt("PGSQL_MAX_OPEN_CONNS", pgDefaultMaxOpenConns)
	maxIdle = pgEnvInt("PGSQL_MAX_IDLE_CONNS", pgDefaultMaxIdleConns)
	if maxIdle > maxOpen {
		maxIdle = maxOpen
	}
	return maxOpen, maxIdle
}

func pgEnvInt(name string, def int) int {
	if n, err := strconv.Atoi(strings.TrimSpace(os.Getenv(name))); err == nil && n > 0 {
		return n
	}
	return def
}

// pgConnConfig parses a DSN and sets how pgx sends statements.
//
// pgx's default (cache_statement) prepares a named statement on the server for every distinct SQL text
// and keeps 512 of them per connection. Many of the API's statements have ids pasted into the text (the
// chat list, the browse feeds), so each member's request is a new text, and each one held 200 to 600 kB
// of server memory until it fell out of the cache: tens of MB per connection that MySQL never spends,
// because its driver interpolates parameters and keeps nothing on the server.
//
// cache_describe keeps nothing on the server. The first time a connection sees a text it asks the server
// for the parameter and result types through the unnamed statement, and remembers only that description
// in the Go process. Every execution is then one round trip, planned with its real values, as on MySQL.
// The parameter types still come from the server, which the lenient codecs in pgtypes.go depend on
// (a Go bool for a smallint, a number for a text column); the exec and simple_protocol modes guess the
// type from the Go value and would break them.
//
// A remembered description can go stale when a migration changes a column's type or the columns of a
// SELECT *. pgx drops the description when a query fails, so the next call asks again; the default mode
// had the same one-off failure.
func pgConnConfig(dsn string) (*pgx.ConnConfig, error) {
	cfg, err := pgx.ParseConfig(dsn)
	if err != nil {
		return nil, err
	}
	cfg.DefaultQueryExecMode = pgx.QueryExecModeCacheDescribe
	return cfg, nil
}

// newPostgresFailoverDialector is the PostgreSQL twin of newFailoverDialector: the replica connector
// falls back to the source for a retry window after a failed dial.
func newPostgresFailoverDialector(replicaDSN, sourceDSN string) gorm.Dialector {
	rc, err := pgConnConfig(replicaDSN)
	if err != nil {
		panic("database: failed to parse replica DSN for failover: " + err.Error())
	}
	rc.ConnectTimeout = 2 * time.Second
	sc, err := pgConnConfig(sourceDSN)
	if err != nil {
		panic("database: failed to parse source DSN for failover: " + err.Error())
	}
	fc := &pgFailoverConnector{
		primary:    stdlib.GetConnector(*rc, stdlib.OptionAfterConnect(registerLenientTypes)),
		fallback:   stdlib.GetConnector(*sc, stdlib.OptionAfterConnect(registerLenientTypes)),
		retryAfter: 30 * time.Second,
	}
	return postgres.New(postgres.Config{Conn: sql.OpenDB(newPgTranslatingConnector(fc))})
}

type pgFailoverConnector struct {
	primary    driver.Connector
	fallback   driver.Connector
	retryAfter time.Duration
	lastFail   atomic.Int64
	loggedDown atomic.Bool
}

func (f *pgFailoverConnector) Connect(ctx context.Context) (driver.Conn, error) {
	now := time.Now()
	last := f.lastFail.Load()
	if last != 0 && now.Sub(time.Unix(0, last)) < f.retryAfter {
		return f.fallback.Connect(ctx)
	}
	conn, err := f.primary.Connect(ctx)
	if err == nil {
		f.lastFail.Store(0)
		f.loggedDown.Store(false)
		return conn, nil
	}
	f.lastFail.Store(now.UnixNano())
	if f.loggedDown.CompareAndSwap(false, true) {
		fmt.Printf("read replica unavailable, using source: %v\n", err)
	}
	return f.fallback.Connect(ctx)
}

func (f *pgFailoverConnector) Driver() driver.Driver { return f.primary.Driver() }

// initPostgres is InitDatabase for DB_ENGINE=postgres. Pool stays nil: it is the MySQL driver's
// cancellable-query handle, and on PostgreSQL a cancelled context cancels the query through the
// driver. Reads go to PGSQL_HOST_READ when it is set, as MYSQL_HOST_READ does on MySQL, with the same
// failover to the source.
func initPostgres() {
	writeHost := os.Getenv("PGSQL_HOST")
	readHost := os.Getenv("PGSQL_HOST_READ")

	fmt.Println("Connecting to PostgreSQL", writeHost, pgEnv("PGSQL_PORT", "5432"), pgEnv("PGSQL_DATABASE", "iznik"), pgEnv("PGSQL_USERNAME", "root"))

	newLogger := sentrylogpackage.New(
		sentrylogpackage.Config(logger.Config{
			SlowThreshold:             time.Second * 30,
			LogLevel:                  logger.Warn,
			IgnoreRecordNotFoundError: true,
		}),
	)

	maxOpen, maxIdle := pgPoolSizes()

	// Built into a local and published last, for the reason given in InitDatabase.
	db, err := gorm.Open(newPostgresDialector(buildPostgresDSN(writeHost)), &gorm.Config{
		Logger: newLogger,
	})
	if err != nil {
		panic("failed to connect database: " + err.Error())
	}

	if readHost != "" {
		fmt.Println("Read replica enabled at", readHost)
		resolverErr := db.Use(
			dbresolver.Register(dbresolver.Config{
				Replicas: []gorm.Dialector{newPostgresFailoverDialector(buildPostgresDSN(readHost), buildPostgresDSN(writeHost))},
				Policy:   dbresolver.RandomPolicy{},
			}).
				SetMaxOpenConns(maxOpen).
				SetMaxIdleConns(maxIdle).
				SetConnMaxLifetime(5 * time.Minute),
		)
		if resolverErr != nil {
			panic("failed to register read replica: " + resolverErr.Error())
		}
	}

	RegisterCustomClauseBuilders(db)

	dbConfig, err := db.DB()
	if err != nil {
		panic("failed to get database config: " + err.Error())
	}
	dbConfig.SetMaxOpenConns(maxOpen)
	dbConfig.SetMaxIdleConns(maxIdle)
	dbConfig.SetConnMaxLifetime(time.Hour)

	DBConn = db
	Pool = nil
}

// newPostgresDialector opens a PostgreSQL connection pool whose statements are translated from MySQL
// syntax (pgtranslate.go).
func newPostgresDialector(dsn string) gorm.Dialector {
	cfg, err := pgConnConfig(dsn)
	if err != nil {
		panic("database: failed to parse DSN: " + err.Error())
	}
	return postgres.New(postgres.Config{Conn: sql.OpenDB(newPgTranslatingConnector(stdlib.GetConnector(*cfg, stdlib.OptionAfterConnect(registerLenientTypes))))})
}
