package misc

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// LokiStream is one stream of a Loki log query: its labels and its [timestamp_ns, line] pairs.
type LokiStream struct {
	Stream map[string]string `json:"stream"`
	Values [][]string        `json:"values"`
}

// LokiStatusError is a non-200 answer from Loki. Body is the start of the response, for callers
// that may show it; callers facing members or volunteers should not.
type LokiStatusError struct {
	Status int
	Body   string
}

func (e *LokiStatusError) Error() string {
	return fmt.Sprintf("Loki returned status %d: %s", e.Status, e.Body)
}

// lokiMaxResponse bounds how much of a query_range answer is read.
const lokiMaxResponse = 64 << 20

// LokiQueryRange runs a log query against /loki/api/v1/query_range and returns its streams.
// start and end are Unix nanoseconds; direction is "forward" or "backward".
func LokiQueryRange(baseURL, query string, startNs, endNs int64, limit int, direction string, timeout time.Duration) ([]LokiStream, error) {
	params := url.Values{}
	params.Set("query", query)
	params.Set("start", strconv.FormatInt(startNs, 10))
	params.Set("end", strconv.FormatInt(endNs, 10))
	params.Set("limit", strconv.Itoa(limit))
	params.Set("direction", direction)

	client := &http.Client{Timeout: timeout}
	resp, err := client.Get(strings.TrimRight(baseURL, "/") + "/loki/api/v1/query_range?" + params.Encode())
	if err != nil {
		return nil, fmt.Errorf("HTTP request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
		return nil, &LokiStatusError{Status: resp.StatusCode, Body: string(body)}
	}

	var parsed struct {
		Status string `json:"status"`
		Data   struct {
			Result []LokiStream `json:"result"`
		} `json:"data"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, lokiMaxResponse)).Decode(&parsed); err != nil {
		return nil, fmt.Errorf("failed to parse Loki response: %w", err)
	}
	if parsed.Status != "success" {
		return nil, fmt.Errorf("Loki query failed with status: %s", parsed.Status)
	}
	return parsed.Data.Result, nil
}
