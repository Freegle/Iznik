package main

import (
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// TestSwaggerRedirect verifies that GET /swagger redirects to /swagger/index.html.
func TestSwaggerRedirect(t *testing.T) {
	app := newInternalApp(t)
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
	app := newInternalApp(t)
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
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/swagger/swagger.json", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Errorf("expected 200, got %d", resp.StatusCode)
	}
}

// TestSwaggerPublicOnExternalApp verifies /swagger is accessible without JWT on the external port.
func TestSwaggerPublicOnExternalApp(t *testing.T) {
	app := newExternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/swagger", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 302 {
		t.Errorf("external /swagger: expected 302, got %d", resp.StatusCode)
	}
}
