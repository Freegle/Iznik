package sentrylogpackage

import (
	"bytes"
	"context"
	"errors"
	"io"
	"os"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
	"gorm.io/gorm"
	logger2 "gorm.io/gorm/logger"
)

// Silence the unused-import linter if errors package stops being referenced.
var _ = errors.New

// captureStdout redirects os.Stdout while fn runs and returns what was written.
// Used because the logger writes via fmt.Printf directly to stdout.
func captureStdout(fn func()) string {
	orig := os.Stdout
	r, w, _ := os.Pipe()
	os.Stdout = w

	done := make(chan string, 1)
	go func() {
		var buf bytes.Buffer
		_, _ = io.Copy(&buf, r)
		done <- buf.String()
	}()

	fn()

	_ = w.Close()
	os.Stdout = orig
	return <-done
}

// newTestLogger builds a logger without going through New() (which calls
// sentry.Init and prints setup output we don't want in unit tests).
func newTestLogger(level logger2.LogLevel, slow time.Duration, ignoreNotFound bool) *logger {
	return &logger{
		Config: Config{
			SlowThreshold:             slow,
			IgnoreRecordNotFoundError: ignoreNotFound,
			LogLevel:                  level,
		},
		infoStr:      "%s\n[info] ",
		warnStr:      "%s\n[warn] ",
		errStr:       "%s\n[error] ",
		traceStr:     "%s\n[%.3fms] [rows:%v] %s",
		traceWarnStr: "%s %s\n[%.3fms] [rows:%v] %s",
		traceErrStr:  "%s %s\n[%.3fms] [rows:%v] %s",
	}
}

func TestErrRecordNotFoundIsGORMSentinel(t *testing.T) {
	// The package exports gorm.ErrRecordNotFound under its own name so callers
	// can reference it without importing gorm directly.
	assert.Equal(t, gorm.ErrRecordNotFound, ErrRecordNotFound)
	assert.Equal(t, "record not found", ErrRecordNotFound.Error())
}

func TestLogModeReturnsNewLoggerWithLevel(t *testing.T) {
	base := newTestLogger(logger2.Silent, 0, true)
	got := base.LogMode(logger2.Warn)

	// LogMode must return a new instance, not mutate the original.
	gotLogger, ok := got.(*logger)
	assert.True(t, ok, "LogMode should return *logger")
	assert.Equal(t, logger2.Warn, gotLogger.LogLevel)
	assert.Equal(t, logger2.Silent, base.LogLevel, "original must be untouched")
}

func TestInfoRespectsLogLevel(t *testing.T) {
	// At LogLevel=Info, Info() writes.
	l := newTestLogger(logger2.Info, 0, true)
	out := captureStdout(func() { l.Info(context.Background(), "hello %s", "world") })
	assert.Contains(t, out, "[info]")
	assert.Contains(t, out, "hello world")
}

func TestInfoSilentBelowLogLevel(t *testing.T) {
	// At LogLevel=Warn (below Info), Info() writes nothing.
	l := newTestLogger(logger2.Warn, 0, true)
	out := captureStdout(func() { l.Info(context.Background(), "should not appear") })
	assert.NotContains(t, out, "should not appear")
}

func TestWarnRespectsLogLevel(t *testing.T) {
	l := newTestLogger(logger2.Warn, 0, true)
	out := captureStdout(func() { l.Warn(context.Background(), "watch out %d", 42) })
	assert.Contains(t, out, "[warn]")
	assert.Contains(t, out, "watch out 42")
}

func TestWarnSilentBelowLogLevel(t *testing.T) {
	// At LogLevel=Error (below Warn), Warn() writes nothing.
	l := newTestLogger(logger2.Error, 0, true)
	out := captureStdout(func() { l.Warn(context.Background(), "nope") })
	assert.NotContains(t, out, "nope")
}

func TestErrorAlwaysPrintsPrefix(t *testing.T) {
	// Error() always prints "ERROR " before doing anything else — even at Silent.
	l := newTestLogger(logger2.Silent, 0, true)
	out := captureStdout(func() { l.Error(context.Background(), "boom") })
	assert.Contains(t, out, "ERROR")
}

func TestErrorAtErrorLevelPrintsMessage(t *testing.T) {
	l := newTestLogger(logger2.Error, 0, true)
	out := captureStdout(func() { l.Error(context.Background(), "kaboom %s", "x") })
	assert.Contains(t, out, "[error]")
	assert.Contains(t, out, "kaboom x")
}

func TestTraceSuccessAtInfoLevel(t *testing.T) {
	// Info log level + no error → normal trace line with ms + rows.
	l := newTestLogger(logger2.Info, 0, true)
	begin := time.Now().Add(-10 * time.Millisecond)
	out := captureStdout(func() {
		l.Trace(context.Background(), begin, func() (string, int64) {
			return "SELECT 1", 1
		}, nil)
	})
	assert.Contains(t, out, "SELECT 1")
	assert.Contains(t, out, "[rows:1]")
}

func TestTraceSuccessRowsMinusOne(t *testing.T) {
	// rows == -1 branch — the logger prints "-" in place of the row count.
	l := newTestLogger(logger2.Info, 0, true)
	begin := time.Now().Add(-5 * time.Millisecond)
	out := captureStdout(func() {
		l.Trace(context.Background(), begin, func() (string, int64) {
			return "EXEC thing", -1
		}, nil)
	})
	assert.Contains(t, out, "EXEC thing")
	assert.Contains(t, out, "[rows:-]")
}

func TestTraceSlowQueryAtWarnLevel(t *testing.T) {
	// Elapsed > SlowThreshold at Warn level → prints SLOW SQL warning.
	l := newTestLogger(logger2.Warn, 1*time.Nanosecond, true)
	begin := time.Now().Add(-50 * time.Millisecond)
	out := captureStdout(func() {
		l.Trace(context.Background(), begin, func() (string, int64) {
			return "SELECT slow", 7
		}, nil)
	})
	assert.Contains(t, out, "SLOW SQL")
	assert.Contains(t, out, "SELECT slow")
	assert.Contains(t, out, "[rows:7]")
}

func TestTraceSlowQueryRowsMinusOne(t *testing.T) {
	l := newTestLogger(logger2.Warn, 1*time.Nanosecond, true)
	begin := time.Now().Add(-20 * time.Millisecond)
	out := captureStdout(func() {
		l.Trace(context.Background(), begin, func() (string, int64) {
			return "UPDATE slow", -1
		}, nil)
	})
	assert.Contains(t, out, "SLOW SQL")
	assert.Contains(t, out, "[rows:-]")
}

func TestTraceErrorAtErrorLevel(t *testing.T) {
	// err != nil at Error level → prints the error trace line + captures to Sentry.
	l := newTestLogger(logger2.Error, 0, true)
	begin := time.Now().Add(-3 * time.Millisecond)
	out := captureStdout(func() {
		l.Trace(context.Background(), begin, func() (string, int64) {
			return "SELECT broken", 0
		}, errors.New("boom"))
	})
	assert.Contains(t, out, "TRACE")
	assert.Contains(t, out, "SELECT broken")
	assert.Contains(t, out, "[rows:0]")
}

func TestTraceErrorRowsMinusOne(t *testing.T) {
	l := newTestLogger(logger2.Error, 0, true)
	begin := time.Now().Add(-1 * time.Millisecond)
	out := captureStdout(func() {
		l.Trace(context.Background(), begin, func() (string, int64) {
			return "DELETE broken", -1
		}, errors.New("boom"))
	})
	assert.Contains(t, out, "[rows:-]")
}

func TestTraceRecordNotFoundIsIgnoredWhenConfigured(t *testing.T) {
	// IgnoreRecordNotFoundError=true + the package's sentinel → no TRACE line.
	// Passing ErrRecordNotFound (the package alias) keeps errors.Is() trivially true.
	l := newTestLogger(logger2.Error, 0, true)
	begin := time.Now().Add(-1 * time.Millisecond)
	out := captureStdout(func() {
		l.Trace(context.Background(), begin, func() (string, int64) {
			return "SELECT missing", 0
		}, ErrRecordNotFound)
	})
	assert.NotContains(t, out, "TRACE")
	assert.NotContains(t, out, "SELECT missing")
}

func TestTraceRecordNotFoundLoggedWhenNotIgnored(t *testing.T) {
	// IgnoreRecordNotFoundError=false → the sentinel is reported like any other error.
	l := newTestLogger(logger2.Error, 0, false)
	begin := time.Now().Add(-1 * time.Millisecond)
	out := captureStdout(func() {
		l.Trace(context.Background(), begin, func() (string, int64) {
			return "SELECT missing", 0
		}, ErrRecordNotFound)
	})
	assert.Contains(t, out, "TRACE")
	assert.Contains(t, out, "SELECT missing")
}

func TestTraceSilentBelowLogLevel(t *testing.T) {
	// LogLevel == Silent → early return, nothing printed.
	l := newTestLogger(logger2.Silent, 0, true)
	begin := time.Now().Add(-1 * time.Millisecond)
	out := captureStdout(func() {
		l.Trace(context.Background(), begin, func() (string, int64) {
			return "SELECT quiet", 1
		}, nil)
	})
	assert.NotContains(t, out, "SELECT quiet")
}
