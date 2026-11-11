package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func searchMembersAs(t *testing.T, modToken string, term string) []uint64 {
	request := httptest.NewRequest("GET", "/api/modtools/members?filter=search&q="+term+"&jwt="+modToken, nil)
	resp, err := getApp().Test(request)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var body struct {
		Members []struct {
			ID uint64 `json:"id"`
		} `json:"members"`
	}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&body))
	ids := make([]uint64, 0, len(body.Members))
	for _, m := range body.Members {
		ids = append(ids, m.ID)
	}
	return ids
}

// The member search finds a member by name and by the start of an email address. The address is
// in users_emails, not on users.
func TestListMembersSearchFindsByNameAndEmail(t *testing.T) {
	prefix := uniquePrefix("memsearch")
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)
	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)

	assert.Contains(t, searchMembersAs(t, modToken, prefix+"_member"), memberID, "found by name")
	assert.Contains(t, searchMembersAs(t, modToken, prefix+"_member@"), memberID, "found by the start of the email address")
	assert.NotContains(t, searchMembersAs(t, modToken, prefix+"_nobody"), memberID)
}

func banMemberStatus(t *testing.T, token string, userID uint64) int {
	request := httptest.NewRequest("POST", fmt.Sprintf("/api/modtools/members/%d/ban?jwt=%s", userID, token), nil)
	resp, err := getApp().Test(request)
	assert.NoError(t, err)
	return resp.StatusCode
}

// A ban needs a real, ordinary target: not oneself, not a missing member, and only an admin
// may ban a moderator, support or admin account.
func TestBanMemberRefusesBadTargets(t *testing.T) {
	prefix := uniquePrefix("bantarget")
	db := database.DBConn
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)
	otherModID := CreateTestUser(t, prefix+"_mod2", utils.SYSTEMROLE_MODERATOR)
	adminID := CreateTestUser(t, prefix+"_admin", utils.SYSTEMROLE_ADMIN)
	_, adminToken := CreateTestSession(t, adminID)

	assert.Equal(t, fiber.StatusBadRequest, banMemberStatus(t, modToken, modID), "no self-ban")
	assert.Equal(t, fiber.StatusNotFound, banMemberStatus(t, modToken, 999999999), "a member that does not exist")
	assert.Equal(t, fiber.StatusForbidden, banMemberStatus(t, modToken, adminID), "a moderator cannot ban an admin")
	assert.Equal(t, fiber.StatusForbidden, banMemberStatus(t, modToken, otherModID), "a moderator cannot ban a moderator")

	var banned *string
	db.Raw("SELECT banned FROM users WHERE id = ?", adminID).Scan(&banned)
	assert.Nil(t, banned)
	db.Raw("SELECT banned FROM users WHERE id = ?", otherModID).Scan(&banned)
	assert.Nil(t, banned)

	assert.Equal(t, fiber.StatusOK, banMemberStatus(t, adminToken, otherModID), "an admin can ban a moderator")
}
