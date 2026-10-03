package test

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The ModTools "find posts by member" search (GET /modtools/messages
// ?subaction=searchmemb) finds the matching members first and then their
// posts through the poster index, the way v1 did. The previous shape scanned
// the community's posts newest-first looking for one whose poster matched,
// which for a person with fewer posts than the page size - the usual case -
// had to read the whole community before it could stop, and on production's
// larger communities hit MAX_EXECUTION_TIME on every search and answered 500.
//
// The candidate query pins the memberships access path with FORCE INDEX on
// memberships_groupid_collection_emailfrequency, as the member search does,
// so these tests also fail loudly if that index is ever renamed.

func mtSearchMemberIDs(t *testing.T, token string, groupID uint64, term string) (int, []uint64) {
	t.Helper()
	u := fmt.Sprintf("/api/modtools/messages?collection=Approved&subaction=searchmemb&search=%s&jwt=%s",
		url.QueryEscape(term), token)
	if groupID > 0 {
		u += fmt.Sprintf("&groupid=%d", groupID)
	}
	resp, err := getApp().Test(httptest.NewRequest("GET", u, nil))
	require.NoError(t, err)

	var body map[string]interface{}
	_ = json.NewDecoder(resp.Body).Decode(&body)
	var ids []uint64
	if raw, ok := body["messages"].([]interface{}); ok {
		for _, id := range raw {
			ids = append(ids, uint64(id.(float64)))
		}
	}
	return resp.StatusCode, ids
}

func contains(ids []uint64, id uint64) bool {
	for _, v := range ids {
		if v == id {
			return true
		}
	}
	return false
}
