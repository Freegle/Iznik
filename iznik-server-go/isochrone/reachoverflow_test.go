package isochrone

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/utils"
)

// The rural-access ring as an alternative way into the browse feed, for a viewer whose own
// density band earns a wider travel budget than the audience cap allowed the post.
//
// These test the SQL SHAPE rather than the rows it returns, because the shape is where this
// goes wrong silently: a stray "AND" or a mis-ordered parameter still runs, and still returns
// a plausible-looking feed.

func TestViewerRuralPath_EmptyWhenTheLaneIsOff(t *testing.T) {
	// Explicitly off: the lane is ON by default now (matching the batch config),
	// so only a stated "false" turns it away.
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "false")
	if p := rippling.ViewerRuralPath(nil, 42); p != "" {
		t.Errorf("lane off must yield no path, got %q", p)
	}
}

// A signed-out viewer has no band, and must not be handed one by accident. Checked before the
// database is touched, so a nil handle here is also proof that it short-circuits.
func TestViewerRuralPath_EmptyWithoutAViewer(t *testing.T) {
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "1")
	if p := rippling.ViewerRuralPath(nil, 0); p != "" {
		t.Errorf("no viewer must yield no path, got %q", p)
	}
}

// The band is looked UP, never interpolated: a settings value cannot become part of a JSON
// path. An unknown band therefore yields nothing rather than a path that might match.
func TestRuralBandPaths_OnlyKnownBandsResolve(t *testing.T) {
	for band, want := range map[string]string{
		"dense":  "$.rural.dense",
		"medium": "$.rural.medium",
		"sparse": "$.rural.sparse",
	} {
		if got := rippling.RuralBandPath(band); got != want {
			t.Errorf("band %q gave %q, expected %q", band, got, want)
		}
	}

	for _, bogus := range []string{"", "SPARSE", "rural", `sparse"] OR 1=1 --`} {
		if got := rippling.RuralBandPath(bogus); got != "" {
			t.Errorf("unknown band %q resolved to %q, expected nothing", bogus, got)
		}
	}
}

func TestComposeReachOverflow_UnchangedWhenTheLaneCannotApply(t *testing.T) {
	plain := "AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?)) "
	plainArgs := []interface{}{-0.1, 51.5, utils.SRID}

	// Nobody admitted - the same case as "the lane cannot apply", now expressed
	// as an empty msgid list because the rings are resolved before we get here.
	got, gotArgs := composeReachOverflow(plain, plainArgs, nil)

	// Not merely equivalent - IDENTICAL. A lane nobody is using must not change the query
	// the feed has always run, nor its cost.
	if got != plain {
		t.Errorf("expected the untouched containment SQL.\n got: %s\nwant: %s", got, plain)
	}
	if len(gotArgs) != len(plainArgs) {
		t.Errorf("expected %d args, got %d", len(plainArgs), len(gotArgs))
	}
	if strings.Contains(got, "overflow_bounds") {
		t.Error("the overflow branch must be absent, not merely false")
	}
}

// The cheap box test must come BEFORE the polygon parse. Reversed, every candidate row would
// parse a ring - which is the cost this prefilter exists to avoid, and it would not show up as
// a wrong answer anywhere.
func TestRuralOverflowWhere_BoxIsTestedBeforeTheRing(t *testing.T) {
	where, args := rippling.RuralOverflowWhere(-0.1, 51.5, utils.SRID, "$.rural.sparse")

	box := strings.Index(where, "$.bbox[0]")
	ring := strings.Index(where, "ST_GeomFromText")
	if box == -1 || ring == -1 {
		t.Fatalf("expected both a box test and a ring test, got: %s", where)
	}
	if box > ring {
		t.Errorf("the box test must precede the ring parse, got: %s", where)
	}

	// lng, lat, path, srid, lng, lat, srid
	if len(args) != 7 {
		t.Fatalf("expected 7 args, got %d: %v", len(args), args)
	}
	if args[2] != "$.rural.sparse" {
		t.Errorf("expected the band path third, got %v", args[2])
	}
}

// Composition, which is where a stray keyword hides: the reach fragment opens with "AND ", so
// wrapping it without stripping that would produce "AND ((AND ...".
func TestReachOrOverflowSQL_ComposesWithoutADoubledKeyword(t *testing.T) {
	reachWhere := "AND ST_Contains(rr.polygon, ST_SRID(POINT(?, ?), ?)) "
	where, args := composeReachOverflow(reachWhere, []interface{}{-0.1, 51.5, utils.SRID}, []uint64{101, 102})

	// 3 for the reach test, 1 for the msgid list. The ring arm is a list of ids
	// now, not seven JSON binds: ORing the JSON test against the spatial
	// predicate removed the SPATIAL index and scanned a ~17GB table.
	if len(args) != 4 {
		t.Errorf("expected 4 args, got %d", len(args))
	}
	if !strings.Contains(where, "rr.msgid IN (?)") {
		t.Errorf("expected the ring arm as a msgid list, got: %s", where)
	}
	if strings.Contains(where, "overflow_bounds") || strings.Contains(where, "JSON_EXTRACT") {
		t.Errorf("the JSON ring test must not reach the feed query: %s", where)
	}
	if strings.Contains(where, "AND (AND") || strings.Contains(where, "((AND") {
		t.Errorf("doubled AND in composed SQL: %s", where)
	}
	if !strings.Contains(where, " OR ") {
		t.Errorf("expected the ring as an alternative, got: %s", where)
	}
	if strings.Count(where, "(") != strings.Count(where, ")") {
		t.Errorf("unbalanced parentheses: %s", where)
	}
	// The reach test and the ring must be ALTERNATIVES within one conjunct. Without the
	// outer parens the OR would escape and dissolve every other filter in the WHERE.
	if !strings.HasPrefix(strings.TrimSpace(where), "AND ((") {
		t.Errorf("the pair must be bracketed as one conjunct, got: %s", where)
	}
}

func TestFairnessMaxQuintile_ClampedToTheRangeRingsExistFor(t *testing.T) {
	// Must agree with the batch. A viewer admitted by browse and refused by the mail, or the
	// other way round, is worse than either behaviour on its own.
	for env, want := range map[string]int{"": 1, "0": 1, "1": 1, "3": 3, "4": 4, "9": 4, "nonsense": 1} {
		t.Setenv("RIPPLE_FAIRNESS_MAX_QUINTILE", env)
		if got := rippling.FairnessMaxQuintile(); got != want {
			t.Errorf("env %q gave %d, expected %d", env, got, want)
		}
	}
}

func TestViewerFairnessPath_EmptyWhenTheLaneIsOff(t *testing.T) {
	t.Setenv("RIPPLE_FAIRNESS_ENABLED", "")
	if p := rippling.ViewerFairnessPath(51.5, -0.1); p != "" {
		t.Errorf("lane off must yield no path, got %q", p)
	}
}

// With the lane on but the spatial server unreachable in tests, the lookup yields 0 - which
// must read as "not eligible", never as a match. An outage costs the lane its extra posts
// rather than showing everyone inside a stretched ring.
func TestViewerFairnessPath_EmptyWhenDeprivationCannotBeAnswered(t *testing.T) {
	t.Setenv("RIPPLE_FAIRNESS_ENABLED", "1")
	t.Setenv("ROUTING_EVAL_URL", "http://127.0.0.1:1")
	if p := rippling.ViewerFairnessPath(51.5, -0.1); p != "" {
		t.Errorf("unanswerable deprivation must yield no path, got %q", p)
	}
}

// The badge's id-list query must carry the viewer's ring as a third containment
// arm - and only for viewers a ring actually admits. Without it, a ring-admitted
// post appears in the feed but not the badge count.
func TestFromIDsWhere_RingArmOnlyForRingViewers(t *testing.T) {
	latlng := utils.LatLng{Lat: 51.5, Lng: -0.1}

	plain, plainArgs := fromIDsWhere([]int64{1}, []int64{2}, latlng, nil)
	if strings.Contains(plain, "ms.msgid IN (?) AND EXISTS") == false {
		t.Fatalf("raster arms must stay keyed on the id lists, got %q", plain)
	}
	if strings.Count(plain, "ms.msgid IN (?)") != 2 {
		t.Fatalf("nothing admitted must mean two arms, not three: %q", plain)
	}
	if len(plainArgs) != 9 {
		t.Fatalf("plain arg count = %d, want 9 (in, partial, lng, lat, srid + 4 author-cap)", len(plainArgs))
	}

	ringed, ringedArgs := fromIDsWhere([]int64{1}, []int64{2}, latlng, []uint64{101, 102})
	if strings.Count(ringed, "ms.msgid IN (?)") != 3 {
		t.Fatalf("admitted posts must add a third id-list arm, got %q", ringed)
	}
	if len(ringedArgs) != 10 {
		t.Fatalf("ringed arg count = %d, want 10 (9 + the admitted list)", len(ringedArgs))
	}
	if ids, ok := ringedArgs[5].([]uint64); !ok || len(ids) != 2 || ids[0] != 101 {
		t.Fatalf("admitted ids must bind in the ring arm's slot, got %v at index 5", ringedArgs[5])
	}
}

// The ring test is JSON_EXTRACT over a column. In this WHERE it can only be
// carried by an EXISTS correlated on ms.msgid, which is true for rows in NEITHER
// raster bucket - so the id lists stop bounding the scan and the badge walks
// messages_spatial. Ids keep it keyed; this asserts the shape, because the query
// still returns the right answer when it is wrong, just slowly enough to take the
// site with it.
func TestFromIDsWhere_NeverCarriesTheJSONRingTest(t *testing.T) {
	latlng := utils.LatLng{Lat: 51.5, Lng: -0.1}

	ringed, _ := fromIDsWhere([]int64{1}, []int64{2}, latlng, []uint64{101})
	for _, banned := range []string{"overflow_bounds", "ST_GeomFromText"} {
		if strings.Contains(ringed, banned) {
			t.Errorf("the JSON ring test must not reach the badge query (%s): %q", banned, ringed)
		}
	}

	// JSON_EXTRACT itself cannot be banned outright: the author distance cap
	// reads browseMaxDistance out of au.settings, which is a keyed lookup on a
	// row already joined, not a predicate anything has to scan for. Every
	// occurrence must be that one.
	if strings.Count(ringed, "JSON_EXTRACT") != strings.Count(ringed, "JSON_EXTRACT(au.settings") {
		t.Errorf("the only JSON_EXTRACT allowed here is the author cap's settings lookup: %q", ringed)
	}
}
