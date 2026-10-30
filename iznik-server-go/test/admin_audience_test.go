package test

import (
	json2 "encoding/json"
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// With no communities an ADMIN says who it goes to: members around a place, or, for Support and
// Admin only, the whole country. One that says neither is not approved, so it cannot go to everyone
// by default.

type adminAudience struct {
	National     bool
	Lat          *float64
	Reachminutes *int
}

func readAdminAudience(id uint64) adminAudience {
	var a adminAudience
	database.DBConn.Table("admins").Select("national, lat, reachminutes").Where("id = ?", id).Scan(&a)
	return a
}

// A moderator's ADMIN goes to members around them unless they say otherwise.
func TestAdminAudience_DefaultsToTheSendersArea(t *testing.T) {
	stubRouting(t, false)
	prefix := uniquePrefix("adm_area")
	modID := CreateTestUser(t, prefix, "Moderator")
	_, token := CreateTestSession(t, modID)

	status, result := postAdmin(t, token, map[string]interface{}{"subject": "Area " + prefix, "text": "Hello"})
	assert.Equal(t, 200, status, "%v", result)
	id := uint64(result["id"].(float64))
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM admins WHERE id = ?", id) })

	a := readAdminAudience(id)
	assert.False(t, a.National)
	assert.NotNil(t, a.Lat)
	assert.InDelta(t, 55.9533, *a.Lat, 0.001)
	assert.Equal(t, 30, *a.Reachminutes)
}

// Only Support or Admin can send to the whole country, as only they could send an ADMIN with no
// community before.
func TestAdminAudience_NationalIsForSupportOnly(t *testing.T) {
	prefix := uniquePrefix("adm_nat")
	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, supportToken := CreateTestSession(t, supportID)

	content := map[string]interface{}{"subject": "National " + prefix, "text": "Hello", "national": true}

	status, _ := postAdmin(t, modToken, content)
	assert.Equal(t, 403, status)

	status, result := postAdmin(t, supportToken, content)
	assert.Equal(t, 200, status, "%v", result)
	id := uint64(result["id"].(float64))
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM admins WHERE id = ?", id) })
	assert.True(t, readAdminAudience(id).National)

	// A moderator cannot approve it either.
	assert.Equal(t, 403, approveAdmin(t, modToken, id, ""))
	assert.Equal(t, 200, approveAdmin(t, supportToken, id, ""))
}

// An ADMIN that says neither national nor where it goes is refused at approval.
func TestAdminAudience_NoAudienceCannotBeApproved(t *testing.T) {
	stubRouting(t, false)
	prefix := uniquePrefix("adm_none")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	id := createTestAdmin(t, supportID, "None "+prefix)
	database.DBConn.Exec("UPDATE admins SET lat = NULL, lng = NULL WHERE id = ?", id)
	t.Cleanup(func() { database.DBConn.Exec("DELETE FROM admins WHERE id = ?", id) })

	assert.Equal(t, 400, approveAdmin(t, token, id, ""))

	body, _ := json2.Marshal(map[string]interface{}{"id": id, "lat": 51.5074, "lng": -0.1278})
	assert.Equal(t, 200, patchJSON(t, "/api/modtools/admin", token, string(body)))
	assert.Equal(t, 200, approveAdmin(t, token, id, ""), fmt.Sprintf("admin %d", id))
}
