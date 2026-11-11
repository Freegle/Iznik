package ux

import (
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"regexp"
	"sort"
	"strconv"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/gofiber/fiber/v2"
)

const (
	// maxSampleSessions is how many sessions the rollup keeps per (flow, step); see ux_sample_sessions.
	maxSampleSessions = 20
	// maxSampleEvents caps one session's timeline.
	maxSampleEvents = 200
	// maxEventText caps each string we hand back, so a label can never carry a paragraph out.
	maxEventText = 100
	// sampleFetchWorkers bounds concurrent Loki queries for one request.
	sampleFetchWorkers = 5
)

// sessionIDRe is what a session id looks like. They are put into a LogQL string, so anything else
// is never sent.
var sessionIDRe = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)

// SampleEvent is one line of a session's timeline. It is deliberately the only thing taken from a
// client log line: no URL, no user agent, no message text, no user id.
type SampleEvent struct {
	Ts         string `json:"ts"`
	EventType  string `json:"event_type"`
	ActionName string `json:"action_name"`
	// InteractionType is click, scroll and the like, for interaction lines. Their action_name and
	// label carry visible text (post titles, names, chat), so neither is returned for them.
	InteractionType string `json:"interaction_type"`
	Step            string `json:"step"`
	DwellMs         *int64 `json:"dwell_ms"`
	Component       string `json:"component"`
	PageRoute       string `json:"page_route"`
}

type SampleSession struct {
	SessionID string        `json:"session_id"`
	Events    []SampleEvent `json:"events"`
}

type SamplesResponse struct {
	Date      string          `json:"date"`
	Flow      string          `json:"flow"`
	Step      string          `json:"step"`
	Sessions  []SampleSession `json:"sessions"`
	LokiError string          `json:"loki_error,omitempty"`
}

// SessionFetcher returns one session's timeline for the day around date.
type SessionFetcher func(sessionID string, date time.Time) ([]SampleEvent, error)

var (
	fetcherMu sync.RWMutex
	fetcher   SessionFetcher = fetchFromLoki
)

// SetSessionFetcher replaces the Loki fetch, for tests, and returns a function that puts it back.
func SetSessionFetcher(f SessionFetcher) func() {
	fetcherMu.Lock()
	old := fetcher
	fetcher = f
	fetcherMu.Unlock()
	return func() {
		fetcherMu.Lock()
		fetcher = old
		fetcherMu.Unlock()
	}
}

// Samples serves GET /modtools/ux/samples?date=&flow=&step=: the sampled abandoned sessions for a
// step, each with its client timeline from Loki. A Loki failure still returns the session list,
// with empty timelines and loki_error, because the ids are useful on their own.
func Samples(c *fiber.Ctx) error {
	if err := requireSupport(c); err != nil {
		return err
	}

	date, err := time.Parse(dateFormat, c.Query("date"))
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "date must be YYYY-MM-DD")
	}
	flow, step := c.Query("flow"), c.Query("step")
	if !flowRe.MatchString(flow) || !flowRe.MatchString(step) {
		return fiber.NewError(fiber.StatusBadRequest, "flow and step are required")
	}

	key := fmt.Sprintf("samples|%s|%s|%s", date.Format(dateFormat), flow, step)
	out, err := cached(key, func() (interface{}, bool, error) {
		var ids []string
		if err := database.DBConn.Table("ux_sample_sessions").Select("session_id").
			Where("`date` = ? AND flow = ? AND step = ?", date.Format(dateFormat), flow, step).
			Order("id").Limit(maxSampleSessions).Scan(&ids).Error; err != nil {
			return nil, false, err
		}

		resp := SamplesResponse{Date: date.Format(dateFormat), Flow: flow, Step: step, Sessions: make([]SampleSession, len(ids))}
		errs := make([]error, len(ids))

		fetcherMu.RLock()
		fetch := fetcher
		fetcherMu.RUnlock()

		sem := make(chan struct{}, sampleFetchWorkers)
		var wg sync.WaitGroup
		for i, id := range ids {
			resp.Sessions[i] = SampleSession{SessionID: id, Events: []SampleEvent{}}
			if !sessionIDRe.MatchString(id) {
				continue
			}
			wg.Add(1)
			sem <- struct{}{}
			go func(i int, id string) {
				defer wg.Done()
				defer func() { <-sem }()
				events, err := fetch(id, date)
				if err != nil {
					errs[i] = err
					return
				}
				if events != nil {
					resp.Sessions[i].Events = events
				}
			}(i, id)
		}
		wg.Wait()

		for _, e := range errs {
			if e != nil {
				resp.LokiError = truncate(e.Error(), 200)
				break
			}
		}
		// A degraded answer must not sit in the cache for ten minutes.
		return resp, resp.LokiError == "", nil
	})
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not read UX samples")
	}
	return c.JSON(out)
}

func truncate(s string, n int) string {
	r := []rune(s)
	if len(r) <= n {
		return s
	}
	return string(r[:n])
}

// fetchFromLoki reads one session's flow, interaction and page_view lines from the day before to
// the day after date. The messages here are our own and fixed, so a Loki failure never echoes a
// URL or a response body.
func fetchFromLoki(sessionID string, date time.Time) ([]SampleEvent, error) {
	if !sessionIDRe.MatchString(sessionID) {
		return nil, errors.New("invalid session id")
	}
	base := os.Getenv("LOKI_URL")
	if base == "" {
		return nil, errors.New("Loki is not configured")
	}

	day := time.Date(date.Year(), date.Month(), date.Day(), 0, 0, 0, 0, time.UTC)
	query := `{app="freegle", source="client", event_type=~"flow|interaction|page_view"} |= "` + sessionID + `" | json session_id, event_type, action_name, interaction_type, step, dwell_ms, component, page_route | session_id="` + sessionID + `"`
	streams, err := misc.LokiQueryRange(base, query, day.AddDate(0, 0, -1).UnixNano(), day.AddDate(0, 0, 2).UnixNano(),
		maxSampleEvents, "forward", 10*time.Second)
	if err != nil {
		var status *misc.LokiStatusError
		if errors.As(err, &status) {
			return nil, fmt.Errorf("Loki returned status %d", status.Status)
		}
		return nil, errors.New("Loki is unreachable or its response could not be read")
	}

	type stamped struct {
		ns int64
		ev SampleEvent
	}
	var all []stamped
	for _, stream := range streams {
		for _, v := range stream.Values {
			if len(v) < 2 {
				continue
			}
			ns, err := strconv.ParseInt(v[0], 10, 64)
			if err != nil {
				continue
			}
			var line map[string]interface{}
			if json.Unmarshal([]byte(v[1]), &line) != nil {
				continue
			}
			all = append(all, stamped{ns, eventFromLine(ns, line, stream.Stream)})
		}
	}
	sort.SliceStable(all, func(i, j int) bool { return all[i].ns < all[j].ns })
	if len(all) > maxSampleEvents {
		all = all[:maxSampleEvents]
	}

	events := make([]SampleEvent, 0, len(all))
	for _, s := range all {
		events = append(events, s.ev)
	}
	return events, nil
}

// eventFromLine copies the whitelisted fields out of a client log line, and nothing else.
func eventFromLine(ns int64, line map[string]interface{}, labels map[string]string) SampleEvent {
	str := func(k string) string {
		if s, ok := line[k].(string); ok {
			return truncate(s, maxEventText)
		}
		return ""
	}
	ev := SampleEvent{
		Ts:        time.Unix(0, ns).UTC().Format(time.RFC3339Nano),
		EventType: str("event_type"),
		Step:      str("step"),
		Component: str("component"),
		PageRoute: str("page_route"),
	}
	if ev.EventType == "interaction" {
		ev.InteractionType = str("interaction_type")
	} else {
		ev.ActionName = str("action_name")
	}
	if ev.EventType == "" {
		ev.EventType = truncate(labels["event_type"], maxEventText)
	}
	if d, ok := line["dwell_ms"].(float64); ok && d >= 0 {
		ms := int64(d + 0.5)
		ev.DwellMs = &ms
	}
	return ev
}
