package test

import (
	"bytes"
	json2 "encoding/json"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestVolunteering_InvalidID(t *testing.T) {
	// Non-integer ID should return 404
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/volunteering/notanint", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestVolunteering_InvalidGroupID(t *testing.T) {
	// Non-existent group should return empty array
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/volunteering/group/999999999", nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteering_V2Path(t *testing.T) {
	// Verify v2 paths work
	prefix := uniquePrefix("volv2")
	_, token := CreateFullTestUser(t, prefix)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/apiv2/volunteering?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringCreateUnauthorized(t *testing.T) {
	body := `{"title":"Test","location":"Edinburgh","description":"Test"}`
	req := httptest.NewRequest("POST", "/api/volunteering", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestVolunteeringCreateMissingFields(t *testing.T) {
	prefix := uniquePrefix("volwr_miss")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Missing title
	body := `{"location":"Edinburgh","description":"Test"}`
	req := httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	// Missing location
	body = `{"title":"Test","description":"Test"}`
	req = httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	// Missing description
	body = `{"title":"Test","location":"Edinburgh"}`
	req = httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestVolunteeringSaveUnauthorized(t *testing.T) {
	body := `{"id":1,"title":"Updated"}`
	req := httptest.NewRequest("PATCH", "/api/volunteering", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestVolunteeringDeleteUnauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("DELETE", "/api/volunteering/1", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestVolunteeringCreateWithoutGroup(t *testing.T) {
	prefix := uniquePrefix("volwr_nogrp")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Regular user can create without a group (groups added separately via AddGroup).
	body := `{"title":"Test Vol","location":"Edinburgh","description":"A test volunteering opportunity"}`
	req := httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestVolunteeringCreateNoGroupAdminAllowed(t *testing.T) {
	prefix := uniquePrefix("volwr_admngrp")
	adminID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, adminID)
	db := database.DBConn
	db.Exec("UPDATE users SET systemrole = 'Support' WHERE id = ?", adminID)

	// Admin/support can create without a group.
	body := `{"title":"Test Vol","location":"Edinburgh","description":"A test volunteering opportunity"}`
	req := httptest.NewRequest("POST", "/api/volunteering?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

// A member of no communities at all should still be shown national opportunities.
func TestVolunteering_NationalOpsListedWithNoGroups(t *testing.T) {
	prefix := uniquePrefix("volnatnogrp")
	db := database.DBConn

	creatorID := CreateTestUser(t, prefix+"_creator", "User")
	db.Exec("INSERT INTO volunteering (userid, title, description, location, pending, deleted, expired) VALUES (?, 'National Only Vol', 'national desc', '', 0, 0, 0)", creatorID)
	var nationalOpID uint64
	db.Raw("SELECT id FROM volunteering WHERE userid = ? AND title = 'National Only Vol' ORDER BY id DESC LIMIT 1", creatorID).Scan(&nationalOpID)
	assert.Greater(t, nationalOpID, uint64(0))

	loneID := CreateTestUser(t, prefix+"_lone", "User")
	_, loneToken := CreateTestSession(t, loneID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/volunteering?jwt="+loneToken, nil))
	assert.Equal(t, 200, resp.StatusCode)
	var ids []uint64
	json2.Unmarshal(rsp(resp), &ids)
	assert.Contains(t, ids, nationalOpID, "member of no communities must still see national ops")
}
