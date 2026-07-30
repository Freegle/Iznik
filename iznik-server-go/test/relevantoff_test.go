package test

import (
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestRelevantOffValidKey: a correct Link key turns off relevantallowed and the
// GET returns a friendly 200 confirmation page.
func TestRelevantOffValidKey(t *testing.T) {
	prefix := uniquePrefix("relevantoff")
	userID := CreateTestUser(t, prefix, "Member")
	db := database.DBConn
	db.Exec("UPDATE users SET relevantallowed = 1 WHERE id = ?", userID)
	key := fmt.Sprintf("relkey%v", userID)
	db.Exec("INSERT INTO users_logins (userid, type, uid, credentials) VALUES (?, ?, ?, ?)",
		userID, utils.LOGIN_TYPE_LINK, fmt.Sprintf("%v", userID), key)
	defer db.Exec("DELETE FROM users_logins WHERE userid = ? AND credentials = ?", userID, key)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/user/relevantoff?u=%v&k=%s", userID, key), nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var ra int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&ra)
	assert.Equal(t, 0, ra, "valid key turns relevantallowed off")
}

// TestRelevantOffBadKey: a wrong key is rejected (403) and the setting is unchanged.
func TestRelevantOffBadKey(t *testing.T) {
	prefix := uniquePrefix("relevantoffbad")
	userID := CreateTestUser(t, prefix, "Member")
	db := database.DBConn
	db.Exec("UPDATE users SET relevantallowed = 1 WHERE id = ?", userID)
	db.Exec("INSERT INTO users_logins (userid, type, uid, credentials) VALUES (?, ?, ?, ?)",
		userID, utils.LOGIN_TYPE_LINK, fmt.Sprintf("%v", userID), "goodkey")
	defer db.Exec("DELETE FROM users_logins WHERE userid = ? AND credentials = ?", userID, "goodkey")

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/user/relevantoff?u=%v&k=WRONGKEY", userID), nil), 60000)
	require.Equal(t, 403, resp.StatusCode)

	var ra int
	db.Raw("SELECT relevantallowed FROM users WHERE id = ?", userID).Scan(&ra)
	assert.Equal(t, 1, ra, "a bad key must not change the setting")
}

// TestRelevantOffMissingParams: no u/k → 400.
func TestRelevantOffMissingParams(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/relevantoff", nil), 60000)
	require.Equal(t, 400, resp.StatusCode)
}
