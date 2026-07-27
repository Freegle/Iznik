package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/config"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// Tests for GET /api/config/{key} endpoint.
//
// The endpoint is unauthenticated but only serves an allowlist of keys the public
// web/app clients legitimately fetch; everything else in the admin-writable config
// store is 403.

func TestConfigGet_AllowlistedKey(t *testing.T) {
	db := database.DBConn

	// voicepost_rollout_pct is an allowlisted public key. Insert a distinctive value
	// and confirm it's returned without auth. Delete only the row we added so a
	// pre-existing production-seeded value (if any) is left untouched.
	key := "voicepost_rollout_pct"
	val := "cfgget_" + uniquePrefix("v")
	db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?)", key, val)
	defer db.Exec("DELETE FROM config WHERE `key` = ? AND value = ?", key, val)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/config/"+key, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var results []config.ConfigItem
	json2.Unmarshal(rsp(resp), &results)
	found := false
	for _, r := range results {
		if r.Value == val {
			found = true
		}
	}
	assert.True(t, found, "allowlisted key should return its value without auth")
}

func TestConfigGet_NonAllowlistedForbidden(t *testing.T) {
	// A key that is not on the public allowlist must be rejected, even if it exists.
	db := database.DBConn
	key := "test_secret_" + uniquePrefix("cfg")
	db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?)", key, "should_not_be_readable")
	defer db.Exec("DELETE FROM config WHERE `key` = ?", key)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/config/"+key, nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestConfigGet_NonExistentNonAllowlisted(t *testing.T) {
	// Unknown, non-allowlisted key is 403 (not a 200 empty array) — the allowlist is
	// checked before the DB lookup, so it doesn't leak which keys exist.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/config/nonexistent_key_xyz_999", nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestConfigGet_V2Path(t *testing.T) {
	// The /apiv2/ path also serves allowlisted keys.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/apiv2/config/app_fd_version_ios_required", nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestConfigGet_AllowlistedNoAuth(t *testing.T) {
	// Allowlisted keys remain readable without authentication.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/config/app_fd_version_android_required", nil))
	assert.Equal(t, 200, resp.StatusCode)
}
