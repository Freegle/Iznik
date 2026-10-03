package test

import (
	"bytes"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestCommunityEvent_InvalidID(t *testing.T) {
	// Non-integer ID should return 404
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/communityevent/notanint", nil))
	assert.Equal(t, 404, resp.StatusCode)
}

func TestCommunityEvent_InvalidGroupID(t *testing.T) {
	// Non-existent group should return empty array
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/communityevent/group/999999999", nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEvent_V2Path(t *testing.T) {
	// Verify v2 paths work
	prefix := uniquePrefix("eventv2")
	_, token := CreateFullTestUser(t, prefix)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/apiv2/communityevent?jwt="+token, nil))
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEventCreateUnauthorized(t *testing.T) {
	body := `{"title":"Test","location":"Edinburgh","description":"Test"}`
	req := httptest.NewRequest("POST", "/api/communityevent", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCommunityEventCreateMissingFields(t *testing.T) {
	prefix := uniquePrefix("cewr_miss")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Missing title
	body := `{"location":"Edinburgh","description":"Test"}`
	req := httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	// Missing location
	body = `{"title":"Test","description":"Test"}`
	req = httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)

	// Missing description
	body = `{"title":"Test","location":"Edinburgh"}`
	req = httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ = getApp().Test(req)
	assert.Equal(t, 400, resp.StatusCode)
}

func TestCommunityEventSaveUnauthorized(t *testing.T) {
	body := `{"id":1,"title":"Updated"}`
	req := httptest.NewRequest("PATCH", "/api/communityevent", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCommunityEventDeleteUnauthorized(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("DELETE", "/api/communityevent/1", nil))
	assert.Equal(t, 401, resp.StatusCode)
}

func TestCommunityEventCreateWithoutGroup(t *testing.T) {
	prefix := uniquePrefix("cewr_nogrp")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	// Regular user can create without a group (groups added separately via AddGroup).
	body := `{"title":"Test Event","location":"Edinburgh","description":"A test community event"}`
	req := httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestCommunityEventCreateNoGroupAdminAllowed(t *testing.T) {
	prefix := uniquePrefix("cewr_admngrp")
	adminID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, adminID)
	db := database.DBConn
	db.Exec("UPDATE users SET systemrole = 'Support' WHERE id = ?", adminID)

	// Admin/support can create without a group.
	body := `{"title":"Test Event","location":"Edinburgh","description":"A test community event"}`
	req := httptest.NewRequest("POST", "/api/communityevent?jwt="+token, bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := getApp().Test(req)
	assert.Equal(t, 200, resp.StatusCode)
}
