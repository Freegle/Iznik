package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// MessageOriginGroup must return the group a message was first posted to (its arrival
// matches the message), so only that group's rejection notifies the poster, and a
// secondary (rippled-in) group's rejection stays silent (#6). It must NOT mis-attribute
// origin when the true origin row has been hard-deleted.
func TestMessageOriginGroup(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("origin")
	userID := CreateTestUser(t, prefix, "User")
	group1 := CreateTestGroup(t, prefix+"a") // origin — CreateTestMessage sets arrival = NOW()
	group2 := CreateTestGroup(t, prefix+"b") // rippled in later

	mid := CreateTestMessage(t, userID, group1, "OFFER: origin test item", 51.5, -0.1)

	// Rippled into a second group an hour later.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, arrival, collection, autoreposts) "+
		"VALUES (?, ?, NOW() + INTERVAL 1 HOUR, 'Approved', 0)", mid, group2)

	// Origin = the earliest-arriving group whose arrival matches the message.
	assert.Equal(t, group1, message.MessageOriginGroup(db, mid))

	// A plain-delete rejection SOFT-deletes the origin row (deleted=1); it still persists
	// and is still correctly identified as origin (so a later secondary reject stays silent).
	db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid = ?", mid, group1)
	assert.Equal(t, group1, message.MessageOriginGroup(db, mid), "soft-deleted origin still matched")

	// HARD-deleting the origin row (handleDeleteMessage/handleMove) leaves only the later
	// rippled-in group, which fails the arrival match → 0, so the caller notifies all
	// groups rather than mis-attributing origin to a secondary group.
	db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid = ?", mid, group1)
	assert.Equal(t, uint64(0), message.MessageOriginGroup(db, mid), "hard-deleted origin → 0 (safe fallback)")

	// No group rows at all → 0.
	assert.Equal(t, uint64(0), message.MessageOriginGroup(db, 999999999), "no rows → 0")
}
