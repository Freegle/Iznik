package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// Reporting a member as a spammer is a moderator tool, and it changes how another member's
// ChitChat is shown, so an ordinary member must not be able to do it.
func TestSpammerPendingAddRefusedForOrdinaryMember(t *testing.T) {
	prefix := uniquePrefix("SpamPendMember")
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, memberID)
	targetID := CreateTestUser(t, prefix+"_target", utils.SYSTEMROLE_USER)

	body := fmt.Sprintf(`{"userid":%d,"collection":"PendingAdd","reason":"Looks off"}`, targetID)
	req := httptest.NewRequest("POST", "/api/modtools/spammers?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
	assert.NotEqual(t, utils.NEWSFEEDMODSTATUS_SUPPRESSED, newsfeedStatusOf(targetID))
	database.DBConn.Exec("DELETE FROM spam_users WHERE userid = ?", targetID)
}
