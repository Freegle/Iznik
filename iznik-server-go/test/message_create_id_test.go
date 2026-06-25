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

// Regression guard for Discourse 9832 "mixed up offers": each created offer must
// receive the id of its OWN newly-inserted message. The old code read the new id
// back with "SELECT id FROM messages WHERE fromuser=? ORDER BY id DESC LIMIT 1",
// which the read/write split routes to a read replica - under replication lag
// that returned the user's PREVIOUS message, so a new offer (and its photos) got
// grafted onto an existing post. The create now uses the INSERT's LastInsertId.
//
// The replica-lag trigger can't be reproduced against the single test DB, so this
// is a contract guard: it pins that two consecutive creates return distinct ids
// each pointing at their own content (the second offer must not land on the first).
func TestCreateMessageReturnsItsOwnNewId(t *testing.T) {
	prefix := uniquePrefix("create-ownid")
	db := database.DBConn
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	var locationID uint64
	db.Raw("SELECT id FROM locations LIMIT 1").Scan(&locationID)
	require.NotZero(t, locationID, "need a location row for the offer")

	create := func(item, subject string) uint64 {
		payload := map[string]interface{}{
			"type":        "Offer",
			"messagetype": "Offer",
			"item":        item,
			"subject":     subject,
			"textbody":    "body for " + item,
			"collection":  "Draft",
			"groupid":     groupID,
			"locationid":  locationID,
		}
		s, _ := json.Marshal(payload)
		req := httptest.NewRequest("PUT", fmt.Sprintf("/api/message?jwt=%s", token), bytes.NewBuffer(s))
		req.Header.Set("Content-Type", "application/json")
		resp, _ := getApp().Test(req, 10000)
		require.Equal(t, 200, resp.StatusCode)
		var result map[string]interface{}
		json.NewDecoder(resp.Body).Decode(&result)
		idf, ok := result["id"].(float64)
		require.True(t, ok, "create must return a numeric id")
		return uint64(idf)
	}

	idA := create("Silk fabric", "OFFER: Silk fabric ("+prefix+")")
	idB := create("Cheese slicer", "OFFER: Cheese slicer ("+prefix+")")

	assert.NotZero(t, idA)
	assert.NotZero(t, idB)
	assert.NotEqual(t, idA, idB, "a second new offer must not reuse the first offer's message id")

	// Each id must point at its OWN content - the second create must not have
	// landed on (and overwritten/merged into) the first message.
	var subjA, subjB string
	db.Raw("SELECT subject FROM messages WHERE id = ?", idA).Scan(&subjA)
	db.Raw("SELECT subject FROM messages WHERE id = ?", idB).Scan(&subjB)
	assert.Contains(t, subjA, "Silk fabric", "first offer keeps its own subject")
	assert.Contains(t, subjB, "Cheese slicer", "second offer points at the newly-created message")
}
