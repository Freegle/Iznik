package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// A post with no embedding matches nothing, rather than falling back to
// something looser.
func TestSearchMatchesWithoutAnEmbeddingReturnNothing(t *testing.T) {
	prefix := uniquePrefix("searchnoembed")
	poster := CreateTestUser(t, prefix+"_poster", "User")
	msgID := CreateTestMessage(t, poster, "OFFER: No vector here", 51.5, -0.1)

	embedding.Global.SetEntries(nil)

	resp, _ := getApp().Test(httptest.NewRequest("GET",
		fmt.Sprintf("/api/message/%d/searchmatches", msgID), nil), 60000)
	require.Equal(t, 200, resp.StatusCode)

	var matches []message.SearchMatch
	require.NoError(t, json.Unmarshal(rsp(resp), &matches))
	assert.Empty(t, matches)
}
