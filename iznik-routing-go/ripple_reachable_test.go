package main

import (
	"encoding/json"
	"strings"
	"testing"
)

func TestRippleScheduleResponse_CarriesReachableGroupIDs(t *testing.T) {
	b, err := json.Marshal(rippleScheduleResponse{
		Schedule:          []rippleScheduleEntry{},
		ReachableGroupIDs: []int64{21656, 21458},
	})
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(b), `"reachable_group_ids":[21656,21458]`) {
		t.Errorf("reachable_group_ids not carried in schedule response: %s", b)
	}

	// An empty (non-nil) list must serialise as [] rather than null, so the batch
	// can distinguish a new server that computed zero groups from an old server
	// that omits the field entirely.
	be, _ := json.Marshal(rippleScheduleResponse{ReachableGroupIDs: []int64{}})
	if !strings.Contains(string(be), `"reachable_group_ids":[]`) {
		t.Errorf("empty reachable_group_ids should serialise as []: %s", be)
	}
}
