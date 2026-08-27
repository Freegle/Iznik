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
// chat reply hold — answer from the post's stored cell grid alone
// (rippling.ReachMembership): one keyed read, one run-stream probe. The
// sandwich bounds are a browse-narrowing device and must NOT decide these
// gates, so the fixtures here are ADVERSARIAL — bounds contradicting the
// grid, impossible for verified writer-derived bounds — to prove the grid,
// not the bounds, was trusted.

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

	// The grid covers the viewer while the outer bound (adversarially)
	// excludes them: the grid decides, so they are eligible.
	insertReachCells(t, mid, coversViewerWkt)
	setOuterBound(mid, farAwayWkt)
	assert.Nil(t, eligibility(), "a viewer the grid covers is eligible whatever the bounds say")

	// The grid misses the viewer while the outer bound (adversarially)
	// covers them: still the grid decides — blocked.
	insertReachCells(t, mid, missesViewerWkt)
	setOuterBound(mid, bigCoversViewerWkt)
	if e := eligibility(); assert.NotNil(t, e, "grid misses → replyeligible set") {
		assert.False(t, *e, "a viewer the grid misses is reach-blocked whatever the bounds say")
	}

	// Degraded bounds (completion pruning) change nothing for this gate: the
	// grid still answers, so a viewer inside it stays eligible.
	insertReachCells(t, mid, coversViewerWkt)
	degradeBounds(mid)
	assert.Nil(t, eligibility(), "degraded bounds do not blind the single-point gate")
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

	// The grid covers the replier while the outer bound (adversarially)
	// excludes them: delivered, not held — the grid decides.
	insertReachCells(t, msgID, coversViewerWkt)
	setOuterBound(msgID, farAwayWkt)
	assert.Equal(t, fiber.StatusOK, post())
	assert.Equal(t, 0, heldCount(), "a replier the grid covers is in reach whatever the bounds say — not held")

	// The grid misses the replier while the outer bound (adversarially)
	// covers them: held.
	insertReachCells(t, msgID, missesViewerWkt)
	setOuterBound(msgID, bigCoversViewerWkt)
	assert.Equal(t, fiber.StatusOK, post())
	assert.Equal(t, 1, heldCount(), "a replier the grid misses is out of reach whatever the bounds say — held")
}
