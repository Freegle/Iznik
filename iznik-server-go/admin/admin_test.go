package admin

// Internal test package so coverage is tracked for admin.go directly.
// These tests exercise only pre-DB validation paths (auth guard, body
// parsing, required-field checks). database.DBConn is nil in this context;
// any path that reaches a DB call would panic, so tests never supply a
// combination of inputs (e.g. a non-zero groupid) that would reach one.

import (
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
)

const adminTestSecret = "admin_test_jwt_secret"

func adminApp() *fiber.App {
	app := fiber.New(fiber.Config{
		ErrorHandler: func(c *fiber.Ctx, err error) error {
			code := fiber.StatusInternalServerError
			if e, ok := err.(*fiber.Error); ok {
				code = e.Code
			}
			return c.Status(code).JSON(fiber.Map{"error": err.Error()})
		},
	})
	app.Get("/admin/:id", GetAdmin)
	app.Get("/admin", ListAdmins)
	app.Post("/admin", PostAdmin)
	app.Patch("/admin", PatchAdmin)
	app.Delete("/admin", DeleteAdmin)
	return app
}

func adminAuthToken(t *testing.T, userID uint64) string {
	t.Helper()
	t.Setenv("JWT_SECRET", adminTestSecret)
	claims := jwt.MapClaims{
		"id":        strconv.FormatUint(userID, 10),
		"sessionid": "1",
		"exp":       time.Now().Add(time.Hour).Unix(),
	}
	tok := jwt.NewWithClaims(jwt.SigningMethodHS256, claims)
	s, err := tok.SignedString([]byte(adminTestSecret))
	if err != nil {
		t.Fatalf("failed to sign test token: %v", err)
	}
	return s
}

func newAdminRequest(t *testing.T, method, target, body string, authed bool) *http.Request {
	t.Helper()
	var reader io.Reader
	if body != "" {
		reader = strings.NewReader(body)
	}
	req := httptest.NewRequest(method, target, reader)
	if body != "" {
		req.Header.Set("Content-Type", "application/json")
	}
	if authed {
		req.Header.Set("Authorization", adminAuthToken(t, 999))
	}
	return req
}

func TestGetAdmin_Unauthenticated(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "GET", "/admin/1", "", false)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusUnauthorized {
		t.Errorf("expected 401, got %d", resp.StatusCode)
	}
}

func TestGetAdmin_InvalidID(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "GET", "/admin/not-a-number", "", true)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusBadRequest {
		t.Errorf("expected 400, got %d", resp.StatusCode)
	}
}

func TestListAdmins_Unauthenticated(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "GET", "/admin", "", false)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusUnauthorized {
		t.Errorf("expected 401, got %d", resp.StatusCode)
	}
}

func TestPostAdmin_Unauthenticated(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "POST", "/admin", "", false)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusUnauthorized {
		t.Errorf("expected 401, got %d", resp.StatusCode)
	}
}

func TestPostAdmin_InvalidBody(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "POST", "/admin", "{not json", true)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusBadRequest {
		t.Errorf("expected 400, got %d", resp.StatusCode)
	}
}

func TestPostAdmin_HoldMissingID(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "POST", "/admin", `{"action":"Hold","id":0}`, true)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusBadRequest {
		t.Errorf("expected 400, got %d", resp.StatusCode)
	}
}

func TestPostAdmin_ReleaseMissingID(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "POST", "/admin", `{"action":"Release","id":0}`, true)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusBadRequest {
		t.Errorf("expected 400, got %d", resp.StatusCode)
	}
}

func TestPatchAdmin_Unauthenticated(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "PATCH", "/admin", "", false)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusUnauthorized {
		t.Errorf("expected 401, got %d", resp.StatusCode)
	}
}

func TestPatchAdmin_InvalidBody(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "PATCH", "/admin", "{not json", true)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusBadRequest {
		t.Errorf("expected 400, got %d", resp.StatusCode)
	}
}

func TestPatchAdmin_MissingID(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "PATCH", "/admin", `{}`, true)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusBadRequest {
		t.Errorf("expected 400, got %d", resp.StatusCode)
	}
}

func TestDeleteAdmin_Unauthenticated(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "DELETE", "/admin", "", false)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusUnauthorized {
		t.Errorf("expected 401, got %d", resp.StatusCode)
	}
}

func TestDeleteAdmin_MissingID(t *testing.T) {
	app := adminApp()
	req := newAdminRequest(t, "DELETE", "/admin", "", true)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if resp.StatusCode != fiber.StatusBadRequest {
		t.Errorf("expected 400, got %d", resp.StatusCode)
	}
}
