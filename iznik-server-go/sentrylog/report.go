package sentrylogpackage

import (
	"context"
	"regexp"
	"strings"

	"github.com/getsentry/sentry-go"
)

type tagsKey struct{}

// WithTags returns a context whose database errors are reported to Sentry with
// these tags. A bare "context deadline exceeded" says nothing about which query
// ran out of time; a caller that knows (a dashboard component, a date range, a
// group count) puts that in the context its queries run under.
func WithTags(ctx context.Context, tags map[string]string) context.Context {
	merged := map[string]string{}
	if old, ok := ctx.Value(tagsKey{}).(map[string]string); ok {
		for k, v := range old {
			merged[k] = v
		}
	}
	for k, v := range tags {
		merged[k] = v
	}
	return context.WithValue(ctx, tagsKey{}, merged)
}

// TagsFrom returns the tags WithTags put in ctx, or nil.
func TagsFrom(ctx context.Context) map[string]string {
	if ctx == nil {
		return nil
	}
	t, _ := ctx.Value(tagsKey{}).(map[string]string)
	return t
}

// IsDeadlock reports whether err is a MySQL deadlock or lock wait timeout. This
// mirrors database.IsDeadlockOrLockTimeout, which this package cannot import
// because database imports this package.
func IsDeadlock(err error) bool {
	if err == nil {
		return false
	}
	msg := strings.ToLower(err.Error())
	return strings.Contains(msg, "deadlock") ||
		strings.Contains(msg, "error 1213") ||
		strings.Contains(msg, "lock wait timeout")
}

var (
	quotedRe = regexp.MustCompile(`'(?:[^'\\]|\\.)*'`)
	numberRe = regexp.MustCompile(`\b\d+\b`)
	spaceRe  = regexp.MustCompile(`\s+`)
)

const maxSQLSnippet = 200

// SQLShape returns the start of a statement with its values removed, so a report
// says which query failed without carrying what a member typed into it.
func SQLShape(sql string) string {
	s := quotedRe.ReplaceAllString(sql, "?")
	s = numberRe.ReplaceAllString(s, "?")
	s = strings.TrimSpace(spaceRe.ReplaceAllString(s, " "))
	if len(s) > maxSQLSnippet {
		s = s[:maxSQLSnippet]
	}
	return s
}

// capture sends a database error to Sentry with the caller's tags and the shape
// of the statement. fc is the GORM callback that renders the statement.
func capture(ctx context.Context, err error, fc func() (string, int64)) {
	sentry.WithScope(func(scope *sentry.Scope) {
		for k, v := range TagsFrom(ctx) {
			scope.SetTag(k, v)
		}
		if fc != nil {
			if sql, _ := fc(); sql != "" {
				scope.SetExtra("sql_shape", SQLShape(sql))
			}
		}
		sentry.CaptureMessage(err.Error())
	})
}
