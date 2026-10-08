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

// insertRankedJob inserts a small London job with the given cpc and
// clickability and returns its id.
func insertRankedJob(t *testing.T, label string, cpc float64, clickability int) int64 {
	db := database.DBConn
	ref := fmt.Sprintf("ranktest-%s-%d", label, time.Now().UnixNano())
	wkt := "POLYGON((-0.135 51.503, -0.135 51.513, -0.120 51.513, -0.120 51.503, -0.135 51.503))"
	res := db.Exec(fmt.Sprintf(
		"INSERT INTO jobs (title, url, location, body, job_reference, category, geometry, cpc, clickability, visible) "+
			"VALUES (?, 'http://example.com/job', ?, 'Test body', ?, 'General', ST_GeomFromText(?, %d), ?, ?, 1)",
		utils.SRID),
		ref, "loc-"+ref, ref, wkt, cpc, clickability)
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

// Clickability is a keyword count, not a click rate, and ranges far wider than
// cpc, so multiplying the two let common cheap jobs outrank much better-paid
// ones. Pay ranks first; clickability only breaks ties at equal pay.
func TestJobsForIDs_RanksPayBeforeClickability(t *testing.T) {
	lat, lng := 51.5074, -0.1278

	cheapClickable := insertRankedJob(t, "cheap", 0.084, 50)
	wellPaid := insertRankedJob(t, "wellpaid", 0.36, 1)
	samePayLow := insertRankedJob(t, "samepaylow", 0.20, 1)
	samePayHigh := insertRankedJob(t, "samepayhigh", 0.20, 9)

	ids := []int64{cheapClickable, wellPaid, samePayLow, samePayHigh}
	distByID := map[int64]float64{cheapClickable: 0.004, wellPaid: 0.004, samePayLow: 0.004, samePayHigh: 0.004}

	jobs := job.JobsForIDs(ids, distByID, lat, lng, "")

	var got []int64
	for _, j := range jobs {
		got = append(got, int64(j.ID))
	}
	assert.Equal(t, []int64{wellPaid, samePayHigh, samePayLow, cheapClickable}, got)
}
