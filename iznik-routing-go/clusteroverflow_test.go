package main

import (
	"encoding/json"
	"fmt"
	"io"
	"math"
	"net/http"
	"net/http/httptest"
	"testing"
)

// The cluster-anchor overflow lane. Tests split into three layers, cheapest first:
//
//  1. Pure unit tests of the grid-scoring, greedy-admission and sector-angle helpers, with no
//     graph at all - these pin down the algorithm precisely and run instantly.
//  2. A graph-based test of clusterOverflowWedges itself, using the shared 50x50 test grid
//     (dijkstra_test.go) with a synthetic shell-member blob placed at a real, reachable node -
//     proves the wedge is actually traced and stays within bounds.
//  3. Endpoint-level gating tests (cluster_anchor required, mutually exclusive with a bound
//     cap, gated on the pool being below cluster_floor) reusing ruraloverflow_test.go's shared
//     Bristol fixture and helpers, plus one full end-to-end firing test on a small purpose-built
//     "sparse origin -> fast spoke -> town" fixture: the shared 50x50 grid is only ~3x5km, too
//     small to host a committed reach and a separate shell cluster with room to spare (see
//     buildClusterFixture).

// --- 1. Pure unit tests -----------------------------------------------------------------

func TestWithinSector_ExactCentreAndJustInsideWidth(t *testing.T) {
	center := math.Pi / 2 // east
	if !withinSector(center, center, 0.01) {
		t.Error("angle at the sector centre must be within it")
	}
	if !withinSector(center+0.09, center, 0.1) {
		t.Error("angle just inside the half-width must be within the sector")
	}
	if withinSector(center+0.11, center, 0.1) {
		t.Error("angle just outside the half-width must not be within the sector")
	}
}

func TestWithinSector_WrapsAcrossNorth(t *testing.T) {
	// center at 0 (north): angles near 2π are geometrically close to it.
	if !withinSector(2*math.Pi-0.05, 0, 0.1) {
		t.Error("angle just below 2π must wrap to be within a sector centred on 0")
	}
	if !withinSector(0.05, 0, 0.1) {
		t.Error("angle just above 0 must be within a sector centred on 0")
	}
	if withinSector(math.Pi, 0, 0.1) {
		t.Error("the opposite bearing must never be within a narrow sector centred on 0")
	}
}

func TestScoreClusterCells_FindsDenseBlobAboveThreshold(t *testing.T) {
	latCellDeg := clusterCellKm / 111.0
	lngCellDeg := clusterCellKm / 111.0 // cosLat=1 simplification, fine for a unit test

	var members []clusterMember
	// 30 members in one cell: comfortably above a cellK of 20.
	for i := 0; i < 30; i++ {
		members = append(members, clusterMember{Lat: 51.0, Lng: -2.0, Secs: 600})
	}
	cands := scoreClusterCells(members, latCellDeg, lngCellDeg, 20)
	if len(cands) == 0 {
		t.Fatal("expected at least one qualifying cell")
	}
	if cands[0].score < 30 {
		t.Errorf("expected the blob's score to be >=30, got %d", cands[0].score)
	}
}

func TestScoreClusterCells_BelowThresholdFindsNothing(t *testing.T) {
	latCellDeg := clusterCellKm / 111.0
	lngCellDeg := clusterCellKm / 111.0

	var members []clusterMember
	for i := 0; i < 5; i++ {
		members = append(members, clusterMember{Lat: 51.0, Lng: -2.0, Secs: 600})
	}
	cands := scoreClusterCells(members, latCellDeg, lngCellDeg, 20)
	if len(cands) != 0 {
		t.Errorf("5 members must not clear a cellK of 20, got %d candidate cells", len(cands))
	}
}

func TestScoreClusterCells_TieBreaksOnNearerDriveTime(t *testing.T) {
	latCellDeg := clusterCellKm / 111.0
	lngCellDeg := clusterCellKm / 111.0

	// Two equally-scored, well-separated (>3 cells apart) blobs; the nearer one (smaller Secs)
	// must sort first.
	var members []clusterMember
	for i := 0; i < 10; i++ {
		members = append(members, clusterMember{Lat: 51.0, Lng: -2.0, Secs: 1200}) // far
		members = append(members, clusterMember{Lat: 51.1, Lng: -2.0, Secs: 600})  // near
	}
	cands := scoreClusterCells(members, latCellDeg, lngCellDeg, 5)
	if len(cands) < 2 {
		t.Fatalf("expected 2 candidate cells, got %d", len(cands))
	}
	if cands[0].nearestSecs != 600 {
		t.Errorf("expected the nearer blob (600s) ranked first on the tie-break, got nearestSecs=%v first", cands[0].nearestSecs)
	}
}

func TestAdmitClusterCandidates_SuppressesNearbyDuplicateTown(t *testing.T) {
	// Two candidate cells 1 cell apart (well within clusterSuppressKm=3): the second must be
	// suppressed by the first (a single town's spread of dense cells must not spend two wedges
	// on itself).
	cands := []clusterCandidate{
		{row: 0, col: 0, score: 100, nearestSecs: 500},
		{row: 0, col: 1, score: 90, nearestSecs: 500},
	}
	admitted := admitClusterCandidates(cands, 3, 100000, 0)
	if len(admitted) != 1 {
		t.Errorf("expected 1 admitted cluster (the second suppressed), got %d", len(admitted))
	}
}

func TestAdmitClusterCandidates_DistinctTownsBothAdmitted(t *testing.T) {
	// Two candidates far apart (well beyond clusterSuppressKm): both must be admitted.
	cands := []clusterCandidate{
		{row: 0, col: 0, score: 100, nearestSecs: 500},
		{row: 100, col: 100, score: 90, nearestSecs: 500},
	}
	admitted := admitClusterCandidates(cands, 3, 100000, 0)
	if len(admitted) != 2 {
		t.Errorf("expected both distant clusters admitted, got %d", len(admitted))
	}
}

// Spares, deliberately: the cap and the floor are enforced by the build loop, because a
// candidate only earns its place once its wedge has actually been traced. Keeping just
// maxWedges here meant one untraceable town silently zeroed the lane.
func TestAdmitClusterCandidates_KeepsSparesBeyondMaxWedges(t *testing.T) {
	var cands []clusterCandidate
	for i := 0; i < 20; i++ {
		cands = append(cands, clusterCandidate{row: i * 100, col: 0, score: 100 - i, nearestSecs: 500})
	}
	admitted := admitClusterCandidates(cands, 3, 100000, 0)
	want := 3 * clusterCandidateFallbackFactor
	if len(admitted) != want {
		t.Errorf("expected %d ranked candidates (maxWedges plus spares), got %d", want, len(admitted))
	}
	// Still best-first, and still suppression-filtered.
	if admitted[0].score != 100 {
		t.Errorf("expected the best-scoring candidate first, got score %d", admitted[0].score)
	}
}

// The floor no longer ends admission - it ends WEDGE BUILDING. This proves the selection
// function itself has stopped short-circuiting on score alone.
func TestAdmitClusterCandidates_FloorDoesNotStopSelection(t *testing.T) {
	cands := []clusterCandidate{
		{row: 0, col: 0, score: 60, nearestSecs: 500},
		{row: 100, col: 0, score: 60, nearestSecs: 500},
		{row: 200, col: 0, score: 60, nearestSecs: 500},
	}
	// poolAtCeiling=50, floor=100: the first candidate alone would tip 50+60 >= 100, but the
	// spares must survive so the build loop can fall through if that wedge traces nothing.
	admitted := admitClusterCandidates(cands, 3, 100, 50)
	if len(admitted) != 3 {
		t.Errorf("expected all 3 distinct candidates kept for the build loop, got %d", len(admitted))
	}
}

// Ties must resolve the same way every run. Candidates are gathered by ranging over a map
// (randomised) and sorted with a non-stable sort, so without a total order a scheduled
// recompute on identical stored input could swap which town a post's wedge points at.
func TestScoreClusterCells_TieBreakIsDeterministic(t *testing.T) {
	// Two cells of identical score and identical nearest drive time, far enough apart to be
	// distinct clusters.
	members := []clusterMember{}
	for i := 0; i < 30; i++ {
		members = append(members, clusterMember{Lat: 51.5, Lng: -2.5, Secs: 500})
		members = append(members, clusterMember{Lat: 52.5, Lng: -2.5, Secs: 500})
	}
	latCellDeg := clusterCellKm / 111.0
	lngCellDeg := clusterCellKm / (111.0 * math.Cos(51.5*math.Pi/180))

	first := scoreClusterCells(members, latCellDeg, lngCellDeg, 10)
	for i := 0; i < 25; i++ {
		again := scoreClusterCells(members, latCellDeg, lngCellDeg, 10)
		if len(again) != len(first) {
			t.Fatalf("candidate count varied between runs: %d vs %d", len(again), len(first))
		}
		for j := range first {
			if again[j].row != first[j].row || again[j].col != first[j].col {
				t.Fatalf("tie order varied between runs at %d: (%d,%d) vs (%d,%d)",
					j, again[j].row, again[j].col, first[j].row, first[j].col)
			}
		}
	}
}

// --- 2. Graph-based test of clusterOverflowWedges ---------------------------------------

// clusterTestOriginLat/Lng match the shared 50x50 test grid's query point (dijkstra_test.go).
const clusterTestOriginLat = 51.4545
const clusterTestOriginLng = -2.5879

// clusterTestEastNodeID is a real, reachable node ID on the shared 50x50 grid (row24,col25 is
// the origin - see graph_test.go), picked well inside the grid so it is always reachable at a
// generous drive-time ceiling.
const clusterTestEastNodeID = NodeID(24*50 + 39 + 1) // ~1km east of the origin

func TestClusterOverflowWedges_FiresOnRealClusterAndExtendsBeyondCommittedReach(t *testing.T) {
	g := getTestGraph(t)

	// Probe real drive-times on the shared grid rather than assuming a road speed - the
	// calibrated class factors are not something this test should have to know about.
	probe := Isochrone(g, clusterTestOriginLat, clusterTestOriginLng, 20*60, Drive)
	eastSecs, ok := probe.ReachedNodes[clusterTestEastNodeID]
	if !ok {
		t.Fatal("fixture assumption broken: east target node not reachable on the shared test grid")
	}

	committedSecs := eastSecs * 0.4
	clusterMaxSecs := eastSecs * 1.5

	iso2 := Isochrone(g, clusterTestOriginLat, clusterTestOriginLng, clusterMaxSecs, Drive)
	eastNode := g.Nodes[clusterTestEastNodeID]

	var shellMembers []clusterMember
	for i := 0; i < 25; i++ {
		shellMembers = append(shellMembers, clusterMember{
			Lat: float64(eastNode.Lat), Lng: float64(eastNode.Lng), Secs: eastSecs,
		})
	}

	wedges := clusterOverflowWedges(g, iso2, clusterTestOriginLat, clusterTestOriginLng, Drive,
		shellMembers, committedSecs, clusterMaxSecs, 10, 3, 1000, 0)
	if wedges == nil {
		t.Fatal("expected a wedge for a real, dense, in-bounds cluster")
	}
	w1, ok := wedges["w1"]
	if !ok {
		t.Fatalf("expected key \"w1\", got keys %v", clusterKeysOf(wedges))
	}
	ring := w1.Geometry.Coordinates[0]
	if len(ring) < 4 {
		t.Fatalf("wedge ring has %d points, expected >=4", len(ring))
	}
	if ring[0] != ring[len(ring)-1] {
		t.Error("wedge ring is not closed")
	}

	// The wedge must extend EAST (the cluster's bearing): every vertex's longitude should be
	// no less than the origin's, and at least one must be meaningfully east of it.
	maxLng := -math.MaxFloat64
	for _, p := range ring {
		if p[0] > maxLng {
			maxLng = p[0]
		}
		if p[0] < clusterTestOriginLng-0.001 {
			t.Errorf("wedge vertex at lng=%v is west of the origin - the sector filter let a wrong-bearing node through", p[0])
		}
	}
	if maxLng <= clusterTestOriginLng {
		t.Error("wedge does not extend east of the origin at all")
	}

	// It must reach beyond the committed reach: not every vertex can lie inside the committed
	// (0..committedSecs) polygon, or the lane would be rescuing no one - mirrors
	// TestRuralOverflow_SparseRingExtendsBeyondTheCappedReach's sanity check.
	committedFiltered := make(map[NodeID]float32)
	for nid, tt := range iso2.ReachedNodes {
		if tt <= committedSecs {
			committedFiltered[nid] = tt
		}
	}
	res := NetworkResolution(g, iso2.ReachedNodes, Drive)
	committedPoly := IsochronePolygon(g, committedFiltered, res)
	committedRing := committedPoly.Geometry.Coordinates[0]
	allInsideCommitted := true
	for _, p := range ring {
		if !pointInRing(p[0], p[1], committedRing) {
			allInsideCommitted = false
			break
		}
	}
	if allInsideCommitted {
		t.Error("every wedge vertex fell inside the committed reach - the wedge rescues no one")
	}
}

func TestClusterOverflowWedges_NilWhenPoolAlreadyAtFloor(t *testing.T) {
	g := getTestGraph(t)
	iso2 := Isochrone(g, clusterTestOriginLat, clusterTestOriginLng, 10*60, Drive)
	members := []clusterMember{{Lat: clusterTestOriginLat, Lng: clusterTestOriginLng, Secs: 100}}

	if got := clusterOverflowWedges(g, iso2, clusterTestOriginLat, clusterTestOriginLng, Drive,
		members, 60, 600, 1, 3, 50, 50); got != nil {
		t.Error("expected nil when poolAtCeiling already equals floor")
	}
	if got := clusterOverflowWedges(g, iso2, clusterTestOriginLat, clusterTestOriginLng, Drive,
		members, 60, 600, 1, 3, 50, 60); got != nil {
		t.Error("expected nil when poolAtCeiling already exceeds floor")
	}
}

func TestClusterOverflowWedges_NilWithNoShellMembersOrNoWedges(t *testing.T) {
	g := getTestGraph(t)
	iso2 := Isochrone(g, clusterTestOriginLat, clusterTestOriginLng, 10*60, Drive)
	members := []clusterMember{{Lat: clusterTestOriginLat, Lng: clusterTestOriginLng, Secs: 100}}

	if got := clusterOverflowWedges(g, iso2, clusterTestOriginLat, clusterTestOriginLng, Drive,
		nil, 60, 600, 1, 3, 1000, 0); got != nil {
		t.Error("expected nil with no shell members")
	}
	if got := clusterOverflowWedges(g, iso2, clusterTestOriginLat, clusterTestOriginLng, Drive,
		members, 60, 600, 1, 0, 1000, 0); got != nil {
		t.Error("expected nil with maxWedges<=0")
	}
}

func clusterKeysOf(m map[string]*GeoJSONPolygon) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}

// --- 3. Endpoint-level tests --------------------------------------------------------------

// stubSpatialFixed returns exactly the given points on every within_coords call, ignoring the
// request body entirely - both the committed-reach and the wider shell within_coords calls hit
// the same stub, and each is filtered independently against its own isochrone's reached-node
// set (see fetchClusterOverflow), so returning the full fixed list both times is correct.
func stubSpatialFixed(t *testing.T, points [][2]float64) string {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		results := make([]map[string]interface{}, 0, len(points))
		for _, p := range points {
			results = append(results, map[string]interface{}{
				"extra": map[string]interface{}{"lat": p[0], "lng": p[1]},
			})
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]interface{}{"results": results})
	}))
	t.Cleanup(srv.Close)
	return srv.URL
}

// getScheduleOn is getSchedule (ruraloverflow_test.go) parameterised on the graph, for tests
// that need a purpose-built fixture rather than the shared Bristol grid.
func getScheduleOn(t *testing.T, g *Graph, spatialURL, query string) map[string]interface{} {
	t.Helper()
	groupsDBMu.RLock()
	prevDB := groupsDB
	groupsDBMu.RUnlock()
	t.Cleanup(func() {
		groupsDBMu.Lock()
		groupsDB = prevDB
		groupsDBMu.Unlock()
	})

	app := newApp(g, spatialURL, false)
	req := httptest.NewRequest(http.MethodGet, "/v1/ripple-schedule?"+query, nil)
	resp, err := app.Test(req, 120000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 200 {
		t.Fatalf("expected 200, got %d", resp.StatusCode)
	}
	raw, _ := io.ReadAll(resp.Body)
	var body map[string]interface{}
	if err := json.Unmarshal(raw, &body); err != nil {
		t.Fatalf("decode: %v (body %.300s)", err, raw)
	}
	return body
}

// The lane must never fire without the explicit opt-in, same as rural_access/fairness_weight.
func TestClusterOverflow_AbsentWithoutFlag(t *testing.T) {
	url := stubSpatial(t, clusterTestOriginLat, clusterTestOriginLng, 400, 0.08)
	body := getSchedule(t, url, overflowOrigin+"&cluster_floor=100000")
	if _, present := body["overflow_cluster"]; present {
		t.Error("overflow_cluster returned without cluster_anchor=1")
	}
}

// Cluster is on the UNCAPPED side, same as fairness: a bound audience cap means the rural lane
// applies instead, never cluster.
func TestClusterOverflow_AbsentWhenCapBound(t *testing.T) {
	url := stubSpatial(t, clusterTestOriginLat, clusterTestOriginLng, 400, 0.08)
	body := getSchedule(t, url, overflowOrigin+"&target_users=50&cluster_anchor=1&cluster_floor=100000")
	if _, present := body["overflow_cluster"]; present {
		t.Error("overflow_cluster returned for a reach the cap bound - cluster is uncapped-side only")
	}
}

// The lane exists to top up a THIN pool; a pool already at or past cluster_floor has nothing
// to top up, so the second Isochrone and spatial round trip must never be paid for.
func TestClusterOverflow_AbsentWhenPoolAlreadyAtFloor(t *testing.T) {
	url := stubSpatial(t, clusterTestOriginLat, clusterTestOriginLng, 400, 0.08)
	body := getSchedule(t, url, overflowOrigin+"&cluster_anchor=1&cluster_floor=1")
	total, _ := body["total_freeglers"].(float64)
	if total < 1 {
		t.Fatalf("test assumption broken: expected a non-trivial pool, got total_freeglers=%v", total)
	}
	if _, present := body["overflow_cluster"]; present {
		t.Error("overflow_cluster returned even though the pool already met cluster_floor=1")
	}
}

// Both flags off must leave the response exactly as it is today.
func TestClusterOverflow_ResponseUnchangedWhenOff(t *testing.T) {
	url := stubSpatial(t, clusterTestOriginLat, clusterTestOriginLng, 400, 0.08)
	before := getSchedule(t, url, overflowOrigin)
	after := getSchedule(t, url, overflowOrigin+"&cluster_anchor=0")

	a, _ := json.Marshal(before)
	b, _ := json.Marshal(after)
	if string(a) != string(b) {
		t.Errorf("cluster_anchor=0 changed the response:\n%s\nvs\n%s", a, b)
	}
}

// buildClusterFixture builds the smallest graph that can express the shape the cluster-anchor
// lane exists for (see clusteroverflow.go): a sparse origin with only a handful of nearby
// nodes, joined by one long fast (trunk) spoke to a small dense residential grid ("the town")
// straddling the middle of the spoke. The shared 50x50 test grid (dijkstra_test.go) is only
// ~3x5km end to end - too small to hold a committed reach AND a separate shell cluster with
// room for the wedge's glue/far-edge margins either side.
//
// Returns the graph, the origin, the town centre's coordinates and node ID (for probing its
// real drive-time), and the lat/lng points to feed a spatial stub: a handful right at the
// origin (the thin committed pool) and one per town node (the cluster).
func buildClusterFixture(t *testing.T) (g *Graph, originLat, originLng float64,
	townLat, townLng float64, townNodeID NodeID, nearMembers, townMembers [][2]float64) {
	t.Helper()

	originLat, originLng = clusterTestOriginLat, clusterTestOriginLng
	kmToLngDeg := 1.0 / (111.32 * math.Cos(originLat*math.Pi/180))

	var nodes []RawNodeSpec
	var ways []RawWaySpec
	nextID := int64(0)
	newNode := func(lat, lng float64) int64 {
		nextID++
		nodes = append(nodes, RawNodeSpec{OSMID: nextID, Lat: lat, Lng: lng})
		return nextID
	}

	origin := newNode(originLat, originLng)

	// A long, fast (trunk) spoke leading due east: one node per km, far enough that the town
	// 14km out sits comfortably inside whatever shell the test derives. townJoin is captured
	// as the loop passes it, rather than recomputed from ID arithmetic.
	const spokeKm = 30
	const townJoinKm = 14
	prev := origin
	var townJoin int64
	for i := 1; i <= spokeKm; i++ {
		id := newNode(originLat, originLng+float64(i)*kmToLngDeg)
		ways = append(ways, RawWaySpec{NodeIDs: []int64{prev, id}, Highway: "trunk"})
		if i == townJoinKm {
			townJoin = id
		}
		prev = id
	}

	// The town: a small dense residential grid, joined to the spoke by one link edge.
	const townSide = 5
	const townSpacingKm = 0.15
	townLat = originLat
	townLng = originLng + townJoinKm*kmToLngDeg
	var townIDs []int64
	for r := 0; r < townSide; r++ {
		for c := 0; c < townSide; c++ {
			lat := townLat + (float64(r)-float64(townSide)/2)*townSpacingKm/111.0
			lng := townLng + (float64(c)-float64(townSide)/2)*townSpacingKm*kmToLngDeg
			id := newNode(lat, lng)
			townIDs = append(townIDs, id)
			townMembers = append(townMembers, [2]float64{lat, lng})
		}
	}
	for r := 0; r < townSide; r++ {
		for c := 0; c < townSide-1; c++ {
			a, b := townIDs[r*townSide+c], townIDs[r*townSide+c+1]
			ways = append(ways, RawWaySpec{NodeIDs: []int64{a, b}, Highway: "residential"})
		}
	}
	for r := 0; r < townSide-1; r++ {
		for c := 0; c < townSide; c++ {
			a, b := townIDs[r*townSide+c], townIDs[(r+1)*townSide+c]
			ways = append(ways, RawWaySpec{NodeIDs: []int64{a, b}, Highway: "residential"})
		}
	}
	ways = append(ways, RawWaySpec{NodeIDs: []int64{townJoin, townIDs[0]}, Highway: "residential"})

	g = BuildGraphFromRaw(nodes, ways, nil)

	townNodeID = NodeID(townIDs[len(townIDs)/2]) // the town's centre-most node
	for i := 0; i < 3; i++ {
		nearMembers = append(nearMembers, [2]float64{originLat, originLng})
	}
	return g, originLat, originLng, townLat, townLng, townNodeID, nearMembers, townMembers
}

// The full lane, wired end to end: query params in, second Isochrone and spatial round trip
// paid for, a genuine cluster found, a wedge polygon out.
func TestClusterOverflow_FiresEndToEndOnGenuineTownCluster(t *testing.T) {
	g, originLat, originLng, _, _, townNodeID, nearMembers, townMembers := buildClusterFixture(t)

	// Probe the town's real drive-time rather than assuming a road speed, then derive
	// committed/shell ceilings from it with generous margin either side.
	probe := Isochrone(g, originLat, originLng, 3600, Drive)
	townSecs, ok := probe.ReachedNodes[townNodeID]
	if !ok {
		t.Fatal("fixture assumption broken: town not reachable within 60 minutes")
	}
	committedMinutes := float64(townSecs) / 60.0 * 0.5
	clusterMaxMinutes := float64(townSecs) / 60.0 * 1.5

	spatialURL := stubSpatialFixed(t, append(append([][2]float64{}, nearMembers...), townMembers...))

	query := fmt.Sprintf(
		"lat=%f&lng=%f&mode=drive&ticks=3&max_minutes=%f&polygons=0"+
			"&cluster_anchor=1&cluster_floor=20&cluster_k=10&cluster_max_wedges=99&cluster_max_minutes=%f",
		originLat, originLng, committedMinutes, clusterMaxMinutes)
	body := getScheduleOn(t, g, spatialURL, query)

	total, _ := body["total_freeglers"].(float64)
	if total < 1 || total >= 20 {
		t.Fatalf("expected a thin pool (1..19) of just the near-origin members, got total_freeglers=%v", total)
	}

	rings, ok := body["overflow_cluster"].(map[string]interface{})
	if !ok {
		t.Fatalf("expected overflow_cluster in the response: keys %v", keysOfMap(body))
	}
	// cluster_max_wedges=99 must still clamp to the hard cap of 3.
	if len(rings) > 3 {
		t.Errorf("expected at most 3 wedges even though cluster_max_wedges=99 was requested, got %d", len(rings))
	}
	w1, present := rings["w1"]
	if !present {
		t.Fatalf("expected key \"w1\", got keys %v", keysOfMapAny(rings))
	}
	ring := ringOfFeature(t, w1)
	if len(ring) < 4 || ring[0] != ring[len(ring)-1] {
		t.Fatalf("w1 is not a valid closed ring: %d points", len(ring))
	}
}

// cluster_max_minutes below max_minutes must floor to max_minutes (a zero-width shell), not go
// negative - proved here by an absurdly small cluster_max_minutes producing no wedge rather
// than a crash or a nonsensical inverted range.
func TestClusterOverflow_ClusterMaxMinutesFloorsToMaxMinutes(t *testing.T) {
	g, originLat, originLng, _, _, _, nearMembers, townMembers := buildClusterFixture(t)
	spatialURL := stubSpatialFixed(t, append(append([][2]float64{}, nearMembers...), townMembers...))

	query := fmt.Sprintf(
		"lat=%f&lng=%f&mode=drive&ticks=3&max_minutes=10&polygons=0"+
			"&cluster_anchor=1&cluster_floor=20&cluster_k=10&cluster_max_minutes=0.001",
		originLat, originLng)
	body := getScheduleOn(t, g, spatialURL, query)

	if _, present := body["overflow_cluster"]; present {
		t.Error("expected no cluster wedge once cluster_max_minutes floors to a zero-width shell")
	}
}

func keysOfMapAny(m map[string]interface{}) []string {
	out := make([]string, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}
