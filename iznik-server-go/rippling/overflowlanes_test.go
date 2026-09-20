package rippling

import "testing"

// Every path ViewerOverflowPaths can produce must be one the ring index will
// answer for. A path this side drops is a lane that goes dark HERE while the
// mail still invites on it - the surface split the lanes' whole design exists to
// prevent - and a path the server does not know is a 400 that costs the viewer
// every other lane's posts too.
func TestKnownLanes_CoverEveryPathAViewerCanHave(t *testing.T) {
	for band, path := range ruralBandPaths {
		if got := knownLanes([]string{path}); len(got) != 1 {
			t.Errorf("rural band %q maps to %q, which the ring index is not asked for", band, path)
		}
	}
	for _, path := range clusterPaths {
		if got := knownLanes([]string{path}); len(got) != 1 {
			t.Errorf("cluster wedge %q is not asked for", path)
		}
	}
	// Fairness paths are built from the viewer's quintile, clamped to 4.
	for _, path := range []string{`$.fairness."1"`, `$.fairness."2"`, `$.fairness."3"`, `$.fairness."4"`} {
		if got := knownLanes([]string{path}); len(got) != 1 {
			t.Errorf("fairness path %q is not asked for", path)
		}
	}
}

// A lane the index cannot answer for is dropped, not sent: sending it would make
// the whole request a 400 and cost this viewer the lanes that ARE valid.
func TestKnownLanes_DropsWhatTheIndexWouldReject(t *testing.T) {
	got := knownLanes([]string{"$.rural.tropical", "$.rural.sparse", ""})
	if len(got) != 1 || got[0] != "$.rural.sparse" {
		t.Errorf("lanes = %v, want just the sparse path", got)
	}
	if got := knownLanes(nil); len(got) != 0 {
		t.Errorf("no paths must mean no lanes, got %v", got)
	}
}

// No viewer, no lanes: AdmittedMsgids must be able to return before it ever
// reaches the network.
func TestKnownLanes_NoRecognisedLaneMeansNoCall(t *testing.T) {
	if got := knownLanes([]string{"", "nonsense"}); len(got) != 0 {
		t.Errorf("expected nothing askable, got %v", got)
	}
}
