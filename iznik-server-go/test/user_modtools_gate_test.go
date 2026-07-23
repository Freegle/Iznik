package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
)

// TestUserModtoolsFlagRequiresMod verifies the enrichUserForModtools gate: the client-supplied
// ?modtools=true flag must NOT leak a user's posting history (or other mod-only data) to an
// anonymous or non-moderator caller. Existing moderator tests (spammers_parity, group) cover that
// a genuine system moderator still receives modtools data, so this focuses on the leak being closed.
func TestUserModtoolsFlagRequiresMod(t *testing.T) {
	prefix := uniquePrefix("ModtoolsGate")
	groupID := CreateTestGroup(t, prefix)
	targetID := CreateTestUser(t, prefix+"_target", "User")
	CreateTestMembership(t, targetID, groupID, "Member")
	CreateTestMessage(t, targetID, groupID, "History post "+prefix, 55.9533, -3.1883)

	// Anonymous caller passing ?modtools=true must NOT receive posting history.
	resp, _ := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/user/%d?modtools=true", targetID), nil))
	assert.Equal(t, 200, resp.StatusCode)
	var anon map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&anon)
	assert.Nil(t, anon["messagehistory"], "anonymous ?modtools=true must not leak posting history")
}
