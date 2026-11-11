package config

// Tests for the DB-backed handlers, run against an in-memory SQLite database
// driven through GORM's MySQL dialector, so no Docker/MySQL is needed. The
// dialector emits backtick-quoted identifiers, which SQLite accepts.
// PatchAdminConfig's upsert uses MySQL's ON DUPLICATE KEY syntax, which SQLite
// rejects; the handler ignores the write error, so those tests assert on the
// HTTP response only.

import (
	"database/sql"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
	"gorm.io/driver/mysql"
	"gorm.io/gorm"
	"gorm.io/gorm/logger"
	_ "modernc.org/sqlite"
)

func useSQLite(t *testing.T) *sql.DB {
	t.Helper()

	sqlDB, err := sql.Open("sqlite", ":memory:")
	require.NoError(t, err)
	// An in-memory database exists per connection, so pin to one.
	sqlDB.SetMaxOpenConns(1)

	gdb, err := gorm.Open(mysql.New(mysql.Config{Conn: sqlDB, SkipInitializeWithVersion: true}),
		&gorm.Config{Logger: logger.Discard})
	require.NoError(t, err)

	for _, ddl := range []string{
		"CREATE TABLE config (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE, value TEXT)",
		"CREATE TABLE users (id INTEGER PRIMARY KEY, systemrole TEXT)",
		"CREATE TABLE sessions (id INTEGER PRIMARY KEY, userid INTEGER)",
	} {
		_, err := sqlDB.Exec(ddl)
		require.NoError(t, err)
	}

	old := database.DBConn
	database.DBConn = gdb
	t.Cleanup(func() {
		database.DBConn = old
		sqlDB.Close()
	})

	return sqlDB
}

func body(t *testing.T, resp *http.Response) string {
	t.Helper()
	b, err := io.ReadAll(resp.Body)
	require.NoError(t, err)
	return string(b)
}

func makeJWT(t *testing.T, userID, sessionID uint64) string {
	t.Helper()
	t.Setenv("JWT_SECRET", "config-unit-test-secret")
	tok := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"id":        fmt.Sprint(userID),
		"sessionid": fmt.Sprint(sessionID),
		"exp":       float64(time.Now().Add(time.Hour).Unix()),
	})
	s, err := tok.SignedString([]byte(os.Getenv("JWT_SECRET")))
	require.NoError(t, err)
	return s
}

func doAuth(t *testing.T, app *fiber.App, method, path, jwtStr string) *http.Response {
	t.Helper()
	return doJSONAuth(t, app, method, path, "", jwtStr)
}

func doJSONAuth(t *testing.T, app *fiber.App, method, path, reqBody, jwtStr string) *http.Response {
	t.Helper()
	var r io.Reader
	if reqBody != "" {
		r = strings.NewReader(reqBody)
	}
	req, err := http.NewRequest(method, path, r)
	require.NoError(t, err)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", jwtStr)
	resp, err := app.Test(req)
	require.NoError(t, err)
	return resp
}

// ---------------------------------------------------------------------------
// isPublicConfigKey
// ---------------------------------------------------------------------------

func TestIsPublicConfigKey_Table(t *testing.T) {
	tests := []struct {
		key  string
		want bool
	}{
		{"ads_enabled", true},
		{"ux_experiments", true},
		{"app_fd_version_latest", true},
		{"app_mt_version_required", true},
		{"", false},
		{"UX_EXPERIMENTS", false},
		{"ux_experiments_secret", false},
		{"ads_enabled2", false},
		{"app_fd_version", false},
		{"xapp_fd_version_latest", false},
		{"app_other_version_latest", false},
		{"mail_password", false},
	}
	for _, tc := range tests {
		t.Run(tc.key, func(t *testing.T) {
			assert.Equal(t, tc.want, isPublicConfigKey(tc.key))
		})
	}
}

// ---------------------------------------------------------------------------
// Get
// ---------------------------------------------------------------------------

func newGetApp() *fiber.App {
	app := newApp()
	app.Get("/config/:key", Get)
	return app
}

func TestGet_NonPublicKey_Returns403(t *testing.T) {
	// Refused before any DB access, so no DB needed.
	resp := doJSON(t, newGetApp(), "GET", "/config/mail_password", "")
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)
	assert.Contains(t, body(t, resp), "Key not publicly readable")
}

func TestGet_PublicKey(t *testing.T) {
	sqlDB := useSQLite(t)
	_, err := sqlDB.Exec("INSERT INTO config (`key`, value) VALUES ('ads_enabled', '1'), ('secret_thing', 'x')")
	require.NoError(t, err)

	t.Run("present", func(t *testing.T) {
		resp := doJSON(t, newGetApp(), "GET", "/config/ads_enabled", "")
		assert.Equal(t, fiber.StatusOK, resp.StatusCode)
		var items []ConfigItem
		require.NoError(t, json.Unmarshal([]byte(body(t, resp)), &items))
		require.Len(t, items, 1)
		assert.Equal(t, "ads_enabled", items[0].Key)
		assert.Equal(t, "1", items[0].Value)
	})

	t.Run("public but absent returns empty array not null", func(t *testing.T) {
		resp := doJSON(t, newGetApp(), "GET", "/config/ux_experiments", "")
		assert.Equal(t, fiber.StatusOK, resp.StatusCode)
		assert.Equal(t, "[]", body(t, resp))
	})

	t.Run("private key present in DB is still refused", func(t *testing.T) {
		resp := doJSON(t, newGetApp(), "GET", "/config/secret_thing", "")
		assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)
		assert.NotContains(t, body(t, resp), "\"x\"")
	})
}

// ---------------------------------------------------------------------------
// RequireSupportOrAdminMiddleware — DB paths
// ---------------------------------------------------------------------------

func TestRequireSupportOrAdminMiddleware_DBPaths(t *testing.T) {
	sqlDB := useSQLite(t)
	_, err := sqlDB.Exec("INSERT INTO users (id, systemrole) VALUES (1, ?), (2, ?), (3, ?), (4, ?)",
		utils.SYSTEMROLE_USER, utils.SYSTEMROLE_SUPPORT, utils.SYSTEMROLE_ADMIN, utils.SYSTEMROLE_MODERATOR)
	require.NoError(t, err)
	_, err = sqlDB.Exec("INSERT INTO sessions (id, userid) VALUES (10, 1), (20, 2), (30, 3), (40, 4)")
	require.NoError(t, err)

	tests := []struct {
		name    string
		user    uint64
		session uint64
		want    int
	}{
		{"plain user forbidden", 1, 10, fiber.StatusForbidden},
		{"moderator forbidden", 4, 40, fiber.StatusForbidden},
		{"support allowed", 2, 20, fiber.StatusOK},
		{"admin allowed", 3, 30, fiber.StatusOK},
		{"session belongs to another user", 2, 30, fiber.StatusUnauthorized},
		{"session does not exist", 3, 999, fiber.StatusUnauthorized},
		{"user does not exist", 99, 20, fiber.StatusUnauthorized},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			resp := doAuth(t, newMiddlewareApp(), "GET", "/protected", makeJWT(t, tc.user, tc.session))
			assert.Equal(t, tc.want, resp.StatusCode)
		})
	}
}

// ---------------------------------------------------------------------------
// PatchAdminConfig — body handling past validation
// ---------------------------------------------------------------------------

func TestPatchAdminConfig_ValueTypes(t *testing.T) {
	useSQLite(t)

	tests := []struct {
		name string
		body string
	}{
		{"string value", `{"foo":"bar"}`},
		{"number value", `{"foo":42}`},
		{"bool value", `{"foo":true}`},
		{"null value", `{"foo":null}`},
		{"object value", `{"foo":{"a":[1,2,3]}}`},
		{"array value", `{"foo":["a","b"]}`},
		{"several keys plus modtools", `{"a":"1","b":2,"modtools":{"x":1}}`},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			resp := doJSON(t, newPatchConfigApp(), "PATCH", "/config/admin", tc.body)
			assert.Equal(t, fiber.StatusOK, resp.StatusCode)
			assert.Contains(t, body(t, resp), `"status":"Success"`)
		})
	}
}
