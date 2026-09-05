package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestLikeStoryRequiresLogin(t *testing.T) {
	body := `{"id":1}`
	req := httptest.NewRequest("POST", "/api/story/like", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestUnlikeStoryRequiresLogin(t *testing.T) {
	body := `{"id":1}`
	req := httptest.NewRequest("POST", "/api/story/unlike", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestLikeStoryRejectsBadBody(t *testing.T) {
	prefix := uniquePrefix("like_badbody")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `not valid json`
	req := httptest.NewRequest("POST", "/api/story/like?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestLikeStoryRejectsMissingID(t *testing.T) {
	prefix := uniquePrefix("like_no_id")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `{}`
	req := httptest.NewRequest("POST", "/api/story/like?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestLikeStoryRejectsZeroID(t *testing.T) {
	prefix := uniquePrefix("like_zero_id")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	body := `{"id":0}`
	req := httptest.NewRequest("POST", "/api/story/like?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestLikeStoryAddsLike(t *testing.T) {
	prefix := uniquePrefix("like_add")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	storyID := CreateTestStory(t, userID, "Like Add "+prefix, "A story to like", true, true)

	body := fmt.Sprintf(`{"id":%d}`, storyID)
	req := httptest.NewRequest("POST", "/api/story/like?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM users_stories_likes WHERE storyid = ? AND userid = ?", storyID, userID).Scan(&count)
	assert.Equal(t, int64(1), count)
}

func TestLikeStoryIsIdempotent(t *testing.T) {
	prefix := uniquePrefix("like_idem")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	storyID := CreateTestStory(t, userID, "Like Idem "+prefix, "A story to like", true, true)

	body := fmt.Sprintf(`{"id":%d}`, storyID)
	req1 := httptest.NewRequest("POST", "/api/story/like?jwt="+token, bytes.NewBufferString(body))
	req1.Header.Set("Content-Type", "application/json")
	resp1, _ := getApp().Test(req1)
	assert.Equal(t, 200, resp1.StatusCode)

	req2 := httptest.NewRequest("POST", "/api/story/like?jwt="+token, bytes.NewBufferString(body))
	req2.Header.Set("Content-Type", "application/json")
	resp2, _ := getApp().Test(req2)
	assert.Equal(t, 200, resp2.StatusCode)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM users_stories_likes WHERE storyid = ? AND userid = ?", storyID, userID).Scan(&count)
	assert.Equal(t, int64(1), count)
}

func TestUnlikeStoryRemovesLike(t *testing.T) {
	prefix := uniquePrefix("unlike_rem")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	storyID := CreateTestStory(t, userID, "Unlike Rem "+prefix, "A story to unlike", true, true)

	db := database.DBConn
	db.Exec("INSERT INTO users_stories_likes (storyid, userid) VALUES (?, ?)", storyID, userID)

	body := fmt.Sprintf(`{"id":%d}`, storyID)
	req := httptest.NewRequest("POST", "/api/story/unlike?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var count int64
	db.Raw("SELECT COUNT(*) FROM users_stories_likes WHERE storyid = ? AND userid = ?", storyID, userID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestUnlikeStoryWhenNotLiked(t *testing.T) {
	prefix := uniquePrefix("unlike_not")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	storyID := CreateTestStory(t, userID, "Unlike Not "+prefix, "A story to unlike", true, true)

	body := fmt.Sprintf(`{"id":%d}`, storyID)
	req := httptest.NewRequest("POST", "/api/story/unlike?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM users_stories_likes WHERE storyid = ? AND userid = ?", storyID, userID).Scan(&count)
	assert.Equal(t, int64(0), count)
}
