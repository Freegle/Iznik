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

func TestJobsDedupeByCanonicalTitle(t *testing.T) {
	// Test data: two jobs with same canonical_title but different locations
	lat := 52.5833189
	lng := -2.0455619

	db := database.DBConn
	defer func() {
		// Cleanup: delete test jobs
		db.Exec("DELETE FROM jobs WHERE canonical_title = ? OR canonical_title = ?",
			"test-role-dedup", "test-role-control")
	}()

	// Helper to insert a job with specific canonical_title
	insertJob := func(title, location, canonicalTitle string) {
		wkt := fmt.Sprintf("POINT(%.7f %.7f)", lng, lat)
		ref := fmt.Sprintf("test-job-%d-%d", time.Now().UnixNano(), rand.Intn(100000))
		result := db.Exec(
			fmt.Sprintf(
				"INSERT INTO jobs (title, location, url, body, job_reference, category, geometry, cpc, clickability, visible, canonical_title) "+
					"VALUES (?, ?, 'http://example.com/job', 'Test body', ?, 'General', ST_GeomFromText(?, %d), 0.15, 1, 1, ?)",
				utils.SRID),
			title, location, ref, wkt, canonicalTitle)

		if result.Error != nil {
			t.Fatalf("Failed to insert job: %v", result.Error)
		}
	}

	// Insert two jobs with SAME canonical_title but different locations
	insertJob("Software Engineer - London", "London, UK", "test-role-dedup")
	insertJob("Software Engineer - Manchester", "Manchester, UK", "test-role-dedup")

	// Insert one job with DIFFERENT canonical_title as control (should still appear)
	insertJob("DevOps Engineer - Leeds", "Leeds, UK", "test-role-control")

	// Query for jobs
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/job?lat=%f&lng=%f", lat, lng), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var jobs []job.Job
	json2.Unmarshal(rsp(resp), &jobs)

	// Count how many jobs with the "test-role-dedup" canonical_title are returned
	// We can identify them by their titles
	deducedTitles := map[string]int{
		"Software Engineer - London":      0,
		"Software Engineer - Manchester":  0,
		"DevOps Engineer - Leeds":         0,
	}

	for _, j := range jobs {
		for title := range deducedTitles {
			if j.Title == title {
				deducedTitles[title]++
			}
		}
	}

	// ASSERT: Only ONE of the duplicated titles should appear (the first one, due to ordering)
	londonCount := deducedTitles["Software Engineer - London"]
	manchesterCount := deducedTitles["Software Engineer - Manchester"]
	controlCount := deducedTitles["DevOps Engineer - Leeds"]

	// Before fix: both London and Manchester appear (2 total)
	// After fix: only one appears (1 total)
	totalDedupedJobs := londonCount + manchesterCount

	assert.Equal(t, 1, totalDedupedJobs, "Expected exactly 1 job with canonical_title='test-role-dedup', but got %d", totalDedupedJobs)
	assert.Greater(t, controlCount, 0, "Expected control job (different canonical_title) to still appear")
}
