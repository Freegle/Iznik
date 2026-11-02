package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// A reported ChitChat thread carries reviewrequired = 1. The feed used to drop
// those for everyone, so the moderators it was reported to could only reach it
// through the link in the report. ChitChat moderators must see it in the feed;
// ordinary members must still not.
func TestFeedReportedThreadVisibleToChitChatModOnly(t *testing.T) {
	prefix := uniquePrefix("reportedfeed")
	authorID, _ := CreateFullTestUser(t, prefix+"a")
	modID, modToken := CreateFullTestUser(t, prefix+"m")
	_, memberToken := CreateFullTestUser(t, prefix+"u")
	makeChitChatMod(t, modID)

	// Same location as the test users (Edinburgh).
	id := CreateTestNewsfeed(t, authorID, 55.9533, -3.1883, "Reported thread "+prefix)
	if err := database.DBConn.Exec("UPDATE newsfeed SET reviewrequired = 1 WHERE id = ?", id).Error; err != nil {
		t.Fatalf("failed to mark thread reported: %v", err)
	}

	assert.True(t, feedIDs(t, modToken, "anywhere")[id],
		"a ChitChat moderator must see a reported thread in the feed")
	assert.False(t, feedIDs(t, memberToken, "anywhere")[id],
		"an ordinary member must not see a reported thread")
}
