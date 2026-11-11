package test

import (
	"fmt"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/job"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// insertPointJob inserts a job at a point with the given category (nil for none)
// and returns its id.
func insertPointJob(t *testing.T, label string, category *string, lng, lat float64) int64 {
	db := database.DBConn
	ref := fmt.Sprintf("cattest-%s-%d", label, time.Now().UnixNano())
	res := db.Exec(fmt.Sprintf(
		"INSERT INTO jobs (title, url, location, body, job_reference, category, geometry, cpc, clickability, visible) "+
			"VALUES (?, 'http://example.com/job', ?, 'Test body', ?, ?, ST_GeomFromText(?, %d), 0.10, 1, 1)",
		utils.SRID),
		ref, "loc-"+ref, ref, category, fmt.Sprintf("POINT(%f %f)", lng, lat))
	if res.Error != nil {
		t.Fatalf("insert job (%s): %v", label, res.Error)
	}
	var id int64
	db.Raw("SELECT id FROM jobs ORDER BY id DESC LIMIT 1").Scan(&id)
	if id == 0 {
		t.Fatalf("job id not found for %s", label)
	}
	t.Cleanup(func() { db.Exec("DELETE FROM jobs WHERE id = ?", id) })
	return id
}

// A third of the feed has no category. Requiring one by default hid those jobs,
// and where the nearest jobs were mostly uncategorised a member saw three.
func TestJobsForIDs_UncategorisedJobsShownWithoutAFilter(t *testing.T) {
	lat, lng := 53.86469, -2.624747
	general := "General"

	withCat := insertPointJob(t, "withcat", &general, lng+0.001, lat)
	noCat := insertPointJob(t, "nocat", nil, lng+0.002, lat)
	distByID := map[int64]float64{withCat: 0.001, noCat: 0.002}

	var ids []uint64
	for _, j := range job.JobsForIDs([]int64{withCat, noCat}, distByID, lat, lng, "") {
		ids = append(ids, j.ID)
	}
	assert.Contains(t, ids, uint64(withCat), "a categorised job is shown")
	assert.Contains(t, ids, uint64(noCat), "a job with no category is shown when no category is asked for")
}

// Picking a category on the jobs page still narrows the list to it.
func TestJobsForIDs_CategoryFilterStillNarrows(t *testing.T) {
	lat, lng := 53.86469, -2.624747
	general := "General"

	withCat := insertPointJob(t, "withcat", &general, lng+0.001, lat)
	noCat := insertPointJob(t, "nocat", nil, lng+0.002, lat)
	distByID := map[int64]float64{withCat: 0.001, noCat: 0.002}

	var ids []uint64
	for _, j := range job.JobsForIDs([]int64{withCat, noCat}, distByID, lat, lng, "General") {
		ids = append(ids, j.ID)
	}
	assert.Contains(t, ids, uint64(withCat), "the chosen category is shown")
	assert.NotContains(t, ids, uint64(noCat), "a job with no category is not in a chosen category")
}
