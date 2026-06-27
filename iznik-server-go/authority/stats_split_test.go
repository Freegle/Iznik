package authority

import (
	"fmt"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/plugin/dbresolver"
)

// splitDSN mirrors database.buildDSN (unexported) for test setup.
func splitDSN(host string) string {
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

// newSplitDB opens a gorm.DB and registers dbresolver with the replica pointing
// at the SAME host as the source. Even same-host, dbresolver uses a separate
// connection pool for the replica, so connection-local state (e.g. a temporary
// table) is not shared between the source and replica pools. That lets us
// exercise the read/write split's routing in CI without a real replica server.
//
// The source pool is capped to a single connection so the temporary table is
// always visible to a pinned read (the same guarantee production relies on for
// sequential queries).
func newSplitDB(t *testing.T) *gorm.DB {
	t.Helper()
	host := os.Getenv("MYSQL_HOST")
	db, err := gorm.Open(mysql.Open(splitDSN(host)), &gorm.Config{})
	if err != nil {
		t.Fatalf("open source: %v", err)
	}
	if err := db.Use(dbresolver.Register(dbresolver.Config{
		Replicas: []gorm.Dialector{mysql.Open(splitDSN(host))},
		Policy:   dbresolver.RandomPolicy{},
	})); err != nil {
		t.Fatalf("register dbresolver: %v", err)
	}
	sqlDB, err := db.DB()
	if err != nil {
		t.Fatalf("source DB(): %v", err)
	}
	sqlDB.SetMaxOpenConns(1)
	return db
}

// TestSplitTempTableRoutingHazard proves that under the read/write split a plain
// SELECT is routed to the replica pool (a different connection), so a temporary
// table created on the source is NOT visible to an unpinned read - and that
// pinning the read with .Clauses(dbresolver.Write) fixes it. This is the exact
// pattern GetStatsByAuthority depends on.
func TestSplitTempTableRoutingHazard(t *testing.T) {
	db := newSplitDB(t)

	if err := db.Exec(`DROP TEMPORARY TABLE IF EXISTS t_probe`).Error; err != nil {
		t.Fatalf("drop: %v", err)
	}
	if err := db.Exec(`CREATE TEMPORARY TABLE t_probe (id INT)`).Error; err != nil {
		t.Fatalf("create temp: %v", err)
	}
	if err := db.Exec(`INSERT INTO t_probe (id) VALUES (42)`).Error; err != nil {
		t.Fatalf("insert: %v", err)
	}

	// Unpinned read -> routed to the replica pool -> temp table not visible.
	var got int
	unpinnedErr := db.Raw(`SELECT id FROM t_probe LIMIT 1`).Scan(&got).Error
	assert.Error(t, unpinnedErr, "unpinned SELECT from a temp table should fail (routed to the read replica pool)")

	// Pinned read -> routed to the source -> temp table visible.
	got = 0
	pinnedErr := db.Clauses(dbresolver.Write).Raw(`SELECT id FROM t_probe LIMIT 1`).Scan(&got).Error
	assert.NoError(t, pinnedErr, "pinned SELECT from a temp table should succeed (routed to the write source)")
	assert.Equal(t, 42, got)
}

// TestGetStatsByAuthorityUnderSplit runs the real handler against a
// dbresolver-registered connection (source and replica on the same host). It
// must complete without a "Table 'pc' doesn't exist" error, proving the
// writer() pin keeps the temporary-table reads on the write host. Without the
// pin this test fails because the SELECTs from `pc` are routed to the replica
// pool, where the temporary table does not exist.
func TestGetStatsByAuthorityUnderSplit(t *testing.T) {
	saved := database.DBConn
	database.DBConn = newSplitDB(t)
	defer func() { database.DBConn = saved }()

	_, err := GetStatsByAuthority(1, "30 days ago", "today")
	assert.NoError(t, err)
}
