package test

// Tests for what the Lockdown tab shows while a lockdown is on (plan section 11.11): the counts
// of what is held, and the browser Support uses to look through the held items. Like
// lockdown_handlers_test.go these press a real lockdown through PATCH and clean up after it.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func getLockdownJSON(t *testing.T, url string) (int, map[string]interface{}) {
	t.Helper()
	req := httptest.NewRequest("GET", url, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	return resp.StatusCode, result
}

func addHold(t *testing.T, incidentID uint64, kind string, refid uint64, userid uint64, outcome string) uint64 {
	t.Helper()
	row := map[string]interface{}{"lockdownid": incidentID, "kind": kind, "refid": refid, "userid": userid}
	if outcome != "" {
		row["outcome"] = outcome
	}
	require.NoError(t, database.DBConn.Table("lockdown_holds").Create(row).Error)
	var id uint64
	database.DBConn.Table("lockdown_holds").Select("id").Where("kind = ? AND refid = ?", kind, refid).Scan(&id)
	return id
}

// --- GET /modtools/lockdown/stats ---

func TestGetModtoolsLockdownStatsCounts(t *testing.T) {
	prefix := uniquePrefix("ld_counts")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")

	incidentID := pressLockdown(t, token, "counts "+prefix)
	defer cleanupIncident(t, incidentID)

	addHold(t, incidentID, "chat", 900000001, senderID, "")
	addHold(t, incidentID, "chat", 900000002, senderID, "released")
	addHold(t, incidentID, "post", 900000003, senderID, "")
	addHold(t, incidentID, "chitchat", 900000004, senderID, "")

	for kind, count := range map[string]int{
		"held:events":                        2,
		"deferred:digest":                    4,
		"spooled_held:chat":                  1,
		"push":                               3,
		"export":                             1,
		"refused_member":                     2,
		fmt.Sprintf("refused:%d", supportID): 5,
		"email:digest":                       99, // an old-style counter no longer summed anywhere
		"admins_withdrawn":                   3,
	} {
		require.NoError(t, db.Table("lockdown_counters").Create(map[string]interface{}{
			"lockdownid": incidentID, "kind": kind, "count": count,
		}).Error)
	}

	status, result := getLockdownJSON(t, "/api/modtools/lockdown/stats?jwt="+token)
	require.Equal(t, 200, status, "%v", result)

	counts, ok := result["counts"].(map[string]interface{})
	require.True(t, ok, "counts must be present: %v", result)
	assert.Equal(t, float64(2), counts["chat"], "every chat hold so far, released or not")
	assert.Equal(t, float64(1), counts["post"])
	assert.Equal(t, float64(1), counts["chitchat"])
	assert.Equal(t, float64(2), counts["events"])
	assert.Equal(t, float64(5), counts["email"], "deferred mail runs plus mail waiting in the send queue")
	assert.Equal(t, float64(3), counts["push"])
	assert.Equal(t, float64(1), counts["export"])
	assert.Equal(t, float64(7), counts["refused"], "moderator and member refusals together")
	assert.Equal(t, float64(3), counts["admins"], "moderators' unsent admins sent back to pending")

	for _, gone := range []string{"held", "triage", "samples", "clusters", "accountscreated", "outcomes", "waiting", "counters"} {
		_, present := result[gone]
		assert.False(t, present, "%s is no longer part of the stats", gone)
	}

	assert.Equal(t, float64(supportID), result["pressedby"])
	assert.Equal(t, float64(incidentID), result["rowid"])
	api, ok := result["api"].(map[string]interface{})
	require.True(t, ok)
	assert.Equal(t, float64(5), api["delayseconds"])
}

// TestGetModtoolsLockdownStatsRelease: after a close, Support can still watch what was held
// drain - how many are still held, what became of the rest, and the email queue.
func TestGetModtoolsLockdownStatsRelease(t *testing.T) {
	prefix := uniquePrefix("ld_release")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")

	incidentID := pressLockdown(t, token, "release "+prefix)
	defer cleanupIncident(t, incidentID)

	addHold(t, incidentID, "chat", 910000001, senderID, "")
	addHold(t, incidentID, "chat", 910000002, senderID, "released")
	addHold(t, incidentID, "chat", 910000003, senderID, "rejected")
	addHold(t, incidentID, "post", 910000004, senderID, "review")
	addHold(t, incidentID, "post", 910000005, senderID, "gone")
	addHold(t, incidentID, "post", 910000006, senderID, "releasing")
	require.NoError(t, database.DBConn.Table("lockdown_counters").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "queue:email", "count": 42,
	}).Error)

	resp, _ := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "done"})
	require.Equal(t, 200, resp.StatusCode)

	status, result := getLockdownJSON(t, "/api/modtools/lockdown/stats?jwt="+token)
	require.Equal(t, 200, status, "%v", result)

	release, ok := result["release"].(map[string]interface{})
	require.True(t, ok, "release must be present after close: %v", result)
	chat := release["chat"].(map[string]interface{})
	assert.Equal(t, float64(1), chat["held"])
	assert.Equal(t, float64(1), chat["released"])
	assert.Equal(t, float64(1), chat["rejected"])
	post := release["post"].(map[string]interface{})
	assert.Equal(t, float64(1), post["held"], "a post claimed by a release that has not finished is still held")
	assert.Equal(t, float64(1), post["review"])
	assert.Equal(t, float64(1), post["gone"])
	chitchat := release["chitchat"].(map[string]interface{})
	assert.Equal(t, float64(0), chitchat["held"])

	queue := result["queue"].(map[string]interface{})
	assert.Equal(t, float64(42), queue["email"])
	counts := result["counts"].(map[string]interface{})
	assert.Equal(t, float64(0), counts["email"], "the queue reading is not a count of emails held")
}

func TestGetModtoolsLockdownStatsAcksListEveryLoop(t *testing.T) {
	prefix := uniquePrefix("ld_acks")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "acks "+prefix)
	defer cleanupIncident(t, incidentID)

	db.Exec("DELETE FROM lockdown_acks WHERE `loop` = 'tick'")
	require.NoError(t, db.Exec("INSERT INTO lockdown_acks (`loop`, lockdownrowid, seenat) VALUES ('tick', ?, NOW())", incidentID).Error)
	defer db.Exec("DELETE FROM lockdown_acks WHERE `loop` = 'tick'")

	status, result := getLockdownJSON(t, "/api/modtools/lockdown/stats?jwt="+token)
	require.Equal(t, 200, status)
	acks, ok := result["acks"].([]interface{})
	require.True(t, ok, "%v", result)
	require.Len(t, acks, 8)

	loops := map[string]map[string]interface{}{}
	for _, a := range acks {
		row := a.(map[string]interface{})
		loops[row["loop"].(string)] = row
	}
	_, hasTriage := loops["triage"]
	assert.False(t, hasTriage, "the batch loop is lockdown:tick now")
	require.Contains(t, loops, "tick")
	assert.Equal(t, true, loops["tick"]["caughtup"])
}

func TestCommunityEventCreatedWhileEventsHeldIsCounted(t *testing.T) {
	prefix := uniquePrefix("ld_event_count")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, supportToken := CreateTestSession(t, supportID)
	userID := CreateTestUser(t, prefix, "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	incidentID := pressLockdown(t, supportToken, "event count "+prefix)
	defer cleanupIncident(t, incidentID)

	body := fmt.Sprintf(`{"title":"Held Event %s","location":"Edinburgh","description":"Made during a lockdown","contactname":"Test","contactemail":"test@test.com","groupid":%d}`, prefix, groupID)
	req := httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode, "creating an event still works while held; it just waits for approval")

	status, result := getLockdownJSON(t, "/api/modtools/lockdown/stats?jwt="+supportToken)
	require.Equal(t, 200, status)
	counts := result["counts"].(map[string]interface{})
	assert.Equal(t, float64(1), counts["events"])
}

// --- notice text ---

func TestPatchLockdownNoticeRejectsOverlong(t *testing.T) {
	prefix := uniquePrefix("ld_notice_long")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "notice long "+prefix)
	defer cleanupIncident(t, incidentID)

	resp, _ := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": strings.Repeat("a", 501)})
	assert.Equal(t, 400, resp.StatusCode)

	resp2, result := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": strings.Repeat("a", 500)})
	assert.Equal(t, 200, resp2.StatusCode, "%v", result)
}

func TestPatchLockdownNoticeNullOrBlankMeansNoNotice(t *testing.T) {
	prefix := uniquePrefix("ld_notice_none")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	resp, result := patchLockdown(t, token, map[string]interface{}{
		"action": "press", "reason": "notice none " + prefix, "notice": "Messages may be delayed.",
	})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	incidentID := uint64(result["incidentid"].(float64))
	defer cleanupIncident(t, incidentID)
	assert.Equal(t, "Messages may be delayed.", result["notice"], "press carries the notice text")

	_, cleared := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "   "})
	assert.Nil(t, cleared["notice"], "a blank notice is no notice")

	_, _ = patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "Back soon."})
	_, cleared2 := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": nil})
	assert.Nil(t, cleared2["notice"], "null is no notice")

	status, public := getLockdownJSON(t, "/api/lockdown")
	require.Equal(t, 200, status)
	assert.Nil(t, public["notice"])
}

func TestPatchLockdownRemovedActionsAreUnknown(t *testing.T) {
	prefix := uniquePrefix("ld_removed")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "removed actions "+prefix)
	defer cleanupIncident(t, incidentID)

	for _, action := range []string{"phrases", "markspam", "releaseclass"} {
		resp, _ := patchLockdown(t, token, map[string]interface{}{"action": action})
		assert.Equal(t, 400, resp.StatusCode, "%s is no longer an action", action)
	}
}

// --- GET /modtools/lockdown/held ---

func TestGetModtoolsLockdownHeldChat(t *testing.T) {
	prefix := uniquePrefix("ld_held_chat")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	senderID := CreateTestUserWithEmail(t, prefix+"_sender", prefix+"-sender@example.com")
	recipientID := CreateTestUser(t, prefix+"_recip", "User")

	incidentID := pressLockdown(t, token, "held chat "+prefix)
	defer cleanupIncident(t, incidentID)

	chatID := CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")
	heldMsg := CreateTestChatMessage(t, chatID, senderID, "Claim your free voucher "+prefix)
	otherMsg := CreateTestChatMessage(t, chatID, senderID, "Is the sofa still available "+prefix)
	releasedMsg := CreateTestChatMessage(t, chatID, senderID, "Already released "+prefix)
	addHold(t, incidentID, "chat", heldMsg, senderID, "")
	addHold(t, incidentID, "chat", otherMsg, senderID, "")
	addHold(t, incidentID, "chat", releasedMsg, senderID, "released")

	status, result := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=chat&jwt="+token)
	require.Equal(t, 200, status, "%v", result)
	items := result["items"].([]interface{})
	require.Len(t, items, 2, "only items still held")
	assert.Nil(t, result["next"])

	newest := items[0].(map[string]interface{})
	assert.Equal(t, float64(otherMsg), newest["refid"], "newest first")
	assert.Equal(t, "chat", newest["kind"])
	assert.Equal(t, float64(senderID), newest["userid"])
	assert.Equal(t, prefix+"-sender@example.com", newest["email"])
	assert.NotEmpty(t, newest["name"])
	assert.Equal(t, float64(recipientID), newest["recipientid"])
	assert.NotEmpty(t, newest["recipientname"])
	assert.Contains(t, newest["text"], "sofa")

	_, byText := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=chat&q=voucher&jwt="+token)
	textItems := byText["items"].([]interface{})
	require.Len(t, textItems, 1, "q searches the text")
	assert.Equal(t, float64(heldMsg), textItems[0].(map[string]interface{})["refid"])

	_, byEmail := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=chat&q="+prefix+"-sender&jwt="+token)
	assert.Len(t, byEmail["items"].([]interface{}), 2, "q searches the sender's email")

	_, literal := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=chat&q=%25&jwt="+token)
	assert.Len(t, literal["items"].([]interface{}), 0, "a % in q matches literally, not everything")
}

func TestGetModtoolsLockdownHeldPostAndChitChat(t *testing.T) {
	prefix := uniquePrefix("ld_held_post")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	groupID := CreateTestGroup(t, prefix)

	incidentID := pressLockdown(t, token, "held post "+prefix)
	defer cleanupIncident(t, incidentID)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: Held sofa "+prefix, 55.95, -3.19)
	addHold(t, incidentID, "post", msgID, posterID, "")
	nfID := CreateTestNewsfeed(t, posterID, 55.95, -3.19, "Held chitchat "+prefix)
	addHold(t, incidentID, "chitchat", nfID, posterID, "")

	_, posts := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=post&jwt="+token)
	postItems := posts["items"].([]interface{})
	require.Len(t, postItems, 1)
	post := postItems[0].(map[string]interface{})
	assert.Contains(t, post["text"], "Held sofa")
	assert.Nil(t, post["recipientid"], "only chat has a recipient")

	_, chitchat := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=chitchat&jwt="+token)
	ccItems := chitchat["items"].([]interface{})
	require.Len(t, ccItems, 1)
	assert.Contains(t, ccItems[0].(map[string]interface{})["text"], "Held chitchat")
}

func TestGetModtoolsLockdownHeldPages(t *testing.T) {
	prefix := uniquePrefix("ld_held_pages")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	recipientID := CreateTestUser(t, prefix+"_recip", "User")

	incidentID := pressLockdown(t, token, "held pages "+prefix)
	defer cleanupIncident(t, incidentID)

	chatID := CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")
	for i := 0; i < 51; i++ {
		msg := CreateTestChatMessage(t, chatID, senderID, fmt.Sprintf("message %d %s", i, prefix))
		addHold(t, incidentID, "chat", msg, senderID, "")
	}

	_, first := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=chat&jwt="+token)
	require.Len(t, first["items"].([]interface{}), 50)
	next, ok := first["next"].(float64)
	require.True(t, ok, "a full page carries a cursor: %v", first["next"])

	_, second := getLockdownJSON(t, fmt.Sprintf("/api/modtools/lockdown/held?kind=chat&before=%d&jwt=%s", uint64(next), token))
	require.Len(t, second["items"].([]interface{}), 1)
	assert.Nil(t, second["next"])
}

func TestGetModtoolsLockdownHeldValidatesAndRefuses(t *testing.T) {
	prefix := uniquePrefix("ld_held_auth")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, supportToken := CreateTestSession(t, supportID)
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	status, _ := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=spam&jwt="+supportToken)
	assert.Equal(t, 400, status)

	status2, _ := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=chat&jwt="+modToken)
	assert.Equal(t, 403, status2, "only Support and Admin browse held items")

	status3, _ := getLockdownJSON(t, "/api/modtools/lockdown/held?kind=chat")
	assert.Equal(t, 401, status3)
}
