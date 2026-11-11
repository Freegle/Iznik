package emailtracking

import (
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func TestTableNames(t *testing.T) {
	assert.Equal(t, "email_tracking", EmailTracking{}.TableName())
	assert.Equal(t, "email_tracking_clicks", EmailTrackingClick{}.TableName())
	assert.Equal(t, "email_tracking_images", EmailTrackingImage{}.TableName())
}

func TestDigestTypeIsRolledUp(t *testing.T) {
	cases := []struct {
		in   string
		want bool
	}{
		{"", true},
		{"UnifiedDigest", true},
		{"UnifiedDigestDaily", true},
		{"unifieddigest", false},
		{"Digest", false},
		{"ChitChat", false},
		{" UnifiedDigest", false},
	}
	for _, c := range cases {
		assert.Equal(t, c.want, digestTypeIsRolledUp(c.in), "input %q", c.in)
	}
}

func TestIsWholeDayWindow(t *testing.T) {
	cases := []struct {
		name string
		w    digestDateWindow
		want bool
	}{
		{"whole day", digestDateWindow{"2026-10-01 00:00:00", "2026-10-01 23:59:59"}, true},
		{"different dates", digestDateWindow{"2026-10-01 00:00:00", "2026-10-02 23:59:59"}, false},
		{"partial start", digestDateWindow{"2026-10-01 06:00:00", "2026-10-01 23:59:59"}, false},
		{"partial end", digestDateWindow{"2026-10-01 00:00:00", "2026-10-01 12:00:00"}, false},
		{"date only", digestDateWindow{"2026-10-01", "2026-10-01"}, false},
		{"T separator", digestDateWindow{"2026-10-01T00:00:00", "2026-10-01T23:59:59"}, false},
		{"empty", digestDateWindow{}, false},
		{"long start", digestDateWindow{"2026-10-01 00:00:00.000", "2026-10-01 23:59:59"}, false},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			assert.Equal(t, c.want, isWholeDayWindow(c.w))
		})
	}
}

func TestPlanDigestWindows_NoDatabaseNeeded(t *testing.T) {
	now := time.Date(2026, 10, 10, 12, 0, 0, 0, time.UTC)
	partial := func(day int) digestDateWindow {
		d := time.Date(2026, 10, day, 0, 0, 0, 0, time.UTC).Format("2006-01-02")
		return digestDateWindow{d + " 06:00:00", d + " 18:00:00"}
	}

	t.Run("empty input", func(t *testing.T) {
		live, rolled, missing := planDigestWindows(nil, nil, now)
		assert.Empty(t, live)
		assert.Empty(t, rolled)
		assert.Equal(t, 0, missing)
	})

	t.Run("future windows skipped", func(t *testing.T) {
		live, rolled, missing := planDigestWindows(nil, []digestDateWindow{partial(11), partial(12)}, now)
		assert.Empty(t, live)
		assert.Empty(t, rolled)
		assert.Equal(t, 0, missing)
	})

	t.Run("partial past windows are live", func(t *testing.T) {
		in := []digestDateWindow{partial(1), partial(2)}
		live, rolled, missing := planDigestWindows(nil, in, now)
		assert.Equal(t, in, live)
		assert.Empty(t, rolled)
		assert.Equal(t, 0, missing)
	})

	t.Run("cap keeps the most recent", func(t *testing.T) {
		in := []digestDateWindow{partial(1), partial(2), partial(3), partial(4), partial(5)}
		live, _, missing := planDigestWindows(nil, in, now)
		assert.Equal(t, 2, missing)
		assert.Equal(t, in[2:], live)
		assert.Len(t, live, digestLiveDayCap)
	})

	t.Run("unparseable start is not treated as future", func(t *testing.T) {
		in := []digestDateWindow{{"garbage", "garbage"}}
		live, _, missing := planDigestWindows(nil, in, now)
		assert.Equal(t, in, live)
		assert.Equal(t, 0, missing)
	})
}

func TestParseDigestBound_Layouts(t *testing.T) {
	for _, s := range []string{"2026-10-01 12:30:45", "2026-10-01T12:30:45", "2026-10-01"} {
		tm, err := parseDigestBound(s)
		assert.NoError(t, err, s)
		assert.Equal(t, 2026, tm.Year())
	}
	_, err := parseDigestBound("not a date")
	assert.Error(t, err)
	_, err = parseDigestBound("")
	assert.Error(t, err)
}

func TestJournalEnabledFromEnv(t *testing.T) {
	cases := map[string]bool{
		"":      true,
		"on":    true,
		"1":     true,
		"yes":   true,
		"off":   false,
		"OFF":   false,
		" off ": false,
		"0":     false,
		"false": false,
		"No":    false,
		"maybe": true,
	}
	for in, want := range cases {
		assert.Equal(t, want, JournalEnabledFromEnv(in), "input %q", in)
	}
}

func TestSetJournalEnabledToggles(t *testing.T) {
	prev := JournalEnabled()
	defer SetJournalEnabled(prev)

	SetJournalEnabled(true)
	assert.True(t, JournalEnabled())
	SetJournalEnabled(false)
	assert.False(t, JournalEnabled())
}

func TestSetJournalTableForTestRestores(t *testing.T) {
	journalMu.Lock()
	orig := journalTable
	journalMu.Unlock()

	restore := SetJournalTableForTest("some_other_table")
	journalMu.Lock()
	assert.Equal(t, "some_other_table", journalTable)
	journalMu.Unlock()

	restore()
	journalMu.Lock()
	assert.Equal(t, orig, journalTable)
	journalMu.Unlock()
}

func TestCleanRef(t *testing.T) {
	cases := []struct {
		name string
		in   string
		want string
	}{
		{"empty", "", ""},
		{"alnum", "abc123XYZ", "abc123XYZ"},
		{"hyphen allowed", "test-ref-1", "test-ref-1"},
		{"space rejected", "ab cd", ""},
		{"quote rejected", "ab'cd", ""},
		{"slash rejected", "ab/cd", ""},
		{"underscore rejected", "ab_cd", ""},
		{"unicode rejected", "abé", ""},
		{"truncated to max", strings.Repeat("a", 40), strings.Repeat("a", journalRefMax)},
		{"junk past max is dropped", strings.Repeat("a", journalRefMax) + "!!!", strings.Repeat("a", journalRefMax)},
		{"exact max", strings.Repeat("b", journalRefMax), strings.Repeat("b", journalRefMax)},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			assert.Equal(t, c.want, cleanRef(c.in))
		})
	}
}

func TestClampPosition(t *testing.T) {
	assert.Equal(t, "", clampPosition(""))
	assert.Equal(t, "hero", clampPosition("hero"))
	exact := strings.Repeat("x", journalPositionMax)
	assert.Equal(t, exact, clampPosition(exact))
	assert.Equal(t, exact, clampPosition(exact+"yyy"))
	// Multi-byte runes are counted as characters, not bytes.
	multi := strings.Repeat("é", journalPositionMax+5)
	got := clampPosition(multi)
	assert.Equal(t, journalPositionMax, len([]rune(got)))
}

func TestRecordWithInvalidRefIsNoop(t *testing.T) {
	journalMu.Lock()
	before := len(journalBuf)
	journalMu.Unlock()

	RecordImageLoad("", "hero", 10)
	RecordImageLoad("bad ref!", "hero", 10)
	RecordPixelOpen("")
	RecordPixelOpen("bad/ref")

	journalMu.Lock()
	after := len(journalBuf)
	journalMu.Unlock()
	assert.Equal(t, before, after)
}

func TestDeliveryBaseAndImagesDomainHost(t *testing.T) {
	t.Setenv("DELIVERY_DOMAIN", "")
	assert.Equal(t, "https://delivery.ilovefreegle.org", deliveryBase())
	t.Setenv("DELIVERY_DOMAIN", "delivery.example.test")
	assert.Equal(t, "https://delivery.example.test", deliveryBase())

	t.Setenv("IMAGE_DOMAIN", "")
	assert.Equal(t, "images.ilovefreegle.org", imagesDomainHost())
	t.Setenv("IMAGE_DOMAIN", "img.example.test")
	assert.Equal(t, "img.example.test", imagesDomainHost())
}

func TestLogJournalRateLimited(t *testing.T) {
	journalMu.Lock()
	prev := journalLastLog
	journalLastLog = time.Now()
	journalMu.Unlock()
	defer func() {
		journalMu.Lock()
		journalLastLog = prev
		journalMu.Unlock()
	}()

	// Within a minute of the last line: suppressed, last-log time unchanged.
	journalMu.Lock()
	stamp := journalLastLog
	journalMu.Unlock()
	logJournal("suppressed")
	journalMu.Lock()
	assert.Equal(t, stamp, journalLastLog)
	journalLastLog = time.Now().Add(-2 * time.Minute)
	journalMu.Unlock()

	// More than a minute ago: emitted, last-log time advances.
	logJournal("emitted")
	journalMu.Lock()
	assert.WithinDuration(t, time.Now(), journalLastLog, 5*time.Second)
	journalMu.Unlock()
}
