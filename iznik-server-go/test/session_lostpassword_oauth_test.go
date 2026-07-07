package test

// AssertFlip failing test for lostpassword blank screen on OAuth-only accounts.
//
// Bug: POST /api/session {action:"LostPassword"} with an OAuth-only user
// (no Native row in users_logins) returns ret=0 with no socialSignin indicator.
// The frontend cannot show "use social login" because it gets a generic success
// response with no actionable status — resulting in a blank screen.
//
// After the fix: the endpoint returns a non-zero ret code and a socialSignin=true
// field so the frontend can render the "sign in with Google/Yahoo/Facebook" message.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// postLostPassword sends POST /api/session with action=LostPassword and returns decoded JSON.
func postLostPassword(t *testing.T, email string) map[string]interface{} {
	t.Helper()
	body, err := json.Marshal(map[string]interface{}{
		"action": "LostPassword",
		"email":  email,
	})
	require.NoError(t, err)
	req := httptest.NewRequest("POST", "/api/session", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	return result
}

// createOAuthOnlyUser creates a user that has a Google login but no Native password,
// simulating a moderator account created via OAuth (e.g. Google Sign-In).
func createOAuthOnlyUser(t *testing.T, prefix string) (uint64, string) {
	t.Helper()
	db := database.DBConn

	email := fmt.Sprintf("%s@test.com", prefix)
	fullname := fmt.Sprintf("OAuth User %s", prefix)

	result := db.Exec("INSERT INTO users (firstname, lastname, fullname, systemrole) VALUES ('OAuth', ?, ?, 'User')",
		prefix, fullname)
	require.NoError(t, result.Error)

	var userID uint64
	db.Raw("SELECT id FROM users WHERE fullname = ? ORDER BY id DESC LIMIT 1", fullname).Scan(&userID)
	require.NotZero(t, userID)

	db.Exec("INSERT INTO users_emails (userid, email) VALUES (?, ?)", userID, email)

	// Add a Google login to simulate OAuth signup — deliberately no Native login.
	db.Exec("INSERT INTO users_logins (userid, type, uid, credentials) VALUES (?, ?, ?, ?)",
		userID, utils.LOGIN_TYPE_GOOGLE, email, "google-oauth-token-test")

	return userID, email
}

// TestLostPasswordOAuthOnlyUserReturnsNoPasswordStatus is an AssertFlip Step 2 test.
//
// This test FAILS on the current (unfixed) code because the endpoint returns
// ret=0 (generic success) for OAuth-only accounts with no native password.
// It will PASS after the fix, when the endpoint returns a non-zero ret code
// and a socialSignin=true field so the frontend can show the correct message.
func TestLostPasswordOAuthOnlyUserReturnsNoPasswordStatus(t *testing.T) {
	prefix := uniquePrefix("lostpass_oauth")
	_, email := createOAuthOnlyUser(t, prefix)

	result := postLostPassword(t, email)

	// After fix: endpoint must NOT return generic success (ret=0) for OAuth-only accounts.
	// Currently fails: endpoint returns ret=0 regardless of whether a native password exists.
	assert.NotEqual(t, float64(0), result["ret"],
		"lostpassword must return a non-zero ret code for OAuth-only accounts so the frontend can show 'use social login'")

	// After fix: response must carry a socialSignin indicator so the frontend renders
	// the correct message instead of a blank screen.
	socialSignin, hasSocialSignin := result["socialSignin"]
	assert.True(t, hasSocialSignin,
		"response must include a socialSignin field for accounts with no native password")
	if hasSocialSignin {
		assert.Equal(t, true, socialSignin,
			"socialSignin must be true for OAuth-only accounts")
	}
}
