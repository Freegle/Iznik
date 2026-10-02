package userdump

import (
	"database/sql"
	"net/http"
	"net/http/httptest"
	"net/url"
	"sync"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// The dump must query Sentry by absolute start/end, NOT statsPeriod: Sentry only
// allows statsPeriod ”, '24h', '14d', so the old "90d" 400'd and the user's
// errors never made it into the dump. Verify the request shape and that issues
// land in sentry_issues.
func TestCollectSentry_UsesStartEndNotStatsPeriod(t *testing.T) {
	var mu sync.Mutex
	var gotQueries []url.Values
	var gotPaths []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		gotQueries = append(gotQueries, r.URL.Query())
		gotPaths = append(gotPaths, r.URL.Path)
		mu.Unlock()
		// The same issue matches by id and by email, so it must be stored once.
		_, _ = w.Write([]byte(`[{"id":"i1","title":"Boom","culprit":"app.js","level":"error","status":"unresolved","count":"3","userCount":1,"firstSeen":"2026-07-01T00:00:00Z","lastSeen":"2026-07-20T00:00:00Z","permalink":"https://sentry/i1","project":{"slug":"nuxt3"}}]`))
	}))
	defer srv.Close()

	t.Setenv("SENTRY_API_BASE", srv.URL)

	b, err := NewBuilder()
	assert.NoError(t, err)
	defer b.Remove()

	s := &sentryClient{token: "tok", org: "freegle", hc: srv.Client()}
	end := time.Date(2026, 7, 28, 0, 0, 0, 0, time.UTC)
	start := end.Add(-30 * 24 * time.Hour)

	n, err := collectSentry(b, s, 42, []string{"a@b.com", " ", "c@d.com"}, start.UnixNano(), end.UnixNano())
	assert.NoError(t, err)
	assert.Equal(t, 1, n, "the issue found by both searches is stored once")

	// One org-wide search by id and one for all emails, not one per project
	// per address.
	assert.Len(t, gotQueries, 2)
	var searches []string
	for i, q := range gotQueries {
		searches = append(searches, q.Get("query"))
		assert.Equal(t, "/organizations/freegle/issues/", gotPaths[i])
		assert.Equal(t, "-1", q.Get("project"))
		assert.Empty(t, q.Get("statsPeriod"), "must not send the invalid statsPeriod")
		assert.Equal(t, "2026-06-28T00:00:00", q.Get("start"))
		assert.Equal(t, "2026-07-28T00:00:00", q.Get("end"))
	}
	assert.ElementsMatch(t, []string{"user.id:42", "user.email:[a@b.com,c@d.com]"}, searches)

	assert.NoError(t, b.Finalize())
	db, err := sql.Open("sqlite", b.Path())
	assert.NoError(t, err)
	defer db.Close()
	var project string
	assert.NoError(t, db.QueryRow("SELECT project FROM sentry_issues").Scan(&project))
	assert.Equal(t, "nuxt3", project, "the project comes from the issue itself")
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

	_, err := s.issues("user.id:1", start.UnixNano(), end.UnixNano())
	assert.NoError(t, err)
	assert.Equal(t, end.Add(-sentryMaxRange).Format("2006-01-02T15:04:05"), gotStart)
}
