package test

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/firstreply"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"gorm.io/gorm"
)

// A reply to an OFFER of a child car seat is held while Freegle asks the replier to check the seat
// and confirm. See chat/replyconfirm.go.

// ensureFreegleUser returns the Freegle account the API resolves by its well-known address,
// creating it if this database has never had one.
func ensureFreegleUser(t *testing.T) uint64 {
	db := database.DBConn

	var id uint64
	db.Raw("SELECT userid FROM users_emails WHERE email = 'freegle@ilovefreegle.org' LIMIT 1").Scan(&id)
	if id > 0 {
		return id
	}

	id = CreateTestUser(t, uniquePrefix("freeglesys"), "User")
	db.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, 'freegle@ilovefreegle.org', 1)", id)

	// The API believes a lookup that found no account for a minute, and an earlier test may have made one.
	firstreply.FlushSystemUserCache()

	return id
}

func ensureReplyConfirmColumns(t *testing.T) {
	ensureFirstReplyTables(t)
	addColumnIfMissing("chat_prompts", "userid", "BIGINT UNSIGNED NULL")
	addColumnIfMissing("chat_prompts", "heldchatmsgid", "BIGINT UNSIGNED NULL")
	addColumnIfMissing("chat_prompts", "answered_via", "VARCHAR(16) NULL")
}

type replyConfirmFixture struct {
	groupID      uint64
	posterID     uint64
	replierID    uint64
	msgID        uint64
	chatID       uint64
	posterToken  string
	replierToken string
}

// newReplyConfirmFixture makes a poster with an OFFER of a child car seat, a replier and their chat, so a reply
// is asked about. newOfferFixture makes one with any subject.
func newReplyConfirmFixture(t *testing.T, name string) replyConfirmFixture {
	return newOfferFixture(t, name, "OFFER: child car seat")
}

// newOfferFixture is newReplyConfirmFixture for an offer with this subject.
func newOfferFixture(t *testing.T, name string, subject string) replyConfirmFixture {
	ensureReplyConfirmColumns(t)
	ensureFreegleUser(t)

	prefix := uniquePrefix(name)
	groupID := CreateTestGroup(t, prefix)
	f := replyConfirmFixture{groupID: groupID}
	f.posterID = CreateTestUser(t, prefix+"_poster", "User")
	f.replierID = CreateTestUser(t, prefix+"_replier", "User")
	f.msgID = CreateTestMessage(t, f.posterID, groupID, subject+" ("+prefix+")", 51.5, -0.1)
	f.chatID = CreateTestChatRoom(t, f.replierID, &f.posterID, nil, "User2User")
	_, f.posterToken = CreateTestSession(t, f.posterID)
	_, f.replierToken = CreateTestSession(t, f.replierID)

	return f
}

func (f replyConfirmFixture) reply(t *testing.T, text string, refmsgid bool) uint64 {
	if refmsgid {
		return f.replyAbout(t, f.msgID, text)
	}

	return f.replyAbout(t, 0, text)
}

// replyAbout writes in the chat as the replier, naming a post unless msgID is 0.
func (f replyConfirmFixture) replyAbout(t *testing.T, msgID uint64, text string) uint64 {
	body := map[string]interface{}{"message": text}
	if msgID != 0 {
		body["refmsgid"] = msgID
	}

	resp := postChatmessages(t, fmt.Sprintf("/api/chat/%d/message", f.chatID), body, f.replierToken)
	assert.Equal(t, 200, resp.StatusCode)

	var ret struct {
		Id uint64 `json:"id"`
	}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&ret))

	return ret.Id
}

type confirmPromptRow struct {
	Chatmsgid     uint64
	Kind          string
	Userid        *uint64
	Heldchatmsgid *uint64
	Answer        *string
	Message       string
	Processing    int `gorm:"column:processingsuccessful"`
}

func (f replyConfirmFixture) prompt(t *testing.T) *confirmPromptRow {
	var rows []confirmPromptRow
	database.DBConn.Raw("SELECT cp.chatmsgid, cp.kind, cp.userid, cp.heldchatmsgid, cp.answer, cm.message, "+
		"cm.processingsuccessful FROM chat_prompts cp INNER JOIN chat_messages cm ON cm.id = cp.chatmsgid "+
		"WHERE cm.chatid = ? AND cp.kind = 'confirm_reply'", f.chatID).Scan(&rows)

	if len(rows) == 0 {
		return nil
	}

	assert.Len(t, rows, 1, "one question per reply, however many messages follow")
	return &rows[0]
}

func (f replyConfirmFixture) messageTypes(t *testing.T, token string) []string {
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/chat/%d/message?jwt=%s", f.chatID, token), nil), -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var msgs []struct {
		Type   string                 `json:"type"`
		Prompt map[string]interface{} `json:"prompt"`
	}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&msgs))

	types := []string{}
	for _, m := range msgs {
		if m.Type == "Prompt" {
			if assert.NotNil(t, m.Prompt, "the buttons and answer, not just the text") {
				assert.NotEmpty(t, m.Prompt["askedname"], "so the other member reads who answered")
			}
		}
		types = append(types, m.Type)
	}

	return types
}

func (f replyConfirmFixture) answer(t *testing.T, promptID uint64, token string, answer string) int {
	req := httptest.NewRequest("POST",
		fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", f.chatID, promptID, token),
		strings.NewReader(`{"answer":"`+answer+`"}`))
	req.Header.Set("Content-Type", "application/json")

	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)

	return resp.StatusCode
}

// promptRecord is how a question was answered: "button", the only way. Empty while it is unanswered.
func promptRecord(promptID uint64) string {
	var row struct {
		AnsweredVia *string `gorm:"column:answered_via"`
	}
	database.DBConn.Table("chat_prompts").Select("answered_via").Where("chatmsgid = ?", promptID).Scan(&row)
	if row.AnsweredVia != nil {
		return *row.AnsweredVia
	}
	return ""
}

func processingState(msgID uint64) (int, int) {
	var row struct {
		Processingrequired   int
		Processingsuccessful int
	}
	database.DBConn.Raw("SELECT processingrequired, processingsuccessful FROM chat_messages WHERE id = ?", msgID).Scan(&row)
	return row.Processingrequired, row.Processingsuccessful
}

func TestReplyConfirm_AnOfferThatHasGoneIsNotAskedAbout(t *testing.T) {
	// A reply to an offer already taken, withdrawn or expired cannot be asked about: if it were held, nothing would be
	// left to drop it when the offer's outcome had already been dealt with.
	f := newReplyConfirmFixture(t, "rcgone")
	database.DBConn.Exec("INSERT INTO messages_outcomes (msgid, outcome, timestamp) VALUES (?, 'Taken', NOW())", f.msgID)
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", f.msgID) })

	f.reply(t, "Yes please", true)

	assert.Nil(t, f.prompt(t), "an offer that has gone has nothing to ask about")
}

func TestReplyConfirm_OnlyTheReplierSeesTheQuestion(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcvisible")
	f.reply(t, "Is it still available?", true)

	assert.Contains(t, f.messageTypes(t, f.replierToken), "Prompt")
	assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt", "the poster must never see the question")
}

func TestReplyConfirm_OnlyTheReplierCanAnswer(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcanswerer")
	f.reply(t, "Is it still available?", true)

	p := f.prompt(t)
	if assert.NotNil(t, p) {
		assert.Equal(t, 403, f.answer(t, p.Chatmsgid, f.posterToken, "yes"))
	}
}

func TestReplyConfirm_YesLetsTheReplyThrough(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcyes")
	replyID := f.reply(t, "Is it still available?", true)

	p := f.prompt(t)
	if assert.NotNil(t, p) {
		assert.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "yes"))
		assert.Equal(t, "yes", *f.prompt(t).Answer)
		assert.Equal(t, "button", promptRecord(p.Chatmsgid))
	}

	// Still for chats:process-incoming to deliver, now that nothing holds it.
	required, _ := processingState(replyID)
	assert.Equal(t, 1, required)

	// The poster sees the question when the reply arrives, not before it:
	// ChatProcessService reveals both together.
	assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt")
}

func TestReplyConfirm_NoDropsTheReply(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcno")
	replyID := f.reply(t, "Is it still available?", true)

	p := f.prompt(t)
	if assert.NotNil(t, p) {
		assert.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "no"))
	}

	required, successful := processingState(replyID)
	assert.Equal(t, 0, required)
	assert.Equal(t, 0, successful, "the poster never receives a reply the replier withdrew")

	var reason string
	database.DBConn.Table("chat_messages").Select("processingfailreason").Where("id = ?", replyID).Scan(&reason)
	assert.Equal(t, "ReplyWithdrawn", reason, "so a moderator can see why it never arrived")
	assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt", "after a no the poster sees nothing")
}

func TestReplyConfirm_WhatTheReplierTypesIsNeverTakenForAYes(t *testing.T) {
	for _, text := range []string{"no thanks, sorry", "yes please", "Is the damage bad?"} {
		t.Run(text, func(t *testing.T) {
			f := newReplyConfirmFixture(t, "rctyped")
			replyID := f.reply(t, "Is it still available?", true)
			laterID := f.reply(t, text, false)

			p := f.prompt(t)
			if assert.NotNil(t, p) {
				assert.Nil(t, p.Answer, "only the buttons answer the question")
				assert.Equal(t, "", promptRecord(p.Chatmsgid))
			}

			// Both wait for the answer, and neither is shown to the poster.
			required, _ := processingState(replyID)
			assert.Equal(t, 1, required, "the reply is still held")
			required, _ = processingState(laterID)
			assert.Equal(t, 1, required, "and the message after it waits behind the same question")
			assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt")
		})
	}
}

func TestReplyConfirm_ANoWithdrawsTheReplyAndWhatTheReplierWroteAfterIt(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcnoall")
	replyID := f.reply(t, "Is it still available?", true)
	laterID := f.reply(t, "no thanks, sorry", false)
	p := f.prompt(t)
	require.NotNil(t, p)

	assert.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "no"))

	for _, id := range []uint64{replyID, laterID} {
		required, successful := processingState(id)
		assert.Equal(t, 0, required, "no longer waiting")
		assert.Equal(t, 0, successful, "and never delivered to the poster")

		var reason string
		database.DBConn.Table("chat_messages").Select("processingfailreason").Where("id = ?", id).Scan(&reason)
		assert.Equal(t, "ReplyWithdrawn", reason)
	}
}

func TestReplyConfirm_ANoLeavesOtherChatsAndLaterMessagesAlone(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcnoother")
	otherPoster := CreateTestUser(t, uniquePrefix("rcnoother_op"), "User")
	otherChat := CreateTestChatRoom(t, f.replierID, &otherPoster, nil, "User2User")

	replyID := f.reply(t, "Is it still available?", true)

	// A message of theirs in another chat has nothing to do with the question.
	resp := postChatmessages(t, fmt.Sprintf("/api/chat/%d/message", otherChat), map[string]interface{}{"message": "Hello"}, f.replierToken)
	require.Equal(t, 200, resp.StatusCode)
	var other struct {
		Id uint64 `json:"id"`
	}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&other))

	p := f.prompt(t)
	require.NotNil(t, p)
	assert.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "no"))

	required, successful := processingState(replyID)
	assert.Equal(t, 0, required)
	assert.Equal(t, 0, successful)

	required, _ = processingState(other.Id)
	assert.Equal(t, 1, required, "a message in another chat is still for the batch to deliver")

	// Written after the answer, it is nobody's to withdraw.
	afterID := f.reply(t, "Sorry, changed my mind", false)
	required, _ = processingState(afterID)
	assert.Equal(t, 1, required)
}

func TestReplyConfirm_ASecondAnswerIsRefusedAndChangesNothing(t *testing.T) {
	f := newReplyConfirmFixture(t, "rctwice")
	replyID := f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)

	assert.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "no"))
	assert.Equal(t, 409, f.answer(t, p.Chatmsgid, f.replierToken, "yes"), "a yes cannot undo a no")

	assert.Equal(t, "no", *f.prompt(t).Answer)
	required, successful := processingState(replyID)
	assert.Equal(t, 0, required)
	assert.Equal(t, 0, successful)
}

func TestReplyConfirm_AnAnswerRecordedByAnotherTapIsNotAppliedAgain(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcclaim")
	replyID := f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)

	// A yes lands between this tap reading the question and writing its answer. The no finds the question answered and
	// does nothing, so it does not withdraw the reply that the yes released.
	fired := false
	beforeStatement(t, "chat_prompts", true, func(tx *gorm.DB) {
		if !fired {
			fired = true
			database.DBConn.Exec("UPDATE chat_prompts SET answer = 'yes', answered_at = NOW(), answered_via = 'button' WHERE chatmsgid = ?", p.Chatmsgid)
		}
	})
	assert.Equal(t, 409, f.answer(t, p.Chatmsgid, f.replierToken, "no"))
	assert.True(t, fired)

	required, _ := processingState(replyID)
	assert.Equal(t, 1, required, "the reply is not withdrawn by an answer that was not recorded")
	assert.Equal(t, "yes", *f.prompt(t).Answer)
}

// pushesQueuedFor counts the push tasks queued for one chat message.
func pushesQueuedFor(msgID uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'push_notify_chat_message' AND "+
		"CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.message_id')) AS UNSIGNED) = ?", msgID).Scan(&n)

	return n
}

func TestReplyConfirm_TheQuestionCountsAsUnseenForTheReplierAndNotThePoster(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcunseen")
	f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)

	// The question is for the replier: it is unseen until they open the chat, so a replier who has left it is told.
	replierRoom, _ := listRoomForUser(t, f.replierToken, f.chatID)
	if assert.NotNil(t, replierRoom) {
		assert.Equal(t, uint64(1), replierRoom.Unseen, "the question the replier has not read")
	}

	// The poster has been shown nothing, and has nothing unseen.
	posterRoom, _ := listRoomForUser(t, f.posterToken, f.chatID)
	if posterRoom != nil {
		assert.Equal(t, uint64(0), posterRoom.Unseen, "a question about a reply the poster has not got is not theirs to count")
	}

	// Answered, it is no longer waiting for them.
	assert.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "no"))
	replierRoom, _ = listRoomForUser(t, f.replierToken, f.chatID)
	if assert.NotNil(t, replierRoom) {
		assert.Equal(t, uint64(0), replierRoom.Unseen)
	}
}

func TestReplyConfirm_AQuestionThatHasRunOutIsNotUnseen(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcunseenexp")
	f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)
	database.DBConn.Exec("UPDATE chat_prompts SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE chatmsgid = ?", p.Chatmsgid)

	replierRoom, _ := listRoomForUser(t, f.replierToken, f.chatID)
	if assert.NotNil(t, replierRoom) {
		assert.Equal(t, uint64(0), replierRoom.Unseen, "nothing to answer, so nothing to read")
	}
}

func TestReplyConfirm_TheQuestionIsPushedAsAnyChatMessageIs(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcpush")
	f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)

	assert.EqualValues(t, 1, pushesQueuedFor(p.Chatmsgid), "the batch sends the push, to the replier only")
}

func TestReplyConfirm_NothingIsPushedForAReplyThatIsNotAskedAbout(t *testing.T) {
	f := newOfferFixture(t, "rcnopush", "OFFER: armchair")
	before := chatMessageCount(f.chatID)
	f.reply(t, "Is it still available?", true)

	assert.EqualValues(t, before+1, chatMessageCount(f.chatID), "the reply and no question")
	var tasks int64
	database.DBConn.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'push_notify_chat_message' AND "+
		"CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.message_id')) AS UNSIGNED) IN (SELECT id FROM chat_messages WHERE chatid = ?)", f.chatID).Scan(&tasks)
	assert.EqualValues(t, 0, tasks, "the batch pushes the reply itself when it delivers it")
}

func TestReplyConfirm_AMergeMovesTheQuestionWithTheReplyItHolds(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcmerge")
	replyID := f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)

	// The replier's account is merged into another. Their messages move to the surviving account; the question has to
	// go with them, or the foreign key deletes it with the old account and the reply it held is delivered unasked.
	prefix := uniquePrefix("rcmerge_s")
	_, adminToken := mergeAdminSetup(t, prefix)
	survivor := CreateTestUser(t, prefix+"_survivor", "User")
	_, survivorToken := CreateTestSession(t, survivor)

	require.Equal(t, 200, mergeUsers(t, adminToken, f.replierID, survivor))

	var owner uint64
	database.DBConn.Raw("SELECT userid FROM chat_prompts WHERE chatmsgid = ?", p.Chatmsgid).Scan(&owner)
	assert.Equal(t, survivor, owner, "the question is now the surviving account's")

	var held struct {
		Userid   uint64
		Required int `gorm:"column:processingrequired"`
	}
	database.DBConn.Raw("SELECT userid, processingrequired FROM chat_messages WHERE id = ?", replyID).Scan(&held)
	assert.Equal(t, survivor, held.Userid)
	assert.Equal(t, 1, held.Required, "and the reply it holds is still held")
	assert.EqualValues(t, 1, promptsHolding(replyID), "by a question that still exists")

	// The surviving account answers it, and nobody else can.
	assert.Equal(t, 403, f.answer(t, p.Chatmsgid, f.posterToken, "yes"))
	assert.Equal(t, 200, f.answer(t, p.Chatmsgid, survivorToken, "no"))
	required, successful := processingState(replyID)
	assert.Equal(t, 0, required)
	assert.Equal(t, 0, successful, "withdrawn by the surviving account's no")
}

func TestReplyConfirm_EveryCountOfRepliesLeavesOutAHeldOrRefusedReply(t *testing.T) {
	// utils.ReplyHeldByQuestionSQL is the gate the My Posts list, the Browse reply counts, and the batch's digest and
	// daily stats share. A reply is counted while it is not held, and not after a no, an expiry or the post going.
	counted := func(f replyConfirmFixture) int64 {
		var n int64
		database.DBConn.Raw("SELECT COUNT(*) FROM chat_messages cm WHERE cm.chatid = ? AND NOT "+utils.ReplyHeldByQuestionSQL("cm"), f.chatID).Scan(&n)

		return n
	}
	held := func() *replyConfirmFixture {
		f := newReplyConfirmFixture(t, "rccount")

		return &f
	}

	t.Run("WhileTheQuestionIsOpen", func(t *testing.T) {
		f := held()
		f.reply(t, "Is it still available?", true)

		// The reply and what the replier writes next are held; the question is Freegle's own message and is not a reply.
		f.reply(t, "I can come tonight", false)
		assert.EqualValues(t, 1, counted(*f), "only the question is left, which is not the replier's")
	})

	t.Run("AfterANoAndAfterTheQuestionIsOver", func(t *testing.T) {
		f := held()
		f.reply(t, "Is it still available?", true)
		f.reply(t, "I can come tonight", false)
		assert.Equal(t, 200, f.answer(t, f.prompt(t).Chatmsgid, f.replierToken, "no"))
		assert.EqualValues(t, 1, counted(*f), "withdrawn, so not counted")

		// Written after the no, a message is an ordinary one.
		later := f.reply(t, "Sorry, changed my mind", false)
		database.DBConn.Exec("UPDATE chat_messages SET date = NOW() + INTERVAL 1 MINUTE WHERE id = ?", later)
		assert.EqualValues(t, 2, counted(*f), "and is counted with the question")
	})

	t.Run("AfterAYes", func(t *testing.T) {
		f := held()
		f.reply(t, "Is it still available?", true)
		assert.Equal(t, 200, f.answer(t, f.prompt(t).Chatmsgid, f.replierToken, "yes"))
		assert.EqualValues(t, 2, counted(*f), "released, so the reply is counted")
	})

	t.Run("WhenThePostWentFirst", func(t *testing.T) {
		f := held()
		f.reply(t, "Is it still available?", true)
		database.DBConn.Exec("UPDATE chat_prompts SET answer = 'expired', answered_at = NOW() WHERE kind = 'confirm_reply' AND userid = ?", f.replierID)
		assert.EqualValues(t, 1, counted(*f), "dropped, so not counted")
	})

	t.Run("InAnotherChat", func(t *testing.T) {
		f := held()
		f.reply(t, "Is it still available?", true)
		otherPoster := CreateTestUser(t, uniquePrefix("rccount_op"), "User")
		otherChat := CreateTestChatRoom(t, f.replierID, &otherPoster, nil, "User2User")
		resp := postChatmessages(t, fmt.Sprintf("/api/chat/%d/message", otherChat), map[string]interface{}{"message": "Hello"}, f.replierToken)
		require.Equal(t, 200, resp.StatusCode)

		var n int64
		database.DBConn.Raw("SELECT COUNT(*) FROM chat_messages cm WHERE cm.chatid = ? AND NOT "+utils.ReplyHeldByQuestionSQL("cm"), otherChat).Scan(&n)
		assert.EqualValues(t, 1, n, "a message in another chat is not held by this question")
	})
}

// promptsHolding is how many questions name any of these replies as the one they hold.
func promptsHolding(replyIDs ...uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM chat_prompts WHERE heldchatmsgid IN ?", replyIDs).Scan(&n)

	return n
}

func chatMessageCount(chatID uint64) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM chat_messages WHERE chatid = ?", chatID).Scan(&n)

	return n
}

// replyConfirmComparable is a chat message as stored, without the columns that differ between any two
// messages: its own id, who and where it is, which post it names, and when it was written.
func replyConfirmComparable(id uint64) map[string]interface{} {
	var row map[string]interface{}
	database.DBConn.Raw("SELECT * FROM chat_messages WHERE id = ?", id).Scan(&row)
	for _, k := range []string{"id", "chatid", "userid", "refmsgid", "date"} {
		delete(row, k)
	}

	return row
}

// posterView is the reply as the poster's chat fetch returns it, without the fields that differ between
// any two messages.
func (f replyConfirmFixture) posterView(t *testing.T, replyID uint64) map[string]interface{} {
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/chat/%d/message?jwt=%s", f.chatID, f.posterToken), nil), -1)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var msgs []map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&msgs))

	for _, m := range msgs {
		if id, ok := m["id"].(float64); ok && uint64(id) == replyID {
			for _, k := range []string{"id", "chatid", "userid", "refmsgid", "date"} {
				delete(m, k)
			}

			return m
		}
	}

	t.Fatalf("the poster's fetch does not hold reply %d", replyID)

	return nil
}

func TestReplyConfirm_ARippleHeldReplyIsNotAskedAbout(t *testing.T) {
	db := database.DBConn
	f := newReplyConfirmFixture(t, "rcripple")
	CreateTestMembership(t, f.posterID, f.groupID, "Member")
	CreateTestMembership(t, f.replierID, f.groupID, "Member")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, f.replierID)

	// A reach that exists and does not cover the replier, far to the east.
	const outside = "POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))"
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound) VALUES (?, 51.5, -0.1, ?, "+
		"ST_Envelope(ST_GeomFromText('"+outside+"', 3857)))", f.msgID, mustRasterize(t, outside))
	t.Cleanup(func() {
		db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", f.msgID)
		db.Exec("DELETE FROM rippling_held_replies WHERE msgid = ?", f.msgID)
	})
	stubReachEvalMax(t, "out")

	replyID := f.reply(t, "I'd like this please", true)

	var held int64
	db.Raw("SELECT COUNT(*) FROM rippling_held_replies WHERE chatmsgid = ? AND status = 'held'", replyID).Scan(&held)
	assert.EqualValues(t, 1, held, "rippling holds the reply")
	assert.Nil(t, f.prompt(t), "so it is not asked about as well: the poster would see the question before the reply")
	assert.EqualValues(t, 0, promptsHolding(replyID))
}

func TestReplyConfirm_ASecondReplyToTheSamePostWaitsBehindTheFirst(t *testing.T) {
	for _, c := range []struct{ name, text string }{
		{"TheSameTextTwice", "Is it still available?"},
		{"OtherText", "I can come tonight"},
	} {
		t.Run(c.name, func(t *testing.T) {
			f := newReplyConfirmFixture(t, "rcsecond")

			first := f.reply(t, "Is it still available?", true)
			f.reply(t, c.text, true)

			p := f.prompt(t) // asserts there is only one question
			if assert.NotNil(t, p) {
				assert.Nil(t, p.Answer, "the further message does not answer the question")
			}
			var unanswered int64
			database.DBConn.Raw("SELECT COUNT(*) FROM chat_prompts WHERE heldchatmsgid = ? AND answered_at IS NULL", first).Scan(&unanswered)
			assert.EqualValues(t, 1, unanswered, "so the first reply is still held, and the second waits behind it")
		})
	}
}

// ----------------------------------------------------------------------------- two things the hold must leave alone

func TestReplyConfirm_MyPostsDoesNotListAHeldReply(t *testing.T) {
	// listed is what the poster's My Posts shows for the post: the count and the list of replies.
	listed := func(t *testing.T, f replyConfirmFixture) (int, int) {
		m := fetchMessage(t, f.posterToken, f.msgID)

		return m.Replycount, len(m.MessageReply)
	}
	reply := func(t *testing.T, subject string) (replyConfirmFixture, uint64) {
		f := newOfferFixture(t, "rcmyposts", subject)
		CreateTestMembership(t, f.posterID, f.groupID, "Member")
		CreateTestMembership(t, f.replierID, f.groupID, "Member")

		return f, f.reply(t, "Is it still available?", true)
	}

	t.Run("WhileTheQuestionIsOpen", func(t *testing.T) {
		f, _ := reply(t, "OFFER: child car seat")

		count, replies := listed(t, f)
		assert.Equal(t, 0, count, "a reply that waits for its sender's answer is not counted for the poster")
		assert.Equal(t, 0, replies, "and its sender is not listed")
	})

	t.Run("AfterANo", func(t *testing.T) {
		f, _ := reply(t, "OFFER: child car seat")
		assert.Equal(t, 200, f.answer(t, f.prompt(t).Chatmsgid, f.replierToken, "no"))

		count, replies := listed(t, f)
		assert.Equal(t, 0, count, "a reply the replier withdrew never reaches the poster")
		assert.Equal(t, 0, replies)
	})

	t.Run("AfterAYes", func(t *testing.T) {
		f, _ := reply(t, "OFFER: child car seat")
		assert.Equal(t, 200, f.answer(t, f.prompt(t).Chatmsgid, f.replierToken, "yes"))

		count, replies := listed(t, f)
		assert.Equal(t, 1, count, "listed, as any reply is that the batch has yet to deliver")
		assert.Equal(t, 1, replies)
	})

	t.Run("AReplyNotAskedAboutIsListedAtOnce", func(t *testing.T) {
		f, _ := reply(t, "OFFER: armchair")

		count, replies := listed(t, f)
		assert.Equal(t, 1, count)
		assert.Equal(t, 1, replies)
	})
}

func TestReplyConfirm_AnsweringIsNotCountedAsAFreegleChatPromptAnswer(t *testing.T) {
	promptAnswered := func() int64 {
		var n int64
		database.DBConn.Raw("SELECT COALESCE(SUM(count), 0) FROM firstreply_event_metrics WHERE day = CURDATE() AND event = 'prompt_answered'").Scan(&n)

		return n
	}

	// A reply confirmation is asked of a replier in a chat between two members. It is not the Freegle chat's measure.
	f := newReplyConfirmFixture(t, "rcmetric")
	f.reply(t, "Is it still available?", true)
	before := promptAnswered()
	assert.Equal(t, 200, f.answer(t, f.prompt(t).Chatmsgid, f.replierToken, "yes"))
	assert.Equal(t, before, promptAnswered(), "answering a reply confirmation leaves the Freegle chat's count alone")

	// A prompt of another kind is a poster answering Freegle's question in their own chat, and it counts.
	prefix := uniquePrefix("rcmetricdelivery")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	freegleID := CreateTestUser(t, prefix+"_freegle", "User")
	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: metric test", 51.5, -0.1)
	chatID := CreateTestChatRoom(t, freegleID, &posterID, nil, "User2User")
	chatMsgID := seedPrompt(t, chatID, freegleID, msgID, "delivery", deliveryOptions)
	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM chat_prompts WHERE chatmsgid = ?", chatMsgID)
		database.DBConn.Exec("DELETE FROM chat_messages WHERE chatid = ?", chatID)
	})
	_, token := CreateTestSession(t, posterID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", chatID, chatMsgID, token),
		strings.NewReader(`{"answer":"maybe"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	assert.Equal(t, before+1, promptAnswered(), "a delivery answer is counted, so the first assertion is not true of everything")
}

// ----------------------------------------------------------------------------- faults found in review

// beforeStatement runs check just before the Go API runs an insert (or an update, with update true) on table, and
// takes it away when the test ends. Another test running at the same time would see it, so a test that uses it must not
// run in parallel with anything.
func beforeStatement(t *testing.T, table string, update bool, check func(tx *gorm.DB)) {
	t.Helper()
	name := fmt.Sprintf("replyconfirm:test:%s:%v:%s", table, update, t.Name())
	db := database.DBConn
	run := func(tx *gorm.DB) {
		if tx.Statement.Table == table {
			check(tx)
		}
	}

	if update {
		require.NoError(t, db.Callback().Update().Before("gorm:update").Register(name, run))
		t.Cleanup(func() { _ = db.Callback().Update().Remove(name) })

		return
	}

	require.NoError(t, db.Callback().Create().Before("gorm:create").Register(name, run))
	t.Cleanup(func() { _ = db.Callback().Create().Remove(name) })
}

// systemUserLookups counts the queries that look the Freegle account up by this address, so a test can say how often
// the API goes to the database for it. Queries are told apart by the address they bind, so nothing else is counted.
func systemUserLookups(t *testing.T, email string) func() int {
	t.Helper()
	var n atomic.Int64
	count := func(tx *gorm.DB) {
		for _, v := range tx.Statement.Vars {
			if s, ok := v.(string); ok && s == email {
				n.Add(1)
			}
		}
	}
	name := "replyconfirm:test:lookups:" + t.Name()
	db := database.DBConn
	require.NoError(t, db.Callback().Query().After("gorm:query").Register(name+":query", count))
	require.NoError(t, db.Callback().Row().After("gorm:row").Register(name+":row", count))
	t.Cleanup(func() {
		_ = db.Callback().Query().Remove(name + ":query")
		_ = db.Callback().Row().Remove(name + ":row")
	})

	return func() int { return int(n.Load()) }
}

// askedName is the name the other member reads on the answer, as the chat fetch returns it for the question.
func (f replyConfirmFixture) askedName(t *testing.T, token string) string {
	t.Helper()
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/chat/%d/message?jwt=%s", f.chatID, token), nil), -1)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var msgs []struct {
		Type   string                 `json:"type"`
		Prompt map[string]interface{} `json:"prompt"`
	}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&msgs))

	for _, m := range msgs {
		if m.Type == "Prompt" && m.Prompt != nil {
			name, _ := m.Prompt["askedname"].(string)

			return name
		}
	}

	t.Fatal("the chat holds no question")

	return ""
}

func TestReplyConfirm_TheQuestionNamesTheReplierAsOtherMembersSeeThem(t *testing.T) {
	// users.fullname is whatever the member typed, up to 255 characters, on any number of lines. The card is written by
	// the Freegle account and drawn under its logo, and is not content checked, so it shows the name the API shows
	// everywhere else: cut at an at sign and at 32 characters, on one line.
	cases := []struct{ name, fullname, want string }{
		{"Ordinary", "Sam Jones", "Sam Jones"},
		{"AnAddressIsCutAtTheAtSign", "bob.smith@example.com", "bob.smith"},
		{"ALongTextIsCutOnOneLine", "Collection: ring 07700 900123 or\nvisit https://example.com/offer today", "Collection: ring 07700 900123 or..."},
		{"ControlCharactersBecomeSpaces", "Sam\r\n\tJones‮", "Sam Jones"},
		{"ABlankNameIsAFreegler", "\n\n", "A freegler"},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			f := newReplyConfirmFixture(t, "rcname")
			database.DBConn.Exec("UPDATE users SET fullname = ? WHERE id = ?", c.fullname, f.replierID)

			f.reply(t, "Is it still available?", true)

			p := f.prompt(t)
			if assert.NotNil(t, p) {
				assert.True(t, strings.HasPrefix(p.Message, "@"+c.want+", before we pass your reply on: "),
					"the card names them as everyone else sees them: %q", p.Message)
				assert.NotContains(t, p.Message, "\n", "on one line")
				assert.NotContains(t, p.Message, "http", "no link of their own")
			}
			assert.Equal(t, c.want, f.askedName(t, f.replierToken), "and so does the name the other member reads on the answer")
		})
	}
}

func TestReplyConfirm_AReplyInAChatWithSomeoneWhoDidNotPostIsNotAskedAbout(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcelsewhere")
	other := CreateTestUser(t, uniquePrefix("rcelsewhere")+"_other", "User")
	g := f
	g.chatID = CreateTestChatRoom(t, f.replierID, &other, nil, "User2User")

	g.reply(t, "Is it still available?", true)

	assert.Nil(t, g.prompt(t), "a member who did not post the offer is not shown a question about it")
}

func TestReplyConfirm_ALookupThatFindsNoFreegleAccountIsNotRepeatedForAMinute(t *testing.T) {
	absent := fmt.Sprintf("no-freegle-account-%d@example.invalid", time.Now().UnixNano())
	t.Setenv("FIRSTREPLY_SYSTEM_USER_EMAIL", absent)
	firstreply.FlushSystemUserCache()
	t.Cleanup(firstreply.FlushSystemUserCache)
	lookups := systemUserLookups(t, absent)

	for i := 0; i < 5; i++ {
		assert.EqualValues(t, 0, firstreply.SystemUserID(database.DBConn))
	}
	assert.Equal(t, 1, lookups(), "five calls with no account to find are one query, not five")

	// An account made later is found once the minute has passed, with no restart, and a real id is kept for good.
	previous := firstreply.SetSystemUserMissCache(20 * time.Millisecond)
	t.Cleanup(func() { firstreply.SetSystemUserMissCache(previous) })
	firstreply.FlushSystemUserCache()
	assert.EqualValues(t, 0, firstreply.SystemUserID(database.DBConn))
	id := CreateTestUser(t, uniquePrefix("rcsystem"), "User")
	database.DBConn.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, ?, 1)", id, absent)
	time.Sleep(60 * time.Millisecond)
	before := lookups()
	assert.EqualValues(t, id, firstreply.SystemUserID(database.DBConn), "picked up without a restart")
	assert.Equal(t, before+1, lookups())
	assert.EqualValues(t, id, firstreply.SystemUserID(database.DBConn))
	assert.Equal(t, before+1, lookups(), "and then kept: no further query")
}

func TestReplyConfirm_ListingChatsLooksTheFreegleAccountUpOnceWhenThereIsNone(t *testing.T) {
	absent := fmt.Sprintf("no-freegle-account-%d@example.invalid", time.Now().UnixNano())
	t.Setenv("FIRSTREPLY_SYSTEM_USER_EMAIL", absent)
	firstreply.FlushSystemUserCache()
	t.Cleanup(firstreply.FlushSystemUserCache)

	prefix := uniquePrefix("rclist")
	me := CreateTestUser(t, prefix+"_me", "User")
	for i := 0; i < 4; i++ {
		other := CreateTestUser(t, fmt.Sprintf("%s_other%d", prefix, i), "User")
		chatID := CreateTestChatRoom(t, me, &other, nil, "User2User")
		CreateTestChatMessage(t, chatID, other, "Hello")
	}
	_, token := CreateTestSession(t, me)
	lookups := systemUserLookups(t, absent)

	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/chat?jwt="+token, nil), -1)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var chats []map[string]interface{}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&chats))

	assert.GreaterOrEqual(t, len(chats), 4, "the list has the chats, so the loop that used to look the account up for each ran")
	assert.Equal(t, 1, lookups(), "one lookup for the whole list, whatever the number of chats")
}

func TestReplyConfirm_AWithdrawalThatFailsIsNotRecordedAsTheAnswer(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcnofail")
	replyID := f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)

	// A deadlock or a lock wait timeout on the update that withdraws the reply, which any error stands in for.
	failing := true
	beforeStatement(t, "chat_messages", true, func(tx *gorm.DB) {
		if failing {
			_ = tx.AddError(errors.New("injected: deadlock found when trying to get lock"))
		}
	})

	assert.Equal(t, 500, f.answer(t, p.Chatmsgid, f.replierToken, "no"), "the member is told it did not work, and may tap again")
	assert.Nil(t, f.prompt(t).Answer, "the no is not recorded, or the next run would deliver the reply they refused")
	assert.Equal(t, "", promptRecord(p.Chatmsgid))
	required, successful := processingState(replyID)
	assert.Equal(t, 1, required, "the reply is still held")
	assert.Equal(t, 0, successful)

	// Tapping again, once the database answers, withdraws the reply and records the no.
	failing = false
	assert.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "no"))
	assert.Equal(t, "no", *f.prompt(t).Answer)
	required, successful = processingState(replyID)
	assert.Equal(t, 0, required)
	assert.Equal(t, 0, successful, "withdrawn")
}

func TestReplyConfirm_ANoAfterTheReplyIsAlreadyProcessedIsRecordedAndNotApplied(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcprocessed")
	replyID := f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)
	database.DBConn.Exec("UPDATE chat_messages SET processingrequired = 0, processingsuccessful = 1 WHERE id = ?", replyID)

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message/%d/prompt?jwt=%s", f.chatID, p.Chatmsgid, f.replierToken),
		strings.NewReader(`{"answer":"no"}`))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var ret struct {
		Applied bool `json:"applied"`
	}
	require.NoError(t, json.NewDecoder(resp.Body).Decode(&ret))

	assert.False(t, ret.Applied, "there was nothing left to withdraw")
	assert.Equal(t, "no", *f.prompt(t).Answer, "but the answer is what the member said")
}

func TestReplyConfirm_NoReplyWaitsForProcessingBeforeItsHoldIsWritten(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcatomic")

	// chats:process-incoming takes every committed reply that is unprocessed, whatever its age. So the reply must not
	// be committed before the row that holds it: a run between the two would deliver a reply that its sender is then
	// asked about, and a no that finds it already gone.
	checks := 0
	takeable := []uint64{}
	beforeStatement(t, "chat_prompts", false, func(tx *gorm.DB) {
		checks++
		var ids []uint64
		// Another connection of the pool, so it sees only what has been committed.
		database.DBConn.Raw("SELECT id FROM chat_messages WHERE chatid = ? AND processingrequired = 1", f.chatID).Scan(&ids)
		takeable = append(takeable, ids...)
	})

	replyID := f.reply(t, "Is it still available?", true)

	assert.Equal(t, 1, checks, "the hold was written, so the check ran")
	assert.Empty(t, takeable, "no reply was committed and ready for the batch before the hold was written")
	assert.EqualValues(t, 1, promptsHolding(replyID))
	required, _ := processingState(replyID)
	assert.Equal(t, 1, required, "and once both are written the reply waits for processing, held")
}

func TestReplyConfirm_ALaterMessageDoesNotAnswerAnExpiredQuestion(t *testing.T) {
	f := newReplyConfirmFixture(t, "rcexpired")
	f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)
	database.DBConn.Exec("UPDATE chat_prompts SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE chatmsgid = ?", p.Chatmsgid)

	assert.Equal(t, 410, f.answer(t, p.Chatmsgid, f.replierToken, "yes"), "an expired question is refused")
	f.reply(t, "Are you still there?", false)

	again := f.prompt(t)
	if assert.NotNil(t, again) {
		assert.Nil(t, again.Answer, "writing again does not answer a question that is over: the batch drops that reply")
	}
	assert.Equal(t, "", promptRecord(p.Chatmsgid))
	var open int64
	database.DBConn.Raw("SELECT COUNT(*) FROM chat_prompts WHERE chatmsgid = ? AND answered_at IS NULL", p.Chatmsgid).Scan(&open)
	assert.EqualValues(t, 1, open, "it has no time of answer")
}

func TestReplyConfirm_ApprovingAHeldReplyShowsItsQuestionAndRejectingItDoesNot(t *testing.T) {
	// What chats:process-incoming leaves for a confirmed reply from a member whose messages are held for review:
	// processed and successful, and held. The poster is shown neither the reply nor the question while it is held.
	setup := func(t *testing.T) (replyConfirmFixture, uint64, uint64, string) {
		f := newReplyConfirmFixture(t, "rcapprove")
		replyID := f.reply(t, "Is it still available?", true)
		p := f.prompt(t)
		require.NotNil(t, p)
		require.Equal(t, 200, f.answer(t, p.Chatmsgid, f.replierToken, "yes"))
		database.DBConn.Exec("UPDATE chat_messages SET processingrequired = 0, processingsuccessful = 1, reviewrequired = 1, "+
			"reportreason = 'Spam' WHERE id = ?", replyID)
		modID := CreateTestUser(t, uniquePrefix("rcmod"), "User")
		CreateTestMembership(t, modID, f.groupID, "Moderator")
		_, modToken := CreateTestSession(t, modID)

		assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt", "held: no question for the poster")
		assert.NotContains(t, f.messageTypes(t, f.posterToken), "Interested", "held: no reply either")

		return f, replyID, p.Chatmsgid, modToken
	}
	questionSuccessful := func(id uint64) int {
		_, successful := processingState(id)

		return successful
	}

	t.Run("Approve", func(t *testing.T) {
		f, replyID, questionID, modToken := setup(t)

		resp := postChatmessages(t, "/api/chatmessages", map[string]interface{}{"id": replyID, "action": "Approve"}, modToken)
		assert.Equal(t, 200, resp.StatusCode)

		assert.Equal(t, 1, questionSuccessful(questionID), "the question goes with the reply a moderator lets through")
		assert.Contains(t, f.messageTypes(t, f.posterToken), "Prompt")
		assert.Contains(t, f.messageTypes(t, f.posterToken), "Interested")
	})

	t.Run("Reject", func(t *testing.T) {
		f, replyID, questionID, modToken := setup(t)

		resp := postChatmessages(t, "/api/chatmessages", map[string]interface{}{"id": replyID, "action": "Reject"}, modToken)
		assert.Equal(t, 200, resp.StatusCode)

		assert.Equal(t, 0, questionSuccessful(questionID), "a rejected reply leaves no question behind")
		assert.NotContains(t, f.messageTypes(t, f.posterToken), "Prompt")
		assert.NotContains(t, f.messageTypes(t, f.posterToken), "Interested")
	})
}

// The words that make an offer a child car seat (Discourse 9939).

// newCarSeatFixture is newReplyConfirmFixture with an offer whose whole subject is this.
func newCarSeatFixture(t *testing.T, name string, subject string) replyConfirmFixture {
	f := newReplyConfirmFixture(t, name)
	database.DBConn.Exec("UPDATE messages SET subject = ? WHERE id = ?", subject, f.msgID)

	return f
}

// carSeatQuestionsAsked counts the reply questions asked in the fixture's chat.
func carSeatQuestionsAsked(f replyConfirmFixture) int64 {
	var n int64
	database.DBConn.Raw("SELECT COUNT(*) FROM chat_prompts cp INNER JOIN chat_messages cm ON cm.id = cp.chatmsgid "+
		"WHERE cm.chatid = ? AND cp.kind = 'confirm_reply'", f.chatID).Scan(&n)

	return n
}

func TestReplyConfirm_ChildCarSeatIsAskedAbout(t *testing.T) {
	f := newCarSeatFixture(t, "rccarseat", "OFFER: Child car seat, group 1 (SW1)")

	replyID := f.reply(t, "Is this still available?", true)

	p := f.prompt(t)
	if assert.NotNil(t, p, "a reply to a child car seat is held for the replier to confirm") {
		assert.Equal(t, f.replierID, *p.Userid)
		assert.Equal(t, replyID, *p.Heldchatmsgid)
		assert.True(t, strings.HasPrefix(p.Message, "@"), "addressed to the replier: %s", p.Message)
		assert.Contains(t, p.Message, "child car seat")
		assert.Contains(t, p.Message, "never been in an accident")
		assert.Contains(t, p.Message, "childcarseats.org.uk")
		assert.Contains(t, p.Message, "Do you still want it?")
		assert.Equal(t, 0, p.Processing, "the poster is not shown it until the reply goes through")
	}
	required, _ := processingState(replyID)
	assert.Equal(t, 1, required, "the reply waits for the answer")
}

func TestReplyConfirm_ChildCarSeatWordsThatCount(t *testing.T) {
	for _, subject := range []string{
		"OFFER: Maxi-Cosi carseat (SW1)",
		"OFFER: Booster seat for 4-12 years (SW1)",
		"OFFER: Isofix base (SW1)",
		"OFFER: Infant carrier and rain cover (SW1)",
	} {
		f := newCarSeatFixture(t, "rccarseatword", subject)
		f.reply(t, "Yes please", true)
		assert.NotNil(t, f.prompt(t), subject)
	}

	// A seat that is not a child car seat is not asked about.
	g := newCarSeatFixture(t, "rcgardenseat", "OFFER: Garden seat (SW1)")
	g.reply(t, "Yes please", true)
	assert.Nil(t, g.prompt(t), "a garden seat is not a car seat")
}

func TestReplyConfirm_ChildCarSeatIsAskedInTheAppToo(t *testing.T) {
	// The question is policy, so the app asks it as the website does.
	f := newCarSeatFixture(t, "rccarseatapp", "OFFER: Child car seat (SW1)")

	body, _ := json.Marshal(map[string]interface{}{"message": "Still available?", "refmsgid": f.msgID})
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", f.chatID, f.replierToken), strings.NewReader(string(body)))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Origin", "capacitor://localhost")
	resp, err := getApp().Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	assert.NotNil(t, f.prompt(t), "asked in the app as on the web")
}

func TestReplyConfirm_ChildCarSeatIsAskedOncePerReplierAndPost(t *testing.T) {
	f := newCarSeatFixture(t, "rccarseatonce", "OFFER: Child car seat (SW1)")

	f.reply(t, "Is it still available?", true)
	p := f.prompt(t)
	require.NotNil(t, p)

	// The replier says no, then replies again: a second question would be a nag.
	f.answer(t, p.Chatmsgid, f.replierToken, "no")
	second := f.reply(t, "Actually, I do want it", true)

	assert.EqualValues(t, 1, carSeatQuestionsAsked(f), "asked once")
	required, _ := processingState(second)
	assert.Equal(t, 1, required, "the second reply goes to the batch as an ordinary reply")
}

func TestReplyConfirm_ChildCarSeatWantedIsNotAskedAbout(t *testing.T) {
	// The wording is for someone accepting a seat. A WANTED for one is answered by someone offering theirs.
	f := newCarSeatFixture(t, "rccarseatwanted", "WANTED: Child car seat (SW1)")
	database.DBConn.Exec("UPDATE messages SET type = 'Wanted' WHERE id = ?", f.msgID)

	f.reply(t, "I have one", true)
	assert.Nil(t, f.prompt(t))
}

func TestReplyConfirm_ChildCarSeatNotForTrashNothingMembers(t *testing.T) {
	f := newCarSeatFixture(t, "rccarseattn", "OFFER: Child car seat (SW1)")
	database.DBConn.Exec("UPDATE users SET tnuserid = 12345 WHERE id = ?", f.posterID)

	f.reply(t, "Yes please", true)
	assert.Nil(t, f.prompt(t), "nobody at TrashNothing would see the question")
}
