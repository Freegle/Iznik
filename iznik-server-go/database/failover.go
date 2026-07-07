package database

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"fmt"
	"sync/atomic"
	"time"

	rawmysql "github.com/go-sql-driver/mysql"
	gormmysql "gorm.io/driver/mysql"
	"gorm.io/gorm"
)

// failoverConnector implements database/sql/driver.Connector and wraps a
// primary (replica) connector with a fallback (source/write) connector.
//
// When the primary is reachable it is always preferred. When it fails the
// connector falls back to the source for every new connection until the
// retryAfter window expires; after the window, it tries the primary again.
// Recovery is therefore automatic and proportional to the conn-max-lifetime
// of the resolver pool: once connections age out, new ones re-probe the
// primary via this path.
type failoverConnector struct {
	primary    driver.Connector
	fallback   driver.Connector
	retryAfter time.Duration

	// lastFail stores the unix-nanosecond timestamp of the most recent primary
	// failure.  Zero means either "never failed" or "primary recovered".
	lastFail atomic.Int64

	// loggedDown is set to true on the first failure of each down episode so
	// we emit exactly one log line per outage, not one per connection attempt.
	loggedDown atomic.Bool
}

// Connect implements driver.Connector.  The retry-window check is the core of
// the failover: after a failure we skip the primary for retryAfter to avoid
// stalling every new connection on a dial timeout; each new pooled connection
// after the window re-probes the primary, giving automatic periodic retry.
func (f *failoverConnector) Connect(ctx context.Context) (driver.Conn, error) {
	now := time.Now()
	last := f.lastFail.Load()

	if last != 0 && now.Sub(time.Unix(0, last)) < f.retryAfter {
		// Within backoff window: go straight to fallback without attempting
		// the primary, avoiding a dial-timeout stall on every new connection.
		return f.fallback.Connect(ctx)
	}

	// Try the primary (read replica).
	conn, err := f.primary.Connect(ctx)
	if err == nil {
		// Primary is healthy; clear the failure markers for the next episode.
		f.lastFail.Store(0)
		f.loggedDown.Store(false)
		return conn, nil
	}

	// Primary failed.  Record the time (so the retry window starts now) and
	// log exactly once per down episode.
	f.lastFail.Store(now.UnixNano())
	if f.loggedDown.CompareAndSwap(false, true) {
		fmt.Printf("read replica unavailable, using source: %v\n", err)
	}

	return f.fallback.Connect(ctx)
}

// Driver implements driver.Connector.
func (f *failoverConnector) Driver() driver.Driver {
	return f.primary.Driver()
}

// newFailoverDialector builds a gorm Dialector that wraps the replica DSN
// with a write-host fallback.  It parses both DSNs, sets a 2-second dial
// timeout on the replica connector (so a down replica costs at most one brief
// stall per 30-second retry window rather than a full TCP timeout), and
// returns a gorm dialector backed by a *sql.DB opened over the
// failoverConnector.
//
// This replaces the plain mysql.Open(replicaDSN) in dbresolver.Register.
func newFailoverDialector(replicaDSN, sourceDSN string) gorm.Dialector {
	replicaCfg, err := rawmysql.ParseDSN(replicaDSN)
	if err != nil {
		panic("database: failed to parse replica DSN for failover: " + err.Error())
	}
	// Short dial timeout on the replica only — a down replica must not stall
	// every new connection for the OS default TCP timeout (~2 min).
	replicaCfg.Timeout = 2 * time.Second

	sourceCfg, err := rawmysql.ParseDSN(sourceDSN)
	if err != nil {
		panic("database: failed to parse source DSN for failover: " + err.Error())
	}

	primaryConn, err := rawmysql.NewConnector(replicaCfg)
	if err != nil {
		panic("database: failed to build replica connector: " + err.Error())
	}
	fallbackConn, err := rawmysql.NewConnector(sourceCfg)
	if err != nil {
		panic("database: failed to build source connector: " + err.Error())
	}

	fc := &failoverConnector{
		primary:    primaryConn,
		fallback:   fallbackConn,
		retryAfter: 30 * time.Second,
	}

	sqlDB := sql.OpenDB(fc)
	return gormmysql.New(gormmysql.Config{Conn: sqlDB})
}
