package test

import (
	"crypto/rand"
	"crypto/rsa"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/golang-jwt/jwt/v4"
	"github.com/stretchr/testify/assert"
)

// newAppleJWKSServer returns a mock JWKS server for Apple sign-in testing.
func newAppleJWKSServer(privateKey *rsa.PrivateKey, kid string) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		nBytes := privateKey.PublicKey.N.Bytes()
		eBytes := big.NewInt(int64(privateKey.PublicKey.E)).Bytes()
		jwks := map[string]interface{}{
			"keys": []map[string]string{
				{
					"kty": "RSA",
					"kid": kid,
					"use": "sig",
					"alg": "RS256",
					"n":   base64.RawURLEncoding.EncodeToString(nBytes),
					"e":   base64.RawURLEncoding.EncodeToString(eBytes),
				},
			},
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(jwks)
	}))
}

// makeAppleIdentityToken signs a JWT with the given RSA key, mimicking an Apple identity token.
func makeAppleIdentityToken(privateKey *rsa.PrivateKey, kid, sub, email, givenName, familyName string) string {
	claims := jwt.MapClaims{
		"iss": "https://appleid.apple.com",
		"sub": sub,
		"iat": time.Now().Unix(),
		"exp": time.Now().Add(time.Hour).Unix(),
	}
	if email != "" {
		claims["email"] = email
	}
	token := jwt.NewWithClaims(jwt.SigningMethodRS256, claims)
	token.Header["kid"] = kid
	signed, _ := token.SignedString(privateKey)
	return signed
}

// postAppleSession sends an Apple login request to the test session endpoint.
func postAppleSession(identityToken, appleUserID, email, givenName, familyName string) *http.Response {
	creds := map[string]string{
		"identityToken":     identityToken,
		"user":              appleUserID,
		"email":             email,
		"givenName":         givenName,
		"familyName":        familyName,
		"authorizationCode": "fake-auth-code",
	}
	credsJSON, _ := json.Marshal(creds)
	body := fmt.Sprintf(`{"applelogin":true,"applecredentials":%s}`, string(credsJSON))
	return postSession(body)
}

func TestAppleLoginNewUser(t *testing.T) {
	prefix := uniquePrefix("apple-new")
	email := fmt.Sprintf("%s@privaterelay.appleid.com", prefix)
	appleUID := "apple-uid-" + prefix

	privateKey, err := rsa.GenerateKey(rand.Reader, 2048)
	assert.NoError(t, err)
	kid := "apple-test-kid-1"

	jwksServer := newAppleJWKSServer(privateKey, kid)
	defer jwksServer.Close()

	identityToken := makeAppleIdentityToken(privateKey, kid, appleUID, email, "Apple", "User")

	os.Setenv("APPLE_JWKS_URL", jwksServer.URL)
	defer os.Unsetenv("APPLE_JWKS_URL")

	resp := postAppleSession(identityToken, appleUID, email, "Apple", "User")
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])
	assert.NotNil(t, result["jwt"])
	assert.NotNil(t, result["persistent"])

	// Verify user was created with Apple login record.
	db := database.DBConn
	var userID uint64
	db.Raw("SELECT userid FROM users_logins WHERE type = 'Apple' AND uid = ?", appleUID).Scan(&userID)
	assert.NotEqual(t, uint64(0), userID)

	// Cleanup.
	db.Exec("DELETE FROM users_logins WHERE userid = ?", userID)
	db.Exec("DELETE FROM users_emails WHERE userid = ?", userID)
	db.Exec("DELETE FROM sessions WHERE userid = ?", userID)
	db.Exec("DELETE FROM users WHERE id = ?", userID)
}

func TestAppleLoginNewUserEmailHidden(t *testing.T) {
	// Apple hides email after the first sign-in — identityToken has no email claim.
	prefix := uniquePrefix("apple-no-email")
	appleUID := "apple-uid-" + prefix

	privateKey, err := rsa.GenerateKey(rand.Reader, 2048)
	assert.NoError(t, err)
	kid := "apple-test-kid-ne"

	jwksServer := newAppleJWKSServer(privateKey, kid)
	defer jwksServer.Close()

	// No email in the token.
	identityToken := makeAppleIdentityToken(privateKey, kid, appleUID, "", "", "")

	os.Setenv("APPLE_JWKS_URL", jwksServer.URL)
	defer os.Unsetenv("APPLE_JWKS_URL")

	resp := postAppleSession(identityToken, appleUID, "", "", "")
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])

	db := database.DBConn
	var userID uint64
	db.Raw("SELECT userid FROM users_logins WHERE type = 'Apple' AND uid = ?", appleUID).Scan(&userID)
	assert.NotEqual(t, uint64(0), userID)

	// Cleanup.
	db.Exec("DELETE FROM users_logins WHERE userid = ?", userID)
	db.Exec("DELETE FROM users_emails WHERE userid = ?", userID)
	db.Exec("DELETE FROM sessions WHERE userid = ?", userID)
	db.Exec("DELETE FROM users WHERE id = ?", userID)
}

func TestAppleLoginExistingUserByEmail(t *testing.T) {
	prefix := uniquePrefix("apple-email")
	email := fmt.Sprintf("%s@test.com", prefix)
	appleUID := "apple-uid-" + prefix
	userID := CreateTestUser(t, prefix, "User")

	privateKey, err := rsa.GenerateKey(rand.Reader, 2048)
	assert.NoError(t, err)
	kid := "apple-test-kid-2"

	jwksServer := newAppleJWKSServer(privateKey, kid)
	defer jwksServer.Close()

	identityToken := makeAppleIdentityToken(privateKey, kid, appleUID, email, "Test", prefix)

	os.Setenv("APPLE_JWKS_URL", jwksServer.URL)
	defer os.Unsetenv("APPLE_JWKS_URL")

	resp := postAppleSession(identityToken, appleUID, email, "Test", prefix)
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])

	// Apple login should be linked to the existing user.
	db := database.DBConn
	var loginUserID uint64
	db.Raw("SELECT userid FROM users_logins WHERE type = 'Apple' AND uid = ?", appleUID).Scan(&loginUserID)
	assert.Equal(t, userID, loginUserID)

	// Cleanup.
	db.Exec("DELETE FROM users_logins WHERE userid = ? AND type = 'Apple'", userID)
	db.Exec("DELETE FROM sessions WHERE userid = ?", userID)
}

func TestAppleLoginExistingUserByAppleUID(t *testing.T) {
	prefix := uniquePrefix("apple-uid")
	appleUID := "apple-uid-" + prefix
	userID := CreateTestUser(t, prefix, "User")

	db := database.DBConn
	db.Exec("INSERT INTO users_logins (userid, type, uid) VALUES (?, 'Apple', ?)", userID, appleUID)

	privateKey, err := rsa.GenerateKey(rand.Reader, 2048)
	assert.NoError(t, err)
	kid := "apple-test-kid-3"

	jwksServer := newAppleJWKSServer(privateKey, kid)
	defer jwksServer.Close()

	// Different email from what's on file — found by UID, not email.
	identityToken := makeAppleIdentityToken(privateKey, kid, appleUID, "different@apple.com", "", "")

	os.Setenv("APPLE_JWKS_URL", jwksServer.URL)
	defer os.Unsetenv("APPLE_JWKS_URL")

	resp := postAppleSession(identityToken, appleUID, "different@apple.com", "", "")
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])

	// Cleanup.
	db.Exec("DELETE FROM users_logins WHERE userid = ? AND type = 'Apple'", userID)
	db.Exec("DELETE FROM sessions WHERE userid = ?", userID)
}

func TestAppleLoginInvalidToken(t *testing.T) {
	privateKey, err := rsa.GenerateKey(rand.Reader, 2048)
	assert.NoError(t, err)
	kid := "apple-test-kid-bad"

	// JWKS server with a DIFFERENT key — signature will not verify.
	wrongKey, err := rsa.GenerateKey(rand.Reader, 2048)
	assert.NoError(t, err)

	jwksServer := newAppleJWKSServer(wrongKey, kid)
	defer jwksServer.Close()

	identityToken := makeAppleIdentityToken(privateKey, kid, "bad-uid", "bad@apple.com", "", "")

	os.Setenv("APPLE_JWKS_URL", jwksServer.URL)
	defer os.Unsetenv("APPLE_JWKS_URL")

	resp := postAppleSession(identityToken, "bad-uid", "bad@apple.com", "", "")
	assert.Equal(t, 401, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(2), result["ret"])
}

func TestAppleLoginMissingToken(t *testing.T) {
	body := `{"applelogin":true,"applecredentials":{"identityToken":"","user":"some-uid"}}`
	resp := postSession(body)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestAppleLoginTNUser(t *testing.T) {
	prefix := uniquePrefix("apple-tn")
	email := fmt.Sprintf("%s@test.com", prefix)

	db := database.DBConn
	fullname := fmt.Sprintf("TN User %s", prefix)
	db.Exec("INSERT INTO users (firstname, lastname, fullname, systemrole, tnuserid) VALUES ('TN', ?, ?, 'User', 99999)",
		prefix, fullname)

	var userID uint64
	db.Raw("SELECT id FROM users WHERE fullname = ? ORDER BY id DESC LIMIT 1", fullname).Scan(&userID)
	if userID == 0 {
		t.Fatalf("Failed to create TN test user")
	}
	db.Exec("INSERT INTO users_emails (userid, email) VALUES (?, ?)", userID, email)

	privateKey, err := rsa.GenerateKey(rand.Reader, 2048)
	assert.NoError(t, err)
	kid := "apple-test-kid-tn"

	jwksServer := newAppleJWKSServer(privateKey, kid)
	defer jwksServer.Close()

	appleUID := "apple-tn-uid-" + prefix
	identityToken := makeAppleIdentityToken(privateKey, kid, appleUID, email, "TN", "User")

	os.Setenv("APPLE_JWKS_URL", jwksServer.URL)
	defer os.Unsetenv("APPLE_JWKS_URL")

	resp := postAppleSession(identityToken, appleUID, email, "TN", "User")
	assert.Equal(t, 403, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	assert.Equal(t, float64(3), result["ret"])
	assert.True(t, strings.Contains(result["status"].(string), "TN user"))

	// Cleanup.
	db.Exec("DELETE FROM users_emails WHERE userid = ?", userID)
	db.Exec("DELETE FROM sessions WHERE userid = ?", userID)
	db.Exec("DELETE FROM users WHERE id = ?", userID)
}
