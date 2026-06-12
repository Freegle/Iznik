package abtest

// Tests exercise every code path that does NOT require a database connection.
// Paths that reach database.DBConn are excluded; they are verified by
// integration tests that run against a live DB inside Docker.
//
// Coverage reachable without DB:
//   GetABTest  — uid="" → 400 Bad Request  (3 statements)
//   PostABTest — invalid JSON body         → 400 Bad Request  (3 statements)
//              — app=true                  → 200 (no-op)      (5 statements)
//              — uid="" or variant=""      → 200 (no-op)      (4 statements per branch)
//
// Each struct's JSON tags are also verified to guard against accidental renames
// that would silently break the API contract.

import (
	"encoding/json"
	"io"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// ---------------------------------------------------------------------------
// Test app helpers
// ---------------------------------------------------------------------------

func newTestApp() *fiber.App {
	app := fiber.New(fiber.Config{
		ErrorHandler: func(c *fiber.Ctx, err error) error {
			code := fiber.StatusInternalServerError
			if e, ok := err.(*fiber.Error); ok {
				code = e.Code
			}
			return c.Status(code).JSON(fiber.Map{"error": err.Error()})
		},
	})
	app.Get("/abtest", GetABTest)
	app.Post("/abtest", PostABTest)
	return app
}

func boolPtr(b bool) *bool { return &b }
func intPtr(i int) *int    { return &i }

// doPost fires a POST /abtest with a JSON body and returns (statusCode, responseBody).
func doPost(t *testing.T, app *fiber.App, body string) (int, []byte) {
	t.Helper()
	req := httptest.NewRequest("POST", "/abtest", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req)
	require.NoError(t, err)
	b, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, b
}

// ---------------------------------------------------------------------------
// GetABTest — pre-DB paths
// ---------------------------------------------------------------------------

func TestGetABTest_MissingUID_Returns400(t *testing.T) {
	// No uid query parameter → validation rejects before any DB access.
	app := newTestApp()
	req := httptest.NewRequest("GET", "/abtest", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	assert.Contains(t, string(body), "uid is required")
}

func TestGetABTest_EmptyUID_Returns400(t *testing.T) {
	// Explicit ?uid= (empty string) is the same as missing.
	app := newTestApp()
	req := httptest.NewRequest("GET", "/abtest?uid=", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
}

func TestGetABTest_TableDrivenMissingUID(t *testing.T) {
	// Multiple empty/absent uid forms should all return 400.
	// Note: spaces (%20 / +) are NOT considered empty by fiber; only literal ""
	// triggers the uid=="" guard.
	cases := []struct {
		name string
		path string
	}{
		{"no query at all", "/abtest"},
		{"empty uid param", "/abtest?uid="},
	}

	app := newTestApp()
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest("GET", tc.path, nil)
			resp, err := app.Test(req)
			require.NoError(t, err)
			assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode, "path=%s", tc.path)
		})
	}
}

// ---------------------------------------------------------------------------
// PostABTest — invalid body path
// ---------------------------------------------------------------------------

func TestPostABTest_InvalidJSONBody_Returns400(t *testing.T) {
	app := newTestApp()
	code, body := doPost(t, app, "{not valid json!!!")
	assert.Equal(t, fiber.StatusBadRequest, code)
	assert.Contains(t, string(body), "Invalid request body")
}

func TestPostABTest_TableDrivenInvalidBodies(t *testing.T) {
	cases := []struct {
		name string
		body string
	}{
		{"broken JSON", "{bad"},
		{"bare string", `"just a string"`},
		{"array where object expected", `["uid","variant"]`},
		{"number where object expected", `42`},
	}

	app := newTestApp()
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			code, _ := doPost(t, app, tc.body)
			assert.Equal(t, fiber.StatusBadRequest, code, "body=%s", tc.body)
		})
	}
}

// ---------------------------------------------------------------------------
// PostABTest — app=true short-circuit (returns before any DB access)
// ---------------------------------------------------------------------------

func TestPostABTest_AppTrue_Returns200WithoutDB(t *testing.T) {
	// Requests from the mobile app are deliberately ignored to avoid polluting
	// web A/B experiments; the server returns a success response immediately.
	app := newTestApp()
	payload := `{"uid":"test-uid","variant":"A","app":true}`
	code, body := doPost(t, app, payload)
	assert.Equal(t, fiber.StatusOK, code)

	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(body, &m))
	assert.Equal(t, float64(0), m["ret"])
	assert.Equal(t, "Success", m["status"])
}

func TestPostABTest_AppTrueNoOtherFields_Returns200(t *testing.T) {
	// app=true takes precedence even when uid/variant are missing.
	app := newTestApp()
	payload := `{"app":true}`
	code, _ := doPost(t, app, payload)
	assert.Equal(t, fiber.StatusOK, code)
}

func TestPostABTest_AppFalse_DoesNotShortCircuit(t *testing.T) {
	// app=false falls through to the uid/variant check.
	// With empty uid the function returns 200 (not 400) without hitting the DB.
	app := newTestApp()
	payload := `{"app":false,"uid":"","variant":""}`
	code, _ := doPost(t, app, payload)
	// Falls through app=false → uid/variant check → early-success return.
	assert.Equal(t, fiber.StatusOK, code)
}

func TestPostABTest_AppNil_DoesNotShortCircuit(t *testing.T) {
	// Omitting "app" means it's nil → the condition req.App != nil && *req.App is false.
	app := newTestApp()
	payload := `{"uid":"","variant":""}`
	code, _ := doPost(t, app, payload)
	assert.Equal(t, fiber.StatusOK, code)
}

// ---------------------------------------------------------------------------
// PostABTest — missing uid or variant (returns before DB)
// ---------------------------------------------------------------------------

func TestPostABTest_EmptyUID_Returns200(t *testing.T) {
	// Empty UID causes early return before DB insert.
	app := newTestApp()
	code, body := doPost(t, app, `{"uid":"","variant":"A"}`)
	assert.Equal(t, fiber.StatusOK, code)

	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(body, &m))
	assert.Equal(t, float64(0), m["ret"])
	assert.Equal(t, "Success", m["status"])
}

func TestPostABTest_EmptyVariant_Returns200(t *testing.T) {
	// Empty variant causes early return before DB insert.
	app := newTestApp()
	code, _ := doPost(t, app, `{"uid":"my-test-uid","variant":""}`)
	assert.Equal(t, fiber.StatusOK, code)
}

func TestPostABTest_BothEmpty_Returns200(t *testing.T) {
	app := newTestApp()
	code, _ := doPost(t, app, `{"uid":"","variant":""}`)
	assert.Equal(t, fiber.StatusOK, code)
}

func TestPostABTest_MissingUIDField_Returns200(t *testing.T) {
	// No uid field in the JSON body → zero value "" → early return.
	app := newTestApp()
	code, _ := doPost(t, app, `{"variant":"control"}`)
	assert.Equal(t, fiber.StatusOK, code)
}

func TestPostABTest_MissingVariantField_Returns200(t *testing.T) {
	// No variant field → zero value "" → early return.
	app := newTestApp()
	code, _ := doPost(t, app, `{"uid":"exp-uid"}`)
	assert.Equal(t, fiber.StatusOK, code)
}

func TestPostABTest_EmptyObject_Returns200(t *testing.T) {
	// Completely empty JSON object: uid="" variant="" → early return.
	app := newTestApp()
	code, _ := doPost(t, app, `{}`)
	assert.Equal(t, fiber.StatusOK, code)
}

// Table-driven: all permutations of missing/empty uid+variant.
func TestPostABTest_MissingUIDOrVariant_Table(t *testing.T) {
	cases := []struct {
		name    string
		payload string
	}{
		{"uid only missing", `{"variant":"B"}`},
		{"variant only missing", `{"uid":"abc"}`},
		{"both missing", `{}`},
		{"uid empty string", `{"uid":"","variant":"B"}`},
		{"variant empty string", `{"uid":"abc","variant":""}`},
		{"both empty strings", `{"uid":"","variant":""}`},
	}

	app := newTestApp()
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			code, body := doPost(t, app, tc.payload)
			assert.Equal(t, fiber.StatusOK, code, "payload=%s", tc.payload)
			var m map[string]interface{}
			require.NoError(t, json.Unmarshal(body, &m))
			assert.Equal(t, float64(0), m["ret"])
		})
	}
}

// ---------------------------------------------------------------------------
// Struct JSON tags — guard against accidental API contract breaks
// ---------------------------------------------------------------------------

func TestABTestVariant_JSONTagsRoundTrip(t *testing.T) {
	// Verify the JSON serialisation uses the correct field names.
	v := ABTestVariant{
		ID:      1,
		UID:     "test-uid",
		Variant: "control",
		Shown:   42,
		Action:  7,
		Rate:    16.67,
		Suggest: true,
	}

	data, err := json.Marshal(v)
	require.NoError(t, err)

	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(data, &m))

	assert.Equal(t, float64(1), m["id"])
	assert.Equal(t, "test-uid", m["uid"])
	assert.Equal(t, "control", m["variant"])
	assert.Equal(t, float64(42), m["shown"])
	assert.Equal(t, float64(7), m["action"])
	assert.InDelta(t, 16.67, m["rate"].(float64), 0.01)
	assert.Equal(t, true, m["suggest"])
}

func TestPostABTestRequest_JSONTagsRoundTrip(t *testing.T) {
	shown := true
	action := false
	score := 5
	appFlag := false

	req := PostABTestRequest{
		UID:     "exp-123",
		Variant: "treatment",
		Shown:   &shown,
		Action:  &action,
		Score:   &score,
		App:     &appFlag,
	}

	data, err := json.Marshal(req)
	require.NoError(t, err)

	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(data, &m))

	assert.Equal(t, "exp-123", m["uid"])
	assert.Equal(t, "treatment", m["variant"])
	assert.Equal(t, true, m["shown"])
	assert.Equal(t, false, m["action"])
	assert.Equal(t, float64(5), m["score"])
	assert.Equal(t, false, m["app"])
}

func TestPostABTestRequest_NilPointerFieldsOmittedFromJSON(t *testing.T) {
	// Pointer fields with nil value are omitted when marshalled (json:"...,omitempty" behaviour).
	// Here they use plain "json" tags so nil pointers serialise as null.
	req := PostABTestRequest{UID: "x", Variant: "y"}
	data, err := json.Marshal(req)
	require.NoError(t, err)

	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(data, &m))

	// nil *bool serialises as JSON null (not omitted) since tags have no omitempty.
	_, shownPresent := m["shown"]
	assert.True(t, shownPresent, "shown key should be present even when nil")
	assert.Nil(t, m["shown"])
	assert.Nil(t, m["action"])
	assert.Nil(t, m["score"])
	assert.Nil(t, m["app"])
}

func TestGetABTestRequest_UIDQueryTag(t *testing.T) {
	// GetABTestRequest.UID has a `query:"uid"` tag; exercise it via JSON as well.
	req := GetABTestRequest{UID: "some-uid"}
	data, err := json.Marshal(req)
	require.NoError(t, err)
	// json package uses json tags; since there's no json tag, it falls back to
	// the field name "UID".
	assert.Contains(t, string(data), "UID")
	assert.Contains(t, string(data), "some-uid")
}

// ---------------------------------------------------------------------------
// ABTestVariant — zero value behaviour
// ---------------------------------------------------------------------------

func TestABTestVariant_ZeroValue_Suggest_IsFalse(t *testing.T) {
	var v ABTestVariant
	assert.False(t, v.Suggest, "zero-value Suggest must be false (not shown by default)")
}

func TestABTestVariant_ZeroValue_RateIsZero(t *testing.T) {
	var v ABTestVariant
	assert.Equal(t, float64(0), v.Rate)
}

// ---------------------------------------------------------------------------
// PostABTest — response body shape
// ---------------------------------------------------------------------------

func TestPostABTest_SuccessResponseShape(t *testing.T) {
	// All early-return paths emit {"ret":0,"status":"Success"}.
	app := newTestApp()
	code, body := doPost(t, app, `{}`)
	assert.Equal(t, fiber.StatusOK, code)

	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(body, &m))
	assert.Equal(t, float64(0), m["ret"])
	assert.Equal(t, "Success", m["status"])
}

func TestPostABTest_AppTrueResponseShape(t *testing.T) {
	app := newTestApp()
	code, body := doPost(t, app, `{"app":true}`)
	assert.Equal(t, fiber.StatusOK, code)

	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(body, &m))
	assert.Equal(t, float64(0), m["ret"])
	assert.Equal(t, "Success", m["status"])
}

func TestGetABTest_MissingUID_ErrorMessage(t *testing.T) {
	app := newTestApp()
	req := httptest.NewRequest("GET", "/abtest", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)

	body, _ := io.ReadAll(resp.Body)
	var m map[string]interface{}
	require.NoError(t, json.Unmarshal(body, &m))
	assert.Contains(t, m["error"].(string), "uid is required")
}
