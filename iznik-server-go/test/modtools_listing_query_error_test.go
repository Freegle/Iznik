package test

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// A listing query that fails must not be reported to ModTools as an empty
// queue. The all-communities form of this query fans out over every group the
// moderator covers and carries MAX_EXECUTION_TIME(20000), so a slow replica
// aborts it; the result used to be a perfectly ordinary 200 with
// "messages": [], which the client cannot tell apart from "nothing pending".
// The moderator then sees an empty page while the work count in the menu says
// there is work, and the infinite loader stops for good (Discourse 10037).
//
// A cancelled context makes every query on the handle fail, which is the same
// class of error as the execution-time abort and needs no DB surgery.
func TestListMessagesMT_QueryErrorIsNotAnEmptyQueue(t *testing.T) {
	prefix := uniquePrefix("lstmt_qerr")

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Collection=Approved with an explicit groupid so the handler reaches the
	// listing query without needing the DB for the moderator checks first.
	url := fmt.Sprintf("/api/modtools/messages?groupid=%d&collection=Approved&jwt=%s", groupID, modToken)

	// Sanity check: the request works before we break the handle.
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	good := database.DBConn
	cancelledCtx, cancel := context.WithCancel(context.Background())
	cancel()
	database.DBConn = good.WithContext(cancelledCtx)
	defer func() { database.DBConn = good }()

	resp, err = getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.NoError(t, err)

	assert.NotEqual(t, 200, resp.StatusCode,
		"a failed listing query must not be answered with a success")

	var body map[string]interface{}
	_ = json.NewDecoder(resp.Body).Decode(&body)
	if msgs, ok := body["messages"].([]interface{}); ok {
		assert.Fail(t, "a failed listing query returned a messages array",
			"got %d messages, which ModTools would read as an empty queue", len(msgs))
	}
}
