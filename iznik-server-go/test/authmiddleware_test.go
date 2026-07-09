package test

import (
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestPublicEndpointWithStaleJWT(t *testing.T) {
	// A public endpoint should return 200 even if the request includes a JWT
	// for a deleted/expired session. The auth middleware must not override the
	// handler's success response with 401.
	uid := CreateTestUser(t, "stalepublic", "User")
	token := getToken(t, uid)

	// Delete the session to make the JWT stale.
	db := database.DBConn
	db.Exec("DELETE FROM sessions WHERE userid = ?", uid)

	req := httptest.NewRequest("GET", "/api/online?jwt="+token, nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode, "Public endpoint should not return 401 for stale JWT")
}

func TestAuthEndpointWithStaleJWT(t *testing.T) {
	// An auth-requiring endpoint should still return 401 with a stale JWT.
	uid := CreateTestUser(t, "staleauth", "User")
	token := getToken(t, uid)

	// Delete the session to make the JWT stale.
	db := database.DBConn
	db.Exec("DELETE FROM sessions WHERE userid = ?", uid)

	// POST /newsfeed requires auth (calls WhoAmI).
	req := httptest.NewRequest("POST", "/api/newsfeed?jwt="+token, nil)
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode, "Auth endpoint should return 401 for stale JWT")
}
