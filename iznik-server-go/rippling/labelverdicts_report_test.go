package rippling

import (
	"context"
	"testing"
	"time"

	"github.com/getsentry/sentry-go"
	"github.com/stretchr/testify/assert"
)

type reportRecorder struct{ events []*sentry.Event }

func (r *reportRecorder) Configure(sentry.ClientOptions)        {}
func (r *reportRecorder) SendEvent(e *sentry.Event)             { r.events = append(r.events, e) }
func (r *reportRecorder) Flush(time.Duration) bool              { return true }
func (r *reportRecorder) FlushWithContext(context.Context) bool { return true }
func (r *reportRecorder) Close()                                {}

func recordSentry(t *testing.T) *reportRecorder {
	rec := &reportRecorder{}
	client, err := sentry.NewClient(sentry.ClientOptions{Transport: rec})
	assert.NoError(t, err)
	hub := sentry.CurrentHub()
	old := hub.Client()
	hub.BindClient(client)
	reachAlertMu.Lock()
	reachAlertLast = time.Time{}
	reachAlertMu.Unlock()
	t.Cleanup(func() {
		hub.BindClient(old)
		reachAlertMu.Lock()
		reachAlertLast = time.Time{}
		reachAlertMu.Unlock()
	})
	return rec
}

// How long the call took and how many ids it carried travel as extras, not in the message, so
// reports of the same failure keep grouping together.
func TestReportEvalUnavailableCarriesElapsedAndCountAsExtras(t *testing.T) {
	rec := recordSentry(t)

	reportEvalUnavailableAfter("routing server unreachable: timeout", 3010*time.Millisecond, 412)

	if assert.Len(t, rec.events, 1) {
		ev := rec.events[0]
		assert.Equal(t, "Rippling reach evaluation unavailable: routing server unreachable: timeout", ev.Message)
		assert.EqualValues(t, 3010, ev.Extra["elapsed_ms"])
		assert.EqualValues(t, 412, ev.Extra["msgids"])
	}
}

func TestReportEvalUnavailableStillRateLimited(t *testing.T) {
	rec := recordSentry(t)

	reportEvalUnavailable("one")
	reportEvalUnavailable("two")

	assert.Len(t, rec.events, 1)
}
