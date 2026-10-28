package test

import (
	json2 "encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/emailtracking"
	"github.com/stretchr/testify/assert"
)

// =============================================================================
// Helpers for digest click-position tests
// =============================================================================

// dpSeq keeps generated tracking_id values unique within a test run.
var dpSeq int

// createDigestTrackingRecord creates a digest email_tracking row whose metadata
// records `numPosts` ordered post msgids (positions 0..numPosts-1 were shown).
func createDigestTrackingRecord(t *testing.T, emailType string, sentAt time.Time, numPosts int) uint64 {
	db := database.DBConn

	dpSeq++
	shortID := fmt.Sprintf("dp%d-%d", dpSeq, time.Now().UnixNano())
	if len(shortID) > 32 {
		shortID = shortID[:32]
	}

	// Build a post_msgids array of the requested length; the values are
	// irrelevant — only the length (== number of positions shown) matters.
	msgids := make([]string, numPosts)
	for i := range msgids {
		msgids[i] = fmt.Sprintf("%d", i+1)
	}
	meta := fmt.Sprintf(`{"post_msgids":[%s],"digest_number":1}`, strings.Join(msgids, ","))

	tracking := &emailtracking.EmailTracking{
		TrackingID:     shortID,
		EmailType:      emailType,
		RecipientEmail: "dp@example.com",
		SentAt:         &sentAt,
		Metadata:       &meta,
	}
	result := db.Create(tracking)
	assert.NoError(t, result.Error)
	return tracking.ID
}

// createPositionClick records a click at a specific link_position label.
func createPositionClick(t *testing.T, trackingID uint64, position string, clickedAt time.Time) {
	db := database.DBConn
	pos := position
	click := &emailtracking.EmailTrackingClick{
		EmailTrackingID: trackingID,
		LinkURL:         "https://example.com/message/1",
		LinkPosition:    &pos,
		ClickedAt:       clickedAt,
	}
	result := db.Create(click)
	assert.NoError(t, result.Error)
}

// uniqueDigestType returns an email_type unique to this test run so the
// computed counts are isolated from any other digest data in the test DB.
func uniqueDigestType() string {
	dpSeq++
	return fmt.Sprintf("UDPT%d%d", dpSeq, time.Now().UnixNano()%1000000)
}

// =============================================================================
// Tests for GET /api/modtools/email/stats/digestpositions
// =============================================================================

func TestDigestPositions_Unauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/modtools/email/stats/digestpositions", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestDigestPositions_ForbiddenForRegularUser(t *testing.T) {
	prefix := uniquePrefix("dpforbid")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/modtools/email/stats/digestpositions?jwt="+token, nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func TestDigestPositions_SupportUserAccess(t *testing.T) {
	prefix := uniquePrefix("dpsupport")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/modtools/email/stats/digestpositions?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.NotNil(t, result["data"])
	assert.NotNil(t, result["period"])
}

// TestDigestPositions_CTRByPosition verifies the core metric: click-through rate
// per post position, with a non-post (summary) click correctly ignored.
func TestDigestPositions_CTRByPosition(t *testing.T) {
	prefix := uniquePrefix("dpctr")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	now := time.Now()
	etype := uniqueDigestType()

	// Four digests, each showing three posts (positions 0,1,2 → shown = 4 each).
	idA := createDigestTrackingRecord(t, etype, now, 3)
	idB := createDigestTrackingRecord(t, etype, now, 3)
	idC := createDigestTrackingRecord(t, etype, now, 3)
	idD := createDigestTrackingRecord(t, etype, now, 3)
	defer cleanupTestTrackingByID([]uint64{idA, idB, idC, idD})

	// post_0 clicked in A and B; post_1 clicked in C; D only has a summary click.
	createPositionClick(t, idA, "post_0", now)
	createPositionClick(t, idB, "post_0", now)
	createPositionClick(t, idC, "post_1", now)
	createPositionClick(t, idD, "summary_0", now) // must NOT count towards post_0

	start := now.AddDate(0, 0, -1).Format("2006-01-02")
	end := now.AddDate(0, 0, 1).Format("2006-01-02")

	url := "/api/modtools/email/stats/digestpositions?jwt=" + token + "&type=" + etype + "&start=" + start + "&end=" + end
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	data, ok := result["data"].([]interface{})
	assert.True(t, ok)
	assert.Equal(t, 3, len(data)) // positions 0, 1, 2

	p0 := data[0].(map[string]interface{})
	assert.Equal(t, float64(0), p0["position"])
	assert.Equal(t, float64(4), p0["shown"])
	assert.Equal(t, float64(2), p0["emails_clicked"])
	assert.InDelta(t, 50.0, p0["ctr"].(float64), 0.01)

	p1 := data[1].(map[string]interface{})
	assert.Equal(t, float64(1), p1["position"])
	assert.Equal(t, float64(4), p1["shown"])
	assert.Equal(t, float64(1), p1["emails_clicked"])
	assert.InDelta(t, 25.0, p1["ctr"].(float64), 0.01)

	p2 := data[2].(map[string]interface{})
	assert.Equal(t, float64(2), p2["position"])
	assert.Equal(t, float64(4), p2["shown"])
	assert.Equal(t, float64(0), p2["emails_clicked"])
	assert.InDelta(t, 0.0, p2["ctr"].(float64), 0.01)
}

// TestDigestPositions_CompactPositionLabels verifies that the current compact
// card label ("pN") is counted the same as the legacy "post_N" label - both mean
// "clicked the card for the post at position N" - and that compact summary ("yN")
// and image ("iN") links are excluded. Without this the stat only sees legacy
// emails and silently under-reports, since compact "pN" clicks dominate.
func TestDigestPositions_CompactPositionLabels(t *testing.T) {
	prefix := uniquePrefix("dpcompact")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	now := time.Now()
	etype := uniqueDigestType()

	// Two digests, each showing two posts (positions 0,1 → shown = 2 each).
	idA := createDigestTrackingRecord(t, etype, now, 2)
	idB := createDigestTrackingRecord(t, etype, now, 2)
	defer cleanupTestTrackingByID([]uint64{idA, idB})

	// Compact card click on position 0 in A; legacy card click on position 0 in
	// B → position 0 should aggregate both schemes (emails_clicked = 2).
	createPositionClick(t, idA, "p0", now)
	createPositionClick(t, idB, "post_0", now)
	// Summary ("yN") and image ("iN") clicks must be ignored.
	createPositionClick(t, idA, "y1", now)
	createPositionClick(t, idA, "i1", now)

	start := now.AddDate(0, 0, -1).Format("2006-01-02")
	end := now.AddDate(0, 0, 1).Format("2006-01-02")

	url := "/api/modtools/email/stats/digestpositions?jwt=" + token + "&type=" + etype + "&start=" + start + "&end=" + end
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	data := result["data"].([]interface{})
	assert.Equal(t, 2, len(data)) // positions 0,1

	p0 := data[0].(map[string]interface{})
	assert.Equal(t, float64(0), p0["position"])
	assert.Equal(t, float64(2), p0["shown"])
	// Both the compact "p0" and legacy "post_0" clicks count.
	assert.Equal(t, float64(2), p0["emails_clicked"])
	assert.InDelta(t, 100.0, p0["ctr"].(float64), 0.01)

	p1 := data[1].(map[string]interface{})
	assert.Equal(t, float64(1), p1["position"])
	assert.Equal(t, float64(2), p1["shown"])
	// y1 (summary) and i1 (image) must NOT count as position-1 card clicks.
	assert.Equal(t, float64(0), p1["emails_clicked"])
}

// TestDigestPositions_CumulativeShownAcrossSizes verifies that the "shown"
// denominator is cumulative: a digest with K posts contributes to positions 0..K-1.
func TestDigestPositions_CumulativeShownAcrossSizes(t *testing.T) {
	prefix := uniquePrefix("dpcum")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	now := time.Now()
	etype := uniqueDigestType()

	// One digest with 2 posts, one with 4 posts.
	// shown[0]=2, shown[1]=2, shown[2]=1, shown[3]=1.
	idSmall := createDigestTrackingRecord(t, etype, now, 2)
	idBig := createDigestTrackingRecord(t, etype, now, 4)
	defer cleanupTestTrackingByID([]uint64{idSmall, idBig})

	// Click at the deepest position (post_3), only present in the big digest.
	createPositionClick(t, idBig, "post_3", now)

	start := now.AddDate(0, 0, -1).Format("2006-01-02")
	end := now.AddDate(0, 0, 1).Format("2006-01-02")

	url := "/api/modtools/email/stats/digestpositions?jwt=" + token + "&type=" + etype + "&start=" + start + "&end=" + end
	resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	data := result["data"].([]interface{})
	assert.Equal(t, 4, len(data)) // positions 0..3

	p0 := data[0].(map[string]interface{})
	assert.Equal(t, float64(2), p0["shown"])

	p2 := data[2].(map[string]interface{})
	assert.Equal(t, float64(1), p2["shown"])

	p3 := data[3].(map[string]interface{})
	assert.Equal(t, float64(3), p3["position"])
	assert.Equal(t, float64(1), p3["shown"])
	assert.Equal(t, float64(1), p3["emails_clicked"])
	assert.InDelta(t, 100.0, p3["ctr"].(float64), 0.01)
}

// =============================================================================
// Rollup (email_digest_positions_daily) behaviour
// =============================================================================

// uniqueRolledDigestType returns a unified digest email_type unique to this
// test run, so the rollup path (which only serves UnifiedDigest* types) can be
// exercised with an exact-type filter.
func uniqueRolledDigestType() string {
	dpSeq++
	return fmt.Sprintf("UnifiedDigestT%d%d", dpSeq, time.Now().UnixNano()%1000000)
}

// seedRollup writes rollup rows for one day: a marker row (so the day counts as
// summarised) plus one row per {shown, emails_clicked, clicks} triple, positions
// 0..n-1. Rows for the day are removed when the test ends.
func seedRollup(t *testing.T, day string, emailType string, counts [][3]int) {
	db := database.DBConn
	assert.NoError(t, db.Exec(
		"INSERT IGNORE INTO email_digest_positions_daily (day, email_type, position, shown, emails_clicked, clicks) VALUES (?, '', 0, 0, 0, 0)",
		day).Error)
	for pos, c := range counts {
		assert.NoError(t, db.Exec(
			"INSERT INTO email_digest_positions_daily (day, email_type, position, shown, emails_clicked, clicks) VALUES (?, ?, ?, ?, ?, ?)",
			day, emailType, pos, c[0], c[1], c[2]).Error)
	}
	t.Cleanup(func() {
		db.Exec("DELETE FROM email_digest_positions_daily WHERE day = ?", day)
	})
}

func getDigestPositions(t *testing.T, token string, query string) map[string]interface{} {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/modtools/email/stats/digestpositions?jwt="+token+"&"+query, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	return result
}

func supportToken(t *testing.T, name string) string {
	userID := CreateTestUser(t, uniquePrefix(name), "Support")
	_, token := CreateTestSession(t, userID)
	return token
}

func TestDigestPositions_SumsRollupWithoutLiveQueries(t *testing.T) {
	token := supportToken(t, "dproll")
	etype := uniqueRolledDigestType()

	seedRollup(t, "2019-03-10", etype, [][3]int{{10, 2, 3}, {8, 1, 1}})
	seedRollup(t, "2019-03-11", etype, [][3]int{{5, 1, 1}, {5, 0, 0}, {2, 1, 2}})
	seedRollup(t, "2019-03-12", etype, nil) // summarised, nothing sent

	result := getDigestPositions(t, token, "type="+etype+"&start=2019-03-10&end=2019-03-12")

	assert.Equal(t, float64(0), result["missing_days"])
	assert.Equal(t, float64(0), result["live_days"])
	data := result["data"].([]interface{})
	assert.Equal(t, 3, len(data))

	p0 := data[0].(map[string]interface{})
	assert.Equal(t, float64(15), p0["shown"])
	assert.Equal(t, float64(3), p0["emails_clicked"])
	assert.Equal(t, float64(4), p0["clicks"])
	assert.InDelta(t, 20.0, p0["ctr"].(float64), 0.01)

	p1 := data[1].(map[string]interface{})
	assert.Equal(t, float64(13), p1["shown"])
	assert.Equal(t, float64(1), p1["emails_clicked"])

	p2 := data[2].(map[string]interface{})
	assert.Equal(t, float64(2), p2["position"])
	assert.Equal(t, float64(2), p2["shown"])
	assert.InDelta(t, 50.0, p2["ctr"].(float64), 0.01)
}

func TestDigestPositions_RollupTypeFilter(t *testing.T) {
	token := supportToken(t, "dprolltype")
	typeA := uniqueRolledDigestType()
	typeB := uniqueRolledDigestType()

	seedRollup(t, "2019-04-10", typeA, [][3]int{{10, 1, 1}})
	// typeB shares the day: its rows must not leak into an exact-type request
	// for A, and both must be summed when no type is given.
	assert.NoError(t, database.DBConn.Exec(
		"INSERT INTO email_digest_positions_daily (day, email_type, position, shown, emails_clicked, clicks) VALUES ('2019-04-10', ?, 0, 4, 2, 2)",
		typeB).Error)

	only := getDigestPositions(t, token, "type="+typeA+"&start=2019-04-10&end=2019-04-10")
	assert.Equal(t, float64(10), only["data"].([]interface{})[0].(map[string]interface{})["shown"])

	all := getDigestPositions(t, token, "start=2019-04-10&end=2019-04-10")
	assert.Equal(t, float64(0), all["missing_days"])
	data := all["data"].([]interface{})
	assert.Equal(t, float64(14), data[0].(map[string]interface{})["shown"])
	assert.Equal(t, float64(3), data[0].(map[string]interface{})["emails_clicked"])
}

func TestDigestPositions_FillsUnsummarisedRecentDaysLive(t *testing.T) {
	token := supportToken(t, "dprolllive")
	etype := uniqueRolledDigestType()
	now := time.Now()
	yesterday := now.AddDate(0, 0, -1).Format("2006-01-02")
	today := now.Format("2006-01-02")

	// Yesterday is summarised; today is only in email_tracking.
	seedRollup(t, yesterday, etype, [][3]int{{6, 1, 1}, {6, 0, 0}})
	id := createDigestTrackingRecord(t, etype, now, 3)
	defer cleanupTestTrackingByID([]uint64{id})
	createPositionClick(t, id, "p0", now)

	result := getDigestPositions(t, token, "type="+etype+"&start="+yesterday+"&end="+today)

	assert.Equal(t, float64(0), result["missing_days"])
	assert.Equal(t, float64(1), result["live_days"])
	data := result["data"].([]interface{})
	assert.Equal(t, 3, len(data))
	p0 := data[0].(map[string]interface{})
	assert.Equal(t, float64(7), p0["shown"])          // 6 rolled up + 1 live
	assert.Equal(t, float64(2), p0["emails_clicked"]) // 1 + 1
	assert.Equal(t, float64(1), data[2].(map[string]interface{})["shown"])
}

func TestDigestPositions_CapsLiveDaysAndReportsMissing(t *testing.T) {
	token := supportToken(t, "dprollcap")
	etype := uniqueRolledDigestType()

	// Six days, none summarised: the three most recent are computed live and
	// the other three are reported, not computed.
	result := getDigestPositions(t, token, "type="+etype+"&start=2019-05-01&end=2019-05-06")

	assert.Equal(t, float64(3), result["live_days"])
	assert.Equal(t, float64(3), result["missing_days"])
	assert.Equal(t, 0, len(result["data"].([]interface{})))
}

func TestDigestPositions_RollupDoesNotCoverPartialDays(t *testing.T) {
	token := supportToken(t, "dprollpart")
	etype := uniqueRolledDigestType()

	seedRollup(t, "2019-06-10", etype, [][3]int{{10, 5, 5}})

	// A range that starts mid-day cannot be answered from whole-day rows.
	result := getDigestPositions(t, token, "type="+etype+"&start=2019-06-10%2012:00:00&end=2019-06-10")

	assert.Equal(t, float64(1), result["live_days"])
	assert.Equal(t, 0, len(result["data"].([]interface{})))
}

func TestDigestPositions_FutureDaysAreNotMissing(t *testing.T) {
	token := supportToken(t, "dprollfuture")
	etype := uniqueRolledDigestType()
	now := time.Now()

	result := getDigestPositions(t, token, "type="+etype+
		"&start="+now.Format("2006-01-02")+"&end="+now.AddDate(0, 0, 10).Format("2006-01-02"))

	assert.Equal(t, float64(0), result["missing_days"])
	assert.Equal(t, float64(1), result["live_days"]) // today only
}

func TestDigestPositions_NonDigestTypeStaysLive(t *testing.T) {
	token := supportToken(t, "dprollother")
	etype := uniqueDigestType() // not a UnifiedDigest* name: never rolled up

	result := getDigestPositions(t, token, "type="+etype+"&start=2019-07-01&end=2019-07-06")

	assert.Equal(t, float64(0), result["missing_days"])
	assert.Equal(t, float64(6), result["live_days"])
}
