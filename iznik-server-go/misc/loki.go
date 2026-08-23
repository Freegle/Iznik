package misc

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"
)

// LokiClient handles logging to JSON files that Alloy ships to Grafana Loki.
// This approach is resilient (survives Loki downtime) and non-blocking.
type LokiClient struct {
	enabled      bool
	jsonFilePath string
	fileMutex    sync.Mutex
	currentFile  *os.File
	currentDate  string
	goroutineWg  sync.WaitGroup
}

// lokiJsonLogEntry is the JSON structure written to log files for Alloy to pick up.
type lokiJsonLogEntry struct {
	Timestamp string            `json:"timestamp"`
	Labels    map[string]string `json:"labels"`
	Message   json.RawMessage   `json:"message"`
}

var lokiInstance *LokiClient
var lokiOnce sync.Once

// GetLoki returns the singleton Loki client instance.
func GetLoki() *LokiClient {
	lokiOnce.Do(func() {
		enabled := os.Getenv("LOKI_ENABLED") == "true" || os.Getenv("LOKI_ENABLED") == "1"
		jsonFilePath := os.Getenv("LOKI_JSON_PATH")

		if enabled && jsonFilePath == "" {
			fmt.Println("Loki enabled but LOKI_JSON_PATH not set, disabling Loki")
			enabled = false
		}

		lokiInstance = &LokiClient{
			enabled:      enabled,
			jsonFilePath: jsonFilePath,
		}

		if enabled {
			if err := os.MkdirAll(jsonFilePath, 0755); err != nil {
				fmt.Printf("Failed to create Loki log directory %s: %v\n", jsonFilePath, err)
			} else {
				fmt.Printf("Loki JSON file logging enabled, writing to %s\n", jsonFilePath)
			}
		}
	})
	return lokiInstance
}

// IsEnabled returns whether Loki logging is enabled.
func (l *LokiClient) IsEnabled() bool {
	return l.enabled
}

// maxStringLength is the maximum length for logged string values.
const maxStringLength = 32

// Loki refuses any entry longer than its max_line_size (256KB by default) and,
// with max_line_size_truncate off, discards the WHOLE entry rather than clipping
// it - so an oversized line loses the endpoint, status, duration and user_id too,
// not just the body. Truncating strings alone does not bound a line: a response
// body that is an array of several thousand small objects passes through
// truncateMap untouched, because nothing capped the number of ELEMENTS. That is
// how a single /api/changes response reached 1.85MB and was dropped on the floor.
//
// So bound both dimensions: collection sizes here, and the marshalled line as a
// whole in capLogLine below. maxLogLineBytes sits well under Loki's 256KB so the
// labels and the rest of the JSON envelope still fit.
const (
	maxSliceElements = 32
	maxMapKeys       = 64
	maxValueDepth    = 8
	maxLogLineBytes  = 192 * 1024
)

// truncateString truncates a string to maxStringLength characters.
func truncateString(s string) string {
	if len(s) <= maxStringLength {
		return s
	}
	return s[:maxStringLength] + "..."
}

// truncateMap recursively truncates all string values in a map, and caps the
// number of keys kept so a pathologically wide object cannot blow up the line.
func truncateMap(data map[string]interface{}) map[string]interface{} {
	return truncateMapDepth(data, 0)
}

func truncateMapDepth(data map[string]interface{}, depth int) map[string]interface{} {
	result := make(map[string]interface{}, len(data))

	if depth >= maxValueDepth {
		result["_truncated"] = fmt.Sprintf("depth limit %d reached", maxValueDepth)
		return result
	}

	// Map iteration order is random in Go, so when we have to drop keys the ones
	// we keep would otherwise differ run to run and make logs hard to compare.
	// Sort so the same object always logs the same way.
	keys := make([]string, 0, len(data))
	for k := range data {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	kept := 0
	for _, k := range keys {
		if kept >= maxMapKeys {
			result["_truncated"] = fmt.Sprintf("%d more keys", len(keys)-kept)
			break
		}
		result[k] = truncateValueDepth(data[k], depth+1)
		kept++
	}

	return result
}

// truncateValue truncates a value if it's a string, or recursively processes maps/slices.
func truncateValue(v interface{}) interface{} {
	return truncateValueDepth(v, 0)
}

func truncateValueDepth(v interface{}, depth int) interface{} {
	if depth >= maxValueDepth {
		return fmt.Sprintf("...(depth limit %d reached)", maxValueDepth)
	}

	switch val := v.(type) {
	case string:
		return truncateString(val)
	case map[string]interface{}:
		return truncateMapDepth(val, depth)
	case []interface{}:
		n := len(val)
		keep := n
		if keep > maxSliceElements {
			keep = maxSliceElements
		}

		result := make([]interface{}, 0, keep+1)
		for i := 0; i < keep; i++ {
			result = append(result, truncateValueDepth(val[i], depth+1))
		}
		if n > keep {
			result = append(result, fmt.Sprintf("...(%d more elements)", n-keep))
		}
		return result
	default:
		return v
	}
}

// capLogLine is the last line of defence: whatever the shape of the payload, the
// entry we hand to Loki must be under max_line_size or Loki throws the entry away
// entirely. Drop the bulky bodies - which are the only unbounded parts - and keep
// the fields that make the entry worth having, recording what was dropped so the
// gap is visible in the logs rather than silent.
func capLogLine(logData map[string]interface{}) []byte {
	line, err := json.Marshal(logData)
	if err != nil {
		return nil
	}
	if len(line) <= maxLogLineBytes {
		return line
	}

	for _, field := range []string{"response_body", "request_body", "request_headers", "response_headers"} {
		if v, ok := logData[field]; ok {
			if encoded, e := json.Marshal(v); e == nil {
				logData[field] = fmt.Sprintf("...(omitted, %d bytes, line over %d limit)", len(encoded), maxLogLineBytes)
			} else {
				logData[field] = "...(omitted)"
			}
		}

		line, err = json.Marshal(logData)
		if err != nil {
			return nil
		}
		if len(line) <= maxLogLineBytes {
			return line
		}
	}

	// Still too big - something other than the bodies is huge. Hard-clip so we
	// emit a valid, in-limit JSON entry instead of losing the request entirely.
	minimal := map[string]interface{}{
		"endpoint":    logData["endpoint"],
		"duration_ms": logData["duration_ms"],
		"user_id":     logData["user_id"],
		"timestamp":   logData["timestamp"],
		"request_id":  logData["request_id"],
		"_truncated":  fmt.Sprintf("entry exceeded %d bytes", maxLogLineBytes),
	}
	if line, err = json.Marshal(minimal); err != nil {
		return nil
	}
	return line
}

// LogApiRequest logs an API request to Loki.
func (l *LokiClient) LogApiRequest(version, method, endpoint string, statusCode int, durationMs float64, userId *uint64, extra map[string]string) {
	if !l.enabled {
		return
	}

	// Determine log level: only 5xx errors are "error", everything else is "info".
	// 401/403 are normal for unauthenticated requests.
	level := "info"
	if statusCode >= 500 {
		level = "error"
	}

	labels := map[string]string{
		"app":         "freegle",
		"source":      "api",
		"api_version": version,
		"method":      method,
		"status_code": strconv.Itoa(statusCode),
		"level":       level,
	}

	// Add user_id as label for indexed queries.
	if userId != nil && *userId != 0 {
		labels["user_id"] = strconv.FormatUint(*userId, 10)
	}

	logData := map[string]interface{}{
		"endpoint":    endpoint,
		"duration_ms": durationMs,
		"user_id":     userId,
		"timestamp":   time.Now().Format(time.RFC3339),
	}

	for k, v := range extra {
		logData[k] = v
	}

	logLine := capLogLine(logData)
	if logLine == nil {
		return
	}
	l.log(labels, string(logLine))
}

// LogApiRequestFull logs an API request with full request/response data.
func (l *LokiClient) LogApiRequestFull(version, method, endpoint string, statusCode int, durationMs float64, userId *uint64, extra map[string]string, queryParams map[string]string, requestBody, responseBody map[string]interface{}) {
	if !l.enabled {
		return
	}

	// Determine log level: only 5xx errors are "error", everything else is "info".
	// 401/403 are normal for unauthenticated requests.
	level := "info"
	if statusCode >= 500 {
		level = "error"
	}

	labels := map[string]string{
		"app":         "freegle",
		"source":      "api",
		"api_version": version,
		"method":      method,
		"status_code": strconv.Itoa(statusCode),
		"level":       level,
	}

	// Add user_id as label for indexed queries (low-ish cardinality).
	// Note: trace_id and session_id stay in JSON body only (high cardinality).
	if userId != nil && *userId != 0 {
		labels["user_id"] = strconv.FormatUint(*userId, 10)
	}

	logData := map[string]interface{}{
		"endpoint":    endpoint,
		"duration_ms": durationMs,
		"user_id":     userId,
		"timestamp":   time.Now().Format(time.RFC3339),
	}

	for k, v := range extra {
		logData[k] = v
	}

	// Add query parameters (truncated).
	if len(queryParams) > 0 {
		truncatedParams := make(map[string]string)
		for k, v := range queryParams {
			truncatedParams[k] = truncateString(v)
		}
		logData["query_params"] = truncatedParams
	}

	// Add request body (truncated).
	if len(requestBody) > 0 {
		logData["request_body"] = truncateMap(requestBody)
	}

	// Add response body (truncated).
	if len(responseBody) > 0 {
		logData["response_body"] = truncateMap(responseBody)
	}

	logLine := capLogLine(logData)
	if logLine == nil {
		return
	}
	l.log(labels, string(logLine))
}

// Sensitive header patterns to exclude from logging.
var sensitiveHeaderPatterns = []string{
	"authorization",
	"cookie",
	"set-cookie",
	"x-api-key",
}

// Allowed request headers (allowlist approach).
var allowedRequestHeaders = map[string]bool{
	"user-agent":        true,
	"referer":           true,
	"content-type":      true,
	"accept":            true,
	"accept-language":   true,
	"accept-encoding":   true,
	"x-forwarded-for":   true,
	"x-forwarded-proto": true,
	"x-request-id":      true,
	"x-real-ip":         true,
	"origin":            true,
	"host":              true,
	"content-length":    true,
	// Logging context headers.
	"x-freegle-session": true,
	"x-freegle-page":    true,
	"x-freegle-modal":   true,
	"x-freegle-site":    true,
}

// LogApiHeaders logs API headers to Loki (separate stream with 7-day retention).
func (l *LokiClient) LogApiHeaders(version, method, endpoint string, requestHeaders, responseHeaders map[string]string, userId *uint64, requestId string) {
	if !l.enabled {
		return
	}

	labels := map[string]string{
		"app":         "freegle",
		"source":      "api_headers",
		"api_version": version,
		"method":      method,
	}

	logData := map[string]interface{}{
		"endpoint":         endpoint,
		"user_id":          userId,
		"request_id":       requestId,
		"request_headers":  filterHeaders(requestHeaders, true),
		"response_headers": filterHeaders(responseHeaders, false),
		"timestamp":        time.Now().Format(time.RFC3339),
	}

	logLine := capLogLine(logData)
	if logLine == nil {
		return
	}
	l.log(labels, string(logLine))
}

// filterHeaders removes sensitive headers and applies allowlist for request headers.
func filterHeaders(headers map[string]string, useAllowlist bool) map[string]string {
	filtered := make(map[string]string)

	for name, value := range headers {
		nameLower := strings.ToLower(name)

		// Check against sensitive patterns.
		isSensitive := false
		for _, pattern := range sensitiveHeaderPatterns {
			if strings.Contains(nameLower, pattern) {
				isSensitive = true
				break
			}
		}

		if isSensitive {
			continue
		}

		// For request headers, use allowlist.
		if useAllowlist {
			if allowedRequestHeaders[nameLower] {
				filtered[name] = value
			}
		} else {
			// For response headers, include all non-sensitive.
			filtered[name] = value
		}
	}

	return filtered
}

// LogFromLogsTable logs entries that mirror the logs table to Loki.
func (l *LokiClient) LogFromLogsTable(logType, subtype string, groupId, userId, byUser, msgId *uint64, text string) {
	if !l.enabled {
		return
	}

	labels := map[string]string{
		"app":     "freegle",
		"source":  "logs_table",
		"type":    logType,
		"subtype": subtype,
	}

	if groupId != nil {
		labels["groupid"] = strconv.FormatUint(*groupId, 10)
	}
	if userId != nil && *userId != 0 {
		labels["user_id"] = strconv.FormatUint(*userId, 10)
	}

	logData := map[string]interface{}{
		"user_id":   userId,
		"by_user":   byUser,
		"msg_id":    msgId,
		"group_id":  groupId,
		"text":      text,
		"timestamp": time.Now().Format(time.RFC3339),
	}

	logLine := capLogLine(logData)
	if logLine == nil {
		return
	}
	l.log(labels, string(logLine))
}

// LogClientEntry logs entries from the client-side browser to Loki.
func (l *LokiClient) LogClientEntry(level, eventType string, logData map[string]interface{}) {
	if !l.enabled {
		return
	}

	labels := map[string]string{
		"app":        "freegle",
		"source":     "client",
		"level":      level,
		"event_type": eventType,
	}

	// Add user_id as label for indexed queries (low-ish cardinality).
	// Note: trace_id and session_id stay in JSON body only (high cardinality).
	if userID, ok := logData["user_id"].(float64); ok && userID != 0 {
		labels["user_id"] = strconv.FormatInt(int64(userID), 10)
	}

	logLine := capLogLine(logData)
	if logLine == nil {
		return
	}
	l.log(labels, string(logLine))
}

// log writes a log entry to a JSON file for Alloy to ship.
func (l *LokiClient) log(labels map[string]string, logLine string) {
	if !l.enabled {
		return
	}

	now := time.Now()
	timestamp := now.Format(time.RFC3339Nano)
	dateStr := now.Format("2006-01-02")

	entry := lokiJsonLogEntry{
		Timestamp: timestamp,
		Labels:    labels,
		Message:   json.RawMessage(logLine),
	}

	jsonBytes, err := json.Marshal(entry)
	if err != nil {
		fmt.Printf("Loki JSON marshal error: %v\n", err)
		return
	}

	// Add newline for JSON lines format.
	jsonBytes = append(jsonBytes, '\n')

	l.fileMutex.Lock()
	defer l.fileMutex.Unlock()

	// Rotate file daily.
	if l.currentFile == nil || l.currentDate != dateStr {
		if l.currentFile != nil {
			l.currentFile.Close()
		}

		filename := filepath.Join(l.jsonFilePath, fmt.Sprintf("go-api-%s.log", dateStr))
		file, err := os.OpenFile(filename, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
		if err != nil {
			fmt.Printf("Loki file open error: %v\n", err)
			return
		}

		l.currentFile = file
		l.currentDate = dateStr
	}

	if _, err := l.currentFile.Write(jsonBytes); err != nil {
		fmt.Printf("Loki file write error: %v\n", err)
	}
}

// LogCustom emits a structured diagnostic log under a caller-chosen source label.
// Use sparingly — intended for targeted instrumentation (e.g. vector search stats)
// that doesn't fit the generic API request/response shape.
func (l *LokiClient) LogCustom(source string, extraLabels map[string]string, data map[string]interface{}) {
	if !l.enabled {
		return
	}

	labels := map[string]string{
		"app":    "freegle",
		"source": source,
	}
	for k, v := range extraLabels {
		labels[k] = v
	}

	if _, ok := data["timestamp"]; !ok {
		data["timestamp"] = time.Now().Format(time.RFC3339Nano)
	}

	logLine := capLogLine(data)
	if logLine == nil {
		return
	}
	l.log(labels, string(logLine))
}

// LogChatReply logs a chat reply event with source tracking for dashboard analytics.
// Sources: "amp" (AMP email form), "email" (email reply), "website" (web interface)
func (l *LokiClient) LogChatReply(source string, chatID, userID uint64, messageID *uint64, emailTrackingID *uint64) {
	if !l.enabled {
		return
	}

	labels := map[string]string{
		"app":          "freegle",
		"source":       "chat_reply",
		"reply_source": source,
		"user_id":      strconv.FormatUint(userID, 10),
	}

	logData := map[string]interface{}{
		"reply_source":      source,
		"chat_id":           chatID,
		"user_id":           userID,
		"message_id":        messageID,
		"email_tracking_id": emailTrackingID,
		"timestamp":         time.Now().Format(time.RFC3339),
	}

	logLine := capLogLine(logData)
	if logLine == nil {
		return
	}
	l.log(labels, string(logLine))
}

// Flush waits for all in-flight async log goroutines to complete.
// Call in tests after app.Test() to ensure goroutines finish before
// t.TempDir() cleanup removes the log directory.
func (l *LokiClient) Flush() {
	l.goroutineWg.Wait()
}

// Close gracefully shuts down the Loki client.
func (l *LokiClient) Close() {
	if l.enabled {
		l.fileMutex.Lock()
		if l.currentFile != nil {
			l.currentFile.Close()
			l.currentFile = nil
		}
		l.fileMutex.Unlock()
	}
}

// Drain waits for all in-flight async log goroutines to complete.
// Call this in tests before cleaning up temporary directories used as LOKI_JSON_PATH.
func (l *LokiClient) Drain() {
	l.goroutineWg.Wait()
}
