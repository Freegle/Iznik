package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Editing a post's quantity must move BOTH availablenow and availableinitially.
//
// The owner editing "How many?" is stating how many there are - they may have
// found a few more - so the edit moves both. Giving items away is the only
// thing that moves availablenow on its own, and that is what makes
// availableinitially the figure the give-away is measured against.
//
// MessageEditModal.vue sends the edited quantity as both keys, but
// patchMessageRequest carried only Availablenow, so availableinitially kept
// whatever it was at posting time. That is not cosmetic: applyRepost resets
// availablenow BACK to the stale availableinitially, and handleAddBy /
// handleRemoveBy clamp with LEAST(availableinitially, ...), so a quantity
// edited upwards was silently undone. Measured on live: 169 offers in 90 days
// had availablenow > availableinitially, e.g. message 121668509 advertising
// "Wooden top tables ... x5" on availableinitially = 1 - one taken would have
// collapsed it from 5 available to 1.
//
// PUT /message already resolves this as availableinitially = the supplied
// value, else mirror availablenow (see handlePutMessage). PATCH must agree with
// it - two edit paths disagreeing about the same two columns is what produced
// the stale rows in the first place.

// patchQuantitySetup creates an owner-held offer with a known starting quantity.
func patchQuantitySetup(t *testing.T, prefix string, initial, now int) (msgID uint64, ownerToken string) {
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, ownerToken = CreateTestSession(t, ownerID)
	msgID = CreateTestMessage(t, ownerID, groupID, "OFFER: Jars "+prefix, 55.0, -1.0)

	db.Exec("UPDATE messages SET type = 'Offer', availableinitially = ?, availablenow = ? WHERE id = ?",
		initial, now, msgID)

	return msgID, ownerToken
}

// patchQuantity sends an owner-authenticated PATCH /message with the given body.
func patchQuantity(t *testing.T, ownerToken, body string) int {
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+ownerToken, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	return resp.StatusCode
}

// readQuantity reads back the persisted pair.
func readQuantity(t *testing.T, msgID uint64) (initial, now int) {
	type row struct {
		Availableinitially int
		Availablenow       int
	}
	var got row
	database.DBConn.Raw("SELECT availableinitially, availablenow FROM messages WHERE id = ?", msgID).Scan(&got)
	return got.Availableinitially, got.Availablenow
}

// TestPatchMessageQuantityWritesAvailableInitially is the regression test for the
// reported bug: the edit modal sends both keys and both must land. On unpatched
// code availableinitially stays at 1 because patchMessageRequest has no field
// for it.
func TestPatchMessageQuantityWritesAvailableInitially(t *testing.T) {
	prefix := uniquePrefix("patchqty_both")
	msgID, ownerToken := patchQuantitySetup(t, prefix, 1, 1)

	body := fmt.Sprintf(`{"id":%d,"availablenow":3,"availableinitially":3}`, msgID)
	require.Equal(t, 200, patchQuantity(t, ownerToken, body))

	initial, now := readQuantity(t, msgID)
	assert.Equal(t, 3, now, "the edited quantity must be the number now available")
	assert.Equal(t, 3, initial,
		"availableinitially must follow the edit, or a later redraft resets availablenow back to the stale value")
}

// TestPatchMessageQuantityMirrorsWhenOnlyAvailablenowSent pins PATCH to the same
// rule PUT already applies, so a client that sends only the one key (TN edits)
// cannot leave the pair inconsistent.
func TestPatchMessageQuantityMirrorsWhenOnlyAvailablenowSent(t *testing.T) {
	prefix := uniquePrefix("patchqty_mirror")
	msgID, ownerToken := patchQuantitySetup(t, prefix, 1, 1)

	body := fmt.Sprintf(`{"id":%d,"availablenow":5}`, msgID)
	require.Equal(t, 200, patchQuantity(t, ownerToken, body))

	initial, now := readQuantity(t, msgID)
	assert.Equal(t, 5, now)
	assert.Equal(t, 5, initial,
		"availableinitially mirrors availablenow when only the one key is sent, matching PUT /message")
}

// TestPatchMessageWithoutQuantityLeavesBothAlone guards the common case: editing
// only the wording of a partly-taken post must not disturb either column.
func TestPatchMessageWithoutQuantityLeavesBothAlone(t *testing.T) {
	prefix := uniquePrefix("patchqty_none")
	msgID, ownerToken := patchQuantitySetup(t, prefix, 5, 2)

	body := fmt.Sprintf(`{"id":%d,"textbody":"Updated description %s"}`, msgID, prefix)
	require.Equal(t, 200, patchQuantity(t, ownerToken, body))

	initial, now := readQuantity(t, msgID)
	assert.Equal(t, 5, initial, "a text-only edit must not touch availableinitially")
	assert.Equal(t, 2, now, "a text-only edit must not touch availablenow")
}

// TestAddByLeavesAvailableInitiallyAlone is the other half of the rule: only an
// edit moves availableinitially. Giving items away moves availablenow, so that
// "how many were there" stays comparable with "how many are left".
func TestAddByLeavesAvailableInitiallyAlone(t *testing.T) {
	prefix := uniquePrefix("patchqty_giveaway")
	msgID, ownerToken := patchQuantitySetup(t, prefix, 5, 5)
	takerID := CreateTestUser(t, prefix+"_taker", "User")

	require.Equal(t, 200, postMessageAction(t, ownerToken, map[string]interface{}{
		"id": msgID, "action": "AddBy", "userid": takerID, "count": 2,
	}))

	initial, now := readQuantity(t, msgID)
	assert.Equal(t, 5, initial, "giving items away must never move availableinitially")
	assert.Equal(t, 3, now, "giving 2 of 5 away leaves 3")
}

// TestQuantityEditedUpThenTakenDoesNotCollapse is the live symptom of the stale
// column: message 121668509 was posted with the default 1 and edited up to 5, so
// LEAST(availableinitially, availablenow - count) dropped it from 5 available to
// 1 the moment anyone took one. With the edit writing both columns the clamp is
// no longer wrong.
func TestQuantityEditedUpThenTakenDoesNotCollapse(t *testing.T) {
	prefix := uniquePrefix("patchqty_editup")
	msgID, ownerToken := patchQuantitySetup(t, prefix, 1, 1)
	takerID := CreateTestUser(t, prefix+"_taker", "User")

	edit := fmt.Sprintf(`{"id":%d,"availablenow":5,"availableinitially":5}`, msgID)
	require.Equal(t, 200, patchQuantity(t, ownerToken, edit))

	require.Equal(t, 200, postMessageAction(t, ownerToken, map[string]interface{}{
		"id": msgID, "action": "AddBy", "userid": takerID, "count": 1,
	}))

	initial, now := readQuantity(t, msgID)
	assert.Equal(t, 5, initial)
	assert.Equal(t, 4, now,
		"one taken from an edited-up post leaves 4, not the posted-time figure of 1")
}
