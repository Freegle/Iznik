package test

import (
	"errors"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestInitDatabase_Fail(t *testing.T) {
	// Save the current DB connection and env var so we can restore after the
	// intentional panic.
	user := os.Getenv("MYSQL_USER")
	savedConn := database.DBConn
	savedPool := database.Pool

	defer func() {
		os.Setenv("MYSQL_USER", user)
		database.DBConn = savedConn
		database.Pool = savedPool
		recover()
	}()

	os.Setenv("MYSQL_USER", "nonexistent_user_that_does_not_exist")
	database.InitDatabase()
}

// --- IsDeadlockOrLockTimeout ---

func TestIsDeadlockOrLockTimeout_Deadlock(t *testing.T) {
	assert.True(t, database.IsDeadlockOrLockTimeout(errors.New("Deadlock found when trying to get lock")))
}

func TestIsDeadlockOrLockTimeout_Error1213(t *testing.T) {
	assert.True(t, database.IsDeadlockOrLockTimeout(errors.New("Error 1213 (40001): Deadlock")))
}

func TestIsDeadlockOrLockTimeout_LockWaitTimeout(t *testing.T) {
	assert.True(t, database.IsDeadlockOrLockTimeout(errors.New("Lock wait timeout exceeded")))
}

func TestIsDeadlockOrLockTimeout_CaseInsensitive(t *testing.T) {
	assert.True(t, database.IsDeadlockOrLockTimeout(errors.New("DEADLOCK FOUND")))
}

func TestIsDeadlockOrLockTimeout_Nil(t *testing.T) {
	assert.False(t, database.IsDeadlockOrLockTimeout(nil))
}

func TestIsDeadlockOrLockTimeout_UnrelatedError(t *testing.T) {
	assert.False(t, database.IsDeadlockOrLockTimeout(errors.New("column not found")))
}

// --- IsRetryableDBError ---

func TestIsRetryableDBError_ConnectionGoneAway(t *testing.T) {
	assert.True(t, database.IsRetryableDBError(errors.New("MySQL server has gone away")))
}

func TestIsRetryableDBError_LostConnection(t *testing.T) {
	assert.True(t, database.IsRetryableDBError(errors.New("Lost connection to MySQL server during query")))
}

func TestIsRetryableDBError_WSREP(t *testing.T) {
	assert.True(t, database.IsRetryableDBError(errors.New("WSREP has not yet prepared node for application use")))
}

func TestIsRetryableDBError_Deadlock(t *testing.T) {
	// Deadlocks are retryable at the API level
	assert.True(t, database.IsRetryableDBError(errors.New("Deadlock found")))
}

func TestIsRetryableDBError_Nil(t *testing.T) {
	assert.False(t, database.IsRetryableDBError(nil))
}

func TestIsRetryableDBError_SyntaxError(t *testing.T) {
	assert.False(t, database.IsRetryableDBError(errors.New("You have an error in your SQL syntax")))
}

// --- RetryQuery ---

func TestRetryQuery_Success(t *testing.T) {
	var count int64
	err := database.RetryQuery(database.DBConn, &count, "SELECT COUNT(*) FROM users WHERE id = 1")
	assert.NoError(t, err)
}

func TestRetryQuery_WithArgs(t *testing.T) {
	var count int64
	err := database.RetryQuery(database.DBConn, &count, "SELECT COUNT(*) FROM users WHERE id = ?", 1)
	assert.NoError(t, err)
}

func TestRetryQuery_ScanIntoStruct(t *testing.T) {
	type UserRow struct {
		ID uint64 `gorm:"column:id"`
	}
	var rows []UserRow
	err := database.RetryQuery(database.DBConn, &rows, "SELECT id FROM users LIMIT 1")
	assert.NoError(t, err)
}

func TestRetryQuery_BadSQL(t *testing.T) {
	var count int64
	err := database.RetryQuery(database.DBConn, &count, "SELECT FROM nonexistent_table_xyz")
	assert.Error(t, err)
}

func TestRetryQuery_NonExistentTable(t *testing.T) {
	var count int64
	err := database.RetryQuery(database.DBConn, &count, "SELECT COUNT(*) FROM table_that_does_not_exist_abc123")
	assert.Error(t, err)
}

// --- RetryExec ---

func TestRetryExec_Success(t *testing.T) {
	// Use a no-op UPDATE that affects 0 rows
	err := database.RetryExec(database.DBConn, "UPDATE users SET fullname = fullname WHERE id = 0")
	assert.NoError(t, err)
}

func TestRetryExec_WithArgs(t *testing.T) {
	err := database.RetryExec(database.DBConn, "UPDATE users SET fullname = fullname WHERE id = ?", 0)
	assert.NoError(t, err)
}

func TestRetryExec_BadSQL(t *testing.T) {
	err := database.RetryExec(database.DBConn, "UPDATE nonexistent_table_xyz SET foo = bar")
	assert.Error(t, err)
}

// --- RetryExecResult ---

func TestRetryExecResult_ZeroRows(t *testing.T) {
	rows, err := database.RetryExecResult(database.DBConn, "UPDATE users SET fullname = fullname WHERE id = 0")
	assert.NoError(t, err)
	assert.Equal(t, int64(0), rows)
}

func TestRetryExecResult_WithArgs(t *testing.T) {
	rows, err := database.RetryExecResult(database.DBConn, "UPDATE users SET fullname = fullname WHERE id = ?", 0)
	assert.NoError(t, err)
	assert.Equal(t, int64(0), rows)
}

func TestRetryExecResult_BadSQL(t *testing.T) {
	rows, err := database.RetryExecResult(database.DBConn, "UPDATE nonexistent_table_xyz SET foo = bar")
	assert.Error(t, err)
	assert.Equal(t, int64(0), rows)
}

// --- DBRetries constant ---

func TestDBRetries_Value(t *testing.T) {
	// Matches v1's LoggedPDO::$tries default
	require.Equal(t, 10, database.DBRetries)
}
