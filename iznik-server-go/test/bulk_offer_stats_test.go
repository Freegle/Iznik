package test

// Tests for bulk-offer lifecycle integration and per-item authority statistics.
// These tests cover three additions that were absent from the existing suite:
//
//  (a) Collected path uses recomputeBulkAvailableNow - no double-deduction when
//      the external-owner toggle has already fired for the same item.
//  (b) The last item collected (or toggled taken) inserts exactly one
//      messages_outcomes Taken row for the message, and a second trigger is a no-op.
//  (c) GetStatsByAuthority counts bulk items correctly: outcomes = SUM(bi.quantity)
//      for taken items, weight uses the items-table name match or fallback average,
//      replies count structured interest rows.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/authority"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestBulkCollectedPathRecomputesNoDoubleDeduction proves that when the
// external-owner toggle marks an item available=0 (firing recomputeBulkAvailableNow),
// a subsequent in-app BulkInterestState=Collected does NOT further subtract from
// availablenow — because the Collected path now calls the same recompute rather
// than a raw decrement.
//
// Scenario: two items, Item A and Item B, totalling 10 available.
// External toggle marks Item A unavailable → availablenow drops to 6 (only Item B left).
// Collected transition for Item A then fires.
//
// Without the fix the raw decrement would subtract the interest qty (2) again,
// leaving availablenow = 4 instead of 6.
// With the fix the recompute sees Item A still available=0 and returns 6.
func TestBulkCollectedPathRecomputesNoDoubleDeduction(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkdedupe")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	itemAID := addBulkItem(t, msgID, "DeskA", 4, "Good")
	addBulkItem(t, msgID, "ChairB", 6, "Good")

	// Seed availablenow to match the item totals.
	db.Exec("UPDATE messages SET availablenow = 10 WHERE id = ?", msgID)

	// Plant an interest row for Item A at qty=2, state=Reserved.
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 2, 'Reserved')",
		itemAID, msgID, wanterID)

	// --- Step 1: External-owner toggle marks Item A unavailable. ---
	// Call the logged-out POST endpoint to simulate the item-owner clicking "taken".
	token := fmt.Sprintf("edittok_%s_0123456789abcdef", prefix)
	setEditToken(t, msgID, token)
	res := postUpdate(t, token, map[string]interface{}{"itemid": itemAID, "available": false})
	require.Equal(t, 200, res.StatusCode, "external toggle should succeed")

	var availAfterToggle int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availAfterToggle)
	// availablenow should now be 6 (only ChairB with qty=6 remains available=1).
	assert.Equal(t, 6, availAfterToggle, "after external toggle, availablenow = ChairB qty = 6")

	// --- Step 2: In-app Collected transition for Item A. ---
	ownerToken := getToken(t, ownerID)
	body := map[string]interface{}{
		"action":     "BulkInterestState",
		"id":         msgID,
		"bulkitemid": itemAID,
		"userid":     wanterID,
		"state":      "Collected",
	}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", "/api/message?jwt="+ownerToken, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode, "Collected transition should succeed")

	// availablenow must stay at 6: the recompute sees Item A is still available=0
	// from the external toggle and ChairB unchanged at qty=6. Without the fix the
	// raw decrement would subtract 2 again, leaving 4.
	var availAfterCollected int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&availAfterCollected)
	assert.Equal(t, 6, availAfterCollected,
		"Collected recompute must not double-deduct an item already excluded by the external toggle")
}

// TestBulkLastItemCollectedInsertsOutcomeRowOnce proves that when collecting the
// last available units of a bulk offer, exactly one messages_outcomes Taken row
// is created, and a second trigger (the external-owner toggle firing again on the
// same item) is a no-op — the NOT EXISTS guard prevents duplication.
func TestBulkLastItemCollectedInsertsOutcomeRowOnce(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkoutcome")
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.95, -3.18)
	deskID := addBulkItem(t, msgID, "Desk", 2, "Good")
	db.Exec("UPDATE messages SET availablenow = 2 WHERE id = ?", msgID)
	// Interest at qty=2 (all of them), state=Reserved.
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 2, 'Reserved')",
		deskID, msgID, wanterID)

	// Verify no outcomes row exists yet.
	var priorCount int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", msgID).Scan(&priorCount)
	assert.Equal(t, int64(0), priorCount, "no outcomes row before collection")

	// --- Mark the entire desk quantity as Collected. ---
	ownerToken := getToken(t, ownerID)
	body := map[string]interface{}{
		"action":     "BulkInterestState",
		"id":         msgID,
		"bulkitemid": deskID,
		"userid":     wanterID,
		"state":      "Collected",
	}
	bb, _ := json.Marshal(body)
	req := httptest.NewRequest("POST", "/api/message?jwt="+ownerToken, bytes.NewBuffer(bb))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	// availablenow should be 0 (qty reduced 2→0, recompute = SUM where available=1 = 0).
	var avail int
	db.Raw("SELECT availablenow FROM messages WHERE id = ?", msgID).Scan(&avail)
	assert.Equal(t, 0, avail, "availablenow must be 0 after collecting all units")

	// Exactly one Taken outcomes row must exist.
	var afterCount int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ? AND outcome = 'Taken'", msgID).Scan(&afterCount)
	assert.Equal(t, int64(1), afterCount, "exactly one Taken outcomes row must be inserted")

	// --- Fire the external-owner toggle on the same item (already available=0). ---
	// This calls recomputeBulkAvailableNow again, then recordBulkOutcomeIfComplete.
	// The NOT EXISTS guard must prevent a duplicate outcomes row.
	token := fmt.Sprintf("edittok_%s_0123456789abcdef", prefix)
	setEditToken(t, msgID, token)
	res := postUpdate(t, token, map[string]interface{}{"itemid": deskID, "available": false})
	require.Equal(t, 200, res.StatusCode, "external toggle on already-taken item should still return 200")

	var finalCount int64
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ? AND outcome = 'Taken'", msgID).Scan(&finalCount)
	assert.Equal(t, int64(1), finalCount, "second trigger must not duplicate the outcomes row")

	// The fully-collected line must be marked available=0 so the external page
	// reflects it and the flip-day stats arms count its remaining quantity (0).
	var itemAvail int
	db.Raw("SELECT available FROM messages_bulk_items WHERE id = ?", deskID).Scan(&itemAvail)
	assert.Equal(t, 0, itemAvail, "fully-collected item must have available=0")
}

// TestBulkOfferAuthorityStats proves that GetStatsByAuthority includes bulk offer
// items in the per-postcode stats: outcomes count taken-item quantities (not just
// message counts), weight uses the items-table name match multiplied by quantity,
// and replies count structured interest rows.
//
// Fixture design: main_test.go's setupLocationTestData seeds location 1000001
// ("EH3 6SS", type=Postcode) and a matching locations_spatial entry at
// POINT(-3.205333 55.957571) SRID 3857. We build an authority polygon that
// contains that point and place the test message at locationid=1000001. This
// avoids creating new locations_spatial rows whose SRID/FK behaviour in the test
// DB would need separate validation.
//
// Join chain verified against the SQL in GetStatsByAuthority:
//
//	authorities.polygon (SRID 3857) ST_Contains locations_spatial.geometry (locid=1000001)
//	→ pc.locationid = 1000001
//	→ messages.locationid = 1000001
//	→ locations.id = 1000001, type='Postcode', name='EH3 6SS'
//	→ SUBSTRING('EH3 6SS', 1, 5) = "EH3 6"   [LENGTH=7, 7-2=5; LOCATE(' ',name)=4>0]
//
// Setup: one bulk offer with two items. Item A has available=0, qty=2 (remaining
// after 1 was collected in-app), and a Collected interest row for qty=1. Item B
// (qty=3, available=1) is live and excluded from Outcomes/Weight.
//
// Expected for partial postcode "EH3 6":
//   - Outcomes = 3   (available=0 arm: qty=2) + (interest-Collected arm: qty=1)
//   - Weight   = 30  (10 kg × 3 total units via items-table name match)
//   - Replies  = 1   (one interest row regardless of state)
func TestBulkOfferAuthorityStats(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("bulkstats")

	// locationid=1000001 is seeded by setupLocationTestData in TestMain:
	//   locations:         id=1000001, name='EH3 6SS', type='Postcode'
	//   locations_spatial: locationid=1000001, geometry=POINT(-3.205333 55.957571) SRID 3857
	const locID = uint64(1000001)
	const partialPostcode = "EH3 6" // SUBSTRING('EH3 6SS', 1, 7-2)

	// --- Create an authority whose polygon contains POINT(-3.205333 55.957571). ---
	// Coordinates match the convention in locations_spatial: degree-like values
	// stored in SRID 3857 as X=lng, Y=lat.
	authName := "TestAuth_" + prefix
	db.Exec("INSERT INTO authorities (name, polygon) VALUES (?, ST_GeomFromText('POLYGON((-4 55, -3 55, -3 57, -4 57, -4 55))', 3857))", authName)
	var authorityID uint64
	db.Raw("SELECT id FROM authorities WHERE name = ? ORDER BY id DESC LIMIT 1", authName).Scan(&authorityID)
	require.NotZero(t, authorityID, "test authority must be created")
	t.Cleanup(func() {
		db.Exec("DELETE FROM authorities WHERE id = ?", authorityID)
	})

	// --- Create an items-table entry for weight matching. ---
	ns := time.Now().UnixNano()
	itemName := fmt.Sprintf("TestStatChair_%d", ns)
	const knownWeight = 10.0
	db.Exec("INSERT INTO items (name, weight, popularity) VALUES (?, ?, 1)", itemName, knownWeight)
	var itemsRowID uint64
	db.Raw("SELECT id FROM items WHERE name = ? ORDER BY id DESC LIMIT 1", itemName).Scan(&itemsRowID)
	require.NotZero(t, itemsRowID, "test items row must be created")
	t.Cleanup(func() {
		db.Exec("DELETE FROM items WHERE id = ?", itemsRowID)
	})

	// --- Build a bulk offer message and point it at location 1000001. ---
	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	wanterID := CreateTestUser(t, prefix+"_wanter", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Clearance", 55.957571, -3.205333)
	// Override the locationid to the seeded test postcode so pc picks it up.
	db.Exec("UPDATE messages SET locationid = ? WHERE id = ?", locID, msgID)

	// Item A: qty=2 remaining (after 1 was collected in-app), available=0 (owner flipped).
	// Matches itemName in the items table for weight lookup.
	itemAID := addBulkItem(t, msgID, itemName, 2, "Good")
	db.Exec("UPDATE messages_bulk_items SET available = 0 WHERE id = ?", itemAID)
	// Item B: qty=3, no weight match, still available — excluded from Outcomes/Weight.
	addBulkItem(t, msgID, "NoWeightItemForStatsTest", 3, "Good")

	// Interest row for Item A: state=Collected, qty=1 (the in-app-collected portion).
	// Counted by the interest-Collected Outcomes/Weight arms; also counts as a reply.
	db.Exec("INSERT INTO messages_bulk_items_interest (bulkitemid, msgid, userid, quantity, state) VALUES (?, ?, ?, 1, 'Collected')",
		itemAID, msgID, wanterID)

	// --- Call GetStatsByAuthority. ---
	stats, err := authority.GetStatsByAuthority(authorityID, "365 days ago", "today")
	require.NoError(t, err)

	ps, found := stats[partialPostcode]
	require.True(t, found, "stats must include postcode %q (got keys: %v)", partialPostcode, statsKeys(stats))

	// Outcomes = 3: available=0 arm counts Item A's remaining qty=2; interest-Collected
	// arm counts mbii.quantity=1. Together = 3, not 1 per message.
	assert.Equal(t, 3, ps.Outcomes, "outcomes must sum remaining-at-flip (2) and in-app-collected (1)")

	// Weight = 30: 10 kg × 3 total units (both arms); Item B available=1 is excluded.
	assert.InDelta(t, 30.0, ps.Weight, 0.001, "weight must be items-table weight × total counted units (3)")

	// Replies = 1: the single interest row (Collected state counts as a reply).
	assert.Equal(t, 1, ps.Replies, "replies must count the interest row")
}

// statsKeys returns the postcode keys from a stats map for error messages.
func statsKeys(m map[string]authority.PostcodeStats) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}
	return keys
}
