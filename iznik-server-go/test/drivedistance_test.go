package test

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// The /drivedistance route must be WIRED through the real router (a missing
// registration 404s and the frontend swallows that silently — this test makes
// deregistration loud) and must reject anonymous callers.
func TestDriveDistanceRouteWiredAndAuthed(t *testing.T) {
	body := `{"targets":[{"id":1,"lat":51.45,"lng":-2.58}]}`
	req := httptest.NewRequest("POST", "/api/drivedistance", strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	// 401 (not 404): the route exists and the anonymous caller is rejected
	// before any location lookup.
	assert.Equal(t, 401, resp.StatusCode)
}

func TestDriveDistanceValidation(t *testing.T) {
	prefix := uniquePrefix("drivedist")
	userID, token := CreateFullTestUser(t, prefix)
	_ = userID

	// No targets.
	req := httptest.NewRequest("POST", "/api/drivedistance?jwt="+token, strings.NewReader(`{"targets":[]}`))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 400, resp.StatusCode)

	// Valid shape: must not 404/500 even with the routing server absent in
	// the test environment (fail-soft contract: empty results, HTTP 200).
	req = httptest.NewRequest("POST", "/api/drivedistance?jwt="+token, strings.NewReader(`{"targets":[{"id":1,"lat":51.45,"lng":-2.58}]}`))
	req.Header.Set("Content-Type", "application/json")
	resp, err = getApp().Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
}
