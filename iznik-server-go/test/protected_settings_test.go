package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"os"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/golang-jwt/jwt/v4"
	"github.com/stretchr/testify/assert"
)

// PROTECTED_SETTINGS_KEYS: a deployment switch under which a member cannot
// change the listed settings keys about themselves. Off by default. See
// user.ProtectedSettingsKeys and auth.CanWriteProtectedSettings.

func patchProtectedJSON(t *testing.T, url string, body map[string]interface{}) int {
	t.Helper()
	b, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	return resp.StatusCode
}

func storedSettings(t *testing.T, userID uint64) map[string]json.RawMessage {
	t.Helper()
	var raw string
	database.DBConn.Raw("SELECT COALESCE(settings, '{}') FROM users WHERE id = ?", userID).Scan(&raw)
	var m map[string]json.RawMessage
	assert.NoError(t, json.Unmarshal([]byte(raw), &m), raw)
	return m
}

// A token minted by a holder of JWT_SECRET for a member, carrying svc="1".
// This is what a deployment's own server (e.g. a payment webhook) sends.
func serviceToken(userID uint64, sessionID uint64) string {
	token := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"id":        fmt.Sprint(userID),
		"sessionid": fmt.Sprint(sessionID),
		"exp":       time.Now().Unix() + 60,
		"svc":       "1",
	})
	s, _ := token.SignedString([]byte(os.Getenv("JWT_SECRET")))
	return s
}

func TestProtectedSettingsOffByDefault(t *testing.T) {
	t.Setenv("PROTECTED_SETTINGS_KEYS", "")
	uid := CreateTestUser(t, uniquePrefix("protset_off"), "User")
	_, token := CreateTestSession(t, uid)

	status := patchProtectedJSON(t, "/api/session?jwt="+token, map[string]interface{}{
		"settings": map[string]interface{}{"lat_payment": map[string]interface{}{"status": "paid"}},
	})
	assert.Equal(t, 200, status)
	assert.JSONEq(t, `{"status":"paid"}`, string(storedSettings(t, uid)["lat_payment"]), "with the switch off, Freegle behaviour: the member's write stands")
}

func TestProtectedSettingsMemberCannotChangeOwn(t *testing.T) {
	t.Setenv("PROTECTED_SETTINGS_KEYS", "lat_payment")
	db := database.DBConn
	uid := CreateTestUser(t, uniquePrefix("protset_own"), "User")
	_, token := CreateTestSession(t, uid)
	db.Exec("UPDATE users SET settings = ? WHERE id = ?", `{"lat_payment":{"status":"concession"},"mylocation":{"id":1}}`, uid)

	// The usual client read-merge-write: everything back, with the protected
	// key altered and an unprotected key changed.
	status := patchProtectedJSON(t, "/api/session?jwt="+token, map[string]interface{}{
		"settings": map[string]interface{}{
			"lat_payment": map[string]interface{}{"status": "paid"},
			"mylocation":  map[string]interface{}{"id": 2},
			"other":       true,
		},
	})
	assert.Equal(t, 200, status)

	got := storedSettings(t, uid)
	assert.JSONEq(t, `{"status":"concession"}`, string(got["lat_payment"]), "protected key keeps its stored value")
	assert.JSONEq(t, `{"id":2}`, string(got["mylocation"]), "unprotected keys still change")
	assert.JSONEq(t, `true`, string(got["other"]))
}

func TestProtectedSettingsMemberCannotCreateOwn(t *testing.T) {
	t.Setenv("PROTECTED_SETTINGS_KEYS", "lat_payment")
	uid := CreateTestUser(t, uniquePrefix("protset_new"), "User")
	_, token := CreateTestSession(t, uid)

	status := patchProtectedJSON(t, "/api/session?jwt="+token, map[string]interface{}{
		"settings": map[string]interface{}{"lat_payment": map[string]interface{}{"status": "paid"}, "other": 1},
	})
	assert.Equal(t, 200, status)

	got := storedSettings(t, uid)
	_, has := got["lat_payment"]
	assert.False(t, has, "a member cannot create the protected key")
	assert.JSONEq(t, `1`, string(got["other"]))
}

func TestProtectedSettingsSelfEditViaPatchUserIsGuardedToo(t *testing.T) {
	t.Setenv("PROTECTED_SETTINGS_KEYS", "lat_payment")
	uid := CreateTestUser(t, uniquePrefix("protset_pu"), "User")
	_, token := CreateTestSession(t, uid)

	status := patchProtectedJSON(t, "/api/user?jwt="+token, map[string]interface{}{
		"id":       uid,
		"settings": map[string]interface{}{"lat_payment": map[string]interface{}{"status": "paid"}},
	})
	assert.Equal(t, 200, status)
	_, has := storedSettings(t, uid)["lat_payment"]
	assert.False(t, has, "the other self-edit route is guarded the same way")
}

func TestProtectedSettingsServiceTokenMayWrite(t *testing.T) {
	t.Setenv("PROTECTED_SETTINGS_KEYS", "lat_payment")
	uid := CreateTestUser(t, uniquePrefix("protset_svc"), "User")
	sessionID, _ := CreateTestSession(t, uid)

	status := patchProtectedJSON(t, "/api/session?jwt="+serviceToken(uid, sessionID), map[string]interface{}{
		"settings": map[string]interface{}{"lat_payment": map[string]interface{}{"status": "paid", "method": "stripe"}},
	})
	assert.Equal(t, 200, status)
	assert.JSONEq(t, `{"status":"paid","method":"stripe"}`, string(storedSettings(t, uid)["lat_payment"]))
}

func TestProtectedSettingsSystemModMayWriteForMember(t *testing.T) {
	t.Setenv("PROTECTED_SETTINGS_KEYS", "lat_payment")
	prefix := uniquePrefix("protset_admin")
	member := CreateTestUser(t, prefix+"_m", "User")
	admin := CreateTestUser(t, prefix+"_a", "Admin")
	_, adminToken := CreateTestSession(t, admin)

	status := patchProtectedJSON(t, "/api/user?jwt="+adminToken, map[string]interface{}{
		"id":       member,
		"settings": map[string]interface{}{"lat_payment": map[string]interface{}{"status": "paid", "updatedByAdmin": true}},
	})
	assert.Equal(t, 200, status)
	assert.JSONEq(t, `{"status":"paid","updatedByAdmin":true}`, string(storedSettings(t, member)["lat_payment"]))
}

func TestProtectedSettingsSystemModMayWriteOwn(t *testing.T) {
	t.Setenv("PROTECTED_SETTINGS_KEYS", "lat_payment")
	admin := CreateTestUser(t, uniquePrefix("protset_adminown"), "Admin")
	_, token := CreateTestSession(t, admin)

	status := patchProtectedJSON(t, "/api/session?jwt="+token, map[string]interface{}{
		"settings": map[string]interface{}{"lat_payment": map[string]interface{}{"status": "paid"}},
	})
	assert.Equal(t, 200, status)
	assert.JSONEq(t, `{"status":"paid"}`, string(storedSettings(t, admin)["lat_payment"]))
}
