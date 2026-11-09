package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// splitFixture is a post the auto-splitter has split: two items (a sofa and a bed), one
// photo of both kept as a shared post photo, and a crop of it on the sofa.
type splitFixture struct {
	groupID, ownerID, msgID, sofaID, bedID, photoID, cropID uint64
	token                                                   string
}

const cropMods = `{"cx":10,"cy":20,"cw":300,"ch":200,"fw":800,"fh":600,"precrop":true}`

func newSplitFixture(t *testing.T, prefix string) splitFixture {
	db := database.DBConn
	f := splitFixture{}
	f.groupID = CreateTestGroup(t, prefix)
	f.ownerID = CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, f.ownerID, f.groupID, "Member")
	f.token = getToken(t, f.ownerID)
	f.msgID = CreateTestMessage(t, f.ownerID, f.groupID, prefix+" sofa and bed", 52.5, -1.8)
	db.Exec("UPDATE messages SET availableinitially = 2, availablenow = 2 WHERE id = ?", f.msgID)

	f.sofaID = addBulkItem(t, f.msgID, "Sofa", 1, "Unknown")
	f.bedID = addBulkItem(t, f.msgID, "Bed", 1, "Unknown")

	uid := "freegletusd-autosplit-" + prefix
	require.NoError(t, db.Exec("INSERT INTO messages_attachments (msgid, `primary`, externaluid, externalmods, contenttype) VALUES (?, 1, ?, '{}', 'image/jpeg')", f.msgID, uid).Error)
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? AND `primary` = 1", f.msgID).Scan(&f.photoID)
	require.NoError(t, db.Exec("INSERT INTO messages_attachments (msgid, `primary`, externaluid, externalmods, contenttype) VALUES (?, 0, ?, ?, 'image/jpeg')", f.msgID, uid, cropMods).Error)
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? AND `primary` = 0", f.msgID).Scan(&f.cropID)
	require.NotZero(t, f.photoID)
	require.NotZero(t, f.cropID)
	require.NoError(t, db.Exec("INSERT INTO messages_bulk_item_attachments (bulkitemid, attachmentid) VALUES (?, ?)", f.sofaID, f.cropID).Error)

	require.NoError(t, db.Exec("INSERT INTO messages_autosplit (msgid, state, kind, originalavailableinitially, originalavailablenow) VALUES (?, 'Split', 'several_items', 1, 1)", f.msgID).Error)
	return f
}

func splitAction(t *testing.T, token string, body map[string]interface{}) (int, map[string]interface{}) {
	b, _ := json.Marshal(body)
	url := "/api/message"
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest("POST", url, bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	var out map[string]interface{}
	json.Unmarshal(rsp(resp), &out)
	return resp.StatusCode, out
}

func splitGet(t *testing.T, token string, msgID uint64) map[string]interface{} {
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, token), nil)
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var out map[string]interface{}
	require.NoError(t, json.Unmarshal(rsp(resp), &out))
	return out
}

func TestAutoSplitPostIsMarkedAndItsCropsSitOnTheirItems(t *testing.T) {
	f := newSplitFixture(t, uniquePrefix("as_read"))

	m := splitGet(t, f.token, f.msgID)
	assert.Equal(t, true, m["autosplit"])
	assert.Equal(t, float64(2), m["bulkcount"])

	// The post's own photos are the member's; the crop is not among them.
	atts := m["attachments"].([]interface{})
	require.Len(t, atts, 1)
	assert.Equal(t, float64(f.photoID), atts[0].(map[string]interface{})["id"])

	// The crop is the sofa's photo, and its link crops.
	items := m["bulkitems"].([]interface{})
	var sofa map[string]interface{}
	for _, it := range items {
		if it.(map[string]interface{})["name"] == "Sofa" {
			sofa = it.(map[string]interface{})
		}
	}
	require.NotNil(t, sofa)
	sofaAtts := sofa["attachments"].([]interface{})
	require.Len(t, sofaAtts, 1)
	path := sofaAtts[0].(map[string]interface{})["path"].(string)
	assert.Contains(t, path, "&cx=10&cy=20&cw=300&ch=200&precrop=true")
}

func TestAClearanceIsNotMarkedAsASplit(t *testing.T) {
	prefix := uniquePrefix("as_clr")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	msgID := CreateTestMessage(t, userID, groupID, prefix+" clearance", 52.5, -1.8)
	addBulkItem(t, msgID, "Desk", 3, "Good")

	m := splitGet(t, getToken(t, userID), msgID)
	assert.Equal(t, float64(1), m["bulkcount"])
	assert.Nil(t, m["autosplit"])
}

func TestAutoSplitUndoPutsThePostBackAsWritten(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_undo"))

	status, out := splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "AutoSplitUndo"})
	require.Equal(t, 200, status, out)

	var items, crops, photos int64
	db.Table("messages_bulk_items").Where("msgid = ?", f.msgID).Count(&items)
	db.Table("messages_attachments").Where("id = ?", f.cropID).Count(&crops)
	db.Table("messages_attachments").Where("id = ?", f.photoID).Count(&photos)
	assert.Zero(t, items)
	assert.Zero(t, crops, "the crop goes")
	assert.Equal(t, int64(1), photos, "the member's photo stays")

	var counts struct{ Availableinitially, Availablenow int }
	db.Table("messages").Select("availableinitially, availablenow").Where("id = ?", f.msgID).Scan(&counts)
	assert.Equal(t, 1, counts.Availableinitially)
	assert.Equal(t, 1, counts.Availablenow)

	var state string
	db.Table("messages_autosplit").Select("state").Where("msgid = ?", f.msgID).Scan(&state)
	assert.Equal(t, "Undone", state)

	// Undone is final.
	status, _ = splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "AutoSplitUndo"})
	assert.Equal(t, 400, status)

	m := splitGet(t, f.token, f.msgID)
	assert.Nil(t, m["autosplit"])
	assert.Nil(t, m["bulkitems"])
}

// assertSplitUndone checks the post is back as it was written: no items, no crop, the
// member's photo kept, the counts put back, and the split recorded as undone.
func assertSplitUndone(t *testing.T, f splitFixture) {
	db := database.DBConn
	var items, crops, photos int64
	db.Table("messages_bulk_items").Where("msgid = ?", f.msgID).Count(&items)
	db.Table("messages_attachments").Where("id = ?", f.cropID).Count(&crops)
	db.Table("messages_attachments").Where("id = ?", f.photoID).Count(&photos)
	assert.Zero(t, items)
	assert.Zero(t, crops, "the crop goes")
	assert.Equal(t, int64(1), photos, "the member's photo stays")

	var counts struct{ Availableinitially, Availablenow int }
	db.Table("messages").Select("availableinitially, availablenow").Where("id = ?", f.msgID).Scan(&counts)
	assert.Equal(t, 1, counts.Availableinitially)
	assert.Equal(t, 1, counts.Availablenow)

	var state string
	db.Table("messages_autosplit").Select("state").Where("msgid = ?", f.msgID).Scan(&state)
	assert.Equal(t, "Undone", state)
}

func TestChangingASplitPostToAWantedUndoesTheSplit(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_to_wanted"))

	status, body := splitPatch(t, f.token, map[string]interface{}{"id": f.msgID, "msgtype": "Wanted"})
	require.Equal(t, 200, status, body)

	var msgType string
	db.Table("messages").Select("type").Where("id = ?", f.msgID).Scan(&msgType)
	assert.Equal(t, "Wanted", msgType)
	assertSplitUndone(t, f)

	// A WANTED has no item list to show.
	m := splitGet(t, f.token, f.msgID)
	assert.Nil(t, m["autosplit"])
	assert.Nil(t, m["bulkitems"])
}

func TestChangingASplitPostToAWantedDropsAnItemListSentWithIt(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_to_wanted_items"))

	// The item list goes with the split, so the edit is not refused as a clearance edit
	// and no items are written.
	status, body := splitPatch(t, f.token, map[string]interface{}{
		"id":      f.msgID,
		"msgtype": "Wanted",
		"bulkitems": []map[string]interface{}{
			{"id": f.sofaID, "name": "Sofa", "quantity": 1},
			{"id": f.bedID, "name": "Bed", "quantity": 1},
		},
	})
	require.Equal(t, 200, status, body)
	assertSplitUndone(t, f)

	var items int64
	db.Table("messages_bulk_items").Where("msgid = ?", f.msgID).Count(&items)
	assert.Zero(t, items)
}

func TestAModeratorChangingASplitPostToAWantedUndoesTheSplit(t *testing.T) {
	prefix := uniquePrefix("as_to_wanted_mod")
	f := newSplitFixture(t, prefix)
	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, f.groupID, "Moderator")

	status, body := splitPatch(t, getToken(t, mod), map[string]interface{}{"id": f.msgID, "msgtype": "Wanted"})
	require.Equal(t, 200, status, body)
	assertSplitUndone(t, f)
}

func TestAnEditThatKeepsASplitPostAnOfferKeepsTheSplit(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_stays_offer"))

	// The member's edit form always sends the type, unchanged here.
	status, body := splitPatch(t, f.token, map[string]interface{}{
		"id":       f.msgID,
		"msgtype":  "Offer",
		"textbody": "A sofa and a bed, both fine.",
	})
	require.Equal(t, 200, status, body)

	var state string
	db.Table("messages_autosplit").Select("state").Where("msgid = ?", f.msgID).Scan(&state)
	assert.Equal(t, "Split", state)
	var items int64
	db.Table("messages_bulk_items").Where("msgid = ?", f.msgID).Count(&items)
	assert.Equal(t, int64(2), items)
}

func TestAutoSplitUndoIsTheOwnersOrAModerators(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("as_undo_auth")
	f := newSplitFixture(t, prefix)

	status, _ := splitAction(t, "", map[string]interface{}{"id": f.msgID, "action": "AutoSplitUndo"})
	assert.Equal(t, 401, status)

	other := CreateTestUser(t, prefix+"_other", "User")
	status, _ = splitAction(t, getToken(t, other), map[string]interface{}{"id": f.msgID, "action": "AutoSplitUndo"})
	assert.Equal(t, 403, status)

	var state string
	db.Table("messages_autosplit").Select("state").Where("msgid = ?", f.msgID).Scan(&state)
	assert.Equal(t, "Split", state, "a refused undo changes nothing")

	mod := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, mod, f.groupID, "Moderator")
	status, _ = splitAction(t, getToken(t, mod), map[string]interface{}{"id": f.msgID, "action": "AutoSplitUndo"})
	assert.Equal(t, 200, status)
}

func TestAutoSplitUndoIsRefusedOnceThePostHasAnOutcome(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_undo_out"))
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome) VALUES (?, 'Taken')", f.msgID)

	status, _ := splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "AutoSplitUndo"})
	assert.Equal(t, 409, status)
}

func TestUndoOnAPostThatWasNotSplitIsRefused(t *testing.T) {
	prefix := uniquePrefix("as_undo_none")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	msgID := CreateTestMessage(t, userID, groupID, prefix+" one thing", 52.5, -1.8)

	status, _ := splitAction(t, getToken(t, userID), map[string]interface{}{"id": msgID, "action": "AutoSplitUndo"})
	assert.Equal(t, 400, status)
}

func TestBulkItemAvailableMarksOneItemGone(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_avail"))

	status, out := splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "BulkItemAvailable", "bulkitemid": f.sofaID, "available": false})
	require.Equal(t, 200, status, out)
	assert.Equal(t, float64(1), out["availablenow"])

	var av int
	db.Table("messages_bulk_items").Select("available").Where("id = ?", f.sofaID).Scan(&av)
	assert.Equal(t, 0, av)

	// The last item going does not mark a split post Taken: its poster does that, and is
	// asked who took it.
	status, out = splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "BulkItemAvailable", "bulkitemid": f.bedID, "available": false})
	require.Equal(t, 200, status, out)
	assert.Equal(t, float64(0), out["availablenow"])
	var outcomes int64
	db.Table("messages_outcomes").Where("msgid = ?", f.msgID).Count(&outcomes)
	assert.Zero(t, outcomes)

	// And back on offer.
	status, out = splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "BulkItemAvailable", "bulkitemid": f.bedID, "available": true})
	require.Equal(t, 200, status, out)
	assert.Equal(t, float64(1), out["availablenow"])
}

func TestBulkItemAvailableOnAClearanceRecordsTakenWhenTheLastGoes(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("as_avail_clr")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	msgID := CreateTestMessage(t, userID, groupID, prefix+" clearance", 52.5, -1.8)
	itemID := addBulkItem(t, msgID, "Desk", 1, "Good")

	status, _ := splitAction(t, getToken(t, userID), map[string]interface{}{"id": msgID, "action": "BulkItemAvailable", "bulkitemid": itemID, "available": false})
	require.Equal(t, 200, status)
	var outcomes int64
	db.Table("messages_outcomes").Where("msgid = ? AND outcome = 'Taken'", msgID).Count(&outcomes)
	assert.Equal(t, int64(1), outcomes)
}

func TestBulkItemAvailableRefusesOthersAndOtherPostsItems(t *testing.T) {
	prefix := uniquePrefix("as_avail_auth")
	f := newSplitFixture(t, prefix)
	g := newSplitFixture(t, prefix+"b")

	other := CreateTestUser(t, prefix+"_other", "User")
	status, _ := splitAction(t, getToken(t, other), map[string]interface{}{"id": f.msgID, "action": "BulkItemAvailable", "bulkitemid": f.sofaID, "available": false})
	assert.Equal(t, 403, status)

	// An item from another post, through a post the caller owns.
	status, _ = splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "BulkItemAvailable", "bulkitemid": g.sofaID, "available": false})
	assert.Equal(t, 404, status)

	status, _ = splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "BulkItemAvailable", "bulkitemid": f.sofaID})
	assert.Equal(t, 400, status)
}

func TestTakenOnASplitPostMarksItsItemsGone(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_taken"))

	status, out := splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "Outcome", "outcome": "Taken"})
	require.Equal(t, 200, status, out)

	var stillAvailable int64
	db.Table("messages_bulk_items").Where("msgid = ? AND available = 1", f.msgID).Count(&stillAvailable)
	assert.Zero(t, stillAvailable)
}

func TestWithdrawnOnASplitPostLeavesItsItems(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_withdrawn"))

	status, out := splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "Outcome", "outcome": "Withdrawn"})
	require.Equal(t, 200, status, out)

	var stillAvailable int64
	db.Table("messages_bulk_items").Where("msgid = ? AND available = 1", f.msgID).Count(&stillAvailable)
	assert.Equal(t, int64(2), stillAvailable, "a withdrawn post's items were not taken")
}

func splitPatch(t *testing.T, token string, body map[string]interface{}) (int, string) {
	b, _ := json.Marshal(body)
	req := httptest.NewRequest("PATCH", "/api/message?jwt="+token, bytes.NewBuffer(b))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	return resp.StatusCode, string(rsp(resp))
}

func TestEditingASplitPostKeepsTheCropsOfKeptPhotos(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_patch_keep"))

	// The edit sends the photos the poster can see: just the member's photo.
	status, body := splitPatch(t, f.token, map[string]interface{}{"id": f.msgID, "attachments": []uint64{f.photoID}})
	require.Equal(t, 200, status, body)

	var crops int64
	db.Table("messages_attachments").Where("id = ?", f.cropID).Count(&crops)
	assert.Equal(t, int64(1), crops, "the crop stays while its photo does")
}

func TestEditingASplitPostDropsTheCropsOfRemovedPhotos(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_patch_drop"))
	newPhoto := CreateTestAttachment(t, f.msgID)

	status, body := splitPatch(t, f.token, map[string]interface{}{"id": f.msgID, "attachments": []uint64{newPhoto}})
	require.Equal(t, 200, status, body)

	var crops, photos int64
	db.Table("messages_attachments").Where("id = ?", f.cropID).Count(&crops)
	db.Table("messages_attachments").Where("id = ?", f.photoID).Count(&photos)
	assert.Zero(t, photos)
	assert.Zero(t, crops, "a crop goes with the photo it was cut from")
}

func TestTheOwnerOfASplitPostCanRecountAndRemoveItemsWithoutTheClearancePermission(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_patch_items"))
	lampID := addBulkItem(t, f.msgID, "Lamp", 1, "Unknown")
	var textBefore string
	db.Table("messages").Select("textbody").Where("id = ?", f.msgID).Scan(&textBefore)

	// Two sofas, no lamp; the client's own photo list is ignored.
	status, body := splitPatch(t, f.token, map[string]interface{}{
		"id": f.msgID,
		"bulkitems": []map[string]interface{}{
			{"id": f.sofaID, "name": "Sofa", "quantity": 2},
			{"id": f.bedID, "quantity": 1},
		},
	})
	require.Equal(t, 200, status, body)

	type itemRow struct {
		ID       uint64
		Name     string
		Quantity int
	}
	var items []itemRow
	db.Table("messages_bulk_items").Select("id, name, quantity").Where("msgid = ?", f.msgID).Order("id").Scan(&items)
	assert.Equal(t, []itemRow{{f.sofaID, "Sofa", 2}, {f.bedID, "Bed", 1}}, items)
	var gone int64
	db.Table("messages_bulk_items").Where("id = ?", lampID).Count(&gone)
	assert.Zero(t, gone)

	var textAfter string
	db.Table("messages").Select("textbody").Where("id = ?", f.msgID).Scan(&textAfter)
	assert.Equal(t, textBefore, textAfter, "the poster's own words stay; no clearance summary")

	var link uint64
	db.Table("messages_bulk_item_attachments").Select("bulkitemid").Where("attachmentid = ?", f.cropID).Scan(&link)
	assert.Equal(t, f.sofaID, link, "the sofa keeps its crop without the client sending it")
}

func TestASplitPostsItemsCannotBeRenamedAddedOrBorrowed(t *testing.T) {
	prefix := uniquePrefix("as_patch_rules")
	f := newSplitFixture(t, prefix)
	g := newSplitFixture(t, prefix+"b")

	cases := map[string][]map[string]interface{}{
		"renamed":            {{"id": f.sofaID, "name": "Phone me on 07700 900000"}, {"id": f.bedID}},
		"added":              {{"id": f.sofaID}, {"id": f.bedID}, {"name": "Free money", "quantity": 1}},
		"another post's":     {{"id": f.sofaID}, {"id": g.bedID}},
		"the same one twice": {{"id": f.sofaID}, {"id": f.sofaID}},
		"only one left":      {{"id": f.sofaID}},
	}
	for name, items := range cases {
		status, body := splitPatch(t, f.token, map[string]interface{}{"id": f.msgID, "bulkitems": items})
		assert.Equal(t, 400, status, name+": "+body)
	}

	var names []string
	database.DBConn.Table("messages_bulk_items").Where("msgid = ?", f.msgID).Order("id").Pluck("name", &names)
	assert.Equal(t, []string{"Sofa", "Bed"}, names, "nothing was written")
}

func TestARecountKeepsGoneItemsOutOfTheCount(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_patch_count"))
	status, _ := splitAction(t, f.token, map[string]interface{}{"id": f.msgID, "action": "BulkItemAvailable", "bulkitemid": f.sofaID, "available": false})
	require.Equal(t, 200, status)

	status, body := splitPatch(t, f.token, map[string]interface{}{
		"id":        f.msgID,
		"bulkitems": []map[string]interface{}{{"id": f.sofaID, "quantity": 1}, {"id": f.bedID, "quantity": 3}},
	})
	require.Equal(t, 200, status, body)

	var counts struct{ Availableinitially, Availablenow int }
	db.Table("messages").Select("availableinitially, availablenow").Where("id = ?", f.msgID).Scan(&counts)
	assert.Equal(t, 4, counts.Availableinitially)
	assert.Equal(t, 3, counts.Availablenow, "the sofa is still gone")
}

func TestAClearanceEditCannotTakeAnotherPostsPhotoOrItem(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("as_clr_steal")
	victim := newSplitFixture(t, prefix+"v")

	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	grantClearance(t, userID)
	msgID := CreateTestMessage(t, userID, groupID, prefix+" clearance", 52.5, -1.8)
	deskID := addBulkItem(t, msgID, "Desk", 1, "Good")

	status, body := splitPatch(t, getToken(t, userID), map[string]interface{}{
		"id": msgID,
		"bulkitems": []map[string]interface{}{
			{"id": deskID, "name": "Desk", "quantity": 1, "attachments": []uint64{victim.cropID}},
			{"id": victim.bedID, "name": "Bed", "quantity": 1, "attachments": []uint64{victim.photoID}},
		},
	})
	require.Equal(t, 200, status, body)

	var link uint64
	db.Table("messages_bulk_item_attachments").Select("bulkitemid").Where("attachmentid = ?", victim.cropID).Scan(&link)
	assert.Equal(t, victim.sofaID, link, "another post's photo stays on its own item")
	var bedName string
	db.Table("messages_bulk_items").Select("name").Where("id = ?", victim.bedID).Scan(&bedName)
	assert.Equal(t, "Bed", bedName)
	var bedLinks int64
	db.Table("messages_bulk_item_attachments").Where("bulkitemid = ?", victim.bedID).Count(&bedLinks)
	assert.Zero(t, bedLinks, "another post's item is not given photos either")
	var mine []string
	db.Table("messages_bulk_items").Where("msgid = ?", msgID).Pluck("name", &mine)
	assert.Equal(t, []string{"Desk"}, mine)
}

func TestRemovingASplitItemRemovesItsCrop(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_patch_rm"))
	lampID := addBulkItem(t, f.msgID, "Lamp", 1, "Unknown")

	status, body := splitPatch(t, f.token, map[string]interface{}{
		"id":        f.msgID,
		"bulkitems": []map[string]interface{}{{"id": f.bedID, "quantity": 1}, {"id": lampID, "quantity": 1}},
	})
	require.Equal(t, 200, status, body)

	var crops int64
	db.Table("messages_attachments").Where("id = ?", f.cropID).Count(&crops)
	assert.Zero(t, crops)
}

func TestItemsOfAnOrdinaryPostStillNeedTheClearancePermission(t *testing.T) {
	prefix := uniquePrefix("as_patch_gate")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, userID, groupID, "Member")
	msgID := CreateTestMessage(t, userID, groupID, prefix+" one thing", 52.5, -1.8)

	status, _ := splitPatch(t, getToken(t, userID), map[string]interface{}{
		"id":        msgID,
		"bulkitems": []map[string]interface{}{{"name": "Sofa", "quantity": 1}},
	})
	assert.Equal(t, 403, status)
}

func TestItemsOfAnUndoneSplitNeedTheClearancePermission(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_patch_undone"))
	db.Exec("UPDATE messages_autosplit SET state = 'Undone' WHERE msgid = ?", f.msgID)

	status, _ := splitPatch(t, f.token, map[string]interface{}{
		"id":        f.msgID,
		"bulkitems": []map[string]interface{}{{"name": "Sofa", "quantity": 1}},
	})
	assert.Equal(t, 403, status)
}

func TestApprovingASplitPostQueuesFreebieAlerts(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("as_fa")
	f := newSplitFixture(t, prefix)
	mod := CreateTestUser(t, prefix+"_mod", "Moderator")
	CreateTestMembership(t, mod, f.groupID, "Moderator")
	db.Exec("UPDATE messages_groups SET collection = 'Pending' WHERE msgid = ?", f.msgID)
	db.Exec("DELETE FROM background_tasks WHERE task_type = 'freebie_alerts_add' AND JSON_EXTRACT(data, '$.msgid') = ?", f.msgID)

	status, out := splitAction(t, getToken(t, mod), map[string]interface{}{"id": f.msgID, "action": "Approve"})
	require.Equal(t, 200, status, out)

	var n int64
	db.Raw("SELECT COUNT(*) FROM background_tasks WHERE task_type = 'freebie_alerts_add' AND JSON_EXTRACT(data, '$.msgid') = ?", f.msgID).Scan(&n)
	assert.Equal(t, int64(1), n, "a split post is an ordinary Offer for the partners")
}

func TestRotatingACropTurnsTheCropWithIt(t *testing.T) {
	db := database.DBConn
	f := newSplitFixture(t, uniquePrefix("as_rot"))

	body := fmt.Sprintf(`{"id":%d,"rotate":90,"type":"Message"}`, f.cropID)
	req := httptest.NewRequest("POST", "/api/image?jwt="+f.token, strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var mods string
	db.Table("messages_attachments").Select("externalmods").Where("id = ?", f.cropID).Scan(&mods)
	m, ok := misc.ParseMods(mods)
	require.True(t, ok, mods)
	// A quarter turn clockwise of (10, 20, 300x200) in 800x600 is (600-20-200, 10, 200x300) in 600x800.
	assert.Equal(t, misc.Mods{Rotate: 90, Cx: 380, Cy: 10, Cw: 200, Ch: 300, Fw: 600, Fh: 800}, m)
	assert.Contains(t, mods, `"precrop":true`)
}

func TestModsRotatedRoundTrips(t *testing.T) {
	m := misc.Mods{Cx: 10, Cy: 20, Cw: 300, Ch: 200, Fw: 800, Fh: 600}
	// Four quarter turns, one at a time, come back to the start.
	r := m
	for _, deg := range []int{90, 180, 270, 360} {
		r = r.Rotated(deg)
	}
	assert.Equal(t, misc.Mods{Rotate: 360, Cx: 10, Cy: 20, Cw: 300, Ch: 200, Fw: 800, Fh: 600}, r)

	// Half a turn at once.
	h := m.Rotated(180)
	assert.Equal(t, misc.Mods{Rotate: 180, Cx: 800 - 10 - 300, Cy: 600 - 20 - 200, Cw: 300, Ch: 200, Fw: 800, Fh: 600}, h)

	// Back by a quarter turn (-90 is 270 clockwise).
	b := m.Rotated(-90)
	assert.Equal(t, 200, b.Cw)
	assert.Equal(t, 300, b.Ch)

	// A crop without its photo size cannot be turned, so it is dropped.
	assert.Nil(t, misc.Mods{Cx: 1, Cy: 1, Cw: 5, Ch: 5}.Rotated(90).Crop())
}

func TestDeliveryUrlCarriesTheCrop(t *testing.T) {
	url := misc.GetImageDeliveryUrl("freegletusd-abc", `{"rotate":90,"cx":1,"cy":2,"cw":3,"ch":4,"fw":10,"fh":20,"precrop":true}`)
	assert.Contains(t, url, "&ro=90&cx=1&cy=2&cw=3&ch=4&precrop=true")
	assert.NotContains(t, url, "fw=")

	plain := misc.GetImageDeliveryUrl("freegletusd-abc", `{"rotate":90}`)
	assert.True(t, strings.HasSuffix(plain, "&ro=90"), plain)
	assert.False(t, misc.IsCrop(`{"rotate":90}`))
	assert.False(t, misc.IsCrop(`{"ai":true}`))
	assert.False(t, misc.IsCrop(``))
	assert.True(t, misc.IsCrop(cropMods))
}
