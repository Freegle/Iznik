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
		"CREATE TABLE concern_keywords (id INTEGER PRIMARY KEY AUTOINCREMENT, keyword TEXT, substance TEXT, category TEXT, match_mode TEXT, `exclude` TEXT, scope TEXT, group_id INTEGER, action TEXT)",
		"CREATE TABLE background_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_type TEXT, data TEXT)",
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
	for _, s := range []string{
		"INSERT INTO users (id, systemrole) VALUES (1, 'User'), (2, 'Support'), (3, 'Admin'), (4, 'Moderator')",
		"INSERT INTO sessions (id, userid) VALUES (10, 1), (20, 2), (30, 3), (40, 4)",
	} {
		_, err := sqlDB.Exec(s)
		require.NoError(t, err)
	}

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

// ---------------------------------------------------------------------------
// ListConcernKeywords
// ---------------------------------------------------------------------------

func newListKeywordApp() *fiber.App {
	app := newApp()
	app.Get("/keywords", ListConcernKeywords)
	return app
}

func TestListConcernKeywords(t *testing.T) {
	sqlDB := useSQLite(t)

	list := func(t *testing.T, path string) []ConcernKeyword {
		t.Helper()
		resp := doJSON(t, newListKeywordApp(), "GET", path, "")
		require.Equal(t, fiber.StatusOK, resp.StatusCode)
		raw := body(t, resp)
		require.True(t, strings.HasPrefix(raw, "["), "must be an array, got %s", raw)
		var kws []ConcernKeyword
		require.NoError(t, json.Unmarshal([]byte(raw), &kws))
		return kws
	}

	t.Run("empty table returns [] not null", func(t *testing.T) {
		resp := doJSON(t, newListKeywordApp(), "GET", "/keywords", "")
		assert.Equal(t, "[]", body(t, resp))
	})

	_, err := sqlDB.Exec("INSERT INTO concern_keywords (keyword, category, match_mode, scope, group_id, action) VALUES " +
		"('zebra','c','literal','global',0,'flag'), ('apple','c','literal','group',5,'block'), ('mango','c','regex','group',6,'flag')")
	require.NoError(t, err)

	t.Run("ordered by keyword", func(t *testing.T) {
		kws := list(t, "/keywords")
		require.Len(t, kws, 3)
		assert.Equal(t, []string{"apple", "mango", "zebra"}, []string{kws[0].Keyword, kws[1].Keyword, kws[2].Keyword})
	})

	t.Run("scope filter", func(t *testing.T) {
		kws := list(t, "/keywords?scope=group")
		require.Len(t, kws, 2)
		assert.Equal(t, "apple", kws[0].Keyword)
	})

	t.Run("group filter", func(t *testing.T) {
		kws := list(t, "/keywords?group_id=6")
		require.Len(t, kws, 1)
		assert.Equal(t, "mango", kws[0].Keyword)
	})

	t.Run("scope and group combined", func(t *testing.T) {
		assert.Len(t, list(t, "/keywords?scope=group&group_id=5"), 1)
		assert.Len(t, list(t, "/keywords?scope=global&group_id=5"), 0)
	})

	t.Run("no match returns []", func(t *testing.T) {
		resp := doJSON(t, newListKeywordApp(), "GET", "/keywords?scope=nothing", "")
		assert.Equal(t, "[]", body(t, resp))
	})
}

// ---------------------------------------------------------------------------
// CreateConcernKeyword
// ---------------------------------------------------------------------------

func countRows(t *testing.T, sqlDB *sql.DB, q string) int {
	t.Helper()
	var n int
	require.NoError(t, sqlDB.QueryRow(q).Scan(&n))
	return n
}

func TestCreateConcernKeyword_Defaults(t *testing.T) {
	sqlDB := useSQLite(t)

	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword", `{"keyword":"  needle  ","category":"drugs"}`)
	require.Equal(t, fiber.StatusOK, resp.StatusCode)

	var kw ConcernKeyword
	require.NoError(t, json.Unmarshal([]byte(body(t, resp)), &kw))
	assert.NotZero(t, kw.ID)
	assert.Equal(t, "needle", kw.Keyword, "keyword is trimmed")
	assert.Equal(t, "literal", kw.MatchMode)
	assert.Equal(t, "global", kw.Scope)
	assert.Equal(t, "flag", kw.Action)
	assert.Equal(t, 1, countRows(t, sqlDB, "SELECT COUNT(*) FROM concern_keywords"))
	// Flag keywords are not backfilled.
	assert.Equal(t, 0, countRows(t, sqlDB, "SELECT COUNT(*) FROM background_tasks"))
}

func TestCreateConcernKeyword_BackfillOnlyForGlobalBlock(t *testing.T) {
	tests := []struct {
		name     string
		body     string
		wantTask int
	}{
		{"global block queues backfill", `{"keyword":"k","category":"c","action":"block"}`, 1},
		{"explicit global block queues backfill", `{"keyword":"k","category":"c","action":"block","scope":"global"}`, 1},
		{"group block does not", `{"keyword":"k","category":"c","action":"block","scope":"group","group_id":7}`, 0},
		{"global flag does not", `{"keyword":"k","category":"c","action":"flag"}`, 0},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			sqlDB := useSQLite(t)
			resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword", tc.body)
			require.Equal(t, fiber.StatusOK, resp.StatusCode)
			assert.Equal(t, tc.wantTask, countRows(t, sqlDB, "SELECT COUNT(*) FROM background_tasks"))
		})
	}
}

func TestCreateConcernKeyword_AllFieldsStored(t *testing.T) {
	sqlDB := useSQLite(t)

	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword",
		`{"keyword":"k","substance":"sub","category":"c","match_mode":"regex","exclude":"not this","scope":"group","group_id":9,"action":"flag"}`)
	require.Equal(t, fiber.StatusOK, resp.StatusCode)

	var substance, exclude, mode, scope string
	var gid int
	require.NoError(t, sqlDB.QueryRow("SELECT substance, `exclude`, match_mode, scope, group_id FROM concern_keywords").
		Scan(&substance, &exclude, &mode, &scope, &gid))
	assert.Equal(t, "sub", substance)
	assert.Equal(t, "not this", exclude)
	assert.Equal(t, "regex", mode)
	assert.Equal(t, "group", scope)
	assert.Equal(t, 9, gid)
}

func TestCreateConcernKeyword_DBError_Returns500(t *testing.T) {
	sqlDB := useSQLite(t)
	_, err := sqlDB.Exec("DROP TABLE concern_keywords")
	require.NoError(t, err)

	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword", `{"keyword":"k","category":"c"}`)
	assert.Equal(t, fiber.StatusInternalServerError, resp.StatusCode)
	assert.Contains(t, body(t, resp), "Failed to create concern keyword")
}

// ---------------------------------------------------------------------------
// DeleteConcernKeyword
// ---------------------------------------------------------------------------

func TestDeleteConcernKeyword_DBPaths(t *testing.T) {
	sqlDB := useSQLite(t)
	_, err := sqlDB.Exec("INSERT INTO concern_keywords (id, keyword, category) VALUES (5, 'a', 'c'), (6, 'b', 'c')")
	require.NoError(t, err)

	t.Run("deletes existing", func(t *testing.T) {
		resp := doJSON(t, newDeleteKeywordApp(), "DELETE", "/config/concern-keyword/5", "")
		assert.Equal(t, fiber.StatusOK, resp.StatusCode)
		assert.Contains(t, body(t, resp), `"success":true`)
		assert.Equal(t, 1, countRows(t, sqlDB, "SELECT COUNT(*) FROM concern_keywords"))
		assert.Equal(t, 1, countRows(t, sqlDB, "SELECT COUNT(*) FROM concern_keywords WHERE id = 6"))
	})

	t.Run("second delete of same id is 404", func(t *testing.T) {
		resp := doJSON(t, newDeleteKeywordApp(), "DELETE", "/config/concern-keyword/5", "")
		assert.Equal(t, fiber.StatusNotFound, resp.StatusCode)
		assert.Contains(t, body(t, resp), "Concern keyword not found")
	})

	t.Run("unknown id is 404", func(t *testing.T) {
		resp := doJSON(t, newDeleteKeywordApp(), "DELETE", "/config/concern-keyword/12345", "")
		assert.Equal(t, fiber.StatusNotFound, resp.StatusCode)
	})

	t.Run("db error is 500", func(t *testing.T) {
		_, err := sqlDB.Exec("DROP TABLE concern_keywords")
		require.NoError(t, err)
		resp := doJSON(t, newDeleteKeywordApp(), "DELETE", "/config/concern-keyword/6", "")
		assert.Equal(t, fiber.StatusInternalServerError, resp.StatusCode)
		assert.Contains(t, body(t, resp), "Failed to delete concern keyword")
	})
}
