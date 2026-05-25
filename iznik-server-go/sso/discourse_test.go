package sso

import (
	"encoding/base64"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// Helpers for DiscourseSSO endpoint tests
// ---------------------------------------------------------------------------

func newDiscourseSSOApp() *fiber.App {
	app := fiber.New()
	app.Get("/discourse_sso", DiscourseSSO)
	return app
}

// makeValidSSOParams builds a base64-encoded SSO payload with a nonce and returns
// the sso param and the HMAC-SHA256 sig, both already URL-encoded for query use.
func makeValidSSOParams(secret string) (ssoParam, sigParam string) {
	raw := "nonce=testnonce&return_sso_url=https%3A%2F%2Fdiscourse.ilovefreegle.org%2Fsession%2Fsso_login"
	encoded := base64.StdEncoding.EncodeToString([]byte(raw))
	sig := computeHMAC(encoded, secret)
	return url.QueryEscape(encoded), url.QueryEscape(sig)
}

func TestComputeHMACKnownVector(t *testing.T) {
	// HMAC-SHA256("hello", "key") — standard reference vector.
	got := computeHMAC("hello", "key")
	assert.Equal(t, "9307b3b915efb5171ff14d8cb55fbcc798c6c0ef1456d66ded1a6aa723a58b7b", got)
}

func TestValidateHMACMatches(t *testing.T) {
	secret := "discourse-shared-secret"
	payload := "nonce=abc&return_sso_url=https%3A%2F%2Fexample.com"
	sig := computeHMAC(payload, secret)
	assert.True(t, validateHMAC(payload, sig, secret))
}

func TestValidateHMACMismatchedSignature(t *testing.T) {
	// Tampered signature must be rejected.
	assert.False(t, validateHMAC("payload", "deadbeef", "secret"))
}

func TestValidateHMACWrongSecret(t *testing.T) {
	secret := "correct-secret"
	payload := "anything"
	sig := computeHMAC(payload, secret)
	// Same payload + sig but wrong secret must fail.
	assert.False(t, validateHMAC(payload, sig, "wrong-secret"))
}

func TestValidateHMACEmpty(t *testing.T) {
	// Empty strings still produce a deterministic HMAC; signature must match.
	sig := computeHMAC("", "secret")
	assert.True(t, validateHMAC("", sig, "secret"))
	assert.False(t, validateHMAC("", "", "secret"))
}

func TestExtractNonceHappyPath(t *testing.T) {
	raw := "nonce=xyz123&return_sso_url=https%3A%2F%2Fdiscourse.example%2Fsession%2Fsso_login"
	payload := base64.StdEncoding.EncodeToString([]byte(raw))
	nonce, err := extractNonce(payload)
	assert.NoError(t, err)
	assert.Equal(t, "xyz123", nonce)
}

func TestExtractNonceBadBase64(t *testing.T) {
	// "!!!" is not valid base64.
	_, err := extractNonce("!!!")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "base64")
}

func TestExtractNonceNoNonceParam(t *testing.T) {
	// Valid base64, valid query string, but no nonce= key.
	payload := base64.StdEncoding.EncodeToString([]byte("return_sso_url=https%3A%2F%2Fx.test"))
	_, err := extractNonce(payload)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "nonce")
}

func TestExtractNonceEmptyPayload(t *testing.T) {
	// Empty payload decodes to empty string → parse succeeds with no keys → no nonce.
	_, err := extractNonce("")
	assert.Error(t, err)
}

func TestBuildSSOResponseContainsAllFields(t *testing.T) {
	s := &ssoSession{
		UserID:    42,
		Name:      "Alice",
		AvatarURL: "https://example.com/a.jpg",
		Admin:     true,
		Email:     "alice@example.com",
		GroupList: "Freegle London,Freegle Brighton",
		IsMod:     true,
	}
	out := buildSSOResponse("nonce-value", s)
	vals, err := url.ParseQuery(out)
	assert.NoError(t, err)
	assert.Equal(t, "nonce-value", vals.Get("nonce"))
	assert.Equal(t, "alice@example.com", vals.Get("email"))
	assert.Equal(t, "42", vals.Get("external_id"))
	assert.Equal(t, "Alice", vals.Get("username"))
	assert.Equal(t, "Alice", vals.Get("name"))
	assert.Equal(t, "https://example.com/a.jpg", vals.Get("avatar_url"))
	assert.Equal(t, "true", vals.Get("admin"))
	// Bio combines email + group list in the shape the PHP SSO expected.
	bio := vals.Get("bio")
	assert.Contains(t, bio, "alice@example.com")
	assert.Contains(t, bio, "is a mod on Freegle London,Freegle Brighton")
}

func TestBuildSSOResponseNonAdmin(t *testing.T) {
	// Non-admin users get admin=false (literal string, not omitted).
	s := &ssoSession{UserID: 1, Name: "Bob", Email: "b@x", GroupList: "G1"}
	out := buildSSOResponse("n", s)
	vals, _ := url.ParseQuery(out)
	assert.Equal(t, "false", vals.Get("admin"))
}

func TestBuildSSOResponseEmptyGroupList(t *testing.T) {
	// If the user has no groups, the bio still renders — just with nothing after "mod on".
	s := &ssoSession{UserID: 7, Name: "Cleo", Email: "c@x", GroupList: ""}
	out := buildSSOResponse("n", s)
	vals, _ := url.ParseQuery(out)
	bio := vals.Get("bio")
	assert.Contains(t, bio, "c@x")
	assert.True(t, strings.HasSuffix(bio, "is a mod on "))
}

func TestBuildSSOResponseRoundTripsViaHMAC(t *testing.T) {
	// End-to-end: build a response, sign it, validate the signature.
	secret := "test-secret"
	s := &ssoSession{UserID: 99, Name: "Dan", Email: "d@x", GroupList: "G"}
	payload := buildSSOResponse("nonce", s)
	encoded := base64.StdEncoding.EncodeToString([]byte(payload))
	sig := computeHMAC(encoded, secret)
	assert.True(t, validateHMAC(encoded, sig, secret))
}

// ---------------------------------------------------------------------------
// extractNonce — missing url.ParseQuery error branch (10% gap)
// ---------------------------------------------------------------------------

func TestExtractNonce_InvalidQueryString(t *testing.T) {
	// base64 of a string containing an invalid percent-encoded sequence.
	// url.ParseQuery returns an error for malformed escape sequences like %zz.
	raw := "nonce=abc&bad=%zz"
	payload := base64.StdEncoding.EncodeToString([]byte(raw))
	_, err := extractNonce(payload)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "query parse")
}

// ---------------------------------------------------------------------------
// DiscourseSSO — DB-free paths (function is at 0% coverage)
// ---------------------------------------------------------------------------

func TestDiscourseSSO_NoSecret(t *testing.T) {
	// When DISCOURSE_SECRET is not set, the handler returns 500 before touching DB.
	os.Unsetenv("DISCOURSE_SECRET")
	app := newDiscourseSSOApp()
	req := httptest.NewRequest("GET", "/discourse_sso?sso=anything&sig=anysig", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusInternalServerError, resp.StatusCode)
}

func TestDiscourseSSO_InvalidSignature(t *testing.T) {
	// When HMAC check fails, handler returns 403 before touching DB.
	os.Setenv("DISCOURSE_SECRET", "correct-secret")
	defer os.Unsetenv("DISCOURSE_SECRET")
	app := newDiscourseSSOApp()
	// Use a valid base64 payload but a wrong sig.
	payload := base64.StdEncoding.EncodeToString([]byte("nonce=x"))
	req := httptest.NewRequest("GET", "/discourse_sso?sso="+url.QueryEscape(payload)+"&sig=badsig", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)
}

func TestDiscourseSSO_ValidSig_NoCookie(t *testing.T) {
	// Valid HMAC but no cookie → redirects to modtools.org (no DB call).
	secret := "sso-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")
	ssoP, sigP := makeValidSSOParams(secret)
	app := newDiscourseSSOApp()
	req := httptest.NewRequest("GET", "/discourse_sso?sso="+ssoP+"&sig="+sigP, nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Location"), "modtools.org/discourse")
}

func TestDiscourseSSO_ValidSig_InvalidCookieJSON(t *testing.T) {
	// Valid HMAC and a cookie that is not valid JSON → validateDiscourseSession
	// returns an error without hitting the DB → redirects to modtools.
	secret := "sso-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")
	ssoP, sigP := makeValidSSOParams(secret)
	app := newDiscourseSSOApp()
	req := httptest.NewRequest("GET", "/discourse_sso?sso="+ssoP+"&sig="+sigP, nil)
	req.Header.Set("Cookie", "Iznik-Discourse-SSO=notjson{{}}")
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Location"), "modtools.org/discourse")
}

func TestDiscourseSSO_ValidSig_URLEncodedCookie_InvalidJSON(t *testing.T) {
	// Cookie is URL-encoded; after QueryUnescape it still isn't valid JSON →
	// validateDiscourseSession returns error → redirect to modtools.
	secret := "sso-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")
	ssoP, sigP := makeValidSSOParams(secret)
	app := newDiscourseSSOApp()
	req := httptest.NewRequest("GET", "/discourse_sso?sso="+ssoP+"&sig="+sigP, nil)
	// URL-encode something that decodes to invalid JSON.
	req.Header.Set("Cookie", "Iznik-Discourse-SSO="+url.QueryEscape("broken{json"))
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Location"), "modtools.org/discourse")
}

func TestDiscourseSSO_ValidSig_IncompleteCookieData(t *testing.T) {
	// Cookie is valid JSON but fields are zero/empty → validateDiscourseSession
	// returns "incomplete cookie data" error without touching the DB.
	secret := "sso-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")
	ssoP, sigP := makeValidSSOParams(secret)
	app := newDiscourseSSOApp()
	req := httptest.NewRequest("GET", "/discourse_sso?sso="+ssoP+"&sig="+sigP, nil)
	// id=0, series and token present — triggers the incomplete-data check.
	req.Header.Set("Cookie", `Iznik-Discourse-SSO=`+url.QueryEscape(`{"id":0,"series":"s","token":"t"}`))
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Location"), "modtools.org/discourse")
}

// ---------------------------------------------------------------------------
// validateDiscourseSession — pre-DB error paths (currently 0% coverage)
// ---------------------------------------------------------------------------

func TestValidateDiscourseSession_InvalidJSON(t *testing.T) {
	_, err := validateDiscourseSession("}{not json")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "invalid cookie JSON")
}

func TestValidateDiscourseSession_ZeroID(t *testing.T) {
	_, err := validateDiscourseSession(`{"id":0,"series":"abc","token":"def"}`)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "incomplete cookie data")
}

func TestValidateDiscourseSession_EmptySeries(t *testing.T) {
	_, err := validateDiscourseSession(`{"id":42,"series":"","token":"def"}`)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "incomplete cookie data")
}

func TestValidateDiscourseSession_EmptyToken(t *testing.T) {
	_, err := validateDiscourseSession(`{"id":42,"series":"abc","token":""}`)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "incomplete cookie data")
}

func TestValidateDiscourseSession_AllFieldsMissing(t *testing.T) {
	// Empty JSON object — all fields are zero values.
	_, err := validateDiscourseSession(`{}`)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "incomplete cookie data")
}

// ---------------------------------------------------------------------------
// DiscourseSSO table-driven — parameter validation
// ---------------------------------------------------------------------------

func TestDiscourseSSO_NoSecretAlwaysReturns500(t *testing.T) {
	// Regardless of payload/sig values, missing secret = 500.
	os.Unsetenv("DISCOURSE_SECRET")
	app := newDiscourseSSOApp()
	cases := []struct {
		name string
		url  string
	}{
		{"both empty", "/discourse_sso"},
		{"sso only", "/discourse_sso?sso=abc"},
		{"sig only", "/discourse_sso?sig=abc"},
		{"both present", "/discourse_sso?sso=abc&sig=def"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest("GET", tc.url, nil)
			resp, err := app.Test(req)
			assert.NoError(t, err)
			assert.Equal(t, fiber.StatusInternalServerError, resp.StatusCode)
		})
	}
}
