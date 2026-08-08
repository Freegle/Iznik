package session

import (
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// ---------------------------------------------------------------------------
// TopicActiveWithin — pure, no DB/HTTP needed.
// ---------------------------------------------------------------------------

func TestTopicActiveWithin(t *testing.T) {
	since := time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)

	cases := []struct {
		name       string
		createdAt  string
		bumpedAt   string
		lastPosted string
		since      time.Time
		want       bool
	}{
		{"bumpedAt after since wins", "2025-01-01T00:00:00Z", "2026-02-01T00:00:00Z", "2025-06-01T00:00:00Z", since, true},
		{"bumpedAt before since, falls back to lastPosted after since", "2025-01-01T00:00:00Z", "2025-06-01T00:00:00Z", "2026-02-01T00:00:00Z", since, false},
		{"bumpedAt missing, lastPosted after since wins", "2025-01-01T00:00:00Z", "", "2026-02-01T00:00:00Z", since, true},
		{"bumpedAt and lastPosted missing, createdAt after since wins", "2026-02-01T00:00:00Z", "", "", since, true},
		{"all missing", "", "", "", since, false},
		{"all malformed", "not-a-date", "also-not", "nope", since, false},
		{"RFC3339 with millis format", "2025-01-01T00:00:00Z", "2026-02-01T00:00:00.000Z", "", since, true},
		{"exactly equal to since is not after", "2026-01-01T00:00:00Z", "", "", since, false},
		{"bumpedAt malformed falls back to lastPosted", "2025-01-01T00:00:00Z", "garbage", "2026-02-01T00:00:00Z", since, true},
		{"bumpedAt malformed, lastPosted malformed, createdAt valid and after", "2026-03-01T00:00:00Z", "garbage", "garbage2", since, true},
		{"bumpedAt malformed, lastPosted malformed, createdAt valid but before", "2025-03-01T00:00:00Z", "garbage", "garbage2", since, false},
		{"all before since", "2024-01-01T00:00:00Z", "2024-06-01T00:00:00Z", "2024-09-01T00:00:00Z", since, false},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := TopicActiveWithin(c.createdAt, c.bumpedAt, c.lastPosted, c.since)
			assert.Equal(t, c.want, got, c.name)
		})
	}
}

// ---------------------------------------------------------------------------
// FetchEmailHealth — the out-of-window guard returns before touching db, so
// it is safe to call with a nil *gorm.DB for those branches.
// ---------------------------------------------------------------------------

func TestFetchEmailHealth_OutOfWindowNeverTouchesDB(t *testing.T) {
	cases := []struct {
		name string
		hour int
	}{
		{"midnight", 0},
		{"just before window opens", 6},
		{"exactly window close boundary is excluded", 22},
		{"late evening", 23},
		{"negative hour", -1},
		{"hour beyond 24", 25},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			// A nil db would panic if the function tried to use it - reaching
			// here without panicking proves the guard short-circuited.
			in, out := FetchEmailHealth(nil, c.hour)
			assert.Equal(t, int64(0), in)
			assert.Equal(t, int64(0), out)
		})
	}
}

// ---------------------------------------------------------------------------
// fetchDiscourseStats — returns nil immediately when Discourse env vars are
// unset, without making any network call.
// ---------------------------------------------------------------------------

func TestFetchDiscourseStats_NotConfiguredReturnsNil(t *testing.T) {
	cases := []struct {
		name string
		api  string
		key  string
	}{
		{"both unset", "", ""},
		{"api set, key unset", "https://discourse.example.com", ""},
		{"api unset, key set", "", "some-key"},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			t.Setenv("DISCOURSE_API", c.api)
			t.Setenv("DISCOURSE_APIKEY", c.key)
			got := fetchDiscourseStats(12345)
			assert.Nil(t, got)
		})
	}
}

// ---------------------------------------------------------------------------
// FlexBool — accepts JSON booleans, integers (0/1), and strings.
// ---------------------------------------------------------------------------

func TestFlexBool_UnmarshalJSON(t *testing.T) {
	cases := []struct {
		name string
		data string
		want bool
	}{
		{"json true", `true`, true},
		{"json false", `false`, false},
		{"numeric 1", `1`, true},
		{"numeric 0", `0`, false},
		{"quoted true", `"true"`, true},
		{"quoted false", `"false"`, false},
		{"quoted 1", `"1"`, true},
		{"quoted 0", `"0"`, false},
		{"uppercase TRUE", `"TRUE"`, true},
		{"mixed case True", `"True"`, true},
		{"empty string", `""`, false},
		{"garbage string", `"banana"`, false},
		{"numeric 2 is not true", `2`, false},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			var f FlexBool
			err := f.UnmarshalJSON([]byte(c.data))
			assert.NoError(t, err)
			assert.Equal(t, c.want, bool(f))
			assert.Equal(t, c.want, f.Bool())
		})
	}
}

func TestFlexBool_Bool(t *testing.T) {
	var t1 FlexBool = true
	var f1 FlexBool = false
	assert.True(t, t1.Bool())
	assert.False(t, f1.Bool())
}

// ---------------------------------------------------------------------------
// buildPatchSessionUpdateSet — pure clause.Set builder, no DB round-trip.
// ---------------------------------------------------------------------------

func setToMap(set clause.Set) map[string]interface{} {
	m := make(map[string]interface{}, len(set))
	for _, a := range set {
		m[a.Column.Name] = a.Value
	}
	return m
}

func strp(s string) *string { return &s }
func intp(i int) *int       { return &i }
func u64p(u uint64) *uint64 { return &u }

func TestBuildPatchSessionUpdateSet(t *testing.T) {
	t.Run("nothing set produces empty set", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(nil, nil, nil, nil, nil, nil, nil, nil, nil, false, nil)
		assert.Empty(t, set)
	})

	t.Run("displayname alone clears first and last name", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(strp("Jo Bloggs"), nil, nil, nil, nil, nil, nil, nil, nil, false, nil)
		m := setToMap(set)
		assert.Equal(t, "Jo Bloggs", m["fullname"])
		// firstname/lastname should be present and set to the NULL SQL expression.
		assert.Equal(t, gorm.Expr("NULL"), m["firstname"])
		assert.Equal(t, gorm.Expr("NULL"), m["lastname"])
	})

	t.Run("displayname with explicit firstname keeps firstname, clears lastname", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(strp("Jo Bloggs"), strp("Jo"), nil, nil, nil, nil, nil, nil, nil, false, nil)
		m := setToMap(set)
		assert.Equal(t, "Jo Bloggs", m["fullname"])
		assert.Equal(t, "Jo", m["firstname"])
		assert.Contains(t, m, "lastname")
	})

	t.Run("firstname and lastname independently settable without displayname", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(nil, strp("Jo"), strp("Bloggs"), nil, nil, nil, nil, nil, nil, false, nil)
		m := setToMap(set)
		assert.NotContains(t, m, "fullname")
		assert.Equal(t, "Jo", m["firstname"])
		assert.Equal(t, "Bloggs", m["lastname"])
	})

	t.Run("settingsJSON without lastlocation sets only settings", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(nil, nil, nil, strp(`{"a":1}`), nil, nil, nil, nil, nil, false, nil)
		m := setToMap(set)
		assert.Equal(t, `{"a":1}`, m["settings"])
		assert.NotContains(t, m, "lastlocation")
	})

	t.Run("settingsJSON with lastlocation sets both", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(nil, nil, nil, strp(`{"a":1}`), u64p(42), nil, nil, nil, nil, false, nil)
		m := setToMap(set)
		assert.Equal(t, `{"a":1}`, m["settings"])
		assert.Equal(t, uint64(42), m["lastlocation"])
	})

	t.Run("lastlocation without settingsJSON is dropped entirely", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(nil, nil, nil, nil, u64p(42), nil, nil, nil, nil, false, nil)
		m := setToMap(set)
		assert.NotContains(t, m, "lastlocation")
		assert.NotContains(t, m, "settings")
	})

	t.Run("onholidaytill, relevantallowed, newslettersallowed, source, marketingconsent all independently settable", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(nil, nil, nil, nil, nil, strp("2026-12-25"), intp(1), intp(0), strp("app"), false, intp(1))
		m := setToMap(set)
		assert.Equal(t, "2026-12-25", m["onholidaytill"])
		assert.Equal(t, 1, m["relevantallowed"])
		assert.Equal(t, 0, m["newslettersallowed"])
		assert.Equal(t, "app", m["source"])
		assert.Equal(t, 1, m["marketingconsent"])
	})

	t.Run("deletedNull true adds the deleted column", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(nil, nil, nil, nil, nil, nil, nil, nil, nil, true, nil)
		m := setToMap(set)
		assert.Equal(t, gorm.Expr("NULL"), m["deleted"])
	})

	t.Run("deletedNull false omits the deleted column", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(nil, nil, nil, nil, nil, nil, nil, nil, nil, false, nil)
		m := setToMap(set)
		assert.NotContains(t, m, "deleted")
	})

	t.Run("zero-value relevantallowed and newslettersallowed still included when pointer non-nil", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(nil, nil, nil, nil, nil, nil, intp(0), intp(0), nil, false, nil)
		m := setToMap(set)
		assert.Equal(t, 0, m["relevantallowed"])
		assert.Equal(t, 0, m["newslettersallowed"])
	})

	t.Run("everything set at once produces every column", func(t *testing.T) {
		set := buildPatchSessionUpdateSet(
			strp("Jo Bloggs"), strp("Jo"), strp("Bloggs"), strp(`{"a":1}`), u64p(7),
			strp("2026-01-01"), intp(1), intp(1), strp("web"), true, intp(0),
		)
		m := setToMap(set)
		for _, col := range []string{"fullname", "firstname", "lastname", "settings", "lastlocation",
			"onholidaytill", "relevantallowed", "newslettersallowed", "source", "deleted", "marketingconsent"} {
			assert.Contains(t, m, col, "expected column %q in update set", col)
		}
	})
}
