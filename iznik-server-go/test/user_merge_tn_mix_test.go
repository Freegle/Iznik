package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// The shared merge refuses a TrashNothing member with a Freegle account, so the callers that
// do not go through the moderator merge (the TN divergence heal, the email-verify link) cannot
// give a TrashNothing account a volunteer's role. A volunteer's non-preferred TrashNothing
// address does not make them a TrashNothing member.
func TestMergeUsersTxRefusesTrashNothingWithFreegleAccount(t *testing.T) {
	prefix := uniquePrefix("merge_tx_mix")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	volunteer := CreateTestUser(t, prefix+"_vol", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, volunteer, groupID, utils.ROLE_MODERATOR)
	db.Exec("INSERT INTO users_emails (userid, email, preferred) VALUES (?, ?, 0)", volunteer, prefix+"-g3@user.trashnothing.com")

	tn := CreateTestUser(t, prefix+"_tn", utils.SYSTEMROLE_USER)
	stampTN(tn, 991005)
	CreateTestMembership(t, tn, groupID, utils.ROLE_MEMBER)

	err := user.MergeUsersTx(db, volunteer, tn, tn)
	var refusal *fiber.Error
	if assert.ErrorAs(t, err, &refusal, "the volunteer is not merged into the TrashNothing account") {
		assert.Equal(t, fiber.StatusBadRequest, refusal.Code, "the merge is refused as a bad request")
	}
	assert.True(t, userExists(volunteer))

	var role string
	db.Raw("SELECT role FROM memberships WHERE userid = ? AND groupid = ?", tn, groupID).Scan(&role)
	assert.Equal(t, utils.ROLE_MEMBER, role, "the TrashNothing account does not gain the moderator role")

	err = user.MergeUsersTx(db, tn, volunteer, volunteer)
	if assert.ErrorAs(t, err, &refusal, "nor the other way round") {
		assert.Equal(t, fiber.StatusBadRequest, refusal.Code)
	}
	assert.True(t, userExists(tn))
}
