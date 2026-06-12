package amp

import (
	"encoding/json"
	"io"
	"net/http/httptest"
	"os"
	"strconv"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// ValidateToken — all paths that do NOT reach the database
// ---------------------------------------------------------------------------

// validateResult is the shape returned by the test handler wrapping ValidateToken.
type validateResult struct {
	UserID     uint64 `json:"uid"`
	ResourceID uint64 `json:"rid"`
}

// newValidateApp creates a Fiber app with a /:id route whose handler calls
// ValidateToken and returns the result as JSON so tests can inspect it.
func newValidateApp() *fiber.App {
	app := fiber.New()
	app.Get("/v/:id", func(c *fiber.Ctx) error {
		uid, rid, err := ValidateToken(c)
		if err != nil {
			return c.Status(500).JSON(fiber.Map{"error": err.Error()})
		}
		return c.JSON(validateResult{UserID: uid, ResourceID: rid})
	})
	return app
}

// callValidate sends GET /v/<pathID>?<queryStr> to the validate app and returns (userID, resourceID).
func callValidate(t *testing.T, app *fiber.App, pathID, queryStr string) (uint64, uint64) {
	t.Helper()
	path := "/v/" + pathID
	if queryStr != "" {
		path += "?" + queryStr
	}
	req := httptest.NewRequest("GET", path, nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	body, _ := io.ReadAll(resp.Body)
	var result validateResult
	_ = json.Unmarshal(body, &result)
	return result.UserID, result.ResourceID
}

// futureExp returns a Unix timestamp string one day in the future.
func futureExp() string {
	return strconv.FormatInt(time.Now().Add(24*time.Hour).Unix(), 10)
}

// pastExp returns a Unix timestamp string one hour in the past.
func pastExp() string {
	return strconv.FormatInt(time.Now().Add(-1*time.Hour).Unix(), 10)
}

// validToken computes the correct HMAC for the given uid/resID/exp with AMP_SECRET="test-secret".
func validToken(uid, resID, exp string) string {
	return computeHMAC("amp"+uid+resID+exp, "test-secret")
}

func TestValidateToken_MissingAllParams(t *testing.T) {
	app := newValidateApp()
	uid, rid := callValidate(t, app, "123", "")
	assert.Zero(t, uid)
	assert.Zero(t, rid)
}

func TestValidateToken_MissingToken(t *testing.T) {
	// uid + exp present but rt absent → early return.
	app := newValidateApp()
	uid, rid := callValidate(t, app, "123", "uid=1&exp="+futureExp())
	assert.Zero(t, uid)
	assert.Zero(t, rid)
}

func TestValidateToken_MissingUID(t *testing.T) {
	// rt + exp present but uid absent → early return.
	app := newValidateApp()
	uid, rid := callValidate(t, app, "123", "rt=abc&exp="+futureExp())
	assert.Zero(t, uid)
	assert.Zero(t, rid)
}

func TestValidateToken_MissingExp(t *testing.T) {
	// rt + uid present but exp absent → early return.
	app := newValidateApp()
	uid, rid := callValidate(t, app, "123", "rt=abc&uid=1")
	assert.Zero(t, uid)
	assert.Zero(t, rid)
}

func TestValidateToken_MissingResourceID(t *testing.T) {
	// No :id path param → c.Params("id") is "" → early return.
	app := fiber.New()
	app.Get("/no-id", func(c *fiber.Ctx) error {
		uid, rid, _ := ValidateToken(c)
		return c.JSON(validateResult{UserID: uid, ResourceID: rid})
	})
	req := httptest.NewRequest("GET", "/no-id?rt=abc&uid=1&exp="+futureExp(), nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	body, _ := io.ReadAll(resp.Body)
	var result validateResult
	_ = json.Unmarshal(body, &result)
	assert.Zero(t, result.UserID)
	assert.Zero(t, result.ResourceID)
}

func TestValidateToken_ExpiredToken(t *testing.T) {
	// exp is in the past → expiry check fires → (0, 0, nil).
	app := newValidateApp()
	uid, rid := callValidate(t, app, "123", "rt=abc&uid=1&exp="+pastExp())
	assert.Zero(t, uid)
	assert.Zero(t, rid)
}

func TestValidateToken_InvalidExpFormat(t *testing.T) {
	// exp is not a valid integer → strconv.ParseInt fails → (0, 0, nil).
	app := newValidateApp()
	uid, rid := callValidate(t, app, "123", "rt=abc&uid=1&exp=notanumber")
	assert.Zero(t, uid)
	assert.Zero(t, rid)
}

func TestValidateToken_ExpZeroIsExpired(t *testing.T) {
	// exp == 0 is before now → treated as expired.
	app := newValidateApp()
	uid, rid := callValidate(t, app, "123", "rt=abc&uid=1&exp=0")
	assert.Zero(t, uid)
	assert.Zero(t, rid)
}

func TestValidateToken_EmptySecret(t *testing.T) {
	// No AMP secret configured → getAMPSecret returns "" → (0, 0, nil).
	origA := os.Getenv("AMP_SECRET")
	origF := os.Getenv("FREEGLE_AMP_SECRET")
	os.Unsetenv("AMP_SECRET")
	os.Unsetenv("FREEGLE_AMP_SECRET")
	defer func() {
		os.Setenv("AMP_SECRET", origA)
		os.Setenv("FREEGLE_AMP_SECRET", origF)
	}()

	app := newValidateApp()
	exp := futureExp()
	uid, rid := callValidate(t, app, "99", "rt=sometoken&uid=5&exp="+exp)
	assert.Zero(t, uid)
	assert.Zero(t, rid)
}

func TestValidateToken_WrongHMAC(t *testing.T) {
	// Secret set and expiry valid, but token is wrong → HMAC mismatch → (0, 0, nil).
	os.Setenv("AMP_SECRET", "test-secret")
	defer os.Unsetenv("AMP_SECRET")

	app := newValidateApp()
	exp := futureExp()
	uid, rid := callValidate(t, app, "42", "rt=wrong-hmac-token&uid=7&exp="+exp)
	assert.Zero(t, uid)
	assert.Zero(t, rid)
}

// TestValidateToken_CorrectHMACRequiresDB documents that the only remaining
// branch (user-existence DB query) cannot run without a live database
// connection. The HMAC validation logic is verified by the WrongHMAC test
// above (which confirms computeHMAC is called and used for comparison).
func TestValidateToken_CorrectHMACRequiresDB(t *testing.T) {
	t.Skip("user-existence check needs live DB — covered by integration tests")
}

// ---------------------------------------------------------------------------
// recordAmpReplyTracking — nil ID is a guaranteed no-op (no DB call)
// ---------------------------------------------------------------------------

func TestRecordAmpReplyTracking_NilIDIsNoOp(t *testing.T) {
	// A nil emailTrackingID means the email was sent without tracking data.
	// The function must return immediately without touching the DB.
	assert.NotPanics(t, func() {
		recordAmpReplyTracking(nil, nil, "https://example.com/reply", "amp_reply_form")
	})
}

// ---------------------------------------------------------------------------
// GetChatMessages — graceful fallback when token is invalid (no DB reached)
// ---------------------------------------------------------------------------

func newGetChatApp() *fiber.App {
	app := fiber.New()
	app.Get("/amp/chat/:id", GetChatMessages)
	return app
}

func TestGetChatMessages_NoParams_GracefulFallback(t *testing.T) {
	// Missing token params → ValidateToken returns (0,0,nil) → fallback JSON.
	app := newGetChatApp()
	req := httptest.NewRequest("GET", "/amp/chat/123", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result AMPChatResponse
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.Empty(t, result.Items)
	assert.False(t, result.CanReply)
}

func TestGetChatMessages_ExpiredToken_GracefulFallback(t *testing.T) {
	// Expired expiry → ValidateToken returns (0,0,nil) → fallback.
	app := newGetChatApp()
	req := httptest.NewRequest("GET", "/amp/chat/456?rt=abc&uid=1&exp="+pastExp(), nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result AMPChatResponse
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.Empty(t, result.Items)
	assert.False(t, result.CanReply)
}

func TestGetChatMessages_WrongHMAC_GracefulFallback(t *testing.T) {
	os.Setenv("AMP_SECRET", "test-secret")
	defer os.Unsetenv("AMP_SECRET")

	app := newGetChatApp()
	req := httptest.NewRequest("GET", "/amp/chat/789?rt=wrong&uid=1&exp="+futureExp(), nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result AMPChatResponse
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.Empty(t, result.Items)
	assert.False(t, result.CanReply)
}

// ---------------------------------------------------------------------------
// PostChatReply — 400 when token is invalid (no DB reached)
// ---------------------------------------------------------------------------

func newPostChatReplyApp() *fiber.App {
	app := fiber.New()
	app.Post("/amp/chat/:id/reply", PostChatReply)
	return app
}

func TestPostChatReply_NoParams_Returns400InvalidToken(t *testing.T) {
	app := newPostChatReplyApp()
	req := httptest.NewRequest("POST", "/amp/chat/123/reply", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result ReplyResponse
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.False(t, result.Success)
	assert.Equal(t, "Invalid token", result.Message)
}

func TestPostChatReply_ExpiredToken_Returns400(t *testing.T) {
	app := newPostChatReplyApp()
	req := httptest.NewRequest("POST", "/amp/chat/10/reply?rt=abc&uid=1&exp="+pastExp(), nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result ReplyResponse
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.False(t, result.Success)
	assert.Equal(t, "Invalid token", result.Message)
}

func TestPostChatReply_WrongHMAC_Returns400(t *testing.T) {
	os.Setenv("AMP_SECRET", "test-secret")
	defer os.Unsetenv("AMP_SECRET")

	app := newPostChatReplyApp()
	req := httptest.NewRequest("POST", "/amp/chat/11/reply?rt=wrong&uid=1&exp="+futureExp(), nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result ReplyResponse
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.False(t, result.Success)
	assert.Equal(t, "Invalid token", result.Message)
}

// ---------------------------------------------------------------------------
// PostDigestReply — 400 when token is invalid (no DB reached)
// ---------------------------------------------------------------------------

func newPostDigestReplyApp() *fiber.App {
	app := fiber.New()
	app.Post("/amp/digest/:id/reply", PostDigestReply)
	return app
}

func TestPostDigestReply_NoParams_Returns400InvalidToken(t *testing.T) {
	app := newPostDigestReplyApp()
	req := httptest.NewRequest("POST", "/amp/digest/99/reply", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result ReplyResponse
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.False(t, result.Success)
	assert.Equal(t, "Invalid token", result.Message)
}

func TestPostDigestReply_ExpiredToken_Returns400(t *testing.T) {
	app := newPostDigestReplyApp()
	req := httptest.NewRequest("POST", "/amp/digest/200/reply?rt=abc&uid=1&exp="+pastExp(), nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result ReplyResponse
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.False(t, result.Success)
	assert.Equal(t, "Invalid token", result.Message)
}

func TestPostDigestReply_WrongHMAC_Returns400(t *testing.T) {
	os.Setenv("AMP_SECRET", "test-secret")
	defer os.Unsetenv("AMP_SECRET")

	app := newPostDigestReplyApp()
	req := httptest.NewRequest("POST", "/amp/digest/300/reply?rt=wrong&uid=1&exp="+futureExp(), nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result ReplyResponse
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.False(t, result.Success)
	assert.Equal(t, "Invalid token", result.Message)
}

// TestPostDigestReply_ZeroResourceIDFromValidToken verifies the messageID==0
// guard: even if ValidateToken somehow returned a non-zero userID with a zero
// resourceID, PostDigestReply must return 400.
// This path requires a valid HMAC + live DB, so it is skipped here.
func TestPostDigestReply_ZeroResourceID_RequiresDB(t *testing.T) {
	t.Skip("zero-resourceID guard requires valid HMAC + live DB")
}

// ---------------------------------------------------------------------------
// ValidateToken — table-driven coverage of all early-exit conditions
// ---------------------------------------------------------------------------

func TestValidateToken_TableDriven(t *testing.T) {
	os.Setenv("AMP_SECRET", "test-secret")
	defer os.Unsetenv("AMP_SECRET")

	exp := futureExp()
	pastExpStr := pastExp()

	tests := []struct {
		name    string
		pathID  string
		query   string
		wantUID uint64
		wantRID uint64
	}{
		{
			name:   "all params missing",
			pathID: "1", query: "",
		},
		{
			name:   "token missing",
			pathID: "1", query: "uid=1&exp=" + exp,
		},
		{
			name:   "uid missing",
			pathID: "1", query: "rt=tok&exp=" + exp,
		},
		{
			name:   "exp missing",
			pathID: "1", query: "rt=tok&uid=1",
		},
		{
			name:   "exp expired",
			pathID: "1", query: "rt=tok&uid=1&exp=" + pastExpStr,
		},
		{
			name:   "exp non-numeric",
			pathID: "1", query: "rt=tok&uid=1&exp=BADEXP",
		},
		{
			name:   "wrong HMAC",
			pathID: "42", query: "rt=definitely-wrong&uid=7&exp=" + exp,
		},
		{
			name:   "correct HMAC format but wrong value",
			pathID: "42", query: "rt=" + computeHMAC("amp"+"7"+"42"+exp, "wrong-secret") + "&uid=7&exp=" + exp,
		},
	}

	app := newValidateApp()
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			uid, rid := callValidate(t, app, tc.pathID, tc.query)
			assert.Equal(t, tc.wantUID, uid, "userID mismatch for %s", tc.name)
			assert.Equal(t, tc.wantRID, rid, "resourceID mismatch for %s", tc.name)
		})
	}
}
