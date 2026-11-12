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

// The review queue lists the oldest pending message first and hands back the id of the last one it returned as the
// context. Asking again with that context must give the messages after it, or a moderator whose communities have more
// pending than one page can never reach the rest.
func TestReviewQueueContextMovesOnToTheNextPage(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("rqpaging")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_USER)
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, modID, groupID, utils.ROLE_MODERATOR)
	CreateTestMembership(t, memberID, groupID, utils.ROLE_MEMBER)
	chatID := CreateTestChatRoom(t, memberID, nil, &groupID, "User2Mod")
	_, token := CreateTestSession(t, modID)

	var pending []uint64
	for i := 0; i < 5; i++ {
		db.Exec("INSERT INTO chat_messages (chatid, userid, message, date, reviewrequired, processingsuccessful) "+
			"VALUES (?, ?, ?, NOW(), 1, 1)", chatID, memberID, fmt.Sprintf("Pending message %d", i))
		var id uint64
		db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&id)
		require.NotZero(t, id)
		pending = append(pending, id)
	}

	page := func(query string) (ids []uint64, context uint64) {
		resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/chatmessages?jwt=%s%s", token, query), nil), -1)
		require.Equal(t, 200, resp.StatusCode)

		var result struct {
			Chatmessages []struct {
				ID uint64 `json:"id"`
			} `json:"chatmessages"`
			Context struct {
				ID uint64 `json:"id"`
			} `json:"context"`
		}
		require.NoError(t, json.NewDecoder(resp.Body).Decode(&result))
		for _, m := range result.Chatmessages {
			ids = append(ids, m.ID)
		}

		return ids, result.Context.ID
	}

	first, context := page("&limit=3")
	require.Equal(t, pending[:3], first, "the first page is the oldest three")

	second, _ := page(fmt.Sprintf("&limit=3&context=%d", context))
	assert.Equal(t, pending[3:], second, "the next page is the messages after the first, not the first again")
}
