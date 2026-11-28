package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

func stdMsgBody(t *testing.T, id uint64) string {
	var body string
	database.DBConn.Raw("SELECT body FROM mod_stdmsgs WHERE id = ?", id).Scan(&body)
	return body
}

func stdMsgCount(id uint64) int64 {
	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM mod_stdmsgs WHERE id = ?", id).Scan(&count)
	return count
}

func patchStdMsgAs(token string, id uint64, body string) int {
	payload := fmt.Sprintf(`{"id":%d,"body":"%s"}`, id, body)
	req := httptest.NewRequest("PATCH", "/api/modtools/stdmsg?jwt="+token, strings.NewReader(payload))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	return resp.StatusCode
}

func deleteStdMsgAs(token string, id uint64) int {
	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/stdmsg?id=%d&jwt=%s", id, token), nil)
	resp, _ := getApp().Test(req)
	return resp.StatusCode
}

// A standard message is a moderator's canned reply. A member who moderates nothing must not be
// able to rewrite or delete one, even when its config is not protected.
func TestStdMsgPatchAndDeleteRefuseAPlainMember(t *testing.T) {
	prefix := uniquePrefix("StdMsgPlain")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, ownerID, groupID, utils.ROLE_OWNER)
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	_, memberToken := CreateTestSession(t, memberID)

	cfgID := createTestModConfig(t, prefix+"_cfg", ownerID)
	msgID := createTestStdMsg(t, cfgID, prefix+"_msg")

	assert.Equal(t, 403, patchStdMsgAs(memberToken, msgID, "send me your bank details"))
	assert.Equal(t, "", stdMsgBody(t, msgID), "a plain member must not change the canned reply")

	assert.Equal(t, 403, deleteStdMsgAs(memberToken, msgID))
	assert.Equal(t, int64(1), stdMsgCount(msgID), "a plain member must not delete the canned reply")
}

// A moderator of an unrelated community cannot see another team's unprotected config, so cannot
// change or delete its standard messages either.
func TestStdMsgPatchAndDeleteRefuseAModeratorWhoCannotSeeTheConfig(t *testing.T) {
	prefix := uniquePrefix("StdMsgUnrelated")
	groupID := CreateTestGroup(t, prefix)
	otherGroupID := CreateTestGroup(t, prefix+"_other")
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, ownerID, groupID, utils.ROLE_OWNER)
	strangerID := CreateTestUser(t, prefix+"_stranger", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, strangerID, otherGroupID, utils.ROLE_MODERATOR)
	_, strangerToken := CreateTestSession(t, strangerID)

	cfgID := createTestModConfig(t, prefix+"_cfg", ownerID)
	database.DBConn.Exec("UPDATE memberships SET configid = ? WHERE userid = ? AND groupid = ?", cfgID, ownerID, groupID)
	msgID := createTestStdMsg(t, cfgID, prefix+"_msg")

	assert.Equal(t, 403, patchStdMsgAs(strangerToken, msgID, "changed by a stranger"))
	assert.Equal(t, "", stdMsgBody(t, msgID))

	assert.Equal(t, 403, deleteStdMsgAs(strangerToken, msgID))
	assert.Equal(t, int64(1), stdMsgCount(msgID))
}

// Adding a standard message changes the config, so it follows the same rule as editing one: a
// protected config belongs to its creator.
func TestStdMsgPostRefusedForAProtectedConfigOfAnotherModerator(t *testing.T) {
	prefix := uniquePrefix("StdMsgPostProt")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, ownerID, groupID, utils.ROLE_OWNER)
	otherID := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, otherID, groupID, utils.ROLE_MODERATOR)
	_, otherToken := CreateTestSession(t, otherID)

	cfgID := createTestModConfig(t, prefix+"_cfg", ownerID)
	database.DBConn.Exec("UPDATE mod_configs SET protected = 1 WHERE id = ?", cfgID)

	title := prefix + "_injected"
	body := fmt.Sprintf(`{"configid":%d,"title":"%s"}`, cfgID, title)
	req := httptest.NewRequest("POST", "/api/modtools/stdmsg?jwt="+otherToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)

	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM mod_stdmsgs WHERE configid = ? AND title = ?", cfgID, title).Scan(&count)
	assert.Equal(t, int64(0), count, "no standard message may be added to another moderator's protected config")
}

// A standard message must belong to a config that exists.
func TestStdMsgPostRefusedForAConfigThatDoesNotExist(t *testing.T) {
	prefix := uniquePrefix("StdMsgPostNoCfg")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, modID, groupID, utils.ROLE_OWNER)
	_, token := CreateTestSession(t, modID)

	title := prefix + "_orphan"
	body := fmt.Sprintf(`{"configid":%d,"title":"%s"}`, 999999999, title)
	req := httptest.NewRequest("POST", "/api/modtools/stdmsg?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 404, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(2), result["ret"])

	var count int64
	database.DBConn.Raw("SELECT COUNT(*) FROM mod_stdmsgs WHERE title = ?", title).Scan(&count)
	assert.Equal(t, int64(0), count)
}
