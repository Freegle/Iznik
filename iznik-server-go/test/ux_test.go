package test

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The UX read API: GET /modtools/ux/*. Each test seeds the ux_* tables for dates in its own far
// future year, so nothing here can meet real rollup rows, and removes them afterwards.

func uxSupportToken(t *testing.T, name string) string {
	id := CreateTestUser(t, uniquePrefix(name), "Support")
	_, token := CreateTestSession(t, id)
	return token
}

// uxGet calls the endpoint as the given token ("" = not logged in) and decodes the body into out.
func uxGet(t *testing.T, token, path string, out interface{}) int {
	t.Helper()
	sep := "?"
	if strings.Contains(path, "?") {
		sep = "&"
	}
	url := "/api/modtools/ux/" + path
	if token != "" {
		url += sep + "jwt=" + token
	}
	resp, err := getApp().Test(httptest.NewRequest("GET", url, nil), 30000)
	require.NoError(t, err)
	if out != nil && resp.StatusCode == 200 {
		require.NoError(t, json.Unmarshal(rsp(resp), out))
	}
	return resp.StatusCode
}

func uxClean(t *testing.T, from, to string) {
	t.Helper()
	ux.ResetCache()
	cleanup := func() {
		db := database.DBConn
		for _, tbl := range []string{"ux_flow_daily", "ux_flow_outcome_daily", "ux_field_daily", "ux_component_daily",
			"ux_feature_adoption_daily", "ux_search_daily", "ux_regret_daily", "ux_telemetry_health_daily", "ux_sample_sessions"} {
			db.Exec("DELETE FROM "+tbl+" WHERE `date` BETWEEN ? AND ?", from, to)
		}
		ux.ResetCache()
	}
	cleanup()
	t.Cleanup(cleanup)
}

func TestUxRequiresLoginAndSupport(t *testing.T) {
	ux.ResetCache()
	userID := CreateTestUser(t, uniquePrefix("uxnoauth"), "User")
	_, userToken := CreateTestSession(t, userID)

	for _, p := range []string{"funnels", "outcomes", "fields", "friction", "adoption", "regret", "search", "health",
		"samples?date=2031-01-01&flow=give&step=a"} {
		assert.Equal(t, 401, uxGet(t, "", p, nil), p)
		assert.Equal(t, 403, uxGet(t, userToken, p, nil), p)
	}
}

func TestUxInputValidation(t *testing.T) {
	token := uxSupportToken(t, "uxvalid")

	for _, p := range []string{
		"funnels?start=nonsense", "funnels?end=2031-13-45", "funnels?start=2031-02-02&end=2031-02-01",
		"outcomes?start=2020-01-01&end=2031-01-01", // over a year
		"funnels?flow=a%20b", "funnels?flow=give%27%20OR%201%3D1", "fields?flow=" + strings.Repeat("a", 65),
		"samples", "samples?date=2031-01-01", "samples?date=2031-01-01&flow=give", "samples?date=bad&flow=give&step=a",
		"samples?date=2031-01-01&flow=g%22ive&step=a", "samples?date=2031-01-01&flow=give&step=a%7Cb",
	} {
		assert.Equal(t, 400, uxGet(t, token, p, nil), p)
	}
}

func TestUxDefaultsToLastSevenDaysEndingYesterday(t *testing.T) {
	token := uxSupportToken(t, "uxdefault")
	ux.ResetCache()

	var out ux.SearchResponse
	require.Equal(t, 200, uxGet(t, token, "search", &out))
	now := time.Now().UTC()
	assert.Equal(t, now.AddDate(0, 0, -1).Format("2006-01-02"), out.End)
	assert.Equal(t, now.AddDate(0, 0, -7).Format("2006-01-02"), out.Start)
	assert.NotNil(t, out.Days, "an empty period is [] not null")
}

func TestUxFunnels(t *testing.T) {
	token := uxSupportToken(t, "uxfunnel")
	uxClean(t, "2031-03-01", "2031-03-14")
	db := database.DBConn

	ins := func(date, flow, step, dev string, li int, variant string, entered, reentered, completed, backed, abandoned, revisits, vf, help int, p50, p90 interface{}) {
		require.NoError(t, db.Exec("INSERT INTO ux_flow_daily (`date`,flow,step,device_class,logged_in,variant,entered,reentered,completed,backed,abandoned,revisits,validation_failures,help_sought,dwell_p50_ms,dwell_p90_ms) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
			date, flow, step, dev, li, variant, entered, reentered, completed, backed, abandoned, revisits, vf, help, p50, p90).Error)
	}
	ins("2031-03-08", "give", "A", "desktop", 1, "", 100, 10, 60, 5, 30, 7, 3, 2, 1000, 5000)
	ins("2031-03-09", "give", "A", "mobile", 0, "x:b", 300, 4, 150, 20, 100, 1, 0, 1, 2000, 9000)
	ins("2031-03-08", "give", "B", "desktop", 1, "", 50, 0, 40, 0, 5, 0, 0, 0, nil, nil)
	ins("2031-03-10", "ask", "Q", "desktop", 1, "", 10, 0, 5, 0, 1, 0, 0, 0, 700, 800)
	ins("2031-03-02", "give", "A", "desktop", 1, "", 80, 0, 40, 0, 20, 0, 0, 0, 1500, 4000)
	ins("2031-03-15", "give", "A", "desktop", 1, "", 999, 0, 0, 0, 0, 0, 0, 0, 1, 1) // outside both periods
	defer db.Exec("DELETE FROM ux_flow_daily WHERE `date` = '2031-03-15'")

	var out ux.FunnelsResponse
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2031-03-08&end=2031-03-14", &out))
	assert.Equal(t, "2031-03-08", out.Start)
	assert.Equal(t, "2031-03-14", out.End)

	require.Len(t, out.Flows, 2)
	assert.Equal(t, "ask", out.Flows[0].Flow)
	give := out.Flows[1]
	assert.Equal(t, "give", give.Flow)
	require.Len(t, give.Steps, 2)

	a := give.Steps[0]
	assert.Equal(t, "A", a.Step, "steps are ordered by entered, largest first")
	assert.EqualValues(t, 400, a.Entered)
	assert.EqualValues(t, 14, a.Reentered)
	assert.EqualValues(t, 414, a.Visits, "entered plus reentered: the denominator for completed")
	assert.EqualValues(t, 210, a.Completed)
	assert.EqualValues(t, 25, a.Backed)
	assert.EqualValues(t, 130, a.Abandoned)
	assert.EqualValues(t, 8, a.Revisits)
	assert.EqualValues(t, 3, a.ValidationFailures)
	assert.EqualValues(t, 3, a.HelpSought)
	require.NotNil(t, a.DwellP50Ms)
	require.NotNil(t, a.DwellP90Ms)
	assert.EqualValues(t, 1750, *a.DwellP50Ms, "weighted by entered: (100*1000+300*2000)/400")
	assert.EqualValues(t, 8000, *a.DwellP90Ms)

	require.NotNil(t, a.By)
	require.Len(t, a.By.DeviceClass, 2)
	assert.Equal(t, ux.Breakdown{Key: "mobile", Entered: 300, Visits: 304, Completed: 150, Abandoned: 100}, a.By.DeviceClass[0])
	assert.Equal(t, ux.Breakdown{Key: "desktop", Entered: 100, Visits: 110, Completed: 60, Abandoned: 30}, a.By.DeviceClass[1])
	require.Len(t, a.By.LoggedIn, 2)
	assert.Equal(t, "false", a.By.LoggedIn[0].Key)
	assert.EqualValues(t, 300, a.By.LoggedIn[0].Entered)
	assert.Equal(t, "true", a.By.LoggedIn[1].Key)
	require.Len(t, a.By.Variant, 2)
	assert.Equal(t, "x:b", a.By.Variant[0].Key)
	assert.Equal(t, "", a.By.Variant[1].Key)

	b := give.Steps[1]
	assert.Equal(t, "B", b.Step)
	assert.Nil(t, b.DwellP50Ms, "no dwell recorded is null, not zero")

	// Previous period: the equal-length week before, and no breakdown.
	require.Len(t, out.Previous.Flows, 1)
	prev := out.Previous.Flows[0]
	assert.Equal(t, "give", prev.Flow)
	require.Len(t, prev.Steps, 1)
	assert.EqualValues(t, 80, prev.Steps[0].Entered)
	assert.EqualValues(t, 1500, *prev.Steps[0].DwellP50Ms)
	assert.Nil(t, prev.Steps[0].By)

	// The wire keys are exactly as documented.
	var raw map[string]interface{}
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2031-03-08&end=2031-03-14&flow=give", &raw))
	step := raw["flows"].([]interface{})[0].(map[string]interface{})["steps"].([]interface{})[0].(map[string]interface{})
	assert.ElementsMatch(t, []string{"step", "entered", "reentered", "visits", "completed", "backed", "abandoned", "revisits",
		"validation_failures", "help_sought", "dwell_p50_ms", "dwell_p90_ms", "by"}, mapKeys(step))
	assert.ElementsMatch(t, []string{"device_class", "logged_in", "variant"}, mapKeys(step["by"].(map[string]interface{})))
	assert.ElementsMatch(t, []string{"key", "entered", "visits", "completed", "abandoned"},
		mapKeys(step["by"].(map[string]interface{})["device_class"].([]interface{})[0].(map[string]interface{})))
	assert.ElementsMatch(t, []string{"start", "end", "flows", "previous", "filters", "ignored"}, mapKeys(raw))
	assert.Len(t, raw["flows"], 1, "flow filter")
	prevStep := raw["previous"].(map[string]interface{})["flows"].([]interface{})[0].(map[string]interface{})["steps"].([]interface{})[0].(map[string]interface{})
	assert.NotContains(t, prevStep, "by")
}

func mapKeys(m map[string]interface{}) []string {
	var k []string
	for key := range m {
		k = append(k, key)
	}
	sort.Strings(k)
	return k
}

func TestUxOutcomes(t *testing.T) {
	token := uxSupportToken(t, "uxoutcome")
	uxClean(t, "2032-03-01", "2032-03-14")
	db := database.DBConn

	require.NoError(t, db.Exec("INSERT INTO ux_flow_outcome_daily (`date`,flow,device_class,started,submitted,submit_ok,submit_failed,completed,abandoned,resumed,total_dwell_p50_ms) VALUES "+
		"('2032-03-08','give','desktop',100,80,70,10,70,25,5,60000),"+
		"('2032-03-09','give','mobile',300,200,150,50,160,120,15,100000),"+
		"('2032-03-09','ask','mobile',20,0,0,0,0,0,0,NULL),"+
		"('2032-03-02','give','mobile',50,40,40,0,40,10,1,30000)").Error)

	var out ux.OutcomesResponse
	require.Equal(t, 200, uxGet(t, token, "outcomes?start=2032-03-08&end=2032-03-14", &out))
	require.Len(t, out.Flows, 2)
	assert.Equal(t, "ask", out.Flows[0].Flow)
	assert.Nil(t, out.Flows[0].TotalDwellP50Ms)
	g := out.Flows[1]
	assert.Equal(t, "give", g.Flow)
	assert.EqualValues(t, 400, g.Started)
	assert.EqualValues(t, 280, g.Submitted)
	assert.EqualValues(t, 220, g.SubmitOK)
	assert.EqualValues(t, 60, g.SubmitFailed)
	assert.EqualValues(t, 230, g.Completed)
	assert.EqualValues(t, 145, g.Abandoned)
	assert.EqualValues(t, 20, g.Resumed)
	require.NotNil(t, g.TotalDwellP50Ms)
	assert.EqualValues(t, 90000, *g.TotalDwellP50Ms, "weighted by started")

	require.Len(t, out.Previous.Flows, 1)
	assert.EqualValues(t, 50, out.Previous.Flows[0].Started)

	var raw map[string]interface{}
	uxGet(t, token, "outcomes?start=2032-03-08&end=2032-03-14", &raw)
	row := raw["flows"].([]interface{})[0].(map[string]interface{})
	assert.ElementsMatch(t, []string{"flow", "started", "submitted", "submit_ok", "submit_failed", "completed", "abandoned", "resumed", "total_dwell_p50_ms"}, mapKeys(row))
}

func TestUxFields(t *testing.T) {
	token := uxSupportToken(t, "uxfields")
	uxClean(t, "2033-03-01", "2033-03-14")
	db := database.DBConn

	require.NoError(t, db.Exec("INSERT INTO ux_field_daily (`date`,flow,step,field,device_class,revisits,validation_failures,choice_switches) VALUES "+
		"('2033-03-08','give','A','photo','desktop',5,2,1),"+
		"('2033-03-09','give','A','photo','mobile',1,0,3),"+
		"('2033-03-09','give','B','email','mobile',0,10,0),"+
		"('2033-03-09','ask','Q','area','mobile',1,0,0),"+
		"('2033-03-02','give','A','photo','mobile',2,0,0)").Error)

	var out ux.FieldsResponse
	require.Equal(t, 200, uxGet(t, token, "fields?start=2033-03-08&end=2033-03-14", &out))
	require.Len(t, out.Fields, 3)
	assert.Equal(t, ux.FieldRow{Flow: "give", Step: "B", Field: "email", Revisits: 0, ValidationFailures: 10, ChoiceSwitches: 0}, out.Fields[0], "ordered by revisits + validation failures")
	assert.Equal(t, ux.FieldRow{Flow: "give", Step: "A", Field: "photo", Revisits: 6, ValidationFailures: 2, ChoiceSwitches: 4}, out.Fields[1])
	assert.Equal(t, "area", out.Fields[2].Field)
	require.Len(t, out.Previous.Fields, 1)
	assert.EqualValues(t, 2, out.Previous.Fields[0].Revisits)

	var filtered ux.FieldsResponse
	require.Equal(t, 200, uxGet(t, token, "fields?start=2033-03-08&end=2033-03-14&flow=ask", &filtered))
	require.Len(t, filtered.Fields, 1)
	assert.Equal(t, "area", filtered.Fields[0].Field)
	assert.Empty(t, filtered.Previous.Fields)
	assert.NotNil(t, filtered.Previous.Fields, "[] not null")

	var raw map[string]interface{}
	uxGet(t, token, "fields?start=2033-03-08&end=2033-03-14", &raw)
	assert.ElementsMatch(t, []string{"flow", "step", "field", "revisits", "validation_failures", "choice_switches"},
		mapKeys(raw["fields"].([]interface{})[0].(map[string]interface{})))
}

func TestUxFriction(t *testing.T) {
	token := uxSupportToken(t, "uxfriction")
	uxClean(t, "2034-03-01", "2034-03-14")
	db := database.DBConn

	require.NoError(t, db.Exec("INSERT INTO ux_component_daily (`date`,page,component,device_class,impressions,clicks,dead_clicks,rage_clicks,repeated_intent) VALUES "+
		// btn-next now: clicks 100, friction 10+5+5 = 20 -> 0.2; before 100 clicks, 2 friction -> 0.02; change 0.18.
		"('2034-03-08','give','btn-next','desktop',600,60,6,3,3),"+
		"('2034-03-09','give','btn-next','mobile',400,40,4,2,2),"+
		"('2034-03-02','give','btn-next','mobile',900,100,2,0,0),"+
		// photo-drop is new: 25 dead of 50 clicks -> 0.5, nothing before.
		"('2034-03-09','give','photo-drop','mobile',70,50,25,0,0),"+
		// no clicks at all: divisor is max(clicks,1), so 3 rage clicks -> 3.
		"('2034-03-09','home','ghost','mobile',5,0,0,3,0),"+
		// got better: change is negative.
		"('2034-03-09','home','calm','mobile',5,100,1,0,0),"+
		"('2034-03-03','home','calm','mobile',5,100,30,0,0)").Error)

	var out ux.FrictionResponse
	require.Equal(t, 200, uxGet(t, token, "friction?start=2034-03-08&end=2034-03-14", &out))
	require.Len(t, out.Components, 4)
	order := []string{}
	for _, c := range out.Components {
		order = append(order, c.Component)
	}
	assert.Equal(t, []string{"ghost", "photo-drop", "btn-next", "calm"}, order)

	g := out.Components[0]
	assert.EqualValues(t, 3, g.RageClicks)
	assert.InDelta(t, 3.0, g.Change, 1e-9)

	n := out.Components[2]
	assert.Equal(t, "give", n.Page)
	assert.EqualValues(t, 1000, n.Impressions)
	assert.EqualValues(t, 100, n.Clicks)
	assert.EqualValues(t, 10, n.DeadClicks)
	assert.EqualValues(t, 5, n.RageClicks)
	assert.EqualValues(t, 5, n.RepeatedIntent)
	assert.EqualValues(t, 900, n.Previous.Impressions)
	assert.EqualValues(t, 100, n.Previous.Clicks)
	assert.EqualValues(t, 2, n.Previous.DeadClicks)
	assert.InDelta(t, 0.18, n.Change, 1e-9)

	assert.InDelta(t, -0.29, out.Components[3].Change, 1e-9)

	var raw map[string]interface{}
	uxGet(t, token, "friction?start=2034-03-08&end=2034-03-14", &raw)
	row := raw["components"].([]interface{})[0].(map[string]interface{})
	assert.ElementsMatch(t, []string{"page", "component", "impressions", "clicks", "dead_clicks", "rage_clicks", "repeated_intent", "previous", "change"}, mapKeys(row))
	assert.ElementsMatch(t, []string{"impressions", "clicks", "dead_clicks", "rage_clicks", "repeated_intent"}, mapKeys(row["previous"].(map[string]interface{})))
}

func TestUxFrictionIsCappedAtTwenty(t *testing.T) {
	token := uxSupportToken(t, "uxfriction20")
	uxClean(t, "2035-03-01", "2035-03-14")
	db := database.DBConn
	for i := 0; i < 25; i++ {
		require.NoError(t, db.Exec("INSERT INTO ux_component_daily (`date`,page,component,device_class,impressions,clicks,dead_clicks) VALUES ('2035-03-08','p',?,'',10,10,?)",
			fmt.Sprintf("c%02d", i), i).Error)
	}
	var out ux.FrictionResponse
	require.Equal(t, 200, uxGet(t, token, "friction?start=2035-03-08&end=2035-03-14", &out))
	require.Len(t, out.Components, 20)
	assert.Equal(t, "c24", out.Components[0].Component)
}

func TestUxAdoption(t *testing.T) {
	token := uxSupportToken(t, "uxadopt")
	uxClean(t, "2036-03-01", "2036-03-14")
	db := database.DBConn

	require.NoError(t, db.Exec("INSERT INTO ux_feature_adoption_daily (`date`,feature,active_users,using_users,sessions_using) VALUES "+
		"('2036-03-08','chats',100,20,30),('2036-03-09','chats',100,30,40),('2036-03-02','chats',100,10,12),"+
		"('2036-03-08','nobody',0,0,0)").Error)

	var out ux.AdoptionResponse
	require.Equal(t, 200, uxGet(t, token, "adoption?start=2036-03-08&end=2036-03-14", &out))
	require.Len(t, out.Features, 2)
	assert.Equal(t, "chats", out.Features[0].Feature)
	assert.EqualValues(t, 200, out.Features[0].ActiveUsers)
	assert.EqualValues(t, 50, out.Features[0].UsingUsers)
	assert.EqualValues(t, 70, out.Features[0].SessionsUsing)
	assert.InDelta(t, 0.25, out.Features[0].Share, 1e-9)
	assert.InDelta(t, 0.1, out.Features[0].PreviousShare, 1e-9)
	assert.Equal(t, "nobody", out.Features[1].Feature)
	assert.Equal(t, 0.0, out.Features[1].Share, "no active users is 0, not a divide by zero")
	assert.Equal(t, 0.0, out.Features[1].PreviousShare)

	var raw map[string]interface{}
	uxGet(t, token, "adoption?start=2036-03-08&end=2036-03-14", &raw)
	assert.ElementsMatch(t, []string{"feature", "active_users", "using_users", "sessions_using", "share", "previous_share"},
		mapKeys(raw["features"].([]interface{})[0].(map[string]interface{})))
}

func TestUxRegret(t *testing.T) {
	token := uxSupportToken(t, "uxregret")
	uxClean(t, "2037-03-01", "2037-03-14")
	db := database.DBConn

	require.NoError(t, db.Exec("INSERT INTO ux_regret_daily (`date`,`signal`,`count`,base) VALUES "+
		"('2037-03-10','withdrawn_24h',2,90),('2037-03-08','withdrawn_24h',3,100),('2037-03-02','withdrawn_24h',4,80),"+
		"('2037-03-09','duplicate_10m',1,50)").Error)

	var out ux.RegretResponse
	require.Equal(t, 200, uxGet(t, token, "regret?start=2037-03-08&end=2037-03-14", &out))
	require.Len(t, out.Signals, 2)
	assert.Equal(t, "duplicate_10m", out.Signals[0].Signal)
	assert.EqualValues(t, 0, out.Signals[0].PreviousTotal)
	w := out.Signals[1]
	assert.Equal(t, "withdrawn_24h", w.Signal)
	assert.Equal(t, []ux.RegretDay{{Date: "2037-03-08", Count: 3, Base: 100}, {Date: "2037-03-10", Count: 2, Base: 90}}, w.Days, "days in date order")
	assert.EqualValues(t, 5, w.Total)
	assert.EqualValues(t, 4, w.PreviousTotal)
}

func TestUxSearchAndCache(t *testing.T) {
	token := uxSupportToken(t, "uxsearch")
	uxClean(t, "2038-03-01", "2038-03-14")
	db := database.DBConn

	require.NoError(t, db.Exec("INSERT INTO ux_search_daily (`date`,searches,zero_result,re_searched_60s,browse_sessions,browse_scrolled_out) VALUES "+
		"('2038-03-09',50,5,7,200,40)").Error)

	var out ux.SearchResponse
	require.Equal(t, 200, uxGet(t, token, "search?start=2038-03-08&end=2038-03-14", &out))
	require.Len(t, out.Days, 1)
	assert.Equal(t, ux.SearchDay{Date: "2038-03-09", Searches: 50, ZeroResult: 5, ReSearched60s: 7, BrowseSessions: 200, BrowseScrolledOut: 40}, out.Days[0])

	// A second day is invisible while the first answer is cached...
	require.NoError(t, db.Exec("INSERT INTO ux_search_daily (`date`,searches) VALUES ('2038-03-10',9)").Error)
	var cached ux.SearchResponse
	require.Equal(t, 200, uxGet(t, token, "search?start=2038-03-08&end=2038-03-14", &cached))
	assert.Len(t, cached.Days, 1, "served from the 10 minute cache")

	// ...and appears once the cache is dropped.
	ux.ResetCache()
	var fresh ux.SearchResponse
	require.Equal(t, 200, uxGet(t, token, "search?start=2038-03-08&end=2038-03-14", &fresh))
	assert.Len(t, fresh.Days, 2)

	var raw map[string]interface{}
	uxGet(t, token, "search?start=2038-03-08&end=2038-03-14", &raw)
	assert.ElementsMatch(t, []string{"date", "searches", "zero_result", "re_searched_60s", "browse_sessions", "browse_scrolled_out"},
		mapKeys(raw["days"].([]interface{})[0].(map[string]interface{})))
}

func TestUxHealth(t *testing.T) {
	token := uxSupportToken(t, "uxhealth")
	uxClean(t, "2039-03-01", "2039-03-14")
	db := database.DBConn

	require.NoError(t, db.Exec("INSERT INTO ux_telemetry_health_daily (`date`,metric,expected,observed,ratio) VALUES "+
		"('2039-03-08','event:flow',100,95,0.950),('2039-03-09','event:flow',100,60,0.600),"+
		"('2039-03-08','flow_start:give',NULL,12,NULL),('2039-03-09','flow_start:give',100,130,1.300),('2039-03-10','flow_start:give',100,70,0.700),"+
		"('2039-03-08','event:impressions',100,131,1.310)").Error)

	var out ux.HealthResponse
	require.Equal(t, 200, uxGet(t, token, "health?start=2039-03-08&end=2039-03-14", &out))
	require.Len(t, out.Metrics, 3)
	byName := map[string]ux.HealthMetric{}
	for _, m := range out.Metrics {
		byName[m.Metric] = m
	}

	flow := byName["event:flow"]
	assert.False(t, flow.Ok, "one day outside 0.7 to 1.3 fails the metric")
	require.Len(t, flow.Days, 2)
	assert.True(t, flow.Days[0].Ok)
	assert.False(t, flow.Days[1].Ok)
	assert.Equal(t, "2039-03-08", flow.Days[0].Date)
	require.NotNil(t, flow.Days[0].Expected)
	assert.InDelta(t, 100, *flow.Days[0].Expected, 1e-9)
	assert.InDelta(t, 95, flow.Days[0].Observed, 1e-9)
	require.NotNil(t, flow.Days[0].Ratio)
	assert.InDelta(t, 0.95, *flow.Days[0].Ratio, 1e-9)

	start := byName["flow_start:give"]
	assert.True(t, start.Ok, "a null ratio and the exact edges 0.7 and 1.3 are all fine")
	assert.Nil(t, start.Days[0].Ratio)
	assert.Nil(t, start.Days[0].Expected)
	assert.True(t, start.Days[0].Ok)

	assert.False(t, byName["event:impressions"].Ok, "1.31 is outside the band")

	var raw map[string]interface{}
	uxGet(t, token, "health?start=2039-03-08&end=2039-03-14", &raw)
	m := raw["metrics"].([]interface{})[0].(map[string]interface{})
	assert.ElementsMatch(t, []string{"metric", "ok", "days"}, mapKeys(m))
	assert.ElementsMatch(t, []string{"date", "expected", "observed", "ratio", "ok"}, mapKeys(m["days"].([]interface{})[0].(map[string]interface{})))
}

func TestUxSamplesWithStubbedLoki(t *testing.T) {
	token := uxSupportToken(t, "uxsamples")
	uxClean(t, "2031-04-01", "2031-04-01")
	db := database.DBConn

	require.NoError(t, db.Exec("INSERT INTO ux_sample_sessions (`date`,flow,step,`signal`,session_id) VALUES "+
		"('2031-04-01','give','A','flow_abandon','s-1'),('2031-04-01','give','A','flow_abandon','s-2'),"+
		"('2031-04-01','give','A','flow_abandon','bad id\" | json'),('2031-04-01','give','B','flow_abandon','other-step'),"+
		"('2031-04-01','ask','A','flow_abandon','other-flow')").Error)

	var mu sync.Mutex
	var asked []string
	restore := ux.SetSessionFetcher(func(sessionID string, date time.Time) ([]ux.SampleEvent, error) {
		mu.Lock()
		asked = append(asked, sessionID)
		mu.Unlock()
		assert.Equal(t, "2031-04-01", date.Format("2006-01-02"))
		if sessionID == "s-2" {
			return nil, errors.New("loki is having a day")
		}
		d := int64(1234)
		return []ux.SampleEvent{{Ts: "2031-04-01T10:00:00Z", EventType: "flow", ActionName: "step_enter", Step: "A", DwellMs: &d}}, nil
	})
	defer restore()

	var out ux.SamplesResponse
	require.Equal(t, 200, uxGet(t, token, "samples?date=2031-04-01&flow=give&step=A", &out))
	assert.Equal(t, "2031-04-01", out.Date)
	assert.Equal(t, "give", out.Flow)
	assert.Equal(t, "A", out.Step)
	require.Len(t, out.Sessions, 3)
	assert.Equal(t, "s-1", out.Sessions[0].SessionID)
	require.Len(t, out.Sessions[0].Events, 1)
	assert.Equal(t, "step_enter", out.Sessions[0].Events[0].ActionName)
	assert.Equal(t, "s-2", out.Sessions[1].SessionID)
	assert.NotNil(t, out.Sessions[1].Events)
	assert.Empty(t, out.Sessions[1].Events)
	assert.Contains(t, out.LokiError, "loki is having a day")
	assert.Empty(t, out.Sessions[2].Events, "a session id that is not plain characters is never sent to Loki")
	assert.ElementsMatch(t, []string{"s-1", "s-2"}, asked)

	var raw map[string]interface{}
	require.Equal(t, 200, uxGet(t, token, "samples?date=2031-04-01&flow=give&step=A", &raw))
	assert.Contains(t, raw, "loki_error")
	ev := raw["sessions"].([]interface{})[0].(map[string]interface{})["events"].([]interface{})[0].(map[string]interface{})
	assert.ElementsMatch(t, []string{"ts", "event_type", "action_name", "interaction_type", "step", "dwell_ms", "component", "page_route"}, mapKeys(ev))
}

func TestUxSamplesLokiDownDoesNotFail(t *testing.T) {
	token := uxSupportToken(t, "uxsamplesdown")
	uxClean(t, "2031-04-02", "2031-04-02")
	require.NoError(t, database.DBConn.Exec("INSERT INTO ux_sample_sessions (`date`,flow,step,`signal`,session_id) VALUES ('2031-04-02','give','A','flow_abandon','s-9')").Error)

	t.Setenv("LOKI_URL", "http://127.0.0.1:1") // nothing listens
	var out ux.SamplesResponse
	require.Equal(t, 200, uxGet(t, token, "samples?date=2031-04-02&flow=give&step=A", &out))
	require.Len(t, out.Sessions, 1)
	assert.Empty(t, out.Sessions[0].Events)
	assert.NotEmpty(t, out.LokiError)

	// No sessions sampled for the step: empty list, no error.
	var none ux.SamplesResponse
	require.Equal(t, 200, uxGet(t, token, "samples?date=2031-04-02&flow=give&step=Z", &none))
	assert.NotNil(t, none.Sessions)
	assert.Empty(t, none.Sessions)
	assert.Empty(t, none.LokiError)

	// Not configured at all behaves the same way.
	ux.ResetCache()
	t.Setenv("LOKI_URL", "")
	var unset ux.SamplesResponse
	require.Equal(t, 200, uxGet(t, token, "samples?date=2031-04-02&flow=give&step=A", &unset))
	assert.NotEmpty(t, unset.LokiError)
}

// The real fetcher: asks Loki for flow, interaction and page_view lines of one session over the
// day either side, and keeps only the whitelisted fields of each.
func TestUxSamplesRealFetcherKeepsOnlyWhitelistedFields(t *testing.T) {
	token := uxSupportToken(t, "uxsamplesreal")
	uxClean(t, "2031-04-03", "2031-04-03")
	require.NoError(t, database.DBConn.Exec("INSERT INTO ux_sample_sessions (`date`,flow,step,`signal`,session_id) VALUES ('2031-04-03','give','A','flow_abandon','abc_DEF-1')").Error)

	var gotQuery, gotLimit, gotDirection, gotStart, gotEnd string
	loki := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		assert.Equal(t, "/loki/api/v1/query_range", r.URL.Path)
		gotQuery = r.URL.Query().Get("query")
		gotLimit = r.URL.Query().Get("limit")
		gotDirection = r.URL.Query().Get("direction")
		gotStart = r.URL.Query().Get("start")
		gotEnd = r.URL.Query().Get("end")
		// Two streams, deliberately out of time order across them.
		fmt.Fprint(w, `{"status":"success","data":{"resultType":"streams","result":[
		 {"stream":{"event_type":"interaction"},"values":[
		   ["1964131200000000000","{\"event_type\":\"interaction\",\"action_name\":\"click: Sofa LS6\",\"interaction_type\":\"click\",\"label\":\"Sofa LS6\",\"component\":\"GiveNext\",\"page_route\":\"give-mobile-photos\",\"url\":\"https://x/secret?email=a@b.c\",\"user_agent\":\"UA\",\"message\":\"typed text\",\"user_id\":5,\"client_ip\":\"1.2.3.4\"}"]]},
		 {"stream":{"event_type":"flow"},"values":[
		   ["1964131100000000000","{\"event_type\":\"flow\",\"action_name\":\"step_enter\",\"step\":\"A\",\"flow\":\"give\",\"dwell_ms\":250.4,\"message\":\"hello\",\"url\":\"u\"}"],
		   ["1964131300000000000","not json at all"]]}
		]}}`)
	}))
	defer loki.Close()
	t.Setenv("LOKI_URL", loki.URL)

	var raw map[string]interface{}
	require.Equal(t, 200, uxGet(t, token, "samples?date=2031-04-03&flow=give&step=A", &raw))
	assert.NotContains(t, raw, "loki_error")

	assert.Contains(t, gotQuery, `source="client"`)
	assert.Contains(t, gotQuery, `session_id="abc_DEF-1"`)
	assert.Contains(t, gotQuery, "flow|interaction|page_view")
	assert.Contains(t, gotQuery, `|= "abc_DEF-1" | json`, "line filter before the json parser")
	assert.NotContains(t, gotQuery, "| json |", "json extracts only the returned fields")
	assert.Equal(t, "200", gotLimit)
	assert.Equal(t, "forward", gotDirection)
	day := time.Date(2031, 4, 3, 0, 0, 0, 0, time.UTC)
	assert.Equal(t, fmt.Sprint(day.AddDate(0, 0, -1).UnixNano()), gotStart)
	assert.Equal(t, fmt.Sprint(day.AddDate(0, 0, 2).UnixNano()), gotEnd)

	events := raw["sessions"].([]interface{})[0].(map[string]interface{})["events"].([]interface{})
	require.Len(t, events, 2, "the unparseable line is skipped")
	first := events[0].(map[string]interface{})
	second := events[1].(map[string]interface{})
	assert.Equal(t, "step_enter", first["action_name"], "ordered by time across streams")
	assert.EqualValues(t, 250, first["dwell_ms"])
	assert.Equal(t, "click", second["interaction_type"])
	assert.Equal(t, "", second["action_name"], "interaction lines carry the type, not the action text")
	assert.Equal(t, "GiveNext", second["component"])
	assert.Equal(t, "give-mobile-photos", second["page_route"])
	assert.Nil(t, second["dwell_ms"])
	for _, e := range events {
		assert.ElementsMatch(t, []string{"ts", "event_type", "action_name", "interaction_type", "step", "dwell_ms", "component", "page_route"}, mapKeys(e.(map[string]interface{})))
	}
	body, _ := json.Marshal(raw)
	for _, leaked := range []string{"secret", "typed text", "hello", "1.2.3.4", "UA", "Sofa LS6"} {
		assert.NotContains(t, string(body), leaked)
	}
}

func TestUxSamplesLokiErrorStatus(t *testing.T) {
	token := uxSupportToken(t, "uxsamples500")
	uxClean(t, "2031-04-04", "2031-04-04")
	require.NoError(t, database.DBConn.Exec("INSERT INTO ux_sample_sessions (`date`,flow,step,`signal`,session_id) VALUES ('2031-04-04','give','A','flow_abandon','s-1')").Error)

	for name, handler := range map[string]http.HandlerFunc{
		"500":         func(w http.ResponseWriter, r *http.Request) { http.Error(w, "boom", 500) },
		"not json":    func(w http.ResponseWriter, r *http.Request) { fmt.Fprint(w, "<html>") },
		"not success": func(w http.ResponseWriter, r *http.Request) { fmt.Fprint(w, `{"status":"error"}`) },
	} {
		t.Run(name, func(t *testing.T) {
			ux.ResetCache()
			loki := httptest.NewServer(handler)
			defer loki.Close()
			t.Setenv("LOKI_URL", loki.URL)
			var out ux.SamplesResponse
			require.Equal(t, 200, uxGet(t, token, "samples?date=2031-04-04&flow=give&step=A", &out))
			assert.NotEmpty(t, out.LokiError)
			assert.Empty(t, out.Sessions[0].Events)
		})
	}
}
