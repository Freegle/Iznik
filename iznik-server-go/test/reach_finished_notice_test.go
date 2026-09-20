package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// A reach the engine has FINISHED (status 'done': the extent governor or the schedule's
// last tick stopped it) will never grow to a member it does not cover. Telling that
// member the post "hasn't reached your area yet" with an arrival date is wrong twice
// over: the date is the final tick's, already in the past, so the client says "any
// moment now" about a reach that ended weeks ago (Discourse 9808/797, a Tower Hamlets
// member three road miles from a Stepney armchair whose reach finished on 11 August).
//
// The API says so instead: reachfinished=true and no reachesyouat, so the client can
// say the reach has ended and the reply goes straight on.
func TestReachFinishedNotice(t *testing.T) {
	t.Setenv("RIPPLE_ENABLED", "true")
	db := database.DBConn

	prefix := uniquePrefix("reachdone")
	posterID := CreateTestUser(t, prefix, "Poster")
	group := CreateTestGroup(t, prefix)
	mid := CreateTestMessage(t, posterID, group, "OFFER: reach finished test", 51.5, -0.1)

	viewerID := CreateTestUser(t, prefix+"v", "Viewer")
	db.Exec("UPDATE users SET settings = JSON_SET(COALESCE(settings,'{}'), '$.mylocation', "+
		"JSON_OBJECT('lat', 51.5, 'lng', -0.1)) WHERE id = ?", viewerID)

	// Two ticks, the second going live a day after arrival - both long past.
	schedule := `[{"tick":1,"drive_min":15,"cumulative_users":2800},{"tick":2,"drive_min":15.8,"cumulative_users":4000}]`
	db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)
	db.Exec("INSERT INTO rippling_reach (msgid, lat, lng, outer_bound, status, tick, total_ticks, arrival, schedule) "+
		"VALUES (?, 51.5, -0.1, ST_Envelope(ST_GeomFromText('POLYGON((2.4 53.4, 2.6 53.4, 2.6 53.6, 2.4 53.6, 2.4 53.4))', 3857)), "+
		"'done', 2, 2, NOW() - INTERVAL 30 DAY, ?)", mid, schedule)
	defer db.Exec("DELETE FROM rippling_reach WHERE msgid = ?", mid)

	stubReachEvalMax(t, "out")
	idStr := fmt.Sprint(mid)

	msgs := message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) {
		if assert.NotNil(t, msgs[0].ReplyEligible, "outside a finished reach is still outside it") {
			assert.False(t, *msgs[0].ReplyEligible)
		}
		if assert.NotNil(t, msgs[0].ReachFinished, "a finished reach says so") {
			assert.True(t, *msgs[0].ReachFinished)
		}
		assert.Nil(t, msgs[0].ReachesYouAt, "and promises no arrival: there is none coming")
	}

	// Still expanding: the arrival estimate is what the member gets, not the finished flag.
	db.Exec("UPDATE rippling_reach SET status = 'expanding', tick = 1 WHERE msgid = ?", mid)
	msgs = message.GetMessagesByIds(viewerID, []string{idStr}, false)
	if assert.Len(t, msgs, 1) {
		assert.Nil(t, msgs[0].ReachFinished, "an expanding reach is not finished")
		assert.NotNil(t, msgs[0].ReachesYouAt, "and carries the estimate")
	}
}
