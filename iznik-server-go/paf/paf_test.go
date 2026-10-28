package paf

import (
	"io"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestPaf_NotLoggedIn(t *testing.T) {
	app := fiber.New()
	app.Get("/sysadmin/paf", ListImports)
	app.Get("/sysadmin/paf/latest", GetLatest)
	app.Post("/sysadmin/paf", StartImport)

	tests := []struct {
		name, method, url, body string
	}{
		{"list", "GET", "/sysadmin/paf", ""},
		{"latest", "GET", "/sysadmin/paf/latest", ""},
		{"start empty", "POST", "/sysadmin/paf", ""},
		{"start with body", "POST", "/sysadmin/paf", `{"key":"ABCDEFGHIJKLMNOPQRSTUVWX"}`},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			req := httptest.NewRequest(tc.method, tc.url, strings.NewReader(tc.body))
			req.Header.Set("Content-Type", "application/json")
			resp, err := app.Test(req)
			assert.NoError(t, err)
			assert.Equal(t, fiber.StatusUnauthorized, resp.StatusCode)
			b, _ := io.ReadAll(resp.Body)
			assert.Contains(t, string(b), "Not logged in")
		})
	}
}
