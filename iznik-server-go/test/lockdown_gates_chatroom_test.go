package test

// Gate test for chat/chatroom.go's handleNudge (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md): a Nudge is created via a raw insert map
// that never sets processingrequired, so it defaults to 0 and is delivered/notified
// instantly - bypassing chats:process-incoming (ChatProcessService, iznik-batch), which is
// where the "chat" surface's actual hold-and-triage logic lives, and which only ever sees
// rows with processingrequired = 1.
//
// While "chat" is held, handleNudge must set processingrequired = 1 so the row is picked
// up by that pipeline like any other chat message, instead of slipping straight past it.
// Not conditioned on chat_rooms.chattype here - ChatProcessService already keeps
// User2Mod/Mod2Mod flowing and holds only User2User (see its own chattype filter) - so this
// is the same unconditional-on-Held, always-safe shape ordinary CreateChatMessage already
// uses for that flag. When "chat" is not held, the field must stay untouched (0), or every
// ordinary day's nudges would pick up a minute's delay for nothing.
//
// Uses lockdown.SetTestState (see modsHeld() in lockdown_gates_moderation_test.go) so these
// tests never write a real "lockdowns" row and cannot race the other packages' test
// binaries that `go test ./...` runs concurrently against the same test database.

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/lockdown"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func chatHeld() func() {
	return lockdown.SetTestState(lockdown.State{
		Active:   true,
		Surfaces: map[string]bool{"chat": true},
	})
}

func postChatroomAction(t *testing.T, body map[string]interface{}, token string) (int, map[string]interface{}) {
	t.Helper()
	s, _ := json.Marshal(body)
	request := httptest.NewRequest("POST", "/api/chatrooms?jwt="+token, bytes.NewBuffer(s))
	request.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(request)
	if err != nil {
		t.Fatalf("Failed to execute request: %v", err)
	}

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	return resp.StatusCode, result
}

func TestLockdownNudgeSetsProcessingRequiredWhenChatHeld(t *testing.T) {
	prefix := uniquePrefix("ld_nudge_held")
	db := database.DBConn

	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	chatid := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	CreateTestChatMessage(t, chatid, user1ID, "Hello")
	_, token := CreateTestSession(t, user1ID)

	restore := chatHeld()
	defer restore()

	status, result := postChatroomAction(t, map[string]interface{}{"id": chatid, "action": "Nudge"}, token)
	assert.Equal(t, 200, status, "a nudge while chat is held must still look sent to the sender")
	assert.Equal(t, float64(0), result["ret"])

	nudgeID, _ := result["id"].(float64)
	assert.Greater(t, nudgeID, float64(0))

	var processingRequired int
	db.Raw("SELECT processingrequired FROM chat_messages WHERE id = ?", uint64(nudgeID)).Scan(&processingRequired)
	assert.Equal(t, 1, processingRequired, "a held nudge must be routed through chats:process-incoming, or the hold never sees it")
}

func TestLockdownNudgeLeavesProcessingRequiredZeroWhenNotHeld(t *testing.T) {
	prefix := uniquePrefix("ld_nudge_unheld")
	db := database.DBConn

	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	chatid := CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")
	CreateTestChatMessage(t, chatid, user1ID, "Hello")
	_, token := CreateTestSession(t, user1ID)

	status, result := postChatroomAction(t, map[string]interface{}{"id": chatid, "action": "Nudge"}, token)
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(0), result["ret"])

	nudgeID, _ := result["id"].(float64)
	assert.Greater(t, nudgeID, float64(0))

	var processingRequired int
	db.Raw("SELECT processingrequired FROM chat_messages WHERE id = ?", uint64(nudgeID)).Scan(&processingRequired)
	assert.Equal(t, 0, processingRequired, "an ordinary day's nudge must stay instant, not pick up chats:process-incoming's delay")
}

// Gate tests for chat/chatroom.go's PutChatRoom (section 11.9 of the lockdown plan, added
// after review): opening a User2Mod chat is not the member's own action when a moderator
// supplies a userid other than their own - that is the moderator starting a conversation
// with a member, exactly like the "Leave Member" mod-mail gated in
// lockdown_gates_membership_test.go. While "mods" is held, a non-Support/Admin moderator
// may not open a brand new such room. PutChatRoom already looks up an existing room for
// the target user before it creates one (chat/chatroom.go, "Find or create"); the gate
// sits after that lookup, so a room the member already started or wrote in is returned
// by the early "existingID > 0" path and never reaches it - replying stays allowed.

// putChatRoom issues PUT /chat/rooms and returns the raw response.
func putChatRoom(t *testing.T, token string, body map[string]interface{}) *http.Response {
	t.Helper()
	s, _ := json.Marshal(body)
	req := httptest.NewRequest("PUT", "/api/chat/rooms?jwt="+token, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	return resp
}

func TestLockdownRefusesModOpeningNewUser2ModChatToMember(t *testing.T) {
	prefix := uniquePrefix("ld_chat_open")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, memberID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	resp := putChatRoom(t, modToken, map[string]interface{}{
		"chattype": "User2Mod", "groupid": groupID, "userid": memberID,
	})
	assertLockdownRefused(t, resp)

	var chats int64
	db.Table("chat_rooms").Where("user1 = ? AND groupid = ? AND chattype = ?",
		memberID, groupID, utils.CHAT_TYPE_USER2MOD).Count(&chats)
	assert.Equal(t, int64(0), chats, "no room is created while mods is held")
}

func TestLockdownAllowsModReplyingInUser2ModChatMemberStarted(t *testing.T) {
	prefix := uniquePrefix("ld_chat_reply")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, memberID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	existing := CreateTestChatRoom(t, memberID, nil, &groupID, "User2Mod")
	CreateTestChatMessage(t, existing, memberID, "Can you help with this?")

	restore := modsHeld()
	defer restore()

	resp := putChatRoom(t, modToken, map[string]interface{}{
		"chattype": "User2Mod", "groupid": groupID, "userid": memberID,
	})
	assert.Equal(t, fiber.StatusOK, resp.StatusCode,
		"opening a room the member already started must stay allowed while mods is held")

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(existing), result["id"], "the existing room is returned, not refused")
}

func TestLockdownDoesNotRefuseModOpeningUser2ModChatWhenNotHeld(t *testing.T) {
	prefix := uniquePrefix("ld_chat_open_notheld_ok")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, memberID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	resp := putChatRoom(t, modToken, map[string]interface{}{
		"chattype": "User2Mod", "groupid": groupID, "userid": memberID,
	})
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var chats int64
	db.Table("chat_rooms").Where("user1 = ? AND groupid = ? AND chattype = ?",
		memberID, groupID, utils.CHAT_TYPE_USER2MOD).Count(&chats)
	assert.Equal(t, int64(1), chats, "an ordinary day's mod-initiated contact is unaffected")
}

// A member opening their own User2Mod chat is unaffected either way - it is their own
// action, not a moderator writing to them, so GateMod's "mod not Support/Admin" check
// never applies to it (modOpeningMembersChat is false whenever userid is empty or is the
// caller's own id).
func TestLockdownAllowsMemberOpeningOwnUser2ModChatWhileModsHeld(t *testing.T) {
	prefix := uniquePrefix("ld_chat_own")

	groupID := CreateTestGroup(t, prefix)
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")
	_, memberToken := CreateTestSession(t, memberID)

	restore := modsHeld()
	defer restore()

	resp := putChatRoom(t, memberToken, map[string]interface{}{
		"chattype": "User2Mod", "groupid": groupID,
	})
	assert.Equal(t, fiber.StatusOK, resp.StatusCode,
		"a member may always write to their group's volunteers, mods held or not")
}
