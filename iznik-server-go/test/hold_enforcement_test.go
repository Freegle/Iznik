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

func adminHoldSetup(t *testing.T, prefix string) (uint64, uint64, string, string) {
	t.Helper()
	groupID := CreateTestGroup(t, prefix)
	modA := CreateTestUser(t, prefix+"_moda", "User")
	modB := CreateTestUser(t, prefix+"_modb", "User")
	CreateTestMembership(t, modA, groupID, "Moderator")
	CreateTestMembership(t, modB, groupID, "Moderator")
	_, tokenA := CreateTestSession(t, modA)
	_, tokenB := CreateTestSession(t, modB)

	adminID := createTestAdmin(t, modA, groupID, "Hold Test "+prefix)
	database.DBConn.Exec("UPDATE admins SET heldby = ? WHERE id = ?", modA, adminID)
	return adminID, modA, tokenA, tokenB
}

func TestPatchAdminBlockedWhenHeldByAnotherMod(t *testing.T) {
	adminID, _, _, tokenB := adminHoldSetup(t, uniquePrefix("adm_held"))

	status := patchJSON(t, "/api/modtools/admin", tokenB,
		fmt.Sprintf(`{"id":%d,"subject":"Hijacked"}`, adminID))
	assert.Equal(t, 409, status, "editing an admin message another mod holds must be refused")

	var subject string
	database.DBConn.Raw("SELECT subject FROM admins WHERE id = ?", adminID).Scan(&subject)
	assert.NotEqual(t, "Hijacked", subject)
}

func TestPatchAdminCompleteClearsHold(t *testing.T) {
	adminID, _, tokenA, _ := adminHoldSetup(t, uniquePrefix("adm_compl"))

	// complete is a *string on PatchAdminRequest, not a bool - the handler ignores
	// the value and stamps NOW(), but it still has to parse.
	status := patchJSON(t, "/api/modtools/admin", tokenA,
		fmt.Sprintf(`{"id":%d,"complete":"2026-07-21 12:00:00"}`, adminID))
	assert.Equal(t, 200, status)

	var heldby *uint64
	database.DBConn.Raw("SELECT heldby FROM admins WHERE id = ?", adminID).Scan(&heldby)
	assert.Nil(t, heldby, "completing is terminal so the hold must not stay pinned")
}

func TestAdminHoldDoesNotStealAnotherModsHold(t *testing.T) {
	adminID, modA, _, tokenB := adminHoldSetup(t, uniquePrefix("adm_steal"))

	body := fmt.Sprintf(`{"action":"Hold","id":%d}`, adminID)
	assert.Equal(t, 409, postJSON(t, "/api/modtools/admin", tokenB, body))

	var heldby *uint64
	database.DBConn.Raw("SELECT heldby FROM admins WHERE id = ?", adminID).Scan(&heldby)
	assert.NotNil(t, heldby)
	assert.Equal(t, modA, *heldby, "the original holder keeps the hold")
}

// --- memberships -----------------------------------------------------------

func membershipHoldSetup(t *testing.T, prefix string) (uint64, uint64, uint64, string) {
	t.Helper()
	groupID := CreateTestGroup(t, prefix)
	modA := CreateTestUser(t, prefix+"_moda", "User")
	modB := CreateTestUser(t, prefix+"_modb", "User")
	target := CreateTestUser(t, prefix+"_target", "User")
	CreateTestMembership(t, modA, groupID, "Moderator")
	CreateTestMembership(t, modB, groupID, "Moderator")
	CreateTestMembership(t, target, groupID, "Member")
	_, tokenB := CreateTestSession(t, modB)

	database.DBConn.Exec("UPDATE memberships SET heldby = ? WHERE userid = ? AND groupid = ?",
		modA, target, groupID)
	return groupID, target, modA, tokenB
}

func TestMembershipActionsBlockedWhenHeldByAnotherMod(t *testing.T) {
	for _, action := range []string{"Approve", "Reject", "Ban", "Hold", "ReviewHold", "ReviewIgnore"} {
		t.Run(action, func(t *testing.T) {
			groupID, target, modA, tokenB := membershipHoldSetup(t, uniquePrefix("mem_held"))

			body := fmt.Sprintf(`{"action":"%s","userid":%d,"groupid":%d}`, action, target, groupID)
			assert.Equal(t, 409, postJSON(t, "/api/memberships", tokenB, body), action+" must be refused while another mod holds the membership")

			// The membership and the hold must both survive.
			var heldby *uint64
			database.DBConn.Raw("SELECT heldby FROM memberships WHERE userid = ? AND groupid = ?",
				target, groupID).Scan(&heldby)
			assert.NotNil(t, heldby, action+" must not clear another mod's hold")
			assert.Equal(t, modA, *heldby)
		})
	}
}

func TestMembershipReleaseAllowedWhenHeldByAnotherMod(t *testing.T) {
	groupID, target, _, tokenB := membershipHoldSetup(t, uniquePrefix("mem_rel"))

	body := fmt.Sprintf(`{"action":"Release","userid":%d,"groupid":%d}`, target, groupID)
	assert.Equal(t, 200, postJSON(t, "/api/memberships", tokenB, body), "Release is the escape hatch and must stay available")

	var heldby *uint64
	database.DBConn.Raw("SELECT heldby FROM memberships WHERE userid = ? AND groupid = ?",
		target, groupID).Scan(&heldby)
	assert.Nil(t, heldby)
}

// Closing the review is terminal for this group, so it must not leave a heldby
// that nothing clears - otherwise the member shows as held again next time they
// are flagged.
func TestMembershipReviewIgnoreClearsOwnHold(t *testing.T) {
	prefix := uniquePrefix("mem_rvign")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	target := CreateTestUser(t, prefix+"_target", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	CreateTestMembership(t, target, groupID, "Member")
	_, token := CreateTestSession(t, modID)

	database.DBConn.Exec("UPDATE memberships SET heldby = ? WHERE userid = ? AND groupid = ?",
		modID, target, groupID)

	body := fmt.Sprintf(`{"action":"ReviewIgnore","userid":%d,"groupid":%d}`, target, groupID)
	assert.Equal(t, 200, postJSON(t, "/api/memberships", token, body))

	var heldby *uint64
	database.DBConn.Raw("SELECT heldby FROM memberships WHERE userid = ? AND groupid = ?",
		target, groupID).Scan(&heldby)
	assert.Nil(t, heldby, "ignoring the review must drop our own hold with it")
}
