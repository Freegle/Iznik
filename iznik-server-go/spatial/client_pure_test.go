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

// A 200 is not proof of a cell set: a misrouted request, a proxy's own 200,
// or a server too old to know this endpoint would otherwise be returned and
// STORED by the caller, and every later reader would decode-fail and fall
// back for the life of the row while the column looked converted - the same
// failure mode CellSetService::rasterize's PHP twin is hardened against
// (CellSetServiceTest::test_rasterize_refuses_a_200_that_is_not_a_cell_set).
func TestRasterizeWKTRejectsA200ThatIsNotACellSet(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte("<html>oops</html>"))
	}))
	defer srv.Close()

	t.Setenv("SPATIAL_KNN_URL", srv.URL)

	if _, err := RasterizeWKT("POLYGON((0 0,1 0,1 1,0 1,0 0))"); err == nil {
		t.Error("RasterizeWKT returned nil error for a 200 body that is not a cell set")
	}
}

func TestRasterizeWKTRejectsAnEmpty200(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()

	t.Setenv("SPATIAL_KNN_URL", srv.URL)

	if _, err := RasterizeWKT("POLYGON((0 0,1 0,1 1,0 1,0 0))"); err == nil {
		t.Error("RasterizeWKT returned nil error for an empty 200 body")
	}
}

func TestRasterizeWKTAcceptsARealCellSet(t *testing.T) {
	// A minimal valid header (magic + 4 zeroed fields) is enough to pass the
	// validation this test targets - decoding it is DecodeCellSet's job, not
	// RasterizeWKT's.
	body := []byte{0x43, 0x43, 0x53, 0x31, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(body)
	}))
	defer srv.Close()

	t.Setenv("SPATIAL_KNN_URL", srv.URL)

	got, err := RasterizeWKT("POLYGON((0 0,1 0,1 1,0 1,0 0))")
	if err != nil {
		t.Fatalf("RasterizeWKT returned an error for a real cell-set header: %v", err)
	}
	if string(got) != string(body) {
		t.Errorf("RasterizeWKT returned %x, want the server's own bytes %x unchanged", got, body)
	}
}
