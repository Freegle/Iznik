package sso

import (
	"encoding/base64"
	"net/http"
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The ModTools page retries the SSO endpoint only when it is sent back with
// no ?ssoerror. Every refusal of a request that carried a cookie must
// therefore land on a URL that names its failure class, or the page loops.

func TestSSOErrorURL(t *testing.T) {
	assert.Equal(t, "https://modtools.org/discourse?ssoerror=session", ssoErrorURL(ssoFailureSession))
	assert.Equal(t, "https://modtools.org/discourse?ssoerror=notmod", ssoErrorURL(ssoFailureNotMod))
}

func TestIsModSystemrole(t *testing.T) {
	assert.True(t, isModSystemrole("Admin"))
	assert.True(t, isModSystemrole("Support"))
	assert.True(t, isModSystemrole("Moderator"))
	assert.False(t, isModSystemrole("User"))
	assert.False(t, isModSystemrole(""))
}

func TestValidateDiscourseSession_ParseFailuresAreSessionClass(t *testing.T) {
	// A cookie that cannot be read is a session problem, never a role
	// problem: the page tells the moderator to log in again.
	for _, cookie := range []string{"not-json", "{}", `{"id":0,"token":"t"}`, `{"id":5,"token":""}`} {
		session, failure, err := validateDiscourseSession(cookie)
		assert.Nil(t, session, cookie)
		assert.Error(t, err, cookie)
		assert.Equal(t, ssoFailureSession, failure, cookie)
	}
}

func signedNonce(t *testing.T, secret string) (string, string) {
	t.Helper()
	payload := base64.StdEncoding.EncodeToString([]byte("nonce=abc123"))
	return payload, computeHMAC(payload, secret)
}

func TestDiscourseSSO_NoCookie_RedirectsWithoutErrorClass(t *testing.T) {
	// The one redirect the page is allowed to retry: the exact page URL, no
	// ?ssoerror, so the page sets the cookie and comes back once.
	secret := "test-secret"
	t.Setenv("DISCOURSE_SECRET", secret)
	payload, sig := signedNonce(t, secret)

	resp := doSSORequest(t, newSSOApp(), payload, sig)
	require.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Equal(t, "https://modtools.org/discourse", resp.Header.Get("Location"))
}

func TestDiscourseSSO_UnreadableCookie_RedirectsWithSessionError(t *testing.T) {
	secret := "test-secret"
	t.Setenv("DISCOURSE_SECRET", secret)
	payload, sig := signedNonce(t, secret)

	for _, value := range []string{"not-json", url.QueryEscape(`{"id":0,"series":1,"token":"t"}`)} {
		resp := doSSORequest(t, newSSOApp(), payload, sig, &http.Cookie{Name: "Iznik-Discourse-SSO", Value: value})
		require.Equal(t, fiber.StatusFound, resp.StatusCode, value)
		assert.Equal(t, "https://modtools.org/discourse?ssoerror=session", resp.Header.Get("Location"), value)
	}
}

func TestDiscourseSSO_ErrorRedirectIsNotTheRetryURL(t *testing.T) {
	// Guards the property the page relies on: a refused request never lands
	// on the bare page URL that triggers a retry.
	secret := "test-secret"
	t.Setenv("DISCOURSE_SECRET", secret)
	payload, sig := signedNonce(t, secret)

	req := httptest.NewRequest(http.MethodGet, "/discourse_sso?sso="+url.QueryEscape(payload)+"&sig="+url.QueryEscape(sig), nil)
	req.AddCookie(&http.Cookie{Name: "Iznik-Discourse-SSO", Value: "{}"})
	resp, err := newSSOApp().Test(req, -1)
	require.NoError(t, err)
	assert.NotEqual(t, modtoolsDiscoursePage, resp.Header.Get("Location"))
}
