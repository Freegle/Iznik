package sentrylogpackage

import (
	"context"
	"errors"
	"regexp"
	"strings"

	"github.com/getsentry/sentry-go"
	"github.com/go-sql-driver/mysql"
	"github.com/jackc/pgx/v5/pgconn"
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

// IsDeadlock reports whether err is a deadlock or lock wait timeout, by the server's error code: MySQL
// 1213 and 1205, PostgreSQL 40P01 and 55P03. The wording of the message differs between servers and
// versions; the code does not.
func IsDeadlock(err error) bool {
	var me *mysql.MySQLError
	if errors.As(err, &me) {
		return me.Number == 1213 || me.Number == 1205
	}
	var pe *pgconn.PgError
	return errors.As(err, &pe) && (pe.Code == "40P01" || pe.Code == "55P03")
}

var (
	quotedRe  = regexp.MustCompile(`'(?:[^'\\]|\\.)*'`)
	dquotedRe = regexp.MustCompile(`"(?:[^"\\]|\\.)*"`)
	hexRe     = regexp.MustCompile(`\b0[xX][0-9a-fA-F]+\b`)
	decimalRe = regexp.MustCompile(`\b\d+\.\d+\b`)
	numberRe  = regexp.MustCompile(`\b\d+\b`)
	spaceRe   = regexp.MustCompile(`\s+`)

	// The value MySQL quotes in "Duplicate entry '...' for key" and "Incorrect ... value: '...' for
	// column". Greedy, because the value is whatever a member typed and may contain a quote.
	dupEntryRe = regexp.MustCompile(`(?s)(Duplicate entry )'.*'( for key)`)
	badValueRe = regexp.MustCompile(`(?s)(value: )'.*'( for column)`)
)

const maxSQLSnippet = 200

// SQLShape returns the start of a statement with its values removed, so a report
// says which query failed without carrying what a member typed into it.
func SQLShape(sql string) string {
	s := quotedRe.ReplaceAllString(sql, "?")
	s = dquotedRe.ReplaceAllString(s, "?")
	s = hexRe.ReplaceAllString(s, "?")
	s = decimalRe.ReplaceAllString(s, "?")
	s = numberRe.ReplaceAllString(s, "?")
	s = strings.TrimSpace(spaceRe.ReplaceAllString(s, " "))
	if len(s) > maxSQLSnippet {
		s = s[:maxSQLSnippet]
	}
	return s
}

// ScrubMessage removes the member-supplied value from database errors that quote one. "Duplicate
// entry 'someone@example.com' for key ..." would otherwise put an address into Sentry.
func ScrubMessage(msg string) string {
	msg = dupEntryRe.ReplaceAllString(msg, "${1}'?'${2}")
	return badValueRe.ReplaceAllString(msg, "${1}'?'${2}")
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
		sentry.CaptureMessage(ScrubMessage(err.Error()))
	})
}
