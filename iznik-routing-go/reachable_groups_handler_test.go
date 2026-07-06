package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestReachableGroups_MissingParams(t *testing.T) {
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/reachable-groups?lng=-2.5879", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 400 {
		t.Fatalf("missing lat should be 400, got %d", resp.StatusCode)
	}
}

// The endpoint always returns a present, non-null reachable_group_ids list (empty
// when there is no group DB / no reached nodes). Exercises the full handler path.
func TestReachableGroups_ReturnsPresentList(t *testing.T) {
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/reachable-groups?lat=51.4545&lng=-2.5879&minutes=5", nil)
	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		body, _ := io.ReadAll(resp.Body)
		t.Fatalf("expected 200, got %d: %s", resp.StatusCode, body)
	}
	body, _ := io.ReadAll(resp.Body)
	var out struct {
		ReachableGroupIDs []int64 `json:"reachable_group_ids"`
	}
	if err := json.Unmarshal(body, &out); err != nil {
		t.Fatalf("invalid JSON: %v", err)
	}
	if out.ReachableGroupIDs == nil {
		t.Error("reachable_group_ids must be present (non-null) even when empty")
	}
}
