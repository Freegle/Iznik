package test

import (
	json2 "encoding/json"
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"net/http/httptest"
	"testing"
	"time"
)

func TestChangesNoPartnerKey(t *testing.T) {
	// Should reject requests without a partner key.
	req := httptest.NewRequest("GET", "/api/changes", nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestChangesInvalidPartnerKey(t *testing.T) {
	// Should reject requests with an invalid partner key.
	req := httptest.NewRequest("GET", "/api/changes?partner=invalid_key_xyz", nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestChangesValidPartner(t *testing.T) {
	prefix := uniquePrefix("changes")
	db := database.DBConn

	// Create a test partner key.
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Request changes with valid partner key.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])

	changes, ok := result["changes"].(map[string]interface{})
	require.True(t, ok)

	// All three arrays should be present.
	assert.NotNil(t, changes["messages"])
	assert.NotNil(t, changes["users"])
	assert.NotNil(t, changes["ratings"])
}

func TestChangesWithSince(t *testing.T) {
	prefix := uniquePrefix("changes_since")
	db := database.DBConn

	// Create a test partner key.
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Request with a since parameter far in the future — should return empty results.
	futureTime := time.Now().Add(24 * time.Hour).Format(time.RFC3339)
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s&since=%s", partnerKey, futureTime), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	changes := result["changes"].(map[string]interface{})
	messages := changes["messages"].([]interface{})
	users := changes["users"].([]interface{})
	ratings := changes["ratings"].([]interface{})
	assert.Equal(t, 0, len(messages))
	assert.Equal(t, 0, len(users))
	assert.Equal(t, 0, len(ratings))
}

func TestChangesMessageOutcome(t *testing.T) {
	prefix := uniquePrefix("changes_msg")
	db := database.DBConn

	// Create partner key.
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Create a test user, group, and message.
	groupID := CreateTestGroup(t, prefix)
	defer db.Exec("DELETE FROM `groups` WHERE id = ?", groupID)

	userID := CreateTestUser(t, prefix, "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", userID)

	msgID := CreateTestMessage(t, userID, groupID, "OFFER: "+prefix+" test item", 55.95, -3.19)
	defer db.Exec("DELETE FROM messages WHERE id = ?", msgID)

	// Add a message outcome.
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, timestamp) VALUES (?, 'Taken', NOW())", msgID)
	defer db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", msgID)

	// Request changes — should include the outcome.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	changes := result["changes"].(map[string]interface{})
	messages := changes["messages"].([]interface{})

	// Find our message in the results.
	found := false
	for _, m := range messages {
		msg := m.(map[string]interface{})
		if uint64(msg["id"].(float64)) == msgID {
			assert.Equal(t, "Taken", msg["type"])
			found = true
			break
		}
	}
	assert.True(t, found, "Expected message outcome in changes")
}

func TestChangesRatings(t *testing.T) {
	prefix := uniquePrefix("changes_rate")
	db := database.DBConn

	// Create partner key.
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Create two test users.
	raterID := CreateTestUser(t, prefix+"_rater", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", raterID)

	rateeID := CreateTestUser(t, prefix+"_ratee", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", rateeID)

	// Create a rating.
	db.Exec("INSERT INTO ratings (rater, ratee, rating, timestamp, visible) VALUES (?, ?, 'Up', NOW(), 1)", raterID, rateeID)
	defer db.Exec("DELETE FROM ratings WHERE rater = ? AND ratee = ?", raterID, rateeID)

	// Request changes — should include the rating.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	changes := result["changes"].(map[string]interface{})
	ratings := changes["ratings"].([]interface{})

	found := false
	for _, r := range ratings {
		rating := r.(map[string]interface{})
		if uint64(rating["rater"].(float64)) == raterID && uint64(rating["ratee"].(float64)) == rateeID {
			assert.Equal(t, "Up", rating["rating"])
			found = true
			break
		}
	}
	assert.True(t, found, "Expected rating in changes")
}

func TestChangesUserLastUpdatedNotEmpty(t *testing.T) {
	// User changes must have a real date in lastupdated, not an empty string.
	prefix := uniquePrefix("changes_usr")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	userID := CreateTestUser(t, prefix, "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", userID)

	// Set lastupdated to NOW so it appears in results.
	db.Exec("UPDATE users SET lastupdated = NOW() WHERE id = ?", userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	changes := result["changes"].(map[string]interface{})
	users := changes["users"].([]interface{})

	found := false
	for _, u := range users {
		user := u.(map[string]interface{})
		if uint64(user["id"].(float64)) == userID {
			lu := user["lastupdated"].(string)
			assert.NotEmpty(t, lu, "lastupdated should not be empty")
			assert.Contains(t, lu, "T", "lastupdated should be ISO8601 format")
			found = true
			break
		}
	}
	assert.True(t, found, "Expected user in changes")
}

func TestChangesRatingHasIdAndTnRatingId(t *testing.T) {
	// Ratings must include id and tn_rating_id fields.
	prefix := uniquePrefix("changes_rid")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	raterID := CreateTestUser(t, prefix+"_rater", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", raterID)

	rateeID := CreateTestUser(t, prefix+"_ratee", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", rateeID)

	db.Exec("INSERT INTO ratings (rater, ratee, rating, timestamp, visible, tn_rating_id) VALUES (?, ?, 'Up', NOW(), 1, 12345)", raterID, rateeID)
	defer db.Exec("DELETE FROM ratings WHERE rater = ? AND ratee = ?", raterID, rateeID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	changes := result["changes"].(map[string]interface{})
	ratings := changes["ratings"].([]interface{})

	found := false
	for _, r := range ratings {
		rating := r.(map[string]interface{})
		if uint64(rating["rater"].(float64)) == raterID {
			// id must be present and non-zero.
			id, ok := rating["id"]
			assert.True(t, ok, "rating must have id field")
			assert.Greater(t, id.(float64), float64(0), "rating id must be > 0")

			// tn_rating_id must be present.
			tnid, ok := rating["tn_rating_id"]
			assert.True(t, ok, "rating must have tn_rating_id field")
			assert.Equal(t, float64(12345), tnid.(float64))
			found = true
			break
		}
	}
	assert.True(t, found, "Expected rating in changes")
}

func TestChangesInvalidSince(t *testing.T) {
	prefix := uniquePrefix("changes_bad")
	db := database.DBConn

	// Create partner key.
	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Request with invalid since parameter.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s&since=not-a-date", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestChangesRatingCommentField(t *testing.T) {
	// Rating response must include comment field (text column from ratings table).
	prefix := uniquePrefix("changes_comment")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	raterID := CreateTestUser(t, prefix+"_rater", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", raterID)

	rateeID := CreateTestUser(t, prefix+"_ratee", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", rateeID)

	// Create a rating with a comment (text field).
	comment := "Great trader, very responsive!"
	db.Exec("INSERT INTO ratings (rater, ratee, rating, timestamp, visible, text) VALUES (?, ?, 'Up', NOW(), 1, ?)", raterID, rateeID, comment)
	defer db.Exec("DELETE FROM ratings WHERE rater = ? AND ratee = ?", raterID, rateeID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	changes := result["changes"].(map[string]interface{})
	ratings := changes["ratings"].([]interface{})

	found := false
	for _, r := range ratings {
		rating := r.(map[string]interface{})
		if uint64(rating["rater"].(float64)) == raterID {
			// comment field must be present and contain the inserted text.
			commentField, ok := rating["comment"]
			assert.True(t, ok, "rating must have comment field")
			assert.Equal(t, comment, commentField.(string), "rating comment must match database text field")
			found = true
			break
		}
	}
	assert.True(t, found, "Expected rating with comment in changes")
}

func TestChangesRatingReasonField(t *testing.T) {
	// Rating response must include reason field from ratings table.
	prefix := uniquePrefix("changes_reason")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	raterID := CreateTestUser(t, prefix+"_rater", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", raterID)

	rateeID := CreateTestUser(t, prefix+"_ratee", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", rateeID)

	// Create a rating with a reason.
	db.Exec("INSERT INTO ratings (rater, ratee, rating, timestamp, visible, reason) VALUES (?, ?, 'Down', NOW(), 1, 'Ghosted')", raterID, rateeID)
	defer db.Exec("DELETE FROM ratings WHERE rater = ? AND ratee = ?", raterID, rateeID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	changes := result["changes"].(map[string]interface{})
	ratings := changes["ratings"].([]interface{})

	found := false
	for _, r := range ratings {
		rating := r.(map[string]interface{})
		if uint64(rating["rater"].(float64)) == raterID {
			// reason field must be present.
			reasonField, ok := rating["reason"]
			assert.True(t, ok, "rating must have reason field")
			assert.Equal(t, "Ghosted", reasonField.(string), "rating reason must match database value")
			found = true
			break
		}
	}
	assert.True(t, found, "Expected rating with reason in changes")
}

func TestChangesRatingAllFieldsPresent(t *testing.T) {
	// Verify that Rating response includes all required fields: id, rating, comment, tn_rating_id, reason.
	prefix := uniquePrefix("changes_all_fields")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	raterID := CreateTestUser(t, prefix+"_rater", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", raterID)

	rateeID := CreateTestUser(t, prefix+"_ratee", "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", rateeID)

	// Create a fully-populated rating.
	comment := "Excellent service"
	db.Exec("INSERT INTO ratings (rater, ratee, rating, timestamp, visible, tn_rating_id, reason, text) VALUES (?, ?, 'Up', NOW(), 1, ?, 'Punctuality', ?)",
		raterID, rateeID, 999, comment)
	defer db.Exec("DELETE FROM ratings WHERE rater = ? AND ratee = ?", raterID, rateeID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	changes := result["changes"].(map[string]interface{})
	ratings := changes["ratings"].([]interface{})

	found := false
	for _, r := range ratings {
		rating := r.(map[string]interface{})
		if uint64(rating["rater"].(float64)) == raterID {
			// Verify all fields are present in response.
			assert.NotNil(t, rating["id"], "rating must have id field")
			assert.NotNil(t, rating["rating"], "rating must have rating field")
			assert.NotNil(t, rating["comment"], "rating must have comment field")
			assert.NotNil(t, rating["tn_rating_id"], "rating must have tn_rating_id field")
			assert.NotNil(t, rating["reason"], "rating must have reason field")

			// Verify values.
			assert.Greater(t, rating["id"].(float64), float64(0), "id must be > 0")
			assert.Equal(t, "Up", rating["rating"].(string), "rating value must match")
			assert.Equal(t, comment, rating["comment"].(string), "comment must match")
			assert.Equal(t, float64(999), rating["tn_rating_id"].(float64), "tn_rating_id must match")
			assert.Equal(t, "Punctuality", rating["reason"].(string), "reason must match")
			found = true
			break
		}
	}
	assert.True(t, found, "Expected rating with all fields in changes")
}

func TestChangesUserLastUpdatedNullHandling(t *testing.T) {
	// Verify that NULL lastupdated in database scans to empty string, not nil.
	prefix := uniquePrefix("changes_null_lu")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Create user with explicit NULL lastupdated.
	userID := CreateTestUser(t, prefix, "User")
	defer db.Exec("DELETE FROM users WHERE id = ?", userID)

	// Set lastupdated to NOW to include in results.
	db.Exec("UPDATE users SET lastupdated = NOW() WHERE id = ?", userID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	changes := result["changes"].(map[string]interface{})
	users := changes["users"].([]interface{})

	found := false
	for _, u := range users {
		user := u.(map[string]interface{})
		if uint64(user["id"].(float64)) == userID {
			// lastupdated must be a string, never null in JSON.
			assert.IsType(t, "", user["lastupdated"], "lastupdated must be string type, not null")
			lu := user["lastupdated"].(string)
			assert.NotEmpty(t, lu, "lastupdated must not be empty string when set")
			assert.Contains(t, lu, "T", "lastupdated must be ISO8601 format")
			found = true
			break
		}
	}
	assert.True(t, found, "Expected user with lastupdated in changes")
}

func TestChangesResponseStructure(t *testing.T) {
	// Verify the complete response structure matches specification.
	prefix := uniquePrefix("changes_struct")
	db := database.DBConn

	partnerKey := prefix + "_key"
	db.Exec("INSERT INTO partners_keys (partner, `key`) VALUES (?, ?)", prefix+"_partner", partnerKey)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/changes?partner=%s", partnerKey), nil)
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)

	// Top-level fields.
	assert.Equal(t, float64(0), result["ret"], "ret must be 0 for success")
	assert.Equal(t, "Success", result["status"], "status must be Success")

	// Changes object structure.
	changes, ok := result["changes"].(map[string]interface{})
	require.True(t, ok, "changes must be an object")

	// Must have all three arrays.
	assert.NotNil(t, changes["messages"], "changes must have messages array")
	assert.NotNil(t, changes["users"], "changes must have users array")
	assert.NotNil(t, changes["ratings"], "changes must have ratings array")

	// Arrays must be present even if empty (not null).
	messages, ok := changes["messages"].([]interface{})
	assert.True(t, ok, "messages must be an array")
	assert.NotNil(t, messages, "messages must not be null")

	users, ok := changes["users"].([]interface{})
	assert.True(t, ok, "users must be an array")
	assert.NotNil(t, users, "users must not be null")

	ratings, ok := changes["ratings"].([]interface{})
	assert.True(t, ok, "ratings must be an array")
	assert.NotNil(t, ratings, "ratings must not be null")
}
