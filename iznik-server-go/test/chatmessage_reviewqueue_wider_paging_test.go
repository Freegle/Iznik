package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// With wider chat review the queue lists the moderator's own communities first and the other communities after, each
// oldest first. The context after a page of own-community rows must leave the other communities whole, however old
// their messages are, or a moderator paging through the queue never reaches them.
func TestReviewQueueContextAfterOwnCommunityRowsStillReachesWiderReviewRows(t *testing.T) {
	db := database.DBConn
	modToken, modID, groupID, _, widerMsgID := setupWiderReviewData(t)
	require.NotZero(t, modID)

	// Two messages in the moderator's own community, written after the wider review message so that their ids are
	// higher than its.
	prefix := uniquePrefix("rqwider")
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, memberID, groupID, utils.ROLE_MEMBER)
	chatID := CreateTestChatRoom(t, memberID, nil, &groupID, "User2Mod")
	var own []uint64
	for i := 0; i < 2; i++ {
		db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, NOW(), 1, 1)", chatID, memberID, fmt.Sprintf("Own community message %d", i))
		var id uint64
		db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&id)
		require.NotZero(t, id)
		require.Greater(t, id, widerMsgID)
		own = append(own, id)
	}

	page := func(query string) (ids []uint64, context string) {
		resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/chatmessages?jwt=%s%s", modToken, query), nil), -1)
		require.Equal(t, 200, resp.StatusCode)

		var result struct {
			Chatmessages []struct {
				ID uint64 `json:"id"`
			} `json:"chatmessages"`
			Context struct {
				ID    uint64 `json:"id"`
				Wider bool   `json:"wider"`
			} `json:"context"`
		}
		require.NoError(t, json.NewDecoder(resp.Body).Decode(&result))
		for _, m := range result.Chatmessages {
			ids = append(ids, m.ID)
		}

		return ids, fmt.Sprintf("&context=%d&contextwider=%v", result.Context.ID, result.Context.Wider)
	}

	first, context := page("&limit=2")
	require.Equal(t, own, first, "the moderator's own community comes first")

	var reached []uint64
	for i := 0; i < 10 && context != ""; i++ {
		var ids []uint64
		ids, context = page("&limit=1000" + context)
		if len(ids) == 0 {
			break
		}
		reached = append(reached, ids...)
	}

	assert.Contains(t, reached, widerMsgID, "the wider review message is still reached after the own community rows")
}
