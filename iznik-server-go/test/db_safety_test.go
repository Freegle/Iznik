package test

import (
	"os"
	"strings"
	"testing"
)

// TestDatabaseNameIsTestDatabase ensures tests are never accidentally run against
// a production database. Tests create data with names like "Test Volunteering" that
// would contaminate production if run against the live database (Discourse #9528).
func TestDatabaseNameIsTestDatabase(t *testing.T) {
	dbName := os.Getenv("MYSQL_DBNAME")
	if !strings.HasSuffix(dbName, "_test") {
		t.Fatalf(
			"Safety check: tests must run against a database ending in '_test' to prevent "+
				"test data contaminating production (see Discourse #9528). "+
				"Current MYSQL_DBNAME=%q. Run with MYSQL_DBNAME=iznik_go_test.",
			dbName,
		)
	}
}

// TestPostgresDatabaseNameIsTestDatabase is the same check for a run on PostgreSQL (DB_ENGINE=postgres),
// which reads PGSQL_DATABASE.
func TestPostgresDatabaseNameIsTestDatabase(t *testing.T) {
	if os.Getenv("DB_ENGINE") != "postgres" {
		t.Skip("not a PostgreSQL run")
	}
	dbName := os.Getenv("PGSQL_DATABASE")
	if !strings.HasSuffix(dbName, "_test") {
		t.Fatalf(
			"Safety check: tests must run against a database ending in '_test'. Current PGSQL_DATABASE=%q. Run with PGSQL_DATABASE=iznik_go_test.",
			dbName,
		)
	}
}
