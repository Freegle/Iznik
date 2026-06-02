package test

import (
	json2 "encoding/json"
	"fmt"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/job"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"math/rand"
	"net/http/httptest"
	"testing"
	"time"
)

func TestJobs(t *testing.T) {
	// Create a job at specific coordinates for this test
	lat := 52.5833189
	lng := -2.0455619
	jobID := CreateTestJob(t, lat, lng)

	// Query for jobs near those coordinates
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/job?lat=%f&lng=%f", lat, lng), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var jobs []job.Job
	json2.Unmarshal(rsp(resp), &jobs)
	assert.Greater(t, len(jobs), 0)

	// Get the specific job we created
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

func TestJobsDedupeByTitleAndBody(t *testing.T) {
	// The FD jobs feed indexes the SAME posting for many locations, so a single
	// role can appear dozens of times (Discourse 9739, "every job identical
	// except location"). We de-duplicate by title+body so those exact copies
	// collapse to one card — but NOT by canonical_title (the AI-image category),
	// because unrelated jobs from different employers share a category while
	// having different bodies, and each must still appear.
	lat := 52.5833189
	lng := -2.0455619

	db := database.DBConn

	// Clean up by marker (canonical_title) before and after. The jobs table has a
	// unique(location, title) constraint, so stale rows from a crashed run would
	// otherwise cause duplicate-key errors on insert.
	db.Exec("DELETE FROM jobs WHERE canonical_title IN (?, ?, ?)",
		"test-dedup-A", "test-dedup-B", "test-dedup-ctrl")
	defer func() {
		db.Exec("DELETE FROM jobs WHERE canonical_title IN (?, ?, ?)",
			"test-dedup-A", "test-dedup-B", "test-dedup-ctrl")
	}()

	// High expectation (cpc * clickability) so these rank within JOBS_LIMIT.
	insertJob := func(title, location, body, canonicalTitle string) {
		wkt := fmt.Sprintf("POINT(%.7f %.7f)", lng, lat)
		ref := fmt.Sprintf("test-job-%d-%d", time.Now().UnixNano(), rand.Intn(1000000))
		result := db.Exec(
			fmt.Sprintf(
				"INSERT INTO jobs (title, location, url, body, job_reference, category, geometry, cpc, clickability, visible, canonical_title) "+
					"VALUES (?, ?, 'http://example.com/job', ?, ?, 'General', ST_GeomFromText(?, %d), 99.0, 1, 1, ?)",
				utils.SRID),
			title, location, body, ref, wkt, canonicalTitle)

		if result.Error != nil {
			t.Fatalf("Failed to insert job: %v", result.Error)
		}
	}

	// Group A: the SAME posting (identical title + body) indexed for two
	// locations — must collapse to a single card.
	sameBody := "Pick and pack orders in a busy warehouse. Immediate start, full training given."
	insertJob("Warehouse Operative", "London, UK", sameBody, "test-dedup-A")
	insertJob("Warehouse Operative", "Manchester, UK", sameBody, "test-dedup-A")

	// Group B: SAME title and SAME canonical_title but DIFFERENT bodies (two
	// different employers) — both must still appear. This is the case a
	// canonical_title- or title-only dedupe wrongly collapsed.
	bodySunrise := "Sunrise Homes: support residents with daily living in our care home."
	bodyBrightCare := "BrightCare Ltd: deliver care to clients in their own homes across the city."
	insertJob("Care Assistant", "Bristol, UK", bodySunrise, "test-dedup-B")
	insertJob("Care Assistant", "Leeds, UK", bodyBrightCare, "test-dedup-B")

	// Control: a distinct posting — must appear.
	insertJob("Delivery Driver", "York, UK", "Drive a van delivering parcels on a fixed local route.", "test-dedup-ctrl")

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/job?lat=%f&lng=%f", lat, lng), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var jobs []job.Job
	json2.Unmarshal(rsp(resp), &jobs)

	countTitle := func(title string) int {
		n := 0
		for _, j := range jobs {
			if j.Title == title {
				n++
			}
		}
		return n
	}
	countTitleBody := func(title, body string) int {
		n := 0
		for _, j := range jobs {
			if j.Title == title && j.Body == body {
				n++
			}
		}
		return n
	}

	// Group A: identical title+body across locations collapses to exactly one.
	assert.Equal(t, 1, countTitle("Warehouse Operative"),
		"an identical posting indexed for multiple locations should collapse to a single card")

	// Group B: same title, different body -> BOTH appear (not collapsed by
	// canonical_title or by title alone).
	assert.Equal(t, 1, countTitleBody("Care Assistant", bodySunrise),
		"first distinct Care Assistant posting should appear")
	assert.Equal(t, 1, countTitleBody("Care Assistant", bodyBrightCare),
		"second distinct Care Assistant posting (different body) must also appear")
	assert.Equal(t, 2, countTitle("Care Assistant"),
		"two different employers sharing a job title must both appear; title/canonical_title alone must not collapse them")

	// Control posting still appears.
	assert.GreaterOrEqual(t, countTitle("Delivery Driver"), 1,
		"a distinct control posting should still appear")
}
