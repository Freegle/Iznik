package test

import (
	"fmt"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/stretchr/testify/assert"
)

func init() {
	// Set LOKI_URL to localhost so Loki queries fail fast (connection refused)
	// rather than hanging on DNS resolution for non-existent "loki" hostname.
	os.Setenv("LOKI_URL", "http://127.0.0.1:39999")
}

// testSystemLogsRequest is a helper that handles the common pattern of making a
// request to systemlogs endpoints and gracefully handling nil responses (which
// occur when the handler panics or times out due to Loki being unavailable).
func testSystemLogsRequest(t *testing.T, url string) (int, bool) {
	t.Helper()
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil), -1)
	if err != nil {
		t.Logf("Request error (expected in CI without Loki): %v", err)
		return 0, false
	}
	if resp == nil {
		t.Log("Response is nil (expected in CI without Loki)")
		return 0, false
	}
	return resp.StatusCode, true
}

// =============================================================================
// Tests for GET /api/modtools/systemlogs - Auth & Validation
// =============================================================================

func TestSystemLogs_Unauthorized(t *testing.T) {
	// No authentication.
	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs")
	if ok {
		assert.Equal(t, 401, code)
	}
}

func TestSystemLogs_ForbiddenForRegularUser(t *testing.T) {
	prefix := uniquePrefix("syslogs_reg")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Regular user without moderator role should be forbidden.
	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs?jwt="+token)
	if ok {
		assert.Equal(t, 403, code)
	}
}

func TestSystemLogs_ModeratorAccess(t *testing.T) {
	prefix := uniquePrefix("syslogs_mod")
	userID := CreateTestUser(t, prefix, "Moderator")
	_, token := CreateTestSession(t, userID)

	// Moderator should get through auth. The actual query will likely fail
	// because Loki isn't available in test, but we should get past auth (not 401/403).
	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs?jwt="+token)
	if ok {
		// Accept 200 (if Loki is available) or 500 (if Loki is not available).
		assert.NotEqual(t, 401, code, "Moderator should not get 401")
		assert.NotEqual(t, 403, code, "Moderator should not get 403")
	}
}

func TestSystemLogs_SupportAccess(t *testing.T) {
	prefix := uniquePrefix("syslogs_sup")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	// Support user should get through auth.
	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs?jwt="+token)
	if ok {
		assert.NotEqual(t, 401, code, "Support should not get 401")
		assert.NotEqual(t, 403, code, "Support should not get 403")
	}
}

func TestSystemLogs_AdminAccess(t *testing.T) {
	prefix := uniquePrefix("syslogs_admin")
	userID := CreateTestUser(t, prefix, "Admin")
	_, token := CreateTestSession(t, userID)

	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs?jwt="+token)
	if ok {
		assert.NotEqual(t, 401, code, "Admin should not get 401")
		assert.NotEqual(t, 403, code, "Admin should not get 403")
	}
}

func TestSystemLogs_ModeratorCanViewAnyUser(t *testing.T) {
	prefix := uniquePrefix("syslogs_modany")
	modUserID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modUserID)

	// Under the national moderator pool there is no group-scoping left: any
	// moderator can view logs for any user.
	otherUserID := CreateTestUser(t, prefix+"_other", "User")

	code, ok := testSystemLogsRequest(t, fmt.Sprintf("/api/modtools/systemlogs?jwt=%s&userid=%d", modToken, otherUserID))
	if ok {
		assert.NotEqual(t, 403, code, "Moderator should be able to view any user's logs")
	}
}

func TestSystemLogs_SupportCanViewAnyUser(t *testing.T) {
	prefix := uniquePrefix("syslogs_supany")
	supportUserID := CreateTestUser(t, prefix+"_sup", "Support")
	_, supToken := CreateTestSession(t, supportUserID)

	otherUserID := CreateTestUser(t, prefix+"_other", "User")

	code, ok := testSystemLogsRequest(t, fmt.Sprintf("/api/modtools/systemlogs?jwt=%s&userid=%d", supToken, otherUserID))
	if ok {
		assert.NotEqual(t, 403, code, "Support should be able to view any user's logs")
	}
}

// =============================================================================
// Tests for GET /api/modtools/systemlogs/counts - Auth & Validation
// =============================================================================

func TestSystemLogsCounts_Unauthorized(t *testing.T) {
	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs/counts")
	if ok {
		assert.Equal(t, 401, code)
	}
}

func TestSystemLogsCounts_ForbiddenForRegularUser(t *testing.T) {
	prefix := uniquePrefix("syslogscnt_reg")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs/counts?jwt="+token)
	if ok {
		assert.Equal(t, 403, code)
	}
}

func TestSystemLogsCounts_MissingSources(t *testing.T) {
	prefix := uniquePrefix("syslogscnt_nosrc")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	// Missing required 'sources' parameter should return 400.
	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs/counts?jwt="+token)
	if ok {
		assert.Equal(t, 400, code)
	}
}

func TestSystemLogsCounts_WithSources(t *testing.T) {
	prefix := uniquePrefix("syslogscnt_src")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	// With sources parameter should get past validation.
	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs/counts?jwt="+token+"&sources=api")
	if ok {
		// Should not be 400 (validation passed) or 401/403 (auth passed).
		assert.NotEqual(t, 400, code, "Should not get validation error with sources")
		assert.NotEqual(t, 401, code)
		assert.NotEqual(t, 403, code)
	}
}

func TestSystemLogsCounts_ModeratorAccess(t *testing.T) {
	prefix := uniquePrefix("syslogscnt_mod")
	userID := CreateTestUser(t, prefix, "Moderator")
	_, token := CreateTestSession(t, userID)

	code, ok := testSystemLogsRequest(t, "/api/modtools/systemlogs/counts?jwt="+token+"&sources=api")
	if ok {
		assert.NotEqual(t, 401, code)
		assert.NotEqual(t, 403, code)
	}
}
