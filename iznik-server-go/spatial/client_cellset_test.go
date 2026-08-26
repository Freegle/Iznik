package spatial

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// The cell-set calls that client_pure_test.go does not reach: VectorizeCells,
// GroupsIntersectingCells, ReachContaining and ReachOverflowContaining.
// (RasterizeWKT's cell-set validation is already covered there; this file
// deliberately does not duplicate it, and adds only its transport failures.)
//
// These were the thinnest-tested code in the raster-storage change (#1406):
// spatial/client.go merged at 52.3% statement coverage, and the uncovered
// blocks were almost entirely these functions' guards and error branches.
// WHICH code that is matters more than the percentage. While the legacy
// polygon columns exist, every one of these calls has a SQL fallback behind
// it; the Stage 3 drop removes the fallback and makes them the only path that
// answers "is this member inside this post's reach". An untested error branch
// there is an untested admission decision.
//
// The recurring property asserted below is that **every failure is an error,
// never an empty result**. Callers read an empty id list as "no reach covers
// this member", so a transport failure returned as success does not degrade -
// it silently excludes people from posts that really do reach them, with
// nothing in the logs.
//
// Real httptest servers throughout, in the style of TestUpsertLocationPostsItem:
// what is uncovered is the parsing and guarding of a RESPONSE, so a stubbed
// transport would prove nothing about it.

// validCellSet is a minimal well-formed cell set: the "CCS1" magic
// little-endian plus zeroed header fields. Only its bytes matter here - these
// calls pass it through to the server rather than decoding it.
var validCellSet = []byte{0x43, 0x43, 0x53, 0x31, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0}

// serveBytes stands up a server returning fixed bytes with a fixed status and
// points the client at it, returning where the client asked.
func serveBytes(t *testing.T, status int, body []byte) *string {
	t.Helper()
	var gotPath string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath = r.URL.Path
		w.WriteHeader(status)
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)
	t.Setenv("SPATIAL_KNN_URL", srv.URL)

	return &gotPath
}

// deadServer points the client at an address with nothing listening.
func deadServer(t *testing.T) {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {}))
	url := srv.URL
	srv.Close()
	t.Setenv("SPATIAL_KNN_URL", url)
}

func TestRasterizeWKTErrorsOnNon200(t *testing.T) {
	serveBytes(t, http.StatusBadRequest, nil)

	if _, err := RasterizeWKT("NOT WKT"); err == nil {
		t.Fatal("a 400 was treated as success")
	}
}

// An unreachable spatial server must not look like a reach of nothing.
func TestRasterizeWKTErrorsWhenTheServerIsUnreachable(t *testing.T) {
	deadServer(t)

	got, err := RasterizeWKT("POLYGON((0 0,1 0,1 1,0 1,0 0))")
	if err == nil {
		t.Fatal("a dead spatial server was treated as success")
	}
	if got != nil {
		t.Errorf("returned %d bytes alongside the error; callers must have nothing to store", len(got))
	}
}

func TestVectorizeCellsReturnsWKTAndGeoJSON(t *testing.T) {
	var gotPath, gotQuery string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotQuery = r.URL.Path, r.URL.RawQuery
		_, _ = w.Write([]byte(`{"wkt":"POLYGON((0 0,1 0,1 1,0 1,0 0))","geojson":{"type":"Polygon"}}`))
	}))
	defer srv.Close()
	t.Setenv("SPATIAL_KNN_URL", srv.URL)

	wkt, geojson, err := VectorizeCells(validCellSet, 0)
	if err != nil {
		t.Fatalf("VectorizeCells returned %v", err)
	}
	if !strings.HasPrefix(wkt, "POLYGON") {
		t.Errorf("wkt = %q, want a polygon", wkt)
	}
	// The GeoJSON is passed through as raw JSON rather than re-encoded, so the
	// thing to assert is that what comes out is still valid JSON.
	if !json.Valid([]byte(geojson)) {
		t.Errorf("geojson = %q, want valid JSON", geojson)
	}
	if gotPath != "/v1/reach/vectorize" {
		t.Errorf("posted to %q, want /v1/reach/vectorize", gotPath)
	}
	// Tolerance 0 means "keep the exact lattice outline" and must NOT be sent:
	// ?tolerance=0 is an explicit request for zero simplification, which is a
	// different thing to ask a server that may default otherwise.
	if gotQuery != "" {
		t.Errorf("query = %q, want no tolerance parameter at tolerance 0", gotQuery)
	}
}

func TestVectorizeCellsPassesAPositiveTolerance(t *testing.T) {
	var gotQuery string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotQuery = r.URL.RawQuery
		_, _ = w.Write([]byte(`{"wkt":"POLYGON((0 0,1 0,1 1,0 1,0 0))","geojson":{}}`))
	}))
	defer srv.Close()
	t.Setenv("SPATIAL_KNN_URL", srv.URL)

	if _, _, err := VectorizeCells(validCellSet, 0.001); err != nil {
		t.Fatalf("VectorizeCells returned %v", err)
	}
	if !strings.Contains(gotQuery, "tolerance=0.001") {
		t.Errorf("query = %q, want tolerance=0.001", gotQuery)
	}
}

// An empty boundary is an error, not an empty polygon: a caller that stored it
// would leave the post with a reach that has no outline at all.
func TestVectorizeCellsRejectsAnEmptyBoundary(t *testing.T) {
	serveBytes(t, http.StatusOK, []byte(`{"wkt":"","geojson":{}}`))

	_, _, err := VectorizeCells(validCellSet, 0)
	if err == nil {
		t.Fatal("an empty wkt was returned as success")
	}
	if !strings.Contains(err.Error(), "empty boundary") {
		t.Errorf("error = %q, want it to name the empty boundary", err)
	}
}

func TestVectorizeCellsFailuresAreErrors(t *testing.T) {
	cases := []struct {
		name   string
		status int
		body   string
	}{
		{"server error", http.StatusInternalServerError, ""},
		{"endpoint missing on an older server", http.StatusNotFound, ""},
		{"unparseable body", http.StatusOK, "not json"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			serveBytes(t, tc.status, []byte(tc.body))

			wkt, geojson, err := VectorizeCells(validCellSet, 0)
			if err == nil {
				t.Fatal("failure was reported as success")
			}
			if wkt != "" || geojson != "" {
				t.Errorf("got wkt=%q geojson=%q alongside the error, want both empty", wkt, geojson)
			}
		})
	}
}

func TestGroupsIntersectingCellsParsesTheRelations(t *testing.T) {
	path := serveBytes(t, http.StatusOK, []byte(`{"groups":[{"id":7,"within":true},{"id":9,"within":false}]}`))

	got, err := GroupsIntersectingCells(validCellSet)
	if err != nil {
		t.Fatalf("GroupsIntersectingCells returned %v", err)
	}
	if len(got) != 2 {
		t.Fatalf("got %d relations, want 2", len(got))
	}
	// `within` decides whether a reach is clipped away entirely rather than
	// trimmed, so getting the flag onto the right group matters.
	if got[0].ID != 7 || !got[0].Within {
		t.Errorf("first relation = %+v, want id 7 within=true", got[0])
	}
	if got[1].ID != 9 || got[1].Within {
		t.Errorf("second relation = %+v, want id 9 within=false", got[1])
	}
	if *path != "/v1/groups/intersecting" {
		t.Errorf("posted to %q, want /v1/groups/intersecting", *path)
	}
}

// 503 is distinguished from other non-200s because the caller's correct
// response differs: the dataset is still building, so wait rather than treat
// "no groups" as the answer.
func TestGroupsIntersectingCellsReportsDatasetNotReady(t *testing.T) {
	serveBytes(t, http.StatusServiceUnavailable, nil)

	got, err := GroupsIntersectingCells(validCellSet)
	if err == nil {
		t.Fatal("a 503 was treated as success, which reads as 'no group overlaps'")
	}
	if !strings.Contains(err.Error(), "not ready") {
		t.Errorf("error = %q, want it to say the dataset is not ready", err)
	}
	if got != nil {
		t.Errorf("got %v alongside the error, want nil", got)
	}
}

func TestGroupsIntersectingCellsFailuresAreErrors(t *testing.T) {
	for _, tc := range []struct {
		name   string
		status int
		body   string
	}{
		{"server error", http.StatusInternalServerError, ""},
		{"unparseable body", http.StatusOK, "not json"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			serveBytes(t, tc.status, []byte(tc.body))

			if _, err := GroupsIntersectingCells(validCellSet); err == nil {
				t.Fatal("failure was reported as success")
			}
		})
	}
}

func TestReachContainingSplitsInFromPartial(t *testing.T) {
	var gotPath, gotQuery string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotQuery = r.URL.Path, r.URL.RawQuery
		_, _ = w.Write([]byte(`{"in":[1,2],"partial":[3]}`))
	}))
	defer srv.Close()
	t.Setenv("SPATIAL_KNN_URL", srv.URL)

	in, partial, err := ReachContaining(-0.09, 51.51)
	if err != nil {
		t.Fatalf("ReachContaining returned %v", err)
	}
	// `in` is admitted and `partial` is not, so the two lists must not be
	// merged or swapped: a partial promoted to `in` admits someone the reach
	// has not been shown to cover.
	if len(in) != 2 || in[0] != 1 || in[1] != 2 {
		t.Errorf("in = %v, want [1 2]", in)
	}
	if len(partial) != 1 || partial[0] != 3 {
		t.Errorf("partial = %v, want [3]", partial)
	}
	if gotPath != "/v1/reach/containing" {
		t.Errorf("asked %q, want /v1/reach/containing", gotPath)
	}
	// Both coordinates must arrive; dropping one is a silent wrong answer
	// rather than an error.
	if !strings.Contains(gotQuery, "lng=-0.09") || !strings.Contains(gotQuery, "lat=51.51") {
		t.Errorf("query = %q, want both lng and lat", gotQuery)
	}
}

// A point no reach covers is a legitimate empty answer, and must be
// distinguishable from a failure - hence err == nil here and err != nil below.
func TestReachContainingAcceptsAGenuinelyEmptyAnswer(t *testing.T) {
	serveBytes(t, http.StatusOK, []byte(`{"in":[],"partial":[]}`))

	in, partial, err := ReachContaining(-0.09, 51.51)
	if err != nil {
		t.Fatalf("an empty-but-valid answer was reported as an error: %v", err)
	}
	if len(in) != 0 || len(partial) != 0 {
		t.Errorf("got in=%v partial=%v, want both empty", in, partial)
	}
}

func TestReachContainingFailuresAreErrorsNotEmptyResults(t *testing.T) {
	for _, tc := range []struct {
		name   string
		status int
		body   string
	}{
		{"dataset still building", http.StatusServiceUnavailable, ""},
		{"server error", http.StatusInternalServerError, ""},
		{"unparseable body", http.StatusOK, "not json"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			serveBytes(t, tc.status, []byte(tc.body))

			in, partial, err := ReachContaining(-0.09, 51.51)
			if err == nil {
				t.Fatal("failure reported as success, which a caller reads as 'nobody is covered'")
			}
			if in != nil || partial != nil {
				t.Errorf("got in=%v partial=%v alongside the error, want both nil", in, partial)
			}
		})
	}
}

func TestReachContainingErrorsWhenTheServerIsUnreachable(t *testing.T) {
	deadServer(t)

	if _, _, err := ReachContaining(-0.09, 51.51); err == nil {
		t.Fatal("a dead spatial server was treated as an empty reach")
	}
}

// No lanes means there is nothing to ask, and the client must answer without a
// round trip: the feed calls this for every viewer, and most are in no overflow
// lane at all.
func TestReachOverflowContainingWithNoLanesDoesNotCallTheServer(t *testing.T) {
	called := false
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		called = true
		_, _ = io.WriteString(w, `{"in":[],"partial":[]}`)
	}))
	defer srv.Close()
	t.Setenv("SPATIAL_KNN_URL", srv.URL)

	in, partial, err := ReachOverflowContaining(-0.09, 51.51, nil)
	if err != nil {
		t.Fatalf("ReachOverflowContaining returned %v", err)
	}
	if called {
		t.Error("called the spatial server for a viewer in no lanes")
	}
	if len(in) != 0 || len(partial) != 0 {
		t.Errorf("got in=%v partial=%v, want both empty", in, partial)
	}
}

func TestReachOverflowContainingSendsTheLanes(t *testing.T) {
	var gotPath, gotQuery string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotPath, gotQuery = r.URL.Path, r.URL.RawQuery
		_, _ = w.Write([]byte(`{"in":[11],"partial":[12]}`))
	}))
	defer srv.Close()
	t.Setenv("SPATIAL_KNN_URL", srv.URL)

	in, partial, err := ReachOverflowContaining(-0.09, 51.51, []string{"rural", "fairness"})
	if err != nil {
		t.Fatalf("ReachOverflowContaining returned %v", err)
	}
	if gotPath != "/v1/reachoverflow/containing" {
		t.Errorf("asked %q, want /v1/reachoverflow/containing", gotPath)
	}
	// The lanes are named on the wire and filtered server-side, so that one
	// authority decides what a lane admits. Both must be sent, comma-joined.
	if !strings.Contains(gotQuery, "lanes=rural%2Cfairness") {
		t.Errorf("query = %q, want both lanes comma-joined", gotQuery)
	}
	if len(in) != 1 || in[0] != 11 {
		t.Errorf("in = %v, want [11]", in)
	}
	if len(partial) != 1 || partial[0] != 12 {
		t.Errorf("partial = %v, want [12]", partial)
	}
}

func TestReachOverflowContainingFailuresAreErrorsNotEmptyResults(t *testing.T) {
	for _, tc := range []struct {
		name   string
		status int
		body   string
	}{
		{"dataset still building", http.StatusServiceUnavailable, ""},
		{"server error", http.StatusInternalServerError, ""},
		{"unparseable body", http.StatusOK, "not json"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			serveBytes(t, tc.status, []byte(tc.body))

			in, partial, err := ReachOverflowContaining(-0.09, 51.51, []string{"rural"})
			if err == nil {
				t.Fatal("failure reported as success, which reads as 'no ring admits this member'")
			}
			if in != nil || partial != nil {
				t.Errorf("got in=%v partial=%v alongside the error, want both nil", in, partial)
			}
		})
	}
}
