package test

import (
	"encoding/base64"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/emailtracking"
	"github.com/stretchr/testify/assert"
)

// The delivery handlers used to write every image load straight into email_tracking_images and
// UPDATE the parent email_tracking row, so the images of one email serialised on its row lock.
// With the journal on they append to email_tracking_journal instead (in batches) and the nightly
// mail:tracking:fold applies them. These tests pin the Go half of that contract: what a request
// appends, that the HTTP answer is unchanged, and that nothing touches the parent row.

type journalRow struct {
	ID       uint64
	Ref      string
	Kind     uint8
	Position *string
	Scroll   *uint8
}

func journalOn(t *testing.T) {
	prev := emailtracking.JournalEnabled()
	emailtracking.SetJournalEnabled(true)
	// Anything buffered by an earlier test must not leak into this one's counts.
	emailtracking.FlushJournal()
	t.Cleanup(func() {
		emailtracking.FlushJournal()
		emailtracking.SetJournalEnabled(prev)
	})
}

func journalRowsFor(refPrefix string) []journalRow {
	var rows []journalRow
	database.DBConn.Raw("SELECT id, ref, kind, position, scroll FROM email_tracking_journal WHERE ref LIKE ? ORDER BY id",
		refPrefix+"%").Scan(&rows)
	return rows
}

func cleanupJournal(refPrefix string) {
	database.DBConn.Exec("DELETE FROM email_tracking_journal WHERE ref LIKE ?", refPrefix+"%")
}

func imageURLParam() string {
	return base64.StdEncoding.EncodeToString([]byte(getTestImageDomain() + "/test.jpg"))
}

func TestEmailTrackingJournalImageAppendsOnly(t *testing.T) {
	journalOn(t)
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)
	defer cleanupJournal(tracking.TrackingID)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		"/e/d/i/"+tracking.TrackingID+"?url="+imageURLParam()+"&p=item_3&s=75", nil), -1)
	assert.NoError(t, err)
	assert.Equal(t, http.StatusFound, resp.StatusCode)
	assert.Equal(t, getTestImageDomain()+"/test.jpg", resp.Header.Get("Location"))

	emailtracking.FlushJournal()

	rows := journalRowsFor(tracking.TrackingID)
	assert.Len(t, rows, 1)
	assert.Equal(t, uint8(1), rows[0].Kind)
	assert.Equal(t, tracking.TrackingID, rows[0].Ref)
	assert.Equal(t, "item_3", *rows[0].Position)
	assert.Equal(t, uint8(75), *rows[0].Scroll)

	// Nothing has touched the parent row or the images table: that is the fold's job.
	db := database.DBConn
	var parent emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&parent)
	assert.Nil(t, parent.OpenedAt)
	assert.Nil(t, parent.ScrollDepthPercent)
	var images int64
	db.Model(&emailtracking.EmailTrackingImage{}).Where("email_tracking_id = ?", parent.ID).Count(&images)
	assert.Equal(t, int64(0), images)
}

func TestEmailTrackingJournalCompactImageKeepsRefAndRedirect(t *testing.T) {
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)
	ref := tracking.TrackingID[:12]
	defer cleanupJournal(ref)
	path := "/e/d/i/" + ref + "/t/" + encodeCompactID(120345) + "/1/i1"

	// The redirect must be identical with the journal off and on.
	emailtracking.SetJournalEnabled(false)
	off, err := getApp().Test(httptest.NewRequest("GET", path, nil), -1)
	assert.NoError(t, err)
	emailtracking.SetJournalEnabled(true)
	// restore direct mode for the rest of the package
	defer emailtracking.SetJournalEnabled(false)
	on, err := getApp().Test(httptest.NewRequest("GET", path, nil), -1)
	assert.NoError(t, err)
	emailtracking.FlushJournal()

	assert.Equal(t, off.StatusCode, on.StatusCode)
	assert.Equal(t, off.Header.Get("Location"), on.Header.Get("Location"))

	rows := journalRowsFor(ref)
	assert.Len(t, rows, 1, "only the journalled request appends; the direct one wrote straight through")
	assert.Equal(t, ref, rows[0].Ref)
	assert.Equal(t, "i1", *rows[0].Position)
	assert.Nil(t, rows[0].Scroll)
}

func TestEmailTrackingJournalPixelAppendsAndAnswersTheSame(t *testing.T) {
	journalOn(t)
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)
	defer cleanupJournal(tracking.TrackingID)

	resp, err := getApp().Test(httptest.NewRequest("GET", "/e/d/p/"+tracking.TrackingID, nil))
	assert.NoError(t, err)
	assert.Equal(t, http.StatusOK, resp.StatusCode)
	assert.Equal(t, "image/gif", resp.Header.Get("Content-Type"))
	assert.Equal(t, "no-store, no-cache, must-revalidate, max-age=0", resp.Header.Get("Cache-Control"))

	emailtracking.FlushJournal()
	rows := journalRowsFor(tracking.TrackingID)
	assert.Len(t, rows, 1)
	assert.Equal(t, uint8(2), rows[0].Kind)
	assert.Nil(t, rows[0].Position)

	var parent emailtracking.EmailTracking
	database.DBConn.Where("tracking_id = ?", tracking.TrackingID).First(&parent)
	assert.Nil(t, parent.OpenedAt, "the parent row is not touched at request time")
}

func TestEmailTrackingJournalIgnoresJunkRefs(t *testing.T) {
	journalOn(t)
	var before int64
	database.DBConn.Raw("SELECT COUNT(*) FROM email_tracking_journal").Scan(&before)

	for _, ref := range []string{"bad%20ref!", "%25%25", strings.Repeat("a", 5) + "%27"} {
		resp, err := getApp().Test(httptest.NewRequest("GET", "/e/d/p/"+ref, nil))
		assert.NoError(t, err)
		assert.Equal(t, http.StatusOK, resp.StatusCode)
	}
	emailtracking.FlushJournal()

	var after int64
	database.DBConn.Raw("SELECT COUNT(*) FROM email_tracking_journal").Scan(&after)
	assert.Equal(t, before, after, "refs that cannot be tracking ids are not journalled")
}

func TestEmailTrackingJournalClampsFields(t *testing.T) {
	journalOn(t)
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)
	defer cleanupJournal(tracking.TrackingID)

	long := strings.Repeat("p", 80)
	getApp().Test(httptest.NewRequest("GET",
		"/e/d/i/"+tracking.TrackingID+"?url="+imageURLParam()+"&p="+long+"&s=150", nil), -1)
	getApp().Test(httptest.NewRequest("GET",
		"/e/d/i/"+tracking.TrackingID+"?url="+imageURLParam()+"&p=x", nil), -1)
	emailtracking.FlushJournal()

	rows := journalRowsFor(tracking.TrackingID)
	assert.Len(t, rows, 2)
	assert.Len(t, *rows[0].Position, 50, "position fits the images column")
	assert.Nil(t, rows[0].Scroll, "an out-of-range scroll percentage is dropped, not stored")
	assert.Nil(t, rows[1].Scroll, "no s parameter means no scroll value")
}

func TestEmailTrackingJournalBatchesManyLoadsInOrder(t *testing.T) {
	journalOn(t)
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)
	defer cleanupJournal(tracking.TrackingID)

	for i := 0; i < 120; i++ {
		emailtracking.RecordImageLoad(tracking.TrackingID, "item_"+strings.Repeat("x", i%5), i%101)
	}
	// The background flusher may have written some of them already; what matters is that after a
	// flush all are there and a second flush has nothing left.
	emailtracking.FlushJournal()
	assert.Equal(t, 0, emailtracking.FlushJournal(), "a second flush has nothing left")

	rows := journalRowsFor(tracking.TrackingID)
	assert.Len(t, rows, 120)
	for i := 1; i < len(rows); i++ {
		assert.Greater(t, rows[i].ID, rows[i-1].ID)
	}
}

// If the journal cannot be written (the table is not there yet, or the database refuses), tracking
// must not be lost: the events are applied the old direct way.
func TestEmailTrackingJournalFallsBackToDirectWrites(t *testing.T) {
	journalOn(t)
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	restore := emailtracking.SetJournalTableForTest("email_tracking_journal_does_not_exist")
	defer restore()

	emailtracking.RecordImageLoad(tracking.TrackingID, "item_1", 40)
	emailtracking.RecordImageLoad(tracking.TrackingID, "item_2", 90)
	emailtracking.RecordImageLoad(tracking.TrackingID, "item_3", 10)
	emailtracking.FlushJournal()

	db := database.DBConn
	var parent emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&parent)
	assert.NotNil(t, parent.OpenedAt)
	assert.Equal(t, "image", *parent.OpenedVia)
	assert.Equal(t, uint8(90), *parent.ScrollDepthPercent, "deepest scroll wins")
	var images int64
	db.Model(&emailtracking.EmailTrackingImage{}).Where("email_tracking_id = ?", parent.ID).Count(&images)
	assert.Equal(t, int64(3), images)
}

func TestEmailTrackingJournalSwitchDefaultsOn(t *testing.T) {
	assert.True(t, emailtracking.JournalEnabledFromEnv(""))
	assert.True(t, emailtracking.JournalEnabledFromEnv("on"))
	assert.False(t, emailtracking.JournalEnabledFromEnv("off"))
	assert.False(t, emailtracking.JournalEnabledFromEnv("0"))
	assert.False(t, emailtracking.JournalEnabledFromEnv("false"))
}
