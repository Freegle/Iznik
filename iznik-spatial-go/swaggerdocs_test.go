package main

import (
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
)

// TestSwaggerRedirect verifies that GET /swagger redirects to /swagger/index.html.
func TestSwaggerRedirect(t *testing.T) {
	app := fiber.New(fiber.Config{DisableStartupMessage: true})
	app.Get("/swagger", func(c *fiber.Ctx) error {
		return c.Redirect("/swagger/index.html", 302)
	})
	app.Static("/swagger", "./swagger", fiber.Static{Index: "index.html"})

	req := httptest.NewRequest(http.MethodGet, "/swagger", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 302 {
		t.Errorf("expected 302, got %d", resp.StatusCode)
	}
}

// TestSwaggerIndexReachable verifies that GET /swagger/index.html returns the Redoc UI.
func TestSwaggerIndexReachable(t *testing.T) {
	app := fiber.New(fiber.Config{DisableStartupMessage: true})
	app.Get("/swagger", func(c *fiber.Ctx) error {
		return c.Redirect("/swagger/index.html", 302)
	})
	app.Static("/swagger", "./swagger", fiber.Static{Index: "index.html"})

	req := httptest.NewRequest(http.MethodGet, "/swagger/index.html", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Errorf("expected 200, got %d", resp.StatusCode)
	}
	body, _ := io.ReadAll(resp.Body)
	if !strings.Contains(string(body), "redoc") {
		t.Errorf("/swagger/index.html: body does not contain 'redoc'")
	}
}

// TestSwaggerJSONReachable verifies that the generated swagger.json is served.
func TestSwaggerJSONReachable(t *testing.T) {
	app := fiber.New(fiber.Config{DisableStartupMessage: true})
	app.Get("/swagger", func(c *fiber.Ctx) error {
		return c.Redirect("/swagger/index.html", 302)
	})
	app.Static("/swagger", "./swagger", fiber.Static{Index: "index.html"})

	req := httptest.NewRequest(http.MethodGet, "/swagger/swagger.json", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Errorf("expected 200, got %d", resp.StatusCode)
	}
}
