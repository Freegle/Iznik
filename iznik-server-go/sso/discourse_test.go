package sso

import (
	"encoding/base64"
	"net/url"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

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
