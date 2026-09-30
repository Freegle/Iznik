package emailtracking

import (
	"os"
	"strings"
	"testing"
	"time"
)

// ---------------------------------------------------------------------------
// calcEmailRates — shared rate formula helper
// ---------------------------------------------------------------------------

// TestCalcEmailRates_Formulas verifies that the extracted helper produces
// identical values to the previous inline rate formulas used in Stats and
// StatsByType. Each case encodes the formula manually for comparison.
func TestCalcEmailRates_Formulas(t *testing.T) {
	cases := []struct {
		name            string
		totalSent       int64
		opened          int64
		clicked         int64
		linkedBounces   int64
		wantOpen        float64
		wantClick       float64
		wantClickToOpen float64
		wantBounce      float64
	}{
		{
			name:      "typical engagement",
			totalSent: 100, opened: 40, clicked: 10, linkedBounces: 5,
			wantOpen:        40.0,
			wantClick:       10.0,
			wantClickToOpen: 25.0, // 10/40*100
			wantBounce:      5.0,
		},
		{
			name:      "all zeros",
			totalSent: 0, opened: 0, clicked: 0, linkedBounces: 0,
			wantOpen: 0, wantClick: 0, wantClickToOpen: 0, wantBounce: 0,
		},
		{
			name:      "no clicks",
			totalSent: 200, opened: 100, clicked: 0, linkedBounces: 0,
			wantOpen:        50.0,
			wantClick:       0,
			wantClickToOpen: 0,
			wantBounce:      0,
		},
		{
			name:      "no opens implies zero click-to-open",
			totalSent: 50, opened: 0, clicked: 0, linkedBounces: 2,
			wantOpen:        0,
			wantClick:       0,
			wantClickToOpen: 0,
			wantBounce:      4.0, // 2/50*100
		},
		{
			name:      "perfect open rate",
			totalSent: 1000, opened: 1000, clicked: 500, linkedBounces: 10,
			wantOpen:        100.0,
			wantClick:       50.0,
			wantClickToOpen: 50.0, // 500/1000*100
			wantBounce:      1.0,
		},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			open, click, cto, bounce := calcEmailRates(tc.totalSent, tc.opened, tc.clicked, tc.linkedBounces)
			if open != tc.wantOpen {
				t.Errorf("open_rate = %v, want %v", open, tc.wantOpen)
			}
			if click != tc.wantClick {
				t.Errorf("click_rate = %v, want %v", click, tc.wantClick)
			}
			if cto != tc.wantClickToOpen {
				t.Errorf("click_to_open_rate = %v, want %v", cto, tc.wantClickToOpen)
			}
			if bounce != tc.wantBounce {
				t.Errorf("bounce_rate = %v, want %v", bounce, tc.wantBounce)
			}
		})
	}
}

// ---------------------------------------------------------------------------
// statsByTypeQuery — FORCE INDEX conditional on date range
// ---------------------------------------------------------------------------

// TestStatsByTypeQuery_NoDateRange_OmitsForceIndex asserts that StatsByType omits
// FORCE INDEX (sent_at) when no date range is supplied. The unconditional hint
// misleads the optimizer when it must scan the whole table; omitting it lets
// MySQL choose its own plan.
func TestStatsByTypeQuery_NoDateRange_OmitsForceIndex(t *testing.T) {
	table, _, args := statsByTypeQuery("", "")
	if strings.Contains(table, "FORCE INDEX") {
		t.Errorf("StatsByType without dates must not emit FORCE INDEX, got:\n%s", table)
	}
	if len(args) != 0 {
		t.Errorf("expected 0 args without date range, got %d", len(args))
	}
}

// TestStatsByTypeQuery_WithDateRange_IncludesForceIndex asserts that the hint is
// retained when a date range is provided (range scan benefits from the index).
func TestStatsByTypeQuery_WithDateRange_IncludesForceIndex(t *testing.T) {
	table, _, args := statsByTypeQuery("2025-01-01", "2025-12-31")
	if !strings.Contains(table, "FORCE INDEX") {
		t.Errorf("StatsByType with dates must retain FORCE INDEX, got:\n%s", table)
	}
	if len(args) != 2 {
		t.Errorf("expected 2 args with date range, got %d", len(args))
	}
}

// TestStatsByTypeQuery_TNWhere_Present checks that the TN-exclusion WHERE
// constant is present in every generated WHERE clause.
func TestStatsByTypeQuery_TNWhere_Present(t *testing.T) {
	for _, label := range []string{"without-dates", "with-dates"} {
		var whereSQL string
		if label == "without-dates" {
			_, whereSQL, _ = statsByTypeQuery("", "")
		} else {
			_, whereSQL, _ = statsByTypeQuery("2025-01-01", "2025-12-31")
		}
		if !strings.Contains(whereSQL, emailTrackingTNWhere) {
			t.Errorf("[%s] expected TN WHERE clause, missing; got:\n%s", label, whereSQL)
		}
	}
}

func TestIsNumeric(t *testing.T) {
	cases := []struct {
		in   string
		want bool
	}{
		{"", true},
		{"0", true},
		{"123", true},
		{"0123456789", true},
		{"12a", false},
		{"a12", false},
		{"1.2", false},
		{"-12", false},
		{" 12", false},
		{"abc", false},
	}
	for _, c := range cases {
		if got := isNumeric(c.in); got != c.want {
			t.Errorf("isNumeric(%q) = %v, want %v", c.in, got, c.want)
		}
	}
}

func TestContainsString(t *testing.T) {
	if !containsString([]string{"a", "b", "c"}, "b") {
		t.Errorf("containsString should find 'b'")
	}
	if containsString([]string{"a", "b"}, "c") {
		t.Errorf("containsString should not find 'c'")
	}
	if containsString([]string{}, "a") {
		t.Errorf("containsString should not find anything in empty slice")
	}
	if containsString(nil, "a") {
		t.Errorf("containsString should not find anything in nil slice")
	}
	if !containsString([]string{""}, "") {
		t.Errorf("containsString should find empty string in slice containing empty string")
	}
}

func TestNormalizeURL(t *testing.T) {
	cases := []struct {
		in   string
		want string
	}{
		{"", ""},
		{"/message/12345", "/message/{id}"},
		{"/message/12345/edit", "/message/{id}/edit"},
		{"/user/42/group/99", "/user/{id}/group/{id}"},
		{"/message/12345?foo=bar", "/message/{id}"},
		{"/static/page", "/static/page"},
		{"nooslashes", "nooslashes"},
		{"/a/1/b/2/c/3", "/a/{id}/b/{id}/c/{id}"},
		{"/123/456", "/{id}/{id}"},
	}
	for _, c := range cases {
		if got := normalizeURL(c.in); got != c.want {
			t.Errorf("normalizeURL(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}

func TestIsValidRedirectURL(t *testing.T) {
	origUser := os.Getenv("USER_SITE")
	origMod := os.Getenv("MOD_SITE")
	origImg := os.Getenv("IMAGE_DOMAIN")
	origArch := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	origGroup := os.Getenv("GROUP_DOMAIN")
	t.Cleanup(func() {
		os.Setenv("USER_SITE", origUser)
		os.Setenv("MOD_SITE", origMod)
		os.Setenv("IMAGE_DOMAIN", origImg)
		os.Setenv("IMAGE_ARCHIVED_DOMAIN", origArch)
		os.Setenv("GROUP_DOMAIN", origGroup)
	})

	os.Setenv("USER_SITE", "example.com")
	os.Setenv("MOD_SITE", "mod.example.com")
	os.Setenv("IMAGE_DOMAIN", "images.example.com")
	os.Setenv("IMAGE_ARCHIVED_DOMAIN", "archive.example.com")
	os.Setenv("GROUP_DOMAIN", "groups.example.com")

	if isValidRedirectURL("") {
		t.Errorf("empty URL must be invalid")
	}
	if isValidRedirectURL("ftp://example.com") {
		t.Errorf("ftp URL must be invalid")
	}
	if isValidRedirectURL("javascript:alert(1)") {
		t.Errorf("javascript URL must be invalid")
	}
	if isValidRedirectURL("//example.com") {
		t.Errorf("scheme-relative URL must be invalid")
	}
	if isValidRedirectURL("https://evil.com/phish") {
		t.Errorf("disallowed domain must be invalid")
	}

	valid := []string{
		"http://example.com/path",
		"https://example.com/path",
		"https://mod.example.com/foo",
		"https://images.example.com/i/1.jpg",
		"https://archive.example.com/old",
		"https://groups.example.com/g/123",
		"http://localhost:8192/",
		"https://maps.google.com/?q=x",
		"https://delivery.ilovefreegle.org/img/x",
		"https://modtools.org/chat/1",
		"https://freegle.in/paypal1510",
	}
	for _, u := range valid {
		if !isValidRedirectURL(u) {
			t.Errorf("isValidRedirectURL(%q) = false, want true", u)
		}
	}
}

func TestIsValidRedirectURLEmptyEnv(t *testing.T) {
	origUser := os.Getenv("USER_SITE")
	origMod := os.Getenv("MOD_SITE")
	origImg := os.Getenv("IMAGE_DOMAIN")
	origArch := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	origGroup := os.Getenv("GROUP_DOMAIN")
	t.Cleanup(func() {
		os.Setenv("USER_SITE", origUser)
		os.Setenv("MOD_SITE", origMod)
		os.Setenv("IMAGE_DOMAIN", origImg)
		os.Setenv("IMAGE_ARCHIVED_DOMAIN", origArch)
		os.Setenv("GROUP_DOMAIN", origGroup)
	})

	os.Unsetenv("USER_SITE")
	os.Unsetenv("MOD_SITE")
	os.Unsetenv("IMAGE_DOMAIN")
	os.Unsetenv("IMAGE_ARCHIVED_DOMAIN")
	os.Unsetenv("GROUP_DOMAIN")

	if !isValidRedirectURL("http://localhost/") {
		t.Errorf("localhost should always be allowed")
	}
	if !isValidRedirectURL("https://modtools.org/x") {
		t.Errorf("modtools.org should always be allowed")
	}
	if !isValidRedirectURL("https://freegle.in/paypal1510") {
		t.Errorf("freegle.in should always be allowed (Freegle PayPal short links)")
	}
	if isValidRedirectURL("https://unknown.example.net/") {
		t.Errorf("unknown domain must be invalid when env unset")
	}
}

func TestParseDigestBound(t *testing.T) {
	cases := []struct {
		in   string
		want time.Time
	}{
		{"2026-06-01", time.Date(2026, 6, 1, 0, 0, 0, 0, time.UTC)},
		{"2026-06-01 15:04:05", time.Date(2026, 6, 1, 15, 4, 5, 0, time.UTC)},
		{"2026-06-01T15:04:05", time.Date(2026, 6, 1, 15, 4, 5, 0, time.UTC)},
	}
	for _, c := range cases {
		got, err := parseDigestBound(c.in)
		if err != nil {
			t.Fatalf("parseDigestBound(%q) unexpected error: %v", c.in, err)
		}
		if !got.Equal(c.want) {
			t.Errorf("parseDigestBound(%q) = %v, want %v", c.in, got, c.want)
		}
	}

	if _, err := parseDigestBound("not-a-date"); err == nil {
		t.Errorf("parseDigestBound(%q) expected error, got nil", "not-a-date")
	}
}

// TestChunkDateWindows_SingleDay verifies a range that fits in one chunk
// produces exactly one window covering the whole range.
func TestChunkDateWindows_SingleDay(t *testing.T) {
	windows, err := chunkDateWindows("2026-06-01", "2026-06-01 23:59:59", 1)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(windows) != 1 {
		t.Fatalf("expected 1 window, got %d: %+v", len(windows), windows)
	}
	if windows[0].Start != "2026-06-01 00:00:00" || windows[0].End != "2026-06-01 23:59:59" {
		t.Errorf("unexpected window bounds: %+v", windows[0])
	}
}

// TestChunkDateWindows_MultiDay verifies a multi-day range is split into
// contiguous, non-overlapping 1-day windows with no gaps at the boundaries -
// the property the merge logic in DigestClickPositions relies on to sum
// GROUP BY counts across windows and get the same result as one query over
// the whole range.
func TestChunkDateWindows_MultiDay(t *testing.T) {
	windows, err := chunkDateWindows("2026-06-01", "2026-06-03 23:59:59", 1)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(windows) != 3 {
		t.Fatalf("expected 3 windows, got %d: %+v", len(windows), windows)
	}

	want := []digestDateWindow{
		{Start: "2026-06-01 00:00:00", End: "2026-06-01 23:59:59"},
		{Start: "2026-06-02 00:00:00", End: "2026-06-02 23:59:59"},
		{Start: "2026-06-03 00:00:00", End: "2026-06-03 23:59:59"},
	}
	for i, w := range windows {
		if w != want[i] {
			t.Errorf("window %d = %+v, want %+v", i, w, want[i])
		}
	}

	// First window starts at the requested start; last window ends at the
	// requested end; each window's start is exactly one second after the
	// previous window's end (no gap, no overlap).
	if windows[0].Start != "2026-06-01 00:00:00" {
		t.Errorf("first window should start at requested start, got %s", windows[0].Start)
	}
	if windows[len(windows)-1].End != "2026-06-03 23:59:59" {
		t.Errorf("last window should end at requested end, got %s", windows[len(windows)-1].End)
	}
	for i := 1; i < len(windows); i++ {
		prevEnd, err := parseDigestBound(windows[i-1].End)
		if err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
		curStart, err := parseDigestBound(windows[i].Start)
		if err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
		if !curStart.Equal(prevEnd.Add(time.Second)) {
			t.Errorf("window %d starts at %v, want exactly 1s after window %d ends (%v)", i, curStart, i-1, prevEnd)
		}
	}
}

// TestChunkDateWindows_WiderChunk verifies a chunkDays > 1 groups multiple
// days into fewer, wider windows while still covering the whole range with
// no gaps or overlaps.
func TestChunkDateWindows_WiderChunk(t *testing.T) {
	windows, err := chunkDateWindows("2026-06-01", "2026-06-07 23:59:59", 3)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(windows) != 3 {
		t.Fatalf("expected 3 windows (3+3+1 days), got %d: %+v", len(windows), windows)
	}
	if windows[0].Start != "2026-06-01 00:00:00" || windows[0].End != "2026-06-03 23:59:59" {
		t.Errorf("unexpected first window: %+v", windows[0])
	}
	if windows[2].Start != "2026-06-07 00:00:00" || windows[2].End != "2026-06-07 23:59:59" {
		t.Errorf("unexpected last (partial) window: %+v", windows[2])
	}
}

// TestChunkDateWindows_ZeroOrNegativeChunkDays verifies non-positive
// chunkDays values fall back to 1-day windows rather than looping forever or
// producing an empty/negative-width window.
func TestChunkDateWindows_ZeroOrNegativeChunkDays(t *testing.T) {
	windows, err := chunkDateWindows("2026-06-01", "2026-06-02 23:59:59", 0)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(windows) != 2 {
		t.Fatalf("expected 2 windows with chunkDays=0 (defaults to 1), got %d: %+v", len(windows), windows)
	}
}

// TestChunkDateWindows_InvertedRange verifies an end before start (should
// not happen given how DigestClickPositions builds its bounds, but the
// helper must not loop forever) degrades to a single pass-through window.
func TestChunkDateWindows_InvertedRange(t *testing.T) {
	windows, err := chunkDateWindows("2026-06-05", "2026-06-01 23:59:59", 1)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(windows) != 1 {
		t.Fatalf("expected 1 pass-through window for an inverted range, got %d: %+v", len(windows), windows)
	}
	if windows[0].Start != "2026-06-05" || windows[0].End != "2026-06-01 23:59:59" {
		t.Errorf("unexpected pass-through window: %+v", windows[0])
	}
}

// TestChunkDateWindows_UnparseableBounds verifies malformed bounds return an
// error (DigestClickPositions falls back to a single pass-through window in
// this case) rather than panicking.
func TestChunkDateWindows_UnparseableBounds(t *testing.T) {
	if _, err := chunkDateWindows("not-a-date", "2026-06-01 23:59:59", 1); err == nil {
		t.Errorf("expected error for unparseable start bound")
	}
	if _, err := chunkDateWindows("2026-06-01", "not-a-date", 1); err == nil {
		t.Errorf("expected error for unparseable end bound")
	}
}

func TestGenerateTrackingID(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 50; i++ {
		id := generateTrackingID()
		if len(id) != 32 {
			t.Errorf("generateTrackingID len = %d, want 32", len(id))
		}
		for _, c := range id {
			if !strings.ContainsRune("0123456789abcdef", c) {
				t.Errorf("generateTrackingID produced non-hex char %q in %q", c, id)
			}
		}
		if seen[id] {
			t.Errorf("generateTrackingID collision on %q", id)
		}
		seen[id] = true
	}
}
