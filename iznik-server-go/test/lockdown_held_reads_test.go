package test

// Read-side tests for the "lockdownheld" field (sections 10.6/10.12 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md): a per-item label, computed by
// lockdown.ItemHeld, that is true while a lockdown_holds row exists for that item with no
// outcome recorded yet. Unlike the section 11.3 gate tests (lockdown_gates_*_test.go), this
// is a read label, not a write refusal, so it needs no lockdown.SetTestState - only a real
// lockdown_holds row, inserted directly since:
//   - posts never get one written by this API at all: the Laravel batch triage service
//     (LockdownTriageService, KIND_POST) inserts kind='post' rows, this API only reads them.
//   - chitchat rows ARE written by this API (newsfeed.createPost, via chitchatHeld() in
//     lockdown_gates_newsfeed_test.go), but inserting one directly here keeps this file's
//     tests independent of that write path and focused purely on the read side.

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	newsfeedpkg "github.com/freegle/iznik-server-go/newsfeed"
	"github.com/stretchr/testify/assert"
)

// insertPostHold inserts an unresolved lockdown_holds row for a post, exactly as the Laravel
// triage service would. lockdownid is a fake nonzero id, as in chitchatHeld() above -
// lockdown_holds.lockdownid has no foreign key, and ItemHeld only reads kind/refid/outcome.
func insertPostHold(t *testing.T, msgID uint64) {
	t.Helper()
	db := database.DBConn
	db.Exec("INSERT INTO lockdown_holds (lockdownid, kind, refid, userid, risk) VALUES (999999, 'post', ?, NULL, 'low')", msgID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM lockdown_holds WHERE kind = 'post' AND refid = ?", msgID)
	})
}

func insertChitchatHold(t *testing.T, nfID uint64) {
	t.Helper()
	db := database.DBConn
	db.Exec("INSERT INTO lockdown_holds (lockdownid, kind, refid, userid, risk) VALUES (999999, 'chitchat', ?, NULL, 'low')", nfID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM lockdown_holds WHERE kind = 'chitchat' AND refid = ?", nfID)
	})
}

// --- message.go: GetMessagesWithHistory (GET /api/message/:id) ---

func TestMessageLockdownheldTrueWithUnresolvedHold(t *testing.T) {
	prefix := uniquePrefix("ld_held_msg")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	_, token := CreateTestSession(t, posterID)
	msgID := createPendingMessage(t, posterID, groupID, prefix)

	insertPostHold(t, msgID)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// GetMessagesWithHistory returns a single object, not an array, when only one id is asked for.
	var msg message.Message
	json.NewDecoder(resp.Body).Decode(&msg)
	assert.True(t, msg.Lockdownheld, "an unresolved lockdown_holds row must show as lockdownheld")
}

func TestMessageLockdownheldFalseWhenNoHold(t *testing.T) {
	prefix := uniquePrefix("ld_held_msg_none")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	_, token := CreateTestSession(t, posterID)
	msgID := createPendingMessage(t, posterID, groupID, prefix)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var msg message.Message
	json.NewDecoder(resp.Body).Decode(&msg)
	assert.False(t, msg.Lockdownheld, "no lockdown_holds row must show as not held")
}

func TestMessageLockdownheldFalseWhenOutcomeSet(t *testing.T) {
	prefix := uniquePrefix("ld_held_msg_resolved")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	_, token := CreateTestSession(t, posterID)
	msgID := createPendingMessage(t, posterID, groupID, prefix)

	insertPostHold(t, msgID)
	db := database.DBConn
	db.Exec("UPDATE lockdown_holds SET outcome = 'released' WHERE kind = 'post' AND refid = ?", msgID)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var msg message.Message
	json.NewDecoder(resp.Body).Decode(&msg)
	assert.False(t, msg.Lockdownheld, "a hold with an outcome recorded is resolved, not held")
}

// --- message_list.go: ListMessages (GET /api/messages) ---

func TestListMessagesLockdownheldTrueWithUnresolvedHold(t *testing.T) {
	prefix := uniquePrefix("ld_held_list")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	msgID := createPendingMessage(t, posterID, groupID, prefix)

	insertPostHold(t, msgID)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/messages?groupid=%d&collection=Pending&jwt=%s", groupID, modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var result message.ListMessagesResponse
	json.NewDecoder(resp.Body).Decode(&result)

	found := false
	for _, m := range result.Messages {
		if m.ID == msgID {
			found = true
			assert.True(t, m.Lockdownheld, "the pending list must carry the same lockdownheld label as the detail fetch")
		}
	}
	assert.True(t, found, "mod must see the pending message")
}

// --- newsfeed.go: Single (GET /api/newsfeed/:id) ---

func TestNewsfeedSingleLockdownheldTrueWithUnresolvedHold(t *testing.T) {
	prefix := uniquePrefix("ld_held_nf_single")
	userID, token := CreateFullTestUser(t, prefix)
	nfID := CreateTestNewsfeed(t, userID, 52.2, -0.1, "Test held single "+prefix)

	insertChitchatHold(t, nfID)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/newsfeed/%d?jwt=%s", nfID, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var nf newsfeedpkg.Newsfeed
	json.NewDecoder(resp.Body).Decode(&nf)
	assert.True(t, nf.Lockdownheld, "the single-thread fetch must show an unresolved hold")
}

func TestNewsfeedSingleLockdownheldFalseWhenNoHold(t *testing.T) {
	prefix := uniquePrefix("ld_held_nf_single_none")
	userID, token := CreateFullTestUser(t, prefix)
	nfID := CreateTestNewsfeed(t, userID, 52.2, -0.1, "Test unheld single "+prefix)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/newsfeed/%d?jwt=%s", nfID, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var nf newsfeedpkg.Newsfeed
	json.NewDecoder(resp.Body).Decode(&nf)
	assert.False(t, nf.Lockdownheld, "with no lockdown_holds row this must not show as held")
}

// --- newsfeed.go: Feed (GET /api/newsfeed) summary, as seen by a mod ---

func TestNewsfeedFeedSummaryLockdownheldTrueForModWhenHeldAndHidden(t *testing.T) {
	prefix := uniquePrefix("ld_held_nf_feed")
	db := database.DBConn

	userID, _ := CreateFullTestUser(t, prefix)
	nfID := CreateTestNewsfeed(t, userID, 52.2, -0.1, "Test held feed "+prefix)
	// The feed only carries the lockdownheld label for entries it also shows as hidden
	// (see getFeed's hidden-item branch) - mirroring how a real chitchat hold always hides
	// the post at creation time (newsfeed.createPost).
	db.Exec("UPDATE newsfeed SET hidden = NOW() WHERE id = ?", nfID)
	insertChitchatHold(t, nfID)

	modID := CreateTestUser(t, prefix+"_mod", "User")
	db.Exec("UPDATE users SET systemrole = 'Moderator' WHERE id = ?", modID)
	_, modToken := CreateTestSession(t, modID)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/newsfeed?distance=0&jwt=%s", modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var feed []newsfeedpkg.NewsfeedSummary
	json.NewDecoder(resp.Body).Decode(&feed)

	found := false
	for _, item := range feed {
		if item.ID == nfID {
			found = true
			assert.True(t, item.Lockdownheld, "a mod browsing the ordinary feed must see the held-by-lockdown label inline")
		}
	}
	assert.True(t, found, "a site-wide moderator must see the hidden item in their feed")
}
