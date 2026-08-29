package isochrone

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/stretchr/testify/assert"
)

// The one transform BOTH the feed's containment list and the badge count go
// through: label-out ids drop, discovered ids append, everything else - and
// everything when routing is down - passes through untouched. Feed and badge
// sharing this function is what keeps them from disagreeing.
func TestLabelNarrowAndDiscover(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/reach-eval" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		_ = json.NewEncoder(w).Encode(map[string]any{
			"results": []map[string]any{
				{"msgid": 1, "verdict": "in"},
				{"msgid": 2, "verdict": "out"},
				{"msgid": 3, "verdict": "nolabels"},
				{"msgid": 4, "verdict": "out", "origin_area": true},
			},
			"discovered": []map[string]any{{"msgid": 7, "verdict": "in"}},
		})
	}))
	defer srv.Close()
	prevURL := os.Getenv("ROUTING_EVAL_URL")
	defer os.Setenv("ROUTING_EVAL_URL", prevURL)
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	roadblur.ResetRoutingBreaker()

	got := labelNarrowAndDiscover(51.5, -0.1, []int64{1, 2, 3, 4})
	// 2 drops (label-out); 4 stays (out but inside the origin group's
	// union-admitted area); 7 appends (grid missed it, label admits).
	assert.Equal(t, []int64{1, 3, 4, 7}, got)

	// Routing down: the grid list passes through untouched.
	os.Setenv("ROUTING_EVAL_URL", "http://127.0.0.1:1")
	roadblur.ResetRoutingBreaker()
	got = labelNarrowAndDiscover(51.5, -0.1, []int64{1, 2, 3})
	assert.Equal(t, []int64{1, 2, 3}, got)
	roadblur.ResetRoutingBreaker()
}
