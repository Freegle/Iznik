package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
)

func placesTestApp(ix *placesIndex) *fiber.App {
	placesIdx.Store(ix)
	app := fiber.New(fiber.Config{DisableStartupMessage: true})
	registerPlacesRoutes(app, nil)
	return app
}

func getJSON(t *testing.T, app *fiber.App, url string) (*http.Response, map[string]any) {
	t.Helper()
	resp, err := app.Test(httptest.NewRequest(http.MethodGet, url, nil), 5000)
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	var out map[string]any
	if err := json.Unmarshal(body, &out); err != nil {
		t.Fatalf("response not JSON: %v: %s", err, body)
	}
	return resp, out
}

func TestApiNotLoaded(t *testing.T) {
	app := placesTestApp(nil)
	resp, out := getJSON(t, app, "/api?q=Kendal")
	if resp.StatusCode != http.StatusServiceUnavailable {
		t.Errorf("expected 503 when no places file loaded, got %d", resp.StatusCode)
	}
	if out["error"] == nil {
		t.Errorf("expected error body, got %v", out)
	}
}

func TestApiPhotonShape(t *testing.T) {
	app := placesTestApp(testPlaces())
	resp, out := getJSON(t, app, "/api?q=Kendal&bbox=-7.57216793459%2C49.959999905%2C1.68153079591%2C58.6350001085")
	if resp.StatusCode != 200 {
		t.Fatalf("status %d", resp.StatusCode)
	}
	if ct := resp.Header.Get("Content-Type"); !strings.HasPrefix(ct, "application/json") {
		t.Errorf("content type %q", ct)
	}
	if resp.Header.Get("Access-Control-Allow-Origin") != "*" {
		t.Errorf("missing CORS header on GET")
	}
	if out["type"] != "FeatureCollection" {
		t.Errorf("type = %v", out["type"])
	}
	features := out["features"].([]any)
	if len(features) == 0 {
		t.Fatal("no features")
	}
	f0 := features[0].(map[string]any)
	if f0["type"] != "Feature" {
		t.Errorf("feature type = %v", f0["type"])
	}
	gm := f0["geometry"].(map[string]any)
	if gm["type"] != "Point" {
		t.Errorf("geometry type = %v", gm["type"])
	}
	coords := gm["coordinates"].([]any)
	// [lng, lat] order — Kendal is at 54.3N, 2.7W.
	if coords[0].(float64) > 0 || coords[1].(float64) < 50 {
		t.Errorf("coordinates must be [lng,lat]: %v", coords)
	}
	props := f0["properties"].(map[string]any)
	for k, want := range map[string]any{
		"name": "Kendal", "osm_key": "place", "osm_value": "town", "type": "city",
		"osm_type": "R", "country": "United Kingdom", "countrycode": "GB",
		"county": "Westmorland and Furness", "state": "England",
	} {
		if props[k] != want {
			t.Errorf("properties[%s] = %v, want %v", k, props[k], want)
		}
	}
	if props["osm_id"].(float64) != 8292370 {
		t.Errorf("osm_id = %v", props["osm_id"])
	}
	ext := props["extent"].([]any)
	w, n, e, s := ext[0].(float64), ext[1].(float64), ext[2].(float64), ext[3].(float64)
	// The order with a production incident behind it: [W, N, E, S].
	if !(w < e && s < n) {
		t.Errorf("extent must be [W,N,E,S]: %v", ext)
	}
}

func TestApiOptionsCORS(t *testing.T) {
	app := placesTestApp(testPlaces())
	req := httptest.NewRequest(http.MethodOptions, "/api?q=Ken", nil)
	req.Header.Set("Origin", "https://www.ilovefreegle.org")
	req.Header.Set("Access-Control-Request-Method", "GET")
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Errorf("OPTIONS status %d", resp.StatusCode)
	}
	if resp.Header.Get("Access-Control-Allow-Origin") != "*" {
		t.Errorf("missing Allow-Origin")
	}
	if !strings.Contains(resp.Header.Get("Access-Control-Allow-Methods"), "GET") {
		t.Errorf("missing Allow-Methods GET")
	}
	if resp.Header.Get("Access-Control-Allow-Headers") == "" {
		t.Errorf("missing Allow-Headers")
	}
}

// The map components send bbox with spaces after the commas.
func TestApiBboxWithSpaces(t *testing.T) {
	app := placesTestApp(testPlaces())
	resp, out := getJSON(t, app, "/api?q=Kendal&bbox=-7.57216793459,+49.959999905,+1.68153079591,+58.6350001085")
	if resp.StatusCode != 200 {
		t.Fatalf("status %d", resp.StatusCode)
	}
	if len(out["features"].([]any)) == 0 {
		t.Fatal("spaced bbox should still match Kendal")
	}
}

func TestApiLayerFilter(t *testing.T) {
	app := placesTestApp(testPlaces())
	_, out := getJSON(t, app, "/api?q=Kent&layer=city&layer=locality&layer=district")
	for _, f := range out["features"].([]any) {
		props := f.(map[string]any)["properties"].(map[string]any)
		typ := props["type"].(string)
		if typ != "city" && typ != "locality" && typ != "district" {
			t.Errorf("layer filter leaked type %q", typ)
		}
	}
}

// Empty result must be a valid FeatureCollection with a [] features array,
// never null — the leaflet client maps over it unguarded.
func TestApiEmptyResultShape(t *testing.T) {
	app := placesTestApp(testPlaces())
	resp, err := app.Test(httptest.NewRequest(http.MethodGet, "/api?q=zzzqqqxxx", nil), 5000)
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode != 200 {
		t.Fatalf("status %d", resp.StatusCode)
	}
	if !strings.Contains(string(body), `"features":[]`) {
		t.Errorf("empty features must serialise as []: %s", body)
	}
}

func TestApiMissingQ(t *testing.T) {
	app := placesTestApp(testPlaces())
	resp, _ := getJSON(t, app, "/api")
	if resp.StatusCode != http.StatusBadRequest {
		t.Errorf("missing q should 400, got %d", resp.StatusCode)
	}
}

func TestApiLimit(t *testing.T) {
	app := placesTestApp(testPlaces())
	_, out := getJSON(t, app, "/api?q=Ken&limit=2")
	if n := len(out["features"].([]any)); n > 2 {
		t.Errorf("limit=2 exceeded: %d", n)
	}
	// Absurd limits are capped, not honoured.
	_, out = getJSON(t, app, "/api?q=Ken&limit=100000")
	if n := len(out["features"].([]any)); n > 50 {
		t.Errorf("limit cap exceeded: %d", n)
	}
}

func TestApiLatLonBias(t *testing.T) {
	app := placesTestApp(testPlaces())
	_, out := getJSON(t, app, "/api?q=Milton&lat=50.8&lon=-1.09&zoom=13")
	features := out["features"].([]any)
	if len(features) == 0 {
		t.Fatal("no features")
	}
	props := features[0].(map[string]any)["properties"].(map[string]any)
	if props["county"] != "City of Portsmouth" {
		t.Errorf("bias should prefer Portsmouth Milton, got county %v", props["county"])
	}
}

// Full UK postcodes are answered from the locations table; everything the
// canonicaliser accepts must normalise to the stored "OUT IN" form, and
// anything else must fall through to the ordinary index search.
func TestNormalizeUKPostcode(t *testing.T) {
	for q, want := range map[string]string{
		"B160LR":   "B16 0LR",
		"b16 0lr":  "B16 0LR",
		"S5  9fe":  "S5 9FE",
		"SW1A 2DU": "SW1A 2DU",
		"g76 0nh":  "G76 0NH",
	} {
		got, ok := normalizeUKPostcode(q)
		if !ok || got != want {
			t.Errorf("normalizeUKPostcode(%q) = %q, %v; want %q, true", q, got, ok, want)
		}
	}
	for _, q := range []string{"Kendal", "B16", "SW1A", "123456", "B16 0L", "Milton Keynes"} {
		if got, ok := normalizeUKPostcode(q); ok {
			t.Errorf("normalizeUKPostcode(%q) = %q, true; want a fall-through", q, got)
		}
	}
}

// With no DB wired (nil), a postcode-shaped query must not error — it falls
// through to the index and simply finds nothing.
func TestPostcodeQueryFallsThroughWithoutDB(t *testing.T) {
	app := placesTestApp(testPlaces())
	resp, out := getJSON(t, app, "/api?q=B16%200LR")
	if resp.StatusCode != 200 {
		t.Fatalf("status %d", resp.StatusCode)
	}
	if feats, ok := out["features"].([]any); !ok || len(feats) != 0 {
		t.Errorf("expected empty features without a DB, got %v", out["features"])
	}
}

