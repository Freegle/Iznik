package test

// Handler tests for the lockdown switch's own five endpoints (plan section 11.2,
// plans/active/2026-09-27-lockdown-switch.md): GET /lockdown, GET /modtools/lockdown,
// GET /modtools/lockdown/stats, GET /modtools/lockdown/history, PATCH /lockdown.
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
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/lockdown"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// cleanupIncident deletes every row of one incident directly: lockdowns (the pressing row
// plus every surfaces/notice/phrases/... row that followed it, all sharing incidentid),
// lockdown_holds and lockdown_counters. Pass any spam_users rows a markspam test added so
// they are removed too.
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

	presp, _ := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "security"})
	assert.Equal(t, 200, presp.StatusCode)

	req2 := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp2, err := getApp().Test(req2)
	require.NoError(t, err)
	var result2 map[string]interface{}
	json.Unmarshal(rsp(resp2), &result2)
	notice, ok := result2["notice"].(map[string]interface{})
	require.True(t, ok, "notice must be an object once set: %v", result2)
	assert.Equal(t, "security", notice["key"])
	assert.Contains(t, notice["text"], "spam attack")
}

// --- GET /modtools/lockdown ---

func TestGetModtoolsLockdownAnyModeratorNoPhrases(t *testing.T) {
	prefix := uniquePrefix("ld_mt_mod")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, supportToken := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, supportToken, "mod visibility "+prefix)
	defer cleanupIncident(t, incidentID)

	presp, _ := patchLockdown(t, supportToken, map[string]interface{}{"action": "phrases", "phrases": []string{"FreeVoucher"}})
	require.Equal(t, 200, presp.StatusCode)

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
	assert.False(t, hasPhrases, "phrases must not be visible to a plain moderator")
}

func TestGetModtoolsLockdownSupportSeesPhrasesLowercased(t *testing.T) {
	prefix := uniquePrefix("ld_mt_phr")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "phrases visibility "+prefix)
	defer cleanupIncident(t, incidentID)

	presp, _ := patchLockdown(t, token, map[string]interface{}{"action": "phrases", "phrases": []string{"FreeVoucher", "ClickHere"}})
	require.Equal(t, 200, presp.StatusCode)

	resp, result := getModtoolsLockdown(t, token)
	assert.Equal(t, 200, resp.StatusCode)
	phrases, ok := result["phrases"].([]interface{})
	require.True(t, ok, "phrases must be visible to Support: %v", result)
	assert.ElementsMatch(t, []interface{}{"freevoucher", "clickhere"}, phrases)
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
	assert.Equal(t, "hard", surfaces["chat_mode"])
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

func TestPatchLockdownNoticeValidatesEnum(t *testing.T) {
	prefix := uniquePrefix("ld_notice_invalid")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	resp, _ := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "bogus"})
	assert.Equal(t, 400, resp.StatusCode)
}

func TestPatchLockdownNoticeAllowedAfterClose(t *testing.T) {
	prefix := uniquePrefix("ld_notice_afterclose")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "notice after close "+prefix)
	defer cleanupIncident(t, incidentID)
	closeResp, _ := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "done"})
	require.Equal(t, 200, closeResp.StatusCode)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "normal"})
	assert.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, "normal", result["notice"])
}

// TestGetLockdownNoticeClearedOnCloseWhenSecurity is finding 7: GetLockdown used to show
// s.Notice unconditionally, and close carried the notice forward unchanged, so members kept
// seeing "we're dealing with a spam attack" long after the incident that caused it had ended.
func TestGetLockdownNoticeClearedOnCloseWhenSecurity(t *testing.T) {
	prefix := uniquePrefix("ld_notice_close_sec")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "notice close security "+prefix)
	defer cleanupIncident(t, incidentID)

	nresp, _ := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "security"})
	require.Equal(t, 200, nresp.StatusCode)

	req := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	notice, ok := result["notice"].(map[string]interface{})
	require.True(t, ok, "notice must show while the incident is active: %v", result)
	assert.Equal(t, "security", notice["key"])

	closeResp, _ := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "resolved " + prefix})
	require.Equal(t, 200, closeResp.StatusCode)

	req2 := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp2, err := getApp().Test(req2)
	require.NoError(t, err)
	var result2 map[string]interface{}
	json.Unmarshal(rsp(resp2), &result2)
	assert.Nil(t, result2["notice"], "a security notice must not linger for members once the incident is closed: %v", result2)
}

// TestGetLockdownNormalNoticeExpiresAfter24Hours: unlike delay/security, "normal" (the settled
// all-clear) is allowed to be set with no incident active at all, and is meant to show for a
// while rather than forever - finding 7 sets that window at 24 hours from the row that set it.
func TestGetLockdownNormalNoticeExpiresAfter24Hours(t *testing.T) {
	prefix := uniquePrefix("ld_notice_expire")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "notice expiry "+prefix)
	defer cleanupIncident(t, incidentID)
	closeResp, _ := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "resolved " + prefix})
	require.Equal(t, 200, closeResp.StatusCode)

	nresp, nresult := patchLockdown(t, token, map[string]interface{}{"action": "notice", "notice": "normal"})
	require.Equal(t, 200, nresp.StatusCode, "%v", nresult)
	rowID := uint64(nresult["id"].(float64))

	req := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)
	notice, ok := result["notice"].(map[string]interface{})
	require.True(t, ok, "a freshly set normal notice must show: %v", result)
	assert.Equal(t, "normal", notice["key"])

	// lockdowns.created has no ON UPDATE clause (2026_09_27_000001_create_lockdown_tables.php),
	// so back-dating it directly is safe and cannot be reset by anything else touching the row.
	require.NoError(t, db.Exec("UPDATE lockdowns SET created = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE id = ?", rowID).Error)
	lockdown.Invalidate()

	req2 := httptest.NewRequest("GET", "/api/lockdown", nil)
	resp2, err := getApp().Test(req2)
	require.NoError(t, err)
	var result2 map[string]interface{}
	json.Unmarshal(rsp(resp2), &result2)
	assert.Nil(t, result2["notice"], "a normal notice older than 24 hours must stop showing: %v", result2)
}

// --- PATCH /lockdown action=phrases ---

func TestPatchLockdownPhrasesRefusesWhenNotActive(t *testing.T) {
	prefix := uniquePrefix("ld_phrases_inactive")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	resp, _ := patchLockdown(t, token, map[string]interface{}{"action": "phrases", "phrases": []string{"x"}})
	assert.Equal(t, 409, resp.StatusCode)
}

// TestPatchLockdownPhrasesRejectsOverTheCountCap is finding 10: phrases had no cap at all, so a
// mistaken paste (or someone testing what the limit even was) could hand the hot path scanning
// every held message an unbounded slice.
func TestPatchLockdownPhrasesRejectsOverTheCountCap(t *testing.T) {
	prefix := uniquePrefix("ld_phrases_countcap")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "phrases count cap "+prefix)
	defer cleanupIncident(t, incidentID)

	phrases := make([]string, 201)
	for i := range phrases {
		phrases[i] = fmt.Sprintf("phrase%d", i)
	}

	resp, _ := patchLockdown(t, token, map[string]interface{}{"action": "phrases", "phrases": phrases})
	assert.Equal(t, 400, resp.StatusCode)
}

// TestPatchLockdownPhrasesRejectsAnOverlongPhrase is the per-entry half of finding 10's cap.
func TestPatchLockdownPhrasesRejectsAnOverlongPhrase(t *testing.T) {
	prefix := uniquePrefix("ld_phrases_lengthcap")
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "phrases length cap "+prefix)
	defer cleanupIncident(t, incidentID)

	resp, _ := patchLockdown(t, token, map[string]interface{}{
		"action": "phrases", "phrases": []string{strings.Repeat("a", 201)},
	})
	assert.Equal(t, 400, resp.StatusCode)
}

// --- PATCH /lockdown action=markspam ---

func TestPatchLockdownMarkspam(t *testing.T) {
	prefix := uniquePrefix("ld_markspam")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")

	incidentID := pressLockdown(t, token, "markspam "+prefix)
	defer cleanupIncident(t, incidentID, senderID)

	// A hold already flagged spam and still waiting, plus one already dealt with (must be
	// left alone) and one not spam at all (must be left alone).
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": 111, "userid": senderID, "risk": "spam",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": 112, "userid": senderID, "risk": "spam", "outcome": "spam_marked",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "post", "refid": 113, "userid": senderID, "risk": "low",
	}).Error)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "markspam"})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, float64(1), result["holds"], "only the one waiting spam hold must be counted")
	assert.Equal(t, float64(1), result["users"])

	var outcome string
	db.Raw("SELECT outcome FROM lockdown_holds WHERE lockdownid = ? AND refid = 111", incidentID).Scan(&outcome)
	assert.Equal(t, "spam_marked", outcome)

	var count int64
	db.Raw("SELECT COUNT(*) FROM spam_users WHERE userid = ? AND collection = 'Spammer' AND byuserid = ?", senderID, supportID).Scan(&count)
	assert.Equal(t, int64(1), count)

	var reason string
	db.Raw("SELECT reason FROM spam_users WHERE userid = ?", senderID).Scan(&reason)
	assert.Contains(t, reason, "Lockdown")
}

func TestPatchLockdownMarkspamSkipsExistingSpamUser(t *testing.T) {
	prefix := uniquePrefix("ld_markspam_dup")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")

	incidentID := pressLockdown(t, token, "markspam dup "+prefix)
	defer cleanupIncident(t, incidentID, senderID)

	require.NoError(t, db.Table("spam_users").Create(map[string]interface{}{
		"userid": senderID, "collection": "Spammer", "reason": "already flagged",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": 211, "userid": senderID, "risk": "spam",
	}).Error)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "markspam"})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, float64(1), result["holds"])

	var outcome string
	db.Raw("SELECT outcome FROM lockdown_holds WHERE lockdownid = ? AND refid = 211", incidentID).Scan(&outcome)
	assert.Equal(t, "spam_marked", outcome, "the hold must still be marked even though the user was already a spam_users row")

	var reason string
	db.Raw("SELECT reason FROM spam_users WHERE userid = ?", senderID).Scan(&reason)
	assert.Equal(t, "already flagged", reason, "an existing spam_users row must not be overwritten")
}

// TestPatchLockdownMarkspamUpgradesPendingAddAndSkipsWhitelisted is finding 10: patchMarkspam used
// to ignore the error spam_users.Create returns for a userid that already has a row (the column is
// UNIQUE), so a sender sitting in PendingAdd was silently never upgraded to Spammer, and a
// Whitelisted sender's hold was marked spam_marked with nothing in the response to say their
// spam_users row was left alone.
func TestPatchLockdownMarkspamUpgradesPendingAddAndSkipsWhitelisted(t *testing.T) {
	prefix := uniquePrefix("ld_markspam_upgrade")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	pendingID := CreateTestUser(t, prefix+"_pending", "User")
	whitelistedID := CreateTestUser(t, prefix+"_white", "User")
	newID := CreateTestUser(t, prefix+"_new", "User")

	incidentID := pressLockdown(t, token, "markspam upgrade "+prefix)
	defer cleanupIncident(t, incidentID, pendingID, whitelistedID, newID)

	require.NoError(t, db.Table("spam_users").Create(map[string]interface{}{
		"userid": pendingID, "collection": "PendingAdd", "reason": "earlier flag",
	}).Error)
	require.NoError(t, db.Table("spam_users").Create(map[string]interface{}{
		"userid": whitelistedID, "collection": "Whitelisted", "reason": "trusted",
	}).Error)

	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": 601, "userid": pendingID, "risk": "spam",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": 602, "userid": whitelistedID, "risk": "spam",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": 603, "userid": newID, "risk": "spam",
	}).Error)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "markspam"})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, float64(3), result["holds"])
	assert.Equal(t, float64(2), result["users"], "pending-upgrade and brand-new senders both count as newly flagged")
	assert.Equal(t, float64(1), result["skippedwhitelisted"])

	var pendingCollection string
	db.Raw("SELECT collection FROM spam_users WHERE userid = ?", pendingID).Scan(&pendingCollection)
	assert.Equal(t, "Spammer", pendingCollection, "a PendingAdd sender must be upgraded to Spammer")

	var whitelistedCollection string
	db.Raw("SELECT collection FROM spam_users WHERE userid = ?", whitelistedID).Scan(&whitelistedCollection)
	assert.Equal(t, "Whitelisted", whitelistedCollection, "a Whitelisted sender must never be overwritten")

	var newCount int64
	db.Raw("SELECT COUNT(*) FROM spam_users WHERE userid = ? AND collection = 'Spammer'", newID).Scan(&newCount)
	assert.Equal(t, int64(1), newCount)

	var markedCount int64
	db.Raw("SELECT COUNT(*) FROM lockdown_holds WHERE lockdownid = ? AND outcome = 'spam_marked'", incidentID).Scan(&markedCount)
	assert.Equal(t, int64(3), markedCount, "every hold must still be marked spam_marked, including the whitelisted sender's")
}

// --- PATCH /lockdown action=releaseclass ---

func TestPatchLockdownReleaseclass(t *testing.T) {
	prefix := uniquePrefix("ld_releaseclass")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "releaseclass "+prefix)
	defer cleanupIncident(t, incidentID)

	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "post", "refid": 301, "userid": 1, "risk": "risky",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "post", "refid": 302, "userid": 2, "risk": "risky", "outcome": "review",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "post", "refid": 303, "userid": 3, "risk": "low",
	}).Error)

	resp, result := patchLockdown(t, token, map[string]interface{}{
		"action": "releaseclass", "kind": "post", "risk": "risky", "decision": "release",
	})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, float64(2), result["holds"])

	var released int64
	db.Raw("SELECT COUNT(*) FROM lockdown_holds WHERE lockdownid = ? AND refid IN (301, 302) AND outcome = 'approved'", incidentID).Scan(&released)
	assert.Equal(t, int64(2), released)

	var untouched string
	db.Raw("SELECT COALESCE(outcome, '') FROM lockdown_holds WHERE lockdownid = ? AND refid = 303", incidentID).Scan(&untouched)
	assert.Equal(t, "", untouched, "a different risk class must not be touched")
}

func TestPatchLockdownReleaseclassReject(t *testing.T) {
	prefix := uniquePrefix("ld_releaseclass_rej")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "releaseclass reject "+prefix)
	defer cleanupIncident(t, incidentID)

	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": 401, "userid": 1, "risk": "spam",
	}).Error)

	resp, result := patchLockdown(t, token, map[string]interface{}{
		"action": "releaseclass", "kind": "chat", "risk": "spam", "decision": "reject",
	})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, float64(1), result["holds"])

	var outcome string
	db.Raw("SELECT outcome FROM lockdown_holds WHERE lockdownid = ? AND refid = 401", incidentID).Scan(&outcome)
	assert.Equal(t, "spam_marked", outcome)
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

	presp, _ := patchLockdown(t, token, map[string]interface{}{"action": "phrases", "phrases": []string{"x"}})
	require.Equal(t, 200, presp.StatusCode)

	resp, result := patchLockdown(t, token, map[string]interface{}{"action": "close", "endnote": "resolved " + prefix})
	require.Equal(t, 200, resp.StatusCode, "%v", result)
	assert.Equal(t, false, result["active"])
	surfaces := result["surfaces"].(map[string]interface{})
	for _, k := range []string{"chat", "posts", "chitchat", "events", "email", "push", "export", "mods"} {
		assert.Equal(t, false, surfaces[k], "surface %s must be cleared by close", k)
	}

	// Phrases are cleared by close - Support re-reading afterwards must see none left.
	_, mtResult := getModtoolsLockdown(t, token)
	phrases, _ := mtResult["phrases"].([]interface{})
	assert.Empty(t, phrases)
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

func TestGetModtoolsLockdownStats(t *testing.T) {
	prefix := uniquePrefix("ld_stats")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")

	incidentID := pressLockdown(t, token, "stats "+prefix)
	defer cleanupIncident(t, incidentID)

	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": 501, "userid": senderID, "risk": "spam",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": 502, "userid": senderID, "risk": "risky",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "post", "refid": 503, "userid": senderID, "risk": "low", "outcome": "released",
	}).Error)

	require.NoError(t, db.Table("lockdown_counters").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "email:digest", "count": 5,
	}).Error)
	require.NoError(t, db.Table("lockdown_counters").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "push", "count": 3,
	}).Error)
	require.NoError(t, db.Table("lockdown_counters").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "export", "count": 1,
	}).Error)
	require.NoError(t, db.Table("lockdown_counters").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": fmt.Sprintf("refused:%d", supportID), "count": 2,
	}).Error)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/stats?jwt="+token, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	assert.Equal(t, float64(supportID), result["pressedby"])
	assert.NotEmpty(t, result["pressedat"])
	_, hasMinutesAgo := result["minutesago"]
	assert.True(t, hasMinutesAgo)

	// Plan 11.6: rowid/changedat identify the current row (a single press here, so rowid is
	// the same id as the incident itself), and api.delayseconds names the cache TTL the page
	// should not poll faster than.
	assert.Equal(t, float64(incidentID), result["rowid"])
	assert.NotEmpty(t, result["changedat"])
	api, ok := result["api"].(map[string]interface{})
	require.True(t, ok, "api must be present: %v", result)
	assert.Equal(t, float64(5), api["delayseconds"])

	held, ok := result["held"].([]interface{})
	require.True(t, ok, "held must be an array: %v", result)
	var chatHeld map[string]interface{}
	for _, h := range held {
		row := h.(map[string]interface{})
		if row["kind"] == "chat" {
			chatHeld = row
		}
	}
	require.NotNil(t, chatHeld, "chat must appear in held: %v", held)
	assert.Equal(t, float64(2), chatHeld["count"])
	assert.Equal(t, float64(1), chatHeld["distinctusers"])

	triage, ok := result["triage"].([]interface{})
	require.True(t, ok)
	assert.NotEmpty(t, triage)

	samples, ok := result["samples"].(map[string]interface{})
	require.True(t, ok, "samples must be present: %v", result)
	_, hasSpamSamples := samples["spam"]
	_, hasRiskySamples := samples["risky"]
	_, hasLowSamples := samples["low"]
	assert.True(t, hasSpamSamples)
	assert.True(t, hasRiskySamples)
	assert.False(t, hasLowSamples, "low risk must never get samples")

	// Plan 11.7: a sample must carry refid so the page can link back to the actual held item,
	// not just show its text.
	spamSamples, ok := samples["spam"].([]interface{})
	require.True(t, ok)
	require.NotEmpty(t, spamSamples, "the spam hold created above must show up as a sample")
	spamSample := spamSamples[0].(map[string]interface{})
	assert.Equal(t, float64(501), spamSample["refid"])
	assert.Equal(t, float64(senderID), spamSample["userid"])

	counters, ok := result["counters"].(map[string]interface{})
	require.True(t, ok)
	email, ok := counters["email"].(map[string]interface{})
	require.True(t, ok)
	assert.Equal(t, float64(5), email["digest"])
	assert.Equal(t, float64(3), counters["push"])
	assert.Equal(t, float64(1), counters["export"])
	refused, ok := counters["refused"].([]interface{})
	require.True(t, ok)
	require.Len(t, refused, 1)
	refusedRow := refused[0].(map[string]interface{})
	assert.Equal(t, float64(supportID), refusedRow["userid"])
	assert.Equal(t, float64(2), refusedRow["count"])

	outcomes, ok := result["outcomes"].(map[string]interface{})
	require.True(t, ok)
	assert.Equal(t, float64(1), outcomes["released"])
}

// TestGetModtoolsLockdownStatsAcksLeakedWaiting covers plan 11.6/11.7's three additions that
// TestGetModtoolsLockdownStats above does not: acks (how far each batch loop has caught up),
// leaked (what still went out despite the hold) and waiting (mail queued behind it). Kept
// separate because acks writes to lockdown_acks, the one table that is not scoped to an
// incident - it is keyed by loop name only, forever - so it needs its own explicit cleanup
// rather than riding on cleanupIncident.
func TestGetModtoolsLockdownStatsAcksLeakedWaiting(t *testing.T) {
	prefix := uniquePrefix("ld_stats_acks")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix, "Support")
	_, token := CreateTestSession(t, supportID)

	incidentID := pressLockdown(t, token, "acks "+prefix)
	defer cleanupIncident(t, incidentID)
	defer db.Exec("DELETE FROM lockdown_acks WHERE `loop` IN (?, ?)", "chat-process", "triage")

	// chat-process has caught up with the (only, current) row; triage has never ticked at all
	// since the press, so it must still be listed, just as not caught up.
	require.NoError(t, db.Table("lockdown_acks").Create(map[string]interface{}{
		"loop": "chat-process", "lockdownrowid": incidentID, "seenat": time.Now(),
	}).Error)

	require.NoError(t, db.Table("lockdown_counters").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "leaked:email:digest", "count": 4,
	}).Error)
	require.NoError(t, db.Table("lockdown_counters").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "spooled_held:digest", "count": 7,
	}).Error)
	require.NoError(t, db.Table("lockdown_counters").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "deferred:mail-loops", "count": 9,
	}).Error)
	require.NoError(t, db.Table("lockdown_counters").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "filtered:email:digest", "count": 2,
	}).Error)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/stats?jwt="+token, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	acks, ok := result["acks"].([]interface{})
	require.True(t, ok, "acks must be an array: %v", result)
	require.Len(t, acks, 8, "every ack loop must be listed, ticked or not")
	var chatProcessAck, triageAck map[string]interface{}
	for _, a := range acks {
		row := a.(map[string]interface{})
		switch row["loop"] {
		case "chat-process":
			chatProcessAck = row
		case "triage":
			triageAck = row
		}
	}
	require.NotNil(t, chatProcessAck, "acks: %v", acks)
	assert.Equal(t, float64(incidentID), chatProcessAck["lockdownrowid"])
	assert.Equal(t, true, chatProcessAck["caughtup"])
	require.NotNil(t, triageAck, "acks: %v", acks)
	assert.Nil(t, triageAck["lockdownrowid"], "a loop that has never ticked must report no row rather than being absent")
	assert.Equal(t, false, triageAck["caughtup"])

	leaked, ok := result["leaked"].(map[string]interface{})
	require.True(t, ok, "leaked must be present: %v", result)
	assert.Equal(t, float64(4), leaked["email:digest"])
	_, hasChatLeak := leaked["chat"]
	assert.True(t, hasChatLeak, "leaked chat count must be computed live even when zero")

	waiting, ok := result["waiting"].(map[string]interface{})
	require.True(t, ok, "waiting must be present: %v", result)
	waitingEmail, ok := waiting["email"].(map[string]interface{})
	require.True(t, ok, "waiting: %v", waiting)
	queued, ok := waitingEmail["queued"].(map[string]interface{})
	require.True(t, ok, "waiting.email.queued must be present: %v", waitingEmail)
	assert.Equal(t, float64(7), queued["digest"])
	deferred, ok := waitingEmail["deferred"].(map[string]interface{})
	require.True(t, ok, "waiting.email.deferred must be present: %v", waitingEmail)
	assert.Equal(t, float64(9), deferred["mail-loops"])
	removed, ok := waitingEmail["removed"].(map[string]interface{})
	require.True(t, ok, "waiting.email.removed must be present: %v", waitingEmail)
	assert.Equal(t, float64(2), removed["digest"])
}

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
		"risk": "risky", "outcome": "approved",
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

// TestGetModtoolsLockdownStatsSamplesAndClustersAcrossKinds is finding 9: batchSampleTexts must
// key its results by (kind, refid) together, not refid alone, since chat_messages, messages and
// newsfeed all use their own independent auto-increment ids and can collide.
func TestGetModtoolsLockdownStatsSamplesAndClustersAcrossKinds(t *testing.T) {
	prefix := uniquePrefix("ld_batch_samples")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	senderID := CreateTestUser(t, prefix+"_sender", "User")
	groupID := CreateTestGroup(t, prefix)

	incidentID := pressLockdown(t, token, "batch samples "+prefix)
	defer cleanupIncident(t, incidentID, senderID)

	recipientID := CreateTestUser(t, prefix+"_recip", "User")
	chatID := CreateTestChatRoom(t, senderID, &recipientID, nil, "User2User")
	chatMsgID := CreateTestChatMessage(t, chatID, senderID, "chat sample "+prefix)
	postID := CreateTestMessage(t, senderID, groupID, "post sample "+prefix, 51.5, -0.1)
	newsfeedID := CreateTestNewsfeed(t, senderID, 51.5, -0.1, "chitchat sample "+prefix)

	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chat", "refid": chatMsgID, "userid": senderID, "risk": "risky",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "post", "refid": postID, "userid": senderID, "risk": "risky",
	}).Error)
	require.NoError(t, db.Table("lockdown_holds").Create(map[string]interface{}{
		"lockdownid": incidentID, "kind": "chitchat", "refid": newsfeedID, "userid": senderID, "risk": "risky",
	}).Error)

	req := httptest.NewRequest("GET", "/api/modtools/lockdown/stats?jwt="+token, nil)
	resp, err := getApp().Test(req)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var result map[string]interface{}
	json.Unmarshal(rsp(resp), &result)

	samples, ok := result["samples"].(map[string]interface{})
	require.True(t, ok, "samples must be present: %v", result)
	risky, ok := samples["risky"].([]interface{})
	require.True(t, ok, "samples.risky must be present: %v", samples)
	require.Len(t, risky, 3, "one sample per kind: %v", risky)

	texts := map[uint64]string{}
	for _, s := range risky {
		row := s.(map[string]interface{})
		texts[uint64(row["refid"].(float64))] = row["text"].(string)
	}
	assert.Contains(t, texts[chatMsgID], "chat sample "+prefix, "chat sample text must be batched from the right (kind, refid)")
	assert.Contains(t, texts[postID], "post sample "+prefix, "post sample text must be batched from the right (kind, refid)")
	assert.Contains(t, texts[newsfeedID], "chitchat sample "+prefix, "chitchat sample text must be batched from the right (kind, refid)")

	clusters, ok := result["clusters"].([]interface{})
	require.True(t, ok, "clusters must be present: %v", result)
	require.Len(t, clusters, 3, "three distinct texts must produce three distinct clusters, not merged across kinds: %v", clusters)
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
