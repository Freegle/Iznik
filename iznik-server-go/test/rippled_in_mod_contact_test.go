package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A moderator of a group a post merely RIPPLED INTO administers their own copy of it:
// they may approve it or take it off their community. What they may not do is write to
// the freegler, who posted somewhere else entirely and has never heard of their group
// (Discourse 10102: a Walsall mod told a Potteries poster that "Walsall Freegle does not
// accept posts for living creatures"). Correspondence about a post belongs to its home
// community. These tests pin every route by which a mod-authored message can reach the
// poster.

// notifyPosterFlag reads the notifyposter flag from the most recent poster-email task of
// the given type for a message and group. -1 means no such task was queued at all.
func notifyPosterFlag(t *testing.T, taskType string, msgid, groupid uint64) int {
	t.Helper()
	db := database.DBConn

	var flags []int
	db.Raw("SELECT COALESCE(JSON_EXTRACT(data, '$.notifyposter'), -1) FROM background_tasks "+
		"WHERE task_type = ? AND JSON_EXTRACT(data, '$.msgid') = ? AND JSON_EXTRACT(data, '$.groupid') = ? "+
		"ORDER BY id DESC LIMIT 1", taskType, msgid, groupid).Scan(&flags)

	if len(flags) == 0 {
		return -1
	}

	return flags[0]
}

// rippledInPost creates a post that originated on one group and rippled into another,
// returning (origin, rippled, msgid). The rippled-in copy carries rippled_in = 1, as
// ExpandService writes it in production.
func rippledInPost(t *testing.T, prefix string, posterID uint64, collection string) (uint64, uint64, uint64) {
	t.Helper()
	db := database.DBConn

	origin := CreateTestGroup(t, prefix+"_origin")
	rippled := CreateTestGroup(t, prefix+"_rippled")
	msgID := CreateTestMessage(t, posterID, origin, "OFFER: "+prefix+" item", 51.5, -0.1)

	res := db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, NOW(), ?, 0, 1)", msgID, rippled, collection)
	require.NoError(t, res.Error, "seed the rippled-in copy")

	return origin, rippled, msgID
}

// The home groups are the ones the post was not rippled into. Deriving them from an
// arrival window instead loses them for any post moderated more than a few minutes after
// it arrived, because approving re-stamps messages_groups.arrival to the approval time
// while messages.arrival keeps the time the post was received. The rabbits post in
// Discourse 10102 arrived on 30 Aug and was approved on 2 Sep, so every group it had
// rippled into counted as its origin.
func TestHomeGroupsSurviveLateApproval(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("originlate")
	userID := CreateTestUser(t, prefix, "User")
	origin, rippled, msgID := rippledInPost(t, prefix, userID, "Approved")

	// Received three days ago, held, then approved just now.
	db.Exec("UPDATE messages SET arrival = NOW() - INTERVAL 3 DAY WHERE id = ?", msgID)
	db.Exec("UPDATE messages_groups SET arrival = NOW() WHERE msgid = ? AND groupid = ?", msgID, origin)
	db.Exec("UPDATE messages_groups SET arrival = NOW() + INTERVAL 1 MINUTE WHERE msgid = ? AND groupid = ?", msgID, rippled)

	assert.Equal(t, map[uint64]bool{origin: true}, message.HomeGroups(db, msgID),
		"home is the group the post was not rippled into, however late it was approved")
}

// With several rippled-in copies and no surviving home row the home is unknown, and
// the caller falls back to notifying rather than silently dropping a message the poster
// may need.
func TestHomeGroupsUnknownWhenEveryCopyIsRippledIn(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("originnone")
	userID := CreateTestUser(t, prefix, "User")
	origin, _, msgID := rippledInPost(t, prefix, userID, "Approved")

	db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?", msgID, origin)

	assert.Empty(t, message.HomeGroups(db, msgID),
		"no home row left means unknown, not the nearest rippled-in group")
}

// Rejecting a rippled-in copy takes the post off that community. The poster is not told,
// because the post is still live where they put it. The task is still queued so the
// group keeps its own moderation log entry and its mods still get their push.
func TestRejectOnRippledInGroupDoesNotNotifyPoster(t *testing.T) {
	prefix := uniquePrefix("ripreject")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	origin, rippled, msgID := rippledInPost(t, prefix, posterID, "Pending")
	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reject", "groupid": rippled,
		"subject": "Sorry", "body": "We don't take live animals.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, 0, notifyPosterFlag(t, "email_message_rejected", msgID, rippled),
		"a rippled-in group's rejection must not be sent to the poster")

	_ = origin
}

// Deleting an approved rippled-in copy is the route that actually went wrong: the shared
// "Animals (Delete)" standard message has action "Delete Approved Message", which never
// went near the rejection path that suppresses secondary-group mail.
func TestDeleteOnRippledInGroupDoesNotNotifyPoster(t *testing.T) {
	prefix := uniquePrefix("ripdelete")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	_, rippled, msgID := rippledInPost(t, prefix, posterID, "Approved")
	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Delete", "groupid": rippled,
		"subject": "Re: OFFER", "body": "We don't take live animals.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, 0, notifyPosterFlag(t, "email_message_rejected", msgID, rippled),
		"a rippled-in group's delete must not be sent to the poster")
}

// Approving with a standard message is the third way a mod-authored message reaches the
// poster.
func TestApproveOnRippledInGroupDoesNotNotifyPoster(t *testing.T) {
	prefix := uniquePrefix("ripapprove")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	_, rippled, msgID := rippledInPost(t, prefix, posterID, "Pending")
	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Approve", "groupid": rippled,
		"subject": "Welcome", "body": "Approved with a note.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, 0, notifyPosterFlag(t, "email_message_approved", msgID, rippled),
		"a rippled-in group's approval note must not be sent to the poster")
}

// The home community still corresponds with its own poster - the whole point of the
// suppression is that it is scoped to rippled-in copies.
func TestRejectOnHomeGroupStillNotifiesPoster(t *testing.T) {
	prefix := uniquePrefix("homereject")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	origin, _, msgID := rippledInPost(t, prefix, posterID, "Approved")
	database.DBConn.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ? AND groupid = ?", msgID, origin)
	CreateTestMembership(t, modID, origin, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reject", "groupid": origin,
		"subject": "Sorry", "body": "Not suitable.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_rejected", msgID, origin),
		"the home community's rejection still reaches the poster")
}

// Blank Reply, and every standard message whose action is Leave, do nothing except send
// the poster a message. On a rippled-in copy there is nothing left for the action to do,
// so it is refused outright rather than silently swallowed.
func TestReplyFromRippledInGroupIsRefused(t *testing.T) {
	prefix := uniquePrefix("ripreply")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	_, rippled, msgID := rippledInPost(t, prefix, posterID, "Approved")
	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reply", "groupid": rippled,
		"subject": "Re: OFFER", "body": "We don't take live animals.",
	})
	assert.Equal(t, fiber.StatusForbidden, status,
		"a rippled-in group's mods cannot write to the poster")

	assert.Equal(t, -1, notifyPosterFlag(t, "email_message_reply", msgID, rippled),
		"and no mail is queued")
}

// The home community's Blank Reply still works.
func TestReplyFromHomeGroupIsAllowed(t *testing.T) {
	prefix := uniquePrefix("homereply")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	origin, _, msgID := rippledInPost(t, prefix, posterID, "Approved")
	CreateTestMembership(t, modID, origin, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reply", "groupid": origin,
		"subject": "Re: OFFER", "body": "Just checking this has gone.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_reply", msgID, origin),
		"the home community's reply reaches the poster")
}

// Reply took its group straight from the request without checking the caller moderates
// it, so a rippled-in group's mod could name the HOME group and write to the poster over
// that community's name.
func TestReplyGroupidMustBeOneTheModAdministers(t *testing.T) {
	prefix := uniquePrefix("replyspoof")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	origin, rippled, msgID := rippledInPost(t, prefix, posterID, "Approved")
	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reply", "groupid": origin,
		"subject": "Re: OFFER", "body": "Written as a group I don't moderate.",
	})
	assert.Equal(t, fiber.StatusForbidden, status,
		"a mod cannot send a reply as a group they do not moderate")

	assert.Equal(t, -1, notifyPosterFlag(t, "email_message_reply", msgID, origin),
		"and no mail is queued in the home group's name")
}

// A membership that exists only because rippling auto-joined the poster is not a
// relationship with that community, so its mods cannot open a chat off the back of it.
func TestModCannotStartChatWithRippleOnlyMember(t *testing.T) {
	prefix := uniquePrefix("ripchat")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, memberID, groupID, "Member")
	db.Exec("UPDATE memberships SET rippled = 1 WHERE userid = ? AND groupid = ?", memberID, groupID)
	_, modToken := CreateTestSession(t, modID)

	payload, _ := json.Marshal(map[string]interface{}{
		"chattype": "User2Mod", "groupid": groupID, "userid": memberID,
	})
	req := httptest.NewRequest("PUT", "/api/chat/rooms?jwt="+modToken, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)

	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode,
		"a ripple-created membership does not entitle the group's mods to start a chat")

	var chats int64
	db.Table("chat_rooms").Where("user1 = ? AND groupid = ? AND chattype = ?",
		memberID, groupID, utils.CHAT_TYPE_USER2MOD).Count(&chats)
	assert.Equal(t, int64(0), chats, "and no chat room is created")
}

// If the member wrote to the group first, the mods must be able to answer - the block is
// on starting the conversation, not on holding one the member began.
func TestModCanOpenChatTheRippleOnlyMemberStarted(t *testing.T) {
	prefix := uniquePrefix("ripchatex")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, memberID, groupID, "Member")
	db.Exec("UPDATE memberships SET rippled = 1 WHERE userid = ? AND groupid = ?", memberID, groupID)
	_, modToken := CreateTestSession(t, modID)

	existing := CreateTestChatRoom(t, memberID, nil, &groupID, "User2Mod")

	payload, _ := json.Marshal(map[string]interface{}{
		"chattype": "User2Mod", "groupid": groupID, "userid": memberID,
	})
	req := httptest.NewRequest("PUT", "/api/chat/rooms?jwt="+modToken, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var result map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&result))
	assert.Equal(t, float64(existing), result["id"], "the chat the member started is returned")
}

// The member's own route to the mods is untouched: someone whose only tie to a group is
// a ripple can still write to that group's volunteers.
func TestRippleOnlyMemberCanStillStartChatWithMods(t *testing.T) {
	prefix := uniquePrefix("ripchatown")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")
	db.Exec("UPDATE memberships SET rippled = 1 WHERE userid = ? AND groupid = ?", memberID, groupID)
	_, memberToken := CreateTestSession(t, memberID)

	payload, _ := json.Marshal(map[string]interface{}{
		"chattype": "User2Mod", "groupid": groupID,
	})
	req := httptest.NewRequest("PUT", "/api/chat/rooms?jwt="+memberToken, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)

	assert.Equal(t, fiber.StatusOK, resp.StatusCode,
		"a member may always write to the volunteers of a group their post reached")
}

// Joining a group for real ends the ripple-only state. The join used to return early
// because a membership row already existed, leaving the ripple flag set for good.
func TestJoiningClearsTheRippledFlag(t *testing.T) {
	prefix := uniquePrefix("ripjoin")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, memberID, groupID, "Member")
	db.Exec("UPDATE memberships SET rippled = 1 WHERE userid = ? AND groupid = ?", memberID, groupID)
	_, memberToken := CreateTestSession(t, memberID)

	payload, _ := json.Marshal(map[string]interface{}{"groupid": groupID})
	req := httptest.NewRequest("PUT", "/api/memberships?jwt="+memberToken, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var rippled int
	db.Raw("SELECT rippled FROM memberships WHERE userid = ? AND groupid = ?", memberID, groupID).Scan(&rippled)
	assert.Equal(t, 0, rippled, "joining for real makes it an ordinary membership")
}

// Moving house into a community's area does the same: the person now lives there, so the
// membership rippling created for them is an ordinary one and its mods may contact them.
func TestPostcodeMoveIntoGroupAreaClearsTheRippledFlag(t *testing.T) {
	prefix := uniquePrefix("ripmove")
	db := database.DBConn

	inside := CreateTestGroup(t, prefix+"_inside")
	elsewhere := CreateTestGroup(t, prefix+"_elsewhere")
	memberID := CreateTestUser(t, prefix+"_member", "User")

	// The member's post rippled into both groups, so both memberships are ripple-created.
	CreateTestMembership(t, memberID, inside, "Member")
	CreateTestMembership(t, memberID, elsewhere, "Member")
	db.Exec("UPDATE memberships SET rippled = 1 WHERE userid = ? AND groupid IN (?, ?)", memberID, inside, elsewhere)

	// One group covers where they have moved to; the other is far away.
	db.Exec(fmt.Sprintf("UPDATE `groups` SET polyindex = ST_GeomFromText("+
		"'POLYGON((-0.2 51.4,0.1 51.4,0.1 51.6,-0.2 51.6,-0.2 51.4))', %d) WHERE id = ?", utils.SRID), inside)
	db.Exec(fmt.Sprintf("UPDATE `groups` SET polyindex = ST_GeomFromText("+
		"'POLYGON((10.0 51.4,10.3 51.4,10.3 51.6,10.0 51.6,10.0 51.4))', %d) WHERE id = ?", utils.SRID), elsewhere)

	res := db.Exec(fmt.Sprintf("INSERT INTO locations (name, type, lat, lng, geometry) "+
		"VALUES (?, 'Postcode', 51.5, -0.1, ST_GeomFromText('POINT(-0.1 51.5)', %d))", utils.SRID),
		"TESTPC "+prefix)
	require.NoError(t, res.Error)

	var locationID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", "TESTPC "+prefix).Scan(&locationID)
	require.NotZero(t, locationID)

	_, token := CreateTestSession(t, memberID)

	settings := fmt.Sprintf(`{"mylocation":{"id":%d,"name":"TESTPC %s","type":"Postcode"}}`, locationID, prefix)
	payload, _ := json.Marshal(map[string]interface{}{"settings": json.RawMessage(settings)})
	req := httptest.NewRequest("PATCH", "/api/session?jwt="+token, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var here, away int
	db.Raw("SELECT rippled FROM memberships WHERE userid = ? AND groupid = ?", memberID, inside).Scan(&here)
	db.Raw("SELECT rippled FROM memberships WHERE userid = ? AND groupid = ?", memberID, elsewhere).Scan(&away)

	assert.Equal(t, 0, here, "the group they have moved into is now an ordinary membership")
	assert.Equal(t, 1, away, "a group they have not moved into is left alone")
}

// Taking a rippled-in copy off a community must stick. A delete HARD-deletes that group's
// messages_groups row, so the "already on this group" guard stops holding and the next
// expansion tick puts the post straight back. Recording the group as having turned it away
// - the same record a rejection leaves - is what stops that.
func TestDeleteOnRippledInGroupRecordsItSoItCannotRippleBack(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("ripdelclip")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	_, rippled, msgID := rippledInPost(t, prefix, posterID, "Approved")
	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857)), 'expanding')", msgID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Delete", "groupid": rippled,
		"subject": "Re: OFFER", "body": "We don't take live animals.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	var recorded int
	db.Raw("SELECT COALESCE(JSON_CONTAINS(rejected_groups, CAST(? AS JSON)), 0) FROM rippling_reach WHERE msgid = ?",
		rippled, msgID).Scan(&recorded)
	assert.Equal(t, 1, recorded,
		"the community that removed the post is recorded, so the expander does not send it back")
}

// The home community deleting its own copy is not a community turning a rippled post away,
// so it must not be recorded as one - that would clip the post's own origin out of its reach.
func TestDeleteOnHomeGroupDoesNotRecordARejection(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("homedelclip")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	origin, _, msgID := rippledInPost(t, prefix, posterID, "Approved")
	CreateTestMembership(t, modID, origin, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, outer_bound, status) VALUES (?, 51.5, -0.1, "+
		"ST_Envelope(ST_GeomFromText('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857)), 'expanding')", msgID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Delete", "groupid": origin,
		"subject": "Re: OFFER", "body": "Removing this.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	var recorded int
	db.Raw("SELECT COALESCE(JSON_CONTAINS(COALESCE(rejected_groups, JSON_ARRAY()), CAST(? AS JSON)), 0) FROM rippling_reach WHERE msgid = ?",
		origin, msgID).Scan(&recorded)
	assert.Equal(t, 0, recorded, "the home community is never recorded as having turned its own post away")
}

// The member list is the other door to the same thing. "Leave Member" changes nothing
// about the membership - it exists only to send the member a message - so on a membership
// rippling created it has nothing to do, exactly like a reply on a rippled-in post.
func TestModCannotModmailARippleOnlyMember(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("ripmodmail")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, memberID, groupID, "Member")
	db.Exec("UPDATE memberships SET rippled = 1 WHERE userid = ? AND groupid = ?", memberID, groupID)
	_, modToken := CreateTestSession(t, modID)

	payload, _ := json.Marshal(map[string]interface{}{
		"action": "Leave Approved Member", "userid": memberID, "groupid": groupID,
		"subject": "About your post", "body": "We don't take live animals.",
	})
	req := httptest.NewRequest("POST", "/api/memberships?jwt="+modToken, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)

	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode,
		"a ripple-created membership is not someone this group's moderators write to")

	var queued int64
	db.Table("background_tasks").
		Where("task_type = ? AND JSON_EXTRACT(data, '$.userid') = ? AND JSON_EXTRACT(data, '$.groupid') = ?",
			"email_mod_stdmsg", memberID, groupID).Count(&queued)
	assert.Equal(t, int64(0), queued, "and no mail is queued")
}

// An ordinary member of the group is unaffected.
func TestModCanModmailAnOrdinaryMember(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("ordmodmail")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, memberID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	payload, _ := json.Marshal(map[string]interface{}{
		"action": "Leave Approved Member", "userid": memberID, "groupid": groupID,
		"subject": "Hello", "body": "Just checking in.",
	})
	req := httptest.NewRequest("POST", "/api/memberships?jwt="+modToken, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var queued int64
	db.Table("background_tasks").
		Where("task_type = ? AND JSON_EXTRACT(data, '$.userid') = ? AND JSON_EXTRACT(data, '$.groupid') = ?",
			"email_mod_stdmsg", memberID, groupID).Count(&queued)
	assert.Equal(t, int64(1), queued, "their own members still hear from them")
}

// memberNotifyFlag reads notifyposter from the most recent member-mail task for a
// (member, group). -1 means no such task was queued at all.
func memberNotifyFlag(t *testing.T, userid, groupid uint64) int {
	t.Helper()
	db := database.DBConn

	var flags []int
	db.Raw("SELECT COALESCE(JSON_EXTRACT(data, '$.notifyposter'), -1) FROM background_tasks "+
		"WHERE task_type = 'email_mod_stdmsg' AND JSON_EXTRACT(data, '$.userid') = ? "+
		"AND JSON_EXTRACT(data, '$.groupid') = ? ORDER BY id DESC LIMIT 1", userid, groupid).Scan(&flags)

	if len(flags) == 0 {
		return -1
	}

	return flags[0]
}

// Removing a member whose only tie to the group is a ripple is the group's own business,
// and it happens. What does not happen is a message about it: they never joined, so a note
// from a community they have not heard of is the same confusion as one about their post.
func TestRejectingARippleOnlyMemberIsSilent(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("riprejmem")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, memberID, groupID, "Member")
	db.Exec("UPDATE memberships SET rippled = 1 WHERE userid = ? AND groupid = ?", memberID, groupID)
	_, modToken := CreateTestSession(t, modID)

	payload, _ := json.Marshal(map[string]interface{}{
		"action": "Delete Approved Member", "userid": memberID, "groupid": groupID,
		"subject": "Removed", "body": "We don't take live animals.",
	})
	req := httptest.NewRequest("POST", "/api/memberships?jwt="+modToken, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode, "the removal still goes ahead")

	var remaining int64
	db.Table("memberships").Where("userid = ? AND groupid = ?", memberID, groupID).Count(&remaining)
	assert.Equal(t, int64(0), remaining, "they are off the group")

	assert.Equal(t, 0, memberNotifyFlag(t, memberID, groupID),
		"and hear nothing about a community they never joined")
}

// An ordinary member is told, as they always were.
func TestRejectingAnOrdinaryMemberStillTellsThem(t *testing.T) {
	prefix := uniquePrefix("ordrejmem")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	memberID := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, memberID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	payload, _ := json.Marshal(map[string]interface{}{
		"action": "Delete Approved Member", "userid": memberID, "groupid": groupID,
		"subject": "Removed", "body": "Sorry, this has not worked out.",
	})
	req := httptest.NewRequest("POST", "/api/memberships?jwt="+modToken, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	assert.Equal(t, 1, memberNotifyFlag(t, memberID, groupID),
		"their own members are told when they are removed")
}

// crossPostedPost is a TrashNothing cross-post: the member sent the SAME post to two
// communities, whose per-group mails arrive a second apart, and it later rippled into a
// third. Both direct copies carry rippled_in = 0, as the mail importer writes them; only
// the third is rippling's. Returns (first, second, rippled, msgid).
//
// Discourse 10115: a Tower Hamlets mod rejecting the copy the member had sent to Tower
// Hamlets was told it had "rippled in" and that the member would not be told, because
// the home group was modelled as ONE row - the earliest to arrive - and Southwark's mail
// had landed one second earlier.
func crossPostedPost(t *testing.T, prefix string, posterID uint64, collection string) (uint64, uint64, uint64, uint64) {
	t.Helper()
	db := database.DBConn

	first, rippled, msgID := rippledInPost(t, prefix, posterID, collection)
	second := CreateTestGroup(t, prefix+"_second")

	// CreateTestMessage stamps the first copy 15 minutes ago; this one is a second later.
	res := db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts, rippled_in) "+
		"VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 899 SECOND), ?, 0, 0)", msgID, second, collection)
	require.NoError(t, res.Error, "seed the second direct copy")

	return first, second, rippled, msgID
}

// Every direct copy is a home group. The second community the member posted to is as
// much theirs as the first, so its rejection reaches them.
func TestRejectOnSecondDirectCopyStillNotifiesPoster(t *testing.T) {
	prefix := uniquePrefix("xpostreject")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	_, second, _, msgID := crossPostedPost(t, prefix, posterID, "Pending")
	CreateTestMembership(t, modID, second, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reject", "groupid": second,
		"subject": "Sorry", "body": "We don't accept this.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_rejected", msgID, second),
		"a community the member posted to directly tells them when it rejects")
}

// And its Blank Reply is allowed, for the same reason.
func TestReplyFromSecondDirectCopyIsAllowed(t *testing.T) {
	prefix := uniquePrefix("xpostreply")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	_, second, _, msgID := crossPostedPost(t, prefix, posterID, "Approved")
	CreateTestMembership(t, modID, second, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reply", "groupid": second,
		"subject": "Re: OFFER", "body": "Is this still available?",
	})
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_reply", msgID, second),
		"a community the member posted to directly can write to them")
}

// Deleting the second direct copy tells the poster too.
func TestDeleteOnSecondDirectCopyStillNotifiesPoster(t *testing.T) {
	prefix := uniquePrefix("xpostdelete")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	_, second, _, msgID := crossPostedPost(t, prefix, posterID, "Approved")
	CreateTestMembership(t, modID, second, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Delete", "groupid": second,
		"subject": "Sorry", "body": "Not suitable here.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, 1, notifyPosterFlag(t, "email_message_rejected", msgID, second),
		"a community the member posted to directly tells them when it deletes")
}

// The copy that rippled in on its own stays silent, cross-post or not.
func TestRejectOnRippledCopyOfCrossPostStaysSilent(t *testing.T) {
	prefix := uniquePrefix("xpostripple")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	_, _, rippled, msgID := crossPostedPost(t, prefix, posterID, "Pending")
	CreateTestMembership(t, modID, rippled, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status := postMessageAction(t, modToken, map[string]interface{}{
		"id": msgID, "action": "Reject", "groupid": rippled,
		"subject": "Sorry", "body": "We don't accept this.",
	})
	assert.Equal(t, fiber.StatusOK, status)

	assert.Equal(t, 0, notifyPosterFlag(t, "email_message_rejected", msgID, rippled),
		"the rippled-in copy's rejection is still not sent to the poster")
}

// HomeGroups is the set every notify decision reads: every direct copy, none of the
// rippled ones, however late any of them was approved.
func TestHomeGroupsAreEveryDirectCopy(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("homeset")

	posterID := CreateTestUser(t, prefix+"_poster", "User")
	first, second, rippled, msgID := crossPostedPost(t, prefix, posterID, "Approved")

	// Approving re-stamps arrival: the first copy now looks NEWEST of the three.
	db.Exec("UPDATE messages_groups SET arrival = NOW() + INTERVAL 1 HOUR WHERE msgid = ? AND groupid = ?", msgID, first)

	home := message.HomeGroups(db, msgID)
	assert.True(t, home[first], "the first direct copy is home")
	assert.True(t, home[second], "the second direct copy is home")
	assert.False(t, home[rippled], "the rippled-in copy is not")

	assert.Equal(t, 1, message.NotifyPosterFlag(home, first))
	assert.Equal(t, 1, message.NotifyPosterFlag(home, second))
	assert.Equal(t, 0, message.NotifyPosterFlag(home, rippled))
}
