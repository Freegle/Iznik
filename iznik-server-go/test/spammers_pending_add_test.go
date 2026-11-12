package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func newsfeedStatusOf(userID uint64) string {
	var s string
	database.DBConn.Raw("SELECT newsfeedmodstatus FROM users WHERE id = ?", userID).Scan(&s)
	return s
}

// reportPendingAdd reports the target through the API, which hides their ChitChat while the
// report is pending.
func reportPendingAdd(t *testing.T, token string, targetID uint64) uint64 {
	body := fmt.Sprintf(`{"userid":%d,"collection":"PendingAdd","reason":"Looks off"}`, targetID)
	req := httptest.NewRequest("POST", "/api/modtools/spammers?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)
	require.Equal(t, utils.NEWSFEEDMODSTATUS_SUPPRESSED, newsfeedStatusOf(targetID))

	var id uint64
	database.DBConn.Raw("SELECT id FROM spam_users WHERE userid = ? ORDER BY id DESC LIMIT 1", targetID).Scan(&id)
	require.Greater(t, id, uint64(0))
	return id
}

// ChitChat is hidden only while a PendingAdd report is pending review. A report that ends as
// a safelisting or is deleted must give the member their ChitChat back.
func TestSpammerPendingAddSuppressionLiftedWhenWhitelisted(t *testing.T) {
	prefix := uniquePrefix("SpamPendWl")
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	_, token := CreateTestSession(t, adminID)
	targetID := CreateTestUser(t, prefix+"_target", utils.SYSTEMROLE_USER)
	spamID := reportPendingAdd(t, token, targetID)

	body := fmt.Sprintf(`{"id":%d,"collection":"Whitelisted","reason":"Checked, fine"}`, spamID)
	req := httptest.NewRequest("PATCH", "/api/modtools/spammers?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	assert.NotEqual(t, utils.NEWSFEEDMODSTATUS_SUPPRESSED, newsfeedStatusOf(targetID), "a safelisted member's ChitChat is no longer hidden")
}

func TestSpammerPendingAddSuppressionLiftedWhenDeleted(t *testing.T) {
	prefix := uniquePrefix("SpamPendDel")
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	_, token := CreateTestSession(t, adminID)
	targetID := CreateTestUser(t, prefix+"_target", utils.SYSTEMROLE_USER)
	spamID := reportPendingAdd(t, token, targetID)

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/spammers?id=%d&jwt=%s", spamID, token), nil)
	resp, _ := getApp().Test(req)
	require.Equal(t, 200, resp.StatusCode)

	assert.NotEqual(t, utils.NEWSFEEDMODSTATUS_SUPPRESSED, newsfeedStatusOf(targetID), "a member whose report was deleted is no longer hidden")
}
