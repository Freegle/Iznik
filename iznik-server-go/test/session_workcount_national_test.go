package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// The ModTools badge counts are national. The test database is shared, so each test measures the
// change its own rows cause rather than an absolute number.

func workCounter(t *testing.T, token string, key string) func() float64 {
	return func() float64 {
		return getSessionWork(t, token)[key].(float64)
	}
}

func workCountModerator(t *testing.T, prefix string) string {
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, token := CreateTestSession(t, modID)
	return token
}

func insertPendingEvent(t *testing.T, ownerID uint64, title string, startOffset string, endOffset string, deleted int) uint64 {
	db := database.DBConn
	db.Exec("INSERT INTO communityevents (userid, title, description, location, pending, deleted) VALUES (?, ?, 'Description', '', 1, ?)", ownerID, title, deleted)
	var id uint64
	db.Raw("SELECT id FROM communityevents WHERE userid = ? AND title = ? ORDER BY id DESC LIMIT 1", ownerID, title).Scan(&id)
	db.Exec("INSERT INTO communityevents_dates (eventid, start, end) VALUES (?, DATE_ADD(NOW(), INTERVAL "+startOffset+"), DATE_ADD(NOW(), INTERVAL "+endOffset+"))", id)
	t.Cleanup(func() {
		db.Exec("DELETE FROM communityevents_dates WHERE eventid = ?", id)
		db.Exec("DELETE FROM communityevents WHERE id = ?", id)
	})
	return id
}

func TestWorkCountPendingEventsCountsFutureLiveEventsOnly(t *testing.T) {
	prefix := uniquePrefix("wc_events")
	token := workCountModerator(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	count := workCounter(t, token, "pendingevents")

	before := count()
	insertPendingEvent(t, ownerID, prefix+" future", "7 DAY", "8 DAY", 0)
	assert.Equal(t, before+1, count(), "a pending event still to come is counted")

	insertPendingEvent(t, ownerID, prefix+" past", "-3 DAY", "-2 DAY", 0)
	assert.Equal(t, before+1, count(), "an event that has finished is not counted")

	insertPendingEvent(t, ownerID, prefix+" deleted", "7 DAY", "8 DAY", 1)
	assert.Equal(t, before+1, count(), "a deleted event is not counted")
}

func TestWorkCountPendingVolunteeringHeldIsBlueNotRed(t *testing.T) {
	prefix := uniquePrefix("wc_vol")
	db := database.DBConn
	token := workCountModerator(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	holderID := CreateTestUser(t, prefix+"_holder", utils.SYSTEMROLE_USER)
	red := workCounter(t, token, "pendingvolunteering")
	blue := workCounter(t, token, "pendingvolunteeringother")
	redBefore, blueBefore := red(), blue()

	db.Exec("INSERT INTO volunteering (userid, title, description, location, pending, deleted, expired) VALUES (?, ?, 'Description', '', 1, 0, 0)", ownerID, prefix+" vol")
	var volID uint64
	db.Raw("SELECT id FROM volunteering WHERE userid = ? ORDER BY id DESC LIMIT 1", ownerID).Scan(&volID)
	db.Exec("INSERT INTO volunteering_dates (volunteeringid, start, end) VALUES (?, DATE_ADD(NOW(), INTERVAL 7 DAY), DATE_ADD(NOW(), INTERVAL 14 DAY))", volID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM volunteering_dates WHERE volunteeringid = ?", volID)
		db.Exec("DELETE FROM volunteering WHERE id = ?", volID)
	})

	assert.Equal(t, redBefore+1, red(), "an unheld pending opportunity is red")
	assert.Equal(t, blueBefore, blue())

	db.Exec("UPDATE volunteering SET heldby = ? WHERE id = ?", holderID, volID)
	assert.Equal(t, redBefore, red(), "held leaves red")
	assert.Equal(t, blueBefore+1, blue(), "held joins blue")

	db.Exec("UPDATE volunteering SET heldby = NULL, expired = 1 WHERE id = ?", volID)
	assert.Equal(t, redBefore, red(), "an expired opportunity is not counted")
	assert.Equal(t, blueBefore, blue())
}

func TestWorkCountSpamMembersFlaggedReviewedAndReFlagged(t *testing.T) {
	prefix := uniquePrefix("wc_spamm")
	db := database.DBConn
	token := workCountModerator(t, prefix)
	count := workCounter(t, token, "spammembers")
	before := count()

	spamID := CreateTestUser(t, prefix+"_spam", utils.SYSTEMROLE_USER)
	db.Exec("UPDATE users SET reviewrequestedat = NOW() WHERE id = ?", spamID)
	assert.Equal(t, before+1, count(), "a flagged member is counted")

	db.Exec("UPDATE users SET reviewedat = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE id = ?", spamID)
	assert.Equal(t, before, count(), "a reviewed member is not counted")

	db.Exec("UPDATE users SET reviewedat = DATE_SUB(NOW(), INTERVAL 5 DAY), reviewrequestedat = NOW() WHERE id = ?", spamID)
	assert.Equal(t, before+1, count(), "a member re-flagged after the last review is counted again")

	db.Exec("UPDATE users SET deleted = NOW() WHERE id = ?", spamID)
	assert.Equal(t, before, count(), "a deleted member is not counted")
}

func TestWorkCountEditReviewCountsDistinctRecentMessages(t *testing.T) {
	prefix := uniquePrefix("wc_editdist")
	db := database.DBConn
	token := workCountModerator(t, prefix)
	count := workCounter(t, token, "editreview")
	before := count()

	userID := CreateTestUser(t, prefix+"_u", utils.SYSTEMROLE_USER)
	msgID := CreateTestMessage(t, userID, prefix+" edit", 52.2, -0.1)
	t.Cleanup(func() { db.Exec("DELETE FROM messages_edits WHERE msgid = ?", msgID) })

	db.Exec("INSERT INTO messages_edits (msgid, oldsubject, newsubject, reviewrequired, timestamp) VALUES (?, 'Old1', 'New1', 1, NOW())", msgID)
	db.Exec("INSERT INTO messages_edits (msgid, oldsubject, newsubject, reviewrequired, timestamp) VALUES (?, 'Old2', 'New2', 1, NOW())", msgID)
	assert.Equal(t, before+1, count(), "two edits on one message count once")

	db.Exec("UPDATE messages_edits SET timestamp = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE msgid = ?", msgID)
	assert.Equal(t, before, count(), "an edit older than a week is not counted")
}
