package test

import (
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestConfig(t *testing.T) {
	// A non-allowlisted config key is not publicly readable.
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/config/wibble", nil))
	assert.Equal(t, 403, resp.StatusCode)
}
