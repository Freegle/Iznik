package test

// Handler tests for the lockdown switch's own endpoints (plan sections 11.2 and 11.11,
// plans/active/2026-09-27-lockdown-switch.md): GET /lockdown, GET /modtools/lockdown,
// GET /modtools/lockdown/history, PATCH /lockdown. Stats and the held-items browser are in
// lockdown_held_test.go.
//
// Unlike every lockdown_gates_*_test.go file, these tests cannot use lockdown.SetTestState:
// they are testing the persistence layer itself - whether PATCH actually writes and reads
// back the right row - so they write real "lockdowns" rows through the real HTTP endpoint.
// lockdown.go's own doc comment on SetTestState names this file as the accepted exception,
// the same tradeoff lockdown/lockdown_test.go's own setActiveLockdownRow already makes. Every
// test cleans up its own incident directly (not via PATCH close, so a test of close itself
// still has a route to reach) before returning, keeping the exposure window to other
// packages' concurrently running test binaries as short as possible.

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/lockdown"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// cleanupIncident deletes every row of one incident directly: lockdowns (the pressing row
// plus every surfaces/notice/... row that followed it, all sharing incidentid),
// lockdown_holds and lockdown_counters, plus any spam_users rows a test added.
//
// Must call lockdown.Invalidate() after the deletes: Current() (lockdown/lockdown.go) caches
// the newest row for TTL (5s in production) so a per-item gate check costs a memory read, not
// a query. A raw DELETE against the table does not touch that cache, so without this the next
// test's press() - run milliseconds later, well inside the TTL - still reads the just-deleted
// incident as active from the cache and refuses with 409, which then cascades: every other
// press test in this file fails the same way, and every gate elsewhere that holds a surface
// stays held for as long as the stale cache lives. Found via a real 95-test failure run where
// the very first cascade was exactly this "press must succeed... actual: 409" on the test
// immediately following one whose cleanup had already run.
func cleanupIncident(t *testing.T, incidentID uint64, spamUserIDs ...uint64) {
	t.Helper()
	db := database.DBConn
	db.Exec("DELETE FROM lockdowns WHERE incidentid = ? OR id = ?", incidentID, incidentID)
	db.Exec("DELETE FROM lockdown_holds WHERE lockdownid = ?", incidentID)
	db.Exec("DELETE FROM lockdown_counters WHERE lockdownid = ?", incidentID)
	for _, uid := range spamUserIDs {
		db.Exec("DELETE FROM spam_users WHERE userid = ?", uid)
	}
	lockdown.Invalidate()
}

func patchLockdown(t *testing.T, token string, payload map[string]interface{}) (*http.Response, map[string]interface{}) {
	t.Helper()
	body, _ := json.Marshal(payload)
	req := httptest.NewRequest("PATCH", "/api/lockdown?jwt="+token, bytes.NewBuffer(body))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	return resp, result
}

// pressLockdown presses a fresh incident and returns its incidentid, failing the test
// immediately if the press itself did not succeed - every other test here builds on a
// working press, so there is no point letting them run against a broken one.
func pressLockdown(t *testing.T, token string, reason string) uint64 {
	t.Helper()
	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "press", "reason": reason})
	require.Equal(t, 200, resp.StatusCode, "press must succeed: %v", result)
	incidentID := uint64(result["incidentid"].(float64))
	require.NotZero(t, incidentID)
	return incidentID
}

func getModtoolsLockdown(t *testing.T, token string) (*http.Response, map[string]interface{}) {
	t.Helper()
	url := "/api/modtools/lockdown"
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest("GET", url, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	return resp, result
}

// --- GET /lockdown (public) ---

func TestGetLockdownPublicShape(t *testing.T) {
	prefix := uniquePrefix("ld_pub")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "test incident "+prefix)
	defer cleanupIncident(t, incidentID)

	// Fresh press sets no notice - GET /lockdown must show none yet, and must never leak
	// active/surfaces to an anonymous caller.
	req := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	assert.Nil(t, result["notice"])
	_, hasActive := result["active"]
	_, hasSurfaces := result["surfaces"]
	assert.False(t, hasActive, "GET /lockdown must never return active")
	assert.False(t, hasSurfaces, "GET /lockdown must never return surfaces")

	presp, _ := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "  We're dealing with a spam attack.  "})
	assert.Equal(t, 200, presp.StatusCode)

	req2 := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp2, err := getApp().Test(req2)
	require.NoError(t, err)
	var result2 map[string]interface{}
	json.Unmarshal(rsp(resp2), &result2)
	notice, ok := result2["notice"].(map[string]interface{})
	require.True(t, ok, "notice must be an object once set: %v", result2)
	assert.Equal(t, "We're dealing with a spam attack.", notice["text"], "the text is returned trimmed, as written")
	_, hasKey := notice["key"]
	assert.False(t, hasKey)
}

// --- GET /modtools/lockdown ---

func TestGetModtoolsLockdownAnyModerator(t *testing.T) {
	prefix := uniquePrefix("ld_mt_mod")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, supportToken := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, supportToken, "mod visibility "+prefix)
	defer cleanupIncident(t, incidentID)

	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	resp, result := getModtoolsLockdown(t, modToken)
	assert.Equal(t, 200, resp.StatusCode)
	assert.Equal(t, true, result["active"])
	assert.Equal(t, float64(incidentID), result["incidentid"])
	assert.NotNil(t, result["surfaces"])
	assert.Equal(t, "mod visibility "+prefix, result["reason"])
	assert.Equal(t, float64(supportID), result["startedby"])
	assert.NotEmpty(t, result["startedbyname"])
	_, hasPhrases := result["phrases"]
	assert.False(t, hasPhrases, "there are no incident phrases")
	assert.Nil(t, result["notice"], "no notice was set")
}

func TestGetModtoolsLockdownRefusesPlainMember(t *testing.T) {
	prefix := uniquePrefix("ld_mt_member")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := getModtoolsLockdown(t, token)
	assert.Equal(t, 403, resp.StatusCode)
}

func TestGetModtoolsLockdownRefusesUnauthenticated(t *testing.T) {
	resp, _ := getModtoolsLockdown(t, "")
	assert.Equal(t, 401, resp.StatusCode)
}

// --- PATCH /lockdown auth wiring ---

func TestPatchLockdownRefusesUnauthenticated(t *testing.T) {
	resp, _ := patchLockdown(t, "", map[string]interface{}{"action": "press", "reason": "x"})
	assert.Equal(t, 401, resp.StatusCode)
}

func TestPatchLockdownRefusesPlainMember(t *testing.T) {
	prefix := uniquePrefix("ld_patch_member")
	userID := CreateTestUser(t, prefix, "User")
	_, token := CreateTestSession(t, userID)

	resp, _ := patchLockdown(t, token, map[string]interface{}{"action": "press", "reason": "x"})
	assert.Equal(t, 403, resp.StatusCode)
}

func TestPatchLockdownRefusesPlainModerator(t *testing.T) {
	prefix := uniquePrefix("ld_patch_mod")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	resp, _ := patchLockdown(t, token, map[string]interface{}{"action": "press", "reason": "x"})
	assert.Equal(t, 403, resp.StatusCode, "a community moderator is not Support/Admin")
}

// --- PATCH /lockdown action=press ---

func TestPatchLockdownPressFullPreset(t *testing.T) {
	prefix := uniquePrefix("ld_press")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "press", "reason": "spam wave " + prefix})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	incidentID := uint64(result["incidentid"].(float64))
	require.NotZero(t, incidentID)
	defer cleanupIncident(t, incidentID)

	assert.Equal(t, result["id"], result["incidentid"], "a fresh press must carry its own row id as incidentid")
	assert.Equal(t, true, result["active"])
	surfaces, ok := result["surfaces"].(map[string]interface{})
	require.True(t, ok)
	for _, k := range []string{"chat", "posts", "chitchat", "events", "email", "push", "export", "mods"} {
		assert.Equal(t, true, surfaces[k], "surface %s must be held by the full press preset", k)
	}
	_, hasChatMode := surfaces["chat_mode"]
	assert.False(t, hasChatMode, "chat has one mode, so it is not reported")
	assert.Equal(t, float64(supportID), result["startedby"])
}

func TestPatchLockdownPressRequiresReason(t *testing.T) {
	prefix := uniquePrefix("ld_press_noreason")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	resp, _ := patchLockdown(t, token, map[string]interface{}{"action": "press"})
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPatchLockdownPressRefusesWhenAlreadyActive(t *testing.T) {
	prefix := uniquePrefix("ld_press_twice")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "first "+prefix)
	defer cleanupIncident(t, incidentID)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "press", "reason": "second " + prefix})
	assert.Equal(t, 409, resp.StatusCode, "%v", result)
}

// --- PATCH /lockdown action=surfaces ---

func TestPatchLockdownSurfacesPartialUpdate(t *testing.T) {
	prefix := uniquePrefix("ld_surfaces")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "surfaces "+prefix)
	defer cleanupIncident(t, incidentID)

	resp, result := patchLockdown(t, token, map[string]interface{}{
		"action":   "surfaces",
		"surfaces": map[string]interface{}{"mods": false, "export": false},
	})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	surfaces := result["surfaces"].(map[string]interface{})
	assert.Equal(t, false, surfaces["mods"])
	assert.Equal(t, false, surfaces["export"])
	// Untouched surfaces from the press preset must survive the partial update.
	assert.Equal(t, true, surfaces["posts"])
	assert.Equal(t, true, surfaces["chat"])
}

func TestPatchLockdownSurfacesRefusesWhenNotActive(t *testing.T) {
	prefix := uniquePrefix("ld_surfaces_inactive")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "close then surfaces "+prefix)
	closeResp, _ := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "done"})
	require.Equal(t, 200, closeResp.StatusCode)
	defer cleanupIncident(t, incidentID)

	resp, _ := patchLockdown(t, token, map[string]interface{}{
		"action":   "surfaces",
		"surfaces": map[string]interface{}{"mods": true},
	})
	assert.Equal(t, 409, resp.StatusCode)
}

// --- Finding 6: every PATCH decides against a fresh, locked read, not Current()'s cache ---

// TestPatchLockdownPressRefusesEvenWithStaleCacheSayingInactive poisons the process-level
// Current() cache with a state saying no incident is active, while a real one is - the shape
// the pre-fix code got wrong, since it based the whole PATCH on Current() and would have let a
// second press through, opening a second incident on top of the first. SetTestState cannot
// reach loadStateForUpdate at all (it overrides the loadLatest package var; the locked read
// calls loadStateFrom directly), so this only passes once PatchLockdown reads the real row.
func TestPatchLockdownPressRefusesEvenWithStaleCacheSayingInactive(t *testing.T) {
	prefix := uniquePrefix("ld_press_stale_cache")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "stale cache press "+prefix)
	defer cleanupIncident(t, incidentID)

	restore := lockdown.SetTestState(lockdown.State{Active: false})
	defer restore()

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "press", "reason": "second " + prefix})
	assert.Equal(t, 409, resp.StatusCode, "must refuse against the real active row, not the poisoned cache saying inactive: %v", result)
}

// TestPatchLockdownSurfacesMergesFromFreshRowNotStaleCache models the actual production race:
// one apiv2 process writes a new lockdowns row and calls Invalidate() on ITS OWN cache, but a
// second process's five-second Current() cache is unaffected and still holds the older
// surfaces. A raw, un-invalidated write stands in for that other process. If patchSurfaces
// merged its change onto the stale cached surfaces instead of the fresh row, the concurrent
// write lowering "mods" would be silently reverted the moment this process's next surfaces
// PATCH landed.
func TestPatchLockdownSurfacesMergesFromFreshRowNotStaleCache(t *testing.T) {
	prefix := uniquePrefix("ld_surf_fresh_row")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "surfaces fresh row "+prefix)
	defer cleanupIncident(t, incidentID)

	// pressLockdown's own response already warmed this process's Current() cache with every
	// surface true - the state the merge must NOT use.
	concurrentSurfaces, err := json.Marshal(map[string]interface{}{
		"chat": true, "posts": true, "chitchat": true, "events": true,
		"email": true, "push": true, "export": true, "mods": false, "chat_mode": "hard",
	})
	require.NoError(t, err)
	require.NoError(t, db.Table("lockdowns").Create(map[string]interface{}{
		"incidentid": incidentID, "active": 1, "surfaces": string(concurrentSurfaces),
		"changedby": supportID, "reason": "concurrent write " + prefix,
	}).Error)

	resp, result := patchLockdown(t, token, map[string]interface{}{
		"action":   "surfaces",
		"surfaces": map[string]interface{}{"chat": false},
	})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	surfaces := result["surfaces"].(map[string]interface{})
	assert.Equal(t, false, surfaces["chat"], "the surface this PATCH lowered")
	assert.Equal(t, false, surfaces["mods"], "must stay lowered from the fresh row, not reverted from the stale cache")
	assert.Equal(t, true, surfaces["posts"], "an untouched surface must be carried over as the fresh row had it")
}

// --- PATCH /lockdown action=notice ---

func TestPatchLockdownNoticeAllowedAfterClose(t *testing.T) {
	prefix := uniquePrefix("ld_notice_afterclose")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "notice after close "+prefix)
	defer cleanupIncident(t, incidentID)
	closeResp, _ := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "done"})
	require.Equal(t, 200, closeResp.StatusCode)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "Things are back to normal."})
	assert.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, "Things are back to normal.", result["notice"])
}

// TestGetLockdownNoticeClearedOnCloseWhenSecurity is finding 7: GetLockdown used to show
// s.Notice unconditionally, and close carried the notice forward unchanged, so members kept
// seeing "we're dealing with a spam attack" long after the incident that caused it had ended.
func TestGetLockdownNoticeClearedOnClose(t *testing.T) {
	prefix := uniquePrefix("ld_notice_close_sec")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "notice close security "+prefix)
	defer cleanupIncident(t, incidentID)

	nresp, _ := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "Spam attack: don't click voucher links."})
	require.Equal(t, 200, nresp.StatusCode)

	req := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	notice, ok := result["notice"].(map[string]interface{})
	require.True(t, ok, "notice must show while the incident is active: %v", result)
	assert.Equal(t, "Spam attack: don't click voucher links.", notice["text"])

	closeResp, _ := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "resolved " + prefix})
	require.Equal(t, 200, closeResp.StatusCode)

	req2 := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp2, err := getApp().Test(req2)
	require.NoError(t, err)
	var result2 map[string]interface{}
	json.Unmarshal(rsp(resp2), &result2)
	assert.Nil(t, result2["notice"], "the incident's notice must not linger for members once it is closed: %v", result2)
}

// TestGetLockdownNormalNoticeExpiresAfter24Hours: unlike delay/security, "normal" (the settled
// all-clear) is allowed to be set with no incident active at all, and is meant to show for a
// while rather than forever - finding 7 sets that window at 24 hours from the row that set it.
func TestGetLockdownNoticeAfterCloseExpiresAfter24Hours(t *testing.T) {
	prefix := uniquePrefix("ld_notice_expire")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "notice expiry "+prefix)
	defer cleanupIncident(t, incidentID)
	closeResp, _ := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "resolved " + prefix})
	require.Equal(t, 200, closeResp.StatusCode)

	nresp, nresult := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "Things are back to normal."})
	require.Equal(t, 200, nresp.StatusCode, "%v", nresult)
	rowID := uint64(nresult["id"].(float64))

	req := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	notice, ok := result["notice"].(map[string]interface{})
	require.True(t, ok, "a notice set after close must show: %v", result)
	assert.Equal(t, "Things are back to normal.", notice["text"])

	// lockdowns.created has no ON UPDATE clause (2026_09_27_000001_create_lockdown_tables.php),
	// so back-dating it directly is safe and cannot be reset by anything else touching the row.
	require.NoError(t, db.Exec("UPDATE lockdowns SET created = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id = ?", rowID).Error)
	lockdown.Invalidate()

	req2 := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp2, err := getApp().Test(req2)
	require.NoError(t, err)
	var result2 map[string]interface{}
	json.Unmarshal(rsp(resp2), &result2)
	assert.Nil(t, result2["notice"], "a notice set after close stops showing after 24 hours: %v", result2)
}

// --- PATCH /lockdown action=liftall ---

func TestPatchLockdownLiftall(t *testing.T) {
	prefix := uniquePrefix("ld_liftall")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "liftall "+prefix)
	defer cleanupIncident(t, incidentID)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "liftall"})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, true, result["active"], "liftall must leave the incident active - only close ends it")
	surfaces := result["surfaces"].(map[string]interface{})
	for _, k := range []string{"chat", "posts", "chitchat", "events", "email", "push", "export", "mods"} {
		assert.Equal(t, false, surfaces[k], "surface %s must be lowered by liftall", k)
	}
}

// --- PATCH /lockdown action=close ---

func TestPatchLockdownClose(t *testing.T) {
	prefix := uniquePrefix("ld_close")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "close "+prefix)
	defer cleanupIncident(t, incidentID)

	nresp, _ := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "Spam attack in progress."})
	require.Equal(t, 200, nresp.StatusCode)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "resolved " + prefix})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, false, result["active"])
	surfaces := result["surfaces"].(map[string]interface{})
	for _, k := range []string{"chat", "posts", "chitchat", "events", "email", "push", "export", "mods"} {
		assert.Equal(t, false, surfaces[k], "surface %s must be cleared by close", k)
	}

	assert.Nil(t, result["notice"], "close clears the incident's notice")
}

func TestPatchLockdownCloseRequiresEndnote(t *testing.T) {
	prefix := uniquePrefix("ld_close_noendnote")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "close no endnote "+prefix)
	defer cleanupIncident(t, incidentID)

	resp, _ := patchLockdown(t, token, map[string]interface{}{"action": "close"})
	assert.Equal(t, 400, resp.StatusCode)
}

// --- GET /modtools/lockdown/history ---

func TestGetModtoolsLockdownHistory(t *testing.T) {
	prefix := uniquePrefix("ld_history")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "history "+prefix)
	defer cleanupIncident(t, incidentID)
	presp, _ := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "delay"})
	require.Equal(t, 200, presp.StatusCode)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/history?jwt="+token, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var rows []map[string]interface{}
	json.Unmarshal(rsp(resp), &rows)
	require.NotEmpty(t, rows)

	var seenPress, seenNotice bool
	for _, row := range rows {
		if uint64(row["incidentid"].(float64)) == incidentID {
			if row["reason"] == "history "+prefix {
				seenPress = true
			}
			if row["notice"] == "delay" {
				seenNotice = true
			}
		}
	}
	assert.True(t, seenPress, "history must include the pressing row")
	assert.True(t, seenNotice, "history must include the notice row")
}

// TestGetModtoolsLockdownHistorySurfaces: each row carries what was held after it, so the tab
// can say which area a row lifted.
func TestGetModtoolsLockdownHistorySurfaces(t *testing.T) {
	prefix := uniquePrefix("ld_histsurf")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "history surfaces "+prefix)
	defer cleanupIncident(t, incidentID)
	presp, _ := patchLockdown(t, token, map[string]interface{}{"action": "surfaces", "surfaces": map[string]bool{"chat": false}})
	require.Equal(t, 200, presp.StatusCode)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/history?jwt="+token, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var rows []map[string]interface{}
	json.Unmarshal(rsp(resp), &rows)
	require.GreaterOrEqual(t, len(rows), 2)

	// Newest first: the lift, then the press.
	lift := rows[0]["surfaces"].(map[string]interface{})
	press := rows[1]["surfaces"].(map[string]interface{})
	assert.Equal(t, false, lift["chat"])
	assert.Equal(t, true, lift["posts"])
	assert.Equal(t, true, press["chat"])
}

// TestGetModtoolsLockdownHistoryEndedByName covers plan 11.6/11.7's "endedbyname" addition:
// the closing row must name who closed it, not just their id, the same way pressedbyname does
// for GET /modtools/lockdown/stats.
func TestGetModtoolsLockdownHistoryEndedByName(t *testing.T) {
	prefix := uniquePrefix("ld_history_endedby")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "history endedby "+prefix)
	defer cleanupIncident(t, incidentID)
	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "resolved " + prefix})
	require.Equal(t, 200, resp.StatusCode, "%v", result)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/history?jwt="+token, nil)
	hresp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, 200, hresp.StatusCode)
	var rows []map[string]interface{}
	json.Unmarshal(rsp(hresp), &rows)

	var closedRow map[string]interface{}
	for _, row := range rows {
		if uint64(row["incidentid"].(float64)) == incidentID && row["endedby"] != nil && uint64(row["endedby"].(float64)) == supportID {
			closedRow = row
		}
	}
	require.NotNil(t, closedRow, "history must include the row close() wrote: %v", rows)
	assert.Equal(t, "Test User "+prefix, closedRow["endedbyname"])
}

func TestGetModtoolsLockdownHistoryRefusesPlainModerator(t *testing.T) {
	prefix := uniquePrefix("ld_history_mod")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/history?jwt="+token, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}

// --- GET /modtools/lockdown/stats ---

// TestGetModtoolsLockdownStatsLeakedChatExcludesReleasedHolds is finding 8: leakedChatCount used
// to count every User2User message processed since the press, including ones the lockdown's own
// hold pipeline had already caught and released, so "sent since the press" over-counted by
// however many the incident had already dealt with.
func TestGetModtoolsLockdownStatsLeakedChatExcludesReleasedHolds(t *testing.T) {
	prefix := uniquePrefix("ld_leaked_chat")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	recipientID := CreateTestUser(t, prefix+"_recip", "User")

	incidentID := pressLockdown(t, token, "leaked chat "+prefix)
	defer cleanupIncident(t, incidentID, senderID, recipientID)

	chatID := CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")

	leakedMsgID := CreateTestChatMessage(t, chatID, senderID, "leaked "+prefix)
	releasedMsgID := CreateTestChatMessage(t, chatID, senderID, "released "+prefix)

	// lockdowns.startedat and chat_messages.date are both second-precision TIMESTAMP columns, so
	// a NOW()-based insert immediately after the press can tie with startedat at the same second
	// and miss the "> startedat" filter; push both a full minute past it instead.
	require.NoError(t, db.Exec(
		"UPDATE chat_messages SET processingsuccessful = 1, "+
			"date = DATE_ADD((SELECT startedat FROM lockdowns WHERE id = ?), INTERVAL 1 MINUTE) "+
			"WHERE id IN (?, ?)", incidentID, leakedMsgID, releasedMsgID).Error)

	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": releasedMsgID, "userid": senderID,
		"outcome": "released",
	}).Error)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/stats?jwt="+token, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	leaked, ok := result["leaked"].(map[string]interface{})
	require.True(t, ok, "leaked must be present: %v", result)
	assert.Equal(t, float64(1), leaked["chat"], "only the message the hold pipeline never saw counts as leaked: %v", leaked)
}

func TestGetModtoolsLockdownStatsRefusesPlainModerator(t *testing.T) {
	prefix := uniquePrefix("ld_stats_mod")
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix, "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/stats?jwt="+token, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	assert.Equal(t, 403, resp.StatusCode)
}
