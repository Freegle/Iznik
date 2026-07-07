package systemlogs

import (
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// escapeRegex
// ---------------------------------------------------------------------------

func TestEscapeRegex_NoSpecialChars(t *testing.T) {
	assert.Equal(t, "hello", escapeRegex("hello"))
}

func TestEscapeRegex_EmptyString(t *testing.T) {
	assert.Equal(t, "", escapeRegex(""))
}

func TestEscapeRegex_Dot(t *testing.T) {
	// "." must become "\."
	result := escapeRegex("a.b")
	assert.Equal(t, `a\.b`, result)
}

func TestEscapeRegex_Backslash(t *testing.T) {
	result := escapeRegex(`a\b`)
	assert.Equal(t, `a\\b`, result)
}

func TestEscapeRegex_AllSpecialChars(t *testing.T) {
	special := `\` + `.+*?^$()[]{}|`
	result := escapeRegex(special)
	// Each special char should be prefixed with "\"
	assert.NotContains(t, result, "..") // not double-escaping
	// Every char in the result is escaped
	for _, char := range []string{"\\.", "\\+", "\\*", "\\?", "\\^", "\\$", "\\(", "\\)", "\\[", "\\]", "\\{", "\\}", "\\|"} {
		assert.Contains(t, result, char)
	}
}

func TestEscapeRegex_Email(t *testing.T) {
	// Emails have dots and plus signs that need escaping.
	result := escapeRegex("user+tag@example.com")
	assert.Equal(t, `user\+tag@example\.com`, result)
}

func TestEscapeRegex_IPAddress(t *testing.T) {
	result := escapeRegex("192.168.1.1")
	assert.Equal(t, `192\.168\.1\.1`, result)
}

func TestEscapeRegex_Parens(t *testing.T) {
	result := escapeRegex("func(x)")
	assert.Equal(t, `func\(x\)`, result)
}

// ---------------------------------------------------------------------------
// parseTimeRange
// ---------------------------------------------------------------------------

func TestParseTimeRange_NowEnd(t *testing.T) {
	before := time.Now().UnixNano()
	_, endTs := parseTimeRange("1m", "now")
	after := time.Now().UnixNano()
	assert.GreaterOrEqual(t, endTs, before)
	assert.LessOrEqual(t, endTs, after)
}

func TestParseTimeRange_RelativeMinutes(t *testing.T) {
	before := time.Now()
	startTs, _ := parseTimeRange("5m", "now")
	after := time.Now()

	expectedStart := before.Add(-5 * time.Minute)
	expectedEnd := after.Add(-5 * time.Minute)

	assert.GreaterOrEqual(t, startTs, expectedStart.UnixNano())
	assert.LessOrEqual(t, startTs, expectedEnd.UnixNano())
}

func TestParseTimeRange_RelativeHours(t *testing.T) {
	before := time.Now()
	startTs, _ := parseTimeRange("2h", "now")
	after := time.Now()

	expectedStart := before.Add(-2 * time.Hour)
	expectedEnd := after.Add(-2 * time.Hour)

	assert.GreaterOrEqual(t, startTs, expectedStart.UnixNano())
	assert.LessOrEqual(t, startTs, expectedEnd.UnixNano())
}

func TestParseTimeRange_RelativeDays(t *testing.T) {
	before := time.Now()
	startTs, _ := parseTimeRange("3d", "now")
	after := time.Now()

	expectedStart := before.Add(-3 * 24 * time.Hour)
	expectedEnd := after.Add(-3 * 24 * time.Hour)

	assert.GreaterOrEqual(t, startTs, expectedStart.UnixNano())
	assert.LessOrEqual(t, startTs, expectedEnd.UnixNano())
}

func TestParseTimeRange_AbsoluteRFC3339(t *testing.T) {
	startTs, endTs := parseTimeRange("2026-01-01T00:00:00Z", "2026-01-02T00:00:00Z")

	expectedStart, _ := time.Parse(time.RFC3339, "2026-01-01T00:00:00Z")
	expectedEnd, _ := time.Parse(time.RFC3339, "2026-01-02T00:00:00Z")

	assert.Equal(t, expectedStart.UnixNano(), startTs)
	assert.Equal(t, expectedEnd.UnixNano(), endTs)
}

func TestParseTimeRange_InvalidStartFallsBackTo1Minute(t *testing.T) {
	before := time.Now()
	startTs, _ := parseTimeRange("invalid", "now")
	after := time.Now()

	// Should default to 1 minute ago
	expectedStart := before.Add(-1 * time.Minute)
	expectedEnd := after.Add(-1 * time.Minute)

	assert.GreaterOrEqual(t, startTs, expectedStart.UnixNano())
	assert.LessOrEqual(t, startTs, expectedEnd.UnixNano())
}

func TestParseTimeRange_InvalidEndFallsBackToNow(t *testing.T) {
	before := time.Now()
	_, endTs := parseTimeRange("1m", "not-a-date")
	after := time.Now()

	assert.GreaterOrEqual(t, endTs, before.UnixNano())
	assert.LessOrEqual(t, endTs, after.UnixNano())
}

func TestParseTimeRange_StartBeforeEnd(t *testing.T) {
	startTs, endTs := parseTimeRange("1h", "now")
	assert.Less(t, startTs, endTs)
}

// ---------------------------------------------------------------------------
// buildLogQLQuery
// ---------------------------------------------------------------------------

func TestBuildLogQLQuery_BaseAlwaysHasFreegle(t *testing.T) {
	q := buildLogQLQuery("", "", "", "", "", "", "", "", "", "", "", "")
	assert.Contains(t, q, `app="freegle"`)
	assert.Contains(t, q, "| json")
}

func TestBuildLogQLQuery_SingleSource(t *testing.T) {
	q := buildLogQLQuery("api", "", "", "", "", "", "", "", "", "", "", "")
	assert.Contains(t, q, `source="api"`)
	assert.NotContains(t, q, `source=~`)
}

func TestBuildLogQLQuery_MultipleSources(t *testing.T) {
	q := buildLogQLQuery("api,client", "", "", "", "", "", "", "", "", "", "", "")
	assert.Contains(t, q, `source=~"api|client"`)
}

func TestBuildLogQLQuery_SingleType(t *testing.T) {
	q := buildLogQLQuery("", "Message", "", "", "", "", "", "", "", "", "", "")
	assert.Contains(t, q, `type="Message"`)
}

func TestBuildLogQLQuery_MultipleTypes(t *testing.T) {
	q := buildLogQLQuery("", "Message,User", "", "", "", "", "", "", "", "", "", "")
	assert.Contains(t, q, `type=~"Message|User"`)
}

func TestBuildLogQLQuery_SingleSubtype(t *testing.T) {
	q := buildLogQLQuery("", "", "Approved", "", "", "", "", "", "", "", "", "")
	assert.Contains(t, q, `subtype="Approved"`)
}

func TestBuildLogQLQuery_MultipleSubtypes(t *testing.T) {
	q := buildLogQLQuery("", "", "Approved,Rejected", "", "", "", "", "", "", "", "", "")
	assert.Contains(t, q, `subtype=~"Approved|Rejected"`)
}

func TestBuildLogQLQuery_Level(t *testing.T) {
	q := buildLogQLQuery("", "", "", "error", "", "", "", "", "", "", "", "")
	assert.Contains(t, q, `level="error"`)
}

func TestBuildLogQLQuery_MultipleLevels(t *testing.T) {
	q := buildLogQLQuery("", "", "", "error,warn", "", "", "", "", "", "", "", "")
	assert.Contains(t, q, `level=~"error|warn"`)
}

func TestBuildLogQLQuery_GroupID(t *testing.T) {
	q := buildLogQLQuery("", "", "", "", "", "", "42", "", "", "", "", "")
	assert.Contains(t, q, `groupid="42"`)
}

func TestBuildLogQLQuery_UserID(t *testing.T) {
	q := buildLogQLQuery("", "", "", "", "", "123", "", "", "", "", "", "")
	assert.Contains(t, q, `user_id="123"`)
}

func TestBuildLogQLQuery_MsgID(t *testing.T) {
	q := buildLogQLQuery("", "", "", "", "", "", "", "999", "", "", "", "")
	assert.Contains(t, q, `msgid = 999 or msg_id = 999`)
}

func TestBuildLogQLQuery_TraceID(t *testing.T) {
	q := buildLogQLQuery("", "", "", "", "", "", "", "", "abc123", "", "", "")
	assert.Contains(t, q, `trace_id = "abc123"`)
}

func TestBuildLogQLQuery_SessionID(t *testing.T) {
	q := buildLogQLQuery("", "", "", "", "", "", "", "", "", "sess456", "", "")
	assert.Contains(t, q, `session_id = "sess456"`)
}

func TestBuildLogQLQuery_IPAddress(t *testing.T) {
	q := buildLogQLQuery("", "", "", "", "", "", "", "", "", "", "10.0.0.1", "")
	assert.Contains(t, q, `ip = "10.0.0.1"`)
	assert.Contains(t, q, `ip_address = "10.0.0.1"`)
	assert.Contains(t, q, `client_ip = "10.0.0.1"`)
}

func TestBuildLogQLQuery_Email(t *testing.T) {
	q := buildLogQLQuery("", "", "", "", "", "", "", "", "", "", "", "test@example.com")
	// Email is passed through escapeRegex and added as a regex filter
	assert.Contains(t, q, `|~ "(?i)`)
	assert.Contains(t, q, `example`)
}

func TestBuildLogQLQuery_Search(t *testing.T) {
	q := buildLogQLQuery("", "", "", "", "hello world", "", "", "", "", "", "", "")
	assert.Contains(t, q, `|~ "(?i)hello world"`)
}

func TestBuildLogQLQuery_SearchSpecialChars(t *testing.T) {
	// Special regex chars in search must be escaped
	q := buildLogQLQuery("", "", "", "", "test.com", "", "", "", "", "", "", "")
	assert.Contains(t, q, `test\.com`)
}

func TestBuildLogQLQuery_AllFilters(t *testing.T) {
	q := buildLogQLQuery("api", "Message", "Approved", "info", "hello", "1", "5", "10", "trace1", "sess1", "1.2.3.4", "a@b.com")
	assert.Contains(t, q, `app="freegle"`)
	assert.Contains(t, q, `source="api"`)
	assert.Contains(t, q, `type="Message"`)
	assert.Contains(t, q, `subtype="Approved"`)
	assert.Contains(t, q, `level="info"`)
	assert.Contains(t, q, `groupid="5"`)
	assert.Contains(t, q, `user_id="1"`)
	assert.Contains(t, q, `| json`)
}

// ---------------------------------------------------------------------------
// parseLogEntry
// ---------------------------------------------------------------------------

func TestParseLogEntry_BasicFields(t *testing.T) {
	labels := map[string]string{
		"source":  "api",
		"type":    "Message",
		"subtype": "Approved",
		"level":   "info",
	}
	ts := int64(1700000000000000000) // nanoseconds
	entry := parseLogEntry(ts, `{"text":"hello"}`, labels, 0)

	assert.NotEmpty(t, entry.ID)
	assert.Equal(t, "api", entry.Source)
	assert.Equal(t, "Message", entry.Type)
	assert.Equal(t, "Approved", entry.Subtype)
	assert.Equal(t, "info", entry.Level)
	assert.Equal(t, "hello", entry.Text)
}

func TestParseLogEntry_IDFormat(t *testing.T) {
	labels := map[string]string{"source": "api"}
	ts := int64(1700000000000000000)
	entry := parseLogEntry(ts, `{}`, labels, 3)
	// ID format: "timestamp-source-index"
	assert.Equal(t, "1700000000000000000-api-3", entry.ID)
}

func TestParseLogEntry_TimestampFormat(t *testing.T) {
	labels := map[string]string{"source": "api"}
	ts := int64(1700000000000000000)
	entry := parseLogEntry(ts, `{}`, labels, 0)
	// Should be RFC3339Nano
	_, err := time.Parse(time.RFC3339Nano, entry.Timestamp)
	assert.NoError(t, err)
}

func TestParseLogEntry_UserID(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"user_id": 42}`, labels, 0)
	assert.NotNil(t, entry.UserID)
	assert.Equal(t, uint64(42), *entry.UserID)
}

func TestParseLogEntry_ByUser(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"by_user": 99}`, labels, 0)
	assert.NotNil(t, entry.ByUserID)
	assert.Equal(t, uint64(99), *entry.ByUserID)
}

func TestParseLogEntry_ByUserAlternateKey(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"byuser": 77}`, labels, 0)
	assert.NotNil(t, entry.ByUserID)
	assert.Equal(t, uint64(77), *entry.ByUserID)
}

func TestParseLogEntry_GroupID(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"group_id": 5}`, labels, 0)
	assert.NotNil(t, entry.GroupID)
	assert.Equal(t, uint64(5), *entry.GroupID)
}

func TestParseLogEntry_GroupIDAlternateKey(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"groupid": 12}`, labels, 0)
	assert.NotNil(t, entry.GroupID)
	assert.Equal(t, uint64(12), *entry.GroupID)
}

func TestParseLogEntry_MessageID(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"msg_id": 100}`, labels, 0)
	assert.NotNil(t, entry.MessageID)
	assert.Equal(t, uint64(100), *entry.MessageID)
}

func TestParseLogEntry_MessageIDAlternateKey(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"msgid": 200}`, labels, 0)
	assert.NotNil(t, entry.MessageID)
	assert.Equal(t, uint64(200), *entry.MessageID)
}

func TestParseLogEntry_TraceID(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"trace_id": "abc-123"}`, labels, 0)
	assert.Equal(t, "abc-123", entry.TraceID)
}

func TestParseLogEntry_SessionID(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"session_id": "sess-xyz"}`, labels, 0)
	assert.Equal(t, "sess-xyz", entry.SessionID)
}

func TestParseLogEntry_NonJSONFallsToText(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, "plain text log line", labels, 0)
	assert.Equal(t, "plain text log line", entry.Text)
	assert.Nil(t, entry.Raw)
}

func TestParseLogEntry_ClientSourceEventType(t *testing.T) {
	labels := map[string]string{
		"source":     "client",
		"event_type": "page_view",
	}
	entry := parseLogEntry(0, `{"event_type":"page_view"}`, labels, 0)
	// event_type from labels should override type
	assert.Equal(t, "page_view", entry.Type)
}

func TestParseLogEntry_ClientSourceEventTypeFromLabel(t *testing.T) {
	labels := map[string]string{
		"source":     "client",
		"event_type": "button_click",
	}
	// JSON does not have event_type, should get it from label
	entry := parseLogEntry(0, `{"user_id":1}`, labels, 0)
	assert.Equal(t, "button_click", entry.Type)
	// event_type should be injected into raw
	assert.Equal(t, "button_click", entry.Raw["event_type"])
}

func TestParseLogEntry_NonClientSourceNoEventTypeOverride(t *testing.T) {
	labels := map[string]string{
		"source":     "api",
		"type":       "Message",
		"event_type": "should_be_ignored",
	}
	entry := parseLogEntry(0, `{}`, labels, 0)
	// event_type label only applies to client source
	assert.Equal(t, "Message", entry.Type)
}

func TestParseLogEntry_RawMapPopulated(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{"foo":"bar","num":42}`, labels, 0)
	assert.NotNil(t, entry.Raw)
	assert.Equal(t, "bar", entry.Raw["foo"])
}

func TestParseLogEntry_ZeroTimestamp(t *testing.T) {
	labels := map[string]string{"source": "api"}
	entry := parseLogEntry(0, `{}`, labels, 0)
	assert.NotEmpty(t, entry.Timestamp)
}

// ---------------------------------------------------------------------------
// countBySources
// ---------------------------------------------------------------------------

func TestCountBySources_Empty(t *testing.T) {
	counts := countBySources([]LogEntry{})
	assert.Empty(t, counts)
}

func TestCountBySources_SingleSource(t *testing.T) {
	logs := []LogEntry{
		{Source: "api"},
		{Source: "api"},
		{Source: "api"},
	}
	counts := countBySources(logs)
	assert.Equal(t, map[string]int{"api": 3}, counts)
}

func TestCountBySources_MultipleSources(t *testing.T) {
	logs := []LogEntry{
		{Source: "api"},
		{Source: "client"},
		{Source: "api"},
		{Source: "client"},
		{Source: "client"},
		{Source: "logs_table"},
	}
	counts := countBySources(logs)
	assert.Equal(t, 2, counts["api"])
	assert.Equal(t, 3, counts["client"])
	assert.Equal(t, 1, counts["logs_table"])
}

func TestCountBySources_EmptySourceKey(t *testing.T) {
	logs := []LogEntry{
		{Source: ""},
		{Source: ""},
	}
	counts := countBySources(logs)
	assert.Equal(t, 2, counts[""])
}

// ---------------------------------------------------------------------------
// buildTraceSummaries
// ---------------------------------------------------------------------------

func TestBuildTraceSummaries_EmptyLogs(t *testing.T) {
	summaries := buildTraceSummaries([]LogEntry{}, 10)
	assert.Empty(t, summaries)
}

func TestBuildTraceSummaries_RespectsLimit(t *testing.T) {
	var logs []LogEntry
	for i := 0; i < 20; i++ {
		ts := time.Now().Add(time.Duration(i) * time.Second).Format(time.RFC3339Nano)
		logs = append(logs, LogEntry{
			ID:        "log" + string(rune('0'+i)),
			Source:    "api",
			Timestamp: ts,
		})
	}
	summaries := buildTraceSummaries(logs, 5)
	assert.LessOrEqual(t, len(summaries), 5)
}

func TestBuildTraceSummaries_LogsWithTraceID(t *testing.T) {
	ts1 := "2026-01-01T10:00:00Z"
	ts2 := "2026-01-01T10:00:01Z"
	logs := []LogEntry{
		{ID: "1", TraceID: "trace-abc", Source: "api", Timestamp: ts1},
		{ID: "2", TraceID: "trace-abc", Source: "client", Timestamp: ts2},
	}
	summaries := buildTraceSummaries(logs, 10)
	// Two logs with same trace_id → one summary
	assert.Len(t, summaries, 1)
	assert.Equal(t, "trace-abc", summaries[0].TraceID)
	assert.Equal(t, 2, summaries[0].ChildCount)
}

func TestBuildTraceSummaries_LogsWithoutTraceID(t *testing.T) {
	ts := "2026-01-01T10:00:00Z"
	logs := []LogEntry{
		{ID: "1", Source: "api", Timestamp: ts},
		{ID: "2", Source: "client", Timestamp: ts},
	}
	summaries := buildTraceSummaries(logs, 10)
	// Each standalone log becomes its own summary
	assert.Len(t, summaries, 2)
}

func TestBuildTraceSummaries_MixedTraceAndStandalone(t *testing.T) {
	ts := "2026-01-01T10:00:00Z"
	ts2 := "2026-01-01T10:00:01Z"
	logs := []LogEntry{
		{ID: "1", TraceID: "trace-1", Source: "api", Timestamp: ts},
		{ID: "2", TraceID: "trace-1", Source: "client", Timestamp: ts2},
		{ID: "3", Source: "api", Timestamp: ts},
	}
	summaries := buildTraceSummaries(logs, 10)
	// One trace group + one standalone = 2 summaries
	assert.Len(t, summaries, 2)
}

func TestBuildTraceSummaries_SortedByTimestampDescending(t *testing.T) {
	logs := []LogEntry{
		{ID: "1", Source: "api", Timestamp: "2026-01-01T09:00:00Z"},
		{ID: "2", Source: "api", Timestamp: "2026-01-01T11:00:00Z"},
		{ID: "3", Source: "api", Timestamp: "2026-01-01T10:00:00Z"},
	}
	summaries := buildTraceSummaries(logs, 10)
	assert.Len(t, summaries, 3)
	// Most recent first
	assert.Equal(t, "2026-01-01T11:00:00Z", summaries[0].FirstTimestamp)
	assert.Equal(t, "2026-01-01T10:00:00Z", summaries[1].FirstTimestamp)
	assert.Equal(t, "2026-01-01T09:00:00Z", summaries[2].FirstTimestamp)
}

// ---------------------------------------------------------------------------
// buildSingleTraceSummary
// ---------------------------------------------------------------------------

func TestBuildSingleTraceSummary_ChildCount(t *testing.T) {
	logs := []LogEntry{
		{ID: "1", Source: "api", Timestamp: "2026-01-01T10:00:00Z"},
		{ID: "2", Source: "client", Timestamp: "2026-01-01T10:00:01Z"},
		{ID: "3", Source: "api", Timestamp: "2026-01-01T10:00:02Z"},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	assert.Equal(t, "trace-x", summary.TraceID)
	assert.Equal(t, 3, summary.ChildCount)
}

func TestBuildSingleTraceSummary_FirstAndLastTimestamp(t *testing.T) {
	logs := []LogEntry{
		{ID: "1", Source: "api", Timestamp: "2026-01-01T10:00:02Z"},
		{ID: "2", Source: "api", Timestamp: "2026-01-01T10:00:00Z"},
		{ID: "3", Source: "api", Timestamp: "2026-01-01T10:00:04Z"},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	assert.Equal(t, "2026-01-01T10:00:00Z", summary.FirstTimestamp)
	assert.Equal(t, "2026-01-01T10:00:04Z", summary.LastTimestamp)
}

func TestBuildSingleTraceSummary_UniqueSources(t *testing.T) {
	logs := []LogEntry{
		{ID: "1", Source: "api", Timestamp: "2026-01-01T10:00:00Z"},
		{ID: "2", Source: "api", Timestamp: "2026-01-01T10:00:01Z"},
		{ID: "3", Source: "client", Timestamp: "2026-01-01T10:00:02Z"},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	// Should have 2 unique sources (api, client)
	assert.Len(t, summary.Sources, 2)
	assert.Contains(t, summary.Sources, "api")
	assert.Contains(t, summary.Sources, "client")
}

func TestBuildSingleTraceSummary_PrefersClientPageViewAsParent(t *testing.T) {
	raw := map[string]interface{}{"event_type": "page_view"}
	logs := []LogEntry{
		{ID: "api-1", Source: "api", Timestamp: "2026-01-01T10:00:00Z"},
		{ID: "client-1", Source: "client", Timestamp: "2026-01-01T10:00:01Z", Raw: raw},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	assert.Equal(t, "client-1", summary.FirstLog.ID)
}

func TestBuildSingleTraceSummary_FallsBackToAPIIfNoClient(t *testing.T) {
	logs := []LogEntry{
		{ID: "api-1", Source: "api", Timestamp: "2026-01-01T10:00:00Z"},
		{ID: "logs-1", Source: "logs_table", Timestamp: "2026-01-01T10:00:01Z"},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	assert.Equal(t, "api-1", summary.FirstLog.ID)
}

func TestBuildSingleTraceSummary_FallsBackToFirstLogIfNoClientOrAPI(t *testing.T) {
	logs := []LogEntry{
		{ID: "logs-2", Source: "logs_table", Timestamp: "2026-01-01T10:00:01Z"},
		{ID: "logs-1", Source: "logs_table", Timestamp: "2026-01-01T10:00:00Z"},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	// First log after sorting by timestamp
	assert.Equal(t, "logs-1", summary.FirstLog.ID)
}

func TestBuildSingleTraceSummary_RouteSummaryFromPageName(t *testing.T) {
	raw := map[string]interface{}{"page_name": "/browse"}
	logs := []LogEntry{
		{ID: "client-1", Source: "client", Timestamp: "2026-01-01T10:00:00Z", Raw: raw},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	assert.Contains(t, summary.RouteSummary, "/browse")
}

func TestBuildSingleTraceSummary_RouteSummaryFromURL(t *testing.T) {
	raw := map[string]interface{}{"url": "https://www.ilovefreegle.org/browse?lat=51.5"}
	logs := []LogEntry{
		{ID: "client-1", Source: "client", Timestamp: "2026-01-01T10:00:00Z", Raw: raw},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	assert.Len(t, summary.RouteSummary, 1)
	assert.Equal(t, "/browse?lat=51.5", summary.RouteSummary[0])
}

func TestBuildSingleTraceSummary_RouteSummaryNoDuplicates(t *testing.T) {
	raw := map[string]interface{}{"page_name": "/browse"}
	logs := []LogEntry{
		{ID: "client-1", Source: "client", Timestamp: "2026-01-01T10:00:00Z", Raw: raw},
		{ID: "client-2", Source: "client", Timestamp: "2026-01-01T10:00:01Z", Raw: raw},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	// Consecutive duplicates should be deduplicated
	assert.Len(t, summary.RouteSummary, 1)
}

func TestBuildSingleTraceSummary_RouteSummaryDifferentPaths(t *testing.T) {
	raw1 := map[string]interface{}{"page_name": "/browse"}
	raw2 := map[string]interface{}{"page_name": "/post"}
	logs := []LogEntry{
		{ID: "client-1", Source: "client", Timestamp: "2026-01-01T10:00:00Z", Raw: raw1},
		{ID: "client-2", Source: "client", Timestamp: "2026-01-01T10:00:01Z", Raw: raw2},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	assert.Len(t, summary.RouteSummary, 2)
	assert.Equal(t, "/browse", summary.RouteSummary[0])
	assert.Equal(t, "/post", summary.RouteSummary[1])
}

func TestBuildSingleTraceSummary_RouteSummaryRelativePath(t *testing.T) {
	// Page names without leading slash should get one prepended.
	raw := map[string]interface{}{"page_name": "browse"}
	logs := []LogEntry{
		{ID: "client-1", Source: "client", Timestamp: "2026-01-01T10:00:00Z", Raw: raw},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	assert.Len(t, summary.RouteSummary, 1)
	assert.True(t, strings.HasPrefix(summary.RouteSummary[0], "/"))
}

func TestBuildSingleTraceSummary_NonClientLogsSkippedInRouteSummary(t *testing.T) {
	raw := map[string]interface{}{"page_name": "/api-route"}
	logs := []LogEntry{
		{ID: "api-1", Source: "api", Timestamp: "2026-01-01T10:00:00Z", Raw: raw},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	// API logs don't contribute to route summary
	assert.Empty(t, summary.RouteSummary)
}

func TestBuildSingleTraceSummary_ClientLogNoRaw(t *testing.T) {
	logs := []LogEntry{
		{ID: "client-1", Source: "client", Timestamp: "2026-01-01T10:00:00Z", Raw: nil},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	// No raw → no route summary, but no panic
	assert.Empty(t, summary.RouteSummary)
}

func TestBuildSingleTraceSummary_SingleLog(t *testing.T) {
	logs := []LogEntry{
		{ID: "api-1", Source: "api", Timestamp: "2026-01-01T10:00:00Z"},
	}
	summary := buildSingleTraceSummary("trace-x", logs)
	assert.Equal(t, 1, summary.ChildCount)
	assert.Equal(t, "2026-01-01T10:00:00Z", summary.FirstTimestamp)
	assert.Equal(t, "2026-01-01T10:00:00Z", summary.LastTimestamp)
}
