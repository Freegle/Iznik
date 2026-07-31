package test

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// --- PATCH /message/tn/:tnpostid re-derives locationid from lat/lng (Discourse 9908) ---
//
// TN (Trash Nothing) edits arrive as lat/lng with no explicit locationid (see
// applyPatchMessageCore in message.go). Before the fix, messages.locationid stayed
// pinned to whatever it was when the message was first posted, so the rebuilt
// subject line and the owner/mod location display (both of which prefer
// locationid over raw lat/lng) kept showing the OLD postcode after a TN edit
// moved the pin — reported as a TN edit reverting a Milton Keynes postcode back
// to the original one.
//
// These two postcode locations are seeded once for all location tests by
// setupLocationTestData() in main_test.go, and are the only two points the
// in-process spatial mock (ensureSpatialMock) knows about, so KNN lookups
// against them are deterministic:
//
//	1687412  SA65 9ET  lat 52.006292  lng -4.939858  (areaid 999999 "Edinburgh")
//	1000001  EH3 6SS   lat 55.957571  lng -3.205333  (areaid 999999 "Edinburgh")
const (
	patchLocOldID  = uint64(1687412)
	patchLocOldLat = 52.006292
	patchLocOldLng = -4.939858
	patchLocNewID  = uint64(1000001)
	patchLocNewLat = 55.957571
	patchLocNewLng = -3.205333
)

// setupTnPatchLocationMessage creates a message pinned to the OLD test postcode
// (locationid, lat/lng, and a rebuilt-shaped subject), with a tnpostid and
// partner key so it can be edited via PATCH /message/tn/:tnpostid?partner=...
// exactly as a real TN client would.
func setupTnPatchLocationMessage(t *testing.T, prefix string) (msgID uint64, tnpostid string, key string) {
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	// users.tnuserid is UNIQUE, so a fixed value only works for the first test
	// in the run - every later one silently fails to become a TN partner and
	// gets a 403 instead of exercising the location derivation. Derive it from
	// the user id, which is already unique per test.
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 90000000+ownerID, ownerID)

	msgID = CreateTestMessage(t, ownerID, groupID, prefix+" original subject", patchLocOldLat, patchLocOldLng)

	itemName := prefix + "_item"
	db.Exec("INSERT INTO items (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", itemName)
	var itemID uint64
	db.Raw("SELECT id FROM items WHERE name = ?", itemName).Scan(&itemID)
	db.Exec("INSERT INTO messages_items (msgid, itemid) VALUES (?, ?)", msgID, itemID)

	// Give the message a subject in the same "KEYWORD: Item (Area PC)" shape a
	// real rebuild would produce, pinned to the OLD postcode, so a later
	// rebuild-to-the-NEW-postcode is unambiguous.
	oldSubject := fmt.Sprintf("OFFER: %s (Edinburgh SA65)", itemName)
	db.Exec("UPDATE messages SET locationid = ?, lat = ?, lng = ?, subject = ? WHERE id = ?",
		patchLocOldID, patchLocOldLat, patchLocOldLng, oldSubject, msgID)

	tnpostid = fmt.Sprintf("tn-patchloc-%s", prefix)
	db.Exec("UPDATE messages SET tnpostid = ? WHERE id = ?", tnpostid, msgID)

	key = insertTestPartnerKeyMsg(t, prefix, "tn.com")

	return msgID, tnpostid, key
}

// patchTnMessage sends a partner-authenticated PATCH /message/tn/:tnpostid, the
// same request shape a TN client uses.
func patchTnMessage(t *testing.T, tnpostid, key, prefix string, body map[string]interface{}) *http.Response {
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message/tn/%s?partner=%s&tnuserid=90001&email=%s@tn.com", tnpostid, key, prefix+"_owner")
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	return resp
}

// TestPatchMessageByTnDerivesLocationIdFromLatLng is the AssertFlip regression test
// for Discourse 9908. It drives applyPatchMessageCore end-to-end through
// PATCH /message/tn/:tnpostid with a TN-shaped request (lat/lng only, no
// locationid) and asserts BOTH the persisted messages.locationid and the
// rebuilt subject line move to the new coordinates. On unpatched master this
// fails: locationid stays at patchLocOldID and the subject is never rebuilt
// (the rebuild condition never fires because req.Item/Type/Location/Locationid
// are all nil for a coordinates-only PATCH).
func TestPatchMessageByTnDerivesLocationIdFromLatLng(t *testing.T) {
	prefix := uniquePrefix("patchloc_flip")
	db := database.DBConn
	msgID, tnpostid, key := setupTnPatchLocationMessage(t, prefix)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	resp := patchTnMessage(t, tnpostid, key, prefix, map[string]interface{}{
		"lat": patchLocNewLat,
		"lng": patchLocNewLng,
	})
	assert.Equal(t, 200, resp.StatusCode)

	type row struct {
		Locationid uint64
		Subject    string
	}
	var got row
	db.Raw("SELECT locationid, subject FROM messages WHERE id = ?", msgID).Scan(&got)

	assert.Equal(t, patchLocNewID, got.Locationid,
		"locationid should move to the postcode nearest the NEW lat/lng, not stay pinned to the original post's postcode (Discourse 9908)")
	assert.Contains(t, got.Subject, "Edinburgh EH3",
		"subject should be rebuilt using the NEW location once locationid is re-derived")
	assert.NotContains(t, got.Subject, "SA65",
		"subject must not still show the stale original postcode")
}

// TestPatchMessageByTnRejectsZeroZeroSentinelForLocationDerivation guards
// against the documented (0,0) Null Island KNN sentinel: it must never
// overwrite a previously-correct locationid.
func TestPatchMessageByTnRejectsZeroZeroSentinelForLocationDerivation(t *testing.T) {
	prefix := uniquePrefix("patchloc_00")
	db := database.DBConn
	msgID, tnpostid, key := setupTnPatchLocationMessage(t, prefix)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	resp := patchTnMessage(t, tnpostid, key, prefix, map[string]interface{}{
		"lat": 0,
		"lng": 0,
	})
	assert.Equal(t, 200, resp.StatusCode)

	var locationid uint64
	db.Raw("SELECT locationid FROM messages WHERE id = ?", msgID).Scan(&locationid)
	assert.Equal(t, patchLocOldID, locationid,
		"the (0,0) KNN sentinel must never overwrite a previously-correct locationid")
}

// TestPatchMessageByTnRejectsOutOfUKBoundsForLocationDerivation guards against
// coordinates nowhere near the UK. The in-process spatial mock always returns
// the nearest of its two known points regardless of distance, so without an
// explicit bounds check this would otherwise silently "succeed" with a bogus
// postcode.
func TestPatchMessageByTnRejectsOutOfUKBoundsForLocationDerivation(t *testing.T) {
	prefix := uniquePrefix("patchloc_oob")
	db := database.DBConn
	msgID, tnpostid, key := setupTnPatchLocationMessage(t, prefix)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// New York - a very long way outside the UK.
	resp := patchTnMessage(t, tnpostid, key, prefix, map[string]interface{}{
		"lat": 40.7128,
		"lng": -74.0060,
	})
	assert.Equal(t, 200, resp.StatusCode)

	var locationid uint64
	db.Raw("SELECT locationid FROM messages WHERE id = ?", msgID).Scan(&locationid)
	assert.Equal(t, patchLocOldID, locationid,
		"coordinates far outside the UK must never overwrite locationid")
}

// TestPatchMessageByTnRejectsDistantPostcodeForLocationDerivation guards
// against coordinates that ARE inside the UK bounding box but far from any
// postcode the spatial index actually knows about. The mock always returns
// its nearest known point regardless of distance, so this only passes because
// of the explicit max-distance check in derivePatchLocationID.
func TestPatchMessageByTnRejectsDistantPostcodeForLocationDerivation(t *testing.T) {
	prefix := uniquePrefix("patchloc_far")
	db := database.DBConn
	msgID, tnpostid, key := setupTnPatchLocationMessage(t, prefix)
	defer db.Exec("DELETE FROM partners_keys WHERE partner = ?", prefix+"_partner")

	// Central London: within the UK bounding box, but ~150+ miles from both
	// seeded test postcodes (Edinburgh/Pembrokeshire) - too far to trust.
	resp := patchTnMessage(t, tnpostid, key, prefix, map[string]interface{}{
		"lat": 51.5074,
		"lng": -0.1278,
	})
	assert.Equal(t, 200, resp.StatusCode)

	var locationid uint64
	db.Raw("SELECT locationid FROM messages WHERE id = ?", msgID).Scan(&locationid)
	assert.Equal(t, patchLocOldID, locationid,
		"a postcode too far from the supplied coordinates to trust must not overwrite locationid")
}

// TestPatchMessageDoesNotDeriveLocationIdForNonPartnerCaller confirms the
// derivation is scoped to partner (TN) requests. The Freegle web client always
// resolves its postcode picker to a locationid before submitting a PATCH (see
// stores/compose.js), and ModTools edits a message's location by name, not
// lat/lng (see modtools/components/ModMessage.vue) - so an unscoped derivation
// would only ever fire for a caller sending lat/lng without a locationid or
// location name, and should not silently replace what such a caller intended.
func TestPatchMessageDoesNotDeriveLocationIdForNonPartnerCaller(t *testing.T) {
	prefix := uniquePrefix("patchloc_nonpartner")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix)
	ownerID := CreateTestUser(t, prefix+"_owner", "User")
	CreateTestMembership(t, ownerID, groupID, "Member")
	_, token := CreateTestSession(t, ownerID)

	msgID := CreateTestMessage(t, ownerID, groupID, prefix+" Offer", patchLocOldLat, patchLocOldLng)
	db.Exec("UPDATE messages SET locationid = ?, lat = ?, lng = ? WHERE id = ?",
		patchLocOldID, patchLocOldLat, patchLocOldLng, msgID)

	body := map[string]interface{}{
		"id":  msgID,
		"lat": patchLocNewLat,
		"lng": patchLocNewLng,
	}
	bodyBytes, _ := json.Marshal(body)
	url := fmt.Sprintf("/api/message?jwt=%s", token)
	req := httptest.NewRequest("PATCH", url, bytes.NewBuffer(bodyBytes))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var locationid uint64
	db.Raw("SELECT locationid FROM messages WHERE id = ?", msgID).Scan(&locationid)
	assert.Equal(t, patchLocOldID, locationid,
		"a non-partner PATCH caller must not have locationid silently re-derived from lat/lng")
}
