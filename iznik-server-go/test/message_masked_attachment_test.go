package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// A suppressed, rejected or regenerating AI picture is masked: the frontend shows the standard
// no-photo placeholder when an attachment has neither an ouruid nor a path. The path loop must not
// hand such an attachment the legacy img_<id>.jpg URL, which would be a dead link.
func TestMessageMaskedAIAttachmentHasNoPath(t *testing.T) {
	db := database.DBConn

	for _, status := range []string{"rejected", "regenerating", "suppressed"} {
		t.Run(status, func(t *testing.T) {
			prefix := uniquePrefix("masked" + status)

			groupID := CreateTestGroup(t, prefix)
			ownerID := CreateTestUser(t, prefix+"_owner", "User")
			CreateTestMembership(t, ownerID, groupID, "Member")
			msgID := CreateTestMessage(t, ownerID, groupID, "Test item "+prefix, 55.0, -1.0)

			aiName := "masked-" + prefix
			aiUID := "freegletusd-test-" + aiName
			db.Exec("INSERT INTO ai_images (name, externaluid, usage_count, status) VALUES (?, ?, 1, ?)", aiName, aiUID, status)
			var aiImageID uint64
			db.Raw("SELECT id FROM ai_images WHERE name = ? ORDER BY id DESC LIMIT 1", aiName).Scan(&aiImageID)
			assert.NotZero(t, aiImageID)

			db.Exec("INSERT INTO messages_attachments (msgid, externaluid, externalmods, `primary`) VALUES (?, ?, '{\"ai\":true}', 1)", msgID, aiUID)
			var attID uint64
			db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&attID)
			assert.NotZero(t, attID)

			t.Cleanup(func() {
				db.Exec("DELETE FROM ai_images WHERE id = ?", aiImageID)
				db.Exec("DELETE FROM messages_attachments WHERE id = ?", attID)
			})

			msgDetails := message.GetMessagesByIds(ownerID, []string{fmt.Sprint(msgID)}, false)[0]
			assert.Equal(t, 1, len(msgDetails.MessageAttachments))

			att := msgDetails.MessageAttachments[0]
			assert.Equal(t, "", att.Externaluid)
			assert.Equal(t, "", att.Ouruid)
			assert.Equal(t, "", att.Path)
			assert.Equal(t, "", att.Paththumb)
		})
	}
}
