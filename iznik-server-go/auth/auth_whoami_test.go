package auth_test

// WhoAmI has one branch that requires a live DB connection (verifying an
// old-style Authorization2 persistent token against the sessions table).
// These tests exercise every OTHER branch — the ones that return before
// touching database.DBConn, which is nil in this unit-test environment and
// would panic if reached. Reaching the DB branch is covered by the
// integration suite (test/auth_test.go) instead.

import (
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func runWhoAmI(t *testing.T, buildReq func(*http.Request)) (uint64, bool) {
	t.Helper()

	var gotID uint64
	var gotAuthUsed bool

	app := fiber.New()
	app.Get("/test", func(c *fiber.Ctx) error {
		gotID = auth.WhoAmI(c)
		gotAuthUsed, _ = c.Locals("authUsed").(bool)
		return c.SendStatus(fiber.StatusOK)
	})

	req := httptest.NewRequest("GET", "/test", nil)
	buildReq(req)

	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	return gotID, gotAuthUsed
}

// ---------------------------------------------------------------------------
// No credentials at all — returns 0 without touching the DB or Locals.
// ---------------------------------------------------------------------------

func TestWhoAmI_NoCredentials_ReturnsZero(t *testing.T) {
	t.Setenv("JWT_SECRET", testJWTSecret)

	id, authUsed := runWhoAmI(t, func(r *http.Request) {})
	assert.Equal(t, uint64(0), id)
	assert.False(t, authUsed)
}

// ---------------------------------------------------------------------------
// Valid JWT — short-circuits before the Authorization2 branch, no DB needed.
// ---------------------------------------------------------------------------

func TestWhoAmI_ValidJWT_ReturnsIDAndSetsAuthUsed(t *testing.T) {
	t.Setenv("JWT_SECRET", testJWTSecret)
	tok := makeValidJWT(t, 123, 456, time.Hour)

	id, authUsed := runWhoAmI(t, func(r *http.Request) {
		r.Header.Set("Authorization", tok)
	})
	assert.Equal(t, uint64(123), id)
	assert.True(t, authUsed, "a successful auth should mark authUsed for the middleware")
}

func TestWhoAmI_ValidJWT_IgnoresAuthorization2Header(t *testing.T) {
	// id != 0 from the JWT means the Authorization2 branch (id == 0 && ...)
	// is never entered, even though a persistent header is present. If it
	// were entered here it would try to hit a nil DBConn and panic.
	t.Setenv("JWT_SECRET", testJWTSecret)
	tok := makeValidJWT(t, 77, 1, time.Hour)

	id, authUsed := runWhoAmI(t, func(r *http.Request) {
		r.Header.Set("Authorization", tok)
		r.Header.Set("Authorization2", `{"id":999,"series":1,"token":"whatever"}`)
	})
	assert.Equal(t, uint64(77), id)
	assert.True(t, authUsed)
}

// ---------------------------------------------------------------------------
// Expired/invalid JWT falls through to id==0; Authorization2 branch is only
// safe to enter when it can't reach the DB query, i.e. when the persistent
// token itself is malformed or incomplete.
// ---------------------------------------------------------------------------

func TestWhoAmI_NoJWT_UnparseableAuthorization2_ReturnsZero(t *testing.T) {
	t.Setenv("JWT_SECRET", testJWTSecret)

	id, authUsed := runWhoAmI(t, func(r *http.Request) {
		r.Header.Set("Authorization2", "not-json-at-all")
	})
	assert.Equal(t, uint64(0), id)
	assert.False(t, authUsed)
}

func TestWhoAmI_NoJWT_Authorization2MissingToken_ReturnsZero(t *testing.T) {
	// ID > 0 but Token == "" fails the "minPT.ID > 0 && minPT.Token != ''"
	// guard, so the DB is never queried.
	t.Setenv("JWT_SECRET", testJWTSecret)

	id, authUsed := runWhoAmI(t, func(r *http.Request) {
		r.Header.Set("Authorization2", `{"id":555,"token":""}`)
	})
	assert.Equal(t, uint64(0), id)
	assert.False(t, authUsed)
}

func TestWhoAmI_NoJWT_Authorization2ZeroID_ReturnsZero(t *testing.T) {
	// Token present but ID == 0 also fails the guard.
	t.Setenv("JWT_SECRET", testJWTSecret)

	id, authUsed := runWhoAmI(t, func(r *http.Request) {
		r.Header.Set("Authorization2", `{"id":0,"token":"sometoken"}`)
	})
	assert.Equal(t, uint64(0), id)
	assert.False(t, authUsed)
}

func TestWhoAmI_NoJWT_EmptyAuthorization2_ReturnsZero(t *testing.T) {
	// Empty Authorization2 header fails the `len(persistent) > 0` check,
	// same outcome as it being absent entirely.
	t.Setenv("JWT_SECRET", testJWTSecret)

	id, authUsed := runWhoAmI(t, func(r *http.Request) {
		r.Header.Set("Authorization2", "")
	})
	assert.Equal(t, uint64(0), id)
	assert.False(t, authUsed)
}

func TestWhoAmI_NoJWT_Authorization2SeriesAsString_ReturnsZero(t *testing.T) {
	// Historically Series was sometimes serialised as a JSON string ("12345")
	// rather than a number. WhoAmI decodes into a minimal struct without a
	// Series field specifically so this doesn't cause an unmarshal type error
	// that would zero out an otherwise-valid ID+Token pair. Since ID is 0
	// here, this still returns 0, but proves the unmarshal doesn't error out
	// and leave the request in a broken state.
	t.Setenv("JWT_SECRET", testJWTSecret)

	id, authUsed := runWhoAmI(t, func(r *http.Request) {
		r.Header.Set("Authorization2", `{"id":0,"series":"12345","token":"x"}`)
	})
	assert.Equal(t, uint64(0), id)
	assert.False(t, authUsed)
}

// ---------------------------------------------------------------------------
// Boundary: large user ID from JWT is preserved through the uint64 round-trip.
// ---------------------------------------------------------------------------

func TestWhoAmI_ValidJWT_LargeUserID(t *testing.T) {
	t.Setenv("JWT_SECRET", testJWTSecret)
	const bigID uint64 = 1<<53 - 1
	tok := makeValidJWT(t, bigID, 1, time.Hour)

	id, authUsed := runWhoAmI(t, func(r *http.Request) {
		r.Header.Set("Authorization", tok)
	})
	assert.Equal(t, bigID, id)
	assert.True(t, authUsed)
}
