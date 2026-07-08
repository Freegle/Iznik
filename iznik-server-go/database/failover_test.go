package database

import (
	"context"
	"database/sql"
	"database/sql/driver"
	"os"
	"sync/atomic"
	"testing"
	"time"

	rawmysql "github.com/go-sql-driver/mysql"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// testFallbackConnector builds a connector for the real test database, to be
// used as the fallback in failover tests.  buildDSN is unexported but
// accessible here because this file is in package database.
func testFallbackConnector(t *testing.T) driver.Connector {
	t.Helper()
	host := os.Getenv("MYSQL_HOST")
	if host == "" {
		t.Skip("MYSQL_HOST not set; skipping failover integration test")
	}
	cfg, err := rawmysql.ParseDSN(buildDSN(host))
	require.NoError(t, err, "parse test DB DSN")
	conn, err := rawmysql.NewConnector(cfg)
	require.NoError(t, err, "build test DB connector")
	return conn
}

// testUnreachableConnector builds a connector whose Addr is 127.0.0.1:1 — a
// port guaranteed closed on any host — with a short dial timeout so test
// failures are detected quickly.
func testUnreachableConnector(t *testing.T, timeout time.Duration) driver.Connector {
	t.Helper()
	host := os.Getenv("MYSQL_HOST")
	if host == "" {
		t.Skip("MYSQL_HOST not set; skipping failover integration test")
	}
	cfg, err := rawmysql.ParseDSN(buildDSN(host))
	require.NoError(t, err, "parse DSN for unreachable connector")
	cfg.Addr = "127.0.0.1:1"
	cfg.Timeout = timeout
	conn, err := rawmysql.NewConnector(cfg)
	require.NoError(t, err, "build unreachable connector")
	return conn
}

// countingConnector wraps a driver.Connector and counts how many times
// Connect is called, allowing tests to assert that the primary was used.
type countingConnector struct {
	inner driver.Connector
	count atomic.Int64
}

func (c *countingConnector) Connect(ctx context.Context) (driver.Conn, error) {
	c.count.Add(1)
	return c.inner.Connect(ctx)
}

func (c *countingConnector) Driver() driver.Driver {
	return c.inner.Driver()
}

// TestFailoverConnector_FallsBackWhenPrimaryDown verifies that when the
// primary (replica) connector is pointing at an unreachable host, the
// failoverConnector transparently routes to the fallback (source) and the
// resulting *sql.DB can Ping and run a SELECT.
func TestFailoverConnector_FallsBackWhenPrimaryDown(t *testing.T) {
	const dialTimeout = 200 * time.Millisecond

	fc := &failoverConnector{
		primary:    testUnreachableConnector(t, dialTimeout),
		fallback:   testFallbackConnector(t),
		retryAfter: 30 * time.Second,
	}

	db := sql.OpenDB(fc)
	defer db.Close()

	require.NoError(t, db.Ping(), "Ping should succeed via fallback when primary is down")

	var got int
	require.NoError(t, db.QueryRow("SELECT 1").Scan(&got))
	assert.Equal(t, 1, got, "SELECT 1 should return 1 via fallback")
}

// TestFailoverConnector_RetryWindowSkipsPrimary proves that after the first
// failure the connector enters a retry window and subsequent Connect calls go
// straight to the fallback without re-attempting the primary.
//
// The proof is via call counts on a countingConnector wrapper: after the first
// Connect (which calls the primary once and fails), the second Connect must not
// increment the count — the primary was skipped entirely, not just fast.
func TestFailoverConnector_RetryWindowSkipsPrimary(t *testing.T) {
	// Wrap the unreachable connector in a counter so we can observe exactly
	// how many times the primary is called.
	counted := &countingConnector{
		inner: testUnreachableConnector(t, 200*time.Millisecond),
	}

	fc := &failoverConnector{
		primary:    counted,
		fallback:   testFallbackConnector(t),
		retryAfter: 30 * time.Second,
	}

	ctx := context.Background()

	// First Connect: must probe the primary (fails), then fall back to source.
	conn1, err := fc.Connect(ctx)
	require.NoError(t, err, "first Connect should succeed via fallback")
	conn1.Close()
	assert.Equal(t, int64(1), counted.count.Load(),
		"first Connect must attempt the primary exactly once")

	// Second Connect: within the 30-second retry window.  The primary must
	// NOT be called again — the connector goes straight to fallback.
	conn2, err := fc.Connect(ctx)
	require.NoError(t, err, "second Connect should succeed via fallback")
	conn2.Close()
	assert.Equal(t, int64(1), counted.count.Load(),
		"second Connect must skip the primary (count stays at 1 within retry window)")
}

// TestFailoverConnector_PrefersHealthyPrimary proves that when the primary
// connector is healthy, the failoverConnector routes through it — not the
// fallback.  A countingConnector wrapper makes the usage count observable.
func TestFailoverConnector_PrefersHealthyPrimary(t *testing.T) {
	counter := &countingConnector{inner: testFallbackConnector(t)}

	fc := &failoverConnector{
		primary:    counter,
		fallback:   testFallbackConnector(t),
		retryAfter: 30 * time.Second,
	}

	db := sql.OpenDB(fc)
	defer db.Close()

	require.NoError(t, db.Ping(), "Ping should succeed via healthy primary")

	var got int
	require.NoError(t, db.QueryRow("SELECT 1").Scan(&got))
	assert.Equal(t, 1, got)

	// At least one connection must have gone through the primary (counter).
	assert.Greater(t, counter.count.Load(), int64(0),
		"at least one connection should have gone through the primary connector")
}
