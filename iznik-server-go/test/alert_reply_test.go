package test

import (
	"encoding/base64"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func putAlert(t *testing.T, token string, body string) (int, map[string]interface{}) {
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/modtools/alert?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	return resp.StatusCode, result
}

func postAlertAction(t *testing.T, token string, alertID uint64, action string, body string) (int, map[string]interface{}) {
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/alert/%d/%s?jwt=%s", alertID, action, token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	return resp.StatusCode, result
}

func pendingIDs(t *testing.T, token string) []uint64 {
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/alert/pending?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
	var result struct {
		Alerts []struct {
			ID uint64 `json:"id"`
		} `json:"alerts"`
	}
	json2.Unmarshal(rsp(resp), &result)
	ids := []uint64{}
	for _, a := range result.Alerts {
		ids = append(ids, a.ID)
	}
	return ids
}

func requireReplyAlert(t *testing.T, creator uint64, groupID uint64, subject string) uint64 {
	db := database.DBConn
	assert.NoError(t, db.Exec("INSERT INTO alerts (createdby, groupid, `from`, `to`, subject, text, html, quoted, requirereply, created) VALUES (?, ?, 'support', 'Mods', ?, 'Please look', '<p>Please look</p>', 'The member said hello', 1, NOW())",
		creator, groupID, subject).Error)
	var id uint64
	db.Raw("SELECT id FROM alerts WHERE createdby = ? AND subject = ? ORDER BY id DESC LIMIT 1", creator, subject).Scan(&id)
	assert.Greater(t, id, uint64(0))
	return id
}

func TestCreateAlertWithQuoteAttachmentAndRequiredReply(t *testing.T) {
	prefix := uniquePrefix("AlertQuote")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)
	groupID := CreateTestGroup(t, prefix)

	png := base64.StdEncoding.EncodeToString([]byte("\x89PNG\r\n\x1a\nfake"))
	body := fmt.Sprintf(`{"from":"support","subject":"%s","text":"hi","groupid":%d,"quoted":"Original member email","requirereply":1,"attachments":[{"name":"../../shot one.png","mime":"image/png","data":"%s"}]}`,
		prefix, groupID, png)
	status, result := putAlert(t, token, body)
	assert.Equal(t, 200, status)
	id := uint64(result["id"].(float64))

	db := database.DBConn
	var row struct {
		Quoted       string
		Requirereply int
	}
	db.Table("alerts").Select("quoted, requirereply").Where("id = ?", id).Scan(&row)
	assert.Equal(t, "Original member email", row.Quoted)
	assert.Equal(t, 1, row.Requirereply)

	var att struct {
		Name string
		Mime string
		Size int
	}
	db.Table("alerts_attachments").Select("name, mime, size").Where("alertid = ?", id).Scan(&att)
	assert.Equal(t, "shot one.png", att.Name)
	assert.Equal(t, "image/png", att.Mime)
	assert.Equal(t, 12, att.Size)

	// The public GET never shows the member's email.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/alert/%d", id), nil)
	resp, _ := getApp().Test(req)
	assert.NotContains(t, string(rsp(resp)), "Original member email")
}

func TestCreateAlertRefusesExtrasForAllCommunities(t *testing.T) {
	prefix := uniquePrefix("AlertAll")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	for _, extra := range []string{`"requirereply":1`, `"quoted":"x"`, `"attachments":[{"name":"a.png","mime":"image/png","data":"AAAA"}]`} {
		status, _ := putAlert(t, token, fmt.Sprintf(`{"from":"support","subject":"%s","text":"hi","groupid":"AllFreegle",%s}`, prefix, extra))
		assert.Equal(t, 400, status, extra)
	}
}

func TestCreateAlertRefusesBadAttachments(t *testing.T) {
	prefix := uniquePrefix("AlertBadAtt")
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)
	groupID := CreateTestGroup(t, prefix)

	ok := base64.StdEncoding.EncodeToString([]byte("data"))
	exe := fmt.Sprintf(`{"name":"a.exe","mime":"application/x-msdownload","data":"%s"}`, ok)
	img := fmt.Sprintf(`{"name":"a.png","mime":"image/png","data":"%s"}`, ok)
	big := fmt.Sprintf(`{"name":"big.png","mime":"image/png","data":"%s"}`, base64.StdEncoding.EncodeToString(make([]byte, 2600000)))
	six := strings.Join([]string{img, img, img, img, img, img}, ",")

	for name, atts := range map[string]string{"type": exe, "size": big, "count": six, "garbage": `{"name":"a.png","mime":"image/png","data":"!!!"}`} {
		status, _ := putAlert(t, token, fmt.Sprintf(`{"from":"support","subject":"%s","text":"hi","groupid":%d,"attachments":[%s]}`, prefix, groupID, atts))
		assert.Equal(t, 400, status, name)
	}
}

func TestPendingAlertOnlyForTheCommunitysModerators(t *testing.T) {
	prefix := uniquePrefix("AlertPend")
	creator := CreateTestUser(t, prefix+"_creator", "Support")
	groupID := CreateTestGroup(t, prefix)
	otherGroup := CreateTestGroup(t, prefix+"_other")
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, groupID, "Moderator")
	owner := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, owner, groupID, "Owner")
	member := CreateTestUser(t, prefix+"_member", "User")
	CreateTestMembership(t, member, groupID, "Member")
	elsewhere := CreateTestUser(t, prefix+"_elsewhere", "User")
	CreateTestMembership(t, elsewhere, otherGroup, "Moderator")

	id := requireReplyAlert(t, creator, groupID, prefix)

	for who, want := range map[uint64]bool{mod: true, owner: true, member: false, elsewhere: false, creator: false} {
		_, token := CreateTestSession(t, who)
		assert.Equal(t, want, idInList(pendingIDs(t, token), id), "user %d", who)
	}

	// Not logged in.
	req := httptest.NewRequest("GET", "/api/modtools/alert/pending", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func idInList(ids []uint64, id uint64) bool {
	for _, i := range ids {
		if i == id {
			return true
		}
	}
	return false
}

func TestPendingAlertExpiresAndCancels(t *testing.T) {
	prefix := uniquePrefix("AlertExp")
	creator := CreateTestUser(t, prefix+"_creator", "Support")
	groupID := CreateTestGroup(t, prefix)
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, groupID, "Moderator")
	_, modToken := CreateTestSession(t, mod)
	_, creatorToken := CreateTestSession(t, creator)

	old := requireReplyAlert(t, creator, groupID, prefix+"_old")
	database.DBConn.Exec("UPDATE alerts SET created = NOW() - INTERVAL 15 DAY WHERE id = ?", old)
	assert.False(t, idInList(pendingIDs(t, modToken), old))

	cancelled := requireReplyAlert(t, creator, groupID, prefix+"_cancel")
	assert.True(t, idInList(pendingIDs(t, modToken), cancelled))

	// A moderator cannot cancel; the sender can.
	status, _ := postAlertAction(t, modToken, cancelled, "cancel", "")
	assert.Equal(t, 403, status)
	status, _ = postAlertAction(t, creatorToken, cancelled, "cancel", "")
	assert.Equal(t, 200, status)
	assert.False(t, idInList(pendingIDs(t, modToken), cancelled))
}

func TestReplyToAlertSendsChatAndReleases(t *testing.T) {
	prefix := uniquePrefix("AlertReply")
	creator := CreateTestUser(t, prefix+"_creator", "Support")
	groupID := CreateTestGroup(t, prefix)
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, groupID, "Moderator")
	_, modToken := CreateTestSession(t, mod)
	id := requireReplyAlert(t, creator, groupID, prefix)

	status, _ := postAlertAction(t, modToken, id, "reply", `{"message":"   "}`)
	assert.Equal(t, 400, status)

	status, result := postAlertAction(t, modToken, id, "reply", `{"message":"Thanks, I will phone them"}`)
	assert.Equal(t, 200, status)
	chatid := uint64(result["chatid"].(float64))

	var msg string
	database.DBConn.Table("chat_messages").Select("message").Where("chatid = ? AND userid = ?", chatid, mod).Row().Scan(&msg)
	assert.Contains(t, msg, "Thanks, I will phone them")
	assert.Contains(t, msg, prefix)

	assert.False(t, idInList(pendingIDs(t, modToken), id))

	// Nothing left to reply to.
	status, _ = postAlertAction(t, modToken, id, "reply", `{"message":"again"}`)
	assert.Equal(t, 404, status)

	// The sender sees the count.
	_, creatorToken := CreateTestSession(t, creator)
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/alert/%d?jwt=%s", id, creatorToken), nil)
	resp, _ := getApp().Test(req)
	var r map[string]interface{}
	json2.Unmarshal(rsp(resp), &r)
	stats := r["alert"].(map[string]interface{})["stats"].(map[string]interface{})
	assert.Equal(t, float64(1), stats["replied"])
}

func TestSnoozeAlertOnlyOnce(t *testing.T) {
	prefix := uniquePrefix("AlertSnooze")
	creator := CreateTestUser(t, prefix+"_creator", "Support")
	groupID := CreateTestGroup(t, prefix)
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, groupID, "Moderator")
	_, modToken := CreateTestSession(t, mod)
	id := requireReplyAlert(t, creator, groupID, prefix)

	status, result := postAlertAction(t, modToken, id, "snooze", "")
	assert.Equal(t, 200, status)
	assert.Equal(t, float64(30), result["minutes"])
	assert.False(t, idInList(pendingIDs(t, modToken), id))

	// The snooze runs out: the alert is back, with no second snooze on offer.
	database.DBConn.Exec("UPDATE alerts_acknowledgements SET snoozed_until = NOW() - INTERVAL 1 MINUTE WHERE alertid = ? AND userid = ?", id, mod)
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/alert/pending?jwt=%s", modToken), nil)
	resp, _ := getApp().Test(req)
	var r struct {
		Alerts []struct {
			ID              uint64 `json:"id"`
			Snoozeavailable bool   `json:"snoozeavailable"`
		} `json:"alerts"`
	}
	json2.Unmarshal(rsp(resp), &r)
	found := false
	for _, a := range r.Alerts {
		if a.ID == id {
			found = true
			assert.False(t, a.Snoozeavailable)
		}
	}
	assert.True(t, found)

	status, _ = postAlertAction(t, modToken, id, "snooze", "")
	assert.Equal(t, 403, status)

	// Another moderator has their own snooze.
	mod2 := CreateTestUser(t, prefix+"_mod2", "User")
	CreateTestMembership(t, mod2, groupID, "Moderator")
	_, mod2Token := CreateTestSession(t, mod2)
	status, _ = postAlertAction(t, mod2Token, id, "snooze", "")
	assert.Equal(t, 200, status)
}
