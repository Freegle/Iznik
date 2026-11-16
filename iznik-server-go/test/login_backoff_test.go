package test

import (
	"bytes"
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"os"
	"strconv"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/session"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// loginAs posts a login and returns the status and decoded body. address, when given, arrives as
// X-Real-IP, which is where the API reads the client's address from.
func loginAs(t *testing.T, payload map[string]interface{}, address string) (int, map[string]interface{}, string) {
	t.Helper()
	body, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", "/api/session", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	if address != "" {
		req.Header.Set("X-Real-IP", address)
	}
	resp, err := getApp().Test(req, 5000)
	require.NoError(t, err)
	var result map[string]interface{}
	_ = json.NewDecoder(resp.Body).Decode(&result)

	return resp.StatusCode, result, resp.Header.Get("Retry-After")
}

func userWithPassword(t *testing.T, prefix string, password string) (uint64, string) {
	t.Helper()
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	salt := os.Getenv("PASSWORD_SALT")
	if salt == "" {
		salt = "zzzz"
	}
	h := sha1.New()
	h.Write([]byte(password + salt))
	database.DBConn.Exec("INSERT INTO users_logins (userid, type, uid, credentials, salt) VALUES (?, 'Native', ?, ?, ?)",
		userID, strconv.FormatUint(userID, 10), hex.EncodeToString(h.Sum(nil)), salt)

	return userID, fmt.Sprintf("%s@test.com", prefix)
}

func TestLoginRepeatedWrongPasswordsMakeTheAccountWait(t *testing.T) {
	session.ResetLoginBackoff()
	t.Cleanup(session.ResetLoginBackoff)
	_, email := userWithPassword(t, uniquePrefix("login_backoff"), "rightpassword")
	wrong := map[string]interface{}{"email": email, "password": "wrongpassword"}
	right := map[string]interface{}{"email": email, "password": "rightpassword"}

	// The first five wrong passwords get the ordinary answer.
	for i := 0; i < 5; i++ {
		status, result, _ := loginAs(t, wrong, "")
		assert.Equal(t, 403, status)
		assert.Equal(t, float64(3), result["ret"])
	}

	// The sixth is answered too, and starts the wait.
	status, _, _ := loginAs(t, wrong, "")
	assert.Equal(t, 403, status)

	// Now even the right password has to wait, so a guesser learns nothing from trying.
	status, result, retryAfter := loginAs(t, right, "")
	assert.Equal(t, 403, status)
	assert.Equal(t, float64(session.RetLoginBackoff), result["ret"])
	assert.Equal(t, "30", retryAfter)
	assert.Equal(t, float64(30), result["retryafter"])
	assert.Equal(t, "Too many failed attempts. Please wait 30 seconds and try again, or use Forgot password.", result["status"])
	assert.Nil(t, result["jwt"])

	// The same address written with other capitals is the same member.
	status, result, _ = loginAs(t, map[string]interface{}{"email": upperFirst(email), "password": "rightpassword"}, "")
	assert.Equal(t, 403, status)
	assert.Equal(t, float64(session.RetLoginBackoff), result["ret"])

	// Another member is not held back by this one's failures.
	_, otherEmail := userWithPassword(t, uniquePrefix("login_backoff_other"), "otherpassword")
	status, _, _ = loginAs(t, map[string]interface{}{"email": otherEmail, "password": "otherpassword"}, "")
	assert.Equal(t, 200, status)
}

func upperFirst(s string) string {
	return string(bytes.ToUpper([]byte(s[:1]))) + s[1:]
}

func TestLoginAMembersAddressesAndLinkShareOneCount(t *testing.T) {
	session.ResetLoginBackoff()
	t.Cleanup(session.ResetLoginBackoff)
	prefix := uniquePrefix("login_backoff_shared")
	userID, email := userWithPassword(t, prefix, "rightpassword")
	second := prefix + "-second@test.com"
	database.DBConn.Exec("INSERT INTO users_emails (userid, email, preferred, validated) VALUES (?, ?, 0, NOW())", userID, second)
	database.DBConn.Exec("INSERT INTO users_logins (userid, type, uid, credentials) VALUES (?, 'Link', ?, ?)",
		userID, strconv.FormatUint(userID, 10), "therightkey")

	// Two wrong passwords on each address and two wrong link keys: six failures, one member.
	for i := 0; i < 2; i++ {
		status, _, _ := loginAs(t, map[string]interface{}{"email": email, "password": "wrongpassword"}, "")
		assert.Equal(t, 403, status)
		status, _, _ = loginAs(t, map[string]interface{}{"email": second, "password": "wrongpassword"}, "")
		assert.Equal(t, 403, status)
		status, _, _ = loginAs(t, map[string]interface{}{"u": userID, "k": "awrongkey"}, "")
		assert.Equal(t, 403, status)
	}

	for _, right := range []map[string]interface{}{
		{"email": email, "password": "rightpassword"},
		{"email": second, "password": "rightpassword"},
		{"u": userID, "k": "therightkey"},
	} {
		status, result, _ := loginAs(t, right, "")
		assert.Equal(t, 403, status)
		assert.Equal(t, float64(session.RetLoginBackoff), result["ret"])
	}
}

func TestLoginOneIPv6NetworkIsOneAddress(t *testing.T) {
	session.ResetLoginBackoff()
	t.Cleanup(session.ResetLoginBackoff)
	_, email := userWithPassword(t, uniquePrefix("login_backoff_v6"), "rightpassword")
	right := map[string]interface{}{"email": email, "password": "rightpassword"}

	// 2001:db8::/32 is the documentation range (RFC 3849). A new address from the same /64 each time.
	for i := 0; i < 21; i++ {
		unknown := map[string]interface{}{"email": fmt.Sprintf("%s-%d@test.com", uniquePrefix("login_backoff_v6_nobody"), i), "password": "guess"}
		status, _, _ := loginAs(t, unknown, fmt.Sprintf("2001:db8:1:2::%x", i+1))
		assert.Equal(t, 404, status)
	}

	status, result, _ := loginAs(t, right, "2001:db8:1:2::ffff")
	assert.Equal(t, 403, status)
	assert.Equal(t, float64(session.RetLoginBackoff), result["ret"])

	// The /64 next door is someone else.
	status, _, _ = loginAs(t, right, "2001:db8:1:3::1")
	assert.Equal(t, 200, status)
}

func TestLoginSuccessStartsTheCountAgain(t *testing.T) {
	session.ResetLoginBackoff()
	t.Cleanup(session.ResetLoginBackoff)
	_, email := userWithPassword(t, uniquePrefix("login_backoff_ok"), "rightpassword")
	wrong := map[string]interface{}{"email": email, "password": "wrongpassword"}
	right := map[string]interface{}{"email": email, "password": "rightpassword"}

	for i := 0; i < 4; i++ {
		status, _, _ := loginAs(t, wrong, "")
		assert.Equal(t, 403, status)
	}
	status, _, _ := loginAs(t, right, "")
	assert.Equal(t, 200, status)

	// Five more wrong ones are again free: the four before the success no longer count.
	for i := 0; i < 5; i++ {
		status, _, _ = loginAs(t, wrong, "")
		assert.Equal(t, 403, status)
	}
	status, _, _ = loginAs(t, right, "")
	assert.Equal(t, 200, status)
}

func TestLoginOneAddressTryingManyAccountsHasToWait(t *testing.T) {
	session.ResetLoginBackoff()
	t.Cleanup(session.ResetLoginBackoff)
	// A documentation address (RFC 5737): public, and nobody's.
	const address = "203.0.113.77"
	_, email := userWithPassword(t, uniquePrefix("login_backoff_addr"), "rightpassword")
	right := map[string]interface{}{"email": email, "password": "rightpassword"}

	// One failure each against many addresses that do not exist: no single account count is reached.
	for i := 0; i < 21; i++ {
		unknown := map[string]interface{}{"email": fmt.Sprintf("%s-%d@test.com", uniquePrefix("login_backoff_nobody"), i), "password": "guess"}
		status, result, _ := loginAs(t, unknown, address)
		assert.Equal(t, 404, status)
		assert.Equal(t, float64(2), result["ret"])
	}

	// The address now waits, whoever it tries.
	status, result, _ := loginAs(t, right, address)
	assert.Equal(t, 403, status)
	assert.Equal(t, float64(session.RetLoginBackoff), result["ret"])

	// The member themselves, from somewhere else, is not held.
	status, _, _ = loginAs(t, right, "203.0.113.78")
	assert.Equal(t, 200, status)
}

func TestLoginAnAddressThatIsNotPublicIsNotCounted(t *testing.T) {
	session.ResetLoginBackoff()
	t.Cleanup(session.ResetLoginBackoff)
	_, email := userWithPassword(t, uniquePrefix("login_backoff_private"), "rightpassword")

	// A proxy's private address stands for everyone behind it, so it is never made to wait.
	for i := 0; i < 25; i++ {
		unknown := map[string]interface{}{"email": fmt.Sprintf("%s-%d@test.com", uniquePrefix("login_backoff_private_nobody"), i), "password": "guess"}
		status, _, _ := loginAs(t, unknown, "10.0.0.9")
		assert.Equal(t, 404, status)
	}
	status, _, _ := loginAs(t, map[string]interface{}{"email": email, "password": "rightpassword"}, "10.0.0.9")
	assert.Equal(t, 200, status)
}

func TestLoginRepeatedWrongLinkKeysMakeTheAccountWait(t *testing.T) {
	session.ResetLoginBackoff()
	t.Cleanup(session.ResetLoginBackoff)
	userID := CreateTestUser(t, uniquePrefix("login_backoff_link"), utils.SYSTEMROLE_USER)
	database.DBConn.Exec("INSERT INTO users_logins (userid, type, uid, credentials) VALUES (?, 'Link', ?, ?)",
		userID, strconv.FormatUint(userID, 10), "therightkey")
	wrong := map[string]interface{}{"u": userID, "k": "awrongkey"}
	right := map[string]interface{}{"u": userID, "k": "therightkey"}

	for i := 0; i < 6; i++ {
		status, result, _ := loginAs(t, wrong, "")
		assert.Equal(t, 403, status)
		assert.Equal(t, float64(3), result["ret"])
	}

	status, result, _ := loginAs(t, right, "")
	assert.Equal(t, 403, status)
	assert.Equal(t, float64(session.RetLoginBackoff), result["ret"])

	session.ResetLoginBackoff()
	status, _, _ = loginAs(t, right, "")
	assert.Equal(t, 200, status)
}
