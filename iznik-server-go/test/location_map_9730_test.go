package test

// Regression test for bug reported in topic 9730:
// Members with no postcode/location are shown on the map at the group's physical
// location instead of being shown as having no known location.
//
// Root cause: GetLatLng falls back to the most-recently-joined group's lat/lng
// when a user has no personal location set (no mylocation in settings, no
// lastlocation, no message location).
//
// Fix: GetLatLng should return zero/empty location for such users.

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	user2 "github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func TestGetLatLngNoLocationDoesNotFallBackToGroupCoords(t *testing.T) {
	db := database.DBConn

	prefix := uniquePrefix("latlng9730")

	// Create a group with a distinct known lat/lng (clearly non-zero London coords).
	groupLat := 51.5074
	groupLng := -0.1278
	groupName := fmt.Sprintf("TestGroup_%s", prefix)
	db.Exec(fmt.Sprintf(
		"INSERT INTO `groups` (nameshort, namefull, type, onhere, polyindex, lat, lng) "+
			"VALUES (?, ?, 'Freegle', 1, ST_GeomFromText('POINT(-0.1278 51.5074)', %d), ?, ?)",
		utils.SRID),
		groupName, "Test Group "+prefix, groupLat, groupLng)

	var groupID uint64
	db.Raw("SELECT id FROM `groups` WHERE nameshort = ? ORDER BY id DESC LIMIT 1", groupName).Scan(&groupID)
	require.NotZero(t, groupID, "test group must be created")
	defer db.Exec("DELETE FROM `groups` WHERE id = ?", groupID)

	// Create a user with NO personal location: null settings, null lastlocation.
	fullname := fmt.Sprintf("Test User %s", prefix)
	db.Exec(
		"INSERT INTO users (firstname, lastname, fullname, systemrole, lastlocation, settings) "+
			"VALUES ('Test', ?, ?, 'User', NULL, NULL)",
		prefix, fullname)

	var userID uint64
	db.Raw("SELECT id FROM users WHERE fullname = ? ORDER BY id DESC LIMIT 1", fullname).Scan(&userID)
	require.NotZero(t, userID, "test user must be created")
	defer db.Exec("DELETE FROM memberships WHERE userid = ?", userID)
	defer db.Exec("DELETE FROM sessions WHERE userid = ?", userID)
	defer db.Exec("DELETE FROM users_emails WHERE userid = ?", userID)
	defer db.Exec("DELETE FROM users WHERE id = ?", userID)

	// Join the user to the group — this is what triggers the buggy group-coord fallback.
	db.Exec("INSERT INTO memberships (userid, groupid, role, added) VALUES (?, ?, 'Member', NOW())", userID, groupID)

	// Resolve the user's location via the same function used by the members map.
	latlng := user2.GetLatLng(userID)

	// STEP 2 (INVERTED - AssertFlip): A user who has never set a postcode should have
	// NO known location (zero coords). The bug causes GetLatLng to return the group's
	// lat/lng instead, so these assertions FAIL on the current buggy code and will
	// PASS once the group-location fallback is removed.
	assert.Equal(t, float32(0), latlng.Lat,
		"user with no location must return lat=0, not group lat (%.4f)", groupLat)
	assert.Equal(t, float32(0), latlng.Lng,
		"user with no location must return lng=0, not group lng (%.4f)", groupLng)
}
