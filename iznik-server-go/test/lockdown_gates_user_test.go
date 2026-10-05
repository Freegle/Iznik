package test

// Gate tests for user.go (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md):
//   - PATCH /user chatmodstatus, newsfeedmodstatus, and a moderator setting
//     someone else's trustlevel are moderation status changes, refused outright
//     while "mods" is held. The self-service Trustlevel path (a member setting
//     their own Basic/Declined) is a personal setting, not moderation, and stays
//     ungated - proved here by a positive test.
//   - POST /user RatingReviewed is a moderator action, refused while "mods" is held.
//   - PATCH /user displayname and aboutme are personal profile edits, refused
//     while "posts" is held (the Profile row of the plan's endpoint table).
//
// Uses lockdown.SetTestState (see modsHeld()/postsHeld() in the other
// lockdown_gates_*_test.go files) so these tests never write a real "lockdowns"
// row and cannot race the other packages' test binaries that `go test ./...`
// runs concurrently against the same test database.

import (
	"bytes"
	"encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestLockdownRefusesChatmodstatus(t *testing.T) {
	prefix := uniquePrefix("ld_chatmod")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "User")
	targetID := CreateTestUser(t, prefix+"_target", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, targetID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	// users.chatmodstatus defaults to 'Moderated' (create_users_table migration), not "" -
	// capture the real baseline rather than assuming empty, so "unchanged" is what it says.
	var before string
	db.Raw("SELECT COALESCE(chatmodstatus, '') FROM users WHERE id = ?", targetID).Scan(&before)

	restore := modsHeld()
	defer restore()

	payload := map[string]interface{}{
		"id":            targetID,
		"chatmodstatus": "Fully",
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("PATCH", "/api/user?jwt="+modToken, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var chatmodstatus string
	db.Raw("SELECT COALESCE(chatmodstatus, '') FROM users WHERE id = ?", targetID).Scan(&chatmodstatus)
	assert.Equal(t, before, chatmodstatus, "chatmodstatus must not change while mods is held")
}

func TestLockdownRefusesNewsfeedmodstatus(t *testing.T) {
	prefix := uniquePrefix("ld_newsfeedmod")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "User")
	targetID := CreateTestUser(t, prefix+"_target", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, targetID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	// users.newsfeedmodstatus defaults to 'Unmoderated' (create_users_table migration), not
	// "" - capture the real baseline rather than assuming empty, so "unchanged" is what it says.
	var before string
	db.Raw("SELECT COALESCE(newsfeedmodstatus, '') FROM users WHERE id = ?", targetID).Scan(&before)

	restore := modsHeld()
	defer restore()

	payload := map[string]interface{}{
		"id":                targetID,
		"newsfeedmodstatus": "Fully",
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("PATCH", "/api/user?jwt="+modToken, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var newsfeedmodstatus string
	db.Raw("SELECT COALESCE(newsfeedmodstatus, '') FROM users WHERE id = ?", targetID).Scan(&newsfeedmodstatus)
	assert.Equal(t, before, newsfeedmodstatus, "newsfeedmodstatus must not change while mods is held")
}

func TestLockdownRefusesTrustlevelModOnOther(t *testing.T) {
	prefix := uniquePrefix("ld_trustmod")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	targetID := CreateTestUser(t, prefix+"_target", "User")
	_, modToken := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	payload := map[string]interface{}{
		"id":         targetID,
		"trustlevel": "Advanced",
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("PATCH", "/api/user?jwt="+modToken, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var after *string
	db.Raw("SELECT trustlevel FROM users WHERE id = ?", targetID).Scan(&after)
	assert.Nil(t, after, "trustlevel must not change while mods is held")
}

// A member's own Basic/Declined trustlevel is a personal setting, not a
// moderation action, so it must stay allowed even while mods is held.
func TestLockdownAllowsSelfTrustlevelWhenModsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_trustself")
	db := database.DBConn

	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	restore := modsHeld()
	defer restore()

	payload := map[string]interface{}{
		"trustlevel": "Declined",
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("PATCH", "/api/user?jwt="+token, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "a member declining their own trustlevel must not be refused by the mods hold")

	var after *string
	db.Raw("SELECT trustlevel FROM users WHERE id = ?", userID).Scan(&after)
	if assert.NotNil(t, after) {
		assert.Equal(t, "Declined", *after)
	}
}

func TestLockdownRefusesRatingReviewed(t *testing.T) {
	prefix := uniquePrefix("ld_ratingrev")
	db := database.DBConn

	// A plain community moderator, not Support/Admin: GateMod exempts Support/Admin from
	// every refusal (plan section 10.5, "Support/Admin callers are exempt from every
	// refusal"), so a Support rater here would see 200, not 409, regardless of the gate -
	// that would be proving the exemption, not the refusal this test is named for.
	raterID := CreateTestUser(t, prefix+"_rater", "User")
	rateeID := CreateTestUser(t, prefix+"_ratee", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, raterID, groupID, "Moderator")
	CreateTestMembership(t, rateeID, groupID, "Member")
	_, token := CreateTestSession(t, raterID)

	db.Exec("INSERT INTO ratings (rater, ratee, rating, reason, text, timestamp, reviewrequired) VALUES (?, ?, 'Down', 'Other', 'Test', NOW(), 1)",
		raterID, rateeID)
	var ratingID uint64
	db.Raw("SELECT id FROM ratings WHERE rater = ? AND ratee = ? ORDER BY id DESC LIMIT 1", raterID, rateeID).Scan(&ratingID)
	assert.Greater(t, ratingID, uint64(0))

	restore := modsHeld()
	defer restore()

	payload := map[string]interface{}{
		"action":   "RatingReviewed",
		"ratingid": ratingID,
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", "/api/user?jwt="+token, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var reviewRequired bool
	db.Raw("SELECT reviewrequired FROM ratings WHERE id = ?", ratingID).Scan(&reviewRequired)
	assert.True(t, reviewRequired, "reviewrequired must stay set while mods is held")
}

func TestLockdownRefusesDisplaynameWhenPostsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_dispname")
	db := database.DBConn

	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	restore := postsHeld()
	defer restore()

	payload := map[string]interface{}{
		"displayname": "Should Not Apply " + prefix,
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("PATCH", "/api/user?jwt="+token, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var fullname string
	db.Raw("SELECT fullname FROM users WHERE id = ?", userID).Scan(&fullname)
	assert.NotEqual(t, "Should Not Apply "+prefix, fullname)
}

func TestLockdownRefusesAboutmeWhenPostsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_aboutme")

	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	restore := postsHeld()
	defer restore()

	payload := map[string]interface{}{
		"aboutme": "Should not be stored " + prefix,
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("PATCH", "/api/user?jwt="+token, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM users_aboutme WHERE userid = ? AND text = ?", userID, "Should not be stored "+prefix).Scan(&count)
	assert.Equal(t, int64(0), count, "no aboutme entry must be stored while posts is held")
}
