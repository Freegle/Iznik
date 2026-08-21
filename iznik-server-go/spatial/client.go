package spatial

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"
)

var httpClient = &http.Client{Timeout: 5 * time.Second}

func baseURL() string {
	// SPATIAL_KNN_URL is the canonical name; SPATIAL_SERVER_URL is kept for backward compat.
	if u := os.Getenv("SPATIAL_KNN_URL"); u != "" {
		return u
	}
	if u := os.Getenv("SPATIAL_SERVER_URL"); u != "" {
		return u
	}
	return "http://localhost:8194"
}

// adminBaseURL returns the spatial server's admin API, which listens on its own
// port (SPATIAL_ADMIN_PORT, default 8195) alongside the query API on 8194.
func adminBaseURL() string {
	if u := os.Getenv("SPATIAL_ADMIN_URL"); u != "" {
		return u
	}
	return strings.Replace(baseURL(), ":8194", ":8195", 1)
}

// UpsertLocation inserts or replaces one location in the spatial index straight
// away, rather than waiting for the next delta sync.
//
// This matters because postcode remapping asks the spatial index which area is
// nearest. The index only picks up MySQL changes on a 15-minute delta, but the
// remap for a newly drawn area is queued immediately, so without this the remap
// runs against an index that has never heard of the area, keeps every postcode
// pointing where it already pointed, and leaves the area with no postcodes. The
// ModTools map only draws areas that have at least one postcode pointing at
// them, so the area then appears not to have saved at all (Discourse #9950).
func UpsertLocation(id uint64, wkt, name, locType string) error {
	body, err := json.Marshal(map[string]any{
		"items": []map[string]any{{
			"id":    id,
			"wkt":   wkt,
			"extra": map[string]any{"name": name, "type": locType},
		}},
	})
	if err != nil {
		return fmt.Errorf("spatial upsert marshal: %w", err)
	}

	reqURL := fmt.Sprintf("%s/v1/locations/upsert", adminBaseURL())
	resp, err := httpClient.Post(reqURL, "application/json", bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("spatial upsert: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("spatial upsert: HTTP %d", resp.StatusCode)
	}
	return nil
}

// QueryResult mirrors the JSON shape returned by /v1/:dataset/knn.
type QueryResult struct {
	ID       int64          `json:"id"`
	Distance float64        `json:"distance"`
	Extra    map[string]any `json:"extra"`
}

// KNN calls GET /v1/:dataset/knn and returns up to limit results nearest to (lng, lat).
// typeFilter is forwarded as the `type` query param (locations dataset only); pass "" to omit.
func KNN(dataset string, lng, lat float64, limit int, typeFilter string) ([]QueryResult, error) {
	params := url.Values{
		"lng":   {fmt.Sprintf("%f", lng)},
		"lat":   {fmt.Sprintf("%f", lat)},
		"limit": {fmt.Sprintf("%d", limit)},
	}
	if typeFilter != "" {
		params.Set("type", typeFilter)
	}
	reqURL := fmt.Sprintf("%s/v1/%s/knn?%s", baseURL(), url.PathEscape(dataset), params.Encode())

	resp, err := httpClient.Get(reqURL)
	if err != nil {
		return nil, fmt.Errorf("spatial KNN %s: %w", dataset, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusServiceUnavailable {
		return nil, fmt.Errorf("spatial dataset %q not ready", dataset)
	}
	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("spatial KNN %s: HTTP %d", dataset, resp.StatusCode)
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("spatial KNN %s read body: %w", dataset, err)
	}

	var out struct {
		Results []QueryResult `json:"results"`
	}
	if err := json.Unmarshal(body, &out); err != nil {
		return nil, fmt.Errorf("spatial KNN %s parse: %w", dataset, err)
	}
	return out.Results, nil
}

// Within calls GET /v1/:dataset/within and returns all item IDs intersecting the WKT polygon.
func Within(dataset, polygonWKT string) ([]int64, error) {
	params := url.Values{"polygon": {polygonWKT}}
	reqURL := fmt.Sprintf("%s/v1/%s/within?%s", baseURL(), url.PathEscape(dataset), params.Encode())

	resp, err := httpClient.Get(reqURL)
	if err != nil {
		return nil, fmt.Errorf("spatial Within %s: %w", dataset, err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusServiceUnavailable {
		return nil, fmt.Errorf("spatial dataset %q not ready", dataset)
	}
	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("spatial Within %s: HTTP %d: %s", dataset, resp.StatusCode, body)
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("spatial Within %s read body: %w", dataset, err)
	}

	var out struct {
		IDs []int64 `json:"ids"`
	}
	if err := json.Unmarshal(body, &out); err != nil {
		return nil, fmt.Errorf("spatial Within %s parse: %w", dataset, err)
	}
	return out.IDs, nil
}

// ReachContaining calls GET /v1/reach/containing: all live reaches covering
// the point. `in` are definite; `partial` fall in the raster boundary band
// and the caller must exact-test them against rippling_reach.polygon.
func ReachContaining(lng, lat float64) (in []int64, partial []int64, err error) {
	params := url.Values{
		"lng": {fmt.Sprintf("%f", lng)},
		"lat": {fmt.Sprintf("%f", lat)},
	}
	reqURL := fmt.Sprintf("%s/v1/reach/containing?%s", baseURL(), params.Encode())

	resp, err := httpClient.Get(reqURL)
	if err != nil {
		return nil, nil, fmt.Errorf("spatial reach containing: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusServiceUnavailable {
		return nil, nil, fmt.Errorf("spatial dataset \"reach\" not ready")
	}
	if resp.StatusCode != http.StatusOK {
		return nil, nil, fmt.Errorf("spatial reach containing: HTTP %d", resp.StatusCode)
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, nil, fmt.Errorf("spatial reach containing read body: %w", err)
	}

	var out struct {
		In      []int64 `json:"in"`
		Partial []int64 `json:"partial"`
	}
	if err := json.Unmarshal(body, &out); err != nil {
		return nil, nil, fmt.Errorf("spatial reach containing parse: %w", err)
	}
	return out.In, out.Partial, nil
}

// ReachOverflowContaining calls GET /v1/reachoverflow/containing: every
// (post, ring lane) whose ring covers the point.
//
// The ids are PACKED - post and lane in one int64, decoded by
// rippling.DecodeOverflowExtID - because one index answers a per-lane question:
// the same post admits a sparse-band member on one ring and refuses a
// dense-band one on another. `in` are definite; `partial` sit in the raster's
// boundary band and the caller must exact-test them against the ring JSON.
func ReachOverflowContaining(lng, lat float64) (in []int64, partial []int64, err error) {
	params := url.Values{
		"lng": {fmt.Sprintf("%f", lng)},
		"lat": {fmt.Sprintf("%f", lat)},
	}
	reqURL := fmt.Sprintf("%s/v1/reachoverflow/containing?%s", baseURL(), params.Encode())

	resp, err := httpClient.Get(reqURL)
	if err != nil {
		return nil, nil, fmt.Errorf("spatial reachoverflow containing: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusServiceUnavailable {
		return nil, nil, fmt.Errorf("spatial dataset \"reachoverflow\" not ready")
	}
	if resp.StatusCode != http.StatusOK {
		return nil, nil, fmt.Errorf("spatial reachoverflow containing: HTTP %d", resp.StatusCode)
	}

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, nil, fmt.Errorf("spatial reachoverflow containing read body: %w", err)
	}

	var out struct {
		In      []int64 `json:"in"`
		Partial []int64 `json:"partial"`
	}
	if err := json.Unmarshal(body, &out); err != nil {
		return nil, nil, fmt.Errorf("spatial reachoverflow containing parse: %w", err)
	}
	return out.In, out.Partial, nil
}

// ExtraString returns a string value from a QueryResult.Extra map, or "" if absent.
func ExtraString(r QueryResult, key string) string {
	if v, ok := r.Extra[key].(string); ok {
		return v
	}
	return ""
}

// ExtraInt64 returns an int64 value from a QueryResult.Extra map.
// JSON numbers decode as float64, so we convert.
func ExtraInt64(r QueryResult, key string) int64 {
	switch v := r.Extra[key].(type) {
	case float64:
		return int64(v)
	case int64:
		return v
	}
	return 0
}
