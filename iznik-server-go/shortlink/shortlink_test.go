package shortlink

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

func newShortlinkApp() *fiber.App {
	app := fiber.New()
	app.Get("/shortlink", GetShortlink)
	app.Post("/shortlink", PostShortlink)
	return app
}

func newRedirectApp() *fiber.App {
	app := fiber.New()
	app.Get("/shortlink", RedirectShortlink)
	return app
}

func doRequest(t *testing.T, app *fiber.App, method, url string, body io.Reader, contentType string) (*http.Response, map[string]interface{}) {
	t.Helper()
	req := httptest.NewRequest(method, url, body)
	if contentType != "" {
		req.Header.Set("Content-Type", contentType)
	}
	resp, err := app.Test(req)
	assert.NoError(t, err)
	b, _ := io.ReadAll(resp.Body)
	var m map[string]interface{}
	_ = json.Unmarshal(b, &m)
	return resp, m
}

// ---------------------------------------------------------------------------
// PostShortlink — pre-DB validation paths (no DB connection needed)
// ---------------------------------------------------------------------------

func TestPostShortlink_MissingNameAndUrl(t *testing.T) {
	// No parameters at all → 400 "Invalid parameters".
	app := newShortlinkApp()
	resp, m := doRequest(t, app, "POST", "/shortlink", nil, "")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_MissingName_UrlPresent(t *testing.T) {
	// url present but name empty → 400.
	app := newShortlinkApp()
	resp, m := doRequest(t, app, "POST", "/shortlink?url=https://example.com", nil, "")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
	assert.Equal(t, "Invalid parameters", m["status"])
}

func TestPostShortlink_MissingUrl_NamePresent(t *testing.T) {
	// name present but url empty → 400.
	app := newShortlinkApp()
	resp, m := doRequest(t, app, "POST", "/shortlink?name=testlink", nil, "")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_JSONBody_MissingName(t *testing.T) {
	// JSON body with only url → 400 (name empty).
	app := newShortlinkApp()
	body := strings.NewReader(`{"url":"https://example.com"}`)
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/json")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_JSONBody_MissingUrl(t *testing.T) {
	// JSON body with only name → 400 (url empty).
	app := newShortlinkApp()
	body := strings.NewReader(`{"name":"mylink"}`)
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/json")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_JSONBody_EmptyName(t *testing.T) {
	// JSON body with empty string name and valid url → 400.
	app := newShortlinkApp()
	body := strings.NewReader(`{"name":"","url":"https://example.com"}`)
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/json")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_FormValue_MissingName(t *testing.T) {
	// Form body with url but no name → 400.
	app := newShortlinkApp()
	body := strings.NewReader("url=https://example.com")
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/x-www-form-urlencoded")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_FormValue_MissingUrl(t *testing.T) {
	// Form body with name but no url → 400.
	app := newShortlinkApp()
	body := strings.NewReader("name=linkname")
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/x-www-form-urlencoded")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_JSONBodyOverridesQuery_StillMissingUrl(t *testing.T) {
	// JSON body parsed first; if url missing from JSON it falls through to
	// form/query — neither has it → 400.
	app := newShortlinkApp()
	body := strings.NewReader(`{"name":"linkonly"}`)
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/json")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

// ---------------------------------------------------------------------------
// RedirectShortlink — empty-name redirect paths (no DB needed)
// ---------------------------------------------------------------------------

func TestRedirectShortlink_NoNameParam_DefaultUserSite(t *testing.T) {
	// No ?name= parameter with default USER_SITE → redirects to
	// https://www.ilovefreegle.org.
	os.Unsetenv("USER_SITE")
	app := newRedirectApp()
	req := httptest.NewRequest("GET", "/shortlink", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Equal(t, "https://www.ilovefreegle.org", resp.Header.Get("Location"))
}

func TestRedirectShortlink_NoNameParam_CustomUserSite(t *testing.T) {
	// USER_SITE env var overrides the default redirect target.
	os.Setenv("USER_SITE", "custom.example.org")
	defer os.Unsetenv("USER_SITE")
	app := newRedirectApp()
	req := httptest.NewRequest("GET", "/shortlink", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Equal(t, "https://custom.example.org", resp.Header.Get("Location"))
}

func TestRedirectShortlink_EmptyNameParam_RedirectsToDefault(t *testing.T) {
	// ?name= with empty string is treated the same as no name.
	os.Unsetenv("USER_SITE")
	app := newRedirectApp()
	req := httptest.NewRequest("GET", "/shortlink?name=", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusFound, resp.StatusCode)
	assert.Equal(t, "https://www.ilovefreegle.org", resp.Header.Get("Location"))
}

func TestRedirectShortlink_DefaultURL_ContainsHTTPS(t *testing.T) {
	// The redirect URL always starts with https://.
	os.Unsetenv("USER_SITE")
	app := newRedirectApp()
	req := httptest.NewRequest("GET", "/shortlink", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	loc := resp.Header.Get("Location")
	assert.True(t, strings.HasPrefix(loc, "https://"), "redirect location must use https, got %q", loc)
}

func TestRedirectShortlink_StatusCodeIsFound302(t *testing.T) {
	// Verify the redirect status code is 302 (StatusFound), not 301 or 303.
	os.Unsetenv("USER_SITE")
	app := newRedirectApp()
	req := httptest.NewRequest("GET", "/shortlink", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 302, resp.StatusCode)
}

// ---------------------------------------------------------------------------
// Parameter parsing table-driven tests
// ---------------------------------------------------------------------------

func TestRedirectShortlink_UserSiteVariations_BuildsCorrectDefaultURL(t *testing.T) {
	tests := []struct {
		name         string
		userSiteEnv  string
		expectedBase string
	}{
		{"unset", "", "https://www.ilovefreegle.org"},
		{"custom", "test.org", "https://test.org"},
		{"localhost", "localhost:8080", "https://localhost:8080"},
		{"ip", "10.0.0.1", "https://10.0.0.1"},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if tt.userSiteEnv != "" {
				os.Setenv("USER_SITE", tt.userSiteEnv)
			} else {
				os.Unsetenv("USER_SITE")
			}
			defer os.Unsetenv("USER_SITE")

			app := newRedirectApp()
			req := httptest.NewRequest("GET", "/shortlink", nil)
			resp, err := app.Test(req)
			assert.NoError(t, err)
			assert.Equal(t, tt.expectedBase, resp.Header.Get("Location"))
		})
	}
}
