package userdump

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"
)

// sentryProjects are the Freegle Sentry project slugs to search.
var sentryProjects = []string{"go", "nuxt3", "php", "capacitor", "modtools"}

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

func (s *sentryClient) issues(project, query string) ([]sentryIssue, error) {
	params := url.Values{}
	params.Set("query", query)
	params.Set("statsPeriod", "90d")
	u := fmt.Sprintf("%s/projects/%s/%s/issues/?%s", sentryBase(), s.org, project, params.Encode())

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
		return nil, fmt.Errorf("sentry %s status %d: %s", project, resp.StatusCode, strings.TrimSpace(string(body)))
	}

	var issues []sentryIssue
	if err := json.NewDecoder(resp.Body).Decode(&issues); err != nil {
		return nil, err
	}
	return issues, nil
}

// collectSentry queries each project for issues affecting the user (by id and by
// each email) and writes them to sentry_issues. Missing token => error so the
// caller records a warning. Partial per-project failures are tolerated as long
// as something was collected.
func collectSentry(b *Builder, s *sentryClient, userID uint64, emails []string) (int, error) {
	if s.token == "" {
		return 0, fmt.Errorf("no SENTRY_AUTH_TOKEN configured")
	}
	if err := b.EnsureTable("sentry_issues",
		`"project" TEXT, "issue_id" TEXT, "title" TEXT, "culprit" TEXT, "level" TEXT, "status" TEXT, "count" TEXT, "user_count" INTEGER, "first_seen" TEXT, "last_seen" TEXT, "permalink" TEXT, "matched" TEXT`); err != nil {
		return 0, err
	}

	queries := []string{fmt.Sprintf("user.id:%d", userID)}
	for _, em := range emails {
		em = strings.TrimSpace(em)
		if em != "" {
			queries = append(queries, "user.email:"+em)
		}
	}

	seen := map[string]bool{}
	n := 0
	var firstErr error
	for _, project := range sentryProjects {
		for _, qy := range queries {
			issues, err := s.issues(project, qy)
			if err != nil {
				if firstErr == nil {
					firstErr = err
				}
				continue
			}
			for _, is := range issues {
				key := project + "|" + is.ID
				if seen[key] {
					continue
				}
				seen[key] = true
				_ = b.InsertRow("sentry_issues",
					[]string{"project", "issue_id", "title", "culprit", "level", "status", "count", "user_count", "first_seen", "last_seen", "permalink", "matched"},
					project, is.ID, is.Title, is.Culprit, is.Level, is.Status, is.Count, is.UserCount, is.FirstSeen, is.LastSeen, is.Permalink, qy)
				n++
			}
		}
	}
	if n == 0 && firstErr != nil {
		return 0, firstErr
	}
	return n, nil
}
