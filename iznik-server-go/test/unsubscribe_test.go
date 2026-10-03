package test

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// TestUnsubscribeMissingParams: no u/k → 400.
func TestUnsubscribeMissingParams(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("POST", "/api/user/unsubscribe", nil), 60000)
	require.Equal(t, 400, resp.StatusCode)
}

// TestUnsubscribeRouteNotShadowedByUserID: /user/unsubscribe has to be registered before
// /user/:id?, otherwise "unsubscribe" is parsed as a user id and the endpoint is
// unreachable - the same trap relevantoff documents.
func TestUnsubscribeRouteNotShadowedByUserID(t *testing.T) {
	resp, _ := getApp().Test(httptest.NewRequest("GET", "/api/user/unsubscribe", nil), 60000)
	assert.Equal(t, 400, resp.StatusCode, "should reach the handler and complain about params, not fall through to GetUser")
}

// TestUnsubscribeTypesMatchBatch: the same category map is implemented in iznik-batch for
// the mailto: arm of the header, and apiv2 and batch-prod are on different hosts so
// neither can call the other. Neither test container can see the other language's tree,
// so the actual cross-language diff lives in scripts/check-unsubscribe-categories.mjs;
// this pins the Go side so a change here is deliberate and shows up in review next to the
// PHP one.
func TestUnsubscribeTypesMatchBatch(t *testing.T) {
	assert.Equal(t,
		"digest,events,volunteering,newsletter,relevant,chat,notifications,engagement,all,allexceptreplies",
		strings.Join(user.UnsubscribeTypes, ","))

	for _, one := range user.UnsubscribeTypes {
		assert.NotEmpty(t, user.UnsubscribeDescription(one), "%s needs a member-facing description", one)
	}
}
