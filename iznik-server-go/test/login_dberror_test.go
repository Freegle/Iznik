package test

import (
	"fmt"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/session"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"gorm.io/driver/mysql"
	"gorm.io/gorm"
)

// brokenLookupDB opens a *fresh, independent* connection pointed at the
// (always-present) information_schema database, which has no `users` or
// `users_logins` tables. The connection itself succeeds - only queries against
// those tables fail - so this deterministically reproduces "lookup ERRORED",
// as distinct from "lookup found no rows", without relying on flaky
// network-level failures.
//
// Crucially this does NOT touch database.DBConn: it is a standalone *gorm.DB
// passed directly into the function under test, so only that one call is
// affected. Nothing else in the process - middleware, other queries, other
// tests - is touched. This is a regression test for Discourse #9941 post 1,
// where a previous attempt at this fix swapped the global database.DBConn for
// the whole request, breaking every query in the pipeline rather than
// isolating the one lookup being tested.
func brokenLookupDB(t *testing.T) *gorm.DB {
	dsn := fmt.Sprintf(
		"%s:%s@%s(%s:%s)/information_schema?charset=utf8mb4&parseTime=True&loc=Local&interpolateParams=true",
		os.Getenv("MYSQL_USER"), os.Getenv("MYSQL_PASSWORD"), os.Getenv("MYSQL_PROTOCOL"),
		os.Getenv("MYSQL_HOST"), os.Getenv("MYSQL_PORT"),
	)
	badDB, err := gorm.Open(mysql.Open(dsn), &gorm.Config{})
	require.NoError(t, err, "connecting to information_schema should succeed - only queries against users/users_logins should fail")
	return badDB
}

func TestLookupUserIDByEmail_DBErrorNotConfusedWithUnknownEmail(t *testing.T) {
	userID, err := session.LookupUserIDByEmail(brokenLookupDB(t), "doesnt-matter-9941@example.com")
	assert.Error(t, err, "a DB error must be surfaced, not silently treated as zero rows found")
	assert.Equal(t, uint64(0), userID)
}

func TestLookupUserAndEmailByEmail_DBErrorNotConfusedWithUnknownEmail(t *testing.T) {
	userID, email, err := session.LookupUserAndEmailByEmail(brokenLookupDB(t), "doesnt-matter-9941@example.com")
	assert.Error(t, err, "a DB error must be surfaced, not silently treated as zero rows found")
	assert.Equal(t, uint64(0), userID)
	assert.Equal(t, "", email)
}

func TestLookupUserExists_DBErrorNotConfusedWithUnknownUser(t *testing.T) {
	exists, err := session.LookupUserExists(brokenLookupDB(t), 123456789)
	assert.Error(t, err, "a DB error must be surfaced, not silently treated as the user not existing")
	assert.False(t, exists)
}

func TestLookupLinkCredentials_DBErrorNotConfusedWithNoKey(t *testing.T) {
	key, err := session.LookupLinkCredentials(brokenLookupDB(t), 123456789)
	assert.Error(t, err, "a DB error must be surfaced, not silently treated as no key set")
	assert.Equal(t, "", key)
}

func TestVerifyPassword_DBErrorNotConfusedWithWrongPassword(t *testing.T) {
	verified, err := auth.VerifyPassword(brokenLookupDB(t), 123456789, "whatever")
	assert.Error(t, err, "a DB error must be surfaced, not silently treated as a wrong password")
	assert.False(t, verified)
}
