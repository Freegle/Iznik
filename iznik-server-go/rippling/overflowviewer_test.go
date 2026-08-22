package rippling

import (
	"testing"
)

// The lane defaults. Rural and cluster are ON unless explicitly refused, matching the
// batch config's defaults - the production incident this recovers from was precisely the
// two halves of one feature shipping with opposite defaults, mail inviting members the
// site then refused. Fairness stays opt-in, also matching the batch.

func TestRuralOverflowEnabled_OnByDefault(t *testing.T) {
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "")
	if !RuralOverflowEnabled() {
		t.Error("unset must mean ON: live behaviour is the default")
	}
}

func TestRuralOverflowEnabled_ExplicitRefusalsOnly(t *testing.T) {
	for _, off := range []string{"0", "false", "no", "off", " FALSE "} {
		t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", off)
		if RuralOverflowEnabled() {
			t.Errorf("%q must switch the lane off", off)
		}
	}
	for _, on := range []string{"1", "true", "yes", "anything-else"} {
		t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", on)
		if !RuralOverflowEnabled() {
			t.Errorf("%q must leave the lane on", on)
		}
	}
}

func TestClusterOverflowEnabled_OnByDefault(t *testing.T) {
	t.Setenv("RIPPLE_CLUSTER_ANCHOR_ENABLED", "")
	if !ClusterOverflowEnabled() {
		t.Error("unset must mean ON, like the rural lane")
	}
	t.Setenv("RIPPLE_CLUSTER_ANCHOR_ENABLED", "false")
	if ClusterOverflowEnabled() {
		t.Error("an explicit false must switch the lane off")
	}
}

func TestFairnessOverflowEnabled_OffByDefault(t *testing.T) {
	t.Setenv("RIPPLE_FAIRNESS_ENABLED", "")
	if FairnessOverflowEnabled() {
		t.Error("fairness stays opt-in, matching the batch config")
	}
}

// A signed-out viewer has no band, and must not be handed one by accident. Checked before
// the database is touched, so a nil handle here is also proof that it short-circuits.
func TestViewerRuralPath_EmptyWithoutAViewer(t *testing.T) {
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "1")
	if p := ViewerRuralPath(nil, 0); p != "" {
		t.Errorf("no viewer must yield no path, got %q", p)
	}
}

// The band is looked UP, never interpolated: a settings value cannot become part of a
// JSON path. An unknown band therefore yields nothing rather than a path that might match.
func TestRuralBandPath_OnlyKnownBandsResolve(t *testing.T) {
	for band, want := range map[string]string{
		"dense":  "$.rural.dense",
		"medium": "$.rural.medium",
		"sparse": "$.rural.sparse",
	} {
		if got := RuralBandPath(band); got != want {
			t.Errorf("band %q gave %q, expected %q", band, got, want)
		}
	}
	for _, bogus := range []string{"", "SPARSE", "rural", `sparse"] OR 1=1 --`} {
		if got := RuralBandPath(bogus); got != "" {
			t.Errorf("unknown band %q resolved to %q, expected nothing", bogus, got)
		}
	}
}

// The cluster wedges ignore the viewer's BAND - they sit beyond every band's ceiling, so
// gating them on one would refuse exactly the town members they were built to admit. A
// viewer with no band recorded still gets them.
func TestViewerOverflowPaths_ClusterIgnoresTheBand(t *testing.T) {
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "false")
	t.Setenv("RIPPLE_FAIRNESS_ENABLED", "")
	t.Setenv("RIPPLE_CLUSTER_ANCHOR_ENABLED", "1")

	// A real viewer, and a nil DB so the band lookup cannot contribute a path: whatever
	// comes back is the cluster lane alone, unhelped by any band.
	paths := ViewerOverflowPaths(nil, 42, 51.5, -0.1)
	if len(paths) != 3 {
		t.Fatalf("expected the three wedge slots, got %v", paths)
	}
	for i, want := range []string{"$.cluster.w1", "$.cluster.w2", "$.cluster.w3"} {
		if paths[i] != want {
			t.Errorf("slot %d = %q, want %q", i, paths[i], want)
		}
	}
}

// No viewer, no rings - EVERY lane, not just the band-gated ones.
//
// A ring admits a person standing at their own location. A caller with no viewer is asking
// whether a post reached another POST's location (message/postmatches.go, which feeds the
// matched-posts EMAIL), so admitting on a ring there would both rescue a post nobody is
// standing in, and let the pull-only cluster lane decide what we send.
func TestViewerOverflowPaths_NoRingsWithoutAViewer(t *testing.T) {
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "1")
	t.Setenv("RIPPLE_FAIRNESS_ENABLED", "1")
	t.Setenv("RIPPLE_CLUSTER_ANCHOR_ENABLED", "1")

	if paths := ViewerOverflowPaths(nil, 0, 51.5, -0.1); len(paths) != 0 {
		t.Errorf("a viewer-less caller must get no rings at all, got %v", paths)
	}
}

func TestViewerOverflowPaths_EmptyWhenEveryLaneRefused(t *testing.T) {
	t.Setenv("RIPPLE_RURAL_ACCESS_ENABLED", "false")
	t.Setenv("RIPPLE_FAIRNESS_ENABLED", "")
	t.Setenv("RIPPLE_CLUSTER_ANCHOR_ENABLED", "false")

	if paths := ViewerOverflowPaths(nil, 42, 51.5, -0.1); len(paths) != 0 {
		t.Errorf("every lane off must mean no paths, got %v", paths)
	}
}
