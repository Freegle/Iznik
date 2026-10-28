package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"os"
	"strconv"
	"strings"
	"sync"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The experiments side of the UX read API, its two write handlers and the public config key the
// client reads arms from. Every test names its rows with a unique prefix and removes them afterwards.

func uxExpClean(t *testing.T, prefix string) {
	t.Helper()
	db := database.DBConn
	cleanup := func() {
		db.Exec("DELETE FROM ux_experiment_reads WHERE experiment_id IN (SELECT id FROM ux_experiments WHERE name LIKE ?)", prefix+"%")
		db.Exec("DELETE FROM ux_experiments WHERE name LIKE ?", prefix+"%")
		db.Exec("DELETE FROM background_tasks WHERE task_type = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.reason')) LIKE ?", ux.TaskUxExperiment, prefix+"%")
	}
	cleanup()
	t.Cleanup(cleanup)
}

func uxPost(t *testing.T, token, path string, body interface{}) (int, map[string]interface{}) {
	t.Helper()
	b, _ := json.Marshal(body)
	url := "/api/modtools/ux/" + path
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest("POST", url, strings.NewReader(string(b)))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 30000)
	require.NoError(t, err)
	out := map[string]interface{}{}
	_ = json.Unmarshal(rsp(resp), &out)
	return resp.StatusCode, out
}

func uxPatch(t *testing.T, token, path string, body interface{}) (int, map[string]interface{}) {
	t.Helper()
	b, _ := json.Marshal(body)
	url := "/api/modtools/ux/" + path
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest("PATCH", url, strings.NewReader(string(b)))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 30000)
	require.NoError(t, err)
	out := map[string]interface{}{}
	_ = json.Unmarshal(rsp(resp), &out)
	return resp.StatusCode, out
}

func TestUxExperimentsRequireSupport(t *testing.T) {
	userID := CreateTestUser(t, uniquePrefix("uxexpuser"), "User")
	_, userToken := CreateTestSession(t, userID)

	assert.Equal(t, 401, uxGet(t, "", "experiments", nil))
	assert.Equal(t, 403, uxGet(t, userToken, "experiments", nil))
	code, _ := uxPost(t, userToken, "experiments", map[string]interface{}{"flow": "give"})
	assert.Equal(t, 403, code)
	code, _ = uxPatch(t, userToken, "experiments/1", map[string]interface{}{"action": "start"})
	assert.Equal(t, 403, code)
}

func TestUxExperimentCreateValidatesAndListsPerFlow(t *testing.T) {
	prefix := uniquePrefix("uxexp")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxexpsup")

	body := map[string]interface{}{
		"flow": "give", "name": prefix + "-skip", "title": prefix + " Make skipping photos obvious",
		"hypothesis": "People leave at photos because they cannot see they can skip.",
		"change":     "A plain link above the camera button.", "expected": "More finish from the photos step.",
	}
	code, out := uxPost(t, token, "experiments", body)
	require.Equal(t, 200, code, out)
	assert.Equal(t, "draft", out["status"])
	id := uint64(out["id"].(float64))
	assert.NotZero(t, id)

	// Same name again is a conflict; a bad flow, a bad name and empty text are refused.
	code, _ = uxPost(t, token, "experiments", body)
	assert.Equal(t, 409, code)
	bad := map[string]interface{}{}
	for k, v := range body {
		bad[k] = v
	}
	bad["flow"] = "checkout"
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["flow"] = "give"
	bad["name"] = "has spaces"
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["name"] = prefix + "-two"
	bad["title"] = "  "
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["title"] = "t"
	bad["arms"] = map[string][2]int{"control": {0, 60}, "b": {50, 99}}
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["arms"] = map[string][2]int{"a": {0, 49}, "b": {50, 99}}
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)

	// A read shows up under the funnel.
	db := database.DBConn
	db.Exec("UPDATE ux_experiments SET status = 'adopted', started_at = NOW() - INTERVAL 30 DAY, decided_at = NOW() - INTERVAL 29 DAY, decision = 'Adopted at the end.' WHERE id = ?", id)
	db.Exec("INSERT INTO ux_experiment_reads (experiment_id, read_at, hours, metrics, summary) VALUES (?, NOW() - INTERVAL 29 DAY, 24, '{}', 'After 24 hours: fine.')", id)

	var resp ux.ExperimentsResponse
	assert.Equal(t, 200, uxGet(t, token, "experiments?flow=give", &resp))
	require.Len(t, resp.Flows, 1)
	give := resp.Flows[0]
	assert.Equal(t, "give", give.Flow)
	var found *ux.Experiment
	for i := range give.History {
		if give.History[i].ID == id {
			found = &give.History[i]
		}
	}
	require.NotNil(t, found, "the adopted experiment is in the history")
	assert.True(t, found.CleanupOverdue, "adopted 29 days ago with no cleanup is overdue")
	require.NotNil(t, found.LatestRead)
	assert.Equal(t, "After 24 hours: fine.", found.LatestRead.Summary)
	assert.InDelta(t, 24, found.Hours, 0.1)

	// An invalid flow filter is refused; every flow has an entry when none is given.
	assert.Equal(t, 400, uxGet(t, token, "experiments?flow=bad%20flow", nil))
	var all ux.ExperimentsResponse
	assert.Equal(t, 200, uxGet(t, token, "experiments", &all))
	assert.GreaterOrEqual(t, len(all.Flows), 7)
}

func TestUxExperimentActionQueuesForTheBatch(t *testing.T) {
	prefix := uniquePrefix("uxact")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxactsup")
	db := database.DBConn

	code, out := uxPost(t, token, "experiments", map[string]interface{}{
		"flow": "ask", "name": prefix + "-x", "title": prefix + " t", "hypothesis": "h", "change": "c", "expected": "e",
	})
	require.Equal(t, 200, code, out)
	id := int(out["id"].(float64))

	// Only the transitions that make sense are queued.
	code, _ = uxPatch(t, token, "experiments/9999999999", map[string]interface{}{"action": "start"})
	assert.Equal(t, 404, code)
	code, _ = uxPatch(t, token, "experiments/"+strconv.Itoa(id), map[string]interface{}{"action": "dance"})
	assert.Equal(t, 400, code)
	code, _ = uxPatch(t, token, "experiments/"+strconv.Itoa(id), map[string]interface{}{"action": "stop", "reason": prefix})
	assert.Equal(t, 400, code, "a draft cannot be stopped")
	code, _ = uxPatch(t, token, "experiments/"+strconv.Itoa(id), map[string]interface{}{"action": "close", "reason": prefix})
	assert.Equal(t, 400, code, "a draft cannot be closed")

	code, out = uxPatch(t, token, "experiments/"+strconv.Itoa(id), map[string]interface{}{"action": "start", "reason": prefix})
	require.Equal(t, 200, code, out)
	assert.Equal(t, true, out["queued"])

	var queued int64
	// The data column is JSON, which MySQL re-serialises with its own key order and spacing, so
	// match on the extracted values rather than on the text.
	db.Table("background_tasks").Where("task_type = ? AND JSON_EXTRACT(data, '$.action') = 'start' AND JSON_EXTRACT(data, '$.id') = ?", ux.TaskUxExperiment, id).Count(&queued)
	assert.Equal(t, int64(1), queued, "one start task queued for the batch")
	// The row itself is untouched: the batch owns the transition.
	var status string
	db.Table("ux_experiments").Select("status").Where("id = ?", id).Scan(&status)
	assert.Equal(t, "draft", status)
}

func TestUxExperimentsConfigKeyIsPublic(t *testing.T) {
	db := database.DBConn
	db.Exec("INSERT INTO config (`key`, value) VALUES ('ux_experiments', '{\"t\":{\"control\":[0,49],\"b\":[50,99]}}') ON DUPLICATE KEY UPDATE value = VALUES(value)")
	t.Cleanup(func() { db.Exec("DELETE FROM config WHERE `key` = 'ux_experiments'") })

	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/config/ux_experiments", nil))
	require.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	var items []map[string]interface{}
	require.NoError(t, json.Unmarshal(rsp(resp), &items))
	require.Len(t, items, 1)
	assert.Contains(t, items[0]["value"], "\"control\":[0,49]")

	// Other keys stay private.
	resp, _ = getApp().Test(httptest.NewRequest("GET", "/api/config/deploy.laravel_commit", nil))
	assert.Equal(t, 403, resp.StatusCode)
}

func uxExperimentBody(prefix, name, flow string) map[string]interface{} {
	return map[string]interface{}{
		"flow": flow, "name": prefix + name, "title": prefix + " title " + name, "hypothesis": "h",
		"change": "c", "expected": "e",
	}
}

func TestUxCreateExperimentArmsAreExactly(t *testing.T) {
	prefix := uniquePrefix("uxarms")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxarmssup")

	// A range of three numbers is refused, not cut to two; one number is not padded to two.
	body := uxExperimentBody(prefix, "-a", "give")
	body["arms"] = map[string][]int{"control": {0, 49, 99}, "b": {50, 99}}
	code, _ := uxPost(t, token, "experiments", body)
	assert.Equal(t, 400, code)
	body["arms"] = map[string][]int{"control": {0, 49}, "b": {7}}
	code, _ = uxPost(t, token, "experiments", body)
	assert.Equal(t, 400, code)
	body["arms"] = map[string][]int{"control": {}, "b": {50, 99}}
	code, _ = uxPost(t, token, "experiments", body)
	assert.Equal(t, 400, code)
	body["arms"] = map[string][]int{"control": {0, 49}, "b": {50, 99}}
	code, out := uxPost(t, token, "experiments", body)
	assert.Equal(t, 200, code, out)
}

// The batch reads an experiment with one query, and Loki refuses a query that returns 500 series or
// more. Five arms keep every funnel under that, so a sixth is refused here as it is by the batch.
func TestUxCreateExperimentRefusesMoreThanFiveArms(t *testing.T) {
	prefix := uniquePrefix("uxfive")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxfivesup")

	six := uxExperimentBody(prefix, "-six", "give")
	six["arms"] = map[string][]int{"control": {0, 15}, "a": {16, 31}, "b": {32, 47}, "c": {48, 63}, "d": {64, 79}, "e": {80, 99}}
	code, text := uxSendText(t, "POST", token, "experiments", six)
	assert.Equal(t, 400, code)
	assert.Equal(t, "an experiment has at most 5 arms", text)

	five := uxExperimentBody(prefix, "-five", "give")
	five["arms"] = map[string][]int{"control": {0, 19}, "a": {20, 39}, "b": {40, 59}, "c": {60, 79}, "d": {80, 99}}
	code, out := uxPost(t, token, "experiments", five)
	assert.Equal(t, 200, code, out)
}

func TestUxCreateExperimentRefusesReservedNames(t *testing.T) {
	prefix := uniquePrefix("uxres")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxressup")
	db := database.DBConn
	for _, name := range []string{"__proto__", "constructor", "prototype"} {
		body := uxExperimentBody(prefix, "", "give")
		body["name"] = name
		code, _ := uxPost(t, token, "experiments", body)
		assert.Equal(t, 400, code, name)
		var n int64
		db.Table("ux_experiments").Where("name = ?", name).Count(&n)
		assert.Zero(t, n, name)
	}
}

func TestUxCreateExperimentSameNameAtOnceIsOneWinnerNoServerError(t *testing.T) {
	prefix := uniquePrefix("uxrace")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxracesup")

	var wg sync.WaitGroup
	codes := make([]int, 8)
	for i := range codes {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			b, _ := json.Marshal(uxExperimentBody(prefix, "-same", "give"))
			req := httptest.NewRequest("POST", "/api/modtools/ux/experiments?jwt="+token, strings.NewReader(string(b)))
			req.Header.Set("Content-Type", "application/json")
			resp, err := getApp().Test(req, 30000)
			if err == nil {
				codes[i] = resp.StatusCode
			}
		}(i)
	}
	wg.Wait()
	ok, conflict := 0, 0
	for _, c := range codes {
		switch c {
		case 200:
			ok++
		case 409:
			conflict++
		}
	}
	assert.Equal(t, 1, ok, "%v", codes)
	assert.Equal(t, len(codes)-1, conflict, "a lost race is a conflict, never a 500: %v", codes)
}

func TestUxCreateExperimentDesign(t *testing.T) {
	prefix := uniquePrefix("uxdesign")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxdesignsup")
	db := database.DBConn

	bad := uxExperimentBody(prefix, "-bad", "give")
	bad["design"] = map[string]interface{}{"touches": []string{"checkout"}}
	code, _ := uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["design"] = map[string]interface{}{"expected_points": 500}
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)

	good := uxExperimentBody(prefix, "-good", "give")
	good["design"] = map[string]interface{}{"touches": []string{"give", "login", "give"}, "expected_points": 4.5}
	code, out := uxPost(t, token, "experiments", good)
	require.Equal(t, 200, code, out)
	var design string
	db.Raw("SELECT design FROM ux_experiments WHERE id = ?", uint64(out["id"].(float64))).Scan(&design)
	assert.JSONEq(t, `{"touches":["give","login"],"expected_points":4.5}`, design)

	// An event to judge on must be one the batch can read; its rate is a share.
	bad["design"] = map[string]interface{}{"primary_event": `x" } |= "`}
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)
	bad["design"] = map[string]interface{}{"primary_event": "job_ad_click", "base_rate": 2}
	code, _ = uxPost(t, token, "experiments", bad)
	assert.Equal(t, 400, code)

	clicks := uxExperimentBody(prefix, "-clicks", "reply")
	clicks["design"] = map[string]interface{}{"primary_event": "job_ad_click", "base_rate": 0.02}
	code, out = uxPost(t, token, "experiments", clicks)
	require.Equal(t, 200, code, out)
	db.Raw("SELECT design FROM ux_experiments WHERE id = ?", uint64(out["id"].(float64))).Scan(&design)
	assert.JSONEq(t, `{"primary_event":"job_ad_click","base_rate":0.02}`, design)

	store := uxExperimentBody(prefix, "-store", "give")
	store["design"] = map[string]interface{}{"primary_event": "app_store_click", "base_rate": 0.01}
	code, out = uxPost(t, token, "experiments", store)
	require.Equal(t, 200, code, out)

	rated := uxExperimentBody(prefix, "-rated", "chat_outcome")
	rated["design"] = map[string]interface{}{"primary_event": "user_rated", "base_rate": 0.4}
	code, out = uxPost(t, token, "experiments", rated)
	require.Equal(t, 200, code, out)

	chat := uxExperimentBody(prefix, "-chat", "reply")
	chat["design"] = map[string]interface{}{"primary_event": "job_ad_click_chat"}
	code, out = uxPost(t, token, "experiments", chat)
	require.Equal(t, 200, code, out)
}

// An adopted experiment is provisional while its holdout runs: the batch can still reverse it, and
// closing it ends the holdout. The tab needs to know which are in that state.
func TestUxExperimentsAdoptedIsInItsHoldoutForTheHoldoutDays(t *testing.T) {
	token := uxSupportToken(t, "uxholdsup")
	prefix := uniquePrefix("uxhold")
	uxExpClean(t, prefix)
	db := database.DBConn
	for name, days := range map[string]int{"-new": 3, "-old": 20} {
		db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'adopted', DATE_SUB(NOW(), INTERVAL ? DAY), NOW(), NOW())", prefix+name, prefix+name, days)
	}

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))
	until := map[string]*string{}
	for _, f := range all.Flows {
		for _, e := range f.History {
			until[e.Name] = e.HoldoutUntil
		}
	}
	assert.NotNil(t, until[prefix+"-new"], "three days in, the 14-day holdout is still running")
	assert.Nil(t, until[prefix+"-old"], "twenty days in, it is over")
}

func TestUxExperimentsReadSettingsFromEnvironment(t *testing.T) {
	token := uxSupportToken(t, "uxenvsup")
	t.Setenv("UX_EXPERIMENTS_MAX_CONCURRENT", "3")
	t.Setenv("UX_EXPERIMENTS_CLEANUP_DAYS", "2")
	prefix := uniquePrefix("uxenv")
	uxExpClean(t, prefix)
	db := database.DBConn
	db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'adopted', DATE_SUB(NOW(), INTERVAL 3 DAY), NOW(), NOW())", prefix+"-old", prefix+" old")

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))
	assert.Equal(t, int64(3), all.MaxRunning)
	overdue := false
	for _, f := range all.Flows {
		for _, e := range f.History {
			if e.Name == prefix+"-old" {
				overdue = e.CleanupOverdue
			}
		}
	}
	assert.True(t, overdue, "three days after adoption is overdue when the grace period is two")
}

// The holdout days and the holdout share come from the environment of the process that answers, as
// the cap and the cleanup days do, because the batch reads the same two variables. Fixed in the
// handler, the routes would call an adopted change provisional after the batch had stopped watching
// it, or the other way round, and nothing would say so.
func TestUxExperimentsReadHoldoutSettingsFromEnvironment(t *testing.T) {
	token := uxSupportToken(t, "uxholdenvsup")
	prefix := uniquePrefix("uxholdenv")
	uxExpClean(t, prefix)
	db := database.DBConn
	for name, days := range map[string]int{"-fresh": 1, "-three": 3, "-twenty": 20} {
		db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'adopted', DATE_SUB(NOW(), INTERVAL ? DAY), NOW(), NOW())", prefix+name, prefix+name, days)
	}
	// inHoldout says which of the three adopted experiments the routes call provisional.
	inHoldout := func() map[string]bool {
		var all ux.ExperimentsResponse
		require.Equal(t, 200, uxGet(t, token, "experiments", &all))
		out := map[string]bool{}
		for _, f := range all.Flows {
			for _, e := range f.History {
				if strings.HasPrefix(e.Name, prefix) {
					out[strings.TrimPrefix(e.Name, prefix)] = e.HoldoutUntil != nil
				}
			}
		}
		return out
	}

	// Nothing set: the holdout lasts 14 days.
	assert.Equal(t, map[string]bool{"-fresh": true, "-three": true, "-twenty": false}, inHoldout())

	// A longer holdout keeps the twenty-day-old one provisional.
	t.Setenv("UX_EXPERIMENTS_HOLDOUT_DAYS", "30")
	assert.Equal(t, map[string]bool{"-fresh": true, "-three": true, "-twenty": true}, inHoldout())

	// A shorter one ends it for the three-day-old one.
	t.Setenv("UX_EXPERIMENTS_HOLDOUT_DAYS", "2")
	assert.Equal(t, map[string]bool{"-fresh": true, "-three": false, "-twenty": false}, inHoldout())

	// A share of 0 means no holdout at all, whatever the days. A blank share, or any other number,
	// keeps it.
	t.Setenv("UX_EXPERIMENTS_HOLDOUT_DAYS", "30")
	for _, share := range []string{"0", " 0 "} {
		t.Setenv("UX_EXPERIMENTS_HOLDOUT_SHARE", share)
		assert.Equal(t, map[string]bool{"-fresh": false, "-three": false, "-twenty": false}, inHoldout(), "share %q", share)
	}
	for _, share := range []string{"", "10"} {
		t.Setenv("UX_EXPERIMENTS_HOLDOUT_SHARE", share)
		assert.Equal(t, map[string]bool{"-fresh": true, "-three": true, "-twenty": true}, inHoldout(), "share %q", share)
	}
}

func TestUxExperimentsListReadsPerExperiment(t *testing.T) {
	prefix := uniquePrefix("uxreads")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxreadssup")
	db := database.DBConn

	var ids [2]uint64
	for i := range ids {
		name := fmt.Sprintf("%s-e%d", prefix, i)
		db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, started_at, created_at, updated_at) VALUES ('ask', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'running', DATE_SUB(NOW(), INTERVAL 2 HOUR), NOW(), NOW())", name, name)
		db.Raw("SELECT id FROM ux_experiments WHERE name = ?", name).Scan(&ids[i])
		db.Exec("INSERT INTO ux_experiment_reads (experiment_id, read_at, hours, metrics, summary) VALUES (?, DATE_SUB(NOW(), INTERVAL 2 HOUR), 1, '{}', 'older')", ids[i])
		db.Exec("INSERT INTO ux_experiment_reads (experiment_id, read_at, hours, metrics, summary) VALUES (?, DATE_SUB(NOW(), INTERVAL 1 HOUR), 2, '{}', ?)", ids[i], fmt.Sprintf("newest %d", i))
	}

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))
	got := map[uint64]ux.Experiment{}
	for _, f := range all.Flows {
		for _, e := range f.Running {
			got[e.ID] = e
		}
	}
	require.Contains(t, got, ids[0])
	require.Contains(t, got, ids[1])
	require.NotNil(t, got[ids[0]].LatestRead)
	require.NotNil(t, got[ids[1]].LatestRead)
	assert.Equal(t, "newest 0", got[ids[0]].LatestRead.Summary)
	assert.Equal(t, "newest 1", got[ids[1]].LatestRead.Summary)
}

// The answer holds experiments and nothing else: no ideas, problems, survey tallies, member
// reactions or picture addresses, whichever pillar they belong to.
func TestUxExperimentsResponseHasOnlyExperiments(t *testing.T) {
	prefix := uniquePrefix("uxonly")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxonlysup")
	db := database.DBConn
	db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, started_at, result, design, pr_url, cleanup_pr_url, created_at, updated_at) VALUES ('ask', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'running', DATE_SUB(NOW(), INTERVAL 2 HOUR), '{\"mde_points\":2}', '{\"touches\":[\"ask\"]}', 'https://example.com/pr', 'https://example.com/cleanup', NOW(), NOW())", prefix+"-one", prefix+" one")

	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/modtools/ux/experiments?flow=ask&jwt="+token, nil), 30000)
	require.NoError(t, err)
	require.Equal(t, 200, resp.StatusCode)
	var raw map[string]interface{}
	require.NoError(t, json.Unmarshal(rsp(resp), &raw))

	assert.ElementsMatch(t, []string{"running_count", "max_running", "flows"}, mapKeys(raw))
	flows, ok := raw["flows"].([]interface{})
	require.True(t, ok)
	require.Len(t, flows, 1)
	entry := flows[0].(map[string]interface{})
	assert.ElementsMatch(t, []string{"flow", "running", "history"}, mapKeys(entry))

	var one map[string]interface{}
	for _, e := range entry["running"].([]interface{}) {
		if e.(map[string]interface{})["name"] == prefix+"-one" {
			one = e.(map[string]interface{})
		}
	}
	require.NotNil(t, one, "the running experiment is listed")
	assert.ElementsMatch(t, []string{
		"id", "flow", "name", "title", "hypothesis", "change", "expected", "arms", "status",
		"started_at", "ends_at", "decided_at", "decision", "result", "design", "pr_url",
		"cleanup_pr_url", "latest_read", "hours", "cleanup_overdue", "holdout_until", "created_at",
	}, mapKeys(one))
}

// A restarted experiment keeps the reads of the run before in the table. The answer carries only
// the paragraph of the newest read taken at or after the experiment's last start, so the text of a
// run it is no longer in is never sent. The structured read in result is sent as it is stored.
func TestUxExperimentsLatestReadIsFromTheCurrentRun(t *testing.T) {
	prefix := uniquePrefix("uxrun")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxrunsup")
	db := database.DBConn

	// startedHoursAgo of 0 leaves started_at empty, as it is on a draft.
	insert := func(name, status string, startedHoursAgo int, result interface{}) uint64 {
		started := "NULL"
		args := []interface{}{name, name, status, result}
		if startedHoursAgo > 0 {
			started = "DATE_SUB(NOW(), INTERVAL ? HOUR)"
			args = append(args, startedHoursAgo)
		}
		db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, result, started_at, created_at, updated_at) VALUES ('reply', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', ?, ?, "+started+", NOW(), NOW())", args...)
		var id uint64
		db.Raw("SELECT id FROM ux_experiments WHERE name = ?", name).Scan(&id)
		require.NotZero(t, id)
		return id
	}
	read := func(id uint64, hoursAgo int, summary string) {
		db.Exec("INSERT INTO ux_experiment_reads (experiment_id, read_at, hours, metrics, summary) VALUES (?, DATE_SUB(NOW(), INTERVAL ? HOUR), 1, '{}', ?)", id, hoursAgo, summary)
	}
	fetch := func() (map[uint64]ux.Experiment, string) {
		resp, err := getApp().Test(httptest.NewRequest("GET", "/api/modtools/ux/experiments?flow=reply&jwt="+token, nil), 30000)
		require.NoError(t, err)
		require.Equal(t, 200, resp.StatusCode)
		body := rsp(resp)
		var all ux.ExperimentsResponse
		require.NoError(t, json.Unmarshal(body, &all))
		got := map[uint64]ux.Experiment{}
		for _, f := range all.Flows {
			for _, e := range append(append([]ux.Experiment{}, f.Running...), f.History...) {
				got[e.ID] = e
			}
		}
		return got, string(body)
	}

	// A running experiment with one read from before its start and two after: the newest.
	stored := `{"arms":{"control":{"starts":10}},"mde_points":2.1}`
	running := insert(prefix+"-running", "running", 6, stored)
	read(running, 10, prefix+" run before")
	read(running, 4, prefix+" this run first")
	read(running, 2, prefix+" this run newest")
	got, _ := fetch()
	require.Contains(t, got, running)
	require.NotNil(t, got[running].LatestRead)
	assert.Equal(t, prefix+" this run newest", got[running].LatestRead.Summary)
	assert.JSONEq(t, stored, string(got[running].Result), "the result of a running experiment is sent as it is stored")

	// Start again: started_at moves to after every read. The experiment has no read until its first
	// new one, and nothing of the run before is sent.
	db.Exec("UPDATE ux_experiments SET started_at = DATE_SUB(NOW(), INTERVAL 1 HOUR), result = '{\"mde_points\":2.1}' WHERE id = ?", running)
	got, body := fetch()
	assert.Nil(t, got[running].LatestRead, "no read since the last start")
	assert.NotContains(t, body, prefix+" run before")
	assert.NotContains(t, body, prefix+" this run")
	assert.JSONEq(t, `{"mde_points":2.1}`, string(got[running].Result), "the start facts, with no arms")

	// Its first new read appears at once.
	read(running, 0, prefix+" first read of the new run")
	got, _ = fetch()
	require.NotNil(t, got[running].LatestRead)
	assert.Equal(t, prefix+" first read of the new run", got[running].LatestRead.Summary)

	// A draft has no start and so no read, whatever is in the table.
	draft := insert(prefix+"-draft", "draft", 0, nil)
	read(draft, 3, prefix+" draft read")
	got, body = fetch()
	require.Contains(t, got, draft)
	assert.Nil(t, got[draft].LatestRead)
	assert.NotContains(t, body, prefix+" draft read")

	// A finished experiment gives the newest read of its last run, not of the one before.
	finished := insert(prefix+"-finished", "stopped", 20, stored)
	db.Exec("UPDATE ux_experiments SET decided_at = DATE_SUB(NOW(), INTERVAL 5 HOUR) WHERE id = ?", finished)
	read(finished, 30, prefix+" older run")
	read(finished, 15, prefix+" last run first")
	read(finished, 8, prefix+" last run newest")
	got, _ = fetch()
	require.NotNil(t, got[finished].LatestRead)
	assert.Equal(t, prefix+" last run newest", got[finished].LatestRead.Summary)
	assert.JSONEq(t, stored, string(got[finished].Result), "a finished experiment keeps its final read in result")
}

// A body written for an earlier shape may still carry idea_id and picture addresses. They are
// ignored: the request is accepted, and the engine's table has no column for them.
func TestUxCreateExperimentIgnoresIdeaAndImageFields(t *testing.T) {
	prefix := uniquePrefix("uxold")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxoldsup")
	db := database.DBConn

	body := uxExperimentBody(prefix, "-old", "give")
	body["idea_id"] = 7
	body["image_control"] = "https://img.example/a.png"
	body["image_variant"] = "https://img.example/b.png"
	code, out := uxPost(t, token, "experiments", body)
	require.Equal(t, 200, code, out)
	assert.Equal(t, "draft", out["status"])

	var stored int64
	db.Table("ux_experiments").Where("name = ?", body["name"]).Count(&stored)
	assert.Equal(t, int64(1), stored)

	var columns int64
	db.Raw("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ux_experiments' AND column_name IN ('idea_id', 'image_control', 'image_variant')").Scan(&columns)
	assert.Zero(t, columns, "the table has no idea or picture columns, so nothing could be stored")
}

// uxSendText sends a request and returns the status and the body as text. A refusal is answered by
// the test app with its message as plain text, which uxPost and uxPatch cannot decode.
func uxSendText(t *testing.T, method, token, path string, body interface{}) (int, string) {
	t.Helper()
	b, _ := json.Marshal(body)
	url := "/api/modtools/ux/" + path
	if token != "" {
		url += "?jwt=" + token
	}
	req := httptest.NewRequest(method, url, strings.NewReader(string(b)))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req, 30000)
	require.NoError(t, err)
	return resp.StatusCode, string(rsp(resp))
}

// The address of the code change is drawn as a link, so one that does not start with https:// is
// refused, and the check is on the text as sent.
func TestUxCreateExperimentRefusesAnAddressThatIsNotHttps(t *testing.T) {
	prefix := uniquePrefix("uxurl")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxurlsup")
	db := database.DBConn

	for i, address := range []string{"javascript:alert(1)", "http://example.com", " https://example.com"} {
		body := uxExperimentBody(prefix, fmt.Sprintf("-bad%d", i), "give")
		body["pr_url"] = address
		code, text := uxSendText(t, "POST", token, "experiments", body)
		assert.Equal(t, 400, code, address)
		assert.Equal(t, "pr_url must start with https://", text, address)
		var n int64
		db.Table("ux_experiments").Where("name = ?", body["name"]).Count(&n)
		assert.Zero(t, n, "nothing is stored for "+address)
	}

	// No address at all is fine.
	none := uxExperimentBody(prefix, "-none", "give")
	none["pr_url"] = ""
	code, out := uxPost(t, token, "experiments", none)
	require.Equal(t, 200, code, out)
	var noneRow struct {
		PrURL *string `gorm:"column:pr_url"`
	}
	db.Raw("SELECT pr_url FROM ux_experiments WHERE id = ?", uint64(out["id"].(float64))).Scan(&noneRow)
	assert.Nil(t, noneRow.PrURL)

	// A good one is stored as sent.
	good := "https://github.com/Freegle/Iznik/pull/1"
	withURL := uxExperimentBody(prefix, "-good", "give")
	withURL["pr_url"] = good
	code, out = uxPost(t, token, "experiments", withURL)
	require.Equal(t, 200, code, out)
	var goodRow struct {
		PrURL *string `gorm:"column:pr_url"`
	}
	db.Raw("SELECT pr_url FROM ux_experiments WHERE id = ?", uint64(out["id"].(float64))).Scan(&goodRow)
	require.NotNil(t, goodRow.PrURL)
	assert.Equal(t, good, *goodRow.PrURL)
}

// The address of the cleanup is drawn as a link in the same way.
func TestUxExperimentActionRefusesACleanupAddressThatIsNotHttps(t *testing.T) {
	prefix := uniquePrefix("uxclean")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxcleansup")
	db := database.DBConn
	db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'adopted', DATE_SUB(NOW(), INTERVAL 1 DAY), NOW(), NOW())", prefix+"-adopted", prefix+" adopted")
	var id uint64
	db.Raw("SELECT id FROM ux_experiments WHERE name = ?", prefix+"-adopted").Scan(&id)
	require.NotZero(t, id)
	path := "experiments/" + strconv.FormatUint(id, 10)
	queued := func() int64 {
		var n int64
		db.Table("background_tasks").Where("task_type = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.reason')) = ?", ux.TaskUxExperiment, prefix).Count(&n)
		return n
	}

	for _, address := range []string{"javascript:alert(1)", "http://example.com", " https://example.com"} {
		code, text := uxSendText(t, "PATCH", token, path, map[string]interface{}{"action": "close", "reason": prefix, "cleanup_pr": address})
		assert.Equal(t, 400, code, address)
		assert.Equal(t, "cleanup_pr must start with https://", text, address)
	}
	assert.Zero(t, queued(), "nothing is queued for a bad address")

	// No address at all is fine.
	code, out := uxPatch(t, token, path, map[string]interface{}{"action": "close", "reason": prefix})
	require.Equal(t, 200, code, out)
	assert.Equal(t, int64(1), queued())

	// A good one is queued as sent.
	good := "https://github.com/Freegle/Iznik/pull/2"
	code, out = uxPatch(t, token, path, map[string]interface{}{"action": "close", "reason": prefix, "cleanup_pr": good})
	require.Equal(t, 200, code, out)
	var withAddress int64
	db.Table("background_tasks").Where("task_type = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.cleanup_pr')) = ?", ux.TaskUxExperiment, good).Count(&withAddress)
	assert.Equal(t, int64(1), withAddress)
}

// An experiment that ended without being adopted has dead variant code at once, until a cleanup is
// recorded on it.
func TestUxExperimentsFlagCleanupDueForEveryEndedStatus(t *testing.T) {
	prefix := uniquePrefix("uxdue")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxduesup")
	db := database.DBConn

	for _, status := range []string{"reverted", "inconclusive", "stopped", "running", "draft"} {
		db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', ?, NOW(), NOW(), NOW())", prefix+"-"+status, prefix+" "+status, status)
	}
	db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, cleanup_pr_url, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'reverted', NOW(), 'https://github.com/Freegle/Iznik/pull/3', NOW(), NOW())", prefix+"-cleaned", prefix+" cleaned")

	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, token, "experiments", &all))
	due := map[string]bool{}
	for _, f := range all.Flows {
		for _, e := range append(append([]ux.Experiment{}, f.Running...), f.History...) {
			due[e.Name] = e.CleanupOverdue
		}
	}
	for _, status := range []string{"reverted", "inconclusive", "stopped"} {
		assert.True(t, due[prefix+"-"+status], status+" with no cleanup is due at once")
	}
	assert.False(t, due[prefix+"-running"])
	assert.False(t, due[prefix+"-draft"])
	assert.False(t, due[prefix+"-cleaned"], "a recorded cleanup ends it")
}

func TestUxExperimentActionClosesAnEndedExperimentOnlyWithACleanup(t *testing.T) {
	prefix := uniquePrefix("uxendclose")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxendclosesup")
	db := database.DBConn
	idOf := func(status string) string {
		db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', ?, NOW(), NOW(), NOW())", prefix+"-"+status, prefix+" "+status, status)
		var id uint64
		db.Raw("SELECT id FROM ux_experiments WHERE name = ?", prefix+"-"+status).Scan(&id)
		require.NotZero(t, id)
		return "experiments/" + strconv.FormatUint(id, 10)
	}
	queued := func() int64 {
		var n int64
		db.Table("background_tasks").Where("task_type = ? AND JSON_UNQUOTE(JSON_EXTRACT(data, '$.reason')) = ?", ux.TaskUxExperiment, prefix).Count(&n)
		return n
	}

	for _, status := range []string{"reverted", "inconclusive", "stopped"} {
		path := idOf(status)
		code, text := uxSendText(t, "PATCH", token, path, map[string]interface{}{"action": "close", "reason": prefix})
		assert.Equal(t, 400, code, status)
		assert.Equal(t, "Give the cleanup pull request that removed the code", text, status)
		code, out := uxPatch(t, token, path, map[string]interface{}{"action": "close", "reason": prefix, "cleanup_pr": "https://github.com/Freegle/Iznik/pull/4"})
		assert.Equal(t, 200, code, out)
	}
	assert.Equal(t, int64(3), queued())

	for _, status := range []string{"running", "draft"} {
		code, text := uxSendText(t, "PATCH", token, idOf(status), map[string]interface{}{"action": "close", "reason": prefix, "cleanup_pr": "https://github.com/Freegle/Iznik/pull/4"})
		assert.Equal(t, 400, code, status)
		assert.Contains(t, text, "can be closed", status)
	}
	assert.Equal(t, int64(3), queued(), "nothing more was queued")
}

func TestUxExperimentActionRefusesToStartOneWhoseCodeIsGone(t *testing.T) {
	prefix := uniquePrefix("uxgone")
	uxExpClean(t, prefix)
	token := uxSupportToken(t, "uxgonesup")
	db := database.DBConn
	db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, decided_at, cleanup_pr_url, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', 'reverted', NOW(), 'https://github.com/Freegle/Iznik/pull/3', NOW(), NOW())", prefix+"-gone", prefix+" gone")
	var id uint64
	db.Raw("SELECT id FROM ux_experiments WHERE name = ?", prefix+"-gone").Scan(&id)
	require.NotZero(t, id)

	code, text := uxSendText(t, "PATCH", token, "experiments/"+strconv.FormatUint(id, 10), map[string]interface{}{"action": "start", "reason": prefix})
	assert.Equal(t, 400, code)
	assert.Contains(t, text, "code has been removed")
	assert.Contains(t, text, "new name")
	assert.Contains(t, text, "salt")
}

// uxFunctionBody returns the text of the named top-level function in a Go source file: from its
// "func name(" to the next top-level function, or the end of the file. It fails the test if the
// file or the function is not found, or the body is empty, so a wrong name or path cannot pass.
func uxFunctionBody(t *testing.T, path, name string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	require.NoError(t, err, path)
	require.NotEmpty(t, b, path+" is empty")
	text := string(b)
	start := strings.Index(text, "func "+name+"(")
	require.GreaterOrEqual(t, start, 0, name+" was not found in "+path)
	body := text[start:]
	if next := strings.Index(body[1:], "\nfunc "); next >= 0 {
		body = body[:next+1]
	}
	require.NotEmpty(t, strings.TrimSpace(body), name+" has an empty body")
	return body
}

// Production sends a SELECT to the read replica, and a caller that has just created an experiment
// sends the start request straight after. A replica that does not hold the row yet would answer
// "No such experiment". The test database has no replica, so a call cannot show the difference:
// this reads the source and fails if the lookup stops being pinned to the primary.
func TestUxExperimentActionLooksUpTheExperimentOnThePrimary(t *testing.T) {
	body := uxFunctionBody(t, "../ux/experiments.go", "ExperimentAction")
	pin := strings.Index(body, "dbresolver.Write")
	lookup := strings.Index(body, "ux_experiments")
	require.GreaterOrEqual(t, lookup, 0, "the lookup of the experiment was not found")
	require.GreaterOrEqual(t, pin, 0, "the lookup of the experiment is not pinned to the primary database")
	assert.Less(t, pin, lookup, "the pin comes before the first lookup of the experiment")
}

// The id of a new draft is read back after the insert. It is read from the primary too, or a
// replica that has not caught up would answer 0 and the caller would start experiment 0.
func TestUxCreateExperimentReadsTheNewIdFromThePrimary(t *testing.T) {
	body := uxFunctionBody(t, "../ux/experiments.go", "CreateExperiment")
	insert := strings.Index(body, `Table("ux_experiments").Create(`)
	require.GreaterOrEqual(t, insert, 0, "the insert was not found")
	lookup := strings.Index(body[insert:], `Select("id")`)
	require.GreaterOrEqual(t, lookup, 0, "the read of the new id was not found")
	assert.Contains(t, body[insert:insert+lookup], "dbresolver.Write", "the read of the new id is not pinned to the primary database")
}

// The experiments routes read their own two tables and nothing else. They name no table that
// belongs to another part of the UX work.
func TestUxExperimentsBoundary(t *testing.T) {
	files := []string{"../ux/experiments.go", "../ux/serverarm.go"}
	read := 0
	for _, f := range files {
		b, err := os.ReadFile(f)
		require.NoError(t, err, f)
		require.NotEmpty(t, b, f+" is empty")
		read++
		for _, name := range []string{"ux_ideas", "ux_prompts", "ux_prompt_responses", "ux_problems"} {
			assert.NotContains(t, string(b), name, f)
		}
	}
	require.Len(t, files, 2)
	assert.Equal(t, 2, read, "both files were read")
}

// The SysAdmin nudge counts experiments with a verdict that nobody has closed: adopted ones to
// confirm, and reverted, inconclusive or stopped ones whose code is still in. Closed ones, ones with a
// cleanup pull request, and ones still running or not started are not counted.
func TestUxExperimentsVerdictCountsInSessionWork(t *testing.T) {
	prefix := uniquePrefix("uxverdict")
	uxExpClean(t, prefix)
	db := database.DBConn
	groupID := CreateTestGroup(t, prefix)

	adminID := CreateTestUser(t, prefix+"_admin", "Admin")
	CreateTestMembership(t, adminID, groupID, "Moderator")
	_, adminToken := CreateTestSession(t, adminID)
	supportID := CreateTestUser(t, prefix+"_support", "Support")
	CreateTestMembership(t, supportID, groupID, "Moderator")
	_, supportToken := CreateTestSession(t, supportID)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, modToken := CreateTestSession(t, modID)

	baseConfirm, baseRemove := ux.VerdictCounts()

	insert := func(name, status string, cleanupPR interface{}) {
		db.Exec("INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, cleanup_pr_url, decided_at, created_at, updated_at) VALUES ('give', ?, ?, 'h', 'c', 'e', '{\"control\":[0,49],\"b\":[50,99]}', ?, ?, NOW(), NOW(), NOW())", prefix+name, prefix+name, status, cleanupPR)
	}
	// Counted: one adopted (confirm), and one each of the ended kinds (remove).
	insert("-adopted", "adopted", nil)
	insert("-reverted", "reverted", "")
	insert("-inconclusive", "inconclusive", nil)
	insert("-stopped", "stopped", nil)
	// Not counted: closed, ended with the cleanup recorded, adopted with a cleanup recorded,
	// running and not started.
	insert("-closed", "closed", "https://github.com/Freegle/Iznik/pull/1")
	insert("-reverted-done", "reverted", "https://github.com/Freegle/Iznik/pull/2")
	insert("-adopted-done", "adopted", "https://github.com/Freegle/Iznik/pull/3")
	insert("-running", "running", nil)
	insert("-draft", "draft", nil)

	confirm, remove := ux.VerdictCounts()
	assert.Equal(t, baseConfirm+1, confirm)
	assert.Equal(t, baseRemove+3, remove)

	for name, token := range map[string]string{"admin": adminToken, "support": supportToken} {
		work := getSessionWork(t, token)
		assert.Equal(t, float64(confirm+remove), work["uxexperiments"], name)
		assert.Equal(t, float64(confirm), work["uxexperimentsconfirm"], name)
		assert.Equal(t, float64(remove), work["uxexperimentsremove"], name)
	}

	// An ordinary moderator gets the work counts for their groups, but not this one.
	modWork := getSessionWork(t, modToken)
	assert.Equal(t, float64(0), modWork["uxexperiments"])

	// The Go API agrees with the experiments list on which ended ones are waiting.
	var all ux.ExperimentsResponse
	require.Equal(t, 200, uxGet(t, adminToken, "experiments", &all))
	for _, f := range all.Flows {
		for _, e := range f.History {
			switch e.Name {
			case prefix + "-reverted", prefix + "-inconclusive", prefix + "-stopped":
				assert.True(t, e.CleanupOverdue, e.Name)
			case prefix + "-reverted-done", prefix + "-closed":
				assert.False(t, e.CleanupOverdue, e.Name)
			}
		}
	}
}
