package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The "Edit and Message" standard message changes the post and messages the poster in one
// PATCH /message call. Anything that would refuse the message refuses the edit too, so a
// poster is never left with a changed post and no word about it.

func patchMessageAsMod(t *testing.T, token string, body map[string]interface{}) (int, map[string]interface{}) {
	t.Helper()
	b, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)

	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	return resp.StatusCode, result
}

func messageTextbody(msgid uint64) string {
	var textbody string
	database.DBConn.Raw("SELECT COALESCE(textbody, '') FROM messages WHERE id = ?", msgid).Scan(&textbody)
	return textbody
}

func replyTaskCount(msgid uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'email_message_reply' "+
		"AND JSON_EXTRACT(data, '$.msgid') = ?", msgid).Scan(&n)
	return n
}

func logStdmsgid(msgid uint64, subtype string) uint64 {
	var id uint64
	database.DBConn.Raw("SELECT COALESCE(stdmsgid, 0) FROM logs WHERE msgid = ? AND type = 'Message' AND subtype = ? "+
		"ORDER BY id DESC LIMIT 1", msgid, subtype).Scan(&id)
	return id
}

func TestEditAndMessageEditsThePostAndMessagesThePoster(t *testing.T) {
	prefix := uniquePrefix("editmsg")
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, mod)
	msg := CreateTestMessage(t, poster, "OFFER: Kitchen knives "+prefix, 51.5, -0.1)

	cfg := createTestModConfig(t, prefix+"_cfg", mod)
	stdmsg := createTestStdMsg(t, cfg, prefix+"_knives")

	status, _ := patchMessageAsMod(t, modToken, map[string]interface{}{
		"id":           msg,
		"textbody":     "Set of knives.\n\nYou must be 18 or over to collect.",
		"replysubject": "Re: OFFER: Kitchen knives",
		"replybody":    "We've added a note about knives to your post.",
		"stdmsgid":     stdmsg,
	})
	assert.Equal(t, 200, status)

	assert.Contains(t, messageTextbody(msg), "18 or over", "the post is edited")
	assert.Equal(t, int64(1), replyTaskCount(msg), "the poster is messaged")

	var body string
	database.DBConn.Raw("SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.body')) FROM background_tasks "+
		"WHERE task_type = 'email_message_reply' AND JSON_EXTRACT(data, '$.msgid') = ?", msg).Scan(&body)
	assert.Equal(t, "We've added a note about knives to your post.", body)

	assert.Equal(t, stdmsg, logStdmsgid(msg, "Edit"), "the edit is logged with the standard message")
	assert.Equal(t, stdmsg, logStdmsgid(msg, "Replied"), "the message is logged with the standard message")
}

func TestPlainEditStillSendsNothing(t *testing.T) {
	prefix := uniquePrefix("editonly")
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, mod)
	msg := CreateTestMessage(t, poster, "OFFER: Firewood "+prefix, 51.5, -0.1)

	status, _ := patchMessageAsMod(t, modToken, map[string]interface{}{
		"id": msg, "textbody": "Logs, dry.",
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, "Logs, dry.", messageTextbody(msg))
	assert.Equal(t, int64(0), replyTaskCount(msg), "an edit without replybody messages nobody")
}

func TestEditAndMessageWithAnEmptyMessageChangesNothing(t *testing.T) {
	prefix := uniquePrefix("editmsgempty")
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, mod)
	msg := CreateTestMessage(t, poster, "OFFER: Knives "+prefix, 51.5, -0.1)
	before := messageTextbody(msg)

	status, _ := patchMessageAsMod(t, modToken, map[string]interface{}{
		"id": msg, "textbody": "Changed.",
		"replysubject": "Re: OFFER", "replybody": "  \n ",
	})
	assert.Equal(t, 400, status)
	assert.Equal(t, before, messageTextbody(msg))
	assert.Equal(t, int64(0), replyTaskCount(msg))
}

func TestEditAndMessageByTheOwnerWhoIsNotAModIsRefused(t *testing.T) {
	prefix := uniquePrefix("editmsgowner")
	poster := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	_, posterToken := CreateTestSession(t, poster)
	msg := CreateTestMessage(t, poster, "OFFER: Knives "+prefix, 51.5, -0.1)
	before := messageTextbody(msg)

	status, _ := patchMessageAsMod(t, posterToken, map[string]interface{}{
		"id": msg, "textbody": "Changed.", "replysubject": "Hi", "replybody": "Hello me.",
	})
	assert.Equal(t, 403, status)
	assert.Equal(t, before, messageTextbody(msg))
	assert.Equal(t, int64(0), replyTaskCount(msg))
}

func TestPartnerCannotUseEditAndMessage(t *testing.T) {
	prefix := uniquePrefix("editmsgpartner")
	db := database.DBConn
	msg, _, key, tnuserid := setupTnPatchLocationMessage(t, prefix)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")
	before := messageTextbody(msg)

	b, _ := json.Marshal(map[string]interface{}{
		"id": msg, "textbody": "Changed.", "replysubject": "Hi", "replybody": "From the partner.",
	})
	url := fmt.Sprintf("/api/message?partner=%s&tnuserid=%d&email=%s@tn.com", key, tnuserid, prefix+"_owner")
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)

	assert.Equal(t, 403, resp.StatusCode)
	assert.Equal(t, before, messageTextbody(msg))
	assert.Equal(t, int64(0), replyTaskCount(msg))
}
