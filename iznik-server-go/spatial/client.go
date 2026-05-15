package spatial

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"time"
)

var httpClient = &http.Client{Timeout: 5 * time.Second}

func baseURL() string {
	if u := os.Getenv("SPATIAL_SERVER_URL"); u != "" {
		return u
	}
	return "http://localhost:8194"
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
