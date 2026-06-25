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
