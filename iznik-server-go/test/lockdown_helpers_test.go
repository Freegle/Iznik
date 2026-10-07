package test

import (
	json2 "encoding/json"
	"net/http"
	"testing"

	"github.com/stretchr/testify/assert"
)

// assertLockdownRefused checks the standard lockdown refusal shape used by every gate
// except downloads: 409 with {"ret":409,"status":"Changes are paused for a few hours
// while we deal with a security incident.","lockdown":true}. Shared across the
// lockdown_gates_*_test.go files so every gate test asserts the exact same JSON.
func assertLockdownRefused(t *testing.T, resp *http.Response) {
	t.Helper()
	assert.Equal(t, 409, resp.StatusCode)
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(409), result["ret"])
	assert.Equal(t, "Changes are paused for a few hours while we deal with a security incident.", result["status"])
	assert.Equal(t, true, result["lockdown"])
}

// assertLockdownDownloadRefused checks the download-specific lockdown refusal shape,
// the one Support and Admin are not exempt from.
func assertLockdownDownloadRefused(t *testing.T, resp *http.Response) {
	t.Helper()
	assert.Equal(t, 409, resp.StatusCode)
	var result map[string]interface{}
	json2.Unmarshal(rsp(resp), &result)
	assert.Equal(t, float64(409), result["ret"])
	assert.Equal(t, "Downloads are paused while we deal with a security incident.", result["status"])
	assert.Equal(t, true, result["lockdown"])
}
