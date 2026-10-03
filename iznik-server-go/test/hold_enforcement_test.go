package test

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// A "hold" is an exclusive moderator claim, but across most surfaces it was
// advisory only: ModTools shows "Held by X" and hides the buttons, while the
// server let anyone act. A moderator whose screen was stale then acted anyway -
// the bug behind Discourse 9946 on posts. These cover the same enforcement on the
// remaining surfaces, plus clearing the hold when the action is terminal so
// nothing stays pinned as "Held" forever.

// patchJSON PATCHes a JSON body and returns the status code.
func patchJSON(t *testing.T, path string, token string, body string) int {
	t.Helper()
	req := httptest.NewRequest("PATCH", fmt.Sprintf("%s?jwt=%s", path, token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	return resp.StatusCode
}

// postJSON POSTs a JSON body and returns the status code.
func postJSON(t *testing.T, path string, token string, body string) int {
	t.Helper()
	req := httptest.NewRequest("POST", fmt.Sprintf("%s?jwt=%s", path, token), strings.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, -1)
	assert.NoError(t, err)
	return resp.StatusCode
}

// --- spam_users ------------------------------------------------------------

// The holder used to be taken verbatim from the request body and never compared
// to the session user, so a client could hold a report as somebody else entirely.
func TestPatchSpammerCannotHoldAsAnotherUser(t *testing.T) {
	prefix := uniquePrefix("spam_holdas")
	modID, token := createSpamAdminUser(t, prefix)

	impersonated := CreateTestUser(t, prefix+"_other", "User")
	targetID := CreateTestUser(t, prefix+"_target", "User")
	spamID := createTestSpammer(t, targetID, "PendingAdd", "Suspicious")

	body := fmt.Sprintf(`{"id":%d,"collection":"PendingAdd","reason":"Suspicious","heldby":%d}`,
		spamID, impersonated)
	status := patchJSON(t, "/api/modtools/spammers", token, body)
	assert.Equal(t, 200, status)

	var heldby *uint64
	database.DBConn.Raw("SELECT heldby FROM spam_users WHERE id = ?", spamID).Scan(&heldby)
	assert.NotNil(t, heldby)
	assert.Equal(t, modID, *heldby, "the hold must be recorded against the acting mod, not the requested one")
}

func TestPatchSpammerBlockedWhenHeldByAnotherMod(t *testing.T) {
	prefix := uniquePrefix("spam_heldother")
	_, tokenA := createSpamAdminUser(t, prefix+"a")
	modB, tokenB := createSpamAdminUser(t, prefix+"b")

	targetID := CreateTestUser(t, prefix+"_target", "User")
	spamID := createTestSpammer(t, targetID, "PendingAdd", "Suspicious")

	// Mod A holds it.
	status := patchJSON(t, "/api/modtools/spammers", tokenA,
		fmt.Sprintf(`{"id":%d,"collection":"PendingAdd","reason":"Suspicious","heldby":1}`, spamID))
	assert.Equal(t, 200, status)

	// Mod B tries to resolve it.
	status = patchJSON(t, "/api/modtools/spammers", tokenB,
		fmt.Sprintf(`{"id":%d,"collection":"Spammer","reason":"Confirmed"}`, spamID))
	assert.Equal(t, 409, status, "resolving a report another mod holds must be refused")

	var collection string
	database.DBConn.Raw("SELECT collection FROM spam_users WHERE id = ?", spamID).Scan(&collection)
	assert.Equal(t, "PendingAdd", collection, "the report must be untouched")

	// Mod B must not be able to steal the hold either.
	status = patchJSON(t, "/api/modtools/spammers", tokenB,
		fmt.Sprintf(`{"id":%d,"collection":"PendingAdd","reason":"Suspicious","heldby":%d}`, spamID, modB))
	assert.Equal(t, 409, status, "taking another mod's hold must be refused")

	// ...but releasing is the escape hatch and stays available.
	status = patchJSON(t, "/api/modtools/spammers", tokenB,
		fmt.Sprintf(`{"id":%d,"collection":"PendingAdd","reason":"Suspicious"}`, spamID))
	assert.Equal(t, 200, status, "release must remain allowed")

	var heldby *uint64
	database.DBConn.Raw("SELECT heldby FROM spam_users WHERE id = ?", spamID).Scan(&heldby)
	assert.Nil(t, heldby, "release must clear the hold")
}

// --- admins ----------------------------------------------------------------

// --- memberships -----------------------------------------------------------
