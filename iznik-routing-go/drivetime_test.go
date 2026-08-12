package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

// Points from the same fixture graph the other endpoint tests use.
const (
	dtLat = 51.4545
	dtLng = -2.5879
)

func driveTime(t *testing.T, query string) (int, map[string]any) {
	t.Helper()

	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/drive-time?"+query, nil)
	resp, err := app.Test(req, 30000)
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(resp.Body)

	var parsed map[string]any
	_ = json.Unmarshal(body, &parsed)

	return resp.StatusCode, parsed
}

// The endpoint exists to answer "how long would it take this member to drive to the post",
// so that the tick of the post's reach schedule that would cover them can be found by
// comparing against the stored drive-time budgets. A scalar, because the alternative
// (materialising a tick's isochrone polygon) costs ~0.5s and ~1.2MB for a number we then
// throw away.
func TestDriveTimeReturnsAScalar(t *testing.T) {
	// Somewhere very close by, so it is comfortably inside any sane budget.
	status, body := driveTime(t, "lat=51.4545&lng=-2.5879&tolat=51.4600&tolng=-2.5900&max_minutes=30&mode=drive")
	if status != 200 {
		t.Fatalf("expected 200, got %d (%v)", status, body)
	}

	if body["reachable"] != true {
		t.Fatalf("expected a nearby point to be reachable, got %v", body)
	}

	mins, ok := body["drive_min"].(float64)
	if !ok {
		t.Fatalf("expected drive_min to be a number, got %v", body["drive_min"])
	}
	if mins < 0 || mins > 30 {
		t.Errorf("drive_min %v outside the requested budget", mins)
	}
}

// Not reachable within the budget is a real answer, not an error. It is how the caller
// learns that no tick of the schedule will ever cover this member, and so that their held
// reply waits for the reach to finish rather than for an arrival that never comes. A 4xx
// here would be indistinguishable from the routing server being broken, and the caller
// would show no estimate at all rather than the correct one.
func TestDriveTimeBeyondBudgetIsNotAnError(t *testing.T) {
	// A minute's drive will not get from Bristol to the far side of the graph.
	status, body := driveTime(t, "lat=51.4545&lng=-2.5879&tolat=53.4808&tolng=-2.2426&max_minutes=1&mode=drive")
	if status != 200 {
		t.Fatalf("expected 200, got %d (%v)", status, body)
	}
	if body["reachable"] != false {
		t.Errorf("expected reachable=false beyond the budget, got %v", body)
	}
}

// Missing coordinates are a caller bug and must not be answered with a plausible number.
func TestDriveTimeRequiresBothEnds(t *testing.T) {
	for _, q := range []string{
		"lng=-2.5879&tolat=51.46&tolng=-2.59",
		"lat=51.4545&tolat=51.46&tolng=-2.59",
		"lat=51.4545&lng=-2.5879&tolng=-2.59",
		"lat=51.4545&lng=-2.5879&tolat=51.46",
	} {
		if status, _ := driveTime(t, q); status != 400 {
			t.Errorf("%s: expected 400, got %d", q, status)
		}
	}
}

// The budget is what the search cost scales with (about 40ms at 30 minutes on the UK graph,
// 900ms at 120), so an unbounded or absurd value has to be clamped rather than honoured.
func TestDriveTimeClampsTheBudget(t *testing.T) {
	for _, q := range []string{
		"lat=51.4545&lng=-2.5879&tolat=51.46&tolng=-2.59&max_minutes=99999",
		"lat=51.4545&lng=-2.5879&tolat=51.46&tolng=-2.59&max_minutes=-5",
	} {
		if status, _ := driveTime(t, q); status != 200 {
			t.Errorf("%s: expected the budget to be clamped and answered, got %d", q, status)
		}
	}
}

// It is on the internal port for trusted backends, and the external one still needs a JWT.
// This matters because the Go API's SPATIAL_SERVER_URL points at the external port: calling
// it there without auth returns 401, which the caller reports as "no estimate" for everyone.
func TestDriveTimeExternalPortRequiresAuth(t *testing.T) {
	app := newExternalApp(t)
	req := httptest.NewRequest(http.MethodGet,
		"/v1/drive-time?lat=51.4545&lng=-2.5879&tolat=51.46&tolng=-2.59", nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	if resp.StatusCode != 401 {
		t.Errorf("expected 401 without auth on the external port, got %d", resp.StatusCode)
	}
}
