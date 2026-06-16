package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// MessageOriginGroup must return the FIRST group a message was posted to (earliest
// messages_groups arrival), so that only that group's rejection notifies the poster
// and a secondary (rippled-in) group's rejection stays silent (#6).
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

	assert.Equal(t, group1, message.MessageOriginGroup(db, mid), "origin = earliest-arrival group")
	assert.Equal(t, uint64(0), message.MessageOriginGroup(db, 999999999), "no group rows → 0")
}
