package stdmsg

import (
	"encoding/json"
	"io"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func newApp() *fiber.App {
	app := fiber.New()
	app.Get("/stdmsg", GetStdMsg)
	app.Post("/stdmsg", PostStdMsg)
	app.Patch("/stdmsg", PatchStdMsg)
	app.Delete("/stdmsg", DeleteStdMsg)
	return app
}

// With no session every handler must refuse before touching the database, whatever
// the method, content type, body or query string.
func TestStdMsg_NotLoggedIn(t *testing.T) {
	app := newApp()

	tests := []struct {
		name        string
		method      string
		url         string
		body        string
		contentType string
	}{
		{"get no params", "GET", "/stdmsg", "", ""},
		{"get with id", "GET", "/stdmsg?id=5", "", ""},
		{"post no params", "POST", "/stdmsg", "", ""},
		{"post json", "POST", "/stdmsg", `{"configid":1,"title":"t"}`, "application/json"},
		{"post form", "POST", "/stdmsg", "configid=1&title=t", "application/x-www-form-urlencoded"},
		{"post query", "POST", "/stdmsg?configid=1&title=t", "", ""},
		{"post bad json", "POST", "/stdmsg", `{not json`, "application/json"},
		{"patch no params", "PATCH", "/stdmsg", "", ""},
		{"patch json", "PATCH", "/stdmsg", `{"id":1,"title":"x"}`, "application/json"},
		{"patch form", "PATCH", "/stdmsg", "id=1&title=x", "application/x-www-form-urlencoded"},
		{"patch query", "PATCH", "/stdmsg?id=1", "", ""},
		{"delete no params", "DELETE", "/stdmsg", "", ""},
		{"delete query", "DELETE", "/stdmsg?id=1", "", ""},
		{"delete json", "DELETE", "/stdmsg", `{"id":1}`, "application/json"},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest(tc.method, tc.url, strings.NewReader(tc.body))
			if tc.contentType != "" {
				req.Header.Set("Content-Type", tc.contentType)
			}
			resp, err := app.Test(req)
			assert.NoError(t, err)
			assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)

			b, _ := io.ReadAll(resp.Body)
			var m map[string]interface{}
			assert.NoError(t, json.Unmarshal(b, &m))
			assert.Equal(t, float64(1), m["ret"])
			assert.Equal(t, "Not logged in", m["status"])
		})
	}
}

// An Authorization2 header that does not parse as a persistent token must not log anyone in.
func TestStdMsg_NotLoggedIn_BadPersistentToken(t *testing.T) {
	app := newApp()

	for _, hdr := range []string{"garbage", `{"id":0,"token":"x"}`, `{"id":5,"token":""}`} {
		req := httptest.NewRequest("GET", "/stdmsg?id=1", nil)
		req.Header.Set("Authorization2", hdr)
		resp, err := app.Test(req)
		assert.NoError(t, err)
		assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode, hdr)
	}
}

func TestStdMsg_StructJSON(t *testing.T) {
	ins := "ins"
	b, err := json.Marshal(StdMsg{ID: 3, Title: "T", Insert: &ins})
	assert.NoError(t, err)
	var m map[string]interface{}
	assert.NoError(t, json.Unmarshal(b, &m))
	assert.Equal(t, float64(3), m["id"])
	assert.Equal(t, "T", m["title"])
	assert.Equal(t, "ins", m["insert"])
	assert.Nil(t, m["replybody"])
}
