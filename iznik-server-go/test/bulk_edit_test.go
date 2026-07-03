package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// setEditToken stores a known secret edit token for an offer (the mint endpoint
// is exercised separately in TestBulkEditLinkMint).
func setEditToken(t *testing.T, msgid uint64, token string) {
	res := database.DBConn.Exec(
		"INSERT INTO messages_bulk_access (msgid, edittoken) VALUES (?, ?) "+
			"ON DUPLICATE KEY UPDATE edittoken = VALUES(edittoken)", msgid, token)
	require.NoError(t, res.Error)
}

// bulkEditOfferResp is the decoded logged-out GET response.
type bulkEditOfferResp struct {
	Ret     int                    `json:"ret"`
	ID      uint64                 `json:"id"`
	Subject string                 `json:"subject"`
	Items   []message.BulkEditItem `json:"items"`
}

// getOfferByToken calls the logged-out GET endpoint and decodes the response.
func getOfferByToken(t *testing.T, token string) (int, bulkEditOfferResp) {
	var out bulkEditOfferResp
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/bulkoffer/update/"+token, nil), 10000)
	require.NoError(t, err)
	if resp.StatusCode == 200 {
		json.Unmarshal(rsp(resp), &out)
	}
	return resp.StatusCode, out
}

// postUpdate calls the logged-out POST endpoint with a JSON body.
func postUpdate(t *testing.T, token string, body map[string]interface{}) *httptestResponse {
	b, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", "/api/bulkoffer/update/"+token, bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	return &httptestResponse{StatusCode: resp.StatusCode, Body: rsp(resp)}
}

type httptestResponse struct {
	StatusCode int
	Body       []byte
}

// TestBulkEditGetOfferByToken: the logged-out page loads the catalogue from a
// secret token, sees each item's available flag, and gets NO replier/interest data.
func TestBulkEditGetOfferByToken(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkeditget")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Office Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, prefix+"Desk", 4, "Good")
	addBulkItem(t, msgID, prefix+"Chair", 14, "Used")

	// A replier's interest exists in the DB but must NOT leak to the logged-out page.
	secretMarker := prefix + "_SECRET_REPLIER"
	db.Exec("UPDATE users SET fullname = ? WHERE id = ?", secretMarker, wanterID)
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 2, 'Reserved')",
		deskID, msgID, wanterID)

	token := fmt.Sprintf("edittok_%s_0123456789abcdef", prefix)
	setEditToken(t, msgID, token)

	status, out := getOfferByToken(t, token)
	require.Equal(t, 200, status)
	assert.Equal(t, 0, out.Ret)
	assert.Equal(t, msgID, out.ID)
	require.Len(t, out.Items, 2)
	for _, it := range out.Items {
		assert.True(t, it.Available, "items default to available")
		assert.NotZero(t, it.Quantity)
	}

	// Privacy: no replier id/name anywhere in the payload.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/bulkoffer/update/"+token, nil), 10000)
	raw := string(rsp(resp))
	assert.NotContains(t, raw, secretMarker, "replier name must not appear on the logged-out page")
	assert.NotContains(t, raw, "interest", "no interest data on the logged-out page")
	assert.NotContains(t, raw, "userid", "no userid on the logged-out page")
}

// TestBulkEditUpdateAvailability: toggling an item taken updates its flag and
// removes its quantity from messages.availablenow; toggling back restores it.
func TestBulkEditUpdateAvailability(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkeditavail")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, prefix+"Desk", 4, "Good")
	chairID := addBulkItem(t, msgID, prefix+"Chair", 14, "Used")

	token := fmt.Sprintf("edittok_%s_0123456789abcdef", prefix)
	setEditToken(t, msgID, token)

	// Mark the chairs taken.
	res := postUpdate(t, token, map[string]interface{}{"itemid": chairID, "available": false})
	require.Equal(t, 200, res.StatusCode)

	var avail int
	db.Raw("SELECT available FROM messages_bulk_items WHERE id = ?", chairID).Scan(&avail)
	assert.Equal(t, 0, avail, "chair marked taken")

	var availablenow int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availablenow)
	assert.Equal(t, 4, availablenow, "availablenow = sum of remaining available items (desk 4)")

	// The desk is still available and unaffected.
	var deskAvail int
	db.Raw("SELECT available FROM messages_bulk_items WHERE id = ?", deskID).Scan(&deskAvail)
	assert.Equal(t, 1, deskAvail)

	// Toggle the chairs back to available.
	res = postUpdate(t, token, map[string]interface{}{"itemid": chairID, "available": true})
	require.Equal(t, 200, res.StatusCode)
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availablenow)
	assert.Equal(t, 18, availablenow, "availablenow restored to 4+14")
}

// TestBulkEditUpdateQuantity: editing a count updates the item and availablenow.
func TestBulkEditUpdateQuantity(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkeditqty")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, prefix+"Desk", 4, "Good")
	addBulkItem(t, msgID, prefix+"Chair", 14, "Used")

	token := fmt.Sprintf("edittok_%s_0123456789abcdef", prefix)
	setEditToken(t, msgID, token)

	res := postUpdate(t, token, map[string]interface{}{"itemid": deskID, "quantity": 2})
	require.Equal(t, 200, res.StatusCode)

	var qty int
	db.Raw("SELECT quantity FROM messages_bulk_items WHERE id = ?", deskID).Scan(&qty)
	assert.Equal(t, 2, qty)

	var availablenow int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availablenow)
	assert.Equal(t, 16, availablenow, "availablenow = 2 desks + 14 chairs")
}

// TestBulkEditItemNotInOffer: a token for one offer cannot edit another offer's item.
func TestBulkEditItemNotInOffer(t *testing.T) {
	prefix := uniquePrefix("bulkeditxoffer")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgA := CreateTestMessage(t, ownerID, groupID, prefix+" A", 55.95, -3.18)
	addBulkItem(t, msgA, prefix+"A-item", 3, "Good")
	msgB := CreateTestMessage(t, ownerID, groupID, prefix+" B", 55.95, -3.18)
	bItem := addBulkItem(t, msgB, prefix+"B-item", 5, "Good")

	token := fmt.Sprintf("edittok_%s_0123456789abcdef", prefix)
	setEditToken(t, msgA, token)

	// Token is for offer A; try to edit an item that belongs to offer B.
	res := postUpdate(t, token, map[string]interface{}{"itemid": bItem, "available": false})
	assert.Equal(t, 404, res.StatusCode, "cannot edit an item outside the token's offer")
}

// TestBulkEditBadToken: unknown tokens are rejected on both read and write.
func TestBulkEditBadToken(t *testing.T) {
	status, _ := getOfferByToken(t, "definitely_not_a_real_token_1234")
	assert.Equal(t, 404, status)

	res := postUpdate(t, "definitely_not_a_real_token_1234", map[string]interface{}{"itemid": 1, "available": false})
	assert.Equal(t, 404, res.StatusCode)
}

// TestBulkEditLinkMint: the owner mints a stable secret link; a non-owner is
// refused; a non-bulk message is rejected.
func TestBulkEditLinkMint(t *testing.T) {
	prefix := uniquePrefix("bulkeditmint")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	strangerID := CreateTestUser(t, prefix+"_stranger", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	addBulkItem(t, msgID, prefix+"Desk", 4, "Good")

	mint := func(userID uint64) (int, string) {
		tok := getToken(t, userID)
		body, _ := json.Marshal(map[string]interface{}{"id": msgID, "action": "BulkEditLink"})
		req := httptest.NewRequest("POST", "/api/message?jwt="+tok, bytes.NewBuffer(body))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		var out map[string]interface{}
		if resp.StatusCode == 200 {
			json.Unmarshal(rsp(resp), &out)
		}
		token, _ := out["token"].(string)
		return resp.StatusCode, token
	}

	// Owner mints a link.
	status, token := mint(ownerID)
	require.Equal(t, 200, status)
	require.NotEmpty(t, token)
	assert.GreaterOrEqual(t, len(token), 16)

	// Minting again returns the SAME stable token.
	status2, token2 := mint(ownerID)
	require.Equal(t, 200, status2)
	assert.Equal(t, token, token2, "the link is stable across calls")

	// The minted token actually works on the logged-out endpoint.
	getStatus, out := getOfferByToken(t, token)
	require.Equal(t, 200, getStatus)
	assert.Equal(t, msgID, out.ID)

	// A stranger cannot mint a link for someone else's offer.
	strangerStatus, _ := mint(strangerID)
	assert.Equal(t, 403, strangerStatus)

	// A message with no bulk items is not a bulk offer.
	plainMsg := CreateTestMessage(t, ownerID, groupID, prefix+" Plain", 55.95, -3.18)
	plainTok := getToken(t, ownerID)
	body, _ := json.Marshal(map[string]interface{}{"id": plainMsg, "action": "BulkEditLink"})
	req := httptest.NewRequest("POST", "/api/message?jwt="+plainTok, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode, "a non-bulk message has no update link")
	assert.True(t, strings.Contains(strings.ToLower(string(rsp(resp))), "bulk"))
}
