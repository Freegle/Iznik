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

// lastaccessAgeSecs returns how many seconds ago users.lastaccess was set,
// or -1 if it is NULL.
func lastaccessAgeSecs(t *testing.T, uid uint64) int64 {
	var age []*int64
	database.DBConn.Raw("SELECT TIMESTAMPDIFF(SECOND, lastaccess, NOW()) AS age FROM users WHERE id = ?", uid).Pluck("age", &age)
	if len(age) == 0 || age[0] == nil {
		return -1
	}
	return *age[0]
}

func TestLastaccessBumpedWhenNull(t *testing.T) {
	// An authenticated request must set lastaccess when it is NULL.
	uid, token := CreateFullTestUser(t, uniquePrefix("la_null"))
	db := database.DBConn
	db.Exec("UPDATE users SET lastaccess = NULL WHERE id = ?", uid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/notification/count?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	age := lastaccessAgeSecs(t, uid)
	assert.NotEqual(t, int64(-1), age, "lastaccess should have been set from NULL")
	assert.Less(t, age, int64(240), "lastaccess should be recent after authenticated request")
}

func TestLastaccessBumpedWhenStale(t *testing.T) {
	// An authenticated request must refresh lastaccess older than 10 minutes.
	uid, token := CreateFullTestUser(t, uniquePrefix("la_stale"))
	db := database.DBConn
	db.Exec("UPDATE users SET lastaccess = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?", uid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/notification/count?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	age := lastaccessAgeSecs(t, uid)
	assert.Less(t, age, int64(240), "stale lastaccess should be refreshed by authenticated request")
}

func TestLastaccessNotBumpedWhenFresh(t *testing.T) {
	// lastaccess is throttled to every 10 minutes: a request must NOT bump a
	// fresh (5 minutes old) value. The throttle lives in the SQL guard so that
	// concurrent requests can't all write the same row (Galera certification
	// conflicts - see plans/2026-07-17-db3-cpu-reach-sql-prefilter.md).
	uid, token := CreateFullTestUser(t, uniquePrefix("la_fresh"))
	db := database.DBConn
	db.Exec("UPDATE users SET lastaccess = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id = ?", uid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/notification/count?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	age := lastaccessAgeSecs(t, uid)
	assert.GreaterOrEqual(t, age, int64(240), "fresh lastaccess must not be bumped (10-minute throttle)")
}
