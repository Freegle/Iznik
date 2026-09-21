package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"github.com/freegle/iznik-server-go/config"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/stretchr/testify/assert"
	"net/http/httptest"
	"testing"
)

func TestConfig(t *testing.T) {
	// A non-allowlisted config key is not publicly readable.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/config/wibble", nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func stringPtr(s string) *string {
	return &s
}

// Concern Keywords tests

func TestConcernKeywords_Unauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/config/admin/concern_keywords", nil))
	assert.Equal(t, 401, resp.StatusCode)

	prefix := uniquePrefix("ckauth")
	regularUserID := CreateTestUser(t, prefix, "User")
	_, regularToken := CreateTestSession(t, regularUserID)
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/config/admin/concern_keywords?jwt="+regularToken, nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestConcernKeywords_List(t *testing.T) {
	prefix := uniquePrefix("cklist")
	supportUserID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportUserID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/config/admin/concern_keywords?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var keywords []config.ConcernKeyword
	json2.Unmarshal(rsp(resp), &keywords)
	assert.NotNil(t, keywords)
}

func TestConcernKeywords_Create(t *testing.T) {
	prefix := uniquePrefix("ckcreate")
	supportUserID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportUserID)

	kwReq := config.CreateConcernKeywordRequest{
		Keyword:   "test_concern_kw",
		Category:  "review",
		MatchMode: "literal",
		Action:    "flag",
		Scope:     "global",
	}

	body, _ := json2.Marshal(kwReq)
	req := httptest.NewRequest("POST", "/api/config/admin/concern_keywords?jwt="+token, bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var kw config.ConcernKeyword
	json2.Unmarshal(rsp(resp), &kw)
	assert.Equal(t, "test_concern_kw", kw.Keyword)
	assert.Equal(t, "review", kw.Category)
	assert.Equal(t, "literal", kw.MatchMode)
	assert.Equal(t, "flag", kw.Action)
	assert.Equal(t, "global", kw.Scope)
	assert.Greater(t, kw.ID, uint64(0))

	// Clean up
	database.DBConn.Delete(&config.ConcernKeyword{}, kw.ID)
}

// A Freegle-wide block keyword is normally added after the content it targets has
// already landed, so creating one queues a backfill for the batch to apply it to
// the last 24 hours. A flag keyword only holds new content for review, and a
// group-scoped block only concerns that group's queue; neither queues anything.
func TestConcernKeywords_BlockKeywordQueuesBackfill(t *testing.T) {
	prefix := uniquePrefix("ckblock")
	supportUserID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportUserID)

	create := func(name, action, scope string) config.ConcernKeyword {
		body, _ := json2.Marshal(config.CreateConcernKeywordRequest{
			Keyword:   prefix + "_" + name,
			Category:  "scam",
			MatchMode: "literal",
			Action:    action,
			Scope:     scope,
		})
		req := httptest.NewRequest("POST", "/api/config/admin/concern_keywords?jwt="+token, bytes.NewReader(body))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req)
		assert.Equal(t, 200, resp.StatusCode)

		var kw config.ConcernKeyword
		json2.Unmarshal(rsp(resp), &kw)
		assert.Greater(t, kw.ID, uint64(0))
		return kw
	}

	queued := func(id uint64) int64 {
		var n int64
		database.DBConn.Raw(
			"SELECT COUNT(*) FROM background_tasks WHERE task_type = ? AND JSON_EXTRACT(data, '$.keyword_id') = ?",
			queue.TaskConcernKeywordBackfill, id,
		).Scan(&n)
		return n
	}

	blocked := create("block", "block", "global")
	flagged := create("flag", "flag", "global")
	groupBlocked := create("groupblock", "block", "group")

	assert.Equal(t, int64(1), queued(blocked.ID), "creating a global block keyword must queue a backfill")
	assert.Equal(t, int64(0), queued(flagged.ID), "a flag keyword holds new content only; nothing to backfill")
	assert.Equal(t, int64(0), queued(groupBlocked.ID), "a group-scoped block is not a Freegle-wide decision")

	// Clean up
	database.DBConn.Exec(
		"DELETE FROM background_tasks WHERE task_type = ? AND JSON_EXTRACT(data, '$.keyword_id') IN (?, ?, ?)",
		queue.TaskConcernKeywordBackfill, blocked.ID, flagged.ID, groupBlocked.ID,
	)
	for _, kw := range []config.ConcernKeyword{blocked, flagged, groupBlocked} {
		database.DBConn.Delete(&config.ConcernKeyword{}, kw.ID)
	}
}

func TestConcernKeywords_CreateValidation(t *testing.T) {
	prefix := uniquePrefix("ckvalid")
	supportUserID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportUserID)

	kwReq := config.CreateConcernKeywordRequest{
		Category: "review",
	}
	body, _ := json2.Marshal(kwReq)
	req := httptest.NewRequest("POST", "/api/config/admin/concern_keywords?jwt="+token, bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestConcernKeywords_Delete(t *testing.T) {
	prefix := uniquePrefix("ckdelete")
	supportUserID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportUserID)

	kw := config.ConcernKeyword{
		Keyword:   "kw_to_delete",
		Category:  "scam",
		MatchMode: "literal",
		Scope:     "global",
		Action:    "block",
	}
	database.DBConn.Create(&kw)

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/config/admin/concern_keywords/%d?jwt=%s", kw.ID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var count int64
	database.DBConn.Model(&config.ConcernKeyword{}).Where("id = ?", kw.ID).Count(&count)
	assert.Equal(t, int64(0), count)
}
