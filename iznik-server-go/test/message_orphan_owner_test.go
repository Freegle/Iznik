package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// A post whose author row has gone has no owner. An anonymous reader (user id 0) must not be treated
// as that owner: the precise location and the promises stay private.
func TestMessageWithNoAuthorIsNotOwnedByAnAnonymousViewer(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("orphan_owner")
	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	promiseeID := CreateTestUser(t, prefix+"_promisee", utils.SYSTEMROLE_USER)

	msgID := CreateTestMessage(t, posterID, groupID, "OFFER: orphan "+prefix+" (Test)", 52.5, -1.8)
	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Postcode', 52.5, -1.8)", prefix+"_PC")
	var locID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", prefix+"_PC").Scan(&locID)
	db.Exec("UPDATE messages SET locationid = ? WHERE id = ?", locID, msgID)
	db.Exec("INSERT INTO messages_promises (msgid, userid) VALUES (?, ?)", msgID, promiseeID)
	t.Cleanup(func() {
		db.Exec("DELETE FROM messages_promises WHERE msgid = ?", msgID)
	})

	// The author's row is gone: messages.fromuser is set NULL when the user is deleted.
	assert.NoError(t, db.Exec("UPDATE messages SET fromuser = NULL WHERE id = ?", msgID).Error)

	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d", msgID), nil))
	assert.Equal(t, 200, resp.StatusCode)

	var msg map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&msg)

	_, hasLoc := msg["location"]
	assert.False(t, hasLoc, "an anonymous viewer must not see the precise location")

	promises, _ := msg["promises"].([]interface{})
	assert.Empty(t, promises, "an anonymous viewer must not see the promises")
}
