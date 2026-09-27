package test

// Gate tests for newsfeed/newsfeed.go (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md), driven by the ChitChat table there:
//   - "mods": Hide, ConvertedToPost, ReferToWanted/Offer/Taken/Received, AttachToThread
//     and ConvertToStory are all refused outright - they are moderator-only actions.
//   - "chitchat": a new post or reply is created hidden instead of refused, with a
//     lockdown_holds row recorded so it surfaces in the moderators' triage queue;
//     PATCH /newsfeed (an edit, whether by the poster or a moderator) is refused
//     outright - there is no pending-edits state for a ChitChat post to fall into.
//
// Uses lockdown.SetTestState (see modsHeld() in lockdown_gates_moderation_test.go) so
// these tests never write a real "lockdowns" row and cannot race the other packages'
// test binaries that `go test ./...` runs concurrently against the same test database.
// makeChitChatMod is defined in newsfeed_convert_test.go, same package.

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/lockdown"
	"github.com/stretchr/testify/assert"
)

// chitchatHeld sets a nonzero IncidentID as well as Active/Surfaces: unlike modsHeld,
// this gate's "held" treatment writes a real lockdown_holds row via InsertHold, which
// no-ops when Current().IncidentID == 0 (see the guard at the top of InsertHold in
// lockdown/lockdown.go) - so a test that checks the row was actually written needs a
// fake nonzero incident id, safe here because lockdown_holds.lockdownid has no foreign
// key. ID is set to the same value: in real use they coincide for a freshly-pressed
// incident, and nothing here depends on ID itself.
func chitchatHeld() func() {
	return lockdown.SetTestState(lockdown.State{
		ID:         999999,
		IncidentID: 999999,
		Active:     true,
		Surfaces:   map[string]bool{"chitchat": true},
	})
}

func newsfeedAction(t *testing.T, token string, body map[string]interface{}) *http.Response {
	t.Helper()
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", "/api/newsfeed?jwt="+token, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	return resp
}

// --- "mods": Hide and ConvertedToPost are gated on ChitChat Moderation team
// membership (canHidePost), not community-moderator status, and a plain ChitChat mod
// (unlike Admin/Support) is not exempt from the hold. ---

func TestLockdownRefusesNewsfeedHide(t *testing.T) {
	prefix := uniquePrefix("ld_nf_hide")
	userID := CreateTestUser(t, prefix+"_poster", "User")
	nfID := CreateTestNewsfeed(t, userID, 52.2, -0.1, "Test hide "+prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	makeChitChatMod(t, modID)
	_, modToken := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	resp := newsfeedAction(t, modToken, map[string]interface{}{"id": nfID, "action": "Hide"})
	assertLockdownRefused(t, resp)

	db := database.DBConn
	var hiddenby *uint64
	db.Raw("SELECT hiddenby FROM newsfeed WHERE id = ?", nfID).Scan(&hiddenby)
	assert.Nil(t, hiddenby, "Hide must not go through while mods is held")
}

func TestLockdownRefusesNewsfeedConvertedToPost(t *testing.T) {
	prefix := uniquePrefix("ld_nf_convpost")
	userID := CreateTestUser(t, prefix+"_poster", "User")
	nfID := CreateTestNewsfeed(t, userID, 52.2, -0.1, "Test convert "+prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	makeChitChatMod(t, modID)
	_, modToken := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	resp := newsfeedAction(t, modToken, map[string]interface{}{"id": nfID, "action": "ConvertedToPost", "msgid": 1})
	assertLockdownRefused(t, resp)
}

// --- "mods": ReferToWanted/Offer/Taken/Received carry no permission check of their
// own (any logged in member can call them), so the gate is the only thing stopping
// them while mods is held. ---

func TestLockdownRefusesNewsfeedReferToActions(t *testing.T) {
	for _, action := range []string{"ReferToWanted", "ReferToOffer", "ReferToTaken", "ReferToReceived"} {
		t.Run(action, func(t *testing.T) {
			prefix := uniquePrefix("ld_nf_" + action)
			userID := CreateTestUser(t, prefix+"_poster", "User")
			nfID := CreateTestNewsfeed(t, userID, 52.2, -0.1, "Test refer "+prefix)
			_, token := CreateTestSession(t, userID)

			restore := modsHeld()
			defer restore()

			resp := newsfeedAction(t, token, map[string]interface{}{"id": nfID, "action": action})
			assertLockdownRefused(t, resp)
		})
	}
}

// --- "mods": AttachToThread and ConvertToStory are gated on a community moderator's
// memberships row, not ChitChat Moderation team membership. ---

func TestLockdownRefusesNewsfeedAttachToThread(t *testing.T) {
	prefix := uniquePrefix("ld_nf_attach")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	nfID := CreateTestNewsfeed(t, modID, 52.2, -0.1, "Test attach "+prefix)
	otherID := CreateTestNewsfeed(t, modID, 52.2, -0.1, "Test attach target "+prefix)

	restore := modsHeld()
	defer restore()

	resp := newsfeedAction(t, modToken, map[string]interface{}{"id": nfID, "action": "AttachToThread", "replyto": otherID})
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesNewsfeedConvertToStory(t *testing.T) {
	prefix := uniquePrefix("ld_nf_story")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	nfID := CreateTestNewsfeed(t, posterID, 52.2, -0.1, "Test story "+prefix)

	restore := modsHeld()
	defer restore()

	resp := newsfeedAction(t, modToken, map[string]interface{}{"id": nfID, "action": "ConvertToStory"})
	assertLockdownRefused(t, resp)
}

// --- "chitchat": a new post or reply is created hidden, not refused, with a
// lockdown_holds row so it surfaces in the moderators' triage queue. ---

func TestLockdownHoldsNewNewsfeedPostWhenChitchatHeld(t *testing.T) {
	prefix := uniquePrefix("ld_nf_create")
	_, token := CreateFullTestUser(t, prefix)

	restore := chitchatHeld()
	defer restore()

	resp := newsfeedAction(t, token, map[string]interface{}{"message": "Test chitchat hold " + prefix})
	assert.Equal(t, 200, resp.StatusCode, "the write itself must still succeed")

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	id := uint64(result["id"].(float64))
	assert.Greater(t, id, uint64(0))

	db := database.DBConn
	var hidden *string
	db.Raw("SELECT hidden FROM newsfeed WHERE id = ?", id).Scan(&hidden)
	assert.NotNil(t, hidden, "a post made while chitchat is held must be created hidden")

	var holdCount int64
	db.Raw("SELECT COUNT(*) FROM lockdown_holds WHERE kind = 'chitchat' AND refid = ?", id).Scan(&holdCount)
	assert.Equal(t, int64(1), holdCount, "must record a lockdown_holds row so the post shows up in triage")
}

// --- "chitchat": PATCH /newsfeed is refused outright, for the poster's own edit. ---

func TestLockdownRefusesNewsfeedEditWhenChitchatHeld(t *testing.T) {
	prefix := uniquePrefix("ld_nf_edit")
	userID, token := CreateFullTestUser(t, prefix)
	nfID := CreateTestNewsfeed(t, userID, 52.2, -0.1, "Test edit "+prefix)

	restore := chitchatHeld()
	defer restore()

	bb, _ := json.Marshal(map[string]interface{}{"id": nfID, "message": "edited " + prefix})
	req := httptest.NewRequest("PATCH", "/api/newsfeed?jwt="+token, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)
}
