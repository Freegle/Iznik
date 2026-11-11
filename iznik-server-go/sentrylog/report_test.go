package sentrylogpackage

import (
	"context"
	"errors"
	"strings"
	"testing"
	"time"

	"github.com/getsentry/sentry-go"
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
	assert.True(t, IsDeadlock(errors.New("Error 1213 (40001): Deadlock found when trying to get lock; try restarting transaction")))
	assert.True(t, IsDeadlock(errors.New("Error 1205: Lock wait timeout exceeded")))
	assert.False(t, IsDeadlock(errors.New("context deadline exceeded")))
	assert.False(t, IsDeadlock(nil))
}

func TestSQLShapeRemovesValues(t *testing.T) {
	shape := SQLShape("SELECT id FROM users_emails WHERE email = 'someone@example.com' AND userid = 12345")
	assert.NotContains(t, shape, "someone")
	assert.NotContains(t, shape, "12345")
	assert.Contains(t, shape, "users_emails")
	assert.LessOrEqual(t, len(SQLShape(strings.Repeat("a ", 400))), 200)
}

func TestTraceKeepsDeadlocksOutOfSentry(t *testing.T) {
	rec := withRecorder(t)
	l := newTestLogger(logger2.Error, 0, true)

	out := captureStdout(func() {
		l.Trace(context.Background(), time.Now(), func() (string, int64) { return "UPDATE x SET y = 1", 0 },
			errors.New("Error 1213 (40001): Deadlock found when trying to get lock"))
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
