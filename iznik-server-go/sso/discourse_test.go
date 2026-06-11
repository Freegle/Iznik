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
	"github.com/stretchr/testify/require"
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
	// id=0 triggers the incomplete-data check (series is ignored; authentication
	// is by id+token only after PR #679 fix).
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

// ----- DiscourseSSO handler — pre-DB early-exit paths --------------------
// newSSOApp is defined in discourse_handler_test.go (same package).

// makeSignedPayload returns a base64-encoded payload and its HMAC-SHA256 signature.
func makeSignedPayload(rawPayload, secret string) (string, string) {
	encoded := base64.StdEncoding.EncodeToString([]byte(rawPayload))
	sig := computeHMAC(encoded, secret)
	return encoded, sig
}

func TestDiscourseSSO_MissingSecret_Returns500(t *testing.T) {
	// DISCOURSE_SECRET env var not set → 500 Internal Server Error.
	t.Setenv("DISCOURSE_SECRET", "")
	app := newSSOApp()
	req := httptest.NewRequest("GET", "/discourse_sso?sso=anything&sig=anysig", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusInternalServerError, resp.StatusCode)
}

func TestDiscourseSSO_InvalidHMAC_Returns403(t *testing.T) {
	// Secret is set but the signature doesn't match → 403 Forbidden.
	t.Setenv("DISCOURSE_SECRET", "my-secret")
	app := newSSOApp()
	req := httptest.NewRequest("GET", "/discourse_sso?sso=validpayload&sig=wrongsig", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)
}

func TestDiscourseSSO_BadBase64Payload_Returns400(t *testing.T) {
	// Valid HMAC over a payload that, once base64-decoded, fails as URL-encoded nonce.
	// We sign the raw literal "!!!" which is valid base64 only with padding, and
	// the decoded bytes are not valid URL-encoded form → nonce extraction fails.
	secret := "my-secret"
	t.Setenv("DISCOURSE_SECRET", secret)
	app := newSSOApp()

	// "!!!" is not standard base64 — decoding fails → extractNonce returns error.
	badPayload := "!!!"
	sig := computeHMAC(badPayload, secret)
	req := httptest.NewRequest("GET", "/discourse_sso?sso="+url.QueryEscape(badPayload)+"&sig="+url.QueryEscape(sig), nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestDiscourseSSO_ValidPayload_NoCookie_Redirects(t *testing.T) {
	// Signature valid, nonce extracted, but no Iznik-Discourse-SSO cookie → redirect.
	secret := "my-secret"
	t.Setenv("DISCOURSE_SECRET", secret)
	app := newSSOApp()

	rawPayload := "nonce=abc123&return_sso_url=https%3A%2F%2Fdiscourse.example.com"
	encoded, sig := makeSignedPayload(rawPayload, secret)

	req := httptest.NewRequest("GET", "/discourse_sso?sso="+url.QueryEscape(encoded)+"&sig="+url.QueryEscape(sig), nil)
	resp, err := app.Test(req, -1) // -1: don't follow redirects
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Location"), "modtools.org")
}

func TestDiscourseSSO_MultipleNonceValues_ExtractsFirst(t *testing.T) {
	// Duplicate nonce keys in the payload — url.Values.Get returns the first;
	// the handler must still accept the request and reach the redirect path.
	secret := "my-secret"
	t.Setenv("DISCOURSE_SECRET", secret)
	app := newSSOApp()

	rawPayload := "nonce=first&nonce=second"
	encoded, sig := makeSignedPayload(rawPayload, secret)
	req := httptest.NewRequest("GET", "/discourse_sso?sso="+url.QueryEscape(encoded)+"&sig="+url.QueryEscape(sig), nil)
	resp, err := app.Test(req, -1)
	require.NoError(t, err)
	// No cookie → redirect (not a DB-dependent path).
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
}

// ----- validateDiscourseSession — pre-DB validation ----------------------

func TestValidateDiscourseSession_InvalidJSON_ReturnsError(t *testing.T) {
	_, err := validateDiscourseSession("{not valid json}")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "invalid cookie JSON")
}

func TestValidateDiscourseSession_ZeroID(t *testing.T) {
	_, err := validateDiscourseSession(`{"id":0,"series":"abc","token":"def"}`)
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

func TestValidateDiscourseSession_MissingFields(t *testing.T) {
	// Table of cookie values where at least one required field (id or token) is
	// zero/empty. Series is no longer a required field (PR #679 fix: series is now
	// emitted as a JSON number; authentication is by id+token only).
	// All of these return before touching the DB.
	tests := []struct {
		name   string
		cookie string
	}{
		{"ID zero", `{"id":0,"series":"s","token":"t"}`},
		{"token empty", `{"id":1,"series":"s","token":""}`},
		{"all empty", `{"id":0,"series":"","token":""}`},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			_, err := validateDiscourseSession(tc.cookie)
			assert.Error(t, err)
			assert.Contains(t, err.Error(), "incomplete cookie data")
		})
	}
}

func TestValidateDiscourseSession_ValidJSONCompleteFields_TriesDB(t *testing.T) {
	// Cookie has all required fields — the function proceeds past pre-DB checks
	// and hits database.DBConn. Without a DB this panics. Skip if no DB.
	// This test documents that the pre-DB path is not an exit point here.
	t.Skip("requires database — verifies that pre-DB checks pass; DB path not reachable in unit tests")
	_, _ = validateDiscourseSession(`{"id":1,"series":"abc","token":"xyz"}`)
}

// ----- os.Setenv safety — restore original value after tests that mutate env -----

func init() {
	// Ensure DISCOURSE_SECRET is unset between test runs so test isolation
	// is maintained if the test binary reuses the process environment.
	os.Unsetenv("DISCOURSE_SECRET")
}
