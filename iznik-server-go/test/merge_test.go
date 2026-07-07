package test

import (
	"crypto/rand"
	"encoding/hex"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func generateTestUID() string {
	b := make([]byte, 16)
	rand.Read(b)
	return hex.EncodeToString(b)
}

func createTestMerge(t *testing.T, user1 uint64, user2 uint64, offeredby uint64) (uint64, string) {
	db := database.DBConn
	uid := generateTestUID()

	result := db.Exec("INSERT INTO merges (user1, user2, offeredby, uid) VALUES (?, ?, ?, ?)",
		user1, user2, offeredby, uid)
	assert.NoError(t, result.Error)

	var id uint64
	db.Raw("SELECT id FROM merges WHERE uid = ? ORDER BY id DESC LIMIT 1", uid).Scan(&id)
	assert.Greater(t, id, uint64(0))
	return id, uid
}

func TestGetMerge(t *testing.T) {
	prefix := uniquePrefix("MergeGet")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	mergeID, uid := createTestMerge(t, user1ID, user2ID, modID)

	// GET with valid id and uid.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/merge?id=%d&uid=%s", mergeID, uid), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "merge")

	mergeData := result["merge"].(map[string]interface{})
	assert.Equal(t, float64(mergeID), mergeData["id"])
	assert.Equal(t, uid, mergeData["uid"])
	assert.Contains(t, mergeData, "user1")
	assert.Contains(t, mergeData, "user2")

	u1 := mergeData["user1"].(map[string]interface{})
	assert.Equal(t, float64(user1ID), u1["id"])
	assert.Contains(t, u1, "name")
	assert.Contains(t, u1, "email")
}

func TestGetMergeInvalidUid(t *testing.T) {
	prefix := uniquePrefix("MergeInvUID")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	mergeID, _ := createTestMerge(t, user1ID, user2ID, modID)

	// GET with wrong uid.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/merge?id=%d&uid=wrong_uid_value", mergeID), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestCreateMerge(t *testing.T) {
	prefix := uniquePrefix("MergeCrt")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)

	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")

	body := fmt.Sprintf(`{"user1":%d,"user2":%d,"email":false}`, user1ID, user2ID)
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/merge?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Greater(t, result["id"].(float64), float64(0))
	assert.NotEmpty(t, result["uid"])
}

func TestCreateMergeNotMod(t *testing.T) {
	prefix := uniquePrefix("MergeCrtNM")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")

	body := fmt.Sprintf(`{"user1":%d,"user2":%d}`, user1ID, user2ID)
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/merge?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestPostMergeAccept(t *testing.T) {
	prefix := uniquePrefix("MergeAcc")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	mergeID, uid := createTestMerge(t, user1ID, user2ID, modID)

	body := fmt.Sprintf(`{"id":%d,"uid":"%s","user1":%d,"user2":%d,"action":"Accept"}`,
		mergeID, uid, user1ID, user2ID)
	req := httptest.NewRequest("POST", "/api/merge", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify accepted is set.
	db := database.DBConn
	var accepted *string
	db.Raw("SELECT accepted FROM merges WHERE id = ?", mergeID).Scan(&accepted)
	assert.NotNil(t, accepted)
}

func TestPostMergeReject(t *testing.T) {
	prefix := uniquePrefix("MergeRej")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	mergeID, uid := createTestMerge(t, user1ID, user2ID, modID)

	body := fmt.Sprintf(`{"id":%d,"uid":"%s","user1":%d,"user2":%d,"action":"Reject"}`,
		mergeID, uid, user1ID, user2ID)
	req := httptest.NewRequest("POST", "/api/merge", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify rejected is set.
	db := database.DBConn
	var rejected *string
	db.Raw("SELECT rejected FROM merges WHERE id = ?", mergeID).Scan(&rejected)
	assert.NotNil(t, rejected)
}

func TestDeleteMerge(t *testing.T) {
	prefix := uniquePrefix("MergeDel")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)

	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")

	// Insert a users_related row to test the notified flag update.
	db := database.DBConn
	db.Exec("INSERT IGNORE INTO users_related (user1, user2, notified) VALUES (?, ?, 0)", user1ID, user2ID)

	body := fmt.Sprintf(`{"user1":%d,"user2":%d}`, user1ID, user2ID)
	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/merge?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify notified is set.
	var notified int
	db.Raw("SELECT notified FROM users_related WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)",
		user1ID, user2ID, user2ID, user1ID).Scan(&notified)
	assert.Equal(t, 1, notified)
}

func TestGetMergeV2Path(t *testing.T) {
	req := httptest.NewRequest("GET", "/apiv2/merge", nil)
	resp, _ := getApp().Test(req)
	// Returns 400 because id and uid params are required; confirms route is registered.
	assert.Equal(t, 400, resp.StatusCode)
}

// GetMerge must return a `logins` array for each user. V1 returns
// $u->getLogins(FALSE) (array of users_logins rows) — the merge.vue page
// iterates logins.forEach to label each signin method. Sentry issue
// 7384446789 shows 4+/hr crashes ("Cannot read properties of undefined")
// because V2 Go was omitting the field entirely.
func TestGetMergeReturnsLoginsForBothUsers(t *testing.T) {
	prefix := uniquePrefix("MergeLogins")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	db := database.DBConn

	// user1 has a Native and a Google login.
	db.Exec("INSERT INTO users_logins (userid, type, uid, credentials) VALUES (?, 'Native', ?, 'hashed')",
		user1ID, fmt.Sprintf("native-%s-1", prefix))
	db.Exec("INSERT INTO users_logins (userid, type, uid) VALUES (?, 'Google', ?)",
		user1ID, fmt.Sprintf("google-%s-1", prefix))

	// user2 has only a Facebook login.
	db.Exec("INSERT INTO users_logins (userid, type, uid) VALUES (?, 'Facebook', ?)",
		user2ID, fmt.Sprintf("fb-%s-2", prefix))

	mergeID, uid := createTestMerge(t, user1ID, user2ID, modID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/merge?id=%d&uid=%s", mergeID, uid), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	mergeData := result["merge"].(map[string]interface{})
	u1 := mergeData["user1"].(map[string]interface{})
	u2 := mergeData["user2"].(map[string]interface{})

	// Must always be an array — never missing, never null.
	assert.Contains(t, u1, "logins")
	assert.Contains(t, u2, "logins")

	u1Logins, ok := u1["logins"].([]interface{})
	assert.True(t, ok, "user1.logins must be a JSON array")
	u2Logins, ok := u2["logins"].([]interface{})
	assert.True(t, ok, "user2.logins must be a JSON array")

	// Collect the types.
	u1Types := map[string]bool{}
	for _, l := range u1Logins {
		m := l.(map[string]interface{})
		u1Types[m["type"].(string)] = true
		// Credentials must NOT be exposed (V1 calls getLogins(FALSE)).
		_, hasCreds := m["credentials"]
		assert.False(t, hasCreds, "credentials must be stripped from login rows")
	}
	assert.True(t, u1Types["Native"], "user1 logins missing Native")
	assert.True(t, u1Types["Google"], "user1 logins missing Google")

	u2Types := map[string]bool{}
	for _, l := range u2Logins {
		m := l.(map[string]interface{})
		u2Types[m["type"].(string)] = true
	}
	assert.True(t, u2Types["Facebook"], "user2 logins missing Facebook")
}

// Even a user with zero login rows must get an empty array, never a null
// or missing field — otherwise forEach() would still crash the merge page.
func TestGetMergeReturnsEmptyLoginsArrayForUserWithoutLogins(t *testing.T) {
	prefix := uniquePrefix("MergeNoLogins")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")

	db := database.DBConn
	db.Exec("DELETE FROM users_logins WHERE userid IN (?, ?)", user1ID, user2ID)

	mergeID, uid := createTestMerge(t, user1ID, user2ID, modID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/merge?id=%d&uid=%s", mergeID, uid), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	mergeData := result["merge"].(map[string]interface{})
	u1 := mergeData["user1"].(map[string]interface{})
	u2 := mergeData["user2"].(map[string]interface{})

	l1, ok1 := u1["logins"].([]interface{})
	assert.True(t, ok1, "user1.logins must be [] not null/missing")
	assert.Equal(t, 0, len(l1))

	l2, ok2 := u2["logins"].([]interface{})
	assert.True(t, ok2, "user2.logins must be [] not null/missing")
	assert.Equal(t, 0, len(l2))
}
