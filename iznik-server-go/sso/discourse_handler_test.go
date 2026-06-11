package sso

import (
	"encoding/base64"
	"io"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// newSSOApp returns a minimal fiber app with the DiscourseSSO handler mounted.
func newSSOApp() *fiber.App {
	app := fiber.New(fiber.Config{DisableStartupMessage: true})
	app.Get("/discourse_sso", DiscourseSSO)
	return app
}

// doSSORequest sends a GET to /discourse_sso with the given query parameters.
func doSSORequest(t *testing.T, app *fiber.App, ssoPayload, sig string, cookies ...*http.Cookie) *http.Response {
	t.Helper()
	target := "/discourse_sso?sso=" + url.QueryEscape(ssoPayload) + "&sig=" + url.QueryEscape(sig)
	req := httptest.NewRequest(http.MethodGet, target, nil)
	for _, c := range cookies {
		req.AddCookie(c)
	}
	resp, err := app.Test(req, -1)
	assert.NoError(t, err)
	return resp
}

// -------------------------------------------------------------------------
// extractNonce — missing branch: url.ParseQuery error
// -------------------------------------------------------------------------

// TestExtractNonceInvalidQueryEncoding covers the url.ParseQuery error path.
// Valid base64 decodes to "%ZZ" which is invalid percent-encoding; Z is not a
// hex digit so url.QueryUnescape (called internally by url.ParseQuery) returns
// an EscapeError.
func TestExtractNonceInvalidQueryEncoding(t *testing.T) {
	payload := base64.StdEncoding.EncodeToString([]byte("%ZZ"))
	_, err := extractNonce(payload)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "query parse failed")
}

// -------------------------------------------------------------------------
// extractNonce — table-driven exhaustive branch coverage
// -------------------------------------------------------------------------

func TestExtractNonce_Branches(t *testing.T) {
	enc := func(s string) string { return base64.StdEncoding.EncodeToString([]byte(s)) }

	cases := []struct {
		name      string
		payload   string
		wantNonce string
		wantErr   bool
		errFrag   string
	}{
		// Happy path
		{"happy path simple nonce", enc("nonce=abc123"), "abc123", false, ""},
		{"happy path nonce with extra params", enc("nonce=xyz&return_sso_url=https%3A%2F%2Fx.test"), "xyz", false, ""},
		// Multiple nonce keys — url.Values.Get returns the first occurrence
		{"multiple nonces first wins", enc("nonce=first&nonce=second"), "first", false, ""},

		// base64 decode errors
		{"invalid base64 chars", "!!!", "", true, "base64"},
		{"missing padding StdEncoding", "YQ", "", true, "base64"},

		// url.ParseQuery error
		{"invalid percent-encoding in decoded", enc("%ZZ"), "", true, "query parse failed"},

		// No nonce key
		{"no nonce key", enc("return_sso_url=https%3A%2F%2Fx.test"), "", true, "nonce"},
		{"empty base64 payload (no nonce)", enc(""), "", true, "nonce"},
		{"nonce key present but empty value", enc("nonce="), "", true, "nonce"},
		{"only other params", enc("foo=bar&baz=qux"), "", true, "nonce"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got, err := extractNonce(tc.payload)
			if tc.wantErr {
				assert.Error(t, err)
				if tc.errFrag != "" {
					assert.Contains(t, err.Error(), tc.errFrag)
				}
				assert.Equal(t, "", got)
			} else {
				assert.NoError(t, err)
				assert.Equal(t, tc.wantNonce, got)
			}
		})
	}
}

// -------------------------------------------------------------------------
// validateHMAC — table-driven exhaustive branch coverage
// -------------------------------------------------------------------------

func TestValidateHMAC_Branches(t *testing.T) {
	secret := "s3cr3t"
	goodSig := computeHMAC("payload", secret)

	cases := []struct {
		name      string
		payload   string
		sig       string
		secret    string
		wantValid bool
	}{
		{"matching sig", "payload", goodSig, secret, true},
		{"wrong sig all zeroes", "payload", "0000000000000000000000000000000000000000000000000000000000000000", secret, false},
		{"wrong secret", "payload", goodSig, "other-secret", false},
		{"empty payload matches", "", computeHMAC("", secret), secret, true},
		{"empty payload wrong sig", "", goodSig, secret, false},
		{"empty secret", "x", computeHMAC("x", ""), "", true},
		{"tampered payload", "PAYLOAD", goodSig, secret, false},
		{"sig too short", "payload", "deadbeef", secret, false},
		{"sig too long", "payload", goodSig + "aa", secret, false},
		{"empty sig", "payload", "", secret, false},
		{"both payload and sig empty", "", "", secret, false},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			got := validateHMAC(tc.payload, tc.sig, tc.secret)
			assert.Equal(t, tc.wantValid, got)
		})
	}
}

// -------------------------------------------------------------------------
// computeHMAC — determinism and known vector
// -------------------------------------------------------------------------

func TestComputeHMAC_Deterministic(t *testing.T) {
	// Same inputs always produce the same output.
	a := computeHMAC("data", "key")
	b := computeHMAC("data", "key")
	assert.Equal(t, a, b)
}

func TestComputeHMAC_DifferentInputsDifferentOutput(t *testing.T) {
	assert.NotEqual(t, computeHMAC("a", "k"), computeHMAC("b", "k"))
	assert.NotEqual(t, computeHMAC("a", "k1"), computeHMAC("a", "k2"))
}

func TestComputeHMAC_OutputLength(t *testing.T) {
	// SHA256 hex output is always 64 chars.
	assert.Len(t, computeHMAC("anything", "key"), 64)
	assert.Len(t, computeHMAC("", ""), 64)
}

// -------------------------------------------------------------------------
// buildSSOResponse — table-driven exhaustive branch coverage
// -------------------------------------------------------------------------

func TestBuildSSOResponse_Branches(t *testing.T) {
	cases := []struct {
		name    string
		nonce   string
		session *ssoSession
		checks  map[string]string
	}{
		{
			name:    "admin user all fields",
			nonce:   "n1",
			session: &ssoSession{UserID: 1, Name: "Admin", Email: "a@test.com", GroupList: "G1,G2", Admin: true, IsMod: true},
			checks: map[string]string{
				"nonce": "n1", "email": "a@test.com", "external_id": "1",
				"username": "Admin", "name": "Admin", "admin": "true",
				"avatar_url": "",
			},
		},
		{
			name:    "non-admin user",
			nonce:   "n2",
			session: &ssoSession{UserID: 2, Name: "Mod", Email: "m@test.com", GroupList: "G2", Admin: false, IsMod: true},
			checks:  map[string]string{"admin": "false", "external_id": "2"},
		},
		{
			name:    "zero user id",
			nonce:   "n3",
			session: &ssoSession{UserID: 0, Name: "", Email: "", GroupList: ""},
			checks:  map[string]string{"external_id": "0", "admin": "false"},
		},
		{
			name:    "avatar url set",
			nonce:   "n4",
			session: &ssoSession{UserID: 5, AvatarURL: "https://cdn.example.com/pic.jpg"},
			checks:  map[string]string{"avatar_url": "https://cdn.example.com/pic.jpg"},
		},
		{
			name:    "special chars in name survive round-trip",
			nonce:   "n5",
			session: &ssoSession{UserID: 6, Name: "O'Brien & Associates"},
			checks:  map[string]string{"name": "O'Brien & Associates"},
		},
		{
			name:    "empty group list bio suffix",
			nonce:   "n6",
			session: &ssoSession{UserID: 7, Email: "e@test.com", GroupList: ""},
			checks:  map[string]string{"nonce": "n6"},
		},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			out := buildSSOResponse(tc.nonce, tc.session)
			vals, err := url.ParseQuery(out)
			assert.NoError(t, err)
			for k, want := range tc.checks {
				assert.Equal(t, want, vals.Get(k), "field %q mismatch", k)
			}
		})
	}
}

func TestBuildSSOResponse_BioContainsEmailAndGroups(t *testing.T) {
	s := &ssoSession{UserID: 1, Email: "mod@test.com", GroupList: "Freegle London,Freegle Brighton"}
	out := buildSSOResponse("n", s)
	vals, _ := url.ParseQuery(out)
	bio := vals.Get("bio")
	assert.Contains(t, bio, "mod@test.com")
	assert.Contains(t, bio, "is a mod on Freegle London,Freegle Brighton")
}

func TestBuildSSOResponse_AdminFlagIsLiteralString(t *testing.T) {
	// admin field must be the literal string "true" or "false", not "1"/"0".
	sAdmin := &ssoSession{Admin: true}
	sNonAdmin := &ssoSession{Admin: false}

	vAdmin, _ := url.ParseQuery(buildSSOResponse("n", sAdmin))
	vNon, _ := url.ParseQuery(buildSSOResponse("n", sNonAdmin))

	assert.Equal(t, "true", vAdmin.Get("admin"))
	assert.Equal(t, "false", vNon.Get("admin"))
}

// -------------------------------------------------------------------------
// validateDiscourseSession — early-exit paths (no DB required)
// -------------------------------------------------------------------------

func TestValidateDiscourseSession_EarlyExits(t *testing.T) {
	// Series is no longer a required field (PR #679 fix: series is now emitted as
	// a JSON number; authentication is by id+token only). Only id=0 or empty token
	// trigger "incomplete cookie data" before the DB lookup.
	cases := []struct {
		name    string
		cookie  string
		errFrag string
	}{
		{"invalid json", "not-json", "invalid cookie JSON"},
		{"empty json object", "{}", "incomplete cookie data"},
		{"zero user id", `{"id":0,"series":"s","token":"t"}`, "incomplete cookie data"},
		{"empty token", `{"id":1,"series":"s","token":""}`, "incomplete cookie data"},
		{"truncated json", `{"id":1,"series":`, "invalid cookie JSON"},
		{"null values", `{"id":null,"series":null,"token":null}`, "incomplete cookie data"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := validateDiscourseSession(tc.cookie)
			assert.Error(t, err)
			assert.Contains(t, err.Error(), tc.errFrag)
		})
	}
}

// -------------------------------------------------------------------------
// DiscourseSSO handler — paths that don't require a DB connection
// -------------------------------------------------------------------------

func TestDiscourseSSO_NoSecretReturns500(t *testing.T) {
	os.Unsetenv("DISCOURSE_SECRET")
	app := newSSOApp()
	req := httptest.NewRequest(http.MethodGet, "/discourse_sso?sso=anything&sig=anything", nil)
	resp, err := app.Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusInternalServerError, resp.StatusCode)
	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), "SSO not configured")
}

func TestDiscourseSSO_InvalidSignatureReturns403(t *testing.T) {
	os.Setenv("DISCOURSE_SECRET", "test-secret")
	defer os.Unsetenv("DISCOURSE_SECRET")

	app := newSSOApp()
	resp := doSSORequest(t, app, "somepayload", "wrongsig")
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)
	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), "Invalid signature")
}

func TestDiscourseSSO_EmptySignature(t *testing.T) {
	os.Setenv("DISCOURSE_SECRET", "test-secret")
	defer os.Unsetenv("DISCOURSE_SECRET")

	app := newSSOApp()
	resp := doSSORequest(t, app, "payload", "")
	assert.Equal(t, fiber.StatusForbidden, resp.StatusCode)
}

func TestDiscourseSSO_InvalidBase64Payload(t *testing.T) {
	secret := "test-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	// "!!" is not valid base64 — sign it so signature check passes.
	ssoPayload := "!!invalid-base64!!"
	sig := computeHMAC(ssoPayload, secret)

	app := newSSOApp()
	resp := doSSORequest(t, app, ssoPayload, sig)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), "Invalid SSO payload")
}

func TestDiscourseSSO_ValidBase64ButNoNonce(t *testing.T) {
	secret := "test-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	// Valid base64 whose decoded content has no nonce= param.
	rawPayload := base64.StdEncoding.EncodeToString([]byte("return_sso_url=https%3A%2F%2Fdiscourse.example%2F"))
	sig := computeHMAC(rawPayload, secret)

	app := newSSOApp()
	resp := doSSORequest(t, app, rawPayload, sig)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), "Invalid SSO payload")
}

func TestDiscourseSSO_ValidPayloadNoCookie_RedirectsToLogin(t *testing.T) {
	secret := "test-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	rawPayload := base64.StdEncoding.EncodeToString([]byte("nonce=abc123"))
	sig := computeHMAC(rawPayload, secret)

	app := newSSOApp()
	resp := doSSORequest(t, app, rawPayload, sig) // no cookie
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Location"), "modtools.org/discourse")
}

func TestDiscourseSSO_InvalidJSONCookie_RedirectsToLogin(t *testing.T) {
	secret := "test-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	rawPayload := base64.StdEncoding.EncodeToString([]byte("nonce=abc123"))
	sig := computeHMAC(rawPayload, secret)

	app := newSSOApp()
	resp := doSSORequest(t, app, rawPayload, sig, &http.Cookie{
		Name:  "Iznik-Discourse-SSO",
		Value: "not-valid-json",
	})
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Location"), "modtools.org/discourse")
}

func TestDiscourseSSO_IncompleteCookieZeroID_RedirectsToLogin(t *testing.T) {
	secret := "test-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	rawPayload := base64.StdEncoding.EncodeToString([]byte("nonce=abc123"))
	sig := computeHMAC(rawPayload, secret)

	// id=0 → validateDiscourseSession returns "incomplete cookie data" before DB
	app := newSSOApp()
	resp := doSSORequest(t, app, rawPayload, sig, &http.Cookie{
		Name:  "Iznik-Discourse-SSO",
		Value: `{"id":0,"series":"abc","token":"xyz"}`,
	})
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Location"), "modtools.org/discourse")
}

func TestDiscourseSSO_IncompleteCookieEmptySeries_RedirectsToLogin(t *testing.T) {
	// Series is no longer required (PR #679 fix). A cookie with empty series but
	// valid id+token passes the pre-DB checks and reaches the session lookup.
	// Without a DB this path panics, so this test is skipped. The pre-DB early-exit
	// path for empty series no longer exists — that is the intended post-fix behaviour.
	t.Skip("series is now ignored — empty series with valid id+token reaches DB; no DB in unit tests")
}

func TestDiscourseSSO_IncompleteCookieEmptyToken_RedirectsToLogin(t *testing.T) {
	secret := "test-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	rawPayload := base64.StdEncoding.EncodeToString([]byte("nonce=abc123"))
	sig := computeHMAC(rawPayload, secret)

	app := newSSOApp()
	resp := doSSORequest(t, app, rawPayload, sig, &http.Cookie{
		Name:  "Iznik-Discourse-SSO",
		Value: `{"id":1,"series":"abc","token":""}`,
	})
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
}

func TestDiscourseSSO_URLEncodedCookie_RedirectsToLogin(t *testing.T) {
	// Tests the url.QueryUnescape path in DiscourseSSO: browsers sometimes
	// percent-encode JSON cookie values. The handler decodes before passing
	// to validateDiscourseSession.
	secret := "test-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	rawPayload := base64.StdEncoding.EncodeToString([]byte("nonce=abc123"))
	sig := computeHMAC(rawPayload, secret)

	// URL-encoded JSON with id=0 → still returns "incomplete cookie data".
	encodedCookie := url.QueryEscape(`{"id":0,"series":"s","token":"t"}`)
	app := newSSOApp()
	resp := doSSORequest(t, app, rawPayload, sig, &http.Cookie{
		Name:  "Iznik-Discourse-SSO",
		Value: encodedCookie,
	})
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Contains(t, resp.Header.Get("Location"), "modtools.org/discourse")
}

func TestDiscourseSSO_SignatureRoundTrip(t *testing.T) {
	// Verifies that a correctly signed payload is accepted (reaches the
	// cookie check, not rejected for invalid signature).
	secret := "roundtrip-secret"
	os.Setenv("DISCOURSE_SECRET", secret)
	defer os.Unsetenv("DISCOURSE_SECRET")

	rawPayload := base64.StdEncoding.EncodeToString([]byte("nonce=rtrip123&return_sso_url=https%3A%2F%2Fdiscourse.test%2F"))
	sig := computeHMAC(rawPayload, secret)

	app := newSSOApp()
	// No cookie → redirects to login, not rejected with 403.
	resp := doSSORequest(t, app, rawPayload, sig)
	assert.NotEqual(t, fiber.StatusForbidden, resp.StatusCode, "correct signature must not be rejected")
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
}

// ---------------------------------------------------------------------------
// REGRESSION: PR #679 — series emitted as JSON number causes redirect loop
// ---------------------------------------------------------------------------
//
// Before the fix, the SSO cookie struct had `Series string`. When PR #679
// changed session.go to emit series as a uint64 (JSON number), Unmarshal
// would error trying to decode a number into a string field. The handler then
// redirected back to modtools.org/discourse, which set the cookie again and
// retried — infinite loop, all moderators locked out of Discourse.
//
// After the fix, series is absent from the parse struct; only id+token are
// required. A cookie with series as a number must pass the pre-DB validation
// step (json.Unmarshal must not error).

// TestValidateDiscourseSession_NumericSeries_PassesPreDBCheck verifies that a
// cookie whose "series" field is a JSON number (as emitted by PR #679's session.go)
// does NOT cause json.Unmarshal to fail. On the old code this returned
// "invalid cookie JSON"; on the fixed code it passes the pre-DB checks and
// proceeds to the session lookup (the DB lookup itself fails without a DB, but
// the error is "no valid moderator session found", not "invalid cookie JSON").
func TestValidateDiscourseSession_NumericSeries_PassesPreDBCheck(t *testing.T) {
	// This is the cookie format that PR #679 produces: series is a uint64 number.
	// On the OLD code: json.Unmarshal errors because a number can't decode into
	// a string field → "invalid cookie JSON" → infinite redirect loop.
	// On the FIXED code: series field is absent from the parse struct → Unmarshal
	// succeeds (unknown fields are ignored) → proceeds to DB lookup.
	cookie := `{"id":99,"series":12345,"token":"sometoken"}`
	_, err := validateDiscourseSession(cookie)
	// Must NOT be a JSON parse error — that was the bug. The only acceptable
	// errors here are DB-related (no DB in unit tests).
	if err != nil {
		assert.NotContains(t, err.Error(), "invalid cookie JSON",
			"REGRESSION: numeric series must not cause JSON parse failure (PR #679 redirect loop bug)")
		// pre-DB completeness check should pass: id=99 (nonzero), token="sometoken" (nonempty)
		assert.NotContains(t, err.Error(), "incomplete cookie data",
			"id+token are both present; pre-DB check must pass")
		// Expect a DB-related failure (no session in test DB, or no DB connection).
		// Any DB error here is correct behaviour — the cookie parsed successfully.
	}
	// Note: if validateDiscourseSession returns nil (no error), that means a real
	// DB session row matched — also correct. The key assertion is no JSON error.
}

// TestValidateDiscourseSession_NumericSeriesZeroID_StillRejectsEarly verifies
// that a cookie with a numeric series but id=0 still fails the pre-DB check.
// This ensures the id validation is not broken by the series fix.
func TestValidateDiscourseSession_NumericSeriesZeroID_StillRejectsEarly(t *testing.T) {
	cookie := `{"id":0,"series":12345,"token":"sometoken"}`
	_, err := validateDiscourseSession(cookie)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "incomplete cookie data",
		"id=0 must still be rejected as incomplete even when series is a number")
}

// TestValidateDiscourseSession_NumericSeriesEmptyToken_StillRejectsEarly verifies
// that a cookie with a numeric series but empty token still fails the pre-DB check.
func TestValidateDiscourseSession_NumericSeriesEmptyToken_StillRejectsEarly(t *testing.T) {
	cookie := `{"id":99,"series":12345,"token":""}`
	_, err := validateDiscourseSession(cookie)
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "incomplete cookie data",
		"empty token must still be rejected as incomplete even when series is a number")
}
