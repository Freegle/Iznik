package ux

import (
	"encoding/json"
	"io"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// queryApp runs fn inside a real fiber context built from the given URL query.
func queryApp(t *testing.T, query string, fn func(c *fiber.Ctx) error) (int, string) {
	t.Helper()
	app := fiber.New()
	app.Get("/x", func(c *fiber.Ctx) error {
		err := fn(c)
		if err != nil {
			return err
		}
		return c.SendString("ok")
	})
	resp, err := app.Test(httptest.NewRequest("GET", "/x?"+query, nil))
	require.NoError(t, err)
	b, _ := io.ReadAll(resp.Body)
	return resp.StatusCode, string(b)
}

func TestBoostEnvInt(t *testing.T) {
	cases := []struct {
		name, val string
		set       bool
		def, want int
	}{
		{"unset", "", false, 7, 7},
		{"valid", "12", true, 7, 12},
		{"spaces", "  5 ", true, 7, 5},
		{"zero", "0", true, 7, 7},
		{"negative", "-3", true, 7, 7},
		{"garbage", "abc", true, 7, 7},
		{"empty", "", true, 9, 9},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if tc.set {
				t.Setenv("UX_BOOST_ENVINT", tc.val)
			}
			assert.Equal(t, tc.want, envInt("UX_BOOST_ENVINT", tc.def))
		})
	}
}

func TestBoostValidArms(t *testing.T) {
	def, msg := validArms(nil)
	assert.Equal(t, "", msg)
	assert.Contains(t, def, "control")

	cases := []struct {
		name string
		arms map[string][]int
		err  string
	}{
		{"valid", map[string][]int{"control": {0, 49}, "b": {50, 99}}, ""},
		{"too many", map[string][]int{"control": {0, 9}, "a": {10, 19}, "b": {20, 29}, "c": {30, 39}, "d": {40, 49}, "e": {50, 59}}, "at most"},
		{"bad name", map[string][]int{"control": {0, 49}, "b-x": {50, 99}}, "letters, digits"},
		{"bad length", map[string][]int{"control": {0, 49}, "b": {50}}, "exactly two"},
		{"negative", map[string][]int{"control": {-1, 49}, "b": {50, 99}}, "within 0 to 99"},
		{"over 99", map[string][]int{"control": {0, 49}, "b": {50, 100}}, "within 0 to 99"},
		{"reversed", map[string][]int{"control": {0, 49}, "b": {70, 60}}, "within 0 to 99"},
		{"overlap", map[string][]int{"control": {0, 49}, "b": {40, 99}}, "overlaps"},
		{"no control", map[string][]int{"a": {0, 49}, "b": {50, 99}}, "include control"},
		{"no variant", map[string][]int{"control": {0, 99}}, "variant"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			j, m := validArms(tc.arms)
			if tc.err == "" {
				assert.Equal(t, "", m)
				var back map[string][]int
				require.NoError(t, json.Unmarshal([]byte(j), &back))
				assert.Equal(t, tc.arms, back)
			} else {
				assert.Equal(t, "", j)
				assert.Contains(t, m, tc.err)
			}
		})
	}
}

func TestBoostValidDesign(t *testing.T) {
	f := func(v float64) *float64 { return &v }
	cases := []struct {
		name string
		d    *CreateExperimentDesign
		json string
		err  string
	}{
		{"nil", nil, "", ""},
		{"empty", &CreateExperimentDesign{}, "", ""},
		{"touches dedup", &CreateExperimentDesign{Touches: []string{"give", "ask", "give"}}, `{"touches":["give","ask"]}`, ""},
		{"unknown touch", &CreateExperimentDesign{Touches: []string{"nonsense"}}, "", "touches must be flows"},
		{"points ok", &CreateExperimentDesign{ExpectedPoints: f(2)}, `{"expected_points":2}`, ""},
		{"points low", &CreateExperimentDesign{ExpectedPoints: f(0.01)}, "", "expected_points"},
		{"points high", &CreateExperimentDesign{ExpectedPoints: f(51)}, "", "expected_points"},
		{"event ok", &CreateExperimentDesign{PrimaryEvent: "user_rated"}, `{"primary_event":"user_rated"}`, ""},
		{"event bad", &CreateExperimentDesign{PrimaryEvent: "evil}"}, "", "primary_event"},
		{"rate ok", &CreateExperimentDesign{BaseRate: f(0.5)}, `{"base_rate":0.5}`, ""},
		{"rate zero", &CreateExperimentDesign{BaseRate: f(0)}, "", "base_rate"},
		{"rate one", &CreateExperimentDesign{BaseRate: f(1)}, "", "base_rate"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			j, m := validDesign(tc.d)
			if tc.err != "" {
				assert.Contains(t, m, tc.err)
				assert.Equal(t, "", j)
				return
			}
			assert.Equal(t, "", m)
			if tc.json == "" {
				assert.Equal(t, "", j)
			} else {
				assert.JSONEq(t, tc.json, j)
			}
		})
	}
}

func TestBoostIsHTTPS(t *testing.T) {
	assert.True(t, isHTTPS("https://example.org"))
	assert.False(t, isHTTPS("http://example.org"))
	assert.False(t, isHTTPS(" https://example.org"))
	assert.False(t, isHTTPS(""))
}

func TestBoostParseFilter(t *testing.T) {
	cases := []struct {
		query  string
		status int
		device string
		signed string
	}{
		{"", 200, "", ""},
		{"device=web", 200, "web", ""},
		{"device=app-ios&signed_in=1", 200, "app-ios", "1"},
		{"signed_in=0", 200, "", "0"},
		{"device=toaster", 400, "", ""},
		{"signed_in=2", 400, "", ""},
	}
	for _, tc := range cases {
		t.Run(tc.query, func(t *testing.T) {
			var got flowFilter
			status, _ := queryApp(t, tc.query, func(c *fiber.Ctx) error {
				var err error
				got, err = parseFilter(c)
				return err
			})
			assert.Equal(t, tc.status, status)
			if tc.status == 200 {
				assert.Equal(t, tc.device, got.device)
				assert.Equal(t, tc.signed, got.signedIn)
			}
		})
	}
}

func TestBoostFilterApplied(t *testing.T) {
	cases := []struct {
		name    string
		f       flowFilter
		table   string
		echo    FilterEcho
		ignored []string
	}{
		{"nothing asked", flowFilter{}, "ux_flow_daily", FilterEcho{}, []string{}},
		{"both honoured", flowFilter{device: "web", signedIn: "1"}, "ux_flow_daily", FilterEcho{"web", "1"}, []string{}},
		{"signed ignored", flowFilter{device: "app", signedIn: "0"}, "ux_flow_outcome_daily", FilterEcho{Device: "app"}, []string{"signed_in"}},
		{"unlisted table", flowFilter{device: "app", signedIn: "0"}, "no_such_table", FilterEcho{}, []string{"device", "signed_in"}},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			a := tc.f.applied(tc.table)
			assert.Equal(t, tc.echo, a.Filters)
			assert.Equal(t, tc.ignored, a.Ignored)
		})
	}
}

func TestBoostSignalsAndFriction(t *testing.T) {
	assert.Equal(t, "a\x00b\x00c\x00d", signalKey("a", "b", "c", "d"))
	s := signals{signalKey("f", "s", "", signalReached): Then{}}
	assert.NotNil(t, s.get("f", "s", "", signalReached))
	assert.Nil(t, s.get("f", "s", "", signalHelp))

	assert.Equal(t, 0.5, frictionRatio(frictionDB{Clicks: 10, RageClicks: 2, DeadClicks: 2, RepeatedIntent: 1}))
	assert.Equal(t, 3.0, frictionRatio(frictionDB{Clicks: 0, RageClicks: 3}))
	assert.Equal(t, 0.0, frictionRatio(frictionDB{}))

	assert.Equal(t, 0.0, adoptionDB{}.share())
	assert.Equal(t, 0.0, adoptionDB{ActiveUsers: -1, UsingUsers: 5}.share())
	assert.Equal(t, 0.25, adoptionDB{ActiveUsers: 8, UsingUsers: 2}.share())
}

func TestBoostSurveyHelpers(t *testing.T) {
	assert.Equal(t, `a\%b\_c\\d`, escapeLike(`a%b_c\d`))
	assert.Equal(t, "plain", escapeLike("plain"))

	other := "  "
	named := "Chats"
	assert.Equal(t, otherGroup, groupOf(nil))
	assert.Equal(t, otherGroup, groupOf(&other))
	assert.Equal(t, "Chats", groupOf(&named))

	id := uint64(4)
	assert.Equal(t, int64(0), answerBase(nil, map[uint64]int64{4: 9}))
	assert.Equal(t, int64(9), answerBase(&id, map[uint64]int64{4: 9}))
	assert.Equal(t, int64(0), answerBase(&id, map[uint64]int64{}))

	empty := ""
	bad := "{not json"
	good := `{"quotes":[{"text":"hi"}],"ranking":{"most":3,"times_shown":8}}`
	assert.NotNil(t, parseProblemEvidence(nil).Quotes)
	assert.Empty(t, parseProblemEvidence(&empty).Quotes)
	assert.Empty(t, parseProblemEvidence(&bad).Quotes)
	ev := parseProblemEvidence(&good)
	assert.Len(t, ev.Quotes, 1)
	require.NotNil(t, ev.Ranking)
	assert.Equal(t, int64(3), ev.Ranking.Most)
	assert.Equal(t, int64(8), ev.Ranking.TimesShown)
}

func TestBoostParseProblemFilterAndLimit(t *testing.T) {
	long := strings.Repeat("x", 200)
	var f problemFilter
	status, _ := queryApp(t, "flow=give&group=Chats&q="+long+"&min=3", func(c *fiber.Ctx) error {
		var err error
		f, err = parseProblemFilter(c)
		return err
	})
	assert.Equal(t, 200, status)
	assert.Equal(t, "give", f.flow)
	assert.Equal(t, "Chats", f.group)
	assert.Equal(t, int64(3), f.min)
	assert.LessOrEqual(t, len(f.q), maxSearchChars)

	for _, q := range []string{"flow=bad%20flow!", "min=abc", "min=-1"} {
		status, _ = queryApp(t, q, func(c *fiber.Ctx) error {
			_, err := parseProblemFilter(c)
			return err
		})
		assert.Equal(t, 400, status, q)
	}

	limits := []struct {
		q      string
		status int
		want   int
	}{
		{"", 200, defaultProblemLimit},
		{"limit=5", 200, 5},
		{"limit=100000", 200, maxProblemLimit},
		{"limit=0", 400, 0},
		{"limit=x", 400, 0},
	}
	for _, tc := range limits {
		var got int
		status, _ := queryApp(t, tc.q, func(c *fiber.Ctx) error {
			var err error
			got, err = parseProblemLimit(c)
			return err
		})
		assert.Equal(t, tc.status, status, tc.q)
		if tc.status == 200 {
			assert.Equal(t, tc.want, got, tc.q)
		}
	}
}

func TestBoostEventFromLine(t *testing.T) {
	inter := eventFromLine(1_000_000_000, map[string]interface{}{
		"event_type": "interaction", "interaction_type": "click", "action_name": "ignored",
		"step": "s", "component": "c", "page_route": "/r", "dwell_ms": 12.6, "secret": "x",
	}, nil)
	assert.Equal(t, "interaction", inter.EventType)
	assert.Equal(t, "click", inter.InteractionType)
	assert.Equal(t, "", inter.ActionName)
	assert.Equal(t, "1970-01-01T00:00:01Z", inter.Ts)
	require.NotNil(t, inter.DwellMs)
	assert.Equal(t, int64(13), *inter.DwellMs)

	act := eventFromLine(0, map[string]interface{}{"event_type": "flow", "action_name": "go", "dwell_ms": -1.0}, nil)
	assert.Equal(t, "go", act.ActionName)
	assert.Equal(t, "", act.InteractionType)
	assert.Nil(t, act.DwellMs)

	fromLabel := eventFromLine(0, map[string]interface{}{"step": 5}, map[string]string{"event_type": strings.Repeat("z", 300)})
	assert.Len(t, fromLabel.EventType, maxEventText)
	assert.Equal(t, "", fromLabel.Step)
}

func TestBoostParseServerArmEntries(t *testing.T) {
	assert.Empty(t, parseServerArmEntries("not json"))
	assert.Empty(t, parseServerArmEntries("[]"))
	assert.True(t, isJSONNull([]byte(" null ")))
	assert.False(t, isJSONNull([]byte("0")))

	raw := `{
	 "good": {"arms": {"control":[0,49],"b":[50,99]}, "salt":"abc", "until": 123.5, "adopted": true},
	 "plain": {"arms": {"control":[0,49],"b":[50,99]}, "salt": null, "until": null},
	 "badname!": {"arms": {"control":[0,99]}},
	 "__proto__": {"arms": {"control":[0,99]}},
	 "noarms": {"salt":"x"},
	 "notobj": 5,
	 "nullobj": null,
	 "badsalt": {"arms": {"control":[0,99]}, "salt": "way too long a salt!!"},
	 "saltnum": {"arms": {"control":[0,99]}, "salt": 3},
	 "badarms": {"arms": []},
	 "baduntil": {"arms": {"control":[0,99]}, "until": "soon"}
	}`
	out := parseServerArmEntries(raw)

	require.Contains(t, out, "good")
	assert.Equal(t, "abc", out["good"].salt)
	assert.True(t, out["good"].adopted)
	require.NotNil(t, out["good"].until)
	assert.Equal(t, 123.5, *out["good"].until)
	assert.Len(t, out["good"].arms, 2)

	require.Contains(t, out, "plain")
	assert.Nil(t, out["plain"].until)
	assert.False(t, out["plain"].adopted)

	require.Contains(t, out, "baduntil")
	assert.Nil(t, out["baduntil"].until)

	for _, k := range []string{"badname!", "__proto__", "noarms", "notobj", "nullobj", "badsalt", "saltnum", "badarms"} {
		assert.NotContains(t, out, k)
	}
}
