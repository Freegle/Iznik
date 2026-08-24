package spatial

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"
)

func TestBaseURL(t *testing.T) {
	tests := []struct {
		name       string
		knnURL     string
		serverURL  string
		wantResult string
	}{
		{"KNN env var takes precedence", "http://knn.example", "http://server.example", "http://knn.example"},
		{"falls back to SPATIAL_SERVER_URL", "", "http://server.example", "http://server.example"},
		{"falls back to default when both unset", "", "", "http://localhost:8194"},
		{"empty KNN falls through even if server also empty", "", "", "http://localhost:8194"},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			oldKNN, hadKNN := os.LookupEnv("SPATIAL_KNN_URL")
			oldServer, hadServer := os.LookupEnv("SPATIAL_SERVER_URL")
			defer func() {
				if hadKNN {
					os.Setenv("SPATIAL_KNN_URL", oldKNN)
				} else {
					os.Unsetenv("SPATIAL_KNN_URL")
				}
				if hadServer {
					os.Setenv("SPATIAL_SERVER_URL", oldServer)
				} else {
					os.Unsetenv("SPATIAL_SERVER_URL")
				}
			}()

			if tt.knnURL == "" {
				os.Unsetenv("SPATIAL_KNN_URL")
			} else {
				os.Setenv("SPATIAL_KNN_URL", tt.knnURL)
			}
			if tt.serverURL == "" {
				os.Unsetenv("SPATIAL_SERVER_URL")
			} else {
				os.Setenv("SPATIAL_SERVER_URL", tt.serverURL)
			}

			if got := baseURL(); got != tt.wantResult {
				t.Errorf("baseURL() = %q, want %q", got, tt.wantResult)
			}
		})
	}
}

func TestExtraString(t *testing.T) {
	tests := []struct {
		name string
		r    QueryResult
		key  string
		want string
	}{
		{"present string value", QueryResult{Extra: map[string]any{"name": "Fred"}}, "name", "Fred"},
		{"missing key", QueryResult{Extra: map[string]any{"name": "Fred"}}, "missing", ""},
		{"wrong type (number)", QueryResult{Extra: map[string]any{"name": float64(5)}}, "name", ""},
		{"nil Extra map", QueryResult{}, "name", ""},
		{"empty string value", QueryResult{Extra: map[string]any{"name": ""}}, "name", ""},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := ExtraString(tt.r, tt.key); got != tt.want {
				t.Errorf("ExtraString() = %q, want %q", got, tt.want)
			}
		})
	}
}

func TestExtraInt64(t *testing.T) {
	tests := []struct {
		name string
		r    QueryResult
		key  string
		want int64
	}{
		{"float64 value (typical JSON decode)", QueryResult{Extra: map[string]any{"id": float64(42)}}, "id", 42},
		{"int64 value", QueryResult{Extra: map[string]any{"id": int64(7)}}, "id", 7},
		{"missing key", QueryResult{Extra: map[string]any{"id": float64(42)}}, "missing", 0},
		{"wrong type (string)", QueryResult{Extra: map[string]any{"id": "42"}}, "id", 0},
		{"nil Extra map", QueryResult{}, "id", 0},
		{"negative float64", QueryResult{Extra: map[string]any{"id": float64(-3)}}, "id", -3},
		{"zero float64", QueryResult{Extra: map[string]any{"id": float64(0)}}, "id", 0},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := ExtraInt64(tt.r, tt.key); got != tt.want {
				t.Errorf("ExtraInt64() = %d, want %d", got, tt.want)
			}
		})
	}
}

func withServer(t *testing.T, handler http.HandlerFunc) func() {
	t.Helper()
	srv := httptest.NewServer(handler)
	oldKNN, hadKNN := os.LookupEnv("SPATIAL_KNN_URL")
	os.Setenv("SPATIAL_KNN_URL", srv.URL)
	return func() {
		srv.Close()
		if hadKNN {
			os.Setenv("SPATIAL_KNN_URL", oldKNN)
		} else {
			os.Unsetenv("SPATIAL_KNN_URL")
		}
	}
}

func TestKNN_Success(t *testing.T) {
	cleanup := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		if !strings.Contains(r.URL.Path, "/v1/locations/knn") {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		if r.URL.Query().Get("type") != "postcode" {
			t.Errorf("expected type=postcode, got %q", r.URL.Query().Get("type"))
		}
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]any{
			"results": []QueryResult{
				{ID: 1, Distance: 0.5, Extra: map[string]any{"name": "A"}},
				{ID: 2, Distance: 1.5, Extra: map[string]any{"name": "B"}},
			},
		})
	})
	defer cleanup()

	results, err := KNN("locations", -1.5, 53.5, 5, "postcode")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(results) != 2 {
		t.Fatalf("expected 2 results, got %d", len(results))
	}
	if results[0].ID != 1 || results[1].ID != 2 {
		t.Errorf("unexpected result IDs: %+v", results)
	}
}

func TestKNN_NoTypeFilter(t *testing.T) {
	cleanup := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Has("type") {
			t.Errorf("expected no type param, got %q", r.URL.Query().Get("type"))
		}
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]any{"results": []QueryResult{}})
	})
	defer cleanup()

	results, err := KNN("items", 0, 0, 10, "")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(results) != 0 {
		t.Errorf("expected 0 results, got %d", len(results))
	}
}

func TestKNN_ServiceUnavailable(t *testing.T) {
	cleanup := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	})
	defer cleanup()

	_, err := KNN("locations", 0, 0, 5, "")
	if err == nil {
		t.Fatal("expected error for 503, got nil")
	}
	if !strings.Contains(err.Error(), "not ready") {
		t.Errorf("expected 'not ready' error, got: %v", err)
	}
}

func TestKNN_NonOKStatus(t *testing.T) {
	cleanup := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	})
	defer cleanup()

	_, err := KNN("locations", 0, 0, 5, "")
	if err == nil {
		t.Fatal("expected error for 500, got nil")
	}
	if !strings.Contains(err.Error(), "HTTP 500") {
		t.Errorf("expected HTTP 500 in error, got: %v", err)
	}
}

func TestKNN_MalformedJSON(t *testing.T) {
	cleanup := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("{not json"))
	})
	defer cleanup()

	_, err := KNN("locations", 0, 0, 5, "")
	if err == nil {
		t.Fatal("expected JSON parse error, got nil")
	}
	if !strings.Contains(err.Error(), "parse") {
		t.Errorf("expected parse error, got: %v", err)
	}
}

func TestKNN_ConnectionError(t *testing.T) {
	oldKNN, hadKNN := os.LookupEnv("SPATIAL_KNN_URL")
	os.Setenv("SPATIAL_KNN_URL", "http://127.0.0.1:1")
	defer func() {
		if hadKNN {
			os.Setenv("SPATIAL_KNN_URL", oldKNN)
		} else {
			os.Unsetenv("SPATIAL_KNN_URL")
		}
	}()

	_, err := KNN("locations", 0, 0, 5, "")
	if err == nil {
		t.Fatal("expected connection error, got nil")
	}
	if !strings.Contains(err.Error(), "spatial KNN") {
		t.Errorf("expected wrapped 'spatial KNN' error, got: %v", err)
	}
}

func TestWithin_Success(t *testing.T) {
	cleanup := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		if !strings.Contains(r.URL.Path, "/v1/locations/within") {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		if r.URL.Query().Get("polygon") != "POLYGON((0 0,1 1,1 0,0 0))" {
			t.Errorf("unexpected polygon param: %q", r.URL.Query().Get("polygon"))
		}
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]any{"ids": []int64{10, 20, 30}})
	})
	defer cleanup()

	ids, err := Within("locations", "POLYGON((0 0,1 1,1 0,0 0))")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(ids) != 3 || ids[0] != 10 || ids[2] != 30 {
		t.Errorf("unexpected ids: %+v", ids)
	}
}

func TestWithin_ServiceUnavailable(t *testing.T) {
	cleanup := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	})
	defer cleanup()

	_, err := Within("locations", "POLYGON(())")
	if err == nil {
		t.Fatal("expected error for 503, got nil")
	}
	if !strings.Contains(err.Error(), "not ready") {
		t.Errorf("expected 'not ready' error, got: %v", err)
	}
}

func TestWithin_NonOKStatus(t *testing.T) {
	cleanup := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		w.Write([]byte("bad polygon"))
	})
	defer cleanup()

	_, err := Within("locations", "invalid")
	if err == nil {
		t.Fatal("expected error for 400, got nil")
	}
	if !strings.Contains(err.Error(), "HTTP 400") {
		t.Errorf("expected HTTP 400 in error, got: %v", err)
	}
}

func TestWithin_MalformedJSON(t *testing.T) {
	cleanup := withServer(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("not json at all"))
	})
	defer cleanup()

	_, err := Within("locations", "POLYGON(())")
	if err == nil {
		t.Fatal("expected JSON parse error, got nil")
	}
	if !strings.Contains(err.Error(), "parse") {
		t.Errorf("expected parse error, got: %v", err)
	}
}

func TestWithin_ConnectionError(t *testing.T) {
	oldKNN, hadKNN := os.LookupEnv("SPATIAL_KNN_URL")
	os.Setenv("SPATIAL_KNN_URL", "http://127.0.0.1:1")
	defer func() {
		if hadKNN {
			os.Setenv("SPATIAL_KNN_URL", oldKNN)
		} else {
			os.Unsetenv("SPATIAL_KNN_URL")
		}
	}()

	_, err := Within("locations", "POLYGON(())")
	if err == nil {
		t.Fatal("expected connection error, got nil")
	}
	if !strings.Contains(err.Error(), "spatial Within") {
		t.Errorf("expected wrapped 'spatial Within' error, got: %v", err)
	}
}
