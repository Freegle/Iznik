package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

// GET /v1/quintile exposes the IMD lookup this server already holds for the fairness isochrone,
// so the batch can stamp a member's deprivation quintile onto their settings once instead of
// every consumer reimplementing a nearest-centroid search over the LSOA file.

func getQuintile(t *testing.T, query string) (int, map[string]interface{}) {
	t.Helper()
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/quintile?"+query, nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := io.ReadAll(resp.Body)
	var body map[string]interface{}
	_ = json.Unmarshal(raw, &body)
	return resp.StatusCode, body
}

func TestQuintile_RequiresCoordinates(t *testing.T) {
	for _, q := range []string{"", "lat=51.45", "lng=-2.58", "lat=abc&lng=-2.58"} {
		if code, _ := getQuintile(t, q); code != 400 {
			t.Errorf("query %q returned %d, expected 400", q, code)
		}
	}
}

// The answer must be a valid quintile or the explicit unknown, never something a caller could
// mistake for real data.
func TestQuintile_ReturnsAQuintileOrAnHonestUnknown(t *testing.T) {
	code, body := getQuintile(t, "lat=51.4545&lng=-2.5879")
	if code != 200 {
		t.Fatalf("expected 200, got %d", code)
	}

	q, ok := body["quintile"].(float64)
	if !ok {
		t.Fatalf("no quintile in response: %v", body)
	}
	if q < 0 || q > 5 {
		t.Errorf("quintile %v outside 0-5", q)
	}

	available, ok := body["available"].(bool)
	if !ok {
		t.Fatalf("no available flag in response: %v", body)
	}
	// Without the flag a caller cannot tell "quintile 0, we have no data for this estate"
	// from "we have no data at all", and would silently treat every member as unknown.
	if !available && q != 0 {
		t.Errorf("available=false must come with quintile 0, got %v", q)
	}
	t.Logf("Bristol test fixture: quintile=%v available=%v", q, available)
}

// Somewhere with no LSOA anywhere near it must come back unknown rather than snapping to a
// distant centroid and reporting it as fact.
func TestQuintile_UnknownFarFromAnyLSOA(t *testing.T) {
	code, body := getQuintile(t, "lat=40.0&lng=-30.0") // mid-Atlantic
	if code != 200 {
		t.Fatalf("expected 200, got %d", code)
	}
	if q, _ := body["quintile"].(float64); q != 0 {
		t.Errorf("expected quintile 0 in the middle of the Atlantic, got %v", q)
	}
}
