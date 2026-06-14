package main

import (
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
)

// TestSwaggerUI covers registerSwaggerUI: /swagger/ serves the UI page and
// /swagger redirects to it. No DB/index is needed — the routes serve static HTML.
func TestSwaggerUI(t *testing.T) {
	app := fiber.New(fiber.Config{DisableStartupMessage: true})
	registerSwaggerUI(app)

	req := httptest.NewRequest(http.MethodGet, "/swagger/", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatalf("/swagger/: %v", err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("/swagger/: expected 200, got %d", resp.StatusCode)
	}
	body, _ := io.ReadAll(resp.Body)
	if !strings.Contains(string(body), "swagger-ui") {
		t.Errorf("/swagger/: body is not the Swagger UI page")
	}

	req = httptest.NewRequest(http.MethodGet, "/swagger", nil)
	resp, err = app.Test(req, 5000)
	if err != nil {
		t.Fatalf("/swagger redirect: %v", err)
	}
	if resp.StatusCode != http.StatusFound {
		t.Errorf("/swagger: expected 302 redirect, got %d", resp.StatusCode)
	}
}
