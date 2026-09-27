package test

// Gate tests for the moderator-tooling endpoints section 11.3 of the lockdown plan
// (plans/active/2026-09-27-lockdown-switch.md) refuses outright while "mods" is held:
// admin, comment, group, modconfig, stdmsg and spammers writes. Each test uses
// lockdown.SetTestState to simulate the hold entirely in-process (see the doc comment
// on SetTestState in lockdown/lockdown.go for why: these tests never write a real
// "lockdowns" row, so they cannot race the other packages' test binaries that
// `go test ./...` runs concurrently against the same test database).

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/lockdown"
	"github.com/stretchr/testify/assert"
)

func modsHeld() func() {
	return lockdown.SetTestState(lockdown.State{
		Active:   true,
		Surfaces: map[string]bool{"mods": true},
	})
}

// --- admin.go: PostAdmin/PatchAdmin/DeleteAdmin all refused, the row must not be created ---

func TestLockdownRefusesCreateAdmin(t *testing.T) {
	prefix := uniquePrefix("ld_adm_create")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"groupid":%d,"subject":"Test Subject %s","text":"Test text"}`, groupID, prefix)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+modToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesUpdateAdmin(t *testing.T) {
	prefix := uniquePrefix("ld_adm_upd")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	adminID := createTestAdmin(t, modID, groupID, "Lockdown Update Test "+prefix)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"id":%d,"subject":"Should not apply"}`, adminID)
	req := httptest.NewRequest("PATCH", "/api/modtools/admin?jwt="+modToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesDeleteAdmin(t *testing.T) {
	prefix := uniquePrefix("ld_adm_del")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)
	adminID := createTestAdmin(t, modID, groupID, "Lockdown Delete Test "+prefix)

	restore := modsHeld()
	defer restore()

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/admin?id=%d&jwt=%s", adminID, modToken), nil)
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

// --- comment.go: Create/Edit/Delete all refused ---

func TestLockdownRefusesCommentCreate(t *testing.T) {
	prefix := uniquePrefix("ld_cmwr_create")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	targetID := CreateTestUser(t, prefix+"_target", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, targetID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"userid":%d,"groupid":%d,"user1":"Test comment"}`, targetID, groupID)
	req := httptest.NewRequest("POST", "/api/comment?jwt="+modToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesCommentEdit(t *testing.T) {
	prefix := uniquePrefix("ld_cmwr_edit")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	targetID := CreateTestUser(t, prefix+"_target", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, targetID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	db := database.DBConn
	db.Exec("INSERT INTO users_comments (userid, groupid, byuserid, user1, flag) VALUES (?, ?, ?, 'Original', 0)", targetID, groupID, modID)
	var commentID uint64
	db.Raw("SELECT id FROM users_comments WHERE userid = ? AND groupid = ? ORDER BY id DESC LIMIT 1", targetID, groupID).Scan(&commentID)
	t.Cleanup(func() { db.Exec("DELETE FROM users_comments WHERE id = ?", commentID) })

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"id":%d,"user1":"Should not apply"}`, commentID)
	req := httptest.NewRequest("PATCH", "/api/comment?jwt="+modToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesCommentDelete(t *testing.T) {
	prefix := uniquePrefix("ld_cmwr_del")
	modID := CreateTestUser(t, prefix+"_mod", "User")
	targetID := CreateTestUser(t, prefix+"_target", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, targetID, groupID, "Member")
	_, modToken := CreateTestSession(t, modID)

	db := database.DBConn
	db.Exec("INSERT INTO users_comments (userid, groupid, byuserid, user1, flag) VALUES (?, ?, ?, 'To delete', 0)", targetID, groupID, modID)
	var commentID uint64
	db.Raw("SELECT id FROM users_comments WHERE userid = ? AND groupid = ? ORDER BY id DESC LIMIT 1", targetID, groupID).Scan(&commentID)
	t.Cleanup(func() { db.Exec("DELETE FROM users_comments WHERE id = ?", commentID) })

	restore := modsHeld()
	defer restore()

	resp, _ := getApp().Test(httptest.NewRequest("DELETE", fmt.Sprintf("/api/comment/%d?jwt=%s", commentID, modToken), nil))
	assertLockdownRefused(t, resp)
}

// --- group.go: PatchGroup refused ---

func TestLockdownRefusesPatchGroup(t *testing.T) {
	prefix := uniquePrefix("ld_grpw")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"id":%d,"tagline":"Should not apply"}`, groupID)
	req := httptest.NewRequest("PATCH", "/api/group?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownDoesNotRefusePatchGroupForSupport(t *testing.T) {
	prefix := uniquePrefix("ld_grpw_sup")
	groupID := CreateTestGroup(t, prefix)
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"id":%d,"tagline":"Support Edit"}`, groupID)
	req := httptest.NewRequest("PATCH", "/api/group?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

// --- modconfig.go: Post/Patch/Delete all refused ---

func TestLockdownRefusesPostModConfig(t *testing.T) {
	prefix := uniquePrefix("ld_ModCfgPost")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"name":"%s_newcfg"}`, prefix)
	req := httptest.NewRequest("POST", "/api/modtools/modconfig?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPatchModConfig(t *testing.T) {
	prefix := uniquePrefix("ld_ModCfgPatch")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)
	cfgID := createTestModConfig(t, prefix+"_cfg", modID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"id":%d,"name":"%s_updated"}`, cfgID, prefix)
	req := httptest.NewRequest("PATCH", "/api/modtools/modconfig?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesDeleteModConfig(t *testing.T) {
	// System admins are exempt from GateMod, so use a plain group owner - a
	// non-exempt caller who would otherwise be allowed to delete their own config.
	prefix := uniquePrefix("ld_ModCfgDel")
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, ownerID, groupID, "Owner")
	_, ownerToken := CreateTestSession(t, ownerID)
	cfgID := createTestModConfig(t, prefix+"_cfg", ownerID)

	restore := modsHeld()
	defer restore()

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/modconfig?id=%d&jwt=%s", cfgID, ownerToken), nil)
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)

	restore()
	database.DBConn.Exec("DELETE FROM mod_configs WHERE id = ?", cfgID)
}

// --- stdmsg.go: Post/Patch/Delete all refused ---

func TestLockdownRefusesPostStdMsg(t *testing.T) {
	prefix := uniquePrefix("ld_StdMsgPost")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)
	cfgID := createTestModConfig(t, prefix+"_cfg", modID)

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"configid":%d,"title":"%s_newmsg"}`, cfgID, prefix)
	req := httptest.NewRequest("POST", "/api/modtools/stdmsg?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesPatchStdMsg(t *testing.T) {
	prefix := uniquePrefix("ld_StdMsgPatch")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)
	cfgID := createTestModConfig(t, prefix+"_cfg", modID)
	msgID := createTestStdMsg(t, cfgID, prefix+"_msg")

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"id":%d,"title":"Should not apply"}`, msgID)
	req := httptest.NewRequest("PATCH", "/api/modtools/stdmsg?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesDeleteStdMsg(t *testing.T) {
	prefix := uniquePrefix("ld_StdMsgDel")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, modID, groupID, "Owner")
	_, token := CreateTestSession(t, modID)
	cfgID := createTestModConfig(t, prefix+"_cfg", modID)
	msgID := createTestStdMsg(t, cfgID, prefix+"_msg")

	restore := modsHeld()
	defer restore()

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/stdmsg?id=%d&jwt=%s", msgID, token), nil)
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)

	restore()
	database.DBConn.Exec("DELETE FROM mod_stdmsgs WHERE id = ?", msgID)
	database.DBConn.Exec("DELETE FROM mod_configs WHERE id = ?", cfgID)
}

// --- spammers.go: PATCH/DELETE refused, POST (report) still allowed ---

func TestLockdownRefusesPatchSpammer(t *testing.T) {
	prefix := uniquePrefix("ld_SpamPatch")
	_, token := createSpamAdminUser(t, prefix)
	targetID := CreateTestUser(t, prefix+"_target", "User")
	spamID := createTestSpammer(t, targetID, "PendingAdd", "Suspicious")

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"id":%d,"collection":"Spammer","reason":"Confirmed spam"}`, spamID)
	req := httptest.NewRequest("PATCH", "/api/modtools/spammers?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownRefusesDeleteSpammer(t *testing.T) {
	prefix := uniquePrefix("ld_SpamDel")
	_, token := createSpamAdminUser(t, prefix)
	targetID := CreateTestUser(t, prefix+"_target", "User")
	spamID := createTestSpammer(t, targetID, "Spammer", "Bad actor")

	restore := modsHeld()
	defer restore()

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/modtools/spammers?id=%d&jwt=%s", spamID, token), nil)
	resp, _ := getApp().Test(req)
	assertLockdownRefused(t, resp)
}

func TestLockdownDoesNotRefusePostSpammerReport(t *testing.T) {
	// POST /spammers (reporting as PendingAdd) stays allowed for anyone even while
	// mods is held - only the confirm/reject/delete actions are refused.
	prefix := uniquePrefix("ld_SpamPost")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	targetID := CreateTestUser(t, prefix+"_target", "User")

	restore := modsHeld()
	defer restore()

	body := fmt.Sprintf(`{"userid":%d,"collection":"PendingAdd","reason":"Looks off"}`, targetID)
	req := httptest.NewRequest("POST", "/api/modtools/spammers?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	restore()
	database.DBConn.Exec("DELETE FROM spam_users WHERE userid = ?", targetID)
}

// --- spammers.go: ExportSpammers is a download, refused for everyone including Support ---

func TestLockdownRefusesExportSpammersEvenForSupport(t *testing.T) {
	prefix := uniquePrefix("ld_SpamExport")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)

	restore := lockdown.SetTestState(lockdown.State{
		Active:   true,
		Surfaces: map[string]bool{"export": true},
	})
	defer restore()

	req := httptest.NewRequest("GET", "/api/modtools/spammers/export?jwt="+token, nil)
	resp, _ := getApp().Test(req)
	assertLockdownDownloadRefused(t, resp)
}
