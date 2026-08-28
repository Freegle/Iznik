package test

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/roadblur"
	"github.com/stretchr/testify/assert"
)

// The stored label IS the reach record: its verdict decides membership, and
// where there is no verdict - label not stored yet, or the routing server
// unreachable - the member is NOT in reach. There is no grid fallback;
// routing is a dependency, by design.
func TestLabelVerdictsDecideMembership(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("labelstruth")
	userID, _ := CreateFullTestUser(t, prefix)
	msgID := CreateTestMessage(t, userID, CreateTestGroup(t, prefix+"_g"), "OFFER: labels truth item", 51.5, -0.1)

	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, outer_bound) VALUES (?, 51.5, -0.1, ST_Envelope(ST_GeomFromText("+
		"'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))', 3857)))",
		msgID)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", msgID)

	stub := func(verdict string) *httptest.Server {
		return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if r.URL.Path != "/v1/reach-eval" {
				w.WriteHeader(http.StatusNotFound)
				return
			}
			_ = json.NewEncoder(w).Encode(map[string]any{
				"results": []map[string]any{{"msgid": msgID, "verdict": verdict}},
			})
		}))
	}
	prevURL := os.Getenv("ROUTING_EVAL_URL")
	defer os.Setenv("ROUTING_EVAL_URL", prevURL)

	check := func() bool {
		m, err := rippling.ReachMembership(db, []uint64{msgID}, -0.1, 51.5)
		assert.NoError(t, err)
		return m[msgID].InReach
	}

	srv := stub("in")
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	roadblur.ResetRoutingBreaker()
	assert.True(t, check(), "an IN verdict admits")
	srv.Close()

	srv = stub("out")
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	roadblur.ResetRoutingBreaker()
	assert.False(t, check(), "an OUT verdict refuses")
	srv.Close()

	srv = stub("nolabels")
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	roadblur.ResetRoutingBreaker()
	assert.False(t, check(), "no stored label = not in reach; there is no grid to fall back to")
	srv.Close()

	roadblur.ResetRoutingBreaker()
	os.Setenv("ROUTING_EVAL_URL", "http://127.0.0.1:1")
	assert.False(t, check(), "routing unreachable = not in reach; routing is a dependency")
	roadblur.ResetRoutingBreaker()
}

// The discover arm: verdicts narrow the candidate list AND the response's
// discovered ids come back for the caller to union in - including when the
// candidate list is EMPTY (a member no grid covers can still be admitted by a
// stored label).
func TestLabelVerdictsWithDiscover(t *testing.T) {
	var lastBody map[string]any
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/reach-eval" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		_ = json.NewDecoder(r.Body).Decode(&lastBody)
		// Like the real endpoint: verdicts only for the asked candidates,
		// discoveries regardless. Msgid 9 is out but inside the post's
		// origin group's area (union-admitted); msgid 1 is a plain out that
		// ALSO appears in the discovered list (as it can when the candidate
		// list spans chunks) - the out verdict must win.
		results := []map[string]any{}
		if asked, _ := lastBody["msgids"].([]any); len(asked) > 0 {
			results = append(results,
				map[string]any{"msgid": 1, "verdict": "out"},
				map[string]any{"msgid": 9, "verdict": "out", "origin_area": true},
			)
		}
		_ = json.NewEncoder(w).Encode(map[string]any{
			"results":    results,
			"discovered": []map[string]any{{"msgid": 7, "verdict": "in"}, {"msgid": 1, "verdict": "in"}},
		})
	}))
	defer srv.Close()
	prevURL := os.Getenv("ROUTING_EVAL_URL")
	defer os.Setenv("ROUTING_EVAL_URL", prevURL)
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	roadblur.ResetRoutingBreaker()

	verdicts, discovered := rippling.LabelVerdictsWithDiscover(51.5, -0.1, []uint64{1, 2, 9})
	assert.Equal(t, rippling.LabelVerdictOut, verdicts[1])
	_, has9 := verdicts[9]
	assert.False(t, has9, "out+origin_area is NO verdict - the cell grid holds the union and decides")
	assert.Equal(t, []uint64{7}, discovered, "a discovered id the verdicts narrowed away must not come back")
	assert.Equal(t, true, lastBody["discover"], "the request must ask the server to discover")

	// Empty candidate list: the call still happens and still discovers.
	lastBody = nil
	verdicts, discovered = rippling.LabelVerdictsWithDiscover(51.5, -0.1, nil)
	assert.Empty(t, verdicts)
	assert.Equal(t, []uint64{7, 1}, discovered, "no verdicts were asked, so nothing narrows the discoveries")
	assert.NotNil(t, lastBody, "an empty candidate list must still ask the server")
	roadblur.ResetRoutingBreaker()
}

// A 4xx from reach-eval (a routing server that predates the endpoint, a
// rejected body) must NOT trip the routing breaker - it is shared with blur
// and drive-time display, so tripping it on one member's request would
// degrade those for everyone.
func TestLabelVerdicts4xxDoesNotTripBreaker(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNotFound)
	}))
	defer srv.Close()
	prevURL := os.Getenv("ROUTING_EVAL_URL")
	defer os.Setenv("ROUTING_EVAL_URL", prevURL)
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	roadblur.ResetRoutingBreaker()

	verdicts := rippling.LabelVerdicts(51.5, -0.1, []uint64{1})
	assert.Empty(t, verdicts)
	assert.True(t, roadblur.RoutingHealthy(), "a 404 must not open the shared breaker")
	roadblur.ResetRoutingBreaker()
}

// The degraded-path rescue both the feed's probe filter and search's
// degraded arm share: rows whose grid cannot answer get one batched label
// evaluation, and only definite IN survives. Routing down keeps nothing -
// only the spatial index AND routing failing together hides retired posts.
func TestRescueUndecided(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{
			"results": []map[string]any{
				{"msgid": 1, "verdict": "in"},
				{"msgid": 2, "verdict": "out"},
				{"msgid": 3, "verdict": "nolabels"},
			},
		})
	}))
	defer srv.Close()
	prevURL := os.Getenv("ROUTING_EVAL_URL")
	defer os.Setenv("ROUTING_EVAL_URL", prevURL)
	os.Setenv("ROUTING_EVAL_URL", srv.URL)
	roadblur.ResetRoutingBreaker()

	got := rippling.RescueUndecided(51.5, -0.1, []uint64{1, 2, 3})
	assert.Equal(t, []uint64{1}, got)

	os.Setenv("ROUTING_EVAL_URL", "http://127.0.0.1:1")
	roadblur.ResetRoutingBreaker()
	assert.Empty(t, rippling.RescueUndecided(51.5, -0.1, []uint64{1, 2, 3}))
	roadblur.ResetRoutingBreaker()
}

// The feed's id-list narrowing: OUT drops, in/nolabels keep, nil no-ops.
func TestDropLabelOut(t *testing.T) {
	ids := []uint64{1, 2, 3}
	assert.Equal(t, []uint64{1, 2, 3}, rippling.DropLabelOut(append([]uint64(nil), ids...), nil))
	got := rippling.DropLabelOut(append([]uint64(nil), ids...), map[uint64]string{
		1: rippling.LabelVerdictIn, 2: rippling.LabelVerdictOut,
	})
	assert.Equal(t, []uint64{1, 3}, got)
}
