package database

import (
	"fmt"
	sentrylogpackage "github.com/freegle/iznik-server-go/sentrylog"
	sql "github.com/rocketlaunchr/mysql-go"
	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"
	"gorm.io/plugin/dbresolver"
	"os"
	"time"
)

var (
	DBConn *gorm.DB
	Pool   *sql.DB
)

// buildDSN constructs the MySQL DSN for a given host, sharing all other
// connection parameters (user, password, protocol, port, database) across the
// write host and any read replica.
func buildDSN(host string) string {
	return fmt.Sprintf(
		"%s:%s@%s(%s:%s)/%s?charset=utf8mb4&parseTime=True&loc=Local&interpolateParams=true",
		os.Getenv("MYSQL_USER"),
		os.Getenv("MYSQL_PASSWORD"),
		os.Getenv("MYSQL_PROTOCOL"),
		host,
		os.Getenv("MYSQL_PORT"),
		os.Getenv("MYSQL_DBNAME"),
	)
}

func InitDatabase() {
	writeHost := os.Getenv("MYSQL_HOST")
	readHost := os.Getenv("MYSQL_HOST_READ")

	fmt.Println("Connecting to database", writeHost, os.Getenv("MYSQL_PORT"), os.Getenv("MYSQL_DBNAME"), os.Getenv("MYSQL_USER"), os.Getenv("MYSQL_PROTOCOL"))

	mysqlCredentials := buildDSN(writeHost)

	newLogger := sentrylogpackage.New(
		sentrylogpackage.Config(logger.Config{
			SlowThreshold:             time.Second * 30,
			LogLevel:                  logger.Warn,
			IgnoreRecordNotFoundError: true, // Can validly happen for us.
		}),
	)

	// Build into locals and publish to the DBConn/Pool globals only once the
	// handle is fully configured (resolver, clause builders, pool sizing).
	// DBConn is read concurrently by every request goroutine, and on a
	// reconnect (pingDB.go) this function runs while requests are in flight:
	// assigning DBConn at gorm.Open time and then mutating its internals in
	// place handed those requests a half-built handle, which segfaulted
	// inside gorm (db1 apiv2, 2026-08-28, after a burst of failed pings).
	db, err := gorm.Open(mysql.Open(mysqlCredentials), &gorm.Config{
		Logger: newLogger,
	})

	// This rocketlaunchr/mysql-go package allows genuine cancellation of the MySQL query, which doesn't happen in
	// the standard library. See https://medium.com/@rocketlaunchr.cloud/canceling-mysql-in-go-827ed8f83b30.
	//
	// We use this in cases where we want to be able to cancel long-running queries.
	// It always targets the write host - a write is never safe on a replica.
	pool, err2 := sql.Open(mysqlCredentials)

	// Database-level retry is available via RetryQuery/RetryExec in retry.go.
	// API-level retry is handled by handler.WithRetry / handler.RetryGroup.
	if err != nil || err2 != nil {
		panic("failed to connect database")
	}

	// Read/write split (V1 parity: SQLHOSTS_READ / SQLHOSTS_MOD). When
	// MYSQL_HOST_READ is set we route reads to that replica and keep ALL writes
	// on MYSQL_HOST - this protects the single Galera write node from cluster
	// write conflicts/lockups. dbresolver routes by statement: anything that is
	// not a plain SELECT (INSERT/UPDATE/DELETE/REPLACE/DDL, SELECT ... FOR
	// UPDATE, and every transaction) goes to the source (write host); plain
	// SELECTs go to the replica. db.DB() still returns the source pool, so the
	// LastInsertId write sites are unaffected.
	//
	// Replica failover: the Replicas dialector is backed by a failoverConnector
	// (database/failover.go) that automatically falls back to the source when
	// the replica is unreachable. After a failure it skips the replica for a
	// 30-second window (to avoid stalling on a dial timeout), then re-probes it
	// on the next new connection. Recovery is therefore automatic: once the
	// replica is healthy again, the next connection attempt outside the window
	// routes back to it. The 5-minute conn lifetime below bounds how long
	// fallback-backed connections persist after recovery.
	//
	// When MYSQL_HOST_READ is empty the resolver is NOT registered and behaviour
	// is byte-identical to a single-host deployment, so dev/CI/existing prod are
	// unchanged until a replica is explicitly configured.
	//
	// CAVEAT: connection-local state created on the source and read back (e.g.
	// CREATE TEMPORARY TABLE ... then SELECT FROM it) must be pinned to the
	// writer with .Clauses(dbresolver.Write); see authority.GetStatsByAuthority.
	if readHost != "" {
		fmt.Println("Read replica enabled at", readHost)
		resolverErr := db.Use(
			dbresolver.Register(dbresolver.Config{
				Replicas: []gorm.Dialector{newFailoverDialector(buildDSN(readHost), buildDSN(writeHost))},
				Policy:   dbresolver.RandomPolicy{},
			}).
				SetMaxOpenConns(200).
				SetMaxIdleConns(200).
				// 5-minute lifetime ensures fallback-backed connections age out and
				// reconnect via the primary (replica) path within 5 minutes of
				// recovery, bounding how long the source carries replica-destined
				// reads after the replica comes back.
				SetConnMaxLifetime(5 * time.Minute),
		)
		if resolverErr != nil {
			panic("failed to register read replica: " + resolverErr.Error())
		}
	}

	// REPLACE INTO and INSERT ... SELECT conversions (database.InsertSelect,
	// clause.Insert{Modifier: "REPLACE"}) both need a ClauseBuilders override
	// registered on the connection they run against - see clausebuilders.go.
	//
	// This MUST happen after dbresolver registration, not before gorm.Open's
	// plugins are done: dbresolver.Register initializes each replica dialector
	// against this same *gorm.DB, and the mysql driver's Initialize re-installs
	// its default builders over ours (mysql.go: `for k, v := range
	// dialector.ClauseBuilders() { db.ClauseBuilders[k] = v }`). Registering
	// before the resolver therefore silently loses the "VALUES" override
	// wherever MYSQL_HOST_READ is set - which is production and nowhere else,
	// so CI stayed green while every production InsertSelect rendered the
	// placeholder INSERT and failed with Error 1054 (shipped 2026-08-06,
	// caught by the deploy canary on AllSeen's chat_roster backfill). The
	// "INSERT" override (REPLACE INTO) survived only because the mysql
	// dialector does not install a builder under that key. Nothing re-runs
	// dialector.Initialize later - replica failover swaps connectors, never
	// dialectors (failover.go) - so registering here is stable.
	RegisterCustomClauseBuilders(db)

	// We want lots of connections for parallelisation, but must stay below
	// MySQL's max_connections (500 in percona-my.cnf).  Leave headroom for
	// other services (batch, tests) sharing the same MySQL instance.
	// db.DB() returns the source (write) *sql.DB even when dbresolver is
	// registered, so this sizes the write pool.
	dbConfig, err3 := db.DB()
	if err3 != nil {
		panic("failed to get database config: " + err3.Error())
	}
	dbConfig.SetMaxOpenConns(200)
	dbConfig.SetMaxIdleConns(200)
	dbConfig.SetConnMaxLifetime(time.Hour)

	// Publish last - see the comment above gorm.Open.
	DBConn = db
	Pool = pool
}
