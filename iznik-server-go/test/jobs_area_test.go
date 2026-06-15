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

// insertJobWithGeometry inserts a job with an explicit WKT geometry (so we can
// give it a polygon, unlike CreateTestJob which inserts a POINT) and returns id.
func insertJobWithGeometry(t *testing.T, label, wkt string) int64 {
	db := database.DBConn
	ref := fmt.Sprintf("areatest-%s-%d", label, time.Now().UnixNano())
	res := db.Exec(fmt.Sprintf(
		"INSERT INTO jobs (title, url, location, body, job_reference, category, geometry, cpc, clickability, visible) "+
			"VALUES (?, 'http://example.com/job', ?, 'Test body', ?, 'General', ST_GeomFromText(?, %d), 0.10, 1, 1)",
		utils.SRID),
		ref, "loc-"+ref, ref, wkt)
	if res.Error != nil {
		t.Fatalf("insert job (%s): %v", label, res.Error)
	}
	// job_reference may be truncated by the column width, so fetch the row we
	// just inserted by id (tests run sequentially, like CreateTestJob does).
	var id int64
	db.Raw("SELECT id FROM jobs ORDER BY id DESC LIMIT 1").Scan(&id)
	if id == 0 {
		t.Fatalf("job id not found for %s", label)
	}
	t.Cleanup(func() { db.Exec("DELETE FROM jobs WHERE id = ?", id) })
	return id
}

// Regression test for the PR #459 review: the spatial migration dropped the
// area constraint, so a job whose service-area polygon dwarfs the local search
// extent (e.g. a nation-wide listing) could surface as "nearby". JobsForIDs
// must filter it out.
func TestJobsForIDs_ExcludesOversizedPolygon(t *testing.T) {
	lat, lng := 51.5074, -0.1278 // London

	// A small London-sized service area (~1km square).
	smallWKT := "POLYGON((-0.135 51.503, -0.135 51.513, -0.120 51.513, -0.120 51.503, -0.135 51.503))"
	// A nation-scale polygon (most of GB) with the search point inside it.
	hugeWKT := "POLYGON((-5 50, -5 55.5, 2 55.5, 2 50, -5 50))"

	smallID := insertJobWithGeometry(t, "small", smallWKT)
	hugeID := insertJobWithGeometry(t, "huge", hugeWKT)

	// Both came back from KNN at a small centroid distance → a small search box.
	distByID := map[int64]float64{smallID: 0.004, hugeID: 0.006}

	jobs := job.JobsForIDs([]int64{smallID, hugeID}, distByID, lat, lng, "")

	var ids []uint64
	for _, j := range jobs {
		ids = append(ids, j.ID)
	}
	assert.Contains(t, ids, uint64(smallID), "local small-area job should be returned")
	assert.NotContains(t, ids, uint64(hugeID), "nation-scale-polygon job should be filtered out as not 'nearby'")
}
