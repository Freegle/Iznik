package test

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/paf"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

const pafTestKey = "AB1 / CD2 / EF3 / GH4 / JK5 / LM6 / NP7 / QR8"

func pafRequest(t *testing.T, method string, token string, body string) (int, []byte) {
	url := "/api/sysadmin/paf"
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest(method, url, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	assert.NoError(t, err)
	data, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, data
}

// clearPafImports empties paf_imports, which holds at most one unfinished import at a time,
// before and after each test that needs a known starting point.
func clearPafImports(t *testing.T) {
	database.DBConn.Table("paf_imports").Where("1 = 1").Delete(nil)
	t.Cleanup(func() { database.DBConn.Table("paf_imports").Where("1 = 1").Delete(nil) })
}

func TestPafImportsNeedAdmin(t *testing.T) {
	prefix := uniquePrefix("pafauth")

	status, _ := pafRequest(t, "GET", "", "")
	assert.Equal(t, 401, status)

	supportID := CreateTestUser(t, prefix+"_support", utils.SYSTEMROLE_SUPPORT)
	_, supportToken := CreateTestSession(t, supportID)

	status, _ = pafRequest(t, "GET", supportToken, "")
	assert.Equal(t, 403, status)

	status, _ = pafRequest(t, "POST", supportToken, `{"key":"`+pafTestKey+`"}`)
	assert.Equal(t, 403, status)
}

func TestPafStartImportQueuesItAndHidesTheKey(t *testing.T) {
	clearPafImports(t)
	prefix := uniquePrefix("pafstart")
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	_, token := CreateTestSession(t, adminID)

	status, body := pafRequest(t, "POST", token, `{"key":"`+pafTestKey+`","edition":"y26m08"}`)
	assert.Equal(t, 200, status, string(body))
	assert.NotContains(t, string(body), "AB1CD2")

	var created paf.Import
	assert.NoError(t, json.Unmarshal(body, &created))
	assert.Equal(t, "queued", created.Status)
	assert.Equal(t, "Y26M08", *created.Edition)
	assert.Equal(t, adminID, *created.RequestedBy)

	// The key is stored for the batch with the separators taken out.
	var key string
	database.DBConn.Table("paf_imports").Select("unlock_key").Where("id = ?", created.ID).Scan(&key)
	assert.Equal(t, "AB1CD2EF3GH4JK5LM6NP7QR8", key)

	status, body = pafRequest(t, "GET", token, "")
	assert.Equal(t, 200, status)
	assert.NotContains(t, string(body), "unlock_key")
	var list []paf.Import
	assert.NoError(t, json.Unmarshal(body, &list))
	assert.Len(t, list, 1)
	assert.Equal(t, created.ID, list[0].ID)

	// Only one at a time.
	status, body = pafRequest(t, "POST", token, `{"key":"`+pafTestKey+`"}`)
	assert.Equal(t, 409, status)
	assert.Contains(t, string(body), "already in progress")
}

func TestPafStartImportReplacesOneThatHasStalled(t *testing.T) {
	clearPafImports(t)
	prefix := uniquePrefix("pafstale")
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	db.Table("paf_imports").Create(map[string]interface{}{
		"status":     "applying",
		"unlock_key": "OLDKEY",
		"created_at": time.Now().Add(-3 * time.Hour),
		"updated_at": time.Now().Add(-2 * time.Hour),
	})

	status, body := pafRequest(t, "POST", token, `{"key":"`+pafTestKey+`"}`)
	assert.Equal(t, 200, status, string(body))

	var stale struct {
		Status    string
		UnlockKey *string
		Error     string
	}
	db.Table("paf_imports").Select("status, unlock_key, error").Where("status <> ?", "queued").Scan(&stale)
	assert.Equal(t, "failed", stale.Status)
	assert.Nil(t, stale.UnlockKey)
	assert.Contains(t, stale.Error, "Stopped making progress")
}

func TestPafStartImportChecksItsInput(t *testing.T) {
	clearPafImports(t)
	prefix := uniquePrefix("pafinput")
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	_, token := CreateTestSession(t, adminID)

	status, body := pafRequest(t, "POST", token, `{"key":"AB1 / CD2"}`)
	assert.Equal(t, 400, status)
	assert.Contains(t, string(body), "24 letters and numbers")

	status, body = pafRequest(t, "POST", token, `{"key":"`+pafTestKey+`","edition":"August"}`)
	assert.Equal(t, 400, status)
	assert.Contains(t, string(body), "Y26M08")

	status, _ = pafRequest(t, "POST", token, `not json`)
	assert.Equal(t, 400, status)

	var count int64
	database.DBConn.Table("paf_imports").Count(&count)
	assert.Equal(t, int64(0), count)
}

func TestPafListImportsIsEmptyListWhenNone(t *testing.T) {
	clearPafImports(t)
	prefix := uniquePrefix("pafempty")
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	_, token := CreateTestSession(t, adminID)

	status, body := pafRequest(t, "GET", token, "")
	assert.Equal(t, 200, status)
	assert.Equal(t, "[]", string(body))
}

func pafLatestRequest(t *testing.T, token string) (int, paf.Latest) {
	req := httptest.NewRequest("GET", "/api/sysadmin/paf/latest?jwt="+token, nil)
	resp, err := getApp().Test(req, 10000)
	assert.NoError(t, err)
	var latest paf.Latest
	data, _ := io.ReadAll(resp.Body)
	json.Unmarshal(data, &latest)
	return resp.StatusCode, latest
}

// The latest release on AFD's site is what paf:check-latest last stored in the config row.
func TestPafLatestReadsWhatTheBatchStored(t *testing.T) {
	prefix := uniquePrefix("paflatest")
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	_, token := CreateTestSession(t, adminID)
	db := database.DBConn
	db.Exec("DELETE FROM config WHERE `key` = 'paf_latest'")
	t.Cleanup(func() { db.Exec("DELETE FROM config WHERE `key` = 'paf_latest'") })

	status, latest := pafLatestRequest(t, token)
	assert.Equal(t, 200, status)
	assert.Equal(t, "Not checked yet", latest.Error)

	db.Exec("INSERT INTO config (`key`, value) VALUES ('paf_latest', ?)",
		`{"published":"2026-10-03","size":440401920,"checked_at":"2026-10-20T09:23:00+00:00"}`)
	status, latest = pafLatestRequest(t, token)
	assert.Equal(t, 200, status)
	assert.Equal(t, "2026-10-03", latest.Published)
	assert.Equal(t, int64(440401920), latest.Size)
	assert.Empty(t, latest.Error)

	supportID := CreateTestUser(t, prefix+"_support", utils.SYSTEMROLE_SUPPORT)
	_, supportToken := CreateTestSession(t, supportID)
	status, _ = pafLatestRequest(t, supportToken)
	assert.Equal(t, 403, status)
}

// An import lists the date of the release it took, once the batch has recorded it.
func TestPafListImportsCarriesTheReleaseDate(t *testing.T) {
	clearPafImports(t)
	prefix := uniquePrefix("pafrelease")
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	_, token := CreateTestSession(t, adminID)
	database.DBConn.Exec("INSERT INTO paf_imports (status, release_published, created_at, updated_at) VALUES ('done', '2026-10-03', NOW(), NOW())")

	status, body := pafRequest(t, "GET", token, "")
	assert.Equal(t, 200, status)
	var imports []paf.Import
	assert.NoError(t, json.Unmarshal(body, &imports))
	if assert.Len(t, imports, 1) && assert.NotNil(t, imports[0].ReleasePublished) {
		assert.Equal(t, "2026-10-03", *imports[0].ReleasePublished)
	}
}
