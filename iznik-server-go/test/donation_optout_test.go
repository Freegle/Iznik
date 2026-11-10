package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	user2 "github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// A donor can ask never to be asked to donate again. Support needs to see whether someone has
// donated to show them that switch, without seeing the donations themselves, which stay behind
// PERM_GIFTAID.

func getUserAs(t *testing.T, targetID uint64, token string) user2.User {
	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/user/%d?modtools=true&jwt=%s", targetID, token), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var u user2.User
	json.NewDecoder(resp.Body).Decode(&u)
	return u
}

func TestDonorFlagVisibleToSupportWithoutDonationList(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("donorflag")

	donorID := CreateTestUser(t, prefix+"_donor", utils.SYSTEMROLE_USER)
	db.Exec("INSERT INTO users_donations (type, userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount, source) VALUES ('Stripe', ?, ?, 'Donor', '2019-01-01 10:00:00', ?, 5.00, 'Stripe')",
		donorID, prefix+"_donor@example.com", prefix+"_txn")
	defer db.Exec("DELETE FROM users_donations WHERE userid = ?", donorID)

	nonDonorID := CreateTestUser(t, prefix+"_nondonor", utils.SYSTEMROLE_USER)

	supportID := CreateTestUser(t, prefix+"_support", utils.SYSTEMROLE_USER)
	db.Exec("UPDATE users SET systemrole = 'Support' WHERE id = ?", supportID)
	_, supportToken := CreateTestSession(t, supportID)

	u := getUserAs(t, donorID, supportToken)
	assert.True(t, u.Donor, "support should see that a donor has donated, however long ago")
	assert.Empty(t, u.Donations, "the donations themselves stay behind PERM_GIFTAID")
	assert.Nil(t, u.Donated, "support does not get the donation date")

	u = getUserAs(t, nonDonorID, supportToken)
	assert.False(t, u.Donor, "someone who has never donated is not a donor")
}

func TestDonorFlagHiddenFromOtherMembers(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("donorhidden")

	donorID := CreateTestUser(t, prefix+"_donor", utils.SYSTEMROLE_USER)
	db.Exec("INSERT INTO users_donations (type, userid, Payer, PayerDisplayName, timestamp, TransactionID, GrossAmount, source) VALUES ('Stripe', ?, ?, 'Donor', '2019-01-01 10:00:00', ?, 5.00, 'Stripe')",
		donorID, prefix+"_donor@example.com", prefix+"_txn")
	defer db.Exec("DELETE FROM users_donations WHERE userid = ?", donorID)

	otherID := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	_, otherToken := CreateTestSession(t, otherID)

	resp, err := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/user/%d?jwt=%s", donorID, otherToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var u user2.User
	json.NewDecoder(resp.Body).Decode(&u)
	assert.False(t, u.Donor, "another member must not learn who has donated")

	// The donor sees their own status.
	_, donorToken := CreateTestSession(t, donorID)
	resp, err = getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/user/%d?jwt=%s", donorID, donorToken), nil))
	assert.NoError(t, err)
	json.NewDecoder(resp.Body).Decode(&u)
	assert.True(t, u.Donor)
}

func TestSupportCanTurnOffDonationAsksForMember(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("donoptout")

	memberID := CreateTestUser(t, prefix+"_member", utils.SYSTEMROLE_USER)
	db.Exec(`UPDATE users SET settings = '{"notificationmails":true,"somecustom":"keep"}' WHERE id = ?`, memberID)

	supportID := CreateTestUser(t, prefix+"_support", utils.SYSTEMROLE_USER)
	db.Exec("UPDATE users SET systemrole = 'Support' WHERE id = ?", supportID)
	_, supportToken := CreateTestSession(t, supportID)

	payload := map[string]interface{}{
		"id":       memberID,
		"settings": map[string]interface{}{"nodonationasks": true},
	}
	s, _ := json.Marshal(payload)
	request := httptest.NewRequest("PATCH", "/api/user?jwt="+supportToken, bytes.NewBuffer(s))
	request.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(request)
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)

	var stored string
	db.Raw("SELECT settings FROM users WHERE id = ?", memberID).Scan(&stored)
	var settings map[string]interface{}
	json.Unmarshal([]byte(stored), &settings)

	assert.Equal(t, true, settings["nodonationasks"], "support's change lands on the member")
	assert.Equal(t, "keep", settings["somecustom"], "the member's other settings are kept")

	// Support reads it back in the support view, even without moderating the member's groups.
	u := getUserAs(t, memberID, supportToken)
	assert.True(t, u.NoDonationAsks, "support must see the switch as it really is")

	// And it reads false for a member who has not opted out.
	other := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	assert.False(t, getUserAs(t, other, supportToken).NoDonationAsks)

	var supportStored *string
	db.Raw("SELECT settings FROM users WHERE id = ?", supportID).Scan(&supportStored)
	if supportStored != nil {
		assert.NotContains(t, *supportStored, "nodonationasks", "it must not land on the support user")
	}
}
