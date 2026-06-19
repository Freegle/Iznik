package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
)

// Rippling-out makes a post's reach follow the poster's declared location, so rapidly changing
// location is the new way to push posts into unrelated areas. CheckLocationChangeVelocity flags a
// user for moderator review (non-destructively) when they set too many distinct postcodes in 24h.
func TestLocationChangeVelocityFlagsRapidHopper(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("locvel")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")

	db.Exec("UPDATE memberships SET reviewrequestedat = NULL, reviewreason = NULL WHERE userid = ?", userID)
	db.Exec("DELETE FROM logs WHERE user = ? AND subtype IN ('PostcodeChange', 'Suspect')", userID)
	defer db.Exec("DELETE FROM logs WHERE user = ? AND subtype IN ('PostcodeChange', 'Suspect')", userID)

	seed := func(name string) {
		db.Exec("INSERT INTO logs (type, subtype, user, byuser, text, timestamp) "+
			"VALUES ('User', 'PostcodeChange', ?, ?, ?, NOW())", userID, userID, name)
	}
	flaggedCount := func() int {
		var n int
		db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND reviewrequestedat IS NOT NULL", userID).Scan(&n)
		return n
	}

	// Below threshold (2 distinct postcodes) -> not flagged.
	seed("AB1 1AA")
	seed("AB2 2BB")
	user.CheckLocationChangeVelocity(db, userID)
	assert.Equal(t, 0, flaggedCount(), "two location changes is not enough to flag")

	// At threshold (4 distinct postcodes in 24h) -> flagged for review + a Suspect log written.
	seed("AB3 3CC")
	seed("AB4 4DD")
	user.CheckLocationChangeVelocity(db, userID)
	assert.Greater(t, flaggedCount(), 0, "four distinct location changes in 24h flags the user for review")

	var suspect int
	db.Raw("SELECT COUNT(*) FROM logs WHERE user = ? AND type = 'User' AND subtype = 'Suspect'", userID).Scan(&suspect)
	assert.Greater(t, suspect, 0, "a Suspect log is written for mods")
}

// A moderator is never flagged, however often they change location.
func TestLocationChangeVelocitySkipsMods(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("locvelmod")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, modID, groupID, "Moderator")

	db.Exec("UPDATE memberships SET reviewrequestedat = NULL WHERE userid = ?", modID)
	db.Exec("DELETE FROM logs WHERE user = ? AND subtype IN ('PostcodeChange', 'Suspect')", modID)
	defer db.Exec("DELETE FROM logs WHERE user = ? AND subtype IN ('PostcodeChange', 'Suspect')", modID)

	for _, pc := range []string{"AB1 1AA", "AB2 2BB", "AB3 3CC", "AB4 4DD", "AB5 5EE"} {
		db.Exec("INSERT INTO logs (type, subtype, user, byuser, text, timestamp) "+
			"VALUES ('User', 'PostcodeChange', ?, ?, ?, NOW())", modID, modID, pc)
	}
	user.CheckLocationChangeVelocity(db, modID)

	var flagged int
	db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND reviewrequestedat IS NOT NULL", modID).Scan(&flagged)
	assert.Equal(t, 0, flagged, "moderators are never flagged for location velocity")
}
