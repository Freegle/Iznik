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

// ReachOverflowContaining calls GET /v1/reachoverflow/containing for the lanes
// the caller is in, and gets back the posts those rings admit at this point.
//
// The lanes are named, not decoded: the index stamps each ring item with its
// lane and filters server-side, so no caller carries a copy of that encoding.
// One authority answers "does a ring admit this member", and the feed, the
// badge, search, the message page, the reply gate and the mail all ask it - the
// only arrangement in which those surfaces cannot drift apart.
//
// `in` is definite. `partial` sits in the raster's boundary band and is NOT
// admitted by any caller: resolving it exactly costs a ring parse per lane per
// post, which took the read node's load from 8.5 to 45 on 2026-08-21. It is
// returned so callers can see the band exists, never so they can act on it
// differently from one another.
func ReachOverflowContaining(lng, lat float64, lanes []string) (in []int64, partial []int64, err error) {
	if len(lanes) == 0 {
		return nil, nil, nil
	}

	params := url.Values{
		"lng":   {fmt.Sprintf("%f", lng)},
		"lat":   {fmt.Sprintf("%f", lat)},
		"lanes": {strings.Join(lanes, ",")},
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
		In       []int64 `json:"in"`
		Partial  []int64 `json:"partial"`
		Filtered bool    `json:"filtered"`
	}
	if err := json.Unmarshal(body, &out); err != nil {
		return nil, nil, fmt.Errorf("spatial reachoverflow containing parse: %w", err)
	}

	// We asked for specific lanes; if the server did not filter, these are its own
	// PACKED ids (msgid<<4|lane), not msgids. Reading them as msgids would admit
	// arbitrary other posts - only for ring members, and without any error to show
	// for it. A server too old to know the parameter says nothing here, so absent
	// is refused too, and the rings simply stay dark until it is upgraded.
	if !out.Filtered {
		return nil, nil, fmt.Errorf("spatial reachoverflow containing: server did not filter by lane (too old?)")
	}

	return out.In, out.Partial, nil
}

// cellSetMagicLE is the wire-format magic (0x31534343, "CCS1"), little-endian
// - mirrors rippling.cellSetMagic / CellSetService::FORMAT_MAGIC, duplicated
// here rather than imported to avoid a spatial->rippling import cycle
// (rippling already imports spatial for this very function).
var cellSetMagicLE = [4]byte{0x43, 0x43, 0x53, 0x31}

// RasterizeWKT converts a polygon/multipolygon WKT string into its compact
// cell-set form (plans/2026-08-24-rippling-reach-raster-storage.md), via the
// spatial server's POST /v1/reach/rasterize - the ONE place a boundary
// becomes a grid. Used by the reach clip (a secondary-group rejection has to
// turn the REJECTING GROUP's own area into cells before it can subtract them
// from a post's reach grid): the query API, not the admin one, since this is
// a read-shaped conversion, not an index mutation.
//
// A 200 is not proof of a cell set: a misrouted request, a proxy's own 200,
// or a server too old to know this endpoint would otherwise be returned and
// STORED, and every later reader would decode-fail and fall back for the
// life of the row while the column looked converted (the same failure mode
// CellSetService::rasterize's PHP twin was hardened against). Checked here,
// once, at the only place these bytes enter the Go side.
func RasterizeWKT(wkt string) ([]byte, error) {
	reqURL := fmt.Sprintf("%s/v1/reach/rasterize", baseURL())
	resp, err := httpClient.Post(reqURL, "text/plain", strings.NewReader(wkt))
	if err != nil {
		return nil, fmt.Errorf("spatial rasterize: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("spatial rasterize %s: HTTP %d", reqURL, resp.StatusCode)
	}
	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("spatial rasterize %s: read body: %w", reqURL, err)
	}
	if len(body) < 4 || body[0] != cellSetMagicLE[0] || body[1] != cellSetMagicLE[1] ||
		body[2] != cellSetMagicLE[2] || body[3] != cellSetMagicLE[3] {
		// The URL and a short preview are in the message on purpose: the way
		// this fails in practice is a request reaching the WRONG service and
		// getting a perfectly valid 200 from it, which "not a cell set" alone
		// gives you no way to diagnose.
		preview := body
		if len(preview) > 40 {
			preview = preview[:40]
		}
		return nil, fmt.Errorf("spatial rasterize %s: response is not a cell set (%d bytes, starts %q)",
			reqURL, len(body), string(preview))
	}
	return body, nil
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
