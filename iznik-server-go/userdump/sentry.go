package userdump

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"sync"
	"time"
)

// sentryBase returns the Sentry REST API root, overridable via SENTRY_API_BASE
// (used by tests to point at a local server).
func sentryBase() string {
	if v := os.Getenv("SENTRY_API_BASE"); v != "" {
		return strings.TrimRight(v, "/")
	}
	return "https://sentry.io/api/0"
}

type sentryIssue struct {
	ID        string `json:"id"`
	Title     string `json:"title"`
	Culprit   string `json:"culprit"`
	Level     string `json:"level"`
	Status    string `json:"status"`
	Count     string `json:"count"`
	UserCount int    `json:"userCount"`
	FirstSeen string `json:"firstSeen"`
	LastSeen  string `json:"lastSeen"`
	Permalink string `json:"permalink"`
	Project   struct {
		Slug string `json:"slug"`
	} `json:"project"`
}

type sentryClient struct {
	token string
	org   string
	hc    *http.Client
}

func newSentryClient() *sentryClient {
	org := os.Getenv("SENTRY_ORG_SLUG")
	if org == "" {
		org = "freegle"
	}
	return &sentryClient{
		token: os.Getenv("SENTRY_AUTH_TOKEN"),
		org:   org,
		hc:    &http.Client{Timeout: 30 * time.Second},
	}
}

// sentryMaxRange bounds an absolute date query. Sentry's issues API rejects a
// statsPeriod over 14d (only ”, '24h', '14d' are valid) and caps absolute
// start/end ranges at 90 days, so we query by start/end and clamp to 90 days.
const sentryMaxRange = 90 * 24 * time.Hour

// issues runs one org-wide issue search across every project (project=-1), so
// a member costs one request per query rather than one per project per query.
// The project each issue belongs to comes back on the issue itself.
func (s *sentryClient) issues(query string, startNs, endNs int64) ([]sentryIssue, error) {
	end := time.Unix(0, endNs).UTC()
	start := time.Unix(0, startNs).UTC()
	if !end.After(start) || end.Sub(start) > sentryMaxRange {
		start = end.Add(-sentryMaxRange)
	}
	params := url.Values{}
	params.Set("project", "-1")
	params.Set("limit", "100")
	params.Set("query", query)
	// Absolute range, NOT statsPeriod: statsPeriod only allows '', '24h', '14d',
	// so "90d" 400s ("Invalid stats_period"). start/end accepts up to 90 days.
	params.Set("start", start.Format("2006-01-02T15:04:05"))
	params.Set("end", end.Format("2006-01-02T15:04:05"))
	u := fmt.Sprintf("%s/organizations/%s/issues/?%s", sentryBase(), s.org, params.Encode())

	req, err := http.NewRequest(http.MethodGet, u, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+s.token)

	resp, err := s.hc.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 512))
		return nil, fmt.Errorf("sentry status %d: %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}

	var issues []sentryIssue
	if err := json.NewDecoder(resp.Body).Decode(&issues); err != nil {
		return nil, err
	}
	return issues, nil
}

// sentryQueries is the searches that find a member's issues: one by id and one
// for all their emails together. Issue search takes a list (`key:[a,b]`) but
// not OR, so the two cannot be merged into one.
func sentryQueries(userID uint64, emails []string) []string {
	queries := []string{fmt.Sprintf("user.id:%d", userID)}
	var ems []string
	for _, em := range emails {
		em = strings.TrimSpace(em)
		if em != "" && !strings.ContainsAny(em, ",[]") {
			ems = append(ems, em)
		}
	}
	if len(ems) > 0 {
		queries = append(queries, "user.email:["+strings.Join(ems, ",")+"]")
	}
	return queries
}

// collectSentry queries Sentry for issues affecting the user (by id and by
// email, in parallel) and writes them to sentry_issues. Missing token => error
// so the caller records a warning. A failed query is tolerated as long as
// something was collected.
func collectSentry(b *Builder, s *sentryClient, userID uint64, emails []string, startNs, endNs int64) (int, error) {
	if s.token == "" {
		return 0, fmt.Errorf("no SENTRY_AUTH_TOKEN configured")
	}
	if err := b.EnsureTable("sentry_issues",
		`"project" TEXT, "issue_id" TEXT, "title" TEXT, "culprit" TEXT, "level" TEXT, "status" TEXT, "count" TEXT, "user_count" INTEGER, "first_seen" TEXT, "last_seen" TEXT, "permalink" TEXT, "matched" TEXT`); err != nil {
		return 0, err
	}

	queries := sentryQueries(userID, emails)
	results := make([][]sentryIssue, len(queries))
	errs := make([]error, len(queries))
	var wg sync.WaitGroup
	for i, qy := range queries {
		wg.Add(1)
		go func(i int, qy string) {
			defer wg.Done()
			results[i], errs[i] = s.issues(qy, startNs, endNs)
		}(i, qy)
	}
	wg.Wait()

	seen := map[string]bool{}
	n := 0
	var firstErr error
	for i, issues := range results {
		if errs[i] != nil {
			if firstErr == nil {
				firstErr = errs[i]
			}
			continue
		}
		for _, is := range issues {
			if seen[is.ID] {
				continue
			}
			seen[is.ID] = true
			_ = b.InsertRow("sentry_issues",
				[]string{"project", "issue_id", "title", "culprit", "level", "status", "count", "user_count", "first_seen", "last_seen", "permalink", "matched"},
				is.Project.Slug, is.ID, is.Title, is.Culprit, is.Level, is.Status, is.Count, is.UserCount, is.FirstSeen, is.LastSeen, is.Permalink, queries[i])
			n++
		}
	}
	if n == 0 && firstErr != nil {
		return 0, firstErr
	}
	return n, nil
}
