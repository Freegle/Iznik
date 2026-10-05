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

const adminMjmlPart = `<mj-section><mj-column><mj-text>Hello <b>formatted</b> world</mj-text></mj-column></mj-section>`

// postAdmin POSTs body to /modtools/admin and returns the status and decoded JSON response.
func postAdmin(t *testing.T, jwt string, body map[string]interface{}) (int, map[string]interface{}) {
	t.Helper()
	j, _ := json2.Marshal(body)
	req := httptest.NewRequest("POST", "/api/modtools/admin?jwt="+jwt, bytes.NewBuffer(j))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	return resp.StatusCode, result
}

// sendAdminTest sends a Test of this content and returns the status and the test token.
func sendAdminTest(t *testing.T, jwt string, content map[string]interface{}, email string) (int, string) {
	t.Helper()
	body := map[string]interface{}{"action": "Test", "email": email}
	for k, v := range content {
		body[k] = v
	}
	status, result := postAdmin(t, jwt, body)
	token, _ := result["testtoken"].(string)
	return status, token
}

// withTestToken sends a Test of the JSON body and returns the body with the token added, so a
// test can then create that ADMIN.
func withTestToken(t *testing.T, jwt string, body string) string {
	t.Helper()
	var content map[string]interface{}
	assert.NoError(t, json2.Unmarshal([]byte(body), &content))
	status, token := sendAdminTest(t, jwt, content, "test-admin@example.com")
	assert.Equal(t, 200, status, "test send should be accepted")
	content["testtoken"] = token
	j, _ := json2.Marshal(content)
	return string(j)
}

func adminMjmlSetup(t *testing.T, name string) (uint64, uint64, string) {
	prefix := uniquePrefix(name)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)
	return modID, groupID, token
}

func TestAdminTestQueuesEmailAndCreateStoresMjml(t *testing.T) {
	modID, groupID, token := adminMjmlSetup(t, "adm_mjml_ok")
	content := map[string]interface{}{
		"groupid": groupID, "subject": fmt.Sprintf("MJML %d", groupID), "text": "Plain version", "mjml": adminMjmlPart,
	}

	status, testtoken := sendAdminTest(t, token, content, "mod-test@example.com")
	assert.Equal(t, 200, status)
	assert.NotEmpty(t, testtoken)

	var task struct {
		Data string
	}
	database.DBConn.Raw("SELECT data FROM background_tasks WHERE task_type = 'email_admin_test' "+
		"AND JSON_EXTRACT(data, '$.user_id') = ? ORDER BY id DESC LIMIT 1", modID).Scan(&task)
	var data map[string]interface{}
	assert.NoError(t, json2.Unmarshal([]byte(task.Data), &data))
	assert.Equal(t, "mod-test@example.com", data["email"])
	assert.Equal(t, adminMjmlPart, data["mjml"])
	assert.Equal(t, "Plain version", data["text"])

	content["testtoken"] = testtoken
	status, result := postAdmin(t, token, content)
	assert.Equal(t, 200, status)
	id := uint64(result["id"].(float64))

	var mjml *string
	database.DBConn.Raw("SELECT mjml FROM admins WHERE id = ?", id).Scan(&mjml)
	assert.NotNil(t, mjml)
	assert.Equal(t, adminMjmlPart, *mjml)

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", id)
	database.DBConn.Exec("DELETE FROM background_tasks WHERE task_type = 'email_admin_test' AND JSON_EXTRACT(data, '$.user_id') = ?", modID)
}

func TestAdminCreateWithoutTestIsRefused(t *testing.T) {
	_, groupID, token := adminMjmlSetup(t, "adm_mjml_notest")
	status, _ := postAdmin(t, token, map[string]interface{}{
		"groupid": groupID, "subject": "No test", "text": "Body",
	})
	assert.Equal(t, 400, status)

	status, _ = postAdmin(t, token, map[string]interface{}{
		"groupid": groupID, "subject": "No test", "text": "Body", "testtoken": "9999999999.forged",
	})
	assert.Equal(t, 400, status, "a forged token must not work")
}

func TestAdminCreateAfterChangingContentNeedsNewTest(t *testing.T) {
	_, groupID, token := adminMjmlSetup(t, "adm_mjml_changed")
	content := map[string]interface{}{"groupid": groupID, "subject": "Changed", "text": "Original"}
	_, testtoken := sendAdminTest(t, token, content, "a@example.com")

	for field, value := range map[string]interface{}{
		"text": "Edited after the test", "subject": "Other", "mjml": adminMjmlPart,
		"ctalink": "https://example.com", "essential": false,
	} {
		changed := map[string]interface{}{"groupid": groupID, "subject": "Changed", "text": "Original", "testtoken": testtoken}
		changed[field] = value
		status, _ := postAdmin(t, token, changed)
		assert.Equal(t, 400, status, "changing %s after the test must need a new test", field)
	}
}

func TestAdminTestTokenIsNotTransferable(t *testing.T) {
	_, groupID, tokenA := adminMjmlSetup(t, "adm_mjml_xfer")
	prefix := uniquePrefix("adm_mjml_xfer_b")
	modB := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, modB, groupID, "Moderator")
	_, tokenB := CreateTestSession(t, modB)

	content := map[string]interface{}{"groupid": groupID, "subject": "Xfer", "text": "Body"}
	_, testtoken := sendAdminTest(t, tokenA, content, "a@example.com")
	content["testtoken"] = testtoken
	status, _ := postAdmin(t, tokenB, content)
	assert.Equal(t, 400, status, "another moderator's test does not authorise this one")
}

func TestAdminTestNeedsOneEmailAddress(t *testing.T) {
	_, groupID, token := adminMjmlSetup(t, "adm_mjml_email")
	content := map[string]interface{}{"groupid": groupID, "subject": "Email", "text": "Body"}
	for _, email := range []string{"", "not-an-address", "a@example.com, b@example.com", "Someone <a@example.com>"} {
		status, testtoken := sendAdminTest(t, token, content, email)
		assert.Equal(t, 400, status, "%q is not one plain address", email)
		assert.Empty(t, testtoken)
	}
}

func TestAdminTestNeedsModOfGroup(t *testing.T) {
	prefix := uniquePrefix("adm_mjml_member")
	userID := CreateTestUser(t, prefix, "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Member")
	_, token := CreateTestSession(t, userID)

	status, _ := sendAdminTest(t, token, map[string]interface{}{"groupid": groupID, "subject": "S", "text": "T"}, "a@example.com")
	assert.Equal(t, 403, status)
}

func TestAdminTextMustBePlain(t *testing.T) {
	_, groupID, token := adminMjmlSetup(t, "adm_mjml_plain")

	for _, text := range []string{"Hello <b>there</b>", "<p>Para</p>", "Line<br>break", "<a href=\"x\">link</a>", "<!-- hidden -->", "<mj-text>x</mj-text>"} {
		status, _ := sendAdminTest(t, token, map[string]interface{}{"groupid": groupID, "subject": "S", "text": text}, "a@example.com")
		assert.Equal(t, 400, status, "%q contains HTML", text)
	}

	// Placeholders in angle brackets, and comparisons, are plain text.
	for _, text := range []string{"Please add <your names here>", "Fewer than 3 < 5 items", "a <3 b"} {
		status, _ := sendAdminTest(t, token, map[string]interface{}{"groupid": groupID, "subject": "S", "text": text}, "a@example.com")
		assert.Equal(t, 200, status, "%q is plain text", text)
	}

	status, _ := sendAdminTest(t, token, map[string]interface{}{"groupid": groupID, "subject": "S", "text": "   ", "mjml": adminMjmlPart}, "a@example.com")
	assert.Equal(t, 400, status, "the text part is mandatory even with MJML")
}

func TestAdminMjmlMustBeBodySections(t *testing.T) {
	_, groupID, token := adminMjmlSetup(t, "adm_mjml_struct")

	for _, mjml := range []string{
		"<mjml><mj-body>" + adminMjmlPart + "</mj-body></mjml>",
		"<mj-head><mj-title>x</mj-title></mj-head>" + adminMjmlPart,
		`<mj-include path="/etc/passwd" />` + adminMjmlPart,
		"<p>Just HTML</p>",
		string(bytes.Repeat([]byte("x"), 300*1024)) + adminMjmlPart,
	} {
		status, _ := sendAdminTest(t, token, map[string]interface{}{"groupid": groupID, "subject": "S", "text": "T", "mjml": mjml}, "a@example.com")
		assert.Equal(t, 400, status, "MJML should be refused: %.60q", mjml)
	}
}

func TestPatchAdminMjml(t *testing.T) {
	modID, groupID, token := adminMjmlSetup(t, "adm_mjml_patch")
	adminID := createTestAdmin(t, modID, groupID, fmt.Sprintf("Patch MJML %d", groupID))

	mjmlJSON, _ := json2.Marshal(adminMjmlPart)
	assert.Equal(t, 200, patchJSON(t, "/api/modtools/admin", token, fmt.Sprintf(`{"id":%d,"mjml":%s}`, adminID, mjmlJSON)))
	var mjml *string
	database.DBConn.Raw("SELECT mjml FROM admins WHERE id = ?", adminID).Scan(&mjml)
	assert.NotNil(t, mjml)
	assert.Equal(t, adminMjmlPart, *mjml)

	assert.Equal(t, 400, patchJSON(t, "/api/modtools/admin", token, fmt.Sprintf(`{"id":%d,"mjml":"<mjml></mjml>"}`, adminID)))
	assert.Equal(t, 400, patchJSON(t, "/api/modtools/admin", token, fmt.Sprintf(`{"id":%d,"text":"<b>bold</b>"}`, adminID)))

	// An empty MJML part removes it.
	assert.Equal(t, 200, patchJSON(t, "/api/modtools/admin", token, fmt.Sprintf(`{"id":%d,"mjml":""}`, adminID)))
	mjml = nil
	database.DBConn.Raw("SELECT mjml FROM admins WHERE id = ?", adminID).Scan(&mjml)
	assert.Nil(t, mjml)

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", adminID)
}

func TestGetAdminReturnsMjml(t *testing.T) {
	modID, groupID, token := adminMjmlSetup(t, "adm_mjml_get")
	adminID := createTestAdmin(t, modID, groupID, fmt.Sprintf("Get MJML %d", groupID))
	database.DBConn.Exec("UPDATE admins SET mjml = ? WHERE id = ?", adminMjmlPart, adminID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin/%d?jwt=%s", adminID, token), nil)
	resp, _ := getApp().Test(req, -1)
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, adminMjmlPart, result["mjml"])

	req = httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/admin?groupid=%d&jwt=%s", groupID, token), nil)
	resp, _ = getApp().Test(req, -1)
	var list []map[string]interface{}
	json2.Unmarshal(rsp(resp), &list)
	assert.Len(t, list, 1)
	assert.Equal(t, adminMjmlPart, list[0]["mjml"])

	database.DBConn.Exec("DELETE FROM admins WHERE id = ?", adminID)
}
