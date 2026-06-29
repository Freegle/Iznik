package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/aiimage"
	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// addBulkItem inserts one catalogue item and returns its id.
func addBulkItem(t *testing.T, msgid uint64, name string, qty int, condition string) uint64 {
	db := database.DBConn
	res := db.Exec("INSERT INTO messages_bulk_items (msgid, position, name, quantity, `condition`) VALUES (?, 0, ?, ?, ?)",
		msgid, name, qty, condition)
	require.NoError(t, res.Error)
	var id uint64
	db.Raw("SELECT id FROM messages_bulk_items WHERE msgid = ? AND name = ? ORDER BY id DESC LIMIT 1", msgid, name).Scan(&id)
	require.NotZero(t, id)
	return id
}

// TestBulkOfferPutCreatesCatalogue checks PUT /message with a bulkitems array
// creates the catalogue, sets availableinitially to the total quantity, and
// falls back to a readable textbody summary.
func TestBulkOfferPutCreatesCatalogue(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkput")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	db.Exec("UPDATE users SET permissions = ? WHERE id = ?", auth.PERM_CLEARANCE, userID)
	token := getToken(t, userID)

	var locationID uint64
	db.Raw("SELECT id FROM locations LIMIT 1").Scan(&locationID)

	body := map[string]interface{}{
		"messagetype": "Offer",
		"item":        "Office Clearance",
		"collection":  "Draft",
		"groupid":     groupID,
		"locationid":  locationID,
		"bulkitems": []map[string]interface{}{
			{"name": "Office desk", "quantity": 4, "condition": "Good", "photourl": "https://example.com/desk.jpg"},
			{"name": "Chair", "quantity": 14, "condition": "Used"},
		},
	}
	bodyBytes, _ := json.Marshal(body)
	req := httptest.NewRequest("PUT", "/api/message?jwt="+token, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	msgID := uint64(result["id"].(float64))
	require.NotZero(t, msgID)

	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_bulk_items WHERE msgid = ?", msgID).Scan(&count)
	assert.Equal(t, int64(2), count)

	var availInit int
	db.Raw("SELECT availableinitially FROM messages WHERE id = ?", msgID).Scan(&availInit)
	assert.Equal(t, 18, availInit, "availableinitially should equal total quantity 4+14")

	var textbody string
	db.Raw("SELECT textbody FROM messages WHERE id = ?", msgID).Scan(&textbody)
	assert.Contains(t, textbody, "Office desk")
	assert.Contains(t, textbody, "Chair")
	// Ref numbers disambiguate items in the plain-text summary.
	assert.Contains(t, textbody, "1)")

	// Spreadsheet-supplied photo URL is stored on the item.
	var photourl string
	db.Raw("SELECT COALESCE(photourl,'') FROM messages_bulk_items WHERE msgid = ? AND name = 'Office desk'", msgID).Scan(&photourl)
	assert.Equal(t, "https://example.com/desk.jpg", photourl)
}

// TestBulkOfferGetReturnsCatalogue checks GET /message returns the catalogue with
// photos grouped under their item and an interest summary, and that the per-user
// interest list is owner/mod-only while a viewer still sees their own interest.
func TestBulkOfferGetReturnsCatalogue(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkget")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Office Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, "Office desk", 4, "Good")
	chairID := addBulkItem(t, msgID, "Chair", 14, "Used")

	// A photo belongs to the desk item.
	attID := CreateTestAttachment(t, msgID)
	db.Exec("INSERT INTO messages_bulk_item_attachments (bulkitemid, attachmentid) VALUES (?, ?)", deskID, attID)

	// viewer wants 2 desks; other wants 6 chairs.
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 2, 'Interested')", deskID, msgID, viewerID)
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 6, 'Interested')", chairID, msgID, otherID)

	// --- As the owner: sees the full interest list. ---
	ownerToken := getToken(t, ownerID)
	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, ownerToken), nil), 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var ownerMsg message.Message
	json.Unmarshal(rsp(resp), &ownerMsg)
	require.Len(t, ownerMsg.BulkItems, 2)

	var desk *message.BulkItem
	for i := range ownerMsg.BulkItems {
		if ownerMsg.BulkItems[i].ID == deskID {
			desk = &ownerMsg.BulkItems[i]
		}
	}
	require.NotNil(t, desk)
	assert.Equal(t, uint(4), desk.Quantity)
	assert.Len(t, desk.Attachments, 1, "desk photo should be grouped under the desk item")
	assert.Equal(t, 1, desk.Interestcount)
	assert.Equal(t, 2, desk.Interestedquantity)
	assert.Len(t, desk.Interest, 1, "owner sees the per-user interest list")

	// --- As the viewer: sees own interest, not others' list. ---
	viewerToken := getToken(t, viewerID)
	resp, err = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, viewerToken), nil), 10000)
	require.NoError(t, err)
	var viewerMsg message.Message
	json.Unmarshal(rsp(resp), &viewerMsg)
	var vdesk *message.BulkItem
	for i := range viewerMsg.BulkItems {
		if viewerMsg.BulkItems[i].ID == deskID {
			vdesk = &viewerMsg.BulkItems[i]
		}
	}
	require.NotNil(t, vdesk)
	assert.Nil(t, vdesk.Interest, "non-owner must NOT see the per-user interest list")
	require.NotNil(t, vdesk.Yourinterest, "viewer should see their own interest")
	assert.Equal(t, uint(2), vdesk.Yourinterest.Quantity)
	assert.Equal(t, 1, vdesk.Interestcount, "counts are still visible to everyone")
}

// TestBulkInterest checks expressing interest creates rows + one consolidated
// chat reply, caps quantity, updates on re-express, withdraws on zero, and
// rejects interest in your own post.
func TestBulkInterest(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkint")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Office Clearance", 55.95, -3.18)
	chairID := addBulkItem(t, msgID, "Chair", 14, "Used")

	wanterToken := getToken(t, wanterID)
	cancollect := "Tuesday afternoon"
	post := func(qty int) *map[string]interface{} {
		body := map[string]interface{}{
			"action": "BulkInterest",
			"id":     msgID,
			"bulkinterest": []map[string]interface{}{
				{"bulkitemid": chairID, "quantity": qty, "cancollect": cancollect},
			},
		}
		bb, _ := json.Marshal(body)
		req := httptest.NewRequest("POST", "/api/message?jwt="+wanterToken, bytes.NewBuffer(bb))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)
		var result map[string]interface{}
		json.NewDecoder(resp.Body).Decode(&result)
		return &result
	}

	// Request more than available → capped at 14.
	post(20)
	var qty int
	var state string
	db.Raw("SELECT quantity, state FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", chairID, wanterID).Row().Scan(&qty, &state)
	assert.Equal(t, 14, qty, "quantity capped at available")
	assert.Equal(t, "Interested", state)

	// A single consolidated Interested chat message referencing the post.
	var chatCount int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE refmsgid = ? AND userid = ? AND type = 'Interested'", msgID, wanterID).Scan(&chatCount)
	assert.Equal(t, int64(1), chatCount)

	// Re-express with a smaller quantity → updates the existing row (still one row).
	post(3)
	var rowCount int64
	db.Raw("SELECT COUNT(*) FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", chairID, wanterID).Scan(&rowCount)
	assert.Equal(t, int64(1), rowCount)
	db.Raw("SELECT quantity FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", chairID, wanterID).Scan(&qty)
	assert.Equal(t, 3, qty)

	// Re-expressing must NOT add a second chat message — the thread stays clean.
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE refmsgid = ? AND userid = ? AND type = 'Interested'", msgID, wanterID).Scan(&chatCount)
	assert.Equal(t, int64(1), chatCount, "re-express updates the existing chat message, not a new one")

	// Quantity 0 → withdrawn.
	post(0)
	db.Raw("SELECT state FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", chairID, wanterID).Scan(&state)
	assert.Equal(t, "Withdrawn", state)

	// Owner cannot register interest in their own post.
	ownerToken := getToken(t, ownerID)
	body := map[string]interface{}{
		"action": "BulkInterest", "id": msgID,
		"bulkinterest": []map[string]interface{}{{"bulkitemid": chairID, "quantity": 1}},
	}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", "/api/message?jwt="+ownerToken, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode, "cannot be interested in your own post")
}

// TestBulkInterestMessageIsStructured checks the consolidated Interested chat
// reply is structured with the catalogue reference numbers (#n) — so it's
// unambiguous when items share a name and machine-parseable by the Freegle
// Helper / AI — lists the picks in catalogue order, and only the picked items.
func TestBulkInterestMessageIsStructured(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkstruct")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Office Clearance", 55.95, -3.18)
	// Three items: ref #1, #2, #3 in insertion (id) order.
	_ = addBulkItem(t, msgID, "Office desk", 4, "Good")
	chairID := addBulkItem(t, msgID, "Swivel chair", 14, "Used")
	lampID := addBulkItem(t, msgID, "Desk lamp", 6, "LikeNew")

	wanterToken := getToken(t, wanterID)
	// Interested in the chair (#2, qty 2) and the lamp (#3, qty 1), NOT the desk.
	body := map[string]interface{}{
		"action": "BulkInterest",
		"id":     msgID,
		"bulkinterest": []map[string]interface{}{
			{"bulkitemid": lampID, "quantity": 1, "cancollect": "Tue 7 Apr, 10am-4pm"},
			{"bulkitemid": chairID, "quantity": 2, "cancollect": "Tue 7 Apr, 10am-4pm"},
		},
	}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", "/api/message?jwt="+wanterToken, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var msg string
	db.Raw("SELECT message FROM chat_messages WHERE refmsgid = ? AND userid = ? AND type = 'Interested' ORDER BY id DESC LIMIT 1",
		msgID, wanterID).Scan(&msg)

	// Reference numbers + quantities, picked items only.
	assert.Contains(t, msg, "#2 Swivel chair × 2")
	assert.Contains(t, msg, "#3 Desk lamp × 1")
	assert.NotContains(t, msg, "#1", "the desk wasn't picked, so it must not appear")
	assert.Contains(t, msg, "I can collect: Tue 7 Apr, 10am-4pm")
	// Catalogue order: the chair (#2) is listed before the lamp (#3), regardless
	// of the order they were sent in the request.
	assert.Less(t, strings.Index(msg, "#2"), strings.Index(msg, "#3"),
		"picks are listed in catalogue order")
}

// TestBulkInterestOnBehalf checks the offerer can record a replier's interest on
// their behalf (interestuserid) — e.g. when the replier asked for an item in
// free-text chat — while a non-offerer cannot do it for someone else.
func TestBulkInterestOnBehalf(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkonbehalf")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	otherID := CreateTestUser(t, prefix+"_other", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, "Desk", 4, "Good")

	postAs := func(token string, interestUserid uint64, qty int) int {
		body := map[string]interface{}{
			"action":         "BulkInterest",
			"id":             msgID,
			"interestuserid": interestUserid,
			"bulkinterest": []map[string]interface{}{
				{"bulkitemid": deskID, "quantity": qty},
			},
		}
		bb, _ := json.Marshal(body)
		req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(bb))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		return resp.StatusCode
	}

	// The offerer records the replier's interest on their behalf.
	require.Equal(t, 200, postAs(getToken(t, ownerID), wanterID, 2))
	var qty int
	var state string
	db.Raw("SELECT quantity, state FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", deskID, wanterID).Row().Scan(&qty, &state)
	assert.Equal(t, 2, qty, "interest is recorded against the replier, not the offerer")
	assert.Equal(t, "Interested", state)

	// The consolidated Interested chat message is attributed to the replier.
	var chatCount int64
	db.Raw("SELECT COUNT(*) FROM chat_messages WHERE refmsgid = ? AND userid = ? AND type = 'Interested'", msgID, wanterID).Scan(&chatCount)
	assert.Equal(t, int64(1), chatCount)

	// No interest row leaks onto the offerer.
	var ownerRows int64
	db.Raw("SELECT COUNT(*) FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", deskID, ownerID).Scan(&ownerRows)
	assert.Equal(t, int64(0), ownerRows)

	// A non-offerer cannot record interest for someone else.
	assert.Equal(t, 403, postAs(getToken(t, otherID), wanterID, 1))
}

// TestBulkInterestState checks the offerer can transition an interest row, and a
// non-owner cannot.
func TestBulkInterestState(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkstate")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	strangerID := CreateTestUser(t, prefix+"_stranger", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, "Desk", 2, "Good")
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 1, 'Interested')", deskID, msgID, wanterID)

	doState := func(token string, state string) int {
		body := map[string]interface{}{
			"action": "BulkInterestState", "id": msgID,
			"bulkitemid": deskID, "userid": wanterID, "state": state,
		}
		bb, _ := json.Marshal(body)
		req := httptest.NewRequest("POST", "/api/message?jwt="+token, bytes.NewBuffer(bb))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		return resp.StatusCode
	}

	// Stranger cannot change state.
	assert.Equal(t, 403, doState(getToken(t, strangerID), "Reserved"))

	// Owner can.
	assert.Equal(t, 200, doState(getToken(t, ownerID), "Reserved"))
	var state string
	db.Raw("SELECT state FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", deskID, wanterID).Scan(&state)
	assert.Equal(t, "Reserved", state)
}

// TestBulkInterestPreservesReservedState checks that once the offerer has
// reserved an item for a replier, the replier re-expressing interest does not
// reset the state back to Interested (which would break allocation).
func TestBulkInterestPreservesReservedState(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkreserve")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	chairID := addBulkItem(t, msgID, "Chair", 14, "Used")
	wanterToken := getToken(t, wanterID)

	express := func(qty int) {
		body := map[string]interface{}{
			"action": "BulkInterest", "id": msgID,
			"bulkinterest": []map[string]interface{}{{"bulkitemid": chairID, "quantity": qty}},
		}
		bb, _ := json.Marshal(body)
		req := httptest.NewRequest("POST", "/api/message?jwt="+wanterToken, bytes.NewBuffer(bb))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)
	}

	express(4)

	// Offerer reserves the chairs for the wanter.
	stateBody := map[string]interface{}{
		"action": "BulkInterestState", "id": msgID,
		"bulkitemid": chairID, "userid": wanterID, "state": "Reserved",
	}
	bb, _ := json.Marshal(stateBody)
	req := httptest.NewRequest("POST", "/api/message?jwt="+getToken(t, ownerID), bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	// Wanter tweaks their quantity — state must remain Reserved.
	express(6)
	var state string
	var qty int
	db.Raw("SELECT state, quantity FROM messages_bulk_items_interest WHERE bulkitemid = ? AND userid = ?", chairID, wanterID).Row().Scan(&state, &qty)
	assert.Equal(t, "Reserved", state, "offerer's reservation must survive a re-express")
	assert.Equal(t, 6, qty, "quantity still updates")
}

// TestBulkOfferPatchRebuildsCatalogue checks PATCH rebuilds the catalogue and
// drops items no longer present.
func TestBulkOfferPatchRebuildsCatalogue(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkpatch")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	token := getToken(t, ownerID)

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, "Desk", 2, "Good")
	addBulkItem(t, msgID, "OldItem", 1, "Used")

	body := map[string]interface{}{
		"id": msgID,
		"bulkitems": []map[string]interface{}{
			{"id": deskID, "name": "Desk", "quantity": 5, "condition": "Good"},
			{"name": "Cabinet", "quantity": 3, "condition": "Used"},
		},
	}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+token, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	// OldItem removed; Desk updated to qty 5; Cabinet added.
	var names []string
	db.Raw("SELECT name FROM messages_bulk_items WHERE msgid = ? ORDER BY name", msgID).Scan(&names)
	assert.Equal(t, []string{"Cabinet", "Desk"}, names)

	var deskQty int
	db.Raw("SELECT quantity FROM messages_bulk_items WHERE id = ?", deskID).Scan(&deskQty)
	assert.Equal(t, 5, deskQty)

	var availInit int
	db.Raw("SELECT availableinitially FROM messages WHERE id = ?", msgID).Scan(&availInit)
	assert.Equal(t, 8, availInit, "availableinitially rebuilt to 5+3")
}

// TestBulkOfferSlots checks the offerer's collection windows are stored on
// create, summarised into the textbody (so non-structured channels like Trash
// Nothing / email see them), and returned by GET in order.
func TestBulkOfferSlots(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkslots")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	token := getToken(t, ownerID)

	var locationID uint64
	db.Raw("SELECT id FROM locations LIMIT 1").Scan(&locationID)

	body := map[string]interface{}{
		"messagetype": "Offer",
		"item":        "Office Clearance",
		"collection":  "Draft",
		"groupid":     groupID,
		"locationid":  locationID,
		"bulkitems": []map[string]interface{}{
			{"name": "Desk", "quantity": 2, "condition": "Good"},
		},
		"bulkslots": []string{"Tue 7 Apr, 10am-4pm", "Wed 8 Apr, 10am-4pm"},
	}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("PUT", "/api/message?jwt="+token, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	msgID := uint64(result["id"].(float64))

	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_bulk_slots WHERE msgid = ?", msgID).Scan(&count)
	assert.Equal(t, int64(2), count)

	// Collection windows summarised into the textbody for non-structured channels.
	var tb string
	db.Raw("SELECT textbody FROM messages WHERE id = ?", msgID).Scan(&tb)
	assert.Contains(t, tb, "Collection times")
	assert.Contains(t, tb, "Tue 7 Apr")

	// GET returns the slots (seed a group row so the draft is visible to the owner).
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, 'Approved', NOW())", msgID, groupID)
	gresp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token), nil), 10000)
	require.NoError(t, err)
	var msg message.Message
	json.Unmarshal(rsp(gresp), &msg)
	assert.Equal(t, []string{"Tue 7 Apr, 10am-4pm", "Wed 8 Apr, 10am-4pm"}, msg.Bulkslots)
}

// TestBulkItemPhotoIngestion checks that a per-item photo URL (e.g. supplied via
// a spreadsheet) is downloaded and turned into a real Freegle attachment linked
// to the item. The remote fetch and TUS upload are stubbed so the test makes no
// network calls.
func TestBulkItemPhotoIngestion(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkphoto")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, "Desk", 2, "Good")
	db.Exec("UPDATE messages_bulk_items SET photourl = ? WHERE id = ?", "https://example.com/desk.jpg", deskID)

	// Stub the remote download and the TUS upload so no network is hit.
	origFetch := message.BulkPhotoFetcher
	origUpload := aiimage.ImageUploader
	defer func() {
		message.BulkPhotoFetcher = origFetch
		aiimage.ImageUploader = origUpload
	}()

	var fetchedURL string
	message.BulkPhotoFetcher = func(url string) ([]byte, string, error) {
		fetchedURL = url
		return []byte{0xFF, 0xD8, 0xFF, 0xE0}, "image/jpeg", nil
	}
	const stubUID = "bulkphoto-stub-uid-123"
	aiimage.ImageUploader = func(data []byte, mime string) (string, error) {
		return stubUID, nil
	}

	message.IngestBulkItemPhotosSync(db, msgID)

	assert.Equal(t, "https://example.com/desk.jpg", fetchedURL, "the item's photo URL should be fetched")

	var externaluid string
	var bulkitemid uint64
	db.Raw("SELECT COALESCE(ma.externaluid,''), COALESCE(bia.bulkitemid,0) FROM messages_attachments ma "+
		"INNER JOIN messages_bulk_item_attachments bia ON bia.attachmentid = ma.id "+
		"WHERE ma.msgid = ? AND bia.bulkitemid = ?", msgID, deskID).
		Row().Scan(&externaluid, &bulkitemid)
	assert.Equal(t, stubUID, externaluid, "an attachment with the uploaded uid should be created")
	assert.Equal(t, deskID, bulkitemid, "the attachment should be linked to the item")

	// Running again must not create a duplicate — the item already has an attachment.
	message.IngestBulkItemPhotosSync(db, msgID)
	var count int64
	db.Raw("SELECT COUNT(*) FROM messages_bulk_item_attachments WHERE bulkitemid = ?", deskID).Scan(&count)
	assert.Equal(t, int64(1), count, "ingestion is idempotent — no duplicate attachment")
}

// TestBulkItemUploadedPhotoDeliveryURL checks that an uploaded per-item photo
// surfaces to a recipient as a correctly-built image-delivery URL. This is the
// wiring that makes the photo actually render for the person browsing the offer:
// the bulk item must carry the attachment, and the attachment's path/thumb must
// be the image-delivery URL derived from its uploaded uid (the same URL every
// other Freegle photo uses). Whether a given environment's resizer can fetch the
// bytes is a separate infra concern; this asserts the API's contract.
func TestBulkItemUploadedPhotoDeliveryURL(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkphotourl")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, "Office desk", 4, "Good")

	// Mirror the real upload flow: an attachment carrying the uploaded uid (in the
	// tusd "freegletusd-<hash>" form), linked to the bulk item.
	const uid = "freegletusd-deadbeefcafe1234"
	res := db.Exec("INSERT INTO messages_attachments (msgid, externaluid, `primary`) VALUES (?, ?, 1)", msgID, uid)
	require.NoError(t, res.Error)
	var linkAttID uint64
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? AND externaluid = ?", msgID, uid).Scan(&linkAttID)
	res = db.Exec("INSERT INTO messages_bulk_item_attachments (bulkitemid, attachmentid) VALUES (?, ?)", deskID, linkAttID)
	require.NoError(t, res.Error)

	// GET as an ordinary viewer — the recipient who needs to see the photo.
	viewerToken := getToken(t, viewerID)
	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, viewerToken), nil), 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var msg message.Message
	require.NoError(t, json.Unmarshal(rsp(resp), &msg))

	var desk *message.BulkItem
	for i := range msg.BulkItems {
		if msg.BulkItems[i].ID == deskID {
			desk = &msg.BulkItems[i]
		}
	}
	require.NotNil(t, desk, "the desk item should be in the catalogue")
	require.Len(t, desk.Attachments, 1, "the uploaded photo should be grouped under the desk item")

	att := desk.Attachments[0]
	want := misc.GetImageDeliveryUrl(uid, "")
	require.NotEmpty(t, want)
	assert.Equal(t, want, att.Path, "item photo path should be the image-delivery URL for its uid")
	assert.Equal(t, want, att.Paththumb, "item photo thumb should be the image-delivery URL for its uid")
	assert.Contains(t, att.Path, "deadbeefcafe1234", "the delivery URL must reference the uploaded image")
	assert.Contains(t, att.Path, "?url=", "the delivery URL must be a resizer URL")
}

// TestBulkOfferAccessInstructions checks that access instructions are stored on
// PUT, returned only to the offerer/mod (never a plain viewer), and delivered to
// a replier via chat only once the offerer promises (Reserves) them an item.
func TestBulkOfferAccessInstructions(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkaccess")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	viewerID := CreateTestUser(t, prefix+"_viewer", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	ownerToken := getToken(t, ownerID)

	var locationID uint64
	db.Raw("SELECT id FROM locations LIMIT 1").Scan(&locationID)

	const instructions = "12 High St, side door, buzz flat 3"
	body := map[string]interface{}{
		"messagetype":        "Offer",
		"item":               "Office Clearance",
		"collection":         "Draft",
		"groupid":            groupID,
		"locationid":         locationID,
		"accessinstructions": instructions,
		"bulkitems": []map[string]interface{}{
			{"name": "Desk", "quantity": 2, "condition": "Good"},
		},
	}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("PUT", "/api/message?jwt="+ownerToken, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)
	msgID := uint64(result["id"].(float64))
	require.NotZero(t, msgID)

	// Stored on the message.
	var stored string
	db.Raw("SELECT COALESCE(accessinstructions,'') FROM messages_bulk_access WHERE msgid = ?", msgID).Scan(&stored)
	assert.Equal(t, instructions, stored)

	// Make the draft visible so GET returns it.
	db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, 'Approved', NOW())", msgID, groupID)
	deskID := addBulkItemRef(t, msgID)

	// --- Owner sees the instructions. ---
	oresp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, ownerToken), nil), 10000)
	require.NoError(t, err)
	var ownerMsg message.Message
	json.Unmarshal(rsp(oresp), &ownerMsg)
	require.NotNil(t, ownerMsg.Accessinstructions, "owner should see access instructions")
	assert.Equal(t, instructions, *ownerMsg.Accessinstructions)

	// --- A plain viewer must NOT see the instructions. ---
	vresp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, getToken(t, viewerID)), nil), 10000)
	require.NoError(t, err)
	var viewerMsg message.Message
	json.Unmarshal(rsp(vresp), &viewerMsg)
	assert.Nil(t, viewerMsg.Accessinstructions, "a plain viewer must not see access instructions")

	// --- Reveal on promise: owner Reserves the item for the wanter. ---
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 1, 'Interested')", deskID, msgID, wanterID)

	stateBody := map[string]interface{}{
		"action": "BulkInterestState", "id": msgID,
		"bulkitemid": deskID, "userid": wanterID, "state": "Reserved",
	}
	sb, _ := json.Marshal(stateBody)
	sreq := httptest.NewRequest("POST", "/api/message?jwt="+ownerToken, bytes.NewBuffer(sb))
	sreq.Header.Set("Content-Type", "application/json")
	sresp, err := getApp().Test(sreq, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, sresp.StatusCode)

	// The wanter received a chat message containing the instructions.
	var chatBody string
	db.Raw("SELECT cm.message FROM chat_messages cm "+
		"INNER JOIN chat_rooms cr ON cr.id = cm.chatid "+
		"WHERE cm.userid = ? AND ((cr.user1 = ? AND cr.user2 = ?) OR (cr.user1 = ? AND cr.user2 = ?)) "+
		"ORDER BY cm.id DESC LIMIT 1",
		ownerID, ownerID, wanterID, wanterID, ownerID).Scan(&chatBody)
	assert.Contains(t, chatBody, instructions, "the promised replier should receive the access instructions")

	// Re-reserving must not send the instructions again (state already Reserved).
	sreq2 := httptest.NewRequest("POST", "/api/message?jwt="+ownerToken, mustJSON(stateBody))
	sreq2.Header.Set("Content-Type", "application/json")
	getApp().Test(sreq2, 10000)
	var sent int64
	db.Raw("SELECT COUNT(*) FROM chat_messages cm INNER JOIN chat_rooms cr ON cr.id = cm.chatid "+
		"WHERE cm.userid = ? AND cm.message LIKE ? AND ((cr.user1 = ? AND cr.user2 = ?) OR (cr.user1 = ? AND cr.user2 = ?))",
		ownerID, "%"+instructions+"%", ownerID, wanterID, wanterID, ownerID).Scan(&sent)
	assert.Equal(t, int64(1), sent, "access instructions sent once, not on every re-reserve")
}

// addBulkItemRef returns the id of the (single) bulk item created for a message.
func addBulkItemRef(t *testing.T, msgid uint64) uint64 {
	var id uint64
	database.DBConn.Raw("SELECT id FROM messages_bulk_items WHERE msgid = ? ORDER BY id ASC LIMIT 1", msgid).Scan(&id)
	require.NotZero(t, id)
	return id
}

func mustJSON(v interface{}) *bytes.Buffer {
	b, _ := json.Marshal(v)
	return bytes.NewBuffer(b)
}
