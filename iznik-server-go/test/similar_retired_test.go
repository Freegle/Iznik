package test

import (
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// The "More like this nearby" strip on the post page, and the sysadmin
// Recommendations tab that measured it, were removed: the funnel stats showed
// no useful value. Both routes must now be absent, not merely empty, so a
// client still asking for them gets a 404 rather than a quiet [].
func TestSimilarPostsRouteRetired(t *testing.T) {
	prefix := uniquePrefix("simret")
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix, utils.SYSTEMROLE_USER)
	CreateTestMembership(t, userID, groupID, utils.ROLE_MEMBER)
	msgID := CreateTestMessage(t, userID, groupID, "OFFER: Retired strip", 55.9533, -3.1883)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/message/"+fmt.Sprint(msgID)+"/similar", nil))
	assert.Equal(t, 404, resp.StatusCode, "the similar-posts route is gone")
}

func TestRecommendationsStatsRouteRetired(t *testing.T) {
	prefix := uniquePrefix("recret")
	supportID := CreateTestUser(t, prefix+"_support", utils.SYSTEMROLE_SUPPORT)
	token := getToken(t, supportID)

	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/modtools/recommendations/stats?jwt="+token, nil))
	assert.Equal(t, 404, resp.StatusCode, "the recommendations stats route is gone")
}
