package test

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"net/url"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

const testDiscourseSecret = "test_discourse_secret_123"

func makeDiscourseSSO(nonce string, secret string) (string, string) {
	payload := "nonce=" + url.QueryEscape(nonce)
	encoded := base64.StdEncoding.EncodeToString([]byte(payload))

	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(encoded))
	sig := hex.EncodeToString(mac.Sum(nil))

	return encoded, sig
}

func TestDiscourseSSO_ValidFlow(t *testing.T) {
	prefix := uniquePrefix("discoursesso")
	db := database.DBConn

	os.Setenv("DISCOURSE_SECRET", testDiscourseSecret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	// Create a moderator user on a Freegle group.
	userID := CreateTestUser(t, prefix+"_mod", "Moderator")
	email := prefix + "_mod@test.com"
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Moderator")

	// Create a session.
	sessionID, _ := CreateTestSession(t, userID)

	// Get session details for cookie.
	// series is scanned as string to match the API's JSON encoding (session.go returns it as a string).
	var series string
	var token string
	db.Raw("SELECT series, token FROM sessions WHERE id = ?", sessionID).Row().Scan(&series, &token)

	cookieData, _ := json.Marshal(map[string]interface{}{
		"id":     sessionID,
		"series": series,
		"token":  token,
	})

	// Build SSO request.
	ssoPayload, sig := makeDiscourseSSO("test_nonce_"+prefix, testDiscourseSecret)

	req := httptest.NewRequest("GET", fmt.Sprintf("/discourse_sso?sso=%s&sig=%s",
		url.QueryEscape(ssoPayload), url.QueryEscape(sig)), nil)
	req.Header.Set("Cookie", "Iznik-Discourse-SSO="+url.QueryEscape(string(cookieData)))

	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 302, resp.StatusCode)

	location := resp.Header.Get("Location")
	assert.Contains(t, location, "discourse.ilovefreegle.org/session/sso_login",
		"Should redirect to Discourse SSO login")
	assert.Contains(t, location, "sso=", "Should have sso parameter")
	assert.Contains(t, location, "sig=", "Should have sig parameter")

	// Verify the response payload contains the user's email.
	parsedURL, err := url.Parse(location)
	assert.NoError(t, err)
	ssoResp := parsedURL.Query().Get("sso")
	decoded, err := base64.StdEncoding.DecodeString(ssoResp)
	assert.NoError(t, err)
	values, err := url.ParseQuery(string(decoded))
	assert.NoError(t, err)
	assert.Equal(t, email, values.Get("email"))
	assert.Equal(t, fmt.Sprint(userID), values.Get("external_id"))
}

func TestDiscourseSSO_InvalidSignature(t *testing.T) {
	os.Setenv("DISCOURSE_SECRET", testDiscourseSecret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	ssoPayload, _ := makeDiscourseSSO("test_nonce", testDiscourseSecret)

	// Use a wrong signature.
	req := httptest.NewRequest("GET", fmt.Sprintf("/discourse_sso?sso=%s&sig=invalidsig",
		url.QueryEscape(ssoPayload)), nil)

	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestDiscourseSSO_MissingCookie(t *testing.T) {
	os.Setenv("DISCOURSE_SECRET", testDiscourseSecret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	ssoPayload, sig := makeDiscourseSSO("test_nonce", testDiscourseSecret)

	req := httptest.NewRequest("GET", fmt.Sprintf("/discourse_sso?sso=%s&sig=%s",
		url.QueryEscape(ssoPayload), url.QueryEscape(sig)), nil)

	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 302, resp.StatusCode)

	assert.Equal(t, "https://modtools.org/discourse", resp.Header.Get("Location"),
		"No cookie is the one case the ModTools page retries, so it must get the bare page URL")
}

// ssoCookieFor builds the Iznik-Discourse-SSO cookie value for a session row,
// the way the ModTools page does from the persistent token.
func ssoCookieFor(t *testing.T, sessionID uint64) string {
	t.Helper()
	var series, token string
	database.DBConn.Raw("SELECT series, token FROM sessions WHERE id = ?", sessionID).Row().Scan(&series, &token)
	cookieData, _ := json.Marshal(map[string]interface{}{"id": sessionID, "series": series, "token": token})
	return url.QueryEscape(string(cookieData))
}

// ssoRequestWithCookie sends a correctly signed SSO challenge carrying the cookie.
func ssoRequestWithCookie(t *testing.T, cookie string) string {
	t.Helper()
	ssoPayload, sig := makeDiscourseSSO("test_nonce", testDiscourseSecret)
	req := httptest.NewRequest("GET", fmt.Sprintf("/discourse_sso?sso=%s&sig=%s",
		url.QueryEscape(ssoPayload), url.QueryEscape(sig)), nil)
	req.Header.Set("Cookie", "Iznik-Discourse-SSO="+cookie)

	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 302, resp.StatusCode)
	return resp.Header.Get("Location")
}

// A cookie whose session row no longer exists (logged out elsewhere, token
// rotated) must not be retried: the page would set the same dead cookie and
// come straight back. The moderator is told to log in again.
func TestDiscourseSSO_DeadSession_ShowsSessionError(t *testing.T) {
	os.Setenv("DISCOURSE_SECRET", testDiscourseSecret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	cookieData, _ := json.Marshal(map[string]interface{}{"id": 999999999, "series": "1", "token": "no-such-token"})
	location := ssoRequestWithCookie(t, url.QueryEscape(string(cookieData)))
	assert.Equal(t, "https://modtools.org/discourse?ssoerror=session", location)
}

// A live session for an account that is a plain member - the shape left
// behind when a moderator demotes themselves to Member and leaves - must be
// told it is not a moderator, not bounced back to retry.
func TestDiscourseSSO_MemberOnly_ShowsNotModError(t *testing.T) {
	prefix := uniquePrefix("discoursesso_member")
	os.Setenv("DISCOURSE_SECRET", testDiscourseSecret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	userID := CreateTestUser(t, prefix, "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Member")
	sessionID, _ := CreateTestSession(t, userID)

	location := ssoRequestWithCookie(t, ssoCookieFor(t, sessionID))
	assert.Equal(t, "https://modtools.org/discourse?ssoerror=notmod", location)
}

// The double gate: a mod-level system role with no Owner/Moderator membership
// grants nothing, and is reported as a role problem rather than a session one.
func TestDiscourseSSO_StaleModeratorSystemrole_ShowsNotModError(t *testing.T) {
	prefix := uniquePrefix("discoursesso_stale")
	os.Setenv("DISCOURSE_SECRET", testDiscourseSecret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	userID := CreateTestUser(t, prefix, "Moderator")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Member")
	sessionID, _ := CreateTestSession(t, userID)

	location := ssoRequestWithCookie(t, ssoCookieFor(t, sessionID))
	assert.Equal(t, "https://modtools.org/discourse?ssoerror=notmod", location)
}

// The other half of the double gate: an Owner membership does not sign in an
// account whose system role has not been raised.
func TestDiscourseSSO_OwnerMembershipWithUserSystemrole_ShowsNotModError(t *testing.T) {
	prefix := uniquePrefix("discoursesso_ownerusr")
	os.Setenv("DISCOURSE_SECRET", testDiscourseSecret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	userID := CreateTestUser(t, prefix, "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Owner")
	sessionID, _ := CreateTestSession(t, userID)

	location := ssoRequestWithCookie(t, ssoCookieFor(t, sessionID))
	assert.Equal(t, "https://modtools.org/discourse?ssoerror=notmod", location)
}

// An Owner is signed in exactly like a Moderator.
func TestDiscourseSSO_Owner_SignsIn(t *testing.T) {
	prefix := uniquePrefix("discoursesso_owner")
	os.Setenv("DISCOURSE_SECRET", testDiscourseSecret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	userID := CreateTestUser(t, prefix, "Moderator")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, userID, groupID, "Owner")
	sessionID, _ := CreateTestSession(t, userID)

	location := ssoRequestWithCookie(t, ssoCookieFor(t, sessionID))
	assert.Contains(t, location, "discourse.ilovefreegle.org/session/sso_login")
	assert.NotContains(t, location, "ssoerror")
}
