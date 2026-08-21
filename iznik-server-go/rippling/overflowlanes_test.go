package rippling

import "testing"

// The lane table is a CONTRACT with iznik-spatial-go: it stamps these codes into
// the ids its reachoverflow dataset returns, and this side decodes them. A
// disagreement would admit members to a lane they are not in - a sparse-band
// member let in on the dense-band ring - and nothing downstream could detect it,
// because the wrong answer is still a plausible feed.
//
// So both sides assert the pairs verbatim. The twin of this test lives in
// iznik-spatial-go/dataset_reachoverflow_test.go; changing one without the other
// fails there, not in production.
func TestOverflowLaneCodes_MatchTheSpatialServersTable(t *testing.T) {
	want := map[string]int64{
		"$.rural.dense":  1,
		"$.rural.medium": 2,
		"$.rural.sparse": 3,
		`$.fairness."1"`: 4,
		`$.fairness."2"`: 5,
		`$.fairness."3"`: 6,
		`$.fairness."4"`: 7,
		"$.cluster.w1":   8,
		"$.cluster.w2":   9,
		"$.cluster.w3":   10,
	}

	if len(OverflowLaneCodes) != len(want) {
		t.Fatalf("lane count = %d, want %d - a lane added here must be added to the spatial server too",
			len(OverflowLaneCodes), len(want))
	}
	for path, code := range want {
		if got, ok := OverflowLaneCodes[path]; !ok || got != code {
			t.Errorf("lane %q = %d (present=%v), want %d", path, got, ok, code)
		}
	}
}

// Every path ViewerOverflowPaths can produce must be decodable, or that lane is
// silently dark: the spatial server would answer for it and this side would
// discard the answer.
func TestOverflowLaneCodes_CoverEveryPathAViewerCanHave(t *testing.T) {
	for band, path := range ruralBandPaths {
		if _, ok := OverflowLaneCodes[path]; !ok {
			t.Errorf("rural band %q maps to %q, which has no lane code", band, path)
		}
	}
	for _, path := range clusterPaths {
		if _, ok := OverflowLaneCodes[path]; !ok {
			t.Errorf("cluster wedge %q has no lane code", path)
		}
	}
	// Fairness paths are built from the quintile, and the lane reaches at most
	// the fourth fifth (FairnessMaxQuintile clamps to 4).
	for _, path := range []string{`$.fairness."1"`, `$.fairness."2"`, `$.fairness."3"`, `$.fairness."4"`} {
		if _, ok := OverflowLaneCodes[path]; !ok {
			t.Errorf("fairness path %q has no lane code", path)
		}
	}
}

func TestDecodeOverflowExtID_RoundTripsEveryLane(t *testing.T) {
	const msgid = 121564088

	for path, code := range OverflowLaneCodes {
		extID := msgid<<overflowLaneShift | code
		gotMsgid, gotCode := DecodeOverflowExtID(extID)
		if gotMsgid != msgid || gotCode != code {
			t.Errorf("lane %q: decoded (%d, %d), want (%d, %d)", path, gotMsgid, gotCode, msgid, code)
		}
	}
}

// A bare msgid must not decode as a real lane. Code 0 is never issued, so an id
// that arrived without one matches nothing rather than admitting on lane 1.
func TestDecodeOverflowExtID_BareIDCarriesNoLane(t *testing.T) {
	if _, code := DecodeOverflowExtID(121564088 << overflowLaneShift); code != 0 {
		t.Errorf("an id with no lane stamped must decode as lane 0, got %d", code)
	}
	if msgid, code := DecodeOverflowExtID(-1); msgid != 0 || code != 0 {
		t.Errorf("a negative id must decode as nothing, got (%d, %d)", msgid, code)
	}
}

// The viewer's lanes decide which of the spatial server's answers count. An
// answer for a lane this viewer is not in must be discarded - it is another
// band's ring, drawn for people who live somewhere else.
func TestMsgidsForLanes_KeepsOnlyTheViewersLanes(t *testing.T) {
	codes := laneCodesFor([]string{"$.rural.sparse", "$.cluster.w1"})

	ids := []int64{
		101<<overflowLaneShift | OverflowLaneCodes["$.rural.sparse"],
		102<<overflowLaneShift | OverflowLaneCodes["$.rural.dense"],  // not this viewer's band
		103<<overflowLaneShift | OverflowLaneCodes["$.cluster.w1"],   // wedge: unconditional on band
		104<<overflowLaneShift | OverflowLaneCodes[`$.fairness."2"`], // lane the viewer has no path for
	}

	got := msgidsForLanes(ids, codes)
	if len(got) != 2 {
		t.Fatalf("admitted %v, want exactly posts 101 and 103", got)
	}
	if got[0] != 101 || got[1] != 103 {
		t.Errorf("admitted %v, want [101 103]", got)
	}
}

// One post can carry several lanes and match on more than one; it is one post,
// and a duplicate would double it in the feed's id list.
func TestMsgidsForLanes_AdmitsAPostOnce(t *testing.T) {
	codes := laneCodesFor([]string{"$.rural.sparse", "$.cluster.w1", "$.cluster.w2"})

	ids := []int64{
		101<<overflowLaneShift | OverflowLaneCodes["$.rural.sparse"],
		101<<overflowLaneShift | OverflowLaneCodes["$.cluster.w1"],
		101<<overflowLaneShift | OverflowLaneCodes["$.cluster.w2"],
	}

	if got := msgidsForLanes(ids, codes); len(got) != 1 || got[0] != 101 {
		t.Errorf("a post matching three lanes must appear once, got %v", got)
	}
}

// A viewer with no recognised lane must produce no lane filter at all, so
// AdmittedMsgids can return before it ever calls the spatial server.
func TestLaneCodesFor_UnknownPathsAreDropped(t *testing.T) {
	if codes := laneCodesFor([]string{"$.rural.tropical", ""}); len(codes) != 0 {
		t.Errorf("unknown lanes must be dropped, got %v", codes)
	}
}

// A post can be definite on one lane and borderline on another. It is already
// admitted, so it must not also be exact-tested (a ring parse for nothing) nor
// appear twice in the caller's id list.
func TestNotAlreadyIn_DropsPostsAlreadyAdmitted(t *testing.T) {
	got := notAlreadyIn([]uint64{101, 102, 103}, []uint64{102})
	if len(got) != 2 || got[0] != 101 || got[1] != 103 {
		t.Errorf("band = %v, want [101 103]", got)
	}
	if got := notAlreadyIn([]uint64{101}, nil); len(got) != 1 {
		t.Errorf("nothing admitted yet must leave the band alone, got %v", got)
	}
}
