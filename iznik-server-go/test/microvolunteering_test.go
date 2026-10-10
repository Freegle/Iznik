package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/stretchr/testify/assert"

)

func TestGetMicrovolunteering_NotLoggedIn(t *testing.T) {
	// Test without authentication - should return 401
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering", nil))
	assert.Equal(t, 401, resp.StatusCode)

	var result map[string]string
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, "Not logged in", result["error"])
}

func TestGetMicrovolunteering_DeclinedUser(t *testing.T) {
	db := database.DBConn

	// Create a test user with Declined trust level
	var userID uint64
	db.Exec("INSERT INTO users (firstname, lastname, systemrole, trustlevel) VALUES ('MVTest', 'User3', 'User', ?)", microvolunteering.TrustDeclined)
	db.Raw("SELECT id FROM users WHERE firstname = 'MVTest' AND lastname = 'User3' ORDER BY id DESC LIMIT 1").Scan(&userID)
	defer db.Exec("DELETE FROM users WHERE id = ?", userID)

	// Get JWT token for this user
	token := getToken(t, userID)

	// Make authenticated request
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token, nil))

	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	// Declined users should not get challenges
	assert.NotContains(t, result, "type")
}

func TestGetMicrovolunteering_ExcludedUser(t *testing.T) {
	db := database.DBConn

	// Create a test user with Excluded trust level
	var userID uint64
	db.Exec("INSERT INTO users (firstname, lastname, systemrole, trustlevel) VALUES ('MVTest', 'User4', 'User', ?)", microvolunteering.TrustExcluded)
	db.Raw("SELECT id FROM users WHERE firstname = 'MVTest' AND lastname = 'User4' ORDER BY id DESC LIMIT 1").Scan(&userID)
	defer db.Exec("DELETE FROM users WHERE id = ?", userID)

	// Get JWT token for this user
	token := getToken(t, userID)

	// Make authenticated request
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token, nil))

	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	// Excluded users should not get challenges
	assert.NotContains(t, result, "type")
}

func TestGetMicrovolunteering_InviteChallenge(t *testing.T) {
	db := database.DBConn

	// Create a test user
	var userID uint64
	db.Exec("INSERT INTO users (firstname, lastname, systemrole) VALUES ('MVTest', 'User5', 'User')")
	db.Raw("SELECT id FROM users WHERE firstname = 'MVTest' AND lastname = 'User5' ORDER BY id DESC LIMIT 1").Scan(&userID)
	defer db.Exec("DELETE FROM users WHERE id = ?", userID)

	// Clean up any existing invite microactions for this user
	db.Exec("DELETE FROM microactions WHERE userid = ? AND actiontype = ?", userID, microvolunteering.ChallengeInvite)
	defer db.Exec("DELETE FROM microactions WHERE userid = ? AND actiontype = ?", userID, microvolunteering.ChallengeInvite)

	// Get JWT token for this user
	token := getToken(t, userID)

	// Make authenticated request
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token, nil))

	assert.Equal(t, 200, resp.StatusCode)

	var result microvolunteering.Challenge
	json2.Unmarshal(rsp(resp), &result)

	// Should return invite challenge
	assert.Equal(t, microvolunteering.ChallengeInvite, result.Type)
}

func TestMicroVolunteeringResponseCheckMessage(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("mv_checkmsg")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Create a message from a different user
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	msgID := CreateTestMessage(t, senderID, "Test MV Check "+prefix, 55.9533, -3.1883)

	body := fmt.Sprintf(`{"msgid":%d,"response":"Approve","comments":"Looks good"}`, msgID)
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+token,
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify the microaction was recorded
	var actionType string
	var actionResult string
	db.Raw("SELECT actiontype, result FROM microactions WHERE userid = ? AND msgid = ? ORDER BY id DESC LIMIT 1",
		userID, msgID).Row().Scan(&actionType, &actionResult)
	assert.Equal(t, microvolunteering.ChallengeCheckMessage, actionType)
	assert.Equal(t, "Approve", actionResult)
}

// TestMicroVolunteeringResponseCheckMessageOwnMessageDenied verifies that even a
// group member cannot cast a verdict on their OWN post (fromuser != voter).
func TestMicroVolunteeringResponseCheckMessageOwnMessageDenied(t *testing.T) {
	prefix := uniquePrefix("mv_ownmsg")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, "Test MV Own "+prefix, 55.9533, -3.1883)

	body := fmt.Sprintf(`{"msgid":%d,"response":"Reject","comments":"self","msgcategory":"ShouldntBeHere"}`, msgID)
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

// TestMicroVolunteeringResponsePhotoRotateOwnPhotoAllowed verifies that, unlike
// CheckMessage, the PhotoRotate branch does NOT exclude the author:
// getPhotoRotateChallenge can legitimately serve a user their own freshly-posted
// photo, so responding to it must succeed (not be a false-positive 403).
func TestMicroVolunteeringResponsePhotoRotateOwnPhotoAllowed(t *testing.T) {
	prefix := uniquePrefix("mv_photo_own")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	msgID := CreateTestMessage(t, userID, "Test MV Photo Own "+prefix, 55.9533, -3.1883)
	photoID := CreateTestAttachment(t, msgID)

	body := fmt.Sprintf(`{"photoid":%d,"response":"Approve","deg":90}`, photoID)
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

// (Retired) TestMicroVolunteeringResponseSearchTerm removed with the SearchTerm
// challenge — the keyword-similarity dataset it built is obsolete under vector
// search.

func TestMicroVolunteeringResponsePhotoRotate(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("mv_photo")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Create a message with an attachment
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	msgID := CreateTestMessage(t, senderID, "Test Photo "+prefix, 55.9533, -3.1883)
	photoID := CreateTestAttachment(t, msgID)

	body := fmt.Sprintf(`{"photoid":%d,"response":"Approve","deg":90}`, photoID)
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+token,
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify the microaction was recorded
	var actionType string
	db.Raw("SELECT actiontype FROM microactions WHERE userid = ? AND rotatedimage = ? ORDER BY id DESC LIMIT 1",
		userID, photoID).Row().Scan(&actionType)
	assert.Equal(t, microvolunteering.ChallengePhotoRotate, actionType)
}

func TestMicroVolunteeringResponseInvite(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("mv_invite")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `{"invite":true,"response":"Yes"}`
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+token,
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify the microaction was recorded.
	// The result column is enum('Approve','Reject') so the "Yes" response can't be stored there.
	// We just verify the row was created with the correct actiontype.
	var actionType string
	db.Raw("SELECT actiontype FROM microactions WHERE userid = ? AND actiontype = ? ORDER BY id DESC LIMIT 1",
		userID, microvolunteering.ChallengeInvite).Scan(&actionType)
	assert.Equal(t, microvolunteering.ChallengeInvite, actionType)
}

func TestMicroVolunteeringResponseUnauthorized(t *testing.T) {
	body := `{"msgid":1,"response":"Approve"}`
	req := httptest.NewRequest("POST", "/api/microvolunteering",
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestMicroVolunteeringResponseInvalidParams(t *testing.T) {
	prefix := uniquePrefix("mv_invalid")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Send empty body - no valid parameters
	body := `{}`
	req := httptest.NewRequest("POST", "/api/microvolunteering?jwt="+token,
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

// =============================================================================
// PATCH /microvolunteering - Moderator Feedback
// =============================================================================

func TestModFeedbackAsMod(t *testing.T) {
	prefix := uniquePrefix("mv_modfb")
	db := database.DBConn

	// Create a mod user with system role that passes IsSystemMod.
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	// Create a microaction to provide feedback on.
	regularID := CreateTestUser(t, prefix+"_user", "User")
	db.Exec("INSERT INTO microactions (userid, actiontype, result, timestamp, score_negative) VALUES (?, 'CheckMessage', 'Approve', NOW(), 0)", regularID)
	var actionID uint64
	db.Raw("SELECT id FROM microactions WHERE userid = ? ORDER BY id DESC LIMIT 1", regularID).Scan(&actionID)
	assert.NotZero(t, actionID)

	body := fmt.Sprintf(`{"id":%d,"feedback":"Good job","score_positive":0.8,"score_negative":0.1}`, actionID)
	req := httptest.NewRequest("PATCH", "/api/microvolunteering?jwt="+modToken,
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify in DB.
	var modfeedback string
	db.Raw("SELECT modfeedback FROM microactions WHERE id = ?", actionID).Scan(&modfeedback)
	assert.Equal(t, "Good job", modfeedback)
}

func TestModFeedbackAsRegularUserFails(t *testing.T) {
	prefix := uniquePrefix("mv_modfb_no")

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	body := `{"id":1,"feedback":"Should fail"}`
	req := httptest.NewRequest("PATCH", "/api/microvolunteering?jwt="+token,
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestModFeedbackNotLoggedIn(t *testing.T) {
	body := `{"id":1,"feedback":"Should fail"}`
	req := httptest.NewRequest("PATCH", "/api/microvolunteering",
		strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestListMicroActions(t *testing.T) {
	// GET /microvolunteering?list=true returns microactions
	// for groups the mod moderates.
	prefix := uniquePrefix("MicroList")
	db := database.DBConn

	memberID := CreateTestUser(t, prefix+"_member", "User")

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	PromoteTestUserToModerator(t, modID)
	_, modToken := CreateTestSession(t, modID)

	// Create a microaction by the member.
	db.Exec("INSERT INTO microactions (actiontype, userid, result, timestamp, score_negative) VALUES ('CheckMessage', ?, 'Approve', NOW(), 0)", memberID)
	var actionID uint64
	db.Raw("SELECT id FROM microactions WHERE userid = ? ORDER BY id DESC LIMIT 1", memberID).Scan(&actionID)

	// Mod should see the microaction via list=true.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/microvolunteering?list=true&jwt=%s", modToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	items := result["microvolunteerings"].([]interface{})
	found := false
	for _, item := range items {
		m := item.(map[string]interface{})
		if uint64(m["id"].(float64)) == actionID {
			found = true
		}
	}
	assert.True(t, found, "Mod should see the microaction")

	// Cleanup.
	db.Exec("DELETE FROM microactions WHERE id = ?", actionID)
}

// TestGetMicrovolunteering_EEELabel_RestrictsToClassifiedItems exercises
// the rule that EEELabel only serves attachments the model classifier has
// already processed (i.e. present in `eee_classified_attachments`). Without
// this rule, MV labels accumulate on items the model never saw, so the
// Condition/Weight/Size accuracy column on the eee-browser dashboard is
// permanently empty.
//
// Set-up: insert one OFFER attachment that IS in eee_classified_attachments
// and one that isn't. Asserts EEELabel may serve the classified one but
// never the unclassified one.
func TestGetMicrovolunteering_EEELabel_RestrictsToClassifiedItems(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("mv_eee_classified")

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	// Suppress Invite challenge so EEELabel can win the dispatch.
	db.Exec("INSERT INTO microactions (actiontype, userid, version, timestamp, score_negative) VALUES (?, ?, 4, NOW(), 0)",
		microvolunteering.ChallengeInvite, userID)
	defer db.Exec("DELETE FROM microactions WHERE userid = ?", userID)

	// Two OFFER messages, each with one attachment. The first is in
	// eee_classified_attachments, the second is not.
	var msgClassified, msgUnclassified uint64
	db.Exec(`INSERT INTO messages (fromuser, subject, textbody, message, type, arrival, deleted)
		VALUES (?, ?, 'test', 'test', 'Offer', NOW(), NULL)`,
		userID, prefix+" classified item")
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&msgClassified)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msgClassified)

	db.Exec(`INSERT INTO messages (fromuser, subject, textbody, message, type, arrival, deleted)
		VALUES (?, ?, 'test', 'test', 'Offer', NOW(), NULL)`,
		userID, prefix+" unclassified item")
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&msgUnclassified)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msgUnclassified)

	var attClassified, attUnclassified uint64
	db.Exec("INSERT INTO messages_attachments (msgid, archived, externaluid, `primary`) VALUES (?, 0, ?, 1)",
		msgClassified, prefix+"-photo-classified")
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&attClassified)
	defer db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgClassified)

	db.Exec("INSERT INTO messages_attachments (msgid, archived, externaluid, `primary`) VALUES (?, 0, ?, 1)",
		msgUnclassified, prefix+"-photo-unclassified")
	db.Raw("SELECT LAST_INSERT_ID()").Scan(&attUnclassified)
	defer db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgUnclassified)

	// Pointer in MySQL says only the first attachment has been classified.
	db.Exec("INSERT IGNORE INTO eee_classified_attachments (messageid, attid) VALUES (?, ?)",
		msgClassified, attClassified)
	defer db.Exec("DELETE FROM eee_classified_attachments WHERE messageid IN (?, ?)", msgClassified, msgUnclassified)

	// Make many EEELabel requests as this user. None should ever resolve to
	// the unclassified attachment, even though it's an otherwise-eligible
	// recent OFFER with a photo.
	for i := 0; i < 5; i++ {
		req := httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=EEELabel", nil)
		resp, _ := getApp().Test(req)
		assert.Equal(t, 200, resp.StatusCode)

		var result microvolunteering.Challenge
		json2.Unmarshal(rsp(resp), &result)

		if result.EEELabel != nil {
			assert.NotEqual(t, attUnclassified, result.EEELabel.Attid,
				"EEELabel must never serve an attachment that is not in eee_classified_attachments")
			assert.NotEqual(t, msgUnclassified, result.EEELabel.Messageid,
				"EEELabel must never serve a message whose attachments are not in eee_classified_attachments")
		}
	}
}
