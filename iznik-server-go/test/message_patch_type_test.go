package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func patchType(t *testing.T, token string, msgID uint64, msgType string) int {
	body := fmt.Sprintf(`{"id":%d,"type":%q}`, msgID, msgType)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	return resp.StatusCode
}

// Browse and the map read the type from messages_spatial, so a member changing their post
// from an Offer to a Wanted must reach that table.
func TestPatchMessageTypeChangeUpdatesSpatialRow(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("patch_type_spatial")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, ownerID, groupID, utils.ROLE_MEMBER)
	_, token := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, groupID, "OFFER: Table "+prefix, 55.0, -1.0)

	require.Equal(t, 200, patchType(t, token, msgID, "Wanted"))

	var spatialType string
	db.Raw("SELECT msgtype FROM messages_spatial WHERE msgid = ?", msgID).Scan(&spatialType)
	assert.Equal(t, "Wanted", spatialType, "the spatial row follows the post's type")
}

// A member's edit changes a post between Offer and Wanted only. The other ENUM values are
// states and kinds the member cannot choose.
func TestPatchMessageTypeRefusesOtherValues(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("patch_type_bad")

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, ownerID, groupID, utils.ROLE_MEMBER)
	_, token := CreateTestSession(t, ownerID)
	msgID := CreateTestMessage(t, ownerID, groupID, "OFFER: Chair "+prefix, 55.0, -1.0)

	assert.Equal(t, 400, patchType(t, token, msgID, "Admin"))

	var msgType string
	db.Raw("SELECT type FROM messages WHERE id = ?", msgID).Scan(&msgType)
	assert.Equal(t, "Offer", msgType, "the type is unchanged")
}
