package test

import (
	json2 "encoding/json"
	"net/http/httptest"
	"os"
	"testing"

	"github.com/stretchr/testify/assert"
)

// The /status endpoint served /tmp/iznik.status, which was written by
// iznik-server/scripts/cron/status.php. That cron went with the V1 PHP removal
// (c14a7125b), leaving the endpoint permanently returning "Cannot access status
// file". The endpoint is retired; these tests pin it as gone so it cannot creep
// back without the file-writing half being restored too. Host and job health is
// now covered by monitor:email-health, monitor:scheduled-outcomes and the Sentry
// Crons scheduler heartbeat.
func TestStatusEndpointRetired(t *testing.T) {
	// Even with the old status file present, the route must not exist.
	statusJSON := `{"ret":0,"status":"OK","version":"1.0"}`
	err := os.WriteFile("/tmp/iznik.status", []byte(statusJSON), 0644)
	assert.NoError(t, err)
	defer os.Remove("/tmp/iznik.status")

	for _, path := range []string{"/api/status", "/apiv2/status"} {
		resp, _ := getApp().Test(httptest.NewRequest("GET", path, nil))
		assert.Equal(t, 404, resp.StatusCode, "expected %s to be retired", path)
	}
}

func TestGetVersion(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/version", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Contains(t, result, "build")
	assert.Contains(t, result, "commit")
	assert.Contains(t, result, "laravel_commit")
}

func TestGetVersionV2Path(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/apiv2/version", nil))
	assert.Equal(t, 200, resp.StatusCode)

	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Contains(t, result, "commit")
}
