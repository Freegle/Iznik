package test

import (
	"database/sql"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	_ "modernc.org/sqlite"
)

// writeTempDB writes raw SQLite bytes to a temp file and opens it.
func writeTempDB(t *testing.T, data []byte) *sql.DB {
	t.Helper()
	f, err := os.CreateTemp("", "dumptest-*.sqlite")
	if err != nil {
		t.Fatalf("temp: %v", err)
	}
	if _, err := f.Write(data); err != nil {
		t.Fatalf("write: %v", err)
	}
	f.Close()
	t.Cleanup(func() { os.Remove(f.Name()) })
	db, err := sql.Open("sqlite", f.Name())
	if err != nil {
		t.Fatalf("open sqlite: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return db
}

func tableRowCount(t *testing.T, db *sql.DB, table string) int {
	t.Helper()
	var n int
	if err := db.QueryRow(fmt.Sprintf("SELECT COUNT(*) FROM %q", table)).Scan(&n); err != nil {
		t.Fatalf("count %s: %v", table, err)
	}
	return n
}

func tableExists(db *sql.DB, table string) bool {
	var name string
	err := db.QueryRow("SELECT name FROM sqlite_master WHERE type='table' AND name=?", table).Scan(&name)
	return err == nil
}

// parseFrames splits the framed stream into progress frames, the reassembled
// data bytes, and the end frame.
func parseFrames(t *testing.T, body []byte) (progress []map[string]interface{}, data []byte, end map[string]interface{}) {
	t.Helper()
	i := 0
	for i+5 <= len(body) {
		typ := body[i]
		n := int(binary.BigEndian.Uint32(body[i+1 : i+5]))
		i += 5
		if i+n > len(body) {
			t.Fatalf("truncated frame: need %d have %d", n, len(body)-i)
		}
		payload := body[i : i+n]
		i += n
		switch typ {
		case 0x01:
			var m map[string]interface{}
			_ = json.Unmarshal(payload, &m)
			progress = append(progress, m)
		case 0x02:
			data = append(data, payload...)
		case 0x03:
			var m map[string]interface{}
			_ = json.Unmarshal(payload, &m)
			end = m
		}
	}
	return
}

// seedDumpTarget builds a user with a post, a two-sided chat, a membership and a
// log row. Returns the target user id.
func seedDumpTarget(t *testing.T, prefix string) uint64 {
	db := database.DBConn
	groupID := CreateTestGroup(t, prefix)
	target := CreateTestUser(t, prefix+"_target", "User")
	other := CreateTestUser(t, prefix+"_other", "User")
	CreateTestMembership(t, target, groupID, "Member")
	CreateTestMessage(t, target, groupID, "DumpTest Offer Sofa", 55.9533, -3.1883)

	chatID := CreateTestChatRoom(t, target, &other, nil, "User2User")
	CreateTestChatMessage(t, chatID, target, "Hello from target")
	CreateTestChatMessage(t, chatID, other, "Reply from other side")

	db.Exec("INSERT INTO logs (timestamp, type, subtype, user, byuser, text) VALUES (NOW(), 'User', 'Login', ?, ?, 'test')", target, target)
	return target
}

func TestUserDumpUnauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/modtools/user/1/dump", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestUserDumpForbidden(t *testing.T) {
	prefix := uniquePrefix("dumpforbid")
	uid := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, uid)
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?jwt=%s", uid, token), nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestUserDumpNotFound(t *testing.T) {
	prefix := uniquePrefix("dumpnf")
	sup := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, sup)
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/999999999/dump?jwt=%s", token), nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestUserDumpApiKeyBypass(t *testing.T) {
	prefix := uniquePrefix("dumpkey")
	target := CreateTestUser(t, prefix+"_target", "User")

	old := os.Getenv("USERDUMP_API_KEY")
	os.Setenv("USERDUMP_API_KEY", "s3cret-dump-key")
	defer os.Setenv("USERDUMP_API_KEY", old)

	// Correct key, no JWT (not logged in as anyone) -> authorized.
	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/user/%d/dump?format=raw&include=db&key=s3cret-dump-key", target), nil))
	assert.Equal(t, 200, resp.StatusCode)
	db := writeTempDB(t, rsp(resp))
	assert.Equal(t, 1, tableRowCount(t, db, "users"))

	// Header variant works too.
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?format=raw&include=db", target), nil)
	req.Header.Set("X-Dump-Key", "s3cret-dump-key")
	resp2, _ := getApp().Test(req)
	assert.Equal(t, 200, resp2.StatusCode)

	// Wrong key, no JWT -> unauthorized.
	resp3, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/modtools/user/%d/dump?format=raw&key=wrong", target), nil))
	assert.Equal(t, 401, resp3.StatusCode)
}

func TestUserDumpRawBuildsSqlite(t *testing.T) {
	prefix := uniquePrefix("dumpraw")
	sup := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, sup)
	target := seedDumpTarget(t, prefix)

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?format=raw&include=db&jwt=%s", target, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	db := writeTempDB(t, rsp(resp))

	// Meta tables present.
	assert.True(t, tableExists(db, "_meta"))
	assert.True(t, tableExists(db, "_sections"))

	// Core domains present with expected rows.
	assert.Equal(t, 1, tableRowCount(t, db, "users"))
	assert.GreaterOrEqual(t, tableRowCount(t, db, "messages"), 1)
	assert.GreaterOrEqual(t, tableRowCount(t, db, "memberships"), 1)
	assert.GreaterOrEqual(t, tableRowCount(t, db, "chat_rooms"), 1)
	// Both sides of the chat must be captured.
	assert.Equal(t, 2, tableRowCount(t, db, "chat_messages"))

	// _meta records the target user id.
	var v string
	assert.NoError(t, db.QueryRow("SELECT value FROM _meta WHERE key='userid'").Scan(&v))
	assert.Equal(t, fmt.Sprintf("%d", target), v)
}

func TestUserDumpFramedProtocol(t *testing.T) {
	prefix := uniquePrefix("dumpframed")
	sup := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, sup)
	target := seedDumpTarget(t, prefix)

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?include=db&jwt=%s", target, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	progress, data, end := parseFrames(t, rsp(resp))

	// Progress frames carry an ETA and section names.
	assert.NotEmpty(t, progress)
	sawEta := false
	for _, p := range progress {
		if _, ok := p["eta_ms"]; ok {
			sawEta = true
		}
		assert.Contains(t, p, "percent")
		assert.Contains(t, p, "section")
	}
	assert.True(t, sawEta, "expected eta_ms in progress frames")

	// Data frames reconstruct a valid SQLite database.
	assert.NotEmpty(t, data)
	db := writeTempDB(t, data)
	assert.Equal(t, 1, tableRowCount(t, db, "users"))

	// End frame reports size and a sha256.
	assert.NotNil(t, end)
	assert.Equal(t, float64(len(data)), end["bytes"])
	assert.NotEmpty(t, end["sha256"])
}

func TestUserDumpRedactsSecrets(t *testing.T) {
	prefix := uniquePrefix("dumpredact")
	sup := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, sup)
	target := CreateTestUser(t, prefix+"_target", "User")

	database.DBConn.Exec(
		"INSERT INTO users_logins (userid, type, uid, credentials) VALUES (?, 'Native', ?, ?)",
		target, fmt.Sprintf("%d", target), "$2y$10$SECRETHASHvalue1234567890")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?format=raw&include=db&jwt=%s", target, token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	db := writeTempDB(t, rsp(resp))

	if !tableExists(db, "users_logins") {
		t.Skip("users_logins absent in this schema")
	}
	var cred string
	err := db.QueryRow("SELECT credentials FROM users_logins LIMIT 1").Scan(&cred)
	assert.NoError(t, err)
	assert.Equal(t, "[REDACTED]", cred)
}

func TestUserDumpLokiViaFakeServer(t *testing.T) {
	prefix := uniquePrefix("dumploki")
	sup := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, sup)
	target := CreateTestUser(t, prefix+"_target", "User")

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, `{"status":"success","data":{"resultType":"streams","result":[
			{"stream":{"app":"freegle","source":"api"},"values":[["1700000000000000000","{\"user_id\":1,\"session_id\":\"sess-abc\",\"endpoint\":\"/api/chat\"}"]]}
		]}}`)
	}))
	defer srv.Close()

	old := os.Getenv("LOKI_URL")
	os.Setenv("LOKI_URL", srv.URL)
	defer os.Setenv("LOKI_URL", old)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?format=raw&include=loki&jwt=%s", target, token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	db := writeTempDB(t, rsp(resp))

	assert.True(t, tableExists(db, "loki_logs"))
	assert.GreaterOrEqual(t, tableRowCount(t, db, "loki_logs"), 1)

	var status string
	assert.NoError(t, db.QueryRow("SELECT status FROM _sections WHERE name='loki_logs'").Scan(&status))
	assert.Equal(t, "done", status)
}

func TestUserDumpLokiErrorBecomesWarning(t *testing.T) {
	prefix := uniquePrefix("dumplokierr")
	sup := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, sup)
	target := CreateTestUser(t, prefix+"_target", "User")

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "boom", http.StatusInternalServerError)
	}))
	defer srv.Close()

	old := os.Getenv("LOKI_URL")
	os.Setenv("LOKI_URL", srv.URL)
	defer os.Setenv("LOKI_URL", old)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?format=raw&include=loki&jwt=%s", target, token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	db := writeTempDB(t, rsp(resp))

	// The dump still completes; the failure is recorded as a warning.
	var status string
	assert.NoError(t, db.QueryRow("SELECT status FROM _sections WHERE name='loki_logs'").Scan(&status))
	assert.Equal(t, "warning", status)
}

func TestUserDumpSentryViaFakeServer(t *testing.T) {
	prefix := uniquePrefix("dumpsentry")
	sup := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, sup)
	target := CreateTestUser(t, prefix+"_target", "User")

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		fmt.Fprint(w, `[{"id":"123","title":"Boom","culprit":"x","level":"error","status":"unresolved","count":"5","userCount":1,"firstSeen":"2026-01-01T00:00:00Z","lastSeen":"2026-01-02T00:00:00Z","permalink":"http://s/123"}]`)
	}))
	defer srv.Close()

	oldBase, oldTok := os.Getenv("SENTRY_API_BASE"), os.Getenv("SENTRY_AUTH_TOKEN")
	os.Setenv("SENTRY_API_BASE", srv.URL)
	os.Setenv("SENTRY_AUTH_TOKEN", "dummy-token")
	defer os.Setenv("SENTRY_API_BASE", oldBase)
	defer os.Setenv("SENTRY_AUTH_TOKEN", oldTok)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?format=raw&include=sentry&jwt=%s", target, token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	db := writeTempDB(t, rsp(resp))

	assert.True(t, tableExists(db, "sentry_issues"))
	assert.GreaterOrEqual(t, tableRowCount(t, db, "sentry_issues"), 1)
}

func TestUserDumpSentryNoTokenWarns(t *testing.T) {
	prefix := uniquePrefix("dumpsentrynotok")
	sup := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, sup)
	target := CreateTestUser(t, prefix+"_target", "User")

	oldTok := os.Getenv("SENTRY_AUTH_TOKEN")
	os.Setenv("SENTRY_AUTH_TOKEN", "")
	defer os.Setenv("SENTRY_AUTH_TOKEN", oldTok)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?format=raw&include=sentry&jwt=%s", target, token), nil))
	assert.Equal(t, 200, resp.StatusCode)
	db := writeTempDB(t, rsp(resp))

	var status string
	assert.NoError(t, db.QueryRow("SELECT status FROM _sections WHERE name='sentry_issues'").Scan(&status))
	assert.Equal(t, "warning", status)
}
