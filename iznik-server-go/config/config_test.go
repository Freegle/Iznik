package config

// Internal test package — coverage of config.go is correctly tracked here
// because tests are compiled into the same package as the production code.
//
// All tests below exercise pre-DB validation paths only; no database
// connection is required. The DBConn is nil in this context; any path
// that reaches a DB call would panic.

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// newApp returns a Fiber app that surfaces error detail in JSON.
func newApp() *fiber.App {
	return fiber.New(fiber.Config{
		ErrorHandler: func(c *fiber.Ctx, err error) error {
			code := fiber.StatusInternalServerError
			if e, ok := err.(*fiber.Error); ok {
				code = e.Code
			}
			return c.Status(code).JSON(fiber.Map{"error": err.Error()})
		},
	})
}

// doJSON fires a Fiber test request with an optional JSON body.
func doJSON(t *testing.T, app *fiber.App, method, path, body string) *http.Response {
	t.Helper()
	var bodyReader io.Reader
	if body != "" {
		bodyReader = strings.NewReader(body)
	}
	req := httptest.NewRequest(method, path, bodyReader)
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req)
	require.NoError(t, err)
	return resp
}

// ---------------------------------------------------------------------------
// TableName
// ---------------------------------------------------------------------------

func TestConcernKeyword_TableName(t *testing.T) {
	// The ORM table name must exactly match the DB table.
	kw := ConcernKeyword{}
	assert.Equal(t, "concern_keywords", kw.TableName())
}

// ---------------------------------------------------------------------------
// RequireSupportOrAdminMiddleware — pre-DB paths (no valid JWT)
// ---------------------------------------------------------------------------

func newMiddlewareApp() *fiber.App {
	app := newApp()
	app.Get("/protected", RequireSupportOrAdminMiddleware(), func(c *fiber.Ctx) error {
		return c.SendString("ok")
	})
	return app
}

func TestRequireSupportOrAdminMiddleware_NoAuth_Returns401(t *testing.T) {
	req := httptest.NewRequest("GET", "/protected", nil)
	resp, err := newMiddlewareApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)
}

func TestRequireSupportOrAdminMiddleware_EmptyAuthHeader_Returns401(t *testing.T) {
	req := httptest.NewRequest("GET", "/protected", nil)
	req.Header.Set("Authorization", "")
	resp, err := newMiddlewareApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)
}

func TestRequireSupportOrAdminMiddleware_MalformedJWT_Returns401(t *testing.T) {
	// Garbage Authorization header → JWT parse fails → userID == 0 → 401.
	req := httptest.NewRequest("GET", "/protected", nil)
	req.Header.Set("Authorization", "not-a-valid-jwt-at-all")
	resp, err := newMiddlewareApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)
}

func TestRequireSupportOrAdminMiddleware_ShortToken_Returns401(t *testing.T) {
	// Tokens ≤2 characters are ignored by GetJWTFromRequest → userID == 0 → 401.
	req := httptest.NewRequest("GET", "/protected", nil)
	req.Header.Set("Authorization", "ab")
	resp, err := newMiddlewareApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)
}

// Table-driven: all invalid/missing auth should return 401.
func TestRequireSupportOrAdminMiddleware_VariousInvalidAuths(t *testing.T) {
	cases := []struct {
		name   string
		header string
	}{
		{"no header", ""},
		{"single char", "x"},
		{"two chars", "ab"},
		{"garbage", "Bearer garbage.not.a.token"},
		{"just bearer", "Bearer "},
		{"numeric string", "12345"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest("GET", "/protected", nil)
			if tc.header != "" {
				req.Header.Set("Authorization", tc.header)
			}
			resp, err := newMiddlewareApp().Test(req)
			require.NoError(t, err)
			assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode,
				"header=%q should yield 401", tc.header)
		})
	}
}

// ---------------------------------------------------------------------------
// PatchAdminConfig — pre-DB validation paths
// ---------------------------------------------------------------------------

func newPatchConfigApp() *fiber.App {
	app := newApp()
	app.Patch("/config/admin", PatchAdminConfig)
	return app
}

func TestPatchAdminConfig_InvalidJSONBody_Returns400(t *testing.T) {
	resp := doJSON(t, newPatchConfigApp(), "PATCH", "/config/admin", "{not valid json!!!}")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestPatchAdminConfig_EmptyObject_Returns400(t *testing.T) {
	// Empty JSON object {} → len(body) == 0 → "No config keys provided" → 400.
	resp := doJSON(t, newPatchConfigApp(), "PATCH", "/config/admin", "{}")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), "No config keys provided")
}

func TestPatchAdminConfig_OnlyModtoolsKey_ReturnsSuccess(t *testing.T) {
	// Body with only the "modtools" key → that key is skipped → loop is a no-op
	// → returns 200 without touching the DB.
	resp := doJSON(t, newPatchConfigApp(), "PATCH", "/config/admin", `{"modtools":{"x":1}}`)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(body, &m))
	assert.Equal(t, float64(0), m["ret"])
	assert.Equal(t, "Success", m["status"])
}

func TestPatchAdminConfig_ModtoolsStringValue_ReturnsSuccess(t *testing.T) {
	// String modtools value — same no-op behaviour.
	resp := doJSON(t, newPatchConfigApp(), "PATCH", "/config/admin", `{"modtools":"ignored"}`)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
}

func TestPatchAdminConfig_ModtoolsBoolValue_ReturnsSuccess(t *testing.T) {
	resp := doJSON(t, newPatchConfigApp(), "PATCH", "/config/admin", `{"modtools":true}`)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
}

func TestPatchAdminConfig_NoBody_Returns400(t *testing.T) {
	req := httptest.NewRequest("PATCH", "/config/admin", nil)
	req.Header.Set("Content-Type", "application/json")
	resp, err := newPatchConfigApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

// ---------------------------------------------------------------------------
// CreateConcernKeyword — pre-DB validation paths
// ---------------------------------------------------------------------------

func newCreateKeywordApp() *fiber.App {
	app := newApp()
	app.Post("/config/concern-keyword", CreateConcernKeyword)
	return app
}

func TestCreateConcernKeyword_InvalidBody_Returns400(t *testing.T) {
	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword", "{{broken json")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestCreateConcernKeyword_EmptyKeyword_Returns400(t *testing.T) {
	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword",
		`{"keyword":"","category":"spam"}`)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), "Keyword is required")
}

func TestCreateConcernKeyword_WhitespaceKeyword_Returns400(t *testing.T) {
	// spaces-only keyword → TrimSpace → "" → 400.
	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword",
		`{"keyword":"   ","category":"spam"}`)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestCreateConcernKeyword_MissingKeywordField_Returns400(t *testing.T) {
	// No keyword field → zero-value "" → 400.
	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword",
		`{"category":"dangerous"}`)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestCreateConcernKeyword_EmptyCategory_Returns400(t *testing.T) {
	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword",
		`{"keyword":"knife","category":""}`)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), "Category is required")
}

func TestCreateConcernKeyword_MissingCategoryField_Returns400(t *testing.T) {
	// No category field → zero-value "" → 400.
	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword",
		`{"keyword":"knife"}`)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestCreateConcernKeyword_TabOnlyKeyword_Returns400(t *testing.T) {
	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword",
		`{"keyword":"\t","category":"spam"}`)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestCreateConcernKeyword_NewlineOnlyKeyword_Returns400(t *testing.T) {
	resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword",
		`{"keyword":"\n","category":"spam"}`)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

// Table-driven validation matrix.
func TestCreateConcernKeyword_ValidationMatrix(t *testing.T) {
	tests := []struct {
		name    string
		payload string
		wantErr string
	}{
		{
			name:    "empty keyword",
			payload: `{"keyword":"","category":"drugs"}`,
			wantErr: "Keyword is required",
		},
		{
			name:    "keyword only spaces",
			payload: `{"keyword":"   ","category":"drugs"}`,
			wantErr: "Keyword is required",
		},
		{
			name:    "empty category",
			payload: `{"keyword":"cocaine","category":""}`,
			wantErr: "Category is required",
		},
		{
			name:    "missing category field",
			payload: `{"keyword":"cocaine"}`,
			wantErr: "Category is required",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			resp := doJSON(t, newCreateKeywordApp(), "POST", "/config/concern-keyword", tt.payload)
			assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
			body, _ := io.ReadAll(resp.Body)
			assert.Contains(t, string(body), tt.wantErr)
		})
	}
}

// ---------------------------------------------------------------------------
// DeleteConcernKeyword — pre-DB validation paths
// ---------------------------------------------------------------------------

func newDeleteKeywordApp() *fiber.App {
	app := newApp()
	app.Delete("/config/concern-keyword/:id", DeleteConcernKeyword)
	return app
}

func TestDeleteConcernKeyword_NonNumericID_Returns400(t *testing.T) {
	req := httptest.NewRequest("DELETE", "/config/concern-keyword/notanumber", nil)
	resp, err := newDeleteKeywordApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), "Invalid ID")
}

func TestDeleteConcernKeyword_NegativeID_Returns400(t *testing.T) {
	req := httptest.NewRequest("DELETE", "/config/concern-keyword/-5", nil)
	resp, err := newDeleteKeywordApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestDeleteConcernKeyword_FloatID_Returns400(t *testing.T) {
	req := httptest.NewRequest("DELETE", "/config/concern-keyword/1.5", nil)
	resp, err := newDeleteKeywordApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestDeleteConcernKeyword_EmptyID_Returns404(t *testing.T) {
	// No ID segment → Fiber 404 (no matching route).
	req := httptest.NewRequest("DELETE", "/config/concern-keyword/", nil)
	resp, err := newDeleteKeywordApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusNotFound, resp.StatusCode)
}

func TestDeleteConcernKeyword_AlphaNumericID_Returns400(t *testing.T) {
	req := httptest.NewRequest("DELETE", "/config/concern-keyword/abc123", nil)
	resp, err := newDeleteKeywordApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

// Table-driven invalid-ID tests.
func TestDeleteConcernKeyword_InvalidIDVariants(t *testing.T) {
	cases := []string{
		"notanumber",
		"1.5",
		"-99",
		"abc",
		"0x1A",
		"99999999999999999999999", // overflows uint64
	}

	app := newDeleteKeywordApp()
	for _, id := range cases {
		t.Run(id, func(t *testing.T) {
			req := httptest.NewRequest("DELETE", "/config/concern-keyword/"+id, nil)
			resp, err := app.Test(req)
			require.NoError(t, err)
			assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode,
				"expected 400 for id=%q", id)
		})
	}
}
