package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// Tests for PATCH /api/group (PatchGroup) polygon handling.
//
// Regression: clearing the DPA (poly) via ModTools Support returned HTTP 400
// "Invalid poly geometry" because an empty string was fed to validateGeometry.
// Clearing the DPA should be allowed - the group then falls back to the CGA
// (polyofficial) via the polyindex COALESCE, matching the old PHP behaviour.
// See https://discourse.ilovefreegle.org/t/error-when-editing-dpa-using-support-on-modtools/9867

func TestPatchGroupClearDPASucceedsAndFallsBackToCGA(t *testing.T) {
	prefix := uniquePrefix("PatchGrpClearDPA")
	groupID := CreateTestGroup(t, prefix)
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	defer func() {
		db.Exec("DELETE FROM memberships WHERE groupid = ?", groupID)
		db.Exec("DELETE FROM `groups` WHERE id = ?", groupID)
	}()

	// Set a DPA (poly) and a CGA (polyofficial); initialise polyindex from the DPA.
	dpa := "POLYGON((-0.1 51.5, -0.1 51.6, 0.0 51.6, 0.0 51.5, -0.1 51.5))"
	cga := "POLYGON((-0.2 51.4, -0.2 51.7, 0.1 51.7, 0.1 51.4, -0.2 51.4))"
	db.Exec("UPDATE `groups` SET poly = ?, polyofficial = ?, polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
		dpa, cga, dpa, utils.SRID, groupID)

	// Clear the DPA, exactly as ModTools Support does when the DPA box is emptied.
	body := fmt.Sprintf(`{"id":%d,"poly":""}`, groupID)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)

	assert.Equal(t, 200, resp.StatusCode, "clearing the DPA should succeed, not 400")

	// The DPA should now be cleared.
	var polyNull int64
	db.Raw("SELECT COUNT(*) FROM `groups` WHERE id = ? AND poly IS NULL", groupID).Scan(&polyNull)
	assert.Equal(t, int64(1), polyNull, "poly (DPA) should be NULL after clearing")

	// The CGA should be untouched.
	var cgaPresent int64
	db.Raw("SELECT COUNT(*) FROM `groups` WHERE id = ? AND polyofficial IS NOT NULL", groupID).Scan(&cgaPresent)
	assert.Equal(t, int64(1), cgaPresent, "polyofficial (CGA) should be unchanged when clearing the DPA")

	// polyindex should now fall back to the CGA.
	var matchesCGA int64
	db.Raw("SELECT ST_Equals(polyindex, ST_GeomFromText(polyofficial, ?)) FROM `groups` WHERE id = ?",
		utils.SRID, groupID).Scan(&matchesCGA)
	assert.Equal(t, int64(1), matchesCGA, "polyindex should fall back to the CGA when the DPA is cleared")
}

func TestPatchGroupSetValidDPAUpdatesPolyindex(t *testing.T) {
	prefix := uniquePrefix("PatchGrpSetDPA")
	groupID := CreateTestGroup(t, prefix)
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	defer func() {
		db.Exec("DELETE FROM memberships WHERE groupid = ?", groupID)
		db.Exec("DELETE FROM `groups` WHERE id = ?", groupID)
	}()

	dpa := "POLYGON((-0.1 51.5, -0.1 51.6, 0.0 51.6, 0.0 51.5, -0.1 51.5))"
	body := fmt.Sprintf(`{"id":%d,"poly":%q}`, groupID, dpa)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)

	assert.Equal(t, 200, resp.StatusCode, "setting a valid DPA should succeed")

	// poly stored.
	var polyPresent int64
	db.Raw("SELECT COUNT(*) FROM `groups` WHERE id = ? AND poly IS NOT NULL", groupID).Scan(&polyPresent)
	assert.Equal(t, int64(1), polyPresent, "poly (DPA) should be stored")

	// polyindex should track the new DPA.
	var matchesDPA int64
	db.Raw("SELECT ST_Equals(polyindex, ST_GeomFromText(poly, ?)) FROM `groups` WHERE id = ?",
		utils.SRID, groupID).Scan(&matchesDPA)
	assert.Equal(t, int64(1), matchesDPA, "polyindex should track the DPA when one is set")
}

func TestPatchGroupInvalidDPAStillRejected(t *testing.T) {
	prefix := uniquePrefix("PatchGrpBadDPA")
	groupID := CreateTestGroup(t, prefix)
	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	_, token := CreateTestSession(t, adminID)

	db := database.DBConn
	defer func() {
		db.Exec("DELETE FROM memberships WHERE groupid = ?", groupID)
		db.Exec("DELETE FROM `groups` WHERE id = ?", groupID)
	}()

	// A non-empty but invalid geometry must still be rejected.
	body := fmt.Sprintf(`{"id":%d,"poly":"NOT A POLYGON"}`, groupID)
	req := httptest.NewRequest("PATCH", fmt.Sprintf("/api/group?jwt=%s", token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)

	assert.Equal(t, 400, resp.StatusCode, "an invalid (non-empty) polygon should still return 400")
}
