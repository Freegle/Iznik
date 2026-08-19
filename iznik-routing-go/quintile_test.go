package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// The IMD lookup this server already holds for the fairness isochrone, exposed so nothing else
// in the estate has to load, understand or retain IMD data - and so no inferred deprivation
// attribute is written against an individual anywhere. GET /v1/quintile answers one point;
// POST /v1/quintiles answers the set of people a fairness ring would add, in one call.

func getQuintile(t *testing.T, query string) (int, map[string]interface{}) {
	t.Helper()
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodGet, "/v1/quintile?"+query, nil)
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := io.ReadAll(resp.Body)
	var body map[string]interface{}
	_ = json.Unmarshal(raw, &body)
	return resp.StatusCode, body
}

func TestQuintile_RequiresCoordinates(t *testing.T) {
	for _, q := range []string{"", "lat=51.45", "lng=-2.58", "lat=abc&lng=-2.58"} {
		if code, _ := getQuintile(t, q); code != 400 {
			t.Errorf("query %q returned %d, expected 400", q, code)
		}
	}
}

// The answer must be a valid quintile or the explicit unknown, never something a caller could
// mistake for real data.
func TestQuintile_ReturnsAQuintileOrAnHonestUnknown(t *testing.T) {
	code, body := getQuintile(t, "lat=51.4545&lng=-2.5879")
	if code != 200 {
		t.Fatalf("expected 200, got %d", code)
	}

	q, ok := body["quintile"].(float64)
	if !ok {
		t.Fatalf("no quintile in response: %v", body)
	}
	if q < 0 || q > 5 {
		t.Errorf("quintile %v outside 0-5", q)
	}

	available, ok := body["available"].(bool)
	if !ok {
		t.Fatalf("no available flag in response: %v", body)
	}
	// Without the flag a caller cannot tell "quintile 0, we have no data for this estate"
	// from "we have no data at all", and would silently treat every member as unknown.
	if !available && q != 0 {
		t.Errorf("available=false must come with quintile 0, got %v", q)
	}
	t.Logf("Bristol test fixture: quintile=%v available=%v", q, available)
}

// Somewhere with no LSOA anywhere near it must come back unknown rather than snapping to a
// distant centroid and reporting it as fact.
func TestQuintile_UnknownFarFromAnyLSOA(t *testing.T) {
	code, body := getQuintile(t, "lat=40.0&lng=-30.0") // mid-Atlantic
	if code != 200 {
		t.Fatalf("expected 200, got %d", code)
	}
	if q, _ := body["quintile"].(float64); q != 0 {
		t.Errorf("expected quintile 0 in the middle of the Atlantic, got %v", q)
	}
}

func postQuintiles(t *testing.T, jsonBody string) (int, map[string]interface{}) {
	t.Helper()
	app := newInternalApp(t)
	req := httptest.NewRequest(http.MethodPost, "/v1/quintiles", strings.NewReader(jsonBody))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req, 5000)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := io.ReadAll(resp.Body)
	var body map[string]interface{}
	_ = json.Unmarshal(raw, &body)
	return resp.StatusCode, body
}

// The batch answers positionally. A caller matches each answer back to a person by index, so a
// short, reordered or padded array silently attributes one member's deprivation to another -
// the worst failure this endpoint could have.
func TestQuintiles_AnswersEveryPointInOrder(t *testing.T) {
	code, body := postQuintiles(t, `{"points":[[51.4545,-2.5879],[40.0,-30.0],[51.4545,-2.5879]]}`)
	if code != 200 {
		t.Fatalf("expected 200, got %d", code)
	}

	qs, ok := body["quintiles"].([]interface{})
	if !ok {
		t.Fatalf("no quintiles array: %v", body)
	}
	if len(qs) != 3 {
		t.Fatalf("expected 3 answers for 3 points, got %d", len(qs))
	}
	if qs[1].(float64) != 0 {
		t.Errorf("mid-Atlantic point must be unknown, got %v", qs[1])
	}
	// Same point twice must answer the same both times, and its position must be preserved.
	if qs[0].(float64) != qs[2].(float64) {
		t.Errorf("identical points answered differently: %v vs %v", qs[0], qs[2])
	}
	for i, q := range qs {
		if v := q.(float64); v < 0 || v > 5 {
			t.Errorf("point %d: quintile %v outside 0-5", i, v)
		}
	}
}

func TestQuintiles_EmptyBatchIsAnEmptyAnswerNotAnError(t *testing.T) {
	code, body := postQuintiles(t, `{"points":[]}`)
	if code != 200 {
		t.Fatalf("expected 200, got %d", code)
	}
	if qs, ok := body["quintiles"].([]interface{}); !ok || len(qs) != 0 {
		t.Errorf("expected an empty array, got %v", body["quintiles"])
	}
}

func TestQuintiles_RejectsMalformedBody(t *testing.T) {
	if code, _ := postQuintiles(t, `not json`); code != 400 {
		t.Errorf("malformed body should be 400, got %d", code)
	}
}

// Refusing an oversized batch rather than truncating it: a caller handed back fewer answers
// than points would misalign every one of them against its own list.
func TestQuintiles_RefusesAnOversizedBatch(t *testing.T) {
	var sb strings.Builder
	sb.WriteString(`{"points":[`)
	for i := 0; i <= maxQuintilesBatch; i++ {
		if i > 0 {
			sb.WriteString(",")
		}
		sb.WriteString(`[51.4545,-2.5879]`)
	}
	sb.WriteString(`]}`)

	if code, _ := postQuintiles(t, sb.String()); code != 400 {
		t.Errorf("a batch over the cap should be refused, got %d", code)
	}
}
