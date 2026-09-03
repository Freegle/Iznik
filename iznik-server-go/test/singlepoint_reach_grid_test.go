package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strconv"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// The single-point reach gates — replyeligible on the message list and the
// chat reply hold — answer from the post's stored LABEL alone
// (rippling.ReachMembership). The sandwich bounds are a browse-narrowing
// device and must NOT decide these gates, so the fixtures here are
// ADVERSARIAL — bounds contradicting the label — to prove the label, not
// the bounds, was trusted. No verdict (no label, or routing down) is not a
// refusal: these gates fail open, so a reach outage never tells a member a
// post beside them has not reached them. Sentry carries the outage instead.

// TestReplyEligibleGridGate: the message-list reach probe (replyeligible).
func TestReplyEligibleGridGate(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("spbre")
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	group := CreateTestGroup(t, prefix)
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	CreateTestMembership(t, viewerID, group, "Member")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, viewerID)

	mid := CreateTestMessage(t, posterID, group, "OFFER: single point grid (spbre)", 51.5, -0.1)
	idStr := strconv.FormatUint(mid, 10)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	eligibility := func() *bool {
		msgs := message.GetMessagesByIds(viewerID, []string{idStr}, false)
		if !assert.Len(t, msgs, 1) {
			t.FailNow()
		}
		return msgs[0].ReplyEligible
	}

	insertReachCells(t, mid, coversViewerWkt)

	// The label admits while the outer bound (adversarially) excludes: the
	// label decides, so they are eligible.
	stubReachEvalMax(t, "in")
	setOuterBound(mid, farAwayWkt)
	assert.Nil(t, eligibility(), "a viewer the label admits is eligible whatever the bounds say")

	// The label refuses while the outer bound (adversarially) covers:
	// still the label decides — blocked.
	stubReachEvalMax(t, "out")
	setOuterBound(mid, bigCoversViewerWkt)
	if e := eligibility(); assert.NotNil(t, e, "label refuses → replyeligible set") {
		assert.False(t, *e, "a viewer the label refuses is reach-blocked whatever the bounds say")
	}

	// No stored label, or a routing server that cannot answer: no verdict,
	// and no verdict is not a refusal. Nobody is told a post has not reached
	// them on the strength of an outage, so replyeligible stays unset.
	stubReachEvalMax(t, "nolabels")
	degradeBounds(mid)
	assert.Nil(t, eligibility(), "an undecided verdict does not block: the gate fails open")
}

// TestChatReplyGateGridGate: the write-path reply hold answers from the grid too.
func TestChatReplyGateGridGate(t *testing.T) {
	db := database.DBConn
	db.Exec(`CREATE TABLE IF NOT EXISTS rippling_held_replies (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		chatid BIGINT UNSIGNED NOT NULL, chatmsgid BIGINT UNSIGNED NOT NULL,
		msgid BIGINT UNSIGNED NOT NULL, replieruserid BIGINT UNSIGNED NOT NULL,
		source ENUM('email','tn','web') NOT NULL DEFAULT 'email',
		lat DOUBLE, lng DOUBLE,
		status ENUM('held','released','dropped','taken-gone') NOT NULL DEFAULT 'held',
		created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, releasedat TIMESTAMP NULL,
		INDEX (msgid), INDEX (chatid), INDEX (status)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`)

	prefix := uniquePrefix("spbchat")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")
	db.Exec(`UPDATE users SET settings = '{"mylocation":{"lat":51.5,"lng":-0.1}}' WHERE id = ?`, replierID)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: chat gate grid (spbchat)", 51.5, -0.1)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)
	defer db.Exec("DELETE FROM rippling_held_replies WHERE msgid = ?", msgID)

	chatID := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	_, token := CreateTestSession(t, replierID)

	post := func() int {
		var payload chat.ChatMessage
		payload.Message = "I'd like this please"
		payload.Refmsgid = &msgID
		s, _ := json.Marshal(payload)
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/chat/%d/message?jwt=%s", chatID, token), bytes.NewBuffer(s))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		return resp.StatusCode
	}
	heldCount := func() int {
		var n int
		db.Raw("SELECT COUNT(*) FROM rippling_held_replies WHERE msgid = ? AND replieruserid = ?",
			msgID, replierID).Scan(&n)
		return n
	}

	insertReachCells(t, msgID, coversViewerWkt)

	// The label admits while the outer bound (adversarially) excludes:
	// delivered, not held — the label decides.
	stubReachEvalMax(t, "in")
	setOuterBound(msgID, farAwayWkt)
	assert.Equal(t, fiber.StatusOK, post())
	assert.Equal(t, 0, heldCount(), "a replier the label admits is in reach whatever the bounds say — not held")

	// The label refuses while the outer bound (adversarially) covers: held.
	stubReachEvalMax(t, "out")
	setOuterBound(msgID, bigCoversViewerWkt)
	assert.Equal(t, fiber.StatusOK, post())
	assert.Equal(t, 1, heldCount(), "a replier the label refuses is out of reach whatever the bounds say — held")
}
