package test

import (
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// The Support/Admin and moderator route guards recognise a member the way every other route does,
// including by the Authorization2 persistent token. A member who is let in on one route must not be
// refused on another.
func TestSupportRouteGuardAcceptsAuthorization2(t *testing.T) {
	prefix := uniquePrefix("guard_auth2_support")
	supportID := CreateTestUser(t, prefix, utils.SYSTEMROLE_SUPPORT)
	sessionID, _ := CreateTestSession(t, supportID)
	persistent := CreatePersistentToken(t, supportID, sessionID)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/stats", nil)
	req.Header.Set("Authorization2", persistent)
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.NotEqual(t, 401, resp.StatusCode, "a Support member with a valid persistent token is logged in")
	assert.NotEqual(t, 403, resp.StatusCode)
}

func TestSystemLogsGuardAcceptsAuthorization2(t *testing.T) {
	prefix := uniquePrefix("guard_auth2_mod")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	sessionID, _ := CreateTestSession(t, modID)
	persistent := CreatePersistentToken(t, modID, sessionID)

	req := httptest.NewRequest("GET", "/api/modtools/systemlogs", nil)
	req.Header.Set("Authorization2", persistent)
	resp, err := getApp().Test(req, -1)
	if err != nil || resp == nil {
		t.Logf("no response (expected in CI without Loki): %v", err)
		return
	}
	assert.NotEqual(t, 401, resp.StatusCode, "a moderator with a valid persistent token is logged in")
	assert.NotEqual(t, 403, resp.StatusCode)
}
