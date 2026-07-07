package group

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"

	"github.com/freegle/iznik-server-go/database"
)

func init() {
	database.InitDatabase()
}

func TestValidateGeometryValidPolygon(t *testing.T) {
	// Valid WKT polygon should return true.
	valid := validateGeometry("POLYGON((0 0, 1 0, 1 1, 0 1, 0 0))")
	assert.True(t, valid)
}

func TestValidateGeometryValidPoint(t *testing.T) {
	// Valid WKT point should return true.
	valid := validateGeometry("POINT(0 0)")
	assert.True(t, valid)
}

func TestValidateGeometryInvalidWKT(t *testing.T) {
	// Invalid WKT should return false.
	valid := validateGeometry("INVALID WKT")
	assert.False(t, valid)
}

func TestValidateGeometryEmptyString(t *testing.T) {
	// Empty string should return false.
	valid := validateGeometry("")
	assert.False(t, valid)
}

func TestValidateGeometryInvalidCoordinates(t *testing.T) {
	// Non-numeric coordinates should return false.
	valid := validateGeometry("POLYGON((a b, c d))")
	assert.False(t, valid)
}

func TestGetGroupVolunteersEmpty(t *testing.T) {
	// Group with no volunteers should return empty (nil) slice.
	volunteers := GetGroupVolunteers(999999999)
	assert.Empty(t, volunteers)
}

func TestGetGroupVolunteersPopulated(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	now := time.Now().Format("20060102150405")
	nameshort := "test-vol-" + now

	result := db.Exec("INSERT INTO `groups` (nameshort, namefull, type, onhere, polyindex, lat, lng) VALUES (?, ?, 'Freegle', 1, ST_GeomFromText('POINT(0 0)', 3857), 0, 0)",
		nameshort, "Test Volunteer Group")
	require.NoError(t, result.Error)
	var groupID uint64
	db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", nameshort).Scan(&groupID)
	require.NotZero(t, groupID)
	defer db.Exec("DELETE FROM `groups` WHERE id = ?", groupID)

	// Create a user with showmod=1 so GetGroupVolunteers includes them.
	db.Exec("INSERT INTO users (firstname, lastname, fullname, settings) VALUES (?, ?, ?, ?)",
		"Mod", now, "Mod "+now, `{"showmod":1}`)
	var userID uint64
	db.Raw("SELECT id FROM users WHERE lastname = ? ORDER BY id DESC LIMIT 1", now).Scan(&userID)
	require.NotZero(t, userID)
	defer db.Exec("DELETE FROM users WHERE id = ?", userID)

	db.Exec("INSERT INTO memberships (userid, groupid, role, added) VALUES (?, ?, 'Moderator', NOW())",
		userID, groupID)
	defer db.Exec("DELETE FROM memberships WHERE userid = ? AND groupid = ?", userID, groupID)

	volunteers := GetGroupVolunteers(groupID)
	assert.Len(t, volunteers, 1)
	assert.Equal(t, userID, volunteers[0].ID)
}

func TestGroupTableName(t *testing.T) {
	// Group struct should map to groups table.
	var g Group
	assert.Equal(t, "groups", g.TableName())
}

func TestGroupProfileTableName(t *testing.T) {
	// GroupProfile struct should map to groups_images table.
	var gp GroupProfile
	assert.Equal(t, "groups_images", gp.TableName())
}

func TestGroupSponsorTableName(t *testing.T) {
	// GroupSponsor struct should map to groups_sponsorship table.
	var gs GroupSponsor
	assert.Equal(t, "groups_sponsorship", gs.TableName())
}

func TestGroupVolunteerTableName(t *testing.T) {
	// GroupVolunteer struct should map to groupvolunteers table.
	var gv GroupVolunteer
	assert.Equal(t, "groupvolunteers", gv.TableName())
}

func TestIsActiveModForGroupValidJSON(t *testing.T) {
	// Test with valid JSON containing active mods.
	settings := `{"modsonline": 1, "active": true}`
	settingsPtr := &settings
	result := isActiveModForGroup(settingsPtr)
	assert.True(t, result)
}

func TestIsActiveModForGroupInactiveJSON(t *testing.T) {
	// Test with valid JSON but no active mods.
	settings := `{"modsonline": 0, "active": false}`
	settingsPtr := &settings
	result := isActiveModForGroup(settingsPtr)
	assert.False(t, result)
}

func TestIsActiveModForGroupNilSettings(t *testing.T) {
	// Nil settings defaults to active (mod is shown by default).
	result := isActiveModForGroup(nil)
	assert.True(t, result)
}

func TestIsActiveModForGroupEmptyString(t *testing.T) {
	// Empty settings defaults to active (mod is shown by default).
	settings := ""
	settingsPtr := &settings
	result := isActiveModForGroup(settingsPtr)
	assert.True(t, result)
}

func TestIsActiveModForGroupInvalidJSON(t *testing.T) {
	// Invalid JSON defaults to active (mod is shown by default).
	settings := "not valid json"
	settingsPtr := &settings
	result := isActiveModForGroup(settingsPtr)
	assert.True(t, result)
}

func TestGetGroupBasic(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	nameshort := "testgrp-" + time.Now().Format("20060102150405")
	namefull := "Test Group"

	result := db.Exec("INSERT INTO `groups` (nameshort, namefull, type, publish, onhere, polyindex, lat, lng) VALUES (?, ?, 'Freegle', 1, 1, ST_GeomFromText('POINT(-0.1278 51.5074)', 3857), 51.5074, -0.1278)",
		nameshort, namefull)
	require.NoError(t, result.Error)
	var groupID uint64
	db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", nameshort).Scan(&groupID)
	require.NotZero(t, groupID)
	defer db.Exec("DELETE FROM `groups` WHERE id = ?", groupID)

	app := fiber.New()
	app.Get("/api/group/:id", GetGroup)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/group/%d", groupID), nil)
	resp, err := app.Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var respGroup Group
	json.NewDecoder(resp.Body).Decode(&respGroup)
	assert.Equal(t, groupID, respGroup.ID)
	assert.Equal(t, nameshort, respGroup.Nameshort)
	assert.Equal(t, namefull, respGroup.Namefull)
}

func TestGetGroupNotFound(t *testing.T) {
	app := fiber.New()
	app.Get("/api/group/:id", GetGroup)

	// Request non-existent group
	req := httptest.NewRequest("GET", "/api/group/999999999", nil)
	resp, err := app.Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestGetGroupInvalidID(t *testing.T) {
	app := fiber.New()
	app.Get("/api/group/:id", GetGroup)

	// GetGroup treats non-numeric IDs as "not found" (404).
	req := httptest.NewRequest("GET", "/api/group/invalid", nil)
	resp, err := app.Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 404, resp.StatusCode)
}

func TestListGroupsBasic(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	now := time.Now().Format("20060102150405")
	names := []string{"testlist1-" + now, "testlist2-" + now}
	lats := []float64{51.5074, 52.5074}
	lngs := []float64{-0.1278, -1.1278}
	var groupIDs []uint64

	for i, name := range names {
		db.Exec("INSERT INTO `groups` (nameshort, namefull, type, publish, onhere, polyindex, lat, lng) VALUES (?, ?, 'Freegle', 1, 1, ST_GeomFromText(?, 3857), ?, ?)",
			name, "Test List Group "+fmt.Sprintf("%d", i+1),
			fmt.Sprintf("POINT(%f %f)", lngs[i], lats[i]), lats[i], lngs[i])
		var id uint64
		db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", name).Scan(&id)
		if id > 0 {
			groupIDs = append(groupIDs, id)
		}
	}
	defer func() {
		for _, id := range groupIDs {
			db.Exec("DELETE FROM `groups` WHERE id = ?", id)
		}
	}()

	app := fiber.New()
	app.Get("/api/groups", ListGroups)

	req := httptest.NewRequest("GET", "/api/groups?limit=10&offset=0", nil)
	resp, err := app.Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	var respGroups []Group
	json.NewDecoder(resp.Body).Decode(&respGroups)
	assert.Greater(t, len(respGroups), 0)
}

func TestListGroupsWithLatLng(t *testing.T) {
	app := fiber.New()
	app.Get("/api/groups", ListGroups)

	// Request groups near a location
	req := httptest.NewRequest("GET", "/api/groups?lat=51.5074&lng=-0.1278&limit=10", nil)
	resp, err := app.Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Parse response
	var respGroups []Group
	json.NewDecoder(resp.Body).Decode(&respGroups)
	assert.NotNil(t, respGroups)
}

func TestGroupJSONMarshal(t *testing.T) {
	// Test that Group struct marshals/unmarshals correctly to JSON.
	g := Group{
		ID:       1,
		Nameshort: "test",
		Namefull: "Test Group",
		Lat:      51.5,
		Lng:      -0.1,
	}

	// Marshal to JSON
	data, err := json.Marshal(g)
	require.NoError(t, err)

	// Unmarshal back
	var g2 Group
	err = json.Unmarshal(data, &g2)
	require.NoError(t, err)
	assert.Equal(t, g.ID, g2.ID)
	assert.Equal(t, g.Nameshort, g2.Nameshort)
	assert.Equal(t, g.Lat, g2.Lat)
	assert.Equal(t, g.Lng, g2.Lng)
}

func TestGroupEntryJSONMarshal(t *testing.T) {
	// Test that GroupEntry struct marshals/unmarshals correctly to JSON.
	ge := GroupEntry{
		ID:       1,
		Nameshort: "test",
		Namefull: "Test Entry",
		Lat:      52.5,
		Lng:      -1.1,
	}

	data, err := json.Marshal(ge)
	require.NoError(t, err)

	var ge2 GroupEntry
	err = json.Unmarshal(data, &ge2)
	require.NoError(t, err)
	assert.Equal(t, ge.ID, ge2.ID)
	assert.Equal(t, ge.Nameshort, ge2.Nameshort)
	assert.Equal(t, ge.Lat, ge2.Lat)
}
