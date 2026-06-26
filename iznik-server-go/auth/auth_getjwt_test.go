package auth

// Internal test package so coverage is tracked for auth.go directly.
// These tests exercise GetJWTFromRequest without requiring a DB connection.

import (
	"io"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
)

const testSecret = "test_jwt_secret_for_unit_tests"

// jwtTestApp returns a minimal fiber app that calls GetJWTFromRequest and
// writes the three return values as response headers.
func jwtTestApp() *fiber.App {
	app := fiber.New()
	app.Get("/", func(c *fiber.Ctx) error {
		id, sessionID, exp := GetJWTFromRequest(c)
		c.Response().Header.Set("X-ID", strconv.FormatUint(id, 10))
		c.Response().Header.Set("X-SessionID", strconv.FormatUint(sessionID, 10))
		c.Response().Header.Set("X-Exp", strconv.FormatFloat(exp, 'f', 0, 64))
		return nil
	})
	return app
}

// makeToken signs a MapClaims with testSecret using HS256.
func makeToken(claims jwt.MapClaims) string {
	tok := jwt.NewWithClaims(jwt.SigningMethodHS256, claims)
	s, _ := tok.SignedString([]byte(testSecret))
	return s
}

// validClaims returns claims that will parse successfully for the given IDs.
func validClaims(userID, sessionID uint64) jwt.MapClaims {
	return jwt.MapClaims{
		"id":        strconv.FormatUint(userID, 10),
		"sessionid": strconv.FormatUint(sessionID, 10),
		"exp":       time.Now().Add(time.Hour).Unix(),
	}
}

// invokeJWT calls GET / on the test app with the given auth header and optional
// jwt query param. Returns the parsed (id, sessionID) from response headers.
func invokeJWT(t *testing.T, authHeader, jwtQuery string) (uint64, uint64) {
	t.Helper()
	t.Setenv("JWT_SECRET", testSecret)
	app := jwtTestApp()

	url := "/"
	if jwtQuery != "" {
		url = "/?jwt=" + jwtQuery
	}
	req := httptest.NewRequest("GET", url, nil)
	if authHeader != "" {
		req.Header.Set("Authorization", authHeader)
	}
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("app.Test: %v", err)
	}
	defer resp.Body.Close()
	io.ReadAll(resp.Body) //nolint:errcheck

	id, _ := strconv.ParseUint(resp.Header.Get("X-ID"), 10, 64)
	sess, _ := strconv.ParseUint(resp.Header.Get("X-SessionID"), 10, 64)
	return id, sess
}

// ---------------------------------------------------------------------------
// No JWT present
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_NoJWT(t *testing.T) {
	id, sess := invokeJWT(t, "", "")
	if id != 0 || sess != 0 {
		t.Errorf("no JWT: got (%d,%d), want (0,0)", id, sess)
	}
}

func TestGetJWTFromRequest_EmptyAuthorizationHeader(t *testing.T) {
	// Explicit empty string for Authorization → falls through to return 0,0,0.
	id, sess := invokeJWT(t, "", "")
	if id != 0 || sess != 0 {
		t.Errorf("empty auth header: got (%d,%d), want (0,0)", id, sess)
	}
}

// ---------------------------------------------------------------------------
// Valid JWT in Authorization header
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_ValidJWTInHeader(t *testing.T) {
	tok := makeToken(validClaims(42, 7))
	id, sess := invokeJWT(t, tok, "")
	if id != 42 || sess != 7 {
		t.Errorf("valid header JWT: got (%d,%d), want (42,7)", id, sess)
	}
}

// ---------------------------------------------------------------------------
// Valid JWT in query param
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_ValidJWTInQueryParam(t *testing.T) {
	tok := makeToken(validClaims(99, 3))
	id, sess := invokeJWT(t, "", tok)
	if id != 99 || sess != 3 {
		t.Errorf("valid query JWT: got (%d,%d), want (99,3)", id, sess)
	}
}

// When both query param and header are present, the query param is tried first
// (it's read first in the implementation) and wins.
func TestGetJWTFromRequest_QueryParamWinsOverHeader(t *testing.T) {
	headerTok := makeToken(validClaims(1, 1))
	queryTok := makeToken(validClaims(55, 8))
	id, sess := invokeJWT(t, headerTok, queryTok)
	if id != 55 || sess != 8 {
		t.Errorf("query-over-header: got (%d,%d), want (55,8)", id, sess)
	}
}

// ---------------------------------------------------------------------------
// Quote-stripping in token string
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_TokenWithLeadingQuote(t *testing.T) {
	tok := makeToken(validClaims(10, 2))
	id, sess := invokeJWT(t, `"`+tok, "")
	if id != 10 || sess != 2 {
		t.Errorf("leading quote: got (%d,%d), want (10,2)", id, sess)
	}
}

func TestGetJWTFromRequest_TokenWithTrailingQuote(t *testing.T) {
	tok := makeToken(validClaims(11, 3))
	id, sess := invokeJWT(t, tok+`"`, "")
	if id != 11 || sess != 3 {
		t.Errorf("trailing quote: got (%d,%d), want (11,3)", id, sess)
	}
}

func TestGetJWTFromRequest_TokenWithBothQuotes(t *testing.T) {
	tok := makeToken(validClaims(12, 4))
	id, sess := invokeJWT(t, `"`+tok+`"`, "")
	if id != 12 || sess != 4 {
		t.Errorf("both quotes: got (%d,%d), want (12,4)", id, sess)
	}
}

// ---------------------------------------------------------------------------
// Short token (len ≤ 2 skips the parse block)
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_ShortToken_TwoChars(t *testing.T) {
	id, sess := invokeJWT(t, "ab", "")
	if id != 0 || sess != 0 {
		t.Errorf("2-char token: got (%d,%d), want (0,0)", id, sess)
	}
}

func TestGetJWTFromRequest_ShortToken_OneChar(t *testing.T) {
	id, sess := invokeJWT(t, "x", "")
	if id != 0 || sess != 0 {
		t.Errorf("1-char token: got (%d,%d), want (0,0)", id, sess)
	}
}

// ---------------------------------------------------------------------------
// Invalid / expired / wrong-secret tokens
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_MalformedJWT(t *testing.T) {
	id, sess := invokeJWT(t, "not.a.valid.jwt.string", "")
	if id != 0 || sess != 0 {
		t.Errorf("malformed JWT: got (%d,%d), want (0,0)", id, sess)
	}
}

func TestGetJWTFromRequest_ExpiredJWT(t *testing.T) {
	claims := jwt.MapClaims{
		"id":        "7",
		"sessionid": "2",
		"exp":       time.Now().Add(-time.Hour).Unix(),
	}
	tok := makeToken(claims)
	id, sess := invokeJWT(t, tok, "")
	if id != 0 || sess != 0 {
		t.Errorf("expired JWT: got (%d,%d), want (0,0)", id, sess)
	}
}

func TestGetJWTFromRequest_WrongSecret(t *testing.T) {
	claims := validClaims(5, 1)
	tok := jwt.NewWithClaims(jwt.SigningMethodHS256, claims)
	badTok, _ := tok.SignedString([]byte("wrong_secret"))
	id, sess := invokeJWT(t, badTok, "")
	if id != 0 || sess != 0 {
		t.Errorf("wrong secret: got (%d,%d), want (0,0)", id, sess)
	}
}

// ---------------------------------------------------------------------------
// Wrong signing method triggers the `!ok` branch in the key function
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_WrongSigningMethod(t *testing.T) {
	// Build a token with an RS256 alg header. The key function checks
	// `if _, ok := token.Method.(*jwt.SigningMethodHMAC); !ok` and returns an error,
	// so jwt.Parse returns (token, err) with err != nil — falls through to (0,0,0).
	// We forge this by swapping the header of an HS256 token with an RS256 header.
	// The signature won't match, but that's fine — the alg check fires first.
	rs256Header := "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9" // base64url({"alg":"RS256","typ":"JWT"})
	realTok := makeToken(validClaims(1, 1))
	parts := strings.SplitN(realTok, ".", 3)
	if len(parts) != 3 {
		t.Fatal("unexpected token format")
	}
	tampered := rs256Header + "." + parts[1] + ".fakesig"
	id, sess := invokeJWT(t, tampered, "")
	if id != 0 || sess != 0 {
		t.Errorf("RS256 alg: got (%d,%d), want (0,0)", id, sess)
	}
}

// ---------------------------------------------------------------------------
// Missing / wrong-type claims
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_MissingIDClaim(t *testing.T) {
	claims := jwt.MapClaims{
		// "id" is absent
		"sessionid": "5",
		"exp":       time.Now().Add(time.Hour).Unix(),
	}
	tok := makeToken(claims)
	id, sess := invokeJWT(t, tok, "")
	if id != 0 || sess != 0 {
		t.Errorf("missing id claim: got (%d,%d), want (0,0)", id, sess)
	}
}

func TestGetJWTFromRequest_MissingSessionIDClaim(t *testing.T) {
	claims := jwt.MapClaims{
		"id": "5",
		// "sessionid" is absent
		"exp": time.Now().Add(time.Hour).Unix(),
	}
	tok := makeToken(claims)
	id, sess := invokeJWT(t, tok, "")
	if id != 0 || sess != 0 {
		t.Errorf("missing sessionid claim: got (%d,%d), want (0,0)", id, sess)
	}
}

func TestGetJWTFromRequest_NonStringIDClaim(t *testing.T) {
	// "id" as a JSON number rather than a string — the idOk type assertion fails.
	claims := jwt.MapClaims{
		"id":        float64(42),
		"sessionid": "5",
		"exp":       time.Now().Add(time.Hour).Unix(),
	}
	tok := makeToken(claims)
	id, sess := invokeJWT(t, tok, "")
	if id != 0 || sess != 0 {
		t.Errorf("numeric id claim: got (%d,%d), want (0,0)", id, sess)
	}
}

func TestGetJWTFromRequest_NonStringSessionIDClaim(t *testing.T) {
	// "sessionid" as a JSON number — sessOk type assertion fails.
	claims := jwt.MapClaims{
		"id":        "42",
		"sessionid": float64(5),
		"exp":       time.Now().Add(time.Hour).Unix(),
	}
	tok := makeToken(claims)
	id, sess := invokeJWT(t, tok, "")
	if id != 0 || sess != 0 {
		t.Errorf("numeric sessionid claim: got (%d,%d), want (0,0)", id, sess)
	}
}

// ---------------------------------------------------------------------------
// Boundary values
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_LargeIDs(t *testing.T) {
	const bigID uint64 = 1<<53 - 1
	tok := makeToken(validClaims(bigID, bigID))
	id, sess := invokeJWT(t, tok, "")
	if id != bigID || sess != bigID {
		t.Errorf("large IDs: got (%d,%d), want (%d,%d)", id, sess, bigID, bigID)
	}
}

func TestGetJWTFromRequest_ZeroUserIDInValidToken(t *testing.T) {
	// UserID=0 encoded in a valid JWT — ParseUint returns 0, which is the zero value.
	tok := makeToken(validClaims(0, 1))
	id, _ := invokeJWT(t, tok, "")
	if id != 0 {
		t.Errorf("zero user id: got %d, want 0", id)
	}
}

// ---------------------------------------------------------------------------
// Expiry value propagation
// ---------------------------------------------------------------------------

func TestGetJWTFromRequest_ExpValueReturned(t *testing.T) {
	t.Setenv("JWT_SECRET", testSecret)
	app := jwtTestApp()

	expTime := time.Now().Add(24 * time.Hour).Unix()
	claims := jwt.MapClaims{
		"id":        "1",
		"sessionid": "1",
		"exp":       expTime,
	}
	tok := makeToken(claims)

	req := httptest.NewRequest("GET", "/", nil)
	req.Header.Set("Authorization", tok)
	resp, _ := app.Test(req)
	io.ReadAll(resp.Body) //nolint:errcheck

	expStr := resp.Header.Get("X-Exp")
	expGot, _ := strconv.ParseFloat(expStr, 64)
	if int64(expGot) != expTime {
		t.Errorf("exp value: got %v, want %d", expGot, expTime)
	}
}
