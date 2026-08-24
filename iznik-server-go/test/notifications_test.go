package test

import (
	"bytes"
	json2 "encoding/json"
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/notification"
	"github.com/stretchr/testify/assert"
	"net/http/httptest"
	"testing"
)

func TestNotifications(t *testing.T) {
	prefix := uniquePrefix("notif")
	_, token := CreateFullTestUser(t, prefix)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/notification/count?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	type Count struct {
		Count uint64
	}

	var count Count

	json2.Unmarshal(rsp(resp), &count)

	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/notification?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var notifications []notification.Notification

	json2.Unmarshal(rsp(resp), &notifications)
	assert.GreaterOrEqual(t, uint64(len(notifications)), count.Count)
}

func TestNotificationSeen(t *testing.T) {
	prefix := uniquePrefix("notif_seen")

	// Create user and get token
	userID := CreateTestUser(t, prefix, "User")
	fromUserID := CreateTestUser(t, prefix+"_from", "User")
	_, token := CreateTestSession(t, userID)

	// Create a notification
	notifID := CreateTestNotification(t, userID, fromUserID, "CommentOnYourPost")

	// Mark it as seen
	body := fmt.Sprintf(`{"id": %d}`, notifID)
	req := httptest.NewRequest("POST", "/api/notification/seen?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])
}

func TestNotificationSeenUnauthorized(t *testing.T) {
	// Test without token - should fail
	body := `{"id": 1}`
	req := httptest.NewRequest("POST", "/api/notification/seen", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestNotificationSeenInvalidBody(t *testing.T) {
	prefix := uniquePrefix("notif_invalid")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Test with missing ID
	body := `{}`
	req := httptest.NewRequest("POST", "/api/notification/seen?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestNotificationAllSeen(t *testing.T) {
	prefix := uniquePrefix("notif_allseen")

	// Create user and get token
	userID := CreateTestUser(t, prefix, "User")
	fromUserID := CreateTestUser(t, prefix+"_from", "User")
	_, token := CreateTestSession(t, userID)

	// Create multiple notifications
	CreateTestNotification(t, userID, fromUserID, "CommentOnYourPost")
	CreateTestNotification(t, userID, fromUserID, "LovedPost")

	// Mark all as seen
	req := httptest.NewRequest("POST", "/api/notification/allseen?jwt="+token, nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])

	// Verify count is now 0
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/notification/count?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	type Count struct {
		Count uint64
	}
	var count Count
	json2.Unmarshal(rsp(resp), &count)
	assert.Equal(t, uint64(0), count.Count)
}

func TestNotificationAllSeenUnauthorized(t *testing.T) {
	// Test without token - should fail
	req := httptest.NewRequest("POST", "/api/notification/allseen", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestNotificationListRecordsUsersActive(t *testing.T) {
	// V1 parity: notification.php GET calls $me->recordActive() which inserts a row into
	// users_active (userid, timestamp) with timestamp truncated to the hour. This is read by
	// Stats.php to count active users per group and drive the moderator activity leaderboard.
	db := database.DBConn
	prefix := uniquePrefix("notif_active")
	userID, token := CreateFullTestUser(t, prefix)

	// Remove any pre-existing users_active rows for this user
	db.Exec("DELETE FROM users_active WHERE userid = ?", userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/notification?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var count int64
	db.Raw("SELECT COUNT(*) FROM users_active WHERE userid = ?", userID).Scan(&count)
	assert.Equal(t, int64(1), count, "GET /api/notification should insert a users_active row")
}

func TestNotificationListDoesNotBumpFreshLastaccess(t *testing.T) {
	// The navbar polls GET /api/notification every 60s. It must NOT write
	// users.lastaccess on every poll: the auth middleware already maintains
	// lastaccess with a 10-minute throttle, and an unthrottled same-row UPDATE
	// sprayed across Galera write hosts causes certification conflicts
	// (plans/2026-07-17-db3-cpu-reach-sql-prefilter.md, adjacent fix 1).
	uid, token := CreateFullTestUser(t, uniquePrefix("notif_la"))
	db := database.DBConn
	db.Exec("UPDATE users SET lastaccess = DATE_SUB(NOW(), INTERVAL 5 MINUTE) WHERE id = ?", uid)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/notification?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	age := lastaccessAgeSecs(t, uid)
	assert.GreaterOrEqual(t, age, int64(240), "notification poll must not bump a fresh lastaccess")
}
