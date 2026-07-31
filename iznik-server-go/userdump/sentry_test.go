package userdump

import (
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// The dump must query Sentry by absolute start/end, NOT statsPeriod: Sentry only
// allows statsPeriod ”, '24h', '14d', so the old "90d" 400'd and the user's
// errors never made it into the dump. Verify the request shape and that issues
// land in sentry_issues.
func TestCollectSentry_UsesStartEndNotStatsPeriod(t *testing.T) {
	var gotQueries []url.Values
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotQueries = append(gotQueries, r.URL.Query())
		// Return one issue only for the user.id query on one project, so the
		// result is a single row (dedup is per project+id across all projects).
		if strings.Contains(r.URL.Path, "/nuxt3/") && r.URL.Query().Get("query") == "user.id:42" {
			_, _ = w.Write([]byte(`[{"id":"i1","title":"Boom","culprit":"app.js","level":"error","status":"unresolved","count":"3","userCount":1,"firstSeen":"2026-07-01T00:00:00Z","lastSeen":"2026-07-20T00:00:00Z","permalink":"https://sentry/i1"}]`))
			return
		}
		_, _ = w.Write([]byte(`[]`))
	}))
	defer srv.Close()

	t.Setenv("SENTRY_API_BASE", srv.URL)

	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	s := &sentryClient{token: "tok", org: "freegle", hc: srv.Client()}
	end := time.Date(2026, 7, 28, 0, 0, 0, 0, time.UTC)
	start := end.Add(-30 * 24 * time.Hour)

	n, err := collectSentry(b, s, 42, []string{"a@b.com"}, start.UnixNano(), end.UnixNano())
	assert.NoError(t, err)
	assert.Equal(t, 1, n, "the one matching issue is stored (deduped across projects)")

	assert.NotEmpty(t, gotQueries)
	for _, q := range gotQueries {
		assert.Empty(t, q.Get("statsPeriod"), "must not send the invalid statsPeriod")
		assert.Equal(t, "2026-06-28T00:00:00", q.Get("start"))
		assert.Equal(t, "2026-07-28T00:00:00", q.Get("end"))
	}
}

// A window wider than Sentry's 90-day cap is clamped to the last 90 days.
func TestSentryIssues_ClampsRangeTo90Days(t *testing.T) {
	var gotStart string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		gotStart = r.URL.Query().Get("start")
		_, _ = w.Write([]byte(`[]`))
	}))
	defer srv.Close()
	t.Setenv("SENTRY_API_BASE", srv.URL)

	s := &sentryClient{token: "tok", org: "freegle", hc: srv.Client()}
	end := time.Date(2026, 7, 28, 0, 0, 0, 0, time.UTC)
	start := end.Add(-365 * 24 * time.Hour) // a year — must clamp to 90d

	_, err := s.issues("nuxt3", "user.id:1", start.UnixNano(), end.UnixNano())
	assert.NoError(t, err)
	assert.Equal(t, end.Add(-sentryMaxRange).Format("2006-01-02T15:04:05"), gotStart)
}
