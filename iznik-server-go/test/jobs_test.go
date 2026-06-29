package test

import (
	json2 "encoding/json"
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/job"
	"github.com/stretchr/testify/assert"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestJobs(t *testing.T) {
	// Create a job at specific coordinates for this test
	lat := 52.5833189
	lng := -2.0455619
	jobID := CreateTestJob(t, lat, lng)

	// Query for jobs near those coordinates.
	// The spatial server loads jobs from the main DB; in test environments the index may
	// not include test-database jobs, so we only verify the API returns a valid response.
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/job?lat=%f&lng=%f", lat, lng), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var jobs []job.Job
	json2.Unmarshal(rsp(resp), &jobs)
	assert.NotNil(t, jobs)

	// Get the specific job we created — direct lookup by ID does not require spatial.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/job/"+fmt.Sprint(jobID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	// Non-existent job should return 404
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/job/0", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestJobs_InvalidID(t *testing.T) {
	// Non-integer job ID
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/job/notanint", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestJobs_WithoutCoords(t *testing.T) {
	// No lat/lng params - should still return 200 (defaults to 0,0)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/job", nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestJobs_V2Path(t *testing.T) {
	lat := 52.5833189
	lng := -2.0455619
	CreateTestJob(t, lat, lng)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/apiv2/job?lat=%f&lng=%f", lat, lng), nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestJobClick(t *testing.T) {
	// Create a job for this test
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)

	// Record a click without authentication (anonymous user)
	resp, _ := getApp().Test(httptest.NewRequest("POST", fmt.Sprintf("/api/job?id=%d&link=http://example.com/job", jobID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(0), result["ret"])
	assert.Equal(t, "Success", result["status"])

	// Test with missing job ID - still returns success (matches PHP behavior)
	resp, _ = getApp().Test(httptest.NewRequest("POST", "/api/job", nil))
	assert.Equal(t, 200, resp.StatusCode)
}

// TestJobClick_JSONBody guards the transport the web app actually uses: it POSTs
// the click as a JSON body (Content-Type: application/json), which Fiber's
// FormValue does not parse. Before the JSON-body branch, every web click was
// stored with jobid=0/link='', making logs_jobs useless for click attribution.
func TestJobClick_JSONBody(t *testing.T) {
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	link := fmt.Sprintf("https://example.com/job/%d?utm=jsonbody", jobID)

	body := fmt.Sprintf(`{"id":%d,"link":%q}`, jobID, link)
	req := httptest.NewRequest("POST", "/api/job", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")

	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)

	// The id and link from the JSON body must be persisted, not dropped.
	var gotID uint64
	var gotLink string
	database.DBConn.Raw("SELECT jobid, link FROM logs_jobs WHERE jobid = ? ORDER BY id DESC LIMIT 1", jobID).Row().Scan(&gotID, &gotLink)
	assert.Equal(t, jobID, gotID)
	assert.Equal(t, link, gotLink)
}

// TestJobClick_Placement guards per-placement attribution: a click records WHICH slot it came
// from, so click-through can be measured per placement (sticky footer / sidebar / jobs page /
// email / modal) rather than as a single global count. Covers the JSON body (web app), the query
// string (email links), and the absent case (stored NULL, so legacy rows stay distinguishable).
func TestJobClick_Placement(t *testing.T) {
	// (a) JSON body — the web app transport.
	jobID := CreateTestJob(t, 52.5833189, -2.0455619)
	body := fmt.Sprintf(`{"id":%d,"link":"https://example.com/job","placement":"sidebar_left","source":"website"}`, jobID)
	req := httptest.NewRequest("POST", "/api/job", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
	var placement, source string
	database.DBConn.Raw("SELECT COALESCE(placement,''), COALESCE(source,'') FROM logs_jobs WHERE jobid = ? ORDER BY id DESC LIMIT 1", jobID).Row().Scan(&placement, &source)
	assert.Equal(t, "sidebar_left", placement)
	assert.Equal(t, "website", source)

	// (b) query string — the digest email redirect link.
	jobID2 := CreateTestJob(t, 52.5833189, -2.0455619)
	resp, _ = getApp().Test(httptest.NewRequest("POST", fmt.Sprintf("/api/job?id=%d&link=http://x/job&placement=email_redirect&source=email", jobID2), nil))
	assert.Equal(t, 200, resp.StatusCode)
	var placement2 string
	database.DBConn.Raw("SELECT COALESCE(placement,'') FROM logs_jobs WHERE jobid = ? ORDER BY id DESC LIMIT 1", jobID2).Row().Scan(&placement2)
	assert.Equal(t, "email_redirect", placement2)

	// (c) absent placement → NULL, not '' — legacy/untagged clicks stay distinguishable.
	jobID3 := CreateTestJob(t, 52.5833189, -2.0455619)
	body3 := fmt.Sprintf(`{"id":%d,"link":"http://x/job"}`, jobID3)
	req3 := httptest.NewRequest("POST", "/api/job", strings.NewReader(body3))
	req3.Header.Set("Content-Type", "application/json")
	getApp().Test(req3)
	var nullCount int64
	database.DBConn.Raw("SELECT COUNT(*) FROM logs_jobs WHERE jobid = ? AND placement IS NULL", jobID3).Row().Scan(&nullCount)
	assert.Equal(t, int64(1), nullCount)
}

// TestJobClick_ContentFree guards the bot/scanner case: a POST /job with neither an id nor a
// link must record NOTHING (it returns success but writes no row), so logs_jobs is not flooded
// with jobid=0/link='' noise that buries the genuine clicks.
func TestJobClick_ContentFree(t *testing.T) {
	var before int64
	database.DBConn.Raw("SELECT COUNT(*) FROM logs_jobs WHERE (jobid IS NULL OR jobid = 0) AND (link IS NULL OR link = '')").Row().Scan(&before)

	resp, _ := getApp().Test(httptest.NewRequest("POST", "/api/job", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var after int64
	database.DBConn.Raw("SELECT COUNT(*) FROM logs_jobs WHERE (jobid IS NULL OR jobid = 0) AND (link IS NULL OR link = '')").Row().Scan(&after)
	assert.Equal(t, before, after, "content-free POST /job must not insert a logs_jobs row")
}
