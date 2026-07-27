package userdump

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// redactColFor withholds secret-named columns everywhere, plus
// sessions.series specifically (the other half of a session token - not
// name-matched by secretColRe on its own).
func TestRedactColFor(t *testing.T) {
	cases := []struct {
		name  string
		table string
		col   string
		want  bool
	}{
		{"password column matches on any table", "users", "password", true},
		{"passwd variant matches", "users", "passwd", true},
		{"secret substring matches", "some_table", "client_secret", true},
		{"token substring matches", "sessions", "token", true},
		{"credential substring matches", "users", "credential_hash", true},
		{"apikey substring matches", "users", "apikey", true},
		{"api_key substring matches", "users", "api_key", true},
		{"privatekey substring matches", "users", "privatekey", true},
		{"case-insensitive match", "users", "PASSWORD", true},
		{"sessions.series is redacted (session-token half)", "sessions", "series", true},
		{"sessions.series matched case-insensitively on table+col", "Sessions", "SERIES", true},
		{"series column on a DIFFERENT table is not redacted", "other_table", "series", false},
		{"ordinary column on sessions is not redacted", "sessions", "id", false},
		{"ordinary column elsewhere is not redacted", "users", "email", false},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			redact := redactColFor(c.table)
			assert.Equal(t, c.want, redact(c.col), c.name)
		})
	}
}

// inClause builds a "(?,?,...)" placeholder group matching the id count, or
// ("", nil) for an empty id set so callers can skip the IN entirely.
func TestInClause(t *testing.T) {
	t.Run("empty ids returns empty placeholder and nil args", func(t *testing.T) {
		ph, args := inClause(nil)
		assert.Equal(t, "", ph)
		assert.Nil(t, args)

		ph2, args2 := inClause([]interface{}{})
		assert.Equal(t, "", ph2)
		assert.Nil(t, args2)
	})

	t.Run("single id", func(t *testing.T) {
		ph, args := inClause([]interface{}{int64(5)})
		assert.Equal(t, "(?)", ph)
		assert.Equal(t, []interface{}{int64(5)}, args)
	})

	t.Run("multiple ids - one placeholder per id, args returned unchanged and in order", func(t *testing.T) {
		ids := []interface{}{int64(1), int64(2), int64(3)}
		ph, args := inClause(ids)
		assert.Equal(t, "(?,?,?)", ph)
		assert.Equal(t, ids, args)
	})
}
