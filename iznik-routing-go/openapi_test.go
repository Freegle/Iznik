package main

import (
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// TestDocsEndpoints covers registerDocs: the OpenAPI spec and Swagger UI must
// be served, and must be PUBLIC (no JWT) on both the internal and external apps.
func TestDocsEndpoints(t *testing.T) {
	app := newInternalApp(t)

	// /openapi.yaml — served as YAML, contains the spec.
	req := httptest.NewRequest(http.MethodGet, "/openapi.yaml", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatalf("/openapi.yaml: %v", err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("/openapi.yaml: expected 200, got %d", resp.StatusCode)
	}
	if ct := resp.Header.Get("Content-Type"); !strings.Contains(ct, "yaml") {
		t.Errorf("/openapi.yaml: expected yaml content-type, got %q", ct)
	}
	body, _ := io.ReadAll(resp.Body)
	if !strings.Contains(string(body), "openapi: 3.0.3") {
		t.Errorf("/openapi.yaml: spec body missing 'openapi: 3.0.3'")
	}

	// /swagger/ — Swagger UI HTML.
	req = httptest.NewRequest(http.MethodGet, "/swagger/", nil)
	resp, err = app.Test(req, 5000)
	if err != nil {
		t.Fatalf("/swagger/: %v", err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("/swagger/: expected 200, got %d", resp.StatusCode)
	}
	body, _ = io.ReadAll(resp.Body)
	if !strings.Contains(string(body), "swagger-ui") {
		t.Errorf("/swagger/: body is not the Swagger UI page")
	}

	// /swagger redirects to /swagger/.
	req = httptest.NewRequest(http.MethodGet, "/swagger", nil)
	resp, err = app.Test(req, 5000)
	if err != nil {
		t.Fatalf("/swagger redirect: %v", err)
	}
	if resp.StatusCode != http.StatusFound {
		t.Errorf("/swagger: expected 302 redirect, got %d", resp.StatusCode)
	}

	// Docs are public on the external (JWT-gated) app too.
	ext := newExternalApp(t)
	req = httptest.NewRequest(http.MethodGet, "/openapi.yaml", nil)
	resp, err = ext.Test(req, 5000)
	if err != nil {
		t.Fatalf("external /openapi.yaml: %v", err)
	}
	if resp.StatusCode != 200 {
		t.Errorf("external /openapi.yaml: expected 200 (public), got %d", resp.StatusCode)
	}
}
