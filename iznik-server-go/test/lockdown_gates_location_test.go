package test

// Gate tests for location.go and shortlink.go (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md):
//   - PUT /locations (CreateLocation), PATCH /locations (UpdateLocation) and
//     POST /locations with action=Exclude (ExcludeLocation) are moderator/system-mod
//     tooling actions, refused outright while "mods" is held.
//   - POST /shortlink (PostShortlink) is a per-group moderator tool, refused outright
//     while "mods" is held.
//
// Uses lockdown.SetTestState (modsHeld(), defined in lockdown_gates_moderation_test.go)
// so these tests never write a real "lockdowns" row and cannot race the other packages'
// test binaries that `go test ./...` runs concurrently against the same test database.

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestLockdownRefusesCreateLocation(t *testing.T) {
	prefix := uniquePrefix("ld_loc_create")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	payload := map[string]interface{}{
		"name":    "Should Not Exist " + prefix,
		"polygon": "POLYGON((-3.19 55.95, -3.18 55.95, -3.18 55.96, -3.19 55.96, -3.19 55.95))",
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("PUT", "/api/locations?jwt="+modToken, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var count int64
	db.Raw("SELECT COUNT(*) FROM locations WHERE name = ?", "Should Not Exist "+prefix).Scan(&count)
	assert.Equal(t, int64(0), count, "no location must be created while mods is held")
}

func TestLockdownRefusesUpdateLocation(t *testing.T) {
	prefix := uniquePrefix("ld_loc_update")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "Moderator")
	_, modToken := CreateTestSession(t, modID)

	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Polygon', 55.95, -3.19)", prefix+"_loc")
	var locationID uint64
	db.Raw("SELECT id FROM locations WHERE name = ?", prefix+"_loc").Scan(&locationID)
	assert.Greater(t, locationID, uint64(0))

	restore := modsHeld()
	defer restore()

	payload := map[string]interface{}{
		"id":   locationID,
		"name": "Should Not Apply " + prefix,
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("PATCH", "/api/locations?jwt="+modToken, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var name string
	db.Raw("SELECT name FROM locations WHERE id = ?", locationID).Scan(&name)
	assert.Equal(t, prefix+"_loc", name, "location name must not change while mods is held")
}

func TestLockdownRefusesExcludeLocation(t *testing.T) {
	prefix := uniquePrefix("ld_loc_excl")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	db.Exec("INSERT INTO locations (name, type, lat, lng) VALUES (?, 'Polygon', 55.95, -3.19)", prefix+"_loc")
	var locationID uint64
	db.Raw("SELECT id FROM locations WHERE name = ?", prefix+"_loc").Scan(&locationID)
	assert.Greater(t, locationID, uint64(0))

	restore := modsHeld()
	defer restore()

	payload := map[string]interface{}{
		"id":      locationID,
		"groupid": groupID,
		"action":  "Exclude",
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", "/api/locations?jwt="+modToken, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var count int64
	db.Raw("SELECT COUNT(*) FROM locations_excluded WHERE locationid = ? AND groupid = ?", locationID, groupID).Scan(&count)
	assert.Equal(t, int64(0), count, "no exclusion must be created while mods is held")
}

func TestLockdownRefusesShortlinkCreate(t *testing.T) {
	prefix := uniquePrefix("ld_shortlink")
	db := database.DBConn

	modID := CreateTestUser(t, prefix+"_mod", "User")
	groupID := CreateTestGroup(t, prefix)
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	restore := modsHeld()
	defer restore()

	name := prefix + "_link"
	payload := map[string]interface{}{
		"name":    name,
		"groupid": groupID,
	}
	s, _ := json.Marshal(payload)
	req := httptest.NewRequest("POST", "/api/shortlink?jwt="+modToken, bytes.NewBuffer(s))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	var count int64
	db.Raw("SELECT COUNT(*) FROM shortlinks WHERE name = ?", name).Scan(&count)
	assert.Equal(t, int64(0), count, fmt.Sprintf("no shortlink named %s must be created while mods is held", name))
}
