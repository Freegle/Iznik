package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The member side of reach mail. Reach mail used to catch a member who became eligible after
// a post's reach settled only if that happened inside a 60-minute window. The codepaths that
// change a member now queue them in rippling_reach_member_pending, and the batch reach pass
// drains the queue. These tests pin each Go hook: exactly one row, with the right reason.

func queuedReason(t *testing.T, userid uint64) (string, int64) {
	t.Helper()
	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM rippling_reach_member_pending WHERE userid = ?", userid).Scan(&count)
	var reason string
	db.Raw("SELECT reason FROM rippling_reach_member_pending WHERE userid = ?", userid).Scan(&reason)
	return reason, count
}

func clearQueue(userid uint64) {
	database.DBConn.Exec("DELETE FROM rippling_reach_member_pending WHERE userid = ?", userid)
}

func TestReachQueue_JoiningAGroupQueuesTheMember(t *testing.T) {
	prefix := uniquePrefix("rq_join")
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	groupID := CreateTestGroup(t, prefix)
	clearQueue(userID)
	defer clearQueue(userID)

	body, _ := json.Marshal(map[string]interface{}{"userid": userID, "groupid": groupID})
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/memberships?jwt=%s", token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	reason, count := queuedReason(t, userID)
	assert.Equal(t, int64(1), count, "a join queues the member once")
	assert.Equal(t, "joined", reason)
}

func TestReachQueue_SwitchingToImmediateQueuesTheMember(t *testing.T) {
	prefix := uniquePrefix("rq_freq")
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Member")
	clearQueue(userID)
	defer clearQueue(userID)

	patch := func(freq int) {
		body, _ := json.Marshal(map[string]interface{}{"userid": userID, "groupid": groupID, "emailfrequency": freq})
		req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/memberships?jwt=%s", token), bytes.NewBuffer(body))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)
	}

	// Daily first: not immediate, so nothing to queue.
	patch(24)
	_, count := queuedReason(t, userID)
	assert.Equal(t, int64(0), count, "switching to daily does not queue")

	patch(-1)
	reason, count := queuedReason(t, userID)
	assert.Equal(t, int64(1), count, "switching to immediate queues the member")
	assert.Equal(t, "frequency", reason)
}

func TestReachQueue_ChangingPostcodeQueuesTheMember(t *testing.T) {
	prefix := uniquePrefix("rq_move")
	db := database.DBConn
	userID := CreateTestUser(t, prefix+"_user", "User")
	_, token := CreateTestSession(t, userID)
	clearQueue(userID)
	defer clearQueue(userID)

	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Postcode', 55.9533, -3.1883)", prefix+"_loc")
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? LIMIT 1", prefix+"_loc").Scan(&locID)
	require.NotZero(t, locID, "test location")
	defer db.Exec("DELETE FROM locations WHERE id = ?", locID)

	payload, _ := json.Marshal(map[string]interface{}{"settings": map[string]interface{}{
		"mylocation": map[string]interface{}{"id": locID, "name": prefix + "_loc", "type": "Postcode", "lat": 55.9533, "lng": -3.1883},
	}})
	req := httptest.NewRequest("PATCH", "/api/user?jwt="+token, bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	reason, count := queuedReason(t, userID)
	assert.Equal(t, int64(1), count, "a postcode change queues the member")
	assert.Equal(t, "moved", reason)
}

func TestReachQueue_ReturningAfterLongAbsenceQueuesOnce(t *testing.T) {
	db := database.DBConn
	uid, token := CreateFullTestUser(t, uniquePrefix("rq_back"))
	clearQueue(uid)
	defer clearQueue(uid)

	// Away for 100 days: the first authenticated request is the return.
	db.Exec("UPDATE users SET lastaccess = DATE_SUB(NOW(), INTERVAL 100 DAY) WHERE id = ?", uid)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/notification/count?jwt="+token, nil))
	require.Equal(t, 200, resp.StatusCode)

	reason, count := queuedReason(t, uid)
	assert.Equal(t, int64(1), count, "returning after 90 days queues the member")
	assert.Equal(t, "returned", reason)

	// The next request is not a return: lastaccess is now fresh, so nothing more is queued.
	clearQueue(uid)
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/notification/count?jwt="+token, nil))
	require.Equal(t, 200, resp.StatusCode)
	_, count = queuedReason(t, uid)
	assert.Equal(t, int64(0), count, "an ordinary request after the return does not queue")
}

func TestReachQueue_RecentlyActiveMemberIsNotQueued(t *testing.T) {
	db := database.DBConn
	uid, token := CreateFullTestUser(t, uniquePrefix("rq_recent"))
	clearQueue(uid)
	defer clearQueue(uid)

	// Away 20 minutes: past the 10-minute lastaccess throttle, but not a return.
	db.Exec("UPDATE users SET lastaccess = DATE_SUB(NOW(), INTERVAL 20 MINUTE) WHERE id = ?", uid)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/notification/count?jwt="+token, nil))
	require.Equal(t, 200, resp.StatusCode)

	_, count := queuedReason(t, uid)
	assert.Equal(t, int64(0), count, "refreshing lastaccess is not a return")
}

func TestReachQueue_RegisteringWithAGroupQueuesTheNewMember(t *testing.T) {
	prefix := uniquePrefix("rq_reg")
	groupID := CreateTestGroup(t, prefix)

	payload, _ := json.Marshal(map[string]interface{}{
		"email":       fmt.Sprintf("%s@test.com", prefix),
		"password":    "testpass123",
		"firstname":   "Test",
		"lastname":    prefix,
		"displayname": "Test " + prefix,
		"groupid":     groupID,
	})
	req := httptest.NewRequest("PUT", "/api/user", bytes.NewBuffer(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 5000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	require.NotZero(t, result["id"])
	userID := uint64(result["id"].(float64))
	defer clearQueue(userID)

	reason, count := queuedReason(t, userID)
	assert.Equal(t, int64(1), count, "registering straight into a group queues the new member")
	assert.Equal(t, "joined", reason)
}
