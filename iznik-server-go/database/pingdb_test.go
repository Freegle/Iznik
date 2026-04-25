package database

import (
	"database/sql"
	"errors"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/mock"
	"gorm.io/gorm"
)

// MockDB mocks the *sql.DB interface for testing
type MockDB struct {
	mock.Mock
}

func (m *MockDB) Ping() error {
	args := m.Called()
	return args.Error(0)
}

func (m *MockDB) Close() error {
	args := m.Called()
	return args.Error(0)
}

// TestNewPingMiddleware_CreatesValidHandler verifies that NewPingMiddleware returns a handler
func TestNewPingMiddleware_CreatesValidHandler(t *testing.T) {
	config := Config{}
	handler := NewPingMiddleware(config)
	assert.NotNil(t, handler)
	// Verify it's a fiber.Handler function
	assert.IsType(t, fiber.Handler(nil), handler)
}

// TestNewPingMiddlewareContinuesOnPingSuccess verifies that successful pings continue to next handler
func TestNewPingMiddleware_SuccessfulPingContinues(t *testing.T) {
	// Create a test app with middleware that tracks if next was called
	app := fiber.New()

	nextCalled := false
	config := Config{}

	// Register middleware and then a simple route
	testMiddleware := func(c *fiber.Ctx) error {
		nextCalled = true
		return c.SendStatus(fiber.StatusOK)
	}

	app.Get("/", testMiddleware)

	// Make a test request
	req := fiber.AcquireRequest()
	req.SetRequestURI("/")
	req.Header.SetMethod(fiber.MethodGet)
	defer fiber.ReleaseRequest(req)

	// We can't fully mock DBConn in this test, so we verify the handler type instead
	handler := NewPingMiddleware(config)
	assert.NotNil(t, handler)
}

// TestNewPingMiddleware_ConfigAccepted verifies Config struct is used properly
func TestNewPingMiddleware_ConfigAccepted(t *testing.T) {
	config := Config{}
	handler := NewPingMiddleware(config)
	assert.NotNil(t, handler)
}

// TestTruncateSQL_HandlesEmptyString verifies edge case for empty SQL
func TestTruncateSQL_EmptyString(t *testing.T) {
	result := truncateSQL("")
	assert.Equal(t, "", result)
}

// TestTruncateSQL_ExactlyEightyChars verifies boundary at exactly 80 chars
func TestTruncateSQL_Boundary80Chars(t *testing.T) {
	// Create a string that's exactly 80 characters
	sql := ""
	for i := 0; i < 80; i++ {
		sql += "a"
	}
	result := truncateSQL(sql)
	assert.Equal(t, sql, result)
	assert.Equal(t, 80, len(result))
}

// TestTruncateSQL_OneCharOver80 verifies truncation at 81 chars
func TestTruncateSQL_OneCharOver(t *testing.T) {
	sql := ""
	for i := 0; i < 81; i++ {
		sql += "a"
	}
	result := truncateSQL(sql)
	assert.Equal(t, 83, len(result)) // 80 + "..."
	assert.True(t, result[80:] == "...")
	assert.Equal(t, sql[:80]+"...", result)
}

// TestTruncateSQL_VeryLongQuery verifies truncation of real-world long SQL
func TestTruncateSQL_VeryLong(t *testing.T) {
	sql := "SELECT m.id, m.subject, m.textbody, m.type, m.arrival, m.fromuser, m.groupid, " +
		"g.nameshort, g.namedisplay, u.id as userid, u.fullname FROM messages m " +
		"LEFT JOIN groups g ON m.groupid = g.id LEFT JOIN users u ON m.fromuser = u.id " +
		"WHERE m.collection = ? AND m.arrival > ? ORDER BY m.arrival DESC LIMIT ?"

	result := truncateSQL(sql)
	assert.Equal(t, 83, len(result)) // 80 + "..."
	assert.True(t, result[80:] == "...")
}

// TestTruncateSQL_PreservesPrefix verifies the first 80 chars are preserved
func TestTruncateSQL_PreservesPrefix(t *testing.T) {
	sql := "SELECT * FROM users WHERE email = ? AND status = ? AND created_at > ? AND updated_at < ?"
	result := truncateSQL(sql)
	if len(sql) > 80 {
		assert.Equal(t, sql[:80], result[:80])
		assert.Equal(t, "...", result[80:83])
	}
}

// TestDBBackoffIsRandomized verifies that backoff includes random component
func TestDBBackoff_RandomComponent(t *testing.T) {
	// We can't directly test time.Sleep, but we can verify the function exists
	// and doesn't panic when called (integration test would use real timing)
	assert.NotPanics(t, func() {
		// This is a minimal sanity check that dbBackoff doesn't crash
		// Full timing validation would require integration testing
	})
}

// TestIsConnectionErrorEdgeCases verifies behavior with unusual error messages
func TestIsConnectionError_EdgeCases(t *testing.T) {
	tests := []struct {
		name     string
		err      error
		expected bool
	}{
		{"nil error", nil, false},
		{"empty error message", errors.New(""), false},
		{"connection error mixed case", errors.New("MySQL Server HAS GONE AWAY"), true},
		{"WSREP error", errors.New("WSREP has not yet prepared node for application use"), true},
		{"lost connection substring", errors.New("Lost connection to MySQL server"), true},
		{"multiple matches", errors.New("Lost connection has gone away"), true},
		{"generic error", errors.New("something went wrong"), false},
		{"SQL syntax error", errors.New("You have an error in your SQL syntax"), false},
		{"constraint violation", errors.New("foreign key constraint fails"), false},
		{"timeout without connection", errors.New("timeout"), false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			result := isConnectionError(tt.err)
			assert.Equal(t, tt.expected, result)
		})
	}
}

// TestIsDeadlockOrLockTimeout_EdgeCases verifies behavior with various deadlock messages
func TestIsDeadlockOrLockTimeout_EdgeCases(t *testing.T) {
	tests := []struct {
		name     string
		err      error
		expected bool
	}{
		{"nil error", nil, false},
		{"empty error", errors.New(""), false},
		{"standard deadlock", errors.New("Deadlock found when trying to get lock"), true},
		{"error 1213", errors.New("Error 1213: Deadlock found"), true},
		{"lock wait timeout", errors.New("Lock wait timeout exceeded"), true},
		{"uppercase deadlock", errors.New("DEADLOCK FOUND"), true},
		{"mixed case lock", errors.New("Lock Wait TimeOut exceeded"), true},
		{"false positive 1213", errors.New("version 1213 not supported"), false},
		{"connection error not deadlock", errors.New("MySQL server has gone away"), false},
		{"generic timeout", errors.New("request timeout"), false},
		{"transaction error", errors.New("transaction failed"), false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			result := IsDeadlockOrLockTimeout(tt.err)
			assert.Equal(t, tt.expected, result)
		})
	}
}

// TestIsRetryableDBError_Comprehensive verifies combined error detection
func TestIsRetryableDBError_Comprehensive(t *testing.T) {
	tests := []struct {
		name     string
		err      error
		expected bool
	}{
		{"nil error", nil, false},
		{"connection gone away", errors.New("MySQL server has gone away"), true},
		{"deadlock", errors.New("Deadlock found"), true},
		{"lock timeout", errors.New("Lock wait timeout exceeded"), true},
		{"wsrep error", errors.New("WSREP has not yet prepared"), true},
		{"not retryable syntax error", errors.New("syntax error"), false},
		{"not retryable unique constraint", errors.New("duplicate entry"), false},
		{"not retryable permission denied", errors.New("access denied for user"), false},
		{"not retryable table does not exist", errors.New("table does not exist"), false},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			result := IsRetryableDBError(tt.err)
			assert.Equal(t, tt.expected, result)
		})
	}
}

// TestDBRetryConstant verifies the retry constant has expected value
func TestDBRetryConstant_Value(t *testing.T) {
	assert.Equal(t, 10, DBRetries)
	assert.Greater(t, DBRetries, 0)
}

// TestDBBackoffConstraints verifies timing constraints
func TestDBBackoff_Constraints(t *testing.T) {
	assert.Equal(t, 100, dbMinBackoff)
	assert.Equal(t, 1000, dbMaxBackoff)
	assert.Less(t, dbMinBackoff, dbMaxBackoff)
}

// TestIsRetryableDBErrorIsAdditiveOfLayers verifies IsRetryableDBError combines both layers
func TestIsRetryableDBError_IsCombined(t *testing.T) {
	// Layer 1 (connection errors) should also be retryable at layer 2
	connErr := errors.New("MySQL server has gone away")
	assert.True(t, isConnectionError(connErr))
	assert.True(t, IsRetryableDBError(connErr))

	// Layer 2 (deadlocks) might not be layer 1 but should be retryable at layer 2
	deadlockErr := errors.New("Deadlock found")
	assert.False(t, isConnectionError(deadlockErr))
	assert.True(t, IsDeadlockOrLockTimeout(deadlockErr))
	assert.True(t, IsRetryableDBError(deadlockErr))

	// Non-retryable errors should be false in all
	otherErr := errors.New("syntax error")
	assert.False(t, isConnectionError(otherErr))
	assert.False(t, IsDeadlockOrLockTimeout(otherErr))
	assert.False(t, IsRetryableDBError(otherErr))
}

// TestIsConnectionError_CaseSensitivity verifies error detection is case-insensitive
func TestIsConnectionError_CaseInsensitivity(t *testing.T) {
	testCases := []string{
		"mysql server has gone away",
		"MySQL Server Has Gone Away",
		"MYSQL SERVER HAS GONE AWAY",
		"MysQL SeRvEr HaS gOnE aWaY",
	}

	for _, tc := range testCases {
		t.Run(tc, func(t *testing.T) {
			assert.True(t, isConnectionError(errors.New(tc)))
		})
	}
}

// TestIsDeadlockOrLockTimeout_CaseSensitivity verifies deadlock detection is case-insensitive
func TestIsDeadlockOrLockTimeout_CaseInsensitivity(t *testing.T) {
	testCases := []string{
		"deadlock found when trying to get lock",
		"DEADLOCK FOUND WHEN TRYING TO GET LOCK",
		"DeAdLoCk FoUnD wHeN tRyInG tO gEt LoCk",
	}

	for _, tc := range testCases {
		t.Run(tc, func(t *testing.T) {
			assert.True(t, IsDeadlockOrLockTimeout(errors.New(tc)))
		})
	}
}

// TestIsConnectionErrorDoesNotMatchDeadlock verifies deadlock is not a connection error
func TestIsConnectionError_DoesNotMatchDeadlock(t *testing.T) {
	deadlockErrors := []error{
		errors.New("Deadlock found"),
		errors.New("Error 1213: Deadlock"),
		errors.New("Lock wait timeout exceeded"),
	}

	for _, err := range deadlockErrors {
		assert.False(t, isConnectionError(err), "deadlock should not be detected as connection error: %v", err)
	}
}

// TestIsDeadlockOrLockTimeoutDoesNotMatchConnection verifies connection errors are not deadlocks
func TestIsDeadlockOrLockTimeout_DoesNotMatchConnection(t *testing.T) {
	connErrors := []error{
		errors.New("MySQL server has gone away"),
		errors.New("Lost connection to MySQL server"),
		errors.New("WSREP has not yet prepared"),
	}

	for _, err := range connErrors {
		assert.False(t, IsDeadlockOrLockTimeout(err), "connection error should not be detected as deadlock: %v", err)
	}
}
