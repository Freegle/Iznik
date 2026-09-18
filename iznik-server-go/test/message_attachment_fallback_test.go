package test

// TestFetchMessageAttachments_FallsBackWhenEnrichedQueryFails proves that when
// the AI-masking join used to fetch a message's attachments fails - e.g. the
// schema mismatch that made ai_images.status unknown to production for a
// period (PR #305, "restore photo display on posts and modtools") - the real
// photo is still returned instead of silently vanishing.
//
// Root cause: FetchMessageAttachments's enriched query discarded the *gorm.DB
// returned by Scan without checking .Error. GORM leaves the destination slice
// empty on a query error with nothing else to show for it, so a message with a
// real, undamaged attachment row would report zero attachments to both
// ModTools and the public site - exactly what Jeni reported on topic 10050
// post 4 after an outage.
//
// AssertFlip: this test targets the fixed behaviour, so it fails if the
// result.Error check in FetchMessageAttachments is removed - reverting to the
// old code returns zero attachments whenever the enriched query errors.

import (
	"strconv"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

func TestFetchMessageAttachments_FallsBackWhenEnrichedQueryFails(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("attachFallback")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	msgID := CreateTestMessage(t, ownerID, groupID, "OFFER: Sofa "+prefix, 55.0, -1.0)

	uid := "freegletusd-" + prefix
	db.Exec("INSERT INTO messages_attachments (msgid, externaluid, `primary`) VALUES (?, ?, 1)", msgID, uid)

	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", msgID)
	})

	msgIDStr := strconv.FormatUint(msgID, 10)

	// A nonexistent join table forces the enriched query to fail exactly like a
	// real schema mismatch would, without touching the real ai_images table
	// that every other test relies on.
	attachments := message.FetchMessageAttachments(db, msgIDStr, "ai_images_missing_for_test_seam")

	if assert.Len(t, attachments, 1, "a real attachment must survive a failure in the AI-masking join") {
		assert.Equal(t, uid, attachments[0].Externaluid)
	}

	// Sanity check: the normal (production) join table still works and returns
	// the same attachment via the enriched path.
	normal := message.FetchMessageAttachments(db, msgIDStr, "ai_images")
	if assert.Len(t, normal, 1) {
		assert.Equal(t, uid, normal[0].Externaluid)
	}
}
