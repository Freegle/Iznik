package test

import (
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ux"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// The device and signed_in filters on the UX read API. Each test seeds its own month of 2041, which
// no other UX test uses, and removes the rows afterwards. In every month the week from the 8th to
// the 14th is the period asked for and the 1st to the 7th is the period before it.

var uxFilterEndpoints = []string{"funnels", "outcomes", "fields", "friction", "adoption", "regret", "search", "health"}

// uxFilterBody calls an endpoint and returns the status and the raw body, for answers that are not
// the success shape.
func uxFilterBody(t *testing.T, token, path string) (int, string) {
	t.Helper()
	resp, err := getApp().Test(httptest.NewRequest("GET", "/api/modtools/ux/"+path+"&jwt="+token, nil), 30000)
	require.NoError(t, err)
	return resp.StatusCode, string(rsp(resp))
}

// uxFilterFlowRow inserts one ux_flow_daily row for the flow "give", step "A".
func uxFilterFlowRow(t *testing.T, date, device string, loggedIn, entered, completed, abandoned int) {
	t.Helper()
	isApp := 0
	if strings.HasPrefix(device, "app-") {
		isApp = 1
	}
	require.NoError(t, database.DBConn.Exec("INSERT INTO ux_flow_daily (`date`,flow,step,device_class,logged_in,is_app,variant,entered,completed,abandoned) VALUES (?,?,?,?,?,?,?,?,?,?)",
		date, "give", "A", device, loggedIn, isApp, "", entered, completed, abandoned).Error)
}

// uxFilterSeedFunnels writes every kind of device and both sign-in states for one step. The
// current week holds 532 starts in all: 500 on the web (350 of them on a phone), 25 in the apps, 7
// with no device recorded, and 210 signed in. The week before holds 290.
func uxFilterSeedFunnels(t *testing.T, month string) {
	t.Helper()
	cur, prev := month+"-08", month+"-02"
	uxFilterFlowRow(t, cur, "desktop", 1, 100, 60, 30)
	uxFilterFlowRow(t, cur, "desktop", 0, 10, 4, 5)
	uxFilterFlowRow(t, cur, "mobile", 0, 300, 150, 100)
	uxFilterFlowRow(t, cur, "mobile", 1, 50, 30, 10)
	uxFilterFlowRow(t, cur, "tablet", 1, 40, 20, 10)
	uxFilterFlowRow(t, cur, "app-ios", 1, 20, 10, 5)
	uxFilterFlowRow(t, cur, "app-android", 0, 5, 2, 1)
	uxFilterFlowRow(t, cur, "", 0, 7, 3, 1)

	uxFilterFlowRow(t, prev, "desktop", 1, 80, 40, 20)
	uxFilterFlowRow(t, prev, "mobile", 0, 200, 100, 50)
	uxFilterFlowRow(t, prev, "app-ios", 1, 10, 5, 2)
}

// uxFilterStepA is the one step the funnels tests read.
func uxFilterStepA(t *testing.T, flows []ux.FunnelFlow) ux.FunnelStep {
	t.Helper()
	require.Len(t, flows, 1)
	require.Len(t, flows[0].Steps, 1)
	return flows[0].Steps[0]
}

func TestUxFilterRejectsABadValue(t *testing.T) {
	token := uxSupportToken(t, "uxfbad")
	ux.ResetCache()

	for _, ep := range uxFilterEndpoints {
		for _, q := range []string{
			"device=phone", "device=Mobile", "device=web,app", "device=mobile%27%20OR%201%3D1", "device=%27",
			"device=" + strings.Repeat("a", 300),
			"signed_in=2", "signed_in=yes", "signed_in=true", "signed_in=-1", "signed_in=1%20OR%201%3D1",
		} {
			assert.Equal(t, 400, uxGet(t, token, ep+"?"+q, nil), ep+"?"+q)
		}
	}

	status, body := uxFilterBody(t, token, "funnels?device=phone")
	assert.Equal(t, 400, status)
	assert.Contains(t, body, "device must be web, app, mobile, tablet, desktop, app-ios or app-android")
	status, body = uxFilterBody(t, token, "funnels?signed_in=2")
	assert.Equal(t, 400, status)
	assert.Contains(t, body, "signed_in must be 1 or 0")

	// Every good value is accepted, and an empty one means the filter was not asked for.
	for _, q := range []string{"device=web", "device=app", "device=mobile", "device=tablet", "device=desktop",
		"device=app-ios", "device=app-android", "device=", "signed_in=1", "signed_in=0", "signed_in="} {
		assert.Equal(t, 200, uxGet(t, token, "search?start=2041-01-08&end=2041-01-14&"+q, nil), q)
	}
}

func TestUxFunnelsFilterByDevice(t *testing.T) {
	token := uxSupportToken(t, "uxfdev")
	uxClean(t, "2041-02-01", "2041-02-14")
	uxFilterSeedFunnels(t, "2041-02")

	var all ux.FunnelsResponse
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-02-08&end=2041-02-14", &all))
	a := uxFilterStepA(t, all.Flows)
	assert.EqualValues(t, 532, a.Entered, "no device asked: every row counts, including the one with no device recorded")
	require.NotNil(t, a.By)
	assert.Len(t, a.By.DeviceClass, 6, "desktop, mobile, tablet, app-ios, app-android and the empty one")
	assert.EqualValues(t, 290, uxFilterStepA(t, all.Previous.Flows).Entered)

	var out ux.FunnelsResponse
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-02-08&end=2041-02-14&device=mobile", &out))
	a = uxFilterStepA(t, out.Flows)
	assert.EqualValues(t, 350, a.Entered, "both sign-in states of the mobile rows")
	assert.EqualValues(t, 180, a.Completed)
	assert.EqualValues(t, 110, a.Abandoned)
	require.NotNil(t, a.By)
	require.Len(t, a.By.DeviceClass, 1, "a device filter leaves one row in the device breakdown")
	assert.Equal(t, "mobile", a.By.DeviceClass[0].Key)
	assert.Len(t, a.By.LoggedIn, 2, "the other breakdowns are cut by the same filter")

	assert.EqualValues(t, 200, uxFilterStepA(t, out.Previous.Flows).Entered, "the period before is cut too")

	assert.Equal(t, "mobile", out.Filters.Device)
	assert.Equal(t, "", out.Filters.SignedIn)
	assert.NotNil(t, out.Ignored)
	assert.Empty(t, out.Ignored)
}

func TestUxFunnelsFilterByWebAndApp(t *testing.T) {
	token := uxSupportToken(t, "uxfweb")
	uxClean(t, "2041-03-01", "2041-03-14")
	uxFilterSeedFunnels(t, "2041-03")

	var web ux.FunnelsResponse
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-03-08&end=2041-03-14&device=web", &web))
	a := uxFilterStepA(t, web.Flows)
	assert.EqualValues(t, 500, a.Entered, "web is desktop, mobile and tablet: no app, and no row with no device")
	keys := []string{}
	for _, b := range a.By.DeviceClass {
		keys = append(keys, b.Key)
	}
	assert.ElementsMatch(t, []string{"desktop", "mobile", "tablet"}, keys)
	assert.EqualValues(t, 280, uxFilterStepA(t, web.Previous.Flows).Entered)
	assert.Equal(t, "web", web.Filters.Device)

	var app ux.FunnelsResponse
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-03-08&end=2041-03-14&device=app", &app))
	a = uxFilterStepA(t, app.Flows)
	assert.EqualValues(t, 25, a.Entered, "app sums the two app classes only")
	keys = []string{}
	for _, b := range a.By.DeviceClass {
		keys = append(keys, b.Key)
	}
	assert.ElementsMatch(t, []string{"app-ios", "app-android"}, keys)
	assert.EqualValues(t, 10, uxFilterStepA(t, app.Previous.Flows).Entered)

	for device, want := range map[string]int64{"tablet": 40, "desktop": 110, "app-ios": 20, "app-android": 5} {
		var one ux.FunnelsResponse
		require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-03-08&end=2041-03-14&device="+device, &one), device)
		assert.EqualValues(t, want, uxFilterStepA(t, one.Flows).Entered, device)
	}
}

func TestUxFunnelsFilterBySignedIn(t *testing.T) {
	token := uxSupportToken(t, "uxfsign")
	uxClean(t, "2041-04-01", "2041-04-14")
	uxFilterSeedFunnels(t, "2041-04")

	var in ux.FunnelsResponse
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-04-08&end=2041-04-14&signed_in=1", &in))
	assert.EqualValues(t, 210, uxFilterStepA(t, in.Flows).Entered)
	assert.EqualValues(t, 90, uxFilterStepA(t, in.Previous.Flows).Entered, "the period before is cut too")
	assert.Equal(t, "1", in.Filters.SignedIn)
	assert.Equal(t, "", in.Filters.Device)
	assert.Empty(t, in.Ignored)
	for _, b := range uxFilterStepA(t, in.Flows).By.LoggedIn {
		assert.Equal(t, "true", b.Key, "the breakdown by sign-in holds only the signed-in rows")
	}

	var out ux.FunnelsResponse
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-04-08&end=2041-04-14&signed_in=0", &out))
	assert.EqualValues(t, 322, uxFilterStepA(t, out.Flows).Entered)
	assert.EqualValues(t, 200, uxFilterStepA(t, out.Previous.Flows).Entered)
	assert.Equal(t, "0", out.Filters.SignedIn)
}

func TestUxOutcomesIgnoreSignedIn(t *testing.T) {
	token := uxSupportToken(t, "uxfout")
	uxClean(t, "2041-05-01", "2041-05-14")
	require.NoError(t, database.DBConn.Exec("INSERT INTO ux_flow_outcome_daily (`date`,flow,device_class,logged_in,is_app,variant,started,submitted,submit_ok,submit_failed,completed,abandoned,resumed) VALUES "+
		"('2041-05-08','give','desktop',1,0,'',100,80,70,10,70,25,5),"+
		"('2041-05-08','give','mobile',1,0,'',300,200,150,50,160,120,15),"+
		"('2041-05-09','give','mobile',0,0,'',50,40,40,0,40,10,1),"+
		"('2041-05-03','give','mobile',0,0,'',20,10,10,0,10,5,0)").Error)

	var plain ux.OutcomesResponse
	require.Equal(t, 200, uxGet(t, token, "outcomes?start=2041-05-08&end=2041-05-14&device=mobile", &plain))
	require.Len(t, plain.Flows, 1)
	assert.EqualValues(t, 350, plain.Flows[0].Started)
	assert.Empty(t, plain.Ignored)

	var out ux.OutcomesResponse
	require.Equal(t, 200, uxGet(t, token, "outcomes?start=2041-05-08&end=2041-05-14&device=mobile&signed_in=1", &out))
	require.Len(t, out.Flows, 1)
	assert.EqualValues(t, 350, out.Flows[0].Started, "the counts are cut by device only: both sign-in states are in")
	assert.EqualValues(t, 240, out.Flows[0].Submitted)
	assert.EqualValues(t, 20, out.Previous.Flows[0].Started, "the period before is cut by device too")
	assert.Equal(t, "mobile", out.Filters.Device)
	assert.Equal(t, "", out.Filters.SignedIn, "signed_in was asked for and not used")
	assert.Equal(t, []string{"signed_in"}, out.Ignored)

	var everyone ux.OutcomesResponse
	require.Equal(t, 200, uxGet(t, token, "outcomes?start=2041-05-08&end=2041-05-14", &everyone))
	assert.EqualValues(t, 450, everyone.Flows[0].Started)
}

func TestUxFieldsAndFrictionHonourDeviceOnly(t *testing.T) {
	token := uxSupportToken(t, "uxffric")
	uxClean(t, "2041-06-01", "2041-06-14")
	db := database.DBConn

	require.NoError(t, db.Exec("INSERT INTO ux_field_daily (`date`,flow,step,field,device_class,revisits,validation_failures,choice_switches) VALUES "+
		"('2041-06-08','give','A','photo','desktop',5,2,1),"+
		"('2041-06-09','give','A','photo','mobile',1,0,3),"+
		"('2041-06-09','give','B','email','mobile',0,10,0),"+
		"('2041-06-09','give','B','email','desktop',4,4,0),"+
		"('2041-06-02','give','A','photo','mobile',2,0,0),"+
		"('2041-06-02','give','A','photo','desktop',9,0,0)").Error)

	var all ux.FieldsResponse
	require.Equal(t, 200, uxGet(t, token, "fields?start=2041-06-08&end=2041-06-14", &all))
	require.Len(t, all.Fields, 2)
	assert.EqualValues(t, 6, all.Fields[1].Revisits, "photo: desktop 5 plus mobile 1")

	var out ux.FieldsResponse
	require.Equal(t, 200, uxGet(t, token, "fields?start=2041-06-08&end=2041-06-14&device=mobile&signed_in=0", &out))
	require.Len(t, out.Fields, 2)
	assert.Equal(t, ux.FieldRow{Flow: "give", Step: "B", Field: "email", Revisits: 0, ValidationFailures: 10, ChoiceSwitches: 0}, out.Fields[0])
	assert.Equal(t, ux.FieldRow{Flow: "give", Step: "A", Field: "photo", Revisits: 1, ValidationFailures: 0, ChoiceSwitches: 3}, out.Fields[1])
	require.Len(t, out.Previous.Fields, 1)
	assert.EqualValues(t, 2, out.Previous.Fields[0].Revisits, "the period before is cut too")
	assert.Equal(t, "mobile", out.Filters.Device)
	assert.Equal(t, []string{"signed_in"}, out.Ignored)

	// Friction: three desktop components with a ratio of 1, one component on both devices, and 22
	// phone components with ratios of 0 to 0.21. Unfiltered, the top 20 holds the desktop ones.
	// Filtered to phones it is worked out again from the phone rows alone.
	ins := func(component, device string, clicks, dead int) {
		require.NoError(t, db.Exec("INSERT INTO ux_component_daily (`date`,page,component,device_class,impressions,clicks,dead_clicks) VALUES ('2041-06-08','p',?,?,?,?,?)",
			component, device, clicks, clicks, dead).Error)
	}
	for _, c := range []string{"d0", "d1", "d2"} {
		ins(c, "desktop", 100, 100)
	}
	ins("both", "desktop", 50, 50)
	ins("both", "mobile", 100, 30)
	for i := 0; i < 22; i++ {
		ins("m"+uxFilterTwoDigits(i), "mobile", 100, i)
	}

	var fric ux.FrictionResponse
	require.Equal(t, 200, uxGet(t, token, "friction?start=2041-06-08&end=2041-06-14", &fric))
	require.Len(t, fric.Components, 20)
	assert.Equal(t, []string{"d0", "d1", "d2", "both"}, uxFilterComponents(fric.Components[:4]))
	assert.EqualValues(t, 150, fric.Components[3].Clicks, "both devices are in")
	assert.NotContains(t, uxFilterComponents(fric.Components), "m03")

	var cut ux.FrictionResponse
	require.Equal(t, 200, uxGet(t, token, "friction?start=2041-06-08&end=2041-06-14&device=mobile&signed_in=1", &cut))
	require.Len(t, cut.Components, 20, "the top 20 is taken after the filter")
	names := uxFilterComponents(cut.Components)
	assert.Equal(t, "both", names[0])
	assert.EqualValues(t, 100, cut.Components[0].Clicks, "only the phone row of a component on both devices")
	assert.EqualValues(t, 30, cut.Components[0].DeadClicks)
	assert.Equal(t, "m21", names[1])
	assert.Contains(t, names, "m03", "pushed out of the unfiltered top 20, in once the desktop rows are gone")
	assert.NotContains(t, names, "m02")
	for _, n := range names {
		assert.False(t, strings.HasPrefix(n, "d"), n)
	}
	assert.Equal(t, "mobile", cut.Filters.Device)
	assert.Equal(t, []string{"signed_in"}, cut.Ignored)
}

func uxFilterTwoDigits(i int) string {
	return string(rune('0'+i/10)) + string(rune('0'+i%10))
}

func uxFilterComponents(rows []ux.FrictionRow) []string {
	out := []string{}
	for _, r := range rows {
		out = append(out, r.Component)
	}
	return out
}

func TestUxUnfilteredEndpointsIgnoreFilters(t *testing.T) {
	token := uxSupportToken(t, "uxfign")
	uxClean(t, "2041-07-01", "2041-07-14")
	db := database.DBConn
	require.NoError(t, db.Exec("INSERT INTO ux_feature_adoption_daily (`date`,feature,active_users,using_users,sessions_using) VALUES ('2041-07-08','chats',100,20,30)").Error)
	require.NoError(t, db.Exec("INSERT INTO ux_regret_daily (`date`,`signal`,`count`,base) VALUES ('2041-07-08','withdrawn_24h',3,100)").Error)
	require.NoError(t, db.Exec("INSERT INTO ux_search_daily (`date`,searches,zero_result,re_searched_60s,browse_sessions,browse_scrolled_out) VALUES ('2041-07-09',50,5,7,200,40)").Error)
	require.NoError(t, db.Exec("INSERT INTO ux_telemetry_health_daily (`date`,metric,expected,observed,ratio) VALUES ('2041-07-08','event:flow',100,95,0.950)").Error)

	asks := []struct {
		query   string
		ignored []string
	}{
		{"", []string{}},
		{"&device=mobile", []string{"device"}},
		{"&signed_in=1", []string{"signed_in"}},
		{"&device=mobile&signed_in=1", []string{"device", "signed_in"}},
	}
	for _, ep := range []string{"adoption", "regret", "search", "health"} {
		var base map[string]interface{}
		for i, ask := range asks {
			var raw map[string]interface{}
			require.Equal(t, 200, uxGet(t, token, ep+"?start=2041-07-08&end=2041-07-14"+ask.query, &raw), ep+ask.query)

			assert.Equal(t, map[string]interface{}{"device": "", "signed_in": ""}, raw["filters"], ep+ask.query+": nothing was applied")
			got := []string{}
			for _, v := range raw["ignored"].([]interface{}) {
				got = append(got, v.(string))
			}
			assert.Equal(t, ask.ignored, got, ep+ask.query+": ignored lists exactly what was asked")

			delete(raw, "filters")
			delete(raw, "ignored")
			if i == 0 {
				base = raw
				assert.NotEmpty(t, base, ep)
			} else {
				assert.Equal(t, base, raw, ep+ask.query+": the figures do not change")
			}
		}
	}
}

func TestUxFilterIsPartOfTheCacheKey(t *testing.T) {
	token := uxSupportToken(t, "uxfkey")
	uxClean(t, "2041-08-01", "2041-08-14")
	uxFilterSeedFunnels(t, "2041-08")

	entered := func(query string) int64 {
		var out ux.FunnelsResponse
		require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-08-08&end=2041-08-14"+query, &out), query)
		return uxFilterStepA(t, out.Flows).Entered
	}

	// Two calls that differ only by device, in one order and then in the other.
	assert.EqualValues(t, 110, entered("&device=desktop"))
	assert.EqualValues(t, 350, entered("&device=mobile"))
	ux.ResetCache()
	assert.EqualValues(t, 350, entered("&device=mobile"))
	assert.EqualValues(t, 110, entered("&device=desktop"))

	// Sign-in is part of the key too.
	ux.ResetCache()
	assert.EqualValues(t, 210, entered("&signed_in=1"))
	assert.EqualValues(t, 322, entered("&signed_in=0"))
	assert.EqualValues(t, 532, entered(""))

	// The same set of filters is served from the cache: a row added after the first call is not
	// seen until the cache is dropped.
	ux.ResetCache()
	assert.EqualValues(t, 350, entered("&device=mobile"))
	assert.EqualValues(t, 110, entered("&device=desktop"))
	uxFilterFlowRow(t, "2041-08-10", "mobile", 0, 1000, 0, 0)
	assert.EqualValues(t, 350, entered("&device=mobile"), "served from the 10 minute cache")
	assert.EqualValues(t, 110, entered("&device=desktop"))
	ux.ResetCache()
	assert.EqualValues(t, 1350, entered("&device=mobile"))
	assert.EqualValues(t, 110, entered("&device=desktop"))

	// An endpoint that ignores a filter still keeps its answers apart, or one caller's list of
	// ignored filters would reach the next.
	var withDevice, without ux.AdoptionResponse
	require.Equal(t, 200, uxGet(t, token, "adoption?start=2041-08-08&end=2041-08-14&device=mobile", &withDevice))
	require.Equal(t, 200, uxGet(t, token, "adoption?start=2041-08-08&end=2041-08-14", &without))
	assert.Equal(t, []string{"device"}, withDevice.Ignored)
	assert.Empty(t, without.Ignored)
}

func TestUxResponsesSayWhichFiltersWereApplied(t *testing.T) {
	token := uxSupportToken(t, "uxfecho")
	uxClean(t, "2041-09-01", "2041-09-14")

	// Nothing asked: every answer has the filters, all empty, and an array for ignored that is
	// empty and not null.
	for _, ep := range uxFilterEndpoints {
		var raw map[string]interface{}
		require.Equal(t, 200, uxGet(t, token, ep+"?start=2041-09-08&end=2041-09-14", &raw), ep)
		assert.Equal(t, map[string]interface{}{"device": "", "signed_in": ""}, raw["filters"], ep)
		ignored, ok := raw["ignored"].([]interface{})
		assert.True(t, ok, ep+": ignored is an array, not null")
		assert.Empty(t, ignored, ep)
	}

	// Both asked: each endpoint says what it used.
	type want struct {
		device, signedIn string
		ignored          []string
	}
	for ep, w := range map[string]want{
		"funnels":  {"mobile", "1", []string{}},
		"outcomes": {"mobile", "", []string{"signed_in"}},
		"fields":   {"mobile", "", []string{"signed_in"}},
		"friction": {"mobile", "", []string{"signed_in"}},
		"adoption": {"", "", []string{"device", "signed_in"}},
		"regret":   {"", "", []string{"device", "signed_in"}},
		"search":   {"", "", []string{"device", "signed_in"}},
		"health":   {"", "", []string{"device", "signed_in"}},
	} {
		var raw map[string]interface{}
		require.Equal(t, 200, uxGet(t, token, ep+"?start=2041-09-08&end=2041-09-14&device=mobile&signed_in=1", &raw), ep)
		assert.Equal(t, map[string]interface{}{"device": w.device, "signed_in": w.signedIn}, raw["filters"], ep)
		got := []string{}
		for _, v := range raw["ignored"].([]interface{}) {
			got = append(got, v.(string))
		}
		assert.Equal(t, w.ignored, got, ep)
	}

	// The sampled sessions are not filtered and do not say so.
	var samples map[string]interface{}
	require.Equal(t, 200, uxGet(t, token, "samples?date=2041-09-09&flow=give&step=A&device=mobile&signed_in=1", &samples))
	assert.NotContains(t, samples, "filters")
	assert.NotContains(t, samples, "ignored")
}

func TestUxFunnelsPreviousPeriodHasDates(t *testing.T) {
	token := uxSupportToken(t, "uxfprev")
	ux.ResetCache()

	for _, c := range []struct{ start, end, prevStart, prevEnd string }{
		{"2041-10-08", "2041-10-14", "2041-10-01", "2041-10-07"},
		{"2041-10-10", "2041-10-10", "2041-10-09", "2041-10-09"},
		{"2041-08-01", "2041-08-30", "2041-07-02", "2041-07-31"},
	} {
		var out ux.FunnelsResponse
		require.Equal(t, 200, uxGet(t, token, "funnels?start="+c.start+"&end="+c.end, &out), c.start)
		assert.Equal(t, c.start, out.Start)
		assert.Equal(t, c.end, out.End)
		assert.Equal(t, c.prevStart, out.Previous.Start, "the period before, from the server")
		assert.Equal(t, c.prevEnd, out.Previous.End)
		assert.NotNil(t, out.Previous.Flows, "[] not null")
	}

	var raw map[string]interface{}
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-10-08&end=2041-10-14", &raw))
	assert.ElementsMatch(t, []string{"start", "end", "flows"}, mapKeys(raw["previous"].(map[string]interface{})))
}

func TestUxDeviceAndSignedInCombine(t *testing.T) {
	token := uxSupportToken(t, "uxfboth")
	uxClean(t, "2041-11-01", "2041-11-14")
	uxFilterSeedFunnels(t, "2041-11")

	for _, c := range []struct {
		query string
		want  int64
	}{
		{"device=mobile&signed_in=1", 50},
		{"device=mobile&signed_in=0", 300},
		{"device=web&signed_in=1", 190},
		{"device=app&signed_in=0", 5},
		{"device=app&signed_in=1", 20},
	} {
		var out ux.FunnelsResponse
		require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-11-08&end=2041-11-14&"+c.query, &out), c.query)
		assert.EqualValues(t, c.want, uxFilterStepA(t, out.Flows).Entered, c.query)
	}

	// A clash is an empty answer, not an error: nobody on the iOS app was signed out.
	var none ux.FunnelsResponse
	require.Equal(t, 200, uxGet(t, token, "funnels?start=2041-11-08&end=2041-11-14&device=app-ios&signed_in=0", &none))
	assert.NotNil(t, none.Flows)
	assert.Empty(t, none.Flows)
	assert.Empty(t, none.Previous.Flows)
	assert.Equal(t, "app-ios", none.Filters.Device)
	assert.Equal(t, "0", none.Filters.SignedIn)
}

// The funnel code reads the rollup tables and nothing else. It names no table that belongs to
// another part of the UX work.
func TestUxFunnelsBoundary(t *testing.T) {
	files := []string{"../ux/ux.go", "../ux/filters.go", "../ux/rollups.go"}
	read := 0
	for _, f := range files {
		b, err := os.ReadFile(f)
		require.NoError(t, err, f)
		require.NotEmpty(t, b, f+" is empty")
		read++
		text := string(b)
		for _, name := range []string{"ux_experiments", "ux_experiment_reads", "ux_prompts", "ux_prompt_responses", "ux_problems", "ux_ideas"} {
			assert.NotContains(t, text, name, f)
		}
	}
	assert.Equal(t, 3, read, "all three files were read")
}
