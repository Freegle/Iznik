package test

import (
	json2 "encoding/json"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/auth"
	user2 "github.com/freegle/iznik-server-go/user"
	"github.com/golang-jwt/jwt/v4"
	"github.com/stretchr/testify/assert"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestAuth(t *testing.T) {
	// Create a full test user with all relationships
	prefix := uniquePrefix("auth")
	userID, token := CreateFullTestUser(t, prefix)

	// Get the logged in user - use 60s timeout since /api/user is a complex endpoint
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user?jwt="+token, nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)
	var user user2.User
	json2.Unmarshal(rsp(resp), &user)

	// Should match the user we tried to log in as
	assert.Equal(t, user.ID, userID)

	// Should see memberships
	assert.Greater(t, len(user.Memberships), 0)
}

func TestPersistent(t *testing.T) {
	// Create a user and session for this test
	prefix := uniquePrefix("persistent")
	userID := CreateTestUser(t, prefix, "User")
	sessionID, _ := CreateTestSession(t, userID)

	// Create the old-style persistent token used by the PHP API
	token := CreatePersistentToken(t, userID, sessionID)

	// Get the logged in user
	req := httptest.NewRequest("GET", "/api/user", nil)
	req.Header.Set("Authorization2", token)
	resp, _ := getApp().Test(req, 60000)
	assert.Equal(t, 200, resp.StatusCode)
	var user user2.User
	json2.Unmarshal(rsp(resp), &user)
	assert.Equal(t, user.ID, userID)
}

func TestSearches(t *testing.T) {
	// Create a full test user
	prefix := uniquePrefix("searches")
	userID, token := CreateFullTestUser(t, prefix)

	// Get the logged in user's searches
	id := strconv.FormatUint(userID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+id+"/search?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	// Non-existent user should return 404
	id = strconv.FormatUint(0, 10)
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/user/"+id+"/search?jwt="+token, nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestPublicLocation(t *testing.T) {
	// Create a full test user with location
	prefix := uniquePrefix("publoc")
	userID, token := CreateFullTestUser(t, prefix)

	// Get the user's public location
	id := strconv.FormatUint(userID, 10)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+id+"/publiclocation?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var location user2.Publiclocation
	json2.Unmarshal(rsp(resp), &location)
	assert.Greater(t, len(location.Location), 0)
}

func TestExpiredJWT(t *testing.T) {
	// Create a user for this test
	prefix := uniquePrefix("expired")
	userID, _ := CreateFullTestUser(t, prefix)
	id := strconv.FormatUint(userID, 10)

	token := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"id":  id,
		"exp": time.Date(2015, 10, 10, 12, 0, 0, 0, time.UTC).Unix(),
	})

	// Sign and get the complete encoded token as a string using the secret
	tokenString, _ := token.SignedString([]byte(os.Getenv("JWT_SECRET")))

	// Expired token is ignored
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/"+id+"/publiclocation?jwt="+tokenString, nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestValidJWTInvalidUser(t *testing.T) {
	// Create a real user and token, then delete the user so the JWT points
	// to a non-existent user. The middleware's post-check verifies the
	// user+session still exists in DB and returns 401 when it doesn't.
	uid := CreateTestUser(t, uniquePrefix("invaliduser"), "User")
	token := getToken(t, uid)

	db := database.DBConn
	db.Exec("DELETE FROM users WHERE id = ?", uid)

	req := httptest.NewRequest("POST", "/api/newsfeed?jwt="+token, nil)
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

// Reproduces the production "PATCH /session 401" signature: the user still
// exists, but the sessions row the JWT points at has been removed (by
// scripts/cron/purge_sessions.php after 31d of inactivity, or by logout
// from another device). The JWT signature still parses so WhoAmI returns
// the user id, but authMiddleware's sessions JOIN users check fails and
// overrides the response with 401.
//
// Observed in Sentry as ≥3 users in 24h from the forgot-password flow —
// their stale-JWT localStorage overlay survives the u/k auto-login and
// the next authenticated call 401s.
func TestPatchSessionWithPurgedSessionReturns401(t *testing.T) {
	prefix := uniquePrefix("purged_session")
	userID := CreateTestUser(t, prefix, "User")
	sessionID, token := CreateTestSession(t, userID)

	db := database.DBConn
	db.Exec("DELETE FROM sessions WHERE id = ?", sessionID)

	// PATCH /session with a no-op body. The exact endpoint in the Sentry trace:
	// handler would call WhoAmI (passes, JWT parses), mutate nothing meaningful,
	// then the middleware's post-check overrides with 401.
	req := httptest.NewRequest("PATCH", "/api/session?jwt="+token,
		strings.NewReader(`{"displayname":"anything"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode,
		"PATCH /session with JWT for a purged sessions row must 401 (user still exists)")
}

// Forgot-password end-to-end repro. A user whose browser holds a stale JWT
// (from a session that was purged or wiped by logout-elsewhere) clicks the
// emailed ?u=X&k=KEY link. The u/k login creates a NEW session row, but the
// frontend's PATCH /session fires with the OLD JWT still in localStorage —
// the exact race the Sentry trace shows. The old JWT's sessionid now points
// at a missing row, so the middleware returns 401 even though a valid session
// for the same user exists.
func TestForgotPasswordStaleJWTReturns401(t *testing.T) {
	prefix := uniquePrefix("forgotpass_stale")
	userID := CreateTestUser(t, prefix, "User")

	// 1. User's previous device session (JWT still in localStorage).
	oldSessionID, oldJWT := CreateTestSession(t, userID)

	// 2. Purge that session — e.g. cron/purge_sessions.php or logout from
	//    the other device. The JWT is now stale but still parses.
	db := database.DBConn
	db.Exec("DELETE FROM sessions WHERE id = ?", oldSessionID)

	// 3. Simulate a successful u/k auto-login on the forgotpass landing
	//    page. This creates a brand-new session row for the same user.
	_, _, err := auth.CreateSessionAndJWT(userID)
	assert.NoError(t, err)

	// 4. Frontend fires PATCH /session before localStorage is updated with
	//    the new JWT — so it carries the STALE JWT. The server still 401s
	//    because the stale JWT's sessionid doesn't exist.
	req := httptest.NewRequest("PATCH", "/api/session?jwt="+oldJWT,
		strings.NewReader(`{"password":"newpassword"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode,
		"forgotpass PATCH /session with stale JWT from purged session must 401 even though user has a fresh session")
}

// CreateSessionAndJWT must write a numeric, non-zero series value. The
// previous implementation passed utils.RandomHex(16) to a bigint unsigned
// column, which MySQL silently coerced to 0 (or MAX uint64 when the hex
// started with 'f'). This collapsed UNIQUE KEY (id, series, token) across
// thousands of production sessions — breaking the per-device rotation
// premise of (series, token).
func TestCreateSessionAndJWTSeriesIsNumericAndUnique(t *testing.T) {
	prefix := uniquePrefix("series_numeric")
	userID := CreateTestUser(t, prefix, "User")

	db := database.DBConn

	// Create two sessions for the same user. The series values must both be
	// non-zero, not MAX uint64, and distinct — anything else indicates the
	// bigint coercion bug is back.
	persistent1, _, err1 := auth.CreateSessionAndJWT(userID)
	assert.NoError(t, err1)
	persistent2, _, err2 := auth.CreateSessionAndJWT(userID)
	assert.NoError(t, err2)

	series1, _ := persistent1["series"].(uint64)
	series2, _ := persistent2["series"].(uint64)

	assert.NotEqual(t, uint64(0), series1, "series must not be 0 (hex-coercion bug)")
	assert.NotEqual(t, uint64(0), series2, "series must not be 0 (hex-coercion bug)")
	assert.NotEqual(t, ^uint64(0), series1, "series must not be MAX uint64 (hex-coercion overflow)")
	assert.NotEqual(t, ^uint64(0), series2, "series must not be MAX uint64 (hex-coercion overflow)")
	assert.NotEqual(t, series1, series2, "two fresh sessions for the same user must have distinct series")

	// And the value returned in the persistent map must match what is
	// actually stored in the sessions row.
	sessionID1, _ := persistent1["id"].(uint64)
	var storedSeries uint64
	db.Raw("SELECT series FROM sessions WHERE id = ?", sessionID1).Scan(&storedSeries)
	assert.Equal(t, series1, storedSeries,
		"persistent.series must match sessions.series in DB (no silent coercion)")
}

func TestHasPermission(t *testing.T) {
	prefix := uniquePrefix("hasperm")
	db := database.DBConn

	// User with no permissions
	userID := CreateTestUser(t, prefix+"_none", "User")
	assert.False(t, auth.HasPermission(userID, auth.PERM_GIFTAID))

	// User with GiftAid permission
	userGiftAid := CreateTestUser(t, prefix+"_ga", "User")
	db.Exec("UPDATE users SET permissions = 'GiftAid' WHERE id = ?", userGiftAid)
	assert.True(t, auth.HasPermission(userGiftAid, auth.PERM_GIFTAID))
	assert.False(t, auth.HasPermission(userGiftAid, auth.PERM_NEWSLETTER))

	// User with multiple permissions
	userMulti := CreateTestUser(t, prefix+"_multi", "User")
	db.Exec("UPDATE users SET permissions = 'Newsletter,GiftAid,SpamAdmin' WHERE id = ?", userMulti)
	assert.True(t, auth.HasPermission(userMulti, auth.PERM_GIFTAID))
	assert.True(t, auth.HasPermission(userMulti, auth.PERM_NEWSLETTER))
	assert.True(t, auth.HasPermission(userMulti, auth.PERM_SPAM_ADMIN))
	assert.False(t, auth.HasPermission(userMulti, auth.PERM_TEAMS))
}
