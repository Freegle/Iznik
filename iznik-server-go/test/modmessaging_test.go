package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/modmessaging"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// makeUnaddressed marks a post's ORIGIN messages_groups row the way the TN API ingestion
// does for a post placed on a Freegle group its poster never chose.
func makeUnaddressed(msgid uint64) {
	database.DBConn.Exec(
		"UPDATE messages_groups SET mod_messaging_allowed = 0 WHERE msgid = ? AND rippled_in = 0", msgid)
}

func messageDeletedAt(msgid uint64) *string {
	var d *string
	database.DBConn.Raw("SELECT deleted FROM messages WHERE id = ?", msgid).Scan(&d)
	return d
}

func liveGroupRows(msgid uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND deleted = 0", msgid).Scan(&n)
	return n
}

// --- The predicates themselves ---

func TestPostIsUnaddressedOnlyForATNPostOnAGroupNobodyChose(t *testing.T) {
	prefix := uniquePrefix("mmapost")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")

	ordinary := CreateTestMessage(t, poster, group, "OFFER: ordinary "+prefix, 51.5, -0.1)
	assert.False(t, modmessaging.PostIsUnaddressed(db, ordinary),
		"an ordinary post must never read as unaddressed")

	unaddressed := CreateTestMessage(t, poster, group, "OFFER: unaddressed "+prefix, 51.5, -0.1)
	makeUnaddressed(unaddressed)
	assert.True(t, modmessaging.PostIsUnaddressed(db, unaddressed))

	// A rippled-in copy is inserted by the engine without the column and so takes the
	// table default (allowed). That must not mask the origin row's answer - if it did,
	// every unaddressed post would become addressed the moment it rippled.
	rippledTo := CreateTestGroup(t, prefix+"_r")
	addRippledCopy(unaddressed, rippledTo)
	assert.True(t, modmessaging.PostIsUnaddressed(db, unaddressed),
		"a rippled-in copy must not make an unaddressed post look addressed")

	assert.False(t, modmessaging.PostIsUnaddressed(db, 0), "no message id is not unaddressed")
}

func TestUserIsUnaddressedOnlyIsFalseForMixedAndForOrdinaryPosters(t *testing.T) {
	prefix := uniquePrefix("mmauser")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)

	// Someone who has only ever arrived here through unaddressed TN posts.
	tnOnly := CreateTestUser(t, prefix+"_tnonly", "User")
	msg := CreateTestMessage(t, tnOnly, group, "OFFER: tnonly "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)
	assert.True(t, modmessaging.UserIsUnaddressedOnly(db, tnOnly))

	// The mixed case the requirement calls out: one unaddressed post AND an ordinary one.
	// They are a real member, so nothing is restricted for them.
	mixed := CreateTestUser(t, prefix+"_mixed", "User")
	mixedTN := CreateTestMessage(t, mixed, group, "OFFER: mixedtn "+prefix, 51.5, -0.1)
	makeUnaddressed(mixedTN)
	CreateTestMessage(t, mixed, group, "OFFER: mixedown "+prefix, 51.5, -0.1)
	assert.False(t, modmessaging.UserIsUnaddressedOnly(db, mixed),
		"a poster with an ordinary post as well must NOT be restricted")

	// An ordinary member, and a member who has never posted at all.
	ordinary := CreateTestUser(t, prefix+"_ordinary", "User")
	CreateTestMessage(t, ordinary, group, "OFFER: ordinary "+prefix, 51.5, -0.1)
	assert.False(t, modmessaging.UserIsUnaddressedOnly(db, ordinary))

	silent := CreateTestUser(t, prefix+"_silent", "User")
	assert.False(t, modmessaging.UserIsUnaddressedOnly(db, silent),
		"someone with no posts at all must not be restricted")

	// The batch form answers the same question for a whole page in one query.
	batch := modmessaging.UsersUnaddressedOnly(db, []uint64{tnOnly, mixed, ordinary, silent})
	assert.True(t, batch[tnOnly])
	assert.False(t, batch[mixed])
	assert.False(t, batch[ordinary])
	assert.False(t, batch[silent])
}

// A removed post stays removed as far as the member restriction is concerned: taking their
// only post down must not hand a moderator a chat button they did not have a minute ago.
func TestUserStaysUnaddressedOnlyAfterTheirPostIsRemoved(t *testing.T) {
	prefix := uniquePrefix("mmagone")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	msg := CreateTestMessage(t, poster, group, "OFFER: gone "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)

	modmessaging.RemoveUnaddressedPost(db, msg)

	assert.True(t, modmessaging.UserIsUnaddressedOnly(db, poster))
}

// --- Removal ---

func TestRemoveUnaddressedPostTakesItOffEveryGroupAndAudits(t *testing.T) {
	prefix := uniquePrefix("mmarm")
	db := database.DBConn
	origin := CreateTestGroup(t, prefix+"_o")
	rippled := CreateTestGroup(t, prefix+"_r")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	msg := CreateTestMessage(t, poster, origin, "OFFER: remove "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)
	addRippledCopy(msg, rippled)
	addReachRow(msg, "expanding")

	modmessaging.RemoveUnaddressedPost(db, msg)

	assert.Equal(t, int64(0), liveGroupRows(msg), "no group may still carry the post")
	assert.NotNil(t, messageDeletedAt(msg), "the message itself must be soft-deleted")
	assert.Equal(t, "held", reachStatusOf(msg), "the ripple must be frozen so it can never re-reach")

	// Soft, not hard: Support has to be able to see and undo this, since no moderator was
	// ever involved.
	var stillThere int64
	db.Raw("SELECT COUNT(*) FROM messages WHERE id = ?", msg).Scan(&stillThere)
	assert.Equal(t, int64(1), stillThere)

	// One audit row per community it was on, attributed to nobody - it was not a mod's
	// decision.
	var logs int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = ? AND subtype = ? AND msgid = ? AND byuser IS NULL",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, msg).Scan(&logs)
	assert.Equal(t, int64(2), logs, "one Message/Deleted log per group the post was live on")
}

// Removal is reached from a report quorum, which two reporters can hit concurrently and
// which a task retry can replay - so running it twice must not double up the audit trail or
// re-freeze a ripple somebody has since released. A missing id has to be a no-op for the
// same reason it is elsewhere in this package: these functions only ever take things away.
func TestRemoveUnaddressedPostIsIdempotentAndIgnoresAMissingId(t *testing.T) {
	prefix := uniquePrefix("mmanoop")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	msg := CreateTestMessage(t, poster, group, "OFFER: twice "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)

	modmessaging.RemoveUnaddressedPost(db, msg)
	modmessaging.RemoveUnaddressedPost(db, msg)

	var logs int64
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = ? AND subtype = ? AND msgid = ?",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, msg).Scan(&logs)
	assert.Equal(t, int64(1), logs, "a second removal must not write a second audit row")

	// A malformed call - no id at all - must do nothing rather than match every row.
	modmessaging.RemoveUnaddressedPost(db, 0)
	db.Raw("SELECT COUNT(*) FROM logs WHERE type = ? AND subtype = ? AND msgid = ?",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, msg).Scan(&logs)
	assert.Equal(t, int64(1), logs)

	assert.False(t, modmessaging.UserIsUnaddressedOnly(db, 0),
		"no user id cannot be restricted - it says nothing about who they are")
}

// --- The report quorum ---

func TestReportsRemoveAnUnaddressedPostAtQuorumInsteadOfPendingIt(t *testing.T) {
	prefix := uniquePrefix("mmaq")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	first := CreateTestUser(t, prefix+"_r1", "User")
	second := CreateTestUser(t, prefix+"_r2", "User")
	msg := CreateTestMessage(t, poster, group, "OFFER: quorum "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)
	addReachRow(msg, "expanding")

	// One report is not enough - and crucially the post is NOT pended either, because
	// pending it would put it in a mod queue nobody owns.
	microvolunteering.RecordReportVerdict(db, first, msg, group, "spam")
	assert.Equal(t, utils.COLLECTION_APPROVED, collectionOf(msg, group),
		"one report must change nothing")
	assert.Nil(t, messageDeletedAt(msg))

	// The same person reporting twice is still one report: microactions is keyed on
	// (userid, msgid).
	microvolunteering.RecordReportVerdict(db, first, msg, group, "spam again")
	assert.Nil(t, messageDeletedAt(msg), "a repeat report from the same person is not a second vote")

	microvolunteering.RecordReportVerdict(db, second, msg, group, "spam")
	assert.NotNil(t, messageDeletedAt(msg), "two distinct reports must remove the post")
	assert.Equal(t, int64(0), liveGroupRows(msg))
	assert.Equal(t, "held", reachStatusOf(msg))
}

// The mod-is-quorum shortcut deliberately does not apply: for an unaddressed post the
// terminal action is a network-wide delete nobody reviews, so it takes two people. A
// moderator who wants one gone has Delete.
func TestOneModsReportDoesNotRemoveAnUnaddressedPost(t *testing.T) {
	prefix := uniquePrefix("mmamod")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, group, "Moderator")
	msg := CreateTestMessage(t, poster, group, "OFFER: modreport "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)

	microvolunteering.RecordReportVerdict(db, mod, msg, group, "not ok")

	assert.Nil(t, messageDeletedAt(msg), "a single mod report must not delete the post")
	assert.Equal(t, utils.COLLECTION_APPROVED, collectionOf(msg, group),
		"and must not pend it into a queue no community owns either")
}

// An ordinary post is untouched by all of this - it still goes back to Pending for its own
// community's moderators.
func TestOrdinaryPostStillGoesToPendingAtQuorum(t *testing.T) {
	prefix := uniquePrefix("mmaord")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	first := CreateTestUser(t, prefix+"_r1", "User")
	second := CreateTestUser(t, prefix+"_r2", "User")
	msg := CreateTestMessage(t, poster, group, "OFFER: ordinaryq "+prefix, 51.5, -0.1)

	microvolunteering.RecordReportVerdict(db, first, msg, group, "spam")
	microvolunteering.RecordReportVerdict(db, second, msg, group, "spam")

	assert.Equal(t, utils.COLLECTION_PENDING, collectionOf(msg, group))
	assert.Nil(t, messageDeletedAt(msg), "an ordinary post is reviewed, never auto-deleted")
}

// --- The Report action ---

// postMessageActionResult is postMessageAction plus the decoded body, for the cases that
// need to read `ret` rather than just the HTTP status.
func postMessageActionResult(t *testing.T, token string, body map[string]interface{}) (int, map[string]interface{}) {
	t.Helper()
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	return resp.StatusCode, result
}

func TestReportActionRecordsAVerdictWithoutTellingAnyModerator(t *testing.T) {
	prefix := uniquePrefix("mmarep")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	reporter := CreateTestUser(t, prefix+"_reporter", "User")
	_, token := CreateTestSession(t, reporter)
	msg := CreateTestMessage(t, poster, group, "OFFER: reportaction "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)

	status, result := postMessageActionResult(t, token, map[string]interface{}{
		"id": msg, "action": "Report", "groupid": group, "message": "Looks like spam",
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(0), result["ret"])

	var verdicts int64
	db.Raw("SELECT COUNT(*) FROM microactions WHERE msgid = ? AND userid = ? AND result = 'Reject'",
		msg, reporter).Scan(&verdicts)
	assert.Equal(t, int64(1), verdicts, "the report must be recorded as a review verdict")

	// No mod chat anywhere: that is the whole point.
	var chats int64
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE chattype = ? AND groupid = ?",
		utils.CHAT_TYPE_USER2MOD, group).Scan(&chats)
	assert.Equal(t, int64(0), chats, "reporting one of these posts must not open a chat to the mods")
}

// The action must not become a back door for reporting an ordinary post without its
// moderators ever hearing about it.
func TestReportActionRefusesAnOrdinaryPost(t *testing.T) {
	prefix := uniquePrefix("mmarepord")
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	reporter := CreateTestUser(t, prefix+"_reporter", "User")
	_, token := CreateTestSession(t, reporter)
	msg := CreateTestMessage(t, poster, group, "OFFER: reportord "+prefix, 51.5, -0.1)

	status, _ := postMessageActionResult(t, token, map[string]interface{}{
		"id": msg, "action": "Report", "groupid": group, "message": "Looks like spam",
	})
	assert.Equal(t, 400, status)
}

// A client too old to know about the Report action still reports the old way - a User2Mod
// chat message referencing the post. That must record the verdict and write NO chat
// message, or the report lands in a mod inbox after all.
func TestLegacyReportRouteWritesNoChatMessage(t *testing.T) {
	prefix := uniquePrefix("mmaleg")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	reporter := CreateTestUser(t, prefix+"_reporter", "User")
	CreateTestMembership(t, reporter, group, "Member")
	_, token := CreateTestSession(t, reporter)
	msg := CreateTestMessage(t, poster, group, "OFFER: legacyreport "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)

	chatID := CreateTestChatRoom(t, reporter, nil, &group, utils.CHAT_TYPE_USER2MOD)

	body, _ := json.Marshal(map[string]interface{}{
		"message": "I'm reporting this post", "refmsgid": msg,
	})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var msgs int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ? AND refmsgid = ?", chatID, msg).Scan(&msgs)
	assert.Equal(t, int64(0), msgs, "the report must not appear in the mods' chat")

	var verdicts int64
	db.Raw("SELECT COUNT(*) FROM microactions WHERE msgid = ? AND userid = ?", msg, reporter).Scan(&verdicts)
	assert.Equal(t, int64(1), verdicts, "but it must still count as a report")
}

// --- Moderator guards ---

func TestModeratorCannotReplyToOrEditAnUnaddressedPost(t *testing.T) {
	prefix := uniquePrefix("mmaguard")
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, group, "Moderator")
	_, modToken := CreateTestSession(t, mod)
	msg := CreateTestMessage(t, poster, group, "OFFER: guard "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)

	status, _ := postMessageActionResult(t, modToken, map[string]interface{}{
		"id": msg, "action": "Reply", "groupid": group,
		"subject": "About your post", "body": "Please add a photo.",
	})
	assert.Equal(t, 403, status, "Blank Reply / standard messages must be refused")

	patch, _ := json.Marshal(map[string]interface{}{"id": msg, "subject": "OFFER: rewritten"})
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/message?jwt=%s", modToken), bytes.NewBuffer(patch))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode, "a mod must not rewrite a post whose author never joined")

	// Approve is still available: mods keep the queue actions, they just cannot talk to
	// the poster or put words in their mouth.
	status, _ = postMessageActionResult(t, modToken, map[string]interface{}{
		"id": msg, "action": "Approve", "groupid": group,
	})
	assert.Equal(t, 200, status, "Approve must still work")
}

func TestModeratorCannotOpenAChatToAnUnaddressedOnlyMember(t *testing.T) {
	prefix := uniquePrefix("mmachat")
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, poster, group, "Member")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, group, "Moderator")
	_, modToken := CreateTestSession(t, mod)
	makeUnaddressed(CreateTestMessage(t, poster, group, "OFFER: chatguard "+prefix, 51.5, -0.1))

	body, _ := json.Marshal(map[string]interface{}{
		"chattype": utils.CHAT_TYPE_USER2MOD, "groupid": group, "userid": poster,
	})
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/chat/rooms?jwt=%s", modToken), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)

	// Once they have posted normally too they are a real member, and the chat opens.
	CreateTestMessage(t, poster, group, "OFFER: normalpost "+prefix, 51.5, -0.1)
	body2, _ := json.Marshal(map[string]interface{}{
		"chattype": utils.CHAT_TYPE_USER2MOD, "groupid": group, "userid": poster,
	})
	req2 := httptest.NewRequest("PUT", fmt.Sprintf("/api/chat/rooms?jwt=%s", modToken), bytes.NewBuffer(body2))
	req2.Header.Set("Content-Type", "application/json")
	resp2, err := getApp().Test(req2, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp2.StatusCode, "a mixed poster is a real member and can be chatted to")
}

func TestModeratorCannotMailAnUnaddressedOnlyMember(t *testing.T) {
	prefix := uniquePrefix("mmamail")
	db := database.DBConn
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	CreateTestMembership(t, poster, group, "Member")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, group, "Moderator")
	_, modToken := CreateTestSession(t, mod)
	makeUnaddressed(CreateTestMessage(t, poster, group, "OFFER: mailguard "+prefix, 51.5, -0.1))

	body, _ := json.Marshal(map[string]interface{}{
		"userid": poster, "groupid": group, "action": "Leave Approved Member",
		"subject": "Hello", "body": "A note from your volunteers.",
	})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/memberships?jwt=%s", modToken), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)

	var tasks int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_mod_stdmsg' AND data LIKE ?",
		fmt.Sprintf("%%\"userid\": %d%%", poster)).Scan(&tasks)
	assert.Equal(t, int64(0), tasks, "no modmail may be queued")
}

// --- Payloads the moderation UI reads ---

func TestMembersListSaysWhoCannotBeContacted(t *testing.T) {
	prefix := uniquePrefix("mmalist")
	group := CreateTestGroup(t, prefix)
	tnOnly := CreateTestUser(t, prefix+"_tnonly", "User")
	CreateTestMembership(t, tnOnly, group, "Member")
	makeUnaddressed(CreateTestMessage(t, tnOnly, group, "OFFER: listtn "+prefix, 51.5, -0.1))

	ordinary := CreateTestUser(t, prefix+"_ordinary", "User")
	CreateTestMembership(t, ordinary, group, "Member")
	CreateTestMessage(t, ordinary, group, "OFFER: listord "+prefix, 51.5, -0.1)

	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, group, "Moderator")
	_, modToken := CreateTestSession(t, mod)

	req := httptest.NewRequest("GET",
		fmt.Sprintf("/api/memberships?groupid=%d&collection=Approved&limit=100&jwt=%s", group, modToken), nil)
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var payload struct {
		Members []struct {
			Userid              uint64 `json:"userid"`
			ModMessagingAllowed bool   `json:"mod_messaging_allowed"`
		} `json:"members"`
	}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&payload))

	seen := map[uint64]bool{}
	for _, m := range payload.Members {
		seen[m.Userid] = m.ModMessagingAllowed
	}
	assert.Contains(t, seen, tnOnly)
	assert.False(t, seen[tnOnly], "the TN-only member must be flagged as uncontactable")
	assert.Contains(t, seen, ordinary)
	assert.True(t, seen[ordinary], "an ordinary member must be unaffected")
}

func TestMessagePayloadSaysWhenAPostCannotBeRepliedTo(t *testing.T) {
	prefix := uniquePrefix("mmapayload")
	group := CreateTestGroup(t, prefix)
	poster := CreateTestUser(t, prefix+"_poster", "User")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, group, "Moderator")
	_, modToken := CreateTestSession(t, mod)
	msg := CreateTestMessage(t, poster, group, "OFFER: payload "+prefix, 51.5, -0.1)
	makeUnaddressed(msg)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msg, modToken), nil)
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var payload struct {
		ID                  uint64 `json:"id"`
		ModMessagingAllowed bool   `json:"mod_messaging_allowed"`
	}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&payload))
	assert.Equal(t, msg, payload.ID)
	assert.False(t, payload.ModMessagingAllowed)
}
