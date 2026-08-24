package test

import (
	"bytes"
	"encoding/base64"
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestCreateTryst(t *testing.T) {
	prefix := uniquePrefix("Tryst")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	// CreateTryst requires a chat room between the users.
	CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")

	body := fmt.Sprintf(`{"user1":%d,"user2":%d,"arrangedfor":"2038-01-19T03:14:06+00:00"}`, user1ID, user2ID)
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/tryst?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Greater(t, result["id"].(float64), float64(0))
}

// TestCreateTrystDuplicateReturnsExistingID pins the fix for a live defect:
// CreateTryst used to run its ON DUPLICATE KEY UPDATE with no
// "id = LAST_INSERT_ID(id)" forcing, so re-arranging the SAME tryst (the
// unique key is (arrangedfor, user1, user2)) made MySQL's LAST_INSERT_ID()
// report 0, and this endpoint handed that straight back to the caller as
// {"id": 0}. Confirmed against the real test DB (both the raw SQL and the
// converted GORM chain) before this test was added: the unfixed ODKU clause
// returns id 0 on the second call, the fixed one returns the original id
// both times. This test proves the same thing through the actual HTTP
// handler, which is what a caller sees.
func TestCreateTrystDuplicateReturnsExistingID(t *testing.T) {
	prefix := uniquePrefix("TrystDup")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	// CreateTryst requires a chat room between the users.
	CreateTestChatRoom(t, user1ID, &user2ID, nil, "User2User")

	body := fmt.Sprintf(`{"user1":%d,"user2":%d,"arrangedfor":"2038-01-19T03:14:06+00:00"}`, user1ID, user2ID)

	req1 := httptest.NewRequest("PUT", fmt.Sprintf("/api/tryst?jwt=%s", token), strings.NewReader(body))
	req1.Header.Set("Content-Type", "application/json")
	resp1, _ := getApp().Test(req1)
	assert.Equal(t, 200, resp1.StatusCode)

	var result1 map[string]interface{}
	json2.Unmarshal(rsp(resp1), &result1)
	assert.Equal(t, float64(0), result1["ret"])
	id1 := result1["id"].(float64)
	assert.Greater(t, id1, float64(0), "first create must return a real id")

	// Same (arrangedfor, user1, user2) triple again - hits the unique key,
	// so this goes through the ON DUPLICATE KEY UPDATE path.
	req2 := httptest.NewRequest("PUT", fmt.Sprintf("/api/tryst?jwt=%s", token), strings.NewReader(body))
	req2.Header.Set("Content-Type", "application/json")
	resp2, _ := getApp().Test(req2)
	assert.Equal(t, 200, resp2.StatusCode)

	var result2 map[string]interface{}
	json2.Unmarshal(rsp(resp2), &result2)
	assert.Equal(t, float64(0), result2["ret"])
	id2 := result2["id"].(float64)
	assert.Greater(t, id2, float64(0), "duplicate create must not return id 0 - this is the bug being fixed")
	assert.Equal(t, id1, id2, "duplicate create must return the SAME id as the original row")
}

func TestGetTrystList(t *testing.T) {
	prefix := uniquePrefix("TrystList")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/tryst?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "trysts")
}

func TestGetTrystSingle(t *testing.T) {
	prefix := uniquePrefix("TrystSingle")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	var trystID uint64
	db.Raw("SELECT id FROM trysts WHERE user1 = ? ORDER BY id DESC LIMIT 1", user1ID).Scan(&trystID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/tryst?id=%d&jwt=%s", trystID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "tryst")

	tryst := result["tryst"].(map[string]interface{})
	assert.Equal(t, float64(trystID), tryst["id"])
}

func TestPatchTryst(t *testing.T) {
	prefix := uniquePrefix("TrystPatch")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	var trystID uint64
	db.Raw("SELECT id FROM trysts WHERE user1 = ? ORDER BY id DESC LIMIT 1", user1ID).Scan(&trystID)

	body := fmt.Sprintf(`{"id":%d,"arrangedfor":"2038-01-20T10:00:00+00:00"}`, trystID)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/tryst?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
}

func TestConfirmTryst(t *testing.T) {
	prefix := uniquePrefix("TrystConf")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	var trystID uint64
	db.Raw("SELECT id FROM trysts WHERE user1 = ? ORDER BY id DESC LIMIT 1", user1ID).Scan(&trystID)

	body := fmt.Sprintf(`{"id":%d,"confirm":true}`, trystID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/tryst?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	// Verify user1confirmed is set.
	var confirmed *string
	db.Raw("SELECT user1confirmed FROM trysts WHERE id = ?", trystID).Scan(&confirmed)
	assert.NotNil(t, confirmed)
}

func TestDeclineTryst(t *testing.T) {
	prefix := uniquePrefix("TrystDecl")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user2ID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	var trystID uint64
	db.Raw("SELECT id FROM trysts WHERE user1 = ? ORDER BY id DESC LIMIT 1", user1ID).Scan(&trystID)

	body := fmt.Sprintf(`{"id":%d,"decline":true}`, trystID)
	req := httptest.NewRequest("POST", fmt.Sprintf("/api/tryst?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	var declined *string
	db.Raw("SELECT user2declined FROM trysts WHERE id = ?", trystID).Scan(&declined)
	assert.NotNil(t, declined)
}

func TestDeleteTryst(t *testing.T) {
	prefix := uniquePrefix("TrystDel")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	var trystID uint64
	db.Raw("SELECT id FROM trysts WHERE user1 = ? ORDER BY id DESC LIMIT 1", user1ID).Scan(&trystID)

	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/tryst?id=%d&jwt=%s", trystID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	var count int64
	db.Raw("SELECT COUNT(*) FROM trysts WHERE id = ?", trystID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestDeleteTrystWithBodyID(t *testing.T) {
	prefix := uniquePrefix("TrystDelBody")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	var trystID uint64
	db.Raw("SELECT id FROM trysts WHERE user1 = ? ORDER BY id DESC LIMIT 1", user1ID).Scan(&trystID)

	body, _ := json2.Marshal(map[string]interface{}{"id": trystID})
	req := httptest.NewRequest("DELETE", fmt.Sprintf("/api/tryst?jwt=%s", token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	var count int64
	db.Raw("SELECT COUNT(*) FROM trysts WHERE id = ?", trystID).Scan(&count)
	assert.Equal(t, int64(0), count)
}

func TestTrystPermissionDenied(t *testing.T) {
	prefix := uniquePrefix("TrystPerm")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	_, otherToken := CreateTestSession(t, otherID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	var trystID uint64
	db.Raw("SELECT id FROM trysts WHERE user1 = ? ORDER BY id DESC LIMIT 1", user1ID).Scan(&trystID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/tryst?id=%d&jwt=%s", trystID, otherToken), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestGetTrystSingleIncludesCalendarLink(t *testing.T) {
	prefix := uniquePrefix("TrystCal")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	var trystID uint64
	db.Raw("SELECT id FROM trysts WHERE user1 = ? ORDER BY id DESC LIMIT 1", user1ID).Scan(&trystID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/tryst?id=%d&jwt=%s", trystID, token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Contains(t, result, "tryst")

	tryst := result["tryst"].(map[string]interface{})
	calLink, ok := tryst["calendarLink"].(string)
	assert.True(t, ok, "calendarLink should be a string")

	// AddToCalendar.vue's download() extracts and decodes this the same way.
	u, err := url.Parse(calLink)
	assert.NoError(t, err)
	encoded := u.Query().Get("data")
	assert.NotEmpty(t, encoded, "calendarLink should have a data= param the frontend can parse")

	decoded, err := base64.RawURLEncoding.DecodeString(encoded)
	assert.NoError(t, err)

	var eventData map[string]string
	assert.NoError(t, json2.Unmarshal(decoded, &eventData))
	assert.Contains(t, eventData["name"], "Freegle", "calendarLink event data should mention Freegle")
}

func TestGetTrystListIncludesCalendarLink(t *testing.T) {
	prefix := uniquePrefix("TrystCalList")
	user1ID := CreateTestUser(t, prefix+"_u1", "User")
	user2ID := CreateTestUser(t, prefix+"_u2", "User")
	_, token := CreateTestSession(t, user1ID)

	db := database.DBConn
	db.Exec("INSERT INTO trysts (user1, user2, arrangedfor) VALUES (?, ?, '2038-01-19 03:14:06')",
		user1ID, user2ID)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/tryst?jwt=%s", token), nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])

	trysts := result["trysts"].([]interface{})
	assert.GreaterOrEqual(t, len(trysts), 1)

	first := trysts[0].(map[string]interface{})
	calLink, ok := first["calendarLink"].(string)
	assert.True(t, ok, "calendarLink should be present in tryst list items")

	u, err := url.Parse(calLink)
	assert.NoError(t, err)
	assert.NotEmpty(t, u.Query().Get("data"), "calendarLink should have a data= param the frontend can parse")
}

func TestGetTrystV2Path(t *testing.T) {
	req := httptest.NewRequest("GET", "/apiv2/tryst", nil)
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}
