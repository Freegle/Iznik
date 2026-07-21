package spatial

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

// The admin API lives on its own port next to the query API. If this derivation
// breaks, UpsertLocation silently posts to the query port, the upsert never
// lands, and newly drawn areas go back to picking up no postcodes.
func TestAdminBaseURLDerivedFromQueryURL(t *testing.T) {
	t.Setenv("SPATIAL_ADMIN_URL", "")
	t.Setenv("SPATIAL_KNN_URL", "http://spatial-knn:8194")

	if got := adminBaseURL(); got != "http://spatial-knn:8195" {
		t.Errorf("adminBaseURL() = %q, want http://spatial-knn:8195", got)
	}
}

func TestAdminBaseURLExplicitOverrideWins(t *testing.T) {
	t.Setenv("SPATIAL_KNN_URL", "http://spatial-knn:8194")
	t.Setenv("SPATIAL_ADMIN_URL", "http://elsewhere:9999")

	if got := adminBaseURL(); got != "http://elsewhere:9999" {
		t.Errorf("adminBaseURL() = %q, want the explicit override", got)
	}
}

func TestUpsertLocationPostsItem(t *testing.T) {
	var gotPath string
	var payload struct {
		Items []struct {
			ID    int64          `json:"id"`
			WKT   string         `json:"wkt"`
			Extra map[string]any `json:"extra"`
		} `json:"items"`
	}

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		body, _ := io.ReadAll(r.Body)
		_ = json.Unmarshal(body, &payload)
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"upserted":1}`))
	}))
	defer srv.Close()

	t.Setenv("SPATIAL_ADMIN_URL", srv.URL)

	if err := UpsertLocation(42, "POLYGON((0 0,1 0,1 1,0 1,0 0))", "Watermead", "Polygon"); err != nil {
		t.Fatalf("UpsertLocation returned %v", err)
	}

	if gotPath != "/v1/locations/upsert" {
		t.Errorf("posted to %q, want /v1/locations/upsert", gotPath)
	}
	if len(payload.Items) != 1 {
		t.Fatalf("sent %d items, want 1", len(payload.Items))
	}
	if payload.Items[0].ID != 42 {
		t.Errorf("sent id %d, want 42", payload.Items[0].ID)
	}
	if payload.Items[0].Extra["type"] != "Polygon" {
		t.Errorf("sent type %v, want Polygon - the remap only considers non-Postcode types", payload.Items[0].Extra["type"])
	}
	if payload.Items[0].Extra["name"] != "Watermead" {
		t.Errorf("sent name %v, want Watermead", payload.Items[0].Extra["name"])
	}
}

func TestUpsertLocationReportsHTTPError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	t.Setenv("SPATIAL_ADMIN_URL", srv.URL)

	if err := UpsertLocation(42, "POLYGON((0 0,1 0,1 1,0 1,0 0))", "X", "Polygon"); err == nil {
		t.Error("UpsertLocation returned nil on HTTP 500; the caller needs to know so it can log")
	}
}
