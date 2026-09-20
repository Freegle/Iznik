package communityevent

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/gofiber/fiber/v2"
)

// TestHeldByAnotherResponse verifies the exact contract of heldByAnotherResponse.
// The response shape is a client-facing API contract, so we assert every field and key count.
func TestHeldByAnotherResponse(t *testing.T) {
	app := fiber.New()
	app.Get("/heldby", func(c *fiber.Ctx) error {
		return heldByAnotherResponse(c, 4242, "Another Moderator")
	})

	req := httptest.NewRequest(http.MethodGet, "/heldby", nil)
	resp, err := app.Test(req)
	if err != nil {
		t.Fatalf("app.Test() returned error: %v", err)
	}

	if resp.StatusCode != fiber.StatusConflict {
		t.Errorf("status code = %d, want %d", resp.StatusCode, fiber.StatusConflict)
	}

	ct := resp.Header.Get("Content-Type")
	if ct != "application/json" {
		t.Errorf("Content-Type = %q, want application/json", ct)
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		t.Fatalf("failed to read response body: %v", err)
	}

	var got map[string]any
	if err := json.Unmarshal(body, &got); err != nil {
		t.Fatalf("failed to unmarshal JSON: %v\nbody: %s", err, string(body))
	}

	// The API returns numbers as float64 in JSON. Compare ret and heldby as float64.
	wantKeys := map[string]bool{"ret": true, "status": true, "heldby": true, "heldbyname": true}
	if len(got) != 4 {
		t.Errorf("response has %d keys, want exactly 4", len(got))
	}
	for k := range got {
		if !wantKeys[k] {
			t.Errorf("unexpected key %q in response", k)
		}
	}

	if v, ok := got["ret"]; !ok {
		t.Error("missing key 'ret'")
	} else if f, ok := v.(float64); !ok || f != 1.0 {
		t.Errorf("ret = %v (type %T), want float64(1)", v, v)
	}

	if v, ok := got["status"]; !ok {
		t.Error("missing key 'status'")
	} else if s, ok := v.(string); !ok || s != "Held by another moderator" {
		t.Errorf("status = %v (type %T), want \"Held by another moderator\"", v, v)
	}

	if v, ok := got["heldby"]; !ok {
		t.Error("missing key 'heldby'")
	} else if f, ok := v.(float64); !ok || f != 4242.0 {
		t.Errorf("heldby = %v (type %T), want float64(4242)", v, v)
	}

	if v, ok := got["heldbyname"]; !ok {
		t.Error("missing key 'heldbyname'")
	} else if s, ok := v.(string); !ok || s != "Another Moderator" {
		t.Errorf("heldbyname = %v (type %T), want \"Another Moderator\"", v, v)
	}
}
