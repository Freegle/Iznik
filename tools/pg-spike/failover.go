// Package spike holds the dbresolver-on-pgx spike. See
// docs/developers/reference/database-engines.md for what it found.
package spike

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"sync/atomic"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/stdlib"
	"gorm.io/driver/postgres"
	"gorm.io/gorm"
)

// failoverConnector is the PostgreSQL twin of iznik-server-go/database/failover.go: a replica
// connector that falls back to the source connector for retryAfter after a failed dial.
type failoverConnector struct {
	primary    driver.Connector
	fallback   driver.Connector
	retryAfter time.Duration
	lastFail   atomic.Int64
	fellBack   atomic.Int64
}

func (f *failoverConnector) Connect(ctx context.Context) (driver.Conn, error) {
	now := time.Now()
	last := f.lastFail.Load()
	if last != 0 && now.Sub(time.Unix(0, last)) < f.retryAfter {
		f.fellBack.Add(1)
		return f.fallback.Connect(ctx)
	}
	conn, err := f.primary.Connect(ctx)
	if err == nil {
		f.lastFail.Store(0)
		return conn, nil
	}
	f.lastFail.Store(now.UnixNano())
	f.fellBack.Add(1)
	return f.fallback.Connect(ctx)
}

func (f *failoverConnector) Driver() driver.Driver { return f.primary.Driver() }

// newFailoverDialector wraps the replica URL with a source fallback. The replica gets a short dial
// timeout so a dead replica costs one brief stall per retry window.
func newFailoverDialector(replicaURL, sourceURL string) (gorm.Dialector, *failoverConnector, error) {
	rc, err := pgx.ParseConfig(replicaURL)
	if err != nil {
		return nil, nil, err
	}
	rc.ConnectTimeout = 2 * time.Second
	sc, err := pgx.ParseConfig(sourceURL)
	if err != nil {
		return nil, nil, err
	}
	fc := &failoverConnector{
		primary:    stdlib.GetConnector(*rc),
		fallback:   stdlib.GetConnector(*sc),
		retryAfter: 30 * time.Second,
	}
	return postgres.New(postgres.Config{Conn: sql.OpenDB(fc)}), fc, nil
}
