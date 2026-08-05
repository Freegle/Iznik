package userdump

import (
	"testing"
	"time"

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

// findSpec returns the spec for a table, or nil.
func findSpec(specs []dbSpec, table string) *dbSpec {
	for i := range specs {
		if specs[i].table == table {
			return &specs[i]
		}
	}
	return nil
}

// chat_messages is the one extraction that could not be pulled for every chat a
// member is in. A moderator sits in the roster of every Mod2Mod and User2Mod
// chat on their groups - one real admin is in 18,664 rooms - and pulling all of
// their messages with no date bound could not finish inside the caller's
// timeout, so that member could never be investigated at all. Room membership
// stays complete; only the message bodies are anchored on the rooms that were
// active inside the dump's own ?since= window.
func TestBuildDBSpecs_ChatMessagesAreWindowed(t *testing.T) {
	since := time.Date(2026, 5, 7, 0, 0, 0, 0, time.UTC)
	allChats := []interface{}{int64(1), int64(2), int64(3)}
	recentChats := []interface{}{int64(3)}

	specs := buildDBSpecs(42, allChats, recentChats, nil, nil, since)

	msgs := findSpec(specs, "chat_messages")
	assert.NotNil(t, msgs)
	assert.Equal(t, "chatid IN (?) AND date >= ?", msgs.where,
		"anchored on the recent rooms only, and bounded by the window")
	assert.Equal(t, []interface{}{int64(3), since}, msgs.args)

	// Which conversations someone is in is cheap and support needs all of it.
	rooms := findSpec(specs, "chat_rooms")
	assert.NotNil(t, rooms)
	assert.Equal(t, "id IN (?,?,?)", rooms.where)
	assert.Equal(t, allChats, rooms.args)

	roster := findSpec(specs, "chat_roster")
	assert.NotNil(t, roster)
	assert.Equal(t, "chatid IN (?,?,?)", roster.where)

	// Held messages are keyed on every room too, plus the member themselves.
	held := findSpec(specs, "chat_messages_held")
	assert.NotNil(t, held)
	assert.Contains(t, held.where, "chatid IN (?,?,?)")
	assert.Contains(t, held.where, "userid = ?")
}

// A member with rooms but none active in the window gets no chat_messages spec
// at all, rather than an "IN ()" that would be invalid SQL.
func TestBuildDBSpecs_NoRecentChatsMeansNoMessageSpec(t *testing.T) {
	since := time.Date(2026, 5, 7, 0, 0, 0, 0, time.UTC)
	specs := buildDBSpecs(42, []interface{}{int64(1)}, nil, nil, nil, since)

	assert.Nil(t, findSpec(specs, "chat_messages"))
	assert.NotNil(t, findSpec(specs, "chat_rooms"), "membership is still collected")
}

// A member with no chats at all still gets their own held messages.
func TestBuildDBSpecs_NoChatsAtAll(t *testing.T) {
	since := time.Date(2026, 5, 7, 0, 0, 0, 0, time.UTC)
	specs := buildDBSpecs(42, nil, nil, nil, nil, since)

	assert.Nil(t, findSpec(specs, "chat_rooms"))
	assert.Nil(t, findSpec(specs, "chat_messages"))
	held := findSpec(specs, "chat_messages_held")
	assert.NotNil(t, held)
	assert.Equal(t, "userid = ?", held.where)
}
