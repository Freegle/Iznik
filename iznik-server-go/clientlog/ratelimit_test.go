package clientlog

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/misc"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

func TestRatePerMinDefaultsAndOverride(t *testing.T) {
	t.Setenv("CLIENTLOG_RATE_PER_MIN", "")
	assert.Equal(t, 600, ratePerMin())
	t.Setenv("CLIENTLOG_RATE_PER_MIN", "25")
	assert.Equal(t, 25, ratePerMin())
	t.Setenv("CLIENTLOG_RATE_PER_MIN", "nonsense")
	assert.Equal(t, 600, ratePerMin())
	t.Setenv("CLIENTLOG_RATE_PER_MIN", "0")
	assert.Equal(t, 600, ratePerMin())
	t.Setenv("CLIENTLOG_RATE_PER_MIN", "-5")
	assert.Equal(t, 600, ratePerMin())
}

func TestLimiterCountsEntriesNotRequests(t *testing.T) {
	l := newLimiter()
	now := time.Date(2026, 10, 12, 10, 0, 5, 0, time.UTC)

	accepted, tripped := l.take("1.1.1.1", 4, 10, now)
	assert.Equal(t, 4, accepted)
	assert.False(t, tripped)

	// 6 left in the window: a batch of 9 gets 6 and trips.
	accepted, tripped = l.take("1.1.1.1", 9, 10, now.Add(time.Second))
	assert.Equal(t, 6, accepted)
	assert.True(t, tripped, "the call that first crosses the limit reports it")

	// Already over: nothing accepted, and the trip is not reported again within the minute.
	accepted, tripped = l.take("1.1.1.1", 3, 10, now.Add(2*time.Second))
	assert.Equal(t, 0, accepted)
	assert.False(t, tripped, "at most one warning per IP per minute")

	// Another IP is unaffected.
	accepted, tripped = l.take("2.2.2.2", 10, 10, now)
	assert.Equal(t, 10, accepted)
	assert.False(t, tripped)

	// The next minute starts fresh, and can trip (and warn) again.
	accepted, tripped = l.take("1.1.1.1", 11, 10, now.Add(time.Minute))
	assert.Equal(t, 10, accepted)
	assert.True(t, tripped)
}

func TestLimiterMemoryIsBounded(t *testing.T) {
	l := newLimiter()
	l.maxIPs = 50
	now := time.Date(2026, 10, 12, 10, 0, 0, 0, time.UTC)

	for i := 0; i < 50; i++ {
		l.take(fmt.Sprintf("10.0.0.%d", i), 1, 10, now)
	}
	assert.Equal(t, 50, l.size())

	// Old windows are evicted when the next minute's traffic needs the room.
	for i := 0; i < 200; i++ {
		l.take(fmt.Sprintf("10.1.0.%d", i), 1, 10, now.Add(2*time.Minute))
		assert.LessOrEqual(t, l.size(), 50)
	}

	// A table full of live windows still serves a new IP, untracked, rather than growing or refusing.
	accepted, _ := l.take("10.9.9.9", 3, 10, now.Add(2*time.Minute))
	assert.Equal(t, 3, accepted)
	assert.LessOrEqual(t, l.size(), 50)
}

func readLines(t *testing.T, dir string) []map[string]interface{} {
	t.Helper()
	files, _ := filepath.Glob(filepath.Join(dir, "*.log"))
	var out []map[string]interface{}
	for _, f := range files {
		fh, err := os.Open(f)
		assert.NoError(t, err)
		sc := bufio.NewScanner(fh)
		sc.Buffer(make([]byte, 1024*1024), 1024*1024)
		for sc.Scan() {
			var m map[string]interface{}
			if json.Unmarshal(sc.Bytes(), &m) == nil {
				out = append(out, m)
			}
		}
		fh.Close()
	}
	return out
}

func post(t *testing.T, app *fiber.App, n int, prefix string) int {
	t.Helper()
	var entries []string
	for i := 0; i < n; i++ {
		entries = append(entries, fmt.Sprintf(`{"level":"info","message":"%s-%d","event_type":"log"}`, prefix, i))
	}
	req := httptest.NewRequest("POST", "/api/clientlog", bytes.NewBufferString(`{"logs":[`+strings.Join(entries, ",")+`]}`))
	req.Header.Set("Content-Type", "application/json")
	resp, err := app.Test(req)
	assert.NoError(t, err)
	return resp.StatusCode
}

func TestReceiveClientLogsDropsExcessEntriesSilentlyAndWarnsOnce(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("CLIENTLOG_RATE_PER_MIN", "5")
	useLoki(misc.NewFileLoki(dir))
	defer useLoki(nil)
	defaultLimiter = newLimiter()

	app := fiber.New()
	app.Post("/api/clientlog", ReceiveClientLogs)

	assert.Equal(t, 204, post(t, app, 4, "a"))
	assert.Equal(t, 204, post(t, app, 4, "b"), "over the limit still answers 204")
	assert.Equal(t, 204, post(t, app, 3, "c"))
	waitForHandlers()

	var client, warnings int
	for _, e := range readLines(t, dir) {
		labels, _ := e["labels"].(map[string]interface{})
		switch labels["source"] {
		case "client":
			client++
		case "clientlog_ratelimit":
			warnings++
			msg, _ := e["message"].(map[string]interface{})
			assert.EqualValues(t, 5, msg["limit_per_min"])
			assert.NotEmpty(t, msg["client_ip"])
		}
	}
	assert.Equal(t, 5, client, "only the first 5 of 11 entries are logged")
	assert.Equal(t, 1, warnings, "one warning for the IP this minute")
}

func TestReceiveClientLogsUnderLimitLogsEverything(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("CLIENTLOG_RATE_PER_MIN", "")
	useLoki(misc.NewFileLoki(dir))
	defer useLoki(nil)
	defaultLimiter = newLimiter()

	app := fiber.New()
	app.Post("/api/clientlog", ReceiveClientLogs)
	assert.Equal(t, 204, post(t, app, 7, "x"))
	waitForHandlers()

	n := 0
	for _, e := range readLines(t, dir) {
		if labels, _ := e["labels"].(map[string]interface{}); labels["source"] == "client" {
			n++
		}
	}
	assert.Equal(t, 7, n)
}

func TestClientKeyUsesRealIPNeverForwardedFor(t *testing.T) {
	app := fiber.New()
	var got string
	app.Get("/", func(c *fiber.Ctx) error { got = clientKey(c); return nil })

	req := httptest.NewRequest("GET", "/", nil)
	req.Header.Set("X-Forwarded-For", "6.6.6.6, 10.0.0.2")
	req.Header.Set("X-Real-IP", "203.0.113.9")
	app.Test(req)
	assert.Equal(t, "203.0.113.9", got, "haproxy overwrites X-Real-IP with the peer address")

	req = httptest.NewRequest("GET", "/", nil)
	req.Header.Set("X-Forwarded-For", "6.6.6.6")
	app.Test(req)
	assert.NotEqual(t, "6.6.6.6", got, "X-Forwarded-For is client-writable and never used")
	assert.NotEmpty(t, got)
}

func postBody(t *testing.T, app *fiber.App, body string) {
	req := httptest.NewRequest("POST", "/api/clientlog", bytes.NewBufferString(body))
	req.Header.Set("Content-Type", "application/json")
	resp, _ := app.Test(req)
	assert.Equal(t, 204, resp.StatusCode)
	waitForHandlers()
}

func clientMessages(t *testing.T, dir string) []string {
	var out []string
	for _, e := range readLines(t, dir) {
		if l, _ := e["labels"].(map[string]interface{}); l["source"] == "client" {
			msg, _ := e["message"].(map[string]interface{})
			out = append(out, fmt.Sprint(msg["message"]))
		}
	}
	return out
}

func TestFlowEntriesHaveTheirOwnLargerLimitAndKeepTheirOrder(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("CLIENTLOG_RATE_PER_MIN", "2")
	useLoki(misc.NewFileLoki(dir))
	defer useLoki(nil)
	defaultLimiter = newLimiter()
	flowLimiter = newLimiter()

	app := fiber.New()
	app.Post("/api/clientlog", ReceiveClientLogs)
	postBody(t, app, `{"logs":[`+
		`{"event_type":"flow","message":"f1"},{"event_type":"log","message":"a"},{"event_type":"log","message":"b"},`+
		`{"event_type":"flow","message":"f2"},{"event_type":"log","message":"c"},{"event_type":"flow","message":"f3"}]}`)

	assert.Equal(t, []string{"f1", "a", "b", "f2", "f3"}, clientMessages(t, dir),
		"flow entries are under their own limit, the third other entry is dropped, and the order is kept")
}

func TestFlowEntriesAloneCannotBypassTheLimit(t *testing.T) {
	dir := t.TempDir()
	t.Setenv("CLIENTLOG_RATE_PER_MIN", "2")
	useLoki(misc.NewFileLoki(dir))
	defer useLoki(nil)
	defaultLimiter = newLimiter()
	flowLimiter = newLimiter()

	app := fiber.New()
	app.Post("/api/clientlog", ReceiveClientLogs)
	var b strings.Builder
	b.WriteString(`{"logs":[`)
	for i := 0; i < 50; i++ {
		if i > 0 {
			b.WriteString(",")
		}
		fmt.Fprintf(&b, `{"event_type":"flow","message":"f%d"}`, i)
	}
	b.WriteString("]}")
	postBody(t, app, b.String())

	assert.Len(t, clientMessages(t, dir), 2*flowLimitMultiple)
}

func TestLimiterEvictionIsAmortised(t *testing.T) {
	l := newLimiter()
	l.maxIPs = 100
	now := time.Date(2026, 10, 12, 10, 0, 0, 0, time.UTC)
	for i := 0; i < 100; i++ {
		l.take(fmt.Sprintf("a%d", i), 1, 10, now)
	}
	// Full of live windows: repeated new IPs must not each trigger a full scan.
	l.scans = 0
	for i := 0; i < 500; i++ {
		l.take(fmt.Sprintf("b%d", i), 1, 10, now)
	}
	assert.LessOrEqual(t, l.scans, 1, "at most one sweep per minute while the table is full of live windows")

	// A new minute sweeps once and frees the room.
	l.take("c", 1, 10, now.Add(2*time.Minute))
	assert.LessOrEqual(t, l.size(), 2)
}
