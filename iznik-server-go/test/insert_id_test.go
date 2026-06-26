package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Unit guard for the shared helper that the read/write-split id fixes route through
// (Discourse 9832 class). The returned id must be the AUTO_INCREMENT id of the row we just
// inserted, taken from the write connection - never a separate replica-routable SELECT.
// Also pins that ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id) reports the EXISTING row's id,
// which the items / isochrones_users fixes rely on.
func TestExecInsertGetID(t *testing.T) {
	db := database.DBConn
	name := "eiid " + uniquePrefix("execinsert")

	id, err := database.ExecInsertGetID(db,
		"INSERT INTO items (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", name)
	require.NoError(t, err)
	require.NotZero(t, id, "helper must return the new row id")

	var got string
	db.Raw("SELECT name FROM items WHERE id = ?", id).Scan(&got)
	assert.Equal(t, name, got, "returned id points at the row we just inserted")

	// Re-inserting the same unique name must return the SAME id via LAST_INSERT_ID(id),
	// not a new row - the "row already existed" path the upsert fixes depend on.
	id2, err := database.ExecInsertGetID(db,
		"INSERT INTO items (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", name)
	require.NoError(t, err)
	assert.Equal(t, id, id2, "upsert of an existing unique name reports its existing id")

	db.Exec("DELETE FROM items WHERE id = ?", id)
}

// Contract guard for address Create (REPLACE INTO users_addresses): the create must return the id
// of ITS OWN newly-written row, not a previous/other row a lagging read replica might surface.
// REPLACE INTO assigns a fresh AUTO_INCREMENT id, so reading it back from the write result
// (LastInsertId) is the only reliable way under the read/write split.
func TestCreateAddressReturnsItsOwnId(t *testing.T) {
	db := database.DBConn
	userID := CreateTestUser(t, uniquePrefix("addr-ownid"), "User")
	_, token := CreateTestSession(t, userID)

	var pafID uint64
	db.Raw("SELECT id FROM paf_addresses LIMIT 1").Scan(&pafID)
	require.NotZero(t, pafID, "need a paf_addresses row")

	body, _ := json.Marshal(map[string]interface{}{"pafid": pafID, "instructions": "test instructions"})
	req := httptest.NewRequest("PUT", fmt.Sprintf("/api/address?jwt=%s", token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)

	var out map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&out)
	idf, ok := out["id"].(float64)
	require.True(t, ok, "create must return a numeric id")
	id := uint64(idf)
	require.NotZero(t, id)

	// The returned id must point at the row we just created (our user, our pafid).
	var gotUser, gotPaf uint64
	db.Raw("SELECT userid FROM users_addresses WHERE id = ?", id).Scan(&gotUser)
	db.Raw("SELECT pafid FROM users_addresses WHERE id = ?", id).Scan(&gotPaf)
	assert.Equal(t, userID, gotUser, "returned id belongs to the creating user")
	assert.Equal(t, pafID, gotPaf, "returned id points at the address we just created")

	db.Exec("DELETE FROM users_addresses WHERE userid = ?", userID)
}


// ---- anondraft ----
// Regression guard for the read/write-split "INSERT then SELECT" bug in
// findOrCreateUserForDraft (message.go ~line 3511). The old code read the new
// session id back with
//
//	SELECT id FROM sessions WHERE userid=? ORDER BY id DESC LIMIT 1
//
// which the read/write split routes to a read replica. Under replication lag
// that query returned a stale/wrong session id, so the JWT embedded the id of
// a session belonging to a PREVIOUS user, breaking authentication for the
// anonymous-draft flow. The fix uses database.ExecInsertGetID to take the id
// from LastInsertId on the write connection.
//
// The replica-lag trigger cannot be reproduced in the single test DB, so this
// is a contract guard: two consecutive anon drafts with distinct new emails
// must each return a persistent.id (session id) that exists in the sessions
// table and belongs to THEIR OWN newly-created user — not the user from the
// previous INSERT.
func TestAnonDraftSessionReturnsItsOwnNewId(t *testing.T) {
	prefix := uniquePrefix("anondraft-sessionid")
	db := database.DBConn

	createAnonDraft := func(email string) (userID uint64, sessionID uint64) {
		payload := map[string]interface{}{
			"type":       "Offer",
			"item":       "Kettle " + email,
			"collection": "Draft",
			"email":      email,
		}
		body, _ := json.Marshal(payload)
		req := httptest.NewRequest("PUT", "/api/message", bytes.NewBuffer(body))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode, "anon draft with email %s must return 200", email)

		var out map[string]interface{}
		require.NoError(t, json.NewDecoder(resp.Body).Decode(&out))

		_, hasJWT := out["jwt"].(string)
		require.True(t, hasJWT, "anon draft for a brand-new email must return a jwt field")

		pers, hasPers := out["persistent"].(map[string]interface{})
		require.True(t, hasPers, "anon draft for a brand-new email must return a persistent map")

		uidf, _ := pers["userid"].(float64)
		sidf, _ := pers["id"].(float64)
		require.NotZero(t, uidf, "persistent.userid must be non-zero")
		require.NotZero(t, sidf, "persistent.id (sessionID) must be non-zero")
		return uint64(uidf), uint64(sidf)
	}

	email1 := fmt.Sprintf("anon1_%s@example.com", prefix)
	email2 := fmt.Sprintf("anon2_%s@example.com", prefix)

	userID1, sessionID1 := createAnonDraft(email1)
	userID2, sessionID2 := createAnonDraft(email2)

	assert.NotEqual(t, userID1, userID2,
		"two anon drafts with different emails must create two distinct users")
	assert.NotEqual(t, sessionID1, sessionID2,
		"each anon user must receive their own distinct session id")

	// Each session id returned in the JWT/persistent must exist in the sessions
	// table and belong to ITS OWN user. Before the ExecInsertGetID fix, the
	// stale SELECT could return the first user's session id for the second
	// user's JWT, so sessionID2 would resolve to userID1 rather than userID2.
	var owner1, owner2 uint64
	db.Raw("SELECT userid FROM sessions WHERE id = ?", sessionID1).Scan(&owner1)
	db.Raw("SELECT userid FROM sessions WHERE id = ?", sessionID2).Scan(&owner2)
	assert.Equal(t, userID1, owner1,
		"session from first anon draft must belong to first user")
	assert.Equal(t, userID2, owner2,
		"session from second anon draft must belong to second user, not the first")

	// Cleanup anon users created by this test.
	db.Exec("DELETE FROM sessions WHERE id IN (?, ?)", sessionID1, sessionID2)
	db.Exec("DELETE FROM users_emails WHERE userid IN (?, ?)", userID1, userID2)
	db.Exec("DELETE FROM users WHERE id IN (?, ?)", userID1, userID2)
}


// ---- itemsedit ----
func TestEditMessageCreatesNewItemAndLinks(t *testing.T) {
	prefix := uniquePrefix("edit-item-id")
	db := database.DBConn

	// Create isolated owner, group, and message.
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)
	groupID := CreateTestGroup(t, prefix)
	msgID := CreateTestMessage(t, userID, groupID, "OFFER: old-subject ("+prefix+")", 55.9533, -3.1883)

	// Confirm the item does not exist before the PATCH.
	newItemName := fmt.Sprintf("UniqueWidget_%s", prefix)
	var preExistingID uint64
	db.Raw("SELECT id FROM items WHERE name = ?", newItemName).Scan(&preExistingID)
	require.Zero(t, preExistingID, "test item must not exist before the PATCH (prefix collision)")

	// PATCH the message with the brand-new item name.
	payload := map[string]interface{}{
		"id":   msgID,
		"item": newItemName,
	}
	body, _ := json.Marshal(payload)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 10000)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode, "PATCH /message must return 200")

	// The item row must exist in the items table after the PATCH.
	var itemID uint64
	db.Raw("SELECT id FROM items WHERE name = ?", newItemName).Scan(&itemID)
	assert.NotZero(t, itemID,
		"items row must be created for the new item name; if 0, ExecInsertGetID returned stale id")

	// The message must be linked to the newly-created item via messages_items.
	var linkedItemID uint64
	db.Raw("SELECT itemid FROM messages_items WHERE msgid = ? LIMIT 1", msgID).Scan(&linkedItemID)
	assert.Equal(t, itemID, linkedItemID,
		"messages_items must link the message to the newly-created item id")
}


// ---- email ----
// TestAddEmailReturnsItsOwnNewId is a contract guard for the read/write-split fix
// in handleAddEmail (user/user.go ~1742). The old code retrieved the new row's id
// with "SELECT id FROM users_emails WHERE userid=? ORDER BY id DESC LIMIT 1",
// which the read/write split routes to a read replica; under replication lag that
// query can return a stale/previous email's id, so the caller gets the wrong
// emailid. The fix uses database.ExecInsertGetID which returns the INSERT's own
// LastInsertId on the write connection.
//
// The replica-lag trigger cannot be reproduced against the single test DB, so this
// is a contract guard: two consecutive AddEmail calls for distinct new addresses
// must return distinct, non-zero emailids, each pointing at the row that contains
// its own email address and the correct userid.
func TestAddEmailReturnsItsOwnNewId(t *testing.T) {
	prefix := uniquePrefix("add-email-ownid")
	db := database.DBConn

	// CreateTestUser inserts the user plus a primary email (prefix@test.com).
	// The two addresses below are additional secondaries, so they will always
	// hit the INSERT branch (not the "already on this user" early-return path).
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	addEmail := func(email string) uint64 {
		payload := map[string]interface{}{
			"action": "AddEmail",
			"email":  email,
		}
		s, _ := json.Marshal(payload)
		req := httptest.NewRequest("POST", fmt.Sprintf("/api/user?jwt=%s", token), bytes.NewBuffer(s))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)

		var result map[string]interface{}
		err = json.NewDecoder(resp.Body).Decode(&result)
		require.NoError(t, err, "response must be valid JSON")

		idf, ok := result["emailid"].(float64)
		require.True(t, ok, "AddEmail must return a numeric emailid; got %v", result)
		return uint64(idf)
	}

	emailA := fmt.Sprintf("extra-a-%s@test.com", prefix)
	emailB := fmt.Sprintf("extra-b-%s@test.com", prefix)

	idA := addEmail(emailA)
	idB := addEmail(emailB)

	assert.NotZero(t, idA, "first AddEmail must return a non-zero emailid")
	assert.NotZero(t, idB, "second AddEmail must return a non-zero emailid")
	assert.NotEqual(t, idA, idB, "two distinct new emails must receive distinct emailids")

	// Each returned id must point at its OWN row — the second INSERT must not
	// have been confused with (or landed on) the first row.
	type emailRow struct {
		Email  string
		Userid uint64
	}
	var rowA, rowB emailRow
	db.Raw("SELECT email, userid FROM users_emails WHERE id = ?", idA).Scan(&rowA)
	db.Raw("SELECT email, userid FROM users_emails WHERE id = ?", idB).Scan(&rowB)

	assert.Equal(t, emailA, rowA.Email, "first emailid must point at the first address")
	assert.Equal(t, userID, rowA.Userid, "first emailid must belong to the test user")
	assert.Equal(t, emailB, rowB.Email, "second emailid must point at the second address")
	assert.Equal(t, userID, rowB.Userid, "second emailid must belong to the test user")
}


// ---- story ----
// Regression guard for the read/write-split "INSERT then SELECT the new id" class
// of bug (Discourse #9832). POST /newsfeed {"action":"ConvertToStory","id":<nfid>}
// must return the id that came directly from LastInsertId, not from a subsequent
// SELECT that could be routed to a replica and return a stale (wrong) row under
// replication lag.
//
// The lag condition cannot be reproduced against the single test DB, so this is a
// contract guard: convert two different newsfeed entries in succession and assert
// that each returned story id is distinct and points at its own content (the second
// story must not land on the first story's row).
func TestConvertToStoryReturnsItsOwnNewId(t *testing.T) {
	prefix := uniquePrefix("nf2story-ownid")
	db := database.DBConn

	// ConvertToStory is mod-only, so the requesting user needs a Moderator
	// membership in at least one group.
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	// Two distinct newsfeed authors with distinct messages, so we can tell apart
	// which story row belongs to which conversion.
	posterA := CreateTestUser(t, prefix+"_pa", "User")
	posterB := CreateTestUser(t, prefix+"_pb", "User")

	msgA := "Freegle story A " + prefix
	msgB := "Freegle story B " + prefix
	nfA := CreateTestNewsfeed(t, posterA, 52.2, -0.1, msgA)
	nfB := CreateTestNewsfeed(t, posterB, 52.2, -0.1, msgB)

	convert := func(nfID uint64) uint64 {
		payload, _ := json.Marshal(map[string]interface{}{
			"action": "ConvertToStory",
			"id":     nfID,
		})
		req := httptest.NewRequest(
			"POST",
			fmt.Sprintf("/api/newsfeed?jwt=%s", modToken),
			bytes.NewBuffer(payload),
		)
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)

		var result map[string]interface{}
		json.NewDecoder(resp.Body).Decode(&result)
		idf, ok := result["id"].(float64)
		require.True(t, ok, "response must contain a numeric id")
		return uint64(idf)
	}

	storyIDA := convert(nfA)
	storyIDB := convert(nfB)

	assert.NotZero(t, storyIDA, "first story id must be non-zero")
	assert.NotZero(t, storyIDB, "second story id must be non-zero")
	assert.NotEqual(t, storyIDA, storyIDB,
		"each conversion must produce a distinct story id; equal ids indicate the second create read the first insert's id from a stale replica")

	// Each returned id must point at its OWN row - correct author and message text.
	var gotStoryA, gotStoryB string
	var gotUserA, gotUserB uint64
	db.Raw("SELECT story, userid FROM users_stories WHERE id = ?", storyIDA).
		Row().Scan(&gotStoryA, &gotUserA)
	db.Raw("SELECT story, userid FROM users_stories WHERE id = ?", storyIDB).
		Row().Scan(&gotStoryB, &gotUserB)

	assert.Equal(t, msgA, gotStoryA,
		"first story must carry the text of the first newsfeed entry")
	assert.Equal(t, posterA, gotUserA,
		"first story must be attributed to the first newsfeed author")
	assert.Equal(t, msgB, gotStoryB,
		"second story must carry the text of the second newsfeed entry; if it matches msgA the id was mis-routed to the first row")
	assert.Equal(t, posterB, gotUserB,
		"second story must be attributed to the second newsfeed author")
}


// ---- isochrone ----
// TestCreateIsochroneReturnsOwnNewId is a contract guard for two read/write-split
// "INSERT then SELECT id" fixes in PUT /api/isochrone:
//
//  1. EnsureIsochroneExists: the new isochrone row id is taken from ExecInsertGetID
//     (LastInsertId on the write connection) rather than a separate SELECT.
//
//  2. CreateIsochrone: the isochrones_users link id is likewise taken from
//     ExecInsertGetID, not from a read-replica-routable SELECT (Discourse 9832 class).
//
// EnsureIsochroneExists branch exercised: FALLBACK (else-branch, ~line 146).
// In the single test DB there is no live routing server, so both
// FetchIsochroneWKTFromRoutingServer and FetchIsochroneWKT return "".
// The fallback INSERT runs:
//
//	INSERT IGNORE INTO isochrones (locationid, transport, minutes, polygon)
//	SELECT ?, ?, ?, COALESCE(geometry, ST_GeomFromText(CONCAT('POINT(', lng, ' ', lat, ')'), ?))
//	FROM locations WHERE id = ?
//
// A location with lat/lng but NULL geometry is seeded so the COALESCE resolves
// to POINT(lng, lat) and the INSERT succeeds.
//
// Two consecutive creates with different transport types are used to assert that
// each call returns a distinct, non-zero isochrones_users id that points at a
// row owned by the calling user.
func TestCreateIsochroneReturnsOwnNewId(t *testing.T) {
	prefix := uniquePrefix("iso-create-id")
	db := database.DBConn

	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Seed a location with lat/lng.
	locName := fmt.Sprintf("TestLoc_%s", prefix)
	res := db.Exec(
		"INSERT INTO locations (name, type, lat, lng, canon, popularity) VALUES (?, 'Postcode', 55.9533, -3.1883, ?, 0)",
		locName, locName,
	)
	require.NoError(t, res.Error, "seed location with lat/lng")

	var locationID uint64
	db.Raw("SELECT id FROM locations WHERE name = ? ORDER BY id DESC LIMIT 1", locName).Scan(&locationID)
	require.NotZero(t, locationID, "seeded location must be findable")

	// Pre-seed a real-polygon isochrone for each transport we request, so EnsureIsochroneExists
	// returns the existing row early (isochrone.go ~93) and does NOT call the external
	// routing-server / Mapbox fetch. Those fetches hang in the test env (no routing service / no
	// Mapbox key) - an earlier version of this test timed out at 10s on exactly that. This keeps
	// the test deterministic and on the line under test here: the isochrones_users INSERT (277).
	// (The EnsureIsochroneExists INSERT branches at 135/146/153 only run on a cache miss, which
	// requires that external fetch, so they are not endpoint-testable in this env.)
	seedIso := func(transport string) {
		db.Exec(
			"INSERT INTO isochrones (locationid, transport, minutes, source, polygon) VALUES (?, ?, 15, 'Test', "+
				"ST_GeomFromText('POLYGON((-3.20 55.94,-3.16 55.94,-3.16 55.97,-3.20 55.97,-3.20 55.94))', 3857))",
			locationID, transport,
		)
	}
	seedIso("Walk")
	seedIso("Cycle")

	// create calls PUT /api/isochrone and returns the isochrones_users id from the response.
	create := func(transport string) uint64 {
		payload := map[string]interface{}{
			"locationid": locationID,
			"transport":  transport,
			"minutes":    15,
			"nickname":   "test-" + transport,
		}
		body, _ := json.Marshal(payload)
		req := httptest.NewRequest(
			"PUT",
			fmt.Sprintf("/api/isochrone?jwt=%s", token),
			bytes.NewBuffer(body),
		)
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode,
			"PUT /api/isochrone must return 200 for transport=%s", transport)

		var response map[string]interface{}
		require.NoError(t, json.NewDecoder(resp.Body).Decode(&response))

		idf, ok := response["id"].(float64)
		require.True(t, ok, "response must contain a numeric id for transport=%s", transport)
		id := uint64(idf)
		require.NotZero(t, id, "returned id must be non-zero for transport=%s", transport)
		return id
	}

	idWalk := create("Walk")
	idCycle := create("Cycle")

	// Different transport values produce different isochrone rows (unique key on
	// isochrones is (locationid, transport, minutes, source)), so each create must
	// link a distinct isochrones_users row.
	assert.NotEqual(t, idWalk, idCycle,
		"two creates with different transports must yield distinct isochrones_users ids")

	// Each returned id must point at an isochrones_users row owned by this user.
	// This is the core contract guard: under read-replica lag the old code could
	// read back a stale (or zero) id, causing the wrong row to be returned.
	for _, id := range []uint64{idWalk, idCycle} {
		var ownerID uint64
		db.Raw("SELECT userid FROM isochrones_users WHERE id = ?", id).Scan(&ownerID)
		assert.Equal(t, userID, ownerID,
			"isochrones_users row %d must belong to the creating user %d", id, userID)
	}
}


// ---- stripe ----
// Regression guard for the read/write-split "INSERT then SELECT the new id" bug in
// donations/stripeipn.go (handleChargeSucceeded). The old code read the donation id back
// with a separate SELECT routable to a Galera read replica; under replication lag that
// returned the WRONG row. The fix uses database.ExecInsertGetID which reads LastInsertId
// directly from the write connection.
//
// Replica-lag cannot be reproduced against the single test DB, so this is a contract guard:
// two consecutive charge.succeeded IPN calls with different amounts must each produce a
// distinct users_donations id pointing at its own correct amount. If ExecInsertGetID were
// replaced with a trailing SELECT the second call would sometimes return the first row's id,
// making idA == idB or amtB == £25.00 instead of £10.00.
func TestStripeIPNInsertsDonationWithCorrectId(t *testing.T) {
	prefix := uniquePrefix("stripe-ipn-ownid")
	db := database.DBConn

	// Create a user whose email appears in billing_details so the handler exercises the
	// billing-email user-match path (path 3 in matchDonorUser) rather than the
	// anonymous userid=NULL path.
	email := prefix + "@example.com"
	userID := CreateTestUserWithEmail(t, prefix, email)
	require.NotZero(t, userID)

	t.Cleanup(func() {
		// Remove in reverse FK order: notifications reference users, emails reference users.
		db.Exec("DELETE FROM users_donations WHERE TransactionID LIKE ?", prefix+"%")
		db.Exec("DELETE FROM users_notifications WHERE touser = ?", userID)
		db.Exec("DELETE FROM users_emails WHERE userid = ?", userID)
		db.Exec("DELETE FROM users WHERE id = ?", userID)
	})

	// buildEvent returns a minimal charge.succeeded webhook body accepted by StripeIPN.
	// charge.Customer is omitted so the handler never attempts a live Stripe API call.
	// payment_method_details.type is "card" (not "paypal") so the PayPal guard is skipped.
	buildEvent := func(chargeID string, amountPence int64) []byte {
		chargeJSON := fmt.Sprintf(
			`{"id":%q,"amount":%d,"payment_method_details":{"type":"card"},"description":"Test donation","billing_details":{"email":%q,"name":"Test Donor"},"metadata":{}}`,
			chargeID, amountPence, email,
		)
		return []byte(fmt.Sprintf(
			`{"type":"charge.succeeded","id":"evt_%s","data":{"object":%s}}`,
			chargeID, chargeJSON,
		))
	}

	fire := func(chargeID string, amountPence int64) uint64 {
		req := httptest.NewRequest("POST", "/api/stripeipn",
			bytes.NewBuffer(buildEvent(chargeID, amountPence)))
		req.Header.Set("Content-Type", "application/json")
		resp, err := getApp().Test(req, 10000)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode,
			"stripeipn must return 200 for charge %s", chargeID)

		var donationID uint64
		db.Raw("SELECT id FROM users_donations WHERE TransactionID = ? LIMIT 1",
			chargeID).Scan(&donationID)
		require.NotZero(t, donationID,
			"users_donations row must exist after IPN for charge %s", chargeID)
		return donationID
	}

	idA := fire(prefix+"_chA", 2500) // £25.00
	idB := fire(prefix+"_chB", 1000) // £10.00

	assert.NotEqual(t, idA, idB,
		"consecutive Stripe IPN calls must produce distinct donation ids")

	// Each id must point at its own row: wrong LastInsertId would return idA for the second
	// call, so idB's GrossAmount would be £25.00 instead of £10.00.
	var amtA, amtB float64
	db.Raw("SELECT GrossAmount FROM users_donations WHERE id = ?", idA).Scan(&amtA)
	db.Raw("SELECT GrossAmount FROM users_donations WHERE id = ?", idB).Scan(&amtB)
	assert.InDelta(t, 25.0, amtA, 0.01,
		"donation A (id=%d) must record £25.00", idA)
	assert.InDelta(t, 10.0, amtB, 0.01,
		"donation B (id=%d) must record £10.00 (not £25.00 from donation A)", idB)
}
