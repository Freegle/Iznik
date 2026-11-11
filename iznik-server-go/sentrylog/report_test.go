package sentrylogpackage

import (
	"context"
	"errors"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/getsentry/sentry-go"
	"github.com/go-sql-driver/mysql"
	"github.com/stretchr/testify/assert"
	logger2 "gorm.io/gorm/logger"
)

// recordingTransport collects the events the SDK would have sent.
type recordingTransport struct{ events []*sentry.Event }

func (r *recordingTransport) Configure(sentry.ClientOptions)        {}
func (r *recordingTransport) SendEvent(e *sentry.Event)             { r.events = append(r.events, e) }
func (r *recordingTransport) Flush(time.Duration) bool              { return true }
func (r *recordingTransport) FlushWithContext(context.Context) bool { return true }
func (r *recordingTransport) Close()                                {}

func withRecorder(t *testing.T) *recordingTransport {
	rec := &recordingTransport{}
	client, err := sentry.NewClient(sentry.ClientOptions{Transport: rec})
	assert.NoError(t, err)
	hub := sentry.CurrentHub()
	old := hub.Client()
	hub.BindClient(client)
	t.Cleanup(func() { hub.BindClient(old) })
	return rec
}

func TestIsDeadlock(t *testing.T) {
	assert.True(t, IsDeadlock(&mysql.MySQLError{Number: 1213, Message: "Deadlock found when trying to get lock; try restarting transaction"}))
	assert.True(t, IsDeadlock(fmt.Errorf("wrapped: %w", &mysql.MySQLError{Number: 1205, Message: "Lock wait timeout exceeded"})))
	// By number, not wording: a message that happens to say "deadlock" is not one.
	assert.False(t, IsDeadlock(errors.New("Error 1213 (40001): Deadlock found")))
	assert.False(t, IsDeadlock(&mysql.MySQLError{Number: 1062, Message: "Duplicate entry 'deadlock' for key 'email'"}))
	assert.False(t, IsDeadlock(context.DeadlineExceeded))
	assert.False(t, IsDeadlock(nil))
}

func TestSQLShapeRemovesValues(t *testing.T) {
	shape := SQLShape("SELECT id FROM users_emails WHERE email = 'someone@example.com' AND userid = 12345")
	assert.NotContains(t, shape, "someone")
	assert.NotContains(t, shape, "12345")
	assert.Contains(t, shape, "users_emails")
	assert.LessOrEqual(t, len(SQLShape(strings.Repeat("a ", 400))), 200)
}

func TestSQLShapeRemovesEveryKindOfLiteral(t *testing.T) {
	shape := SQLShape(`SELECT id FROM t WHERE a = "person@example.com" AND b = 0xDEADBEEF AND c = 12.50 AND d = 7 AND e = x'41'`)
	for _, leaked := range []string{"person", "DEADBEEF", "12.50", "12", "x'41'"} {
		assert.NotContains(t, shape, leaked)
	}
	assert.Contains(t, shape, "FROM t")
}

func TestScrubMessageRemovesTheQuotedValue(t *testing.T) {
	msg := ScrubMessage("Error 1062 (23000): Duplicate entry 'person@example.com' for key 'users_emails.email'")
	assert.NotContains(t, msg, "person")
	assert.Equal(t, "Error 1062 (23000): Duplicate entry '?' for key 'users_emails.email'", msg)

	// A value with a quote in it is still removed whole.
	quoted := ScrubMessage("Duplicate entry 'o'brien@example.com' for key 'email'")
	assert.NotContains(t, quoted, "brien")

	bad := ScrubMessage("Incorrect string value: '\\xF0\\x9F' for column 'fullname' at row 1")
	assert.NotContains(t, bad, "xF0")

	assert.Equal(t, "context deadline exceeded", ScrubMessage("context deadline exceeded"))
}

func TestTraceSendsNoAddressToSentry(t *testing.T) {
	rec := withRecorder(t)
	l := newTestLogger(logger2.Error, 0, true)

	captureStdout(func() {
		l.Trace(context.Background(), time.Now(), func() (string, int64) { return "INSERT INTO users_emails (email) VALUES ('person@example.com')", 0 },
			&mysql.MySQLError{Number: 1062, Message: "Duplicate entry 'person@example.com' for key 'email'"})
	})

	if assert.Len(t, rec.events, 1) {
		assert.NotContains(t, rec.events[0].Message, "person")
		shape, _ := rec.events[0].Extra["sql_shape"].(string)
		assert.NotContains(t, shape, "person")
	}
}

func TestTraceKeepsDeadlocksOutOfSentry(t *testing.T) {
	rec := withRecorder(t)
	l := newTestLogger(logger2.Error, 0, true)

	out := captureStdout(func() {
		l.Trace(context.Background(), time.Now(), func() (string, int64) { return "UPDATE x SET y = 1", 0 },
			&mysql.MySQLError{Number: 1213, Message: "Deadlock found when trying to get lock"})
	})

	assert.Empty(t, rec.events)
	assert.Contains(t, out, "DEADLOCK")
}

func TestTraceReportsOtherErrorsWithTagsAndShape(t *testing.T) {
	rec := withRecorder(t)
	l := newTestLogger(logger2.Error, 0, true)
	ctx := WithTags(context.Background(), map[string]string{"component": "popularposts"})

	captureStdout(func() {
		l.Trace(ctx, time.Now(), func() (string, int64) { return "SELECT 1 FROM t WHERE a = 'secret'", 0 },
			context.DeadlineExceeded)
	})

	if assert.Len(t, rec.events, 1) {
		ev := rec.events[0]
		assert.Equal(t, "popularposts", ev.Tags["component"])
		shape, _ := ev.Extra["sql_shape"].(string)
		assert.Contains(t, shape, "FROM t")
		assert.NotContains(t, shape, "secret")
	}
}

func TestWithTagsMerges(t *testing.T) {
	ctx := WithTags(context.Background(), map[string]string{"a": "1"})
	ctx = WithTags(ctx, map[string]string{"b": "2"})
	assert.Equal(t, map[string]string{"a": "1", "b": "2"}, TagsFrom(ctx))
}
