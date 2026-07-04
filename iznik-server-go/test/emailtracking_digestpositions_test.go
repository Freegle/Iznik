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

// createDigestTrackingRecordForUser is like createDigestTrackingRecord but sets
// the recipient userid, so cohort filtering (userid % 10) can be exercised.
func createDigestTrackingRecordForUser(t *testing.T, emailType string, sentAt time.Time, numPosts int, userid uint64) uint64 {
	db := database.DBConn
	id := createDigestTrackingRecord(t, emailType, sentAt, numPosts)
	db.Exec("UPDATE email_tracking SET userid = ? WHERE id = ?", userid, id)
	return id
}

// TestDigestPositions_CohortSplit verifies the ranked/holdout cohort filter
// (userid % 10) partitions the click data: a holdout recipient's clicks appear
// only under cohort=holdout, a ranked recipient's only under cohort=ranked, and
// both under no cohort.
func TestDigestPositions_CohortSplit(t *testing.T) {
	prefix := uniquePrefix("dpcohort")
	userID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, userID)

	now := time.Now()
	etype := uniqueDigestType()

	// Synthetic high userids with no matching users row (LEFT JOIN → tnuserid
	// NULL → passes the cohort's tnuserid filter). 990000000 % 10 == 0 (holdout);
	// 990000001 % 10 == 1 (ranked).
	const holdoutUser = uint64(990000000)
	const rankedUser = uint64(990000001)

	// Holdout recipient: 2-post digest, clicks position 0.
	idHold := createDigestTrackingRecordForUser(t, etype, now, 2, holdoutUser)
	// Ranked recipient: 2-post digest, clicks position 1.
	idRank := createDigestTrackingRecordForUser(t, etype, now, 2, rankedUser)
	defer cleanupTestTrackingByID([]uint64{idHold, idRank})

	createPositionClick(t, idHold, "post_0", now)
	createPositionClick(t, idRank, "post_1", now)

	start := now.AddDate(0, 0, -1).Format("2006-01-02")
	end := now.AddDate(0, 0, 1).Format("2006-01-02")
	base := "/api/modtools/email/stats/digestpositions?jwt=" + token + "&type=" + etype + "&start=" + start + "&end=" + end

	// clicksAt returns emails_clicked at a given position for a cohort query.
	clicksAt := func(cohort string, pos int) float64 {
		url := base
		if cohort != "" {
			url += "&cohort=" + cohort
		}
		resp, _ := getApp().Test(httptest.NewRequest("GET", url, nil))
		assert.Equal(t, 200, resp.StatusCode)
		var result map[string]interface{}
		json2.Unmarshal(rsp(resp), &result)
		data, _ := result["data"].([]interface{})
		if pos >= len(data) {
			return 0
		}
		p := data[pos].(map[string]interface{})
		return p["emails_clicked"].(float64)
	}

	// Holdout cohort sees only the holdout recipient's position-0 click.
	assert.Equal(t, float64(1), clicksAt("holdout", 0), "holdout: pos0 clicked")
	assert.Equal(t, float64(0), clicksAt("holdout", 1), "holdout: pos1 not clicked (that was the ranked user)")

	// Ranked cohort sees only the ranked recipient's position-1 click.
	assert.Equal(t, float64(0), clicksAt("ranked", 0), "ranked: pos0 not clicked (that was the holdout user)")
	assert.Equal(t, float64(1), clicksAt("ranked", 1), "ranked: pos1 clicked")

	// No cohort sees both.
	assert.Equal(t, float64(1), clicksAt("", 0), "all: pos0 clicked")
	assert.Equal(t, float64(1), clicksAt("", 1), "all: pos1 clicked")
}
