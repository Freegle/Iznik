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
	"github.com/freegle/iznik-server-go/utils"
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
	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)

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
	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)

	mergeID, _ := createTestMerge(t, user1ID, user2ID, modID)

	// GET with wrong uid.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/merge?id=%d&uid=wrong_uid_value", mergeID), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestCreateMerge(t *testing.T) {
	prefix := uniquePrefix("MergeCrt")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, modID, groupID, utils.ROLE_OWNER)
	_, token := CreateTestSession(t, modID)

	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, user1ID, groupID, utils.ROLE_MEMBER)
	CreateTestMembership(t, user2ID, groupID, utils.ROLE_MEMBER)

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

// A merge offer, once accepted, merges the two accounts, so offering one follows the moderator
// merge rules: the moderator must moderate both accounts, neither may be their own, and neither
// may be an Admin or Support account.
func TestCreateMergeByModeratorFollowsTheModeratorMergeRules(t *testing.T) {
	prefix := uniquePrefix("MergeCrtRules")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	otherGroupID := CreateTestGroup(t, prefix+"_other")
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	_, token := CreateTestSession(t, modID)

	memberA := CreateTestUser(t, prefix+"_a", utils.SYSTEMROLE_USER)
	memberB := CreateTestUser(t, prefix+"_b", utils.SYSTEMROLE_USER)
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	outsider := CreateTestUser(t, prefix+"_out", utils.SYSTEMROLE_USER)
	for _, id := range []uint64{memberA, memberB, adminID} {
		CreateTestMembership(t, id, groupID, utils.ROLE_MEMBER)
	}
	CreateTestMembership(t, outsider, otherGroupID, utils.ROLE_MEMBER)

	offer := func(u1, u2 uint64) int {
		body := fmt.Sprintf(`{"user1":%d,"user2":%d,"email":false}`, u1, u2)
		req := httptest.NewRequest("PUT", fmt.Sprintf("/api/merge?jwt=%s", token), strings.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		return resp.StatusCode
	}

	assert.Equal(t, 403, offer(memberA, modID), "the moderator's own account")
	assert.Equal(t, 403, offer(modID, memberA), "the moderator's own account, either way round")
	assert.Equal(t, 403, offer(memberA, adminID), "an Admin account")
	assert.Equal(t, 403, offer(adminID, memberA), "an Admin account, either way round")
	assert.Equal(t, 403, offer(memberA, outsider), "an account the moderator does not moderate")

	var offers int64
	db.Raw("SELECT COUNT(*) FROM merges WHERE offeredby = ?", modID).Scan(&offers)
	assert.Equal(t, int64(0), offers, "a refused offer leaves no merge row to accept")

	assert.Equal(t, 200, offer(memberA, memberB), "two members of the moderator's community")
}

func TestCreateMergeNotMod(t *testing.T) {
	prefix := uniquePrefix("MergeCrtNM")
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, userID)

	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)

	body := fmt.Sprintf(`{"user1":%d,"user2":%d}`, user1ID, user2ID)
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/merge?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestPostMergeAccept(t *testing.T) {
	prefix := uniquePrefix("MergeAcc")
	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)

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

// postMergeAction sends the member's answer to a merge offer, as the /merge page does.
func postMergeAction(t *testing.T, mergeID uint64, uid string, keep uint64, other uint64, action string) int {
	body := fmt.Sprintf(`{"id":%d,"uid":"%s","user1":%d,"user2":%d,"action":"%s"}`,
		mergeID, uid, keep, other, action)
	req := httptest.NewRequest("POST", "/api/merge", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode
}

// The /merge page tells the member "We've merged your accounts", so Accept must merge them: the
// account sent as user1 is kept and the other is merged into it.
func TestPostMergeAcceptMergesTheAccounts(t *testing.T) {
	prefix := uniquePrefix("MergeAccDo")
	offered1 := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	offered2 := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	db := database.DBConn

	mergeID, uid := createTestMerge(t, offered1, offered2, modID)

	// The member keeps the second account of the offer.
	assert.Equal(t, 200, postMergeAction(t, mergeID, uid, offered2, offered1, "Accept"))

	assert.True(t, userExists(offered2), "the chosen account is kept")
	assert.False(t, userExists(offered1), "the other account is merged away")

	var emails int64
	db.Raw("SELECT COUNT(*) FROM users_emails WHERE userid = ? AND email = ?",
		offered2, prefix+"_u1@test.com").Scan(&emails)
	assert.Equal(t, int64(1), emails, "the merged account's email moves to the kept account")

	assert.NotNil(t, findLog(db, "User", "Merged", offered1), "a Merged log row for the discarded account")
	assert.NotNil(t, findLog(db, "User", "Merged", offered2), "a Merged log row for the kept account")
}

// A TrashNothing member is never merged with a Freegle account, so accepting such an offer is
// refused and both accounts stay.
func TestPostMergeAcceptRefusesTrashNothingWithFreegle(t *testing.T) {
	prefix := uniquePrefix("MergeAccTN")
	fd := CreateTestUser(t, prefix+"_fd", utils.SYSTEMROLE_USER)
	tn := CreateTestUser(t, prefix+"_tn", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	stampTN(tn, 991090)

	mergeID, uid := createTestMerge(t, fd, tn, modID)

	assert.Equal(t, 400, postMergeAction(t, mergeID, uid, fd, tn, "Accept"))
	assert.True(t, userExists(fd))
	assert.True(t, userExists(tn))
}

func TestPostMergeReject(t *testing.T) {
	prefix := uniquePrefix("MergeRej")
	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)

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
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, modID, groupID, utils.ROLE_OWNER)
	_, token := CreateTestSession(t, modID)

	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)

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
	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)

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
	user1ID := CreateTestUser(t, prefix+"_u1", utils.SYSTEMROLE_USER)
	user2ID := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)

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
