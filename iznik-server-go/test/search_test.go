package test

import (
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

func TestGetWords(t *testing.T) {
	words := message.GetWords("Old sofa which is green")
	assert.Equal(t, 2, len(words))
	assert.Equal(t, "sofa", words[0])
	assert.Equal(t, "which", words[1])
}

func TestAPISearch_WithoutAuth(t *testing.T) {
	// Search without auth should still work (just won't record search history)
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/search/table", nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestAPISearch_WithMessageType(t *testing.T) {
	// Search with messagetype filter
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/search/sofa?messagetype=Offer", nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)

	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/message/search/sofa?messagetype=Wanted", nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)
}

func TestAPISearch_V2Path(t *testing.T) {
	// Verify v2 path works
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/apiv2/message/search/chair", nil), 60000)
	assert.Equal(t, 200, resp.StatusCode)
}
