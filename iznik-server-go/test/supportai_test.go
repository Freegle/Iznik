package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func supportAIRequest(t *testing.T, method string, path string, token string, body interface{}) (*http.Response, []byte) {
	var reader io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		reader = bytes.NewBuffer(b)
	}
	url := path
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest(method, url, reader)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	resp, err := getApp().Test(req, 10000)
	assert.NoError(t, err)
	respBody, _ := io.ReadAll(resp.Body)
	return resp, respBody
}

func recordSupportAIRun(t *testing.T, token string, body map[string]interface{}) uint64 {
	resp, respBody := supportAIRequest(t, "POST", "/api/supportai/runs", token, body)
	assert.Equal(t, 200, resp.StatusCode, string(respBody))
	var out struct {
		ID uint64 `json:"id"`
	}
	json.Unmarshal(respBody, &out)
	assert.Greater(t, out.ID, uint64(0))
	t.Cleanup(func() {
		database.DBConn.Exec("DELETE FROM support_ai_runs WHERE id = ?", out.ID)
	})
	return out.ID
}

func TestSupportAIRunsRequireSupport(t *testing.T) {
	prefix := uniquePrefix("saiauth")
	supportID := CreateTestUser(t, prefix+"_support", "Support")
	_, supportToken := CreateTestSession(t, supportID)
	id := recordSupportAIRun(t, supportToken, map[string]interface{}{"query": "secret question " + prefix})

	userID := CreateTestUser(t, prefix+"_user", "User")
	_, userToken := CreateTestSession(t, userID)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	cases := []struct {
		method string
		path   string
		body   interface{}
	}{
		{"GET", "/api/supportai/runs", nil},
		{"GET", fmt.Sprintf("/api/supportai/runs/%d", id), nil},
		{"POST", "/api/supportai/runs", map[string]interface{}{"query": "x"}},
		{"PATCH", "/api/supportai/runs", map[string]interface{}{"id": id, "rating": -1}},
	}

	for _, c := range cases {
		resp, body := supportAIRequest(t, c.method, c.path, "", c.body)
		assert.Equal(t, 401, resp.StatusCode, c.method+" "+c.path)
		assert.NotContains(t, string(body), "secret question")

		for _, token := range []string{userToken, modToken} {
			resp, body = supportAIRequest(t, c.method, c.path, token, c.body)
			assert.Equal(t, 403, resp.StatusCode, c.method+" "+c.path)
			assert.NotContains(t, string(body), "secret question")
		}
	}

	// None of the refused calls rated or added anything.
	var rating *int
	database.DBConn.Raw("SELECT rating FROM support_ai_runs WHERE id = ?", id).Scan(&rating)
	assert.Nil(t, rating)
	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM support_ai_runs WHERE modid IN (?, ?)", userID, modID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestSupportAIRecordListAndGet(t *testing.T) {
	prefix := uniquePrefix("sairec")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)
	memberID := CreateTestUser(t, prefix+"_member", "User")

	id := recordSupportAIRun(t, token, map[string]interface{}{
		"userid":          memberID,
		"sessionid":       "sess-" + prefix,
		"query":           "Why can't they reply? " + prefix,
		"analysis":        "Their email is unverified.",
		"transcript":      `[{"type":"tool","name":"db_query","input":{"sql":"SELECT 1"}}]`,
		"driver":          "subscription",
		"model":           "opus",
		"input_tokens":    1200,
		"output_tokens":   340,
		"duration_ms":     45000,
		"quota_5h_before": 12.5,
		"quota_5h_after":  14,
		"quota_7d_before": 40,
		"quota_7d_after":  40.25,
		// The asker comes from the JWT; a body value is ignored.
		"modid": 1,
	})

	resp, body := supportAIRequest(t, "GET", "/api/supportai/runs", token, nil)
	assert.Equal(t, 200, resp.StatusCode)
	var runs []map[string]interface{}
	assert.NoError(t, json.Unmarshal(body, &runs))
	assert.NotEmpty(t, runs)

	// Most recent first: ours was the last recorded.
	first := runs[0]
	assert.Equal(t, float64(id), first["id"])
	assert.Equal(t, float64(adminID), first["modid"])
	assert.Equal(t, float64(memberID), first["userid"])
	assert.Equal(t, "Their email is unverified.", first["analysis"])
	assert.Equal(t, "Success", first["status"])
	assert.Equal(t, 12.5, first["quota_5h_before"])
	assert.Equal(t, 40.25, first["quota_7d_after"])
	assert.Equal(t, float64(1200), first["input_tokens"])
	assert.Contains(t, first["modname"], prefix+"_admin")
	assert.Contains(t, first["username"], prefix+"_member")
	assert.Nil(t, first["rating"])
	// The list leaves the transcript out; it can be large.
	_, hasTranscript := first["transcript"]
	assert.False(t, hasTranscript)

	resp, body = supportAIRequest(t, "GET", fmt.Sprintf("/api/supportai/runs/%d", id), token, nil)
	assert.Equal(t, 200, resp.StatusCode)
	var run map[string]interface{}
	assert.NoError(t, json.Unmarshal(body, &run))
	assert.Contains(t, run["transcript"], "db_query")
	assert.Equal(t, "sess-"+prefix, run["sessionid"])
	assert.Equal(t, "opus", run["model"])

	resp, _ = supportAIRequest(t, "GET", "/api/supportai/runs/999999999", token, nil)
	assert.Equal(t, 404, resp.StatusCode)
	resp, _ = supportAIRequest(t, "GET", "/api/supportai/runs/abc", token, nil)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestSupportAIRecordValidation(t *testing.T) {
	prefix := uniquePrefix("saival")
	supportID := CreateTestUser(t, prefix+"_support", "Support")
	_, token := CreateTestSession(t, supportID)

	resp, _ := supportAIRequest(t, "POST", "/api/supportai/runs", token, map[string]interface{}{"query": "  "})
	assert.Equal(t, 400, resp.StatusCode)

	// An error run is kept, so failures can be found as well as bad answers.
	id := recordSupportAIRun(t, token, map[string]interface{}{
		"query":  "q " + prefix,
		"status": "Error",
		"error":  "error_max_turns",
	})
	var status, errText string
	database.DBConn.Raw("SELECT status, error FROM support_ai_runs WHERE id = ?", id).Row().Scan(&status, &errText)
	assert.Equal(t, "Error", status)
	assert.Equal(t, "error_max_turns", errText)

	// Anything other than Error is a success; quota left out stays unknown.
	id = recordSupportAIRun(t, token, map[string]interface{}{"query": "q2 " + prefix, "status": "bogus"})
	var quota *float64
	database.DBConn.Raw("SELECT status FROM support_ai_runs WHERE id = ?", id).Scan(&status)
	database.DBConn.Raw("SELECT quota_5h_before FROM support_ai_runs WHERE id = ?", id).Scan(&quota)
	assert.Equal(t, "Success", status)
	assert.Nil(t, quota)
}

func TestSupportAIRate(t *testing.T) {
	prefix := uniquePrefix("sairate")
	supportID := CreateTestUser(t, prefix+"_support", "Support")
	_, token := CreateTestSession(t, supportID)
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, adminToken := CreateTestSession(t, adminID)

	id := recordSupportAIRun(t, token, map[string]interface{}{"query": "q " + prefix})

	comment := "It missed the held chat message."
	resp, body := supportAIRequest(t, "PATCH", "/api/supportai/runs", token, map[string]interface{}{
		"id": id, "rating": -1, "comment": comment,
	})
	assert.Equal(t, 200, resp.StatusCode, string(body))

	var rating int
	var ratedby uint64
	var ratingComment string
	database.DBConn.Raw("SELECT rating, ratedby, rating_comment FROM support_ai_runs WHERE id = ?", id).Row().Scan(&rating, &ratedby, &ratingComment)
	assert.Equal(t, -1, rating)
	assert.Equal(t, supportID, ratedby)
	assert.Equal(t, comment, ratingComment)

	// Rating the same again changes no row but is not "not found".
	resp, _ = supportAIRequest(t, "PATCH", "/api/supportai/runs", token, map[string]interface{}{
		"id": id, "rating": -1, "comment": comment,
	})
	assert.Equal(t, 200, resp.StatusCode)

	// The filter finds it among thumbs down, not thumbs up.
	assertRunListed := func(rating string, want bool) {
		req := httptest.NewRequest("GET", "/api/supportai/runs?rating="+rating+"&limit=200&jwt="+token, nil)
		r, err := getApp().Test(req, 10000)
		assert.NoError(t, err)
		assert.Equal(t, 200, r.StatusCode)
		body, _ := io.ReadAll(r.Body)
		var runs []map[string]interface{}
		json.Unmarshal(body, &runs)
		found := false
		for _, r := range runs {
			if r["id"] == float64(id) {
				found = true
			}
		}
		assert.Equal(t, want, found, "rating filter "+rating)
	}
	assertRunListed("down", true)
	assertRunListed("up", false)
	assertRunListed("unrated", false)

	// A reviewer can change it later; without a comment the comment is kept.
	resp, _ = supportAIRequest(t, "PATCH", "/api/supportai/runs", adminToken, map[string]interface{}{"id": id, "rating": 1})
	assert.Equal(t, 200, resp.StatusCode)
	database.DBConn.Raw("SELECT rating, ratedby, rating_comment FROM support_ai_runs WHERE id = ?", id).Row().Scan(&rating, &ratedby, &ratingComment)
	assert.Equal(t, 1, rating)
	assert.Equal(t, adminID, ratedby)
	assert.Equal(t, comment, ratingComment)
	assertRunListed("up", true)

	// Zero clears the rating.
	resp, _ = supportAIRequest(t, "PATCH", "/api/supportai/runs", token, map[string]interface{}{"id": id, "rating": 0})
	assert.Equal(t, 200, resp.StatusCode)
	var cleared *int
	database.DBConn.Raw("SELECT rating FROM support_ai_runs WHERE id = ?", id).Scan(&cleared)
	assert.Nil(t, cleared)
	assertRunListed("unrated", true)

	resp, _ = supportAIRequest(t, "PATCH", "/api/supportai/runs", token, map[string]interface{}{"id": id, "rating": 5})
	assert.Equal(t, 400, resp.StatusCode)
	resp, _ = supportAIRequest(t, "PATCH", "/api/supportai/runs", token, map[string]interface{}{"rating": 1})
	assert.Equal(t, 400, resp.StatusCode)
	resp, _ = supportAIRequest(t, "PATCH", "/api/supportai/runs", token, map[string]interface{}{"id": 999999999, "rating": 1})
	assert.Equal(t, 404, resp.StatusCode)
}

func TestSupportAIListPaging(t *testing.T) {
	prefix := uniquePrefix("saipage")
	supportID := CreateTestUser(t, prefix+"_support", "Support")
	_, token := CreateTestSession(t, supportID)

	first := recordSupportAIRun(t, token, map[string]interface{}{"query": "one " + prefix})
	second := recordSupportAIRun(t, token, map[string]interface{}{"query": "two " + prefix})

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/supportai/runs?limit=1&before=%d&jwt=%s", second, token), nil)
	resp, err := getApp().Test(req, 10000)
	assert.NoError(t, err)
	body, _ := io.ReadAll(resp.Body)
	var runs []map[string]interface{}
	json.Unmarshal(body, &runs)
	assert.Len(t, runs, 1)
	assert.Equal(t, float64(first), runs[0]["id"])
}
