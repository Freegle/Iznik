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

func TestPostShortlink_MissingNameAndGroupid(t *testing.T) {
	// No parameters at all → 400 "Invalid parameters".
	app := newShortlinkApp()
	resp, m := doRequest(t, app, "POST", "/shortlink", nil, "")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_MissingName_GroupidPresent(t *testing.T) {
	// groupid present but name empty → 400.
	app := newShortlinkApp()
	resp, m := doRequest(t, app, "POST", "/shortlink?groupid=42", nil, "")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
	assert.Equal(t, "Invalid parameters", m["status"])
}

func TestPostShortlink_MissingGroupid_NamePresent(t *testing.T) {
	// name present but groupid = 0 → 400.
	app := newShortlinkApp()
	resp, m := doRequest(t, app, "POST", "/shortlink?name=testlink", nil, "")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_JSONBody_MissingName(t *testing.T) {
	// JSON body with only groupid → 400 (name empty).
	app := newShortlinkApp()
	body := strings.NewReader(`{"groupid":99}`)
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/json")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_JSONBody_MissingGroupid(t *testing.T) {
	// JSON body with only name → 400 (groupid = 0).
	app := newShortlinkApp()
	body := strings.NewReader(`{"name":"mylink"}`)
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/json")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_JSONBody_EmptyName(t *testing.T) {
	// JSON body with empty string name and valid groupid → 400.
	app := newShortlinkApp()
	body := strings.NewReader(`{"name":"","groupid":5}`)
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/json")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_FormValue_MissingName(t *testing.T) {
	// Form body with groupid but no name → 400.
	app := newShortlinkApp()
	body := strings.NewReader("groupid=7")
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/x-www-form-urlencoded")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_FormValue_MissingGroupid(t *testing.T) {
	// Form body with name but no groupid → 400.
	app := newShortlinkApp()
	body := strings.NewReader("name=linkname")
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/x-www-form-urlencoded")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_JSONBodyOverridesQuery_StillMissingGroupid(t *testing.T) {
	// JSON body parsed first; if groupid missing from JSON it falls through to
	// form/query — neither has it → 400.
	app := newShortlinkApp()
	body := strings.NewReader(`{"name":"linkonly"}`)
	resp, m := doRequest(t, app, "POST", "/shortlink", body, "application/json")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_ZeroGroupid_Invalid(t *testing.T) {
	// Explicitly set groupid=0 with a valid name → 400.
	app := newShortlinkApp()
	resp, m := doRequest(t, app, "POST", "/shortlink?name=testname&groupid=0", nil, "")
	assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
	assert.Equal(t, float64(2), m["ret"])
}

func TestPostShortlink_InvalidGroupidNumber_DefaultsToZero(t *testing.T) {
	// Invalid groupid parameter (non-numeric) → strconv.ParseUint returns 0.
	// With 0 groupid and valid name → 400.
	app := newShortlinkApp()
	resp, m := doRequest(t, app, "POST", "/shortlink?name=valid&groupid=notanumber", nil, "")
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
// resolveShortlinkURL — pure-logic branches that don't touch the DB
// ---------------------------------------------------------------------------

func TestResolveShortlinkURL_NonGroupType_NoChange(t *testing.T) {
	// When Type is not "Group", the function must leave Url and Nameshort unchanged.
	original := "https://somewhere.example.com"
	s := &Shortlink{
		ID:        1,
		Type:      "URL",
		Url:       &original,
		Nameshort: "unchanged",
	}
	resolveShortlinkURL(s, "www.ilovefreegle.org")
	assert.Equal(t, original, *s.Url)
	assert.Equal(t, "unchanged", s.Nameshort)
}

func TestResolveShortlinkURL_GroupType_NilGroupid_NoChange(t *testing.T) {
	// Group type with nil Groupid must not touch the DB or modify the struct.
	original := "https://external.example.com"
	s := &Shortlink{
		ID:      2,
		Type:    "Group",
		Groupid: nil,
		Url:     &original,
	}
	resolveShortlinkURL(s, "www.ilovefreegle.org")
	assert.Equal(t, original, *s.Url, "Url must not be changed when Groupid is nil")
}

func TestResolveShortlinkURL_EmptyType_NoChange(t *testing.T) {
	// An empty Type string (not "Group") → no change.
	s := &Shortlink{
		ID:        3,
		Type:      "",
		Nameshort: "before",
	}
	resolveShortlinkURL(s, "www.ilovefreegle.org")
	assert.Nil(t, s.Url)
	assert.Equal(t, "before", s.Nameshort)
}

func TestResolveShortlinkURL_TypeCaseSensitive(t *testing.T) {
	// Only the exact string "Group" triggers DB resolution; "group" must not.
	s := &Shortlink{
		ID:   4,
		Type: "group", // lowercase — should not match
	}
	gid := uint64(999)
	s.Groupid = &gid
	resolveShortlinkURL(s, "www.ilovefreegle.org")
	// Url should remain nil since no DB lookup was made.
	assert.Nil(t, s.Url)
	assert.Equal(t, "", s.Nameshort)
}

func TestResolveShortlinkURL_PreservesNamshortWhenTypeNotGroup(t *testing.T) {
	// When Type != "Group", Nameshort is not modified by resolveShortlinkURL.
	nameshort := "preserved_value"
	s := &Shortlink{
		ID:        5,
		Type:      "Custom",
		Nameshort: nameshort,
	}
	resolveShortlinkURL(s, "example.org")
	assert.Equal(t, nameshort, s.Nameshort)
}

// ---------------------------------------------------------------------------
// Parameter parsing table-driven tests
// ---------------------------------------------------------------------------

func TestPostShortlink_VariousInvalidGroupids(t *testing.T) {
	tests := []struct {
		name       string
		groupidVal string
		expectRet  float64
	}{
		{"notanumber", "notanumber", 2.0},
		{"empty", "", 2.0},
		{"negative", "-5", 2.0}, // ParseUint error, defaults to 0
		{"zero", "0", 2.0},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			app := newShortlinkApp()
			url := "/shortlink?name=testname"
			if tt.groupidVal != "" {
				url += "&groupid=" + tt.groupidVal
			}
			resp, m := doRequest(t, app, "POST", url, nil, "")
			assert.Equal(t, fiber.StatusBadRequest, resp.StatusCode)
			assert.Equal(t, tt.expectRet, m["ret"])
		})
	}
}

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
