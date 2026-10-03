package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A Trash Nothing member's name, photo and about-me are not shown to someone who is
// not logged in. Logged-in members and the partner itself still see them.

func createTNMemberWithIdentity(t *testing.T, prefix string) uint64 {
	db := database.DBConn
	uid := CreateTestUser(t, prefix, "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 900000000+uid, uid)
	db.Exec("INSERT INTO users_aboutme (userid, text) VALUES (?, ?)", uid, "About "+prefix)
	db.Exec("INSERT INTO users_images (userid, url) VALUES (?, ?)", uid, "https://example.com/"+prefix+".jpg")
	return uid
}

func fetchUserJSON(t *testing.T, path string) map[string]interface{} {
	resp, err := getApp().Test(httptest.NewRequest("GET", path, nil))
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var body map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&body))
	return body
}

func assertTNIdentityHidden(t *testing.T, u map[string]interface{}, prefix string) {
	assert.Equal(t, "A freegler", u["displayname"])
	assert.Nil(t, u["fullname"])
	assert.Nil(t, u["firstname"])
	assert.Nil(t, u["lastname"])
	assert.Equal(t, true, u["redacted"])
	profile, _ := u["profile"].(map[string]interface{})
	assert.Empty(t, profile["path"])
	assert.Empty(t, profile["paththumb"])
	aboutme, _ := u["aboutme"].(map[string]interface{})
	assert.Empty(t, aboutme["text"])
	assert.NotContains(t, fmt.Sprint(u), prefix)
}

func TestTNMemberIdentityHiddenWhenLoggedOut(t *testing.T) {
	prefix := uniquePrefix("tnpriv_anon")
	uid := createTNMemberWithIdentity(t, prefix)

	u := fetchUserJSON(t, fmt.Sprintf("/api/user/%d", uid))
	assertTNIdentityHidden(t, u, prefix)
}

func TestTNMemberIdentityHiddenWhenLoggedOutBatch(t *testing.T) {
	prefix := uniquePrefix("tnpriv_batch")
	uid := createTNMemberWithIdentity(t, prefix)
	other := CreateTestUser(t, prefix+"_fd", "User")

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/user/%d,%d", uid, other), nil))
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var users []map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&users))
	require.Len(t, users, 2)

	for _, u := range users {
		if uint64(u["id"].(float64)) == uid {
			assertTNIdentityHidden(t, u, prefix)
		} else {
			assert.NotEqual(t, "A freegler", u["displayname"])
			assert.Nil(t, u["redacted"])
		}
	}
}

func TestTNMemberIdentityShownWhenLoggedIn(t *testing.T) {
	prefix := uniquePrefix("tnpriv_in")
	uid := createTNMemberWithIdentity(t, prefix)
	_, token := CreateFullTestUser(t, prefix+"_viewer")

	u := fetchUserJSON(t, fmt.Sprintf("/api/user/%d?jwt=%s", uid, token))
	assert.Contains(t, u["displayname"], "Test User")
	assert.Nil(t, u["redacted"])
	aboutme, _ := u["aboutme"].(map[string]interface{})
	assert.Equal(t, "About "+prefix, aboutme["text"])
	profile, _ := u["profile"].(map[string]interface{})
	assert.Equal(t, "https://example.com/"+prefix+".jpg", profile["path"])
}

func TestTNMemberIdentityShownToPartner(t *testing.T) {
	prefix := uniquePrefix("tnpriv_partner")
	db := database.DBConn
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", partnerKey, "user.trashnothing.com")
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")
	uid := createTNMemberWithIdentity(t, prefix)

	u := fetchUserJSON(t, fmt.Sprintf("/api/user/%d?partner=%s", uid, partnerKey))
	assert.Contains(t, u["displayname"], "Test User")
	assert.Nil(t, u["redacted"])
}

func TestNonTNMemberIdentityShownWhenLoggedOut(t *testing.T) {
	prefix := uniquePrefix("tnpriv_fd")
	uid := CreateTestUser(t, prefix, "User")

	u := fetchUserJSON(t, fmt.Sprintf("/api/user/%d", uid))
	assert.Contains(t, u["displayname"], "Test User")
	assert.Nil(t, u["redacted"])
}
