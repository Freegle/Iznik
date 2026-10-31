package paf

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"gorm.io/gorm"

	"github.com/freegle/iznik-server-go/database"
)

func init() {
	database.InitDatabase()
}

const goodKey = "ABCDEFGHIJKLMNOPQRSTUVWX"

// withTx swaps database.DBConn for a transaction that is rolled back at the end of the test, so
// nothing the handlers write (or the fixtures below) survives, and nothing already in the
// paf_imports or config tables can interfere.
func withTx(t *testing.T) *gorm.DB {
	orig := database.DBConn
	tx := orig.Begin()
	require.NoError(t, tx.Error)
	database.DBConn = tx
	t.Cleanup(func() {
		database.DBConn = orig
		tx.Rollback()
	})
	return tx
}

// session creates a user with the given systemrole and a session row, returning the user id and
// the Authorization2 header value.
func session(t *testing.T, tx *gorm.DB, role string) (uint64, string) {
	require.NoError(t, tx.Exec("INSERT INTO users (firstname, lastname, fullname, systemrole) VALUES ('Test', 'Paf', 'Test Paf', ?)", role).Error)
	var uid uint64
	tx.Raw("SELECT id FROM users ORDER BY id DESC LIMIT 1").Scan(&uid)
	require.NotZero(t, uid)
	require.NoError(t, tx.Exec("INSERT INTO sessions (userid, series, token, date, lastactive) VALUES (?, ?, 'paftoken', NOW(), NOW())", uid, uid).Error)
	var sid uint64
	tx.Raw("SELECT id FROM sessions ORDER BY id DESC LIMIT 1").Scan(&sid)
	require.NotZero(t, sid)
	return uid, fmt.Sprintf(`{"id":%d,"series":%d,"token":"paftoken"}`, sid, uid)
}

func newApp() *fiber.App {
	app := fiber.New()
	app.Get("/sysadmin/paf", ListImports)
	app.Get("/sysadmin/paf/latest", GetLatest)
	app.Post("/sysadmin/paf", StartImport)
	return app
}

func do(t *testing.T, app *fiber.App, method, url, body, auth string) (int, []byte) {
	req := httptest.NewRequest(method, url, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	if auth != "" {
		req.Header.Set("Authorization2", auth)
	}
	resp, err := app.Test(req, 30000)
	require.NoError(t, err)
	b, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, b
}

func TestPaf_NotAdmin(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "User")
	app := newApp()

	for _, tc := range []struct{ name, method, url, body string }{
		{"list", "GET", "/sysadmin/paf", ""},
		{"latest", "GET", "/sysadmin/paf/latest", ""},
		{"start", "POST", "/sysadmin/paf", `{"key":"` + goodKey + `"}`},
	} {
		t.Run(tc.name, func(t *testing.T) {
			code, b := do(t, app, tc.method, tc.url, tc.body, auth)
			assert.Equal(t, fiber.StatusForbidden, code)
			assert.Contains(t, string(b), "Admin required")
		})
	}
}

func TestPaf_SupportIsNotAdmin(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Support")
	code, _ := do(t, newApp(), "GET", "/sysadmin/paf", "", auth)
	assert.Equal(t, fiber.StatusForbidden, code)
}

func TestPaf_BadSessionIsUnauthorised(t *testing.T) {
	withTx(t)
	code, b := do(t, newApp(), "GET", "/sysadmin/paf", "", `{"id":999999999,"token":"nope"}`)
	assert.Equal(t, fiber.StatusUnauthorized, code)
	assert.Contains(t, string(b), "Not logged in")
}

func TestListImports(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Admin")
	app := newApp()

	// Empty table: an empty array, not null.
	require.NoError(t, tx.Exec("DELETE FROM paf_imports").Error)
	code, b := do(t, app, "GET", "/sysadmin/paf", "", auth)
	assert.Equal(t, fiber.StatusOK, code)
	assert.Equal(t, "[]", strings.TrimSpace(string(b)))

	// Rows come back newest first, never include the key, and are capped at ten.
	for i := 1; i <= 12; i++ {
		require.NoError(t, tx.Exec("INSERT INTO paf_imports (status, edition, unlock_key, detail, release_published, created_at, updated_at) VALUES ('done', ?, 'SECRETKEY', ?, '2026-08-01', NOW(), NOW())",
			fmt.Sprintf("Y26M%02d", i), fmt.Sprintf("row %d", i)).Error)
	}
	code, b = do(t, app, "GET", "/sysadmin/paf", "", auth)
	assert.Equal(t, fiber.StatusOK, code)
	assert.NotContains(t, string(b), "SECRETKEY")
	var got []Import
	require.NoError(t, json.Unmarshal(b, &got))
	require.Len(t, got, 10)
	assert.Equal(t, "row 12", *got[0].Detail)
	assert.Equal(t, "row 3", *got[9].Detail)
	for i := 1; i < len(got); i++ {
		assert.Greater(t, got[i-1].ID, got[i].ID)
	}
	assert.Equal(t, "Y26M12", *got[0].Edition)
	assert.Equal(t, "2026-08-01", *got[0].ReleasePublished)
	assert.Equal(t, "done", got[0].Status)
}

func TestGetLatest(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Admin")
	app := newApp()

	get := func() Latest {
		code, b := do(t, app, "GET", "/sysadmin/paf/latest", "", auth)
		assert.Equal(t, fiber.StatusOK, code)
		var l Latest
		require.NoError(t, json.Unmarshal(b, &l))
		return l
	}

	require.NoError(t, tx.Exec("DELETE FROM config WHERE `key` = 'paf_latest'").Error)
	assert.Equal(t, Latest{Error: "Not checked yet"}, get(), "no row")

	require.NoError(t, tx.Exec("INSERT INTO config (`key`, value) VALUES ('paf_latest', 'not json{')").Error)
	assert.Equal(t, Latest{Error: "Not checked yet"}, get(), "invalid JSON")

	require.NoError(t, tx.Exec("UPDATE config SET value = '' WHERE `key` = 'paf_latest'").Error)
	assert.Equal(t, Latest{Error: "Not checked yet"}, get(), "empty value")

	require.NoError(t, tx.Exec("UPDATE config SET value = ? WHERE `key` = 'paf_latest'",
		`{"published":"2026-08-01","size":12345,"checked_at":"2026-08-02T03:04:05Z"}`).Error)
	assert.Equal(t, Latest{Published: "2026-08-01", Size: 12345, CheckedAt: "2026-08-02T03:04:05Z"}, get(), "valid")

	require.NoError(t, tx.Exec("UPDATE config SET value = ? WHERE `key` = 'paf_latest'", `{"error":"AFD down"}`).Error)
	assert.Equal(t, Latest{Error: "AFD down"}, get(), "stored error")
}

func TestStartImport_InvalidBody(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Admin")
	req := httptest.NewRequest("POST", "/sysadmin/paf", strings.NewReader("this is not json"))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization2", auth)
	resp, err := newApp().Test(req, 30000)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	b, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(b), "Invalid request body")
}

func TestStartImport_KeyValidation(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Admin")
	app := newApp()

	tests := []struct {
		name    string
		key     string
		wantOK  bool
		wantKey string
	}{
		{"empty", "", false, ""},
		{"too short", "ABCDEFGHIJKLMNOPQRSTUVW", false, ""},
		{"too long", "ABCDEFGHIJKLMNOPQRSTUVWXY", false, ""},
		{"only punctuation", "/ / / / / /", false, ""},
		{"exact", goodKey, true, goodKey},
		{"lowercase spaces and slashes", "ab1 / cd2 / ef3 / gh4 / ij5 / kl6 / mn7 / op8", true, "AB1CD2EF3GH4IJ5KL6MN7OP8"},
		{"punctuation stripped to length", "-" + goodKey + "!", true, goodKey},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			require.NoError(t, tx.Exec("DELETE FROM paf_imports").Error)
			body, _ := json.Marshal(StartImportRequest{Key: tc.key})
			code, b := do(t, app, "POST", "/sysadmin/paf", string(body), auth)
			if !tc.wantOK {
				assert.Equal(t, fiber.StatusBadRequest, code)
				assert.Contains(t, string(b), "24 letters and numbers")
				var n int64
				tx.Raw("SELECT COUNT(*) FROM paf_imports").Scan(&n)
				assert.Zero(t, n)
				return
			}
			assert.Equal(t, fiber.StatusOK, code, string(b))
			var stored string
			tx.Raw("SELECT unlock_key FROM paf_imports ORDER BY id DESC LIMIT 1").Scan(&stored)
			assert.Equal(t, tc.wantKey, stored)
			assert.NotContains(t, string(b), tc.wantKey, "response never echoes the key")
		})
	}
}

func TestStartImport_EditionValidation(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Admin")
	app := newApp()

	tests := []struct {
		name        string
		edition     string
		wantOK      bool
		wantEdition string
	}{
		{"empty", "", true, ""},
		{"whitespace only", "   ", true, ""},
		{"valid", "Y26M08", true, "Y26M08"},
		{"lowercase and padded", "  y26m08 ", true, "Y26M08"},
		{"malformed 2026M08", "2026M08", false, ""},
		{"missing Y", "26M08", false, ""},
		{"too many digits", "Y226M08", false, ""},
		{"trailing text", "Y26M08x", false, ""},
		{"words", "latest", false, ""},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			require.NoError(t, tx.Exec("DELETE FROM paf_imports").Error)
			body, _ := json.Marshal(StartImportRequest{Key: goodKey, Edition: tc.edition})
			code, b := do(t, app, "POST", "/sysadmin/paf", string(body), auth)
			if !tc.wantOK {
				assert.Equal(t, fiber.StatusBadRequest, code)
				assert.Contains(t, string(b), "Y26M08")
				return
			}
			assert.Equal(t, fiber.StatusOK, code, string(b))
			var imp Import
			require.NoError(t, json.Unmarshal(b, &imp))
			if tc.wantEdition == "" {
				assert.Nil(t, imp.Edition)
			} else if assert.NotNil(t, imp.Edition) {
				assert.Equal(t, tc.wantEdition, *imp.Edition)
			}
		})
	}
}

func TestStartImport_Creates(t *testing.T) {
	tx := withTx(t)
	uid, auth := session(t, tx, "Admin")
	require.NoError(t, tx.Exec("DELETE FROM paf_imports").Error)

	code, b := do(t, newApp(), "POST", "/sysadmin/paf", `{"key":"`+goodKey+`","edition":"Y26M08"}`, auth)
	require.Equal(t, fiber.StatusOK, code, string(b))
	var imp Import
	require.NoError(t, json.Unmarshal(b, &imp))
	assert.NotZero(t, imp.ID)
	assert.Equal(t, "queued", imp.Status)
	assert.Equal(t, "Y26M08", *imp.Edition)
	assert.Equal(t, "Waiting to start", *imp.Detail)
	assert.Equal(t, uid, *imp.RequestedBy)
	assert.NotNil(t, imp.CreatedAt)
	assert.NotNil(t, imp.UpdatedAt)

	var key string
	tx.Raw("SELECT unlock_key FROM paf_imports WHERE id = ?", imp.ID).Scan(&key)
	assert.Equal(t, goodKey, key)
}

func TestStartImport_ActiveConflict(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Admin")
	app := newApp()
	body := `{"key":"` + goodKey + `"}`

	for _, status := range []string{"queued", "downloading", "extracting", "comparing", "fetching", "applying"} {
		t.Run(status, func(t *testing.T) {
			require.NoError(t, tx.Exec("DELETE FROM paf_imports").Error)
			require.NoError(t, tx.Exec("INSERT INTO paf_imports (status, created_at, updated_at) VALUES (?, NOW(), NOW())", status).Error)
			code, b := do(t, app, "POST", "/sysadmin/paf", body, auth)
			assert.Equal(t, fiber.StatusConflict, code)
			assert.Contains(t, string(b), "already in progress")
			var n int64
			tx.Raw("SELECT COUNT(*) FROM paf_imports").Scan(&n)
			assert.EqualValues(t, 1, n, "no new row")
		})
	}
}

func TestStartImport_FinishedImportsDoNotBlock(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Admin")
	require.NoError(t, tx.Exec("DELETE FROM paf_imports").Error)
	require.NoError(t, tx.Exec("INSERT INTO paf_imports (status, created_at, updated_at) VALUES ('done', NOW(), NOW()), ('failed', NOW(), NOW())").Error)

	code, b := do(t, newApp(), "POST", "/sysadmin/paf", `{"key":"`+goodKey+`"}`, auth)
	assert.Equal(t, fiber.StatusOK, code, string(b))
}

func TestStartImport_StaleImportReplaced(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Admin")
	require.NoError(t, tx.Exec("DELETE FROM paf_imports").Error)
	stale := time.Now().Add(-staleAfter - 5*time.Minute).UTC().Format("2006-01-02 15:04:05")
	require.NoError(t, tx.Exec("INSERT INTO paf_imports (status, unlock_key, created_at, updated_at) VALUES ('applying', 'OLDKEY', ?, ?)", stale, stale).Error)
	var staleID uint64
	tx.Raw("SELECT id FROM paf_imports ORDER BY id DESC LIMIT 1").Scan(&staleID)

	code, b := do(t, newApp(), "POST", "/sysadmin/paf", `{"key":"`+goodKey+`"}`, auth)
	require.Equal(t, fiber.StatusOK, code, string(b))
	var imp Import
	require.NoError(t, json.Unmarshal(b, &imp))
	assert.Greater(t, imp.ID, staleID)
	assert.Equal(t, "queued", imp.Status)

	var old struct {
		Status    string
		UnlockKey *string
		Error     *string
		Finished  *time.Time
	}
	tx.Raw("SELECT status, unlock_key, error, finished_at AS finished FROM paf_imports WHERE id = ?", staleID).Scan(&old)
	assert.Equal(t, "failed", old.Status)
	assert.Nil(t, old.UnlockKey, "old key is cleared")
	require.NotNil(t, old.Error)
	assert.Contains(t, *old.Error, "Stopped making progress")
	assert.NotNil(t, old.Finished)
}

func TestStartImport_StaleAndFreshStillConflicts(t *testing.T) {
	tx := withTx(t)
	_, auth := session(t, tx, "Admin")
	require.NoError(t, tx.Exec("DELETE FROM paf_imports").Error)
	stale := time.Now().Add(-2 * time.Hour).UTC().Format("2006-01-02 15:04:05")
	require.NoError(t, tx.Exec("INSERT INTO paf_imports (status, created_at, updated_at) VALUES ('applying', ?, ?)", stale, stale).Error)
	require.NoError(t, tx.Exec("INSERT INTO paf_imports (status, created_at, updated_at) VALUES ('queued', NOW(), NOW())").Error)

	code, _ := do(t, newApp(), "POST", "/sysadmin/paf", `{"key":"`+goodKey+`"}`, auth)
	assert.Equal(t, fiber.StatusConflict, code)
}
