package database

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"fmt"
	"net/url"
	"os"
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

func pgEnv(name, def string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return def
}

// buildPostgresDSN builds the connection URL for a host. The write host and a read replica share every
// other part. The session time zone is UTC, because the baseline's timestamp columns are timestamptz and
// its datetime columns are timestamp without a zone, and both must mean what they meant on MySQL.
func buildPostgresDSN(host string) string {
	q := url.Values{}
	q.Set("sslmode", pgEnv("PGSQL_SSLMODE", "prefer"))
	q.Set("TimeZone", "UTC")
	u := url.URL{
		Scheme:   "postgres",
		User:     url.UserPassword(pgEnv("PGSQL_USERNAME", "root"), os.Getenv("PGSQL_PASSWORD")),
		Host:     host + ":" + pgEnv("PGSQL_PORT", "5432"),
		Path:     "/" + pgEnv("PGSQL_DATABASE", "iznik"),
		RawQuery: q.Encode(),
	}
	return u.String()
}

// newPostgresFailoverDialector is the PostgreSQL twin of newFailoverDialector: the replica connector
// falls back to the source for a retry window after a failed dial.
func newPostgresFailoverDialector(replicaDSN, sourceDSN string) gorm.Dialector {
	rc, err := pgx.ParseConfig(replicaDSN)
	if err != nil {
		panic("database: failed to parse replica DSN for failover: " + err.Error())
	}
	rc.ConnectTimeout = 2 * time.Second
	sc, err := pgx.ParseConfig(sourceDSN)
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
				SetMaxOpenConns(200).
				SetMaxIdleConns(200).
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
	dbConfig.SetMaxOpenConns(200)
	dbConfig.SetMaxIdleConns(200)
	dbConfig.SetConnMaxLifetime(time.Hour)

	DBConn = db
	Pool = nil
}

// newPostgresDialector opens a PostgreSQL connection pool whose statements are translated from MySQL
// syntax (pgtranslate.go).
func newPostgresDialector(dsn string) gorm.Dialector {
	cfg, err := pgx.ParseConfig(dsn)
	if err != nil {
		panic("database: failed to parse DSN: " + err.Error())
	}
	return postgres.New(postgres.Config{Conn: sql.OpenDB(newPgTranslatingConnector(stdlib.GetConnector(*cfg, stdlib.OptionAfterConnect(registerLenientTypes))))})
}
