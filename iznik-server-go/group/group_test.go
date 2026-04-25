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
	database.InitDB()
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
	// Group with no volunteers should return empty slice.
	volunteers := GetGroupVolunteers(999999999)
	assert.NotNil(t, volunteers)
	assert.Len(t, volunteers, 0)
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
	// Test with nil settings pointer.
	result := isActiveModForGroup(nil)
	assert.False(t, result)
}

func TestIsActiveModForGroupEmptyString(t *testing.T) {
	// Test with empty settings string.
	settings := ""
	settingsPtr := &settings
	result := isActiveModForGroup(settingsPtr)
	assert.False(t, result)
}

func TestIsActiveModForGroupInvalidJSON(t *testing.T) {
	// Test with invalid JSON.
	settings := "not valid json"
	settingsPtr := &settings
	result := isActiveModForGroup(settingsPtr)
	assert.False(t, result)
}

func TestGetGroupBasic(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	// Create a test group
	testGroup := Group{
		Nameshort: "testgrp-" + time.Now().Format("20060102150405"),
		Namefull:  "Test Group",
		Type:      "Freegle",
		Publish:   1,
		Lat:       51.5074,
		Lng:       -0.1278,
	}
	result := db.Create(&testGroup)
	require.NoError(t, result.Error)
	defer db.Delete(&testGroup)

	// Create a test app
	app := fiber.New()
	app.Get("/api/group/:id", GetGroup)

	// Make request
	req := httptest.NewRequest("GET", fmt.Sprintf("/api/group/%d", testGroup.ID), nil)
	resp, err := app.Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Parse response
	var respGroup Group
	json.NewDecoder(resp.Body).Decode(&respGroup)
	assert.Equal(t, testGroup.ID, respGroup.ID)
	assert.Equal(t, testGroup.Nameshort, respGroup.Nameshort)
	assert.Equal(t, testGroup.Namefull, respGroup.Namefull)
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

	// Request with invalid ID format
	req := httptest.NewRequest("GET", "/api/group/invalid", nil)
	resp, err := app.Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestListGroupsBasic(t *testing.T) {
	db := database.DBConn
	require.NotNil(t, db)

	// Create test groups
	now := time.Now().Format("20060102150405")
	testGroups := []Group{
		{
			Nameshort: "testlist1-" + now,
			Namefull:  "Test List Group 1",
			Type:      "Freegle",
			Publish:   1,
			Lat:       51.5074,
			Lng:       -0.1278,
		},
		{
			Nameshort: "testlist2-" + now,
			Namefull:  "Test List Group 2",
			Type:      "Freegle",
			Publish:   1,
			Lat:       52.5074,
			Lng:       -1.1278,
		},
	}
	for _, g := range testGroups {
		db.Create(&g)
	}
	defer func() {
		for _, g := range testGroups {
			db.Delete(&g)
		}
	}()

	app := fiber.New()
	app.Get("/api/groups", ListGroups)

	// Make request
	req := httptest.NewRequest("GET", "/api/groups?limit=10&offset=0", nil)
	resp, err := app.Test(req, -1)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	// Parse response
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
