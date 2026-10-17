package supportai

import (
	"io"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestNullableString(t *testing.T) {
	tests := []struct {
		name string
		in   string
		want *string
	}{
		{"empty is nil", "", nil},
		{"text is kept", "hello", ptr("hello")},
		{"single space is kept", " ", ptr(" ")},
		{"newline is kept", "\n", ptr("\n")},
		{"unicode is kept", "café ☕", ptr("café ☕")},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := nullableString(tc.in)
			if tc.want == nil {
				assert.Nil(t, got)
				return
			}
			if assert.NotNil(t, got) {
				assert.Equal(t, *tc.want, *got)
			}
		})
	}
}

func ptr(s string) *string { return &s }

func TestNameOf(t *testing.T) {
	tests := []struct {
		alias string
	}{{"m"}, {"u"}, {"users"}, {""}}
	for _, tc := range tests {
		t.Run("alias_"+tc.alias, func(t *testing.T) {
			got := nameOf(tc.alias)
			assert.NotContains(t, got, "%s", "every placeholder is replaced")
			assert.Equal(t, 3, strings.Count(got, tc.alias+".fullname")+
				strings.Count(got, tc.alias+".firstname")+
				strings.Count(got, tc.alias+".lastname"))
			assert.Contains(t, got, tc.alias+".fullname")
			assert.Contains(t, got, tc.alias+".firstname")
			assert.Contains(t, got, tc.alias+".lastname")
			assert.True(t, strings.HasPrefix(got, "COALESCE("))
		})
	}
}

func TestRunTableName(t *testing.T) {
	assert.Equal(t, "support_ai_runs", Run{}.TableName())
}

func TestMaxTranscriptBytes(t *testing.T) {
	assert.Equal(t, 2*1024*1024, maxTranscriptBytes)
}

// Without a token nobody is signed in, so every handler refuses before it
// reaches the database or parses a body. Assert on the body as well as the
// status: a refusal that is followed by a success payload is the trap in
// .claude/rules/go-api-traps.md.
func TestHandlersRefuseAnonymous(t *testing.T) {
	app := fiber.New()
	app.Post("/runs", Record)
	app.Patch("/runs", Rate)
	app.Get("/runs", List)
	app.Get("/runs/:id", Get)

	tests := []struct {
		name   string
		method string
		path   string
		body   string
	}{
		{"record", "POST", "/runs", `{"query":"q"}`},
		{"record empty body", "POST", "/runs", ``},
		{"rate", "PATCH", "/runs", `{"id":1,"rating":1}`},
		{"list", "GET", "/runs", ``},
		{"list filtered", "GET", "/runs?rating=up&before=5&limit=3", ``},
		{"get", "GET", "/runs/1", ``},
		{"get bad id", "GET", "/runs/abc", ``},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest(tc.method, tc.path, strings.NewReader(tc.body))
			req.Header.Set("Content-Type", "application/json")
			resp, err := app.Test(req)
			assert.NoError(t, err)
			assert.Equal(t, 401, resp.StatusCode)
			b, _ := io.ReadAll(resp.Body)
			body := string(b)
			assert.Contains(t, body, "Not logged in")
			assert.NotContains(t, body, `"ret":0`)
		})
	}
}
