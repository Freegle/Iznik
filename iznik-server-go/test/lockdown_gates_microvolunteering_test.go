package test

// Gate tests for microvolunteering.go (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md):
//   - GET /microvolunteering offers no new challenge while "mods" or "posts" is
//     held - not a refusal (nothing was attempted that needs blocking), the same
//     silent "nothing to offer" empty object already returned for declined/excluded
//     trust levels, so members aren't asked to help moderate or check posts while
//     incident response is under way.
//   - PATCH /microvolunteering (moderator feedback on a microaction) is a
//     moderator action, refused outright while "mods" is held.
//
// Uses lockdown.SetTestState (see modsHeld()/postsHeld() in the other
// lockdown_gates_*_test.go files) so these tests never write a real "lockdowns"
// row and cannot race the other packages' test binaries that `go test ./...`
// runs concurrently against the same test database.

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

func TestLockdownNoChallengeWhenModsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_mv_mods")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	// Block the invite challenge, same as TestGetMicrovolunteering_NoChallenge, so
	// an unrelated challenge type doesn't mask the lockdown gate being tested here.
	db.Exec("INSERT INTO microactions (actiontype, userid, version, comments, timestamp, score_negative) VALUES (?, ?, 4, 'Test block', NOW(), 0)", microvolunteering.ChallengeInvite, userID)

	restore := modsHeld()
	defer restore()

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=PhotoRotate", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.NotContains(t, result, "type", "no challenge must be offered while mods is held")
}

func TestLockdownNoChallengeWhenPostsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_mv_posts")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)

	db.Exec("INSERT INTO microactions (actiontype, userid, version, comments, timestamp, score_negative) VALUES (?, ?, 4, 'Test block', NOW(), 0)", microvolunteering.ChallengeInvite, userID)

	restore := postsHeld()
	defer restore()

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/microvolunteering?jwt="+token+"&types=PhotoRotate", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.NotContains(t, result, "type", "no challenge must be offered while posts is held")
}

func TestLockdownRefusesModFeedback(t *testing.T) {
	prefix := uniquePrefix("ld_mv_modfb")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	regularID := CreateTestUser(t, prefix+"_user", "User")
	db.Exec("INSERT INTO microactions (userid, actiontype, result, timestamp, score_negative) VALUES (?, 'CheckMessage', 'Approve', NOW(), 0)", regularID)
	var actionID uint64
	db.Raw("SELECT id FROM microactions WHERE userid = ? ORDER BY id DESC LIMIT 1", regularID).Scan(&actionID)
	assert.NotZero(t, actionID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"id":%d,"feedback":"Should not apply","score_positive":0.8,"score_negative":0.1}`, actionID)
	req := httptest.NewRequest("PATCH", "/api/microvolunteering?jwt="+modToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)

	var modfeedback string
	db.Raw("SELECT COALESCE(modfeedback, '') FROM microactions WHERE id = ?", actionID).Scan(&modfeedback)
	assert.Equal(t, "", modfeedback, "modfeedback must not be set while mods is held")
}
